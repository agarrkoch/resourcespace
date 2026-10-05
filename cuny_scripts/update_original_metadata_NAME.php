<?php
include "/opt/homebrew/var/www/include/boot.php";

// Prevent overlapping runs (two processes racing on the same file over
// a network mount is a common cause of "temp file already exists")
$lockFile = fopen(__DIR__ . '/update_original_metadata.lock', 'w');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another instance is already running. Exiting.\n";
    exit(1);
}

$resource_type = 1; //photos
$date = date('Y-m-d', strtotime('yesterday'));
$start = $date . " 00:00:00";
$end   = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
$latest_name_node = (int) trim(file_get_contents(__DIR__ . '/update_original_metadata_latest_name_node.txt'));

// Command-line options:
//   --backfill        also sync regions for every photo that has named faces
//   --resource=12345  only sync regions for this one resource (for testing)
$backfill = false;
$only_resource = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--backfill') {
        $backfill = true;
    } elseif (preg_match('/^--resource=(\d+)$/', $arg, $m)) {
        $only_resource = (int) $m[1];
    }
}

const NET_RETRIES = 5;
const NET_DELAY_US = 750000; // 750ms

// Regions written by this script get an RId with this prefix, so the script
// can find and replace its own regions without touching anyone else's.
const REGION_ID_PREFIX = 'rs-face-';
const IPTC_REGION_TYPE_HUMAN = 'http://cv.iptc.org/newscodes/imageregiontype/human';

function clear_stale_exiftool_tmp($file) {
    $tmp = $file . '_exiftool_tmp';

    clearstatcache(true, $tmp);
    if (!file_exists($tmp)) {
        return;
    }

    clearstatcache(true, $tmp);
    if (!file_exists($tmp)) {
        return;
    }

    try {
        @unlink($tmp);
        echo "Removed stale temp file: $tmp" . PHP_EOL;
    } catch (\ErrorException $e) {
        echo "Stale temp file already gone: $tmp" . PHP_EOL;
    }
}

function run_exiftool($cmd, $retries = NET_RETRIES, $delay_us = NET_DELAY_US) {
    $output = null;
    for ($i = 1; $i <= $retries; $i++) {
        $output = shell_exec($cmd . " 2>&1");
        if ($output !== null && stripos($output, 'Error:') === false) {
            return $output;
        }
        usleep($delay_us);
    }
    return $output;
}

function file_exists_retry($file, $retries = NET_RETRIES, $delay_us = NET_DELAY_US) {
    for ($i = 1; $i <= $retries; $i++) {
        clearstatcache(true, $file);
        if (file_exists($file)) {
            return true;
        }
        usleep($delay_us);
    }
    return false;
}

function XMP_name_exists($name, $file) {
    clear_stale_exiftool_tmp($file);
    $cmd = "exiftool -j -XMP-iptcExt:PersonInImage " . escapeshellarg($file);
    $output = run_exiftool($cmd);
    if (trim((string)$output) == "") {
        return false;
    }
    $data = json_decode($output, true);
    $value = $data[0]['PersonInImage'] ?? [];
    $people = is_array($value) ? $value : ($value !== '' ? [$value] : []);
    return in_array($name, $people);
}

function add_XMP_name($name, $file) {
    clear_stale_exiftool_tmp($file);
    $cmd = "exiftool -overwrite_original -XMP-iptcExt:PersonInImage+=" .
           escapeshellarg($name) . " " . escapeshellarg($file);
    $output = run_exiftool($cmd);
    echo trim((string)$output) . PHP_EOL;
}

// ---------------------------------------------------------------------------
// IPTC Image Region (face bounding boxes)
// ---------------------------------------------------------------------------

// The Faces plugin detects on the scr preview, so its bbox pixels are relative
// to that file. Measure the real file rather than recalculating its size.
function get_scr_dimensions($ref) {
    $path = get_resource_path($ref, true, 'scr', false, 'jpg');
    if (!$path || !file_exists($path)) {
        return null;
    }
    $size = @getimagesize($path);
    if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
        return null;
    }
    return [$size[0], $size[1]];
}

function get_named_faces($ref) {
    $query = "SELECT rf.ref, rf.bbox, n.name
              FROM resource_face rf
              INNER JOIN node n ON rf.node = n.ref
              WHERE rf.resource = ? AND rf.node IS NOT NULL
              ORDER BY rf.ref;";
    return ps_query($query, ['i', $ref]);
}

// Build IPTC regions (top-left origin, relative 0-1) for every named face on a resource.
function build_rs_regions($ref) {
    $dims = get_scr_dimensions($ref);
    if ($dims === null) {
        throw new Exception("scr preview not found for resource $ref, so bounding boxes can't be normalised");
    }
    [$w, $h] = $dims;

    $regions = [];
    foreach (get_named_faces($ref) as $face) {
        $bbox = json_decode((string)$face['bbox'], true);
        if (!is_array($bbox) || count($bbox) !== 4) {
            echo "Skipping face {$face['ref']}: unreadable bbox" . PHP_EOL;
            continue;
        }

        // InsightFace bbox = [x1, y1, x2, y2] in scr pixels; clamp to the image
        [$x1, $y1, $x2, $y2] = array_map('floatval', $bbox);
        $x1 = max(0, min($x1, $w));
        $x2 = max(0, min($x2, $w));
        $y1 = max(0, min($y1, $h));
        $y2 = max(0, min($y2, $h));
        if ($x2 <= $x1 || $y2 <= $y1) {
            echo "Skipping face {$face['ref']}: empty bbox after clamping" . PHP_EOL;
            continue;
        }

        $regions[] = [
            'RegionBoundary' => [
                'RbShape' => 'rectangle',
                'RbUnit'  => 'relative',
                'RbX'     => round($x1 / $w, 5),
                'RbY'     => round($y1 / $h, 5),
                'RbW'     => round(($x2 - $x1) / $w, 5),
                'RbH'     => round(($y2 - $y1) / $h, 5),
            ],
            'RId'    => REGION_ID_PREFIX . $face['ref'],
            'Name'   => $face['name'],
            'RCtype' => [[
                'Identifier' => [IPTC_REGION_TYPE_HUMAN],
                'Name'       => 'Human',
            ]],
        ];
    }
    return $regions;
}

function read_image_regions($file) {
    clear_stale_exiftool_tmp($file);
    $cmd = "exiftool -j -struct -XMP-iptcExt:ImageRegion " . escapeshellarg($file);
    $output = run_exiftool($cmd);
    $data = json_decode((string)$output, true);
    if (!is_array($data) || !isset($data[0])) {
        throw new Exception("Could not read ImageRegion from $file: " . trim((string)$output));
    }
    $regions = $data[0]['ImageRegion'] ?? [];
    // A lone region can come back as a single struct rather than a list
    if (isset($regions['RegionBoundary']) || isset($regions['RId'])) {
        $regions = [$regions];
    }
    return is_array($regions) ? $regions : [];
}

function is_rs_region($region) {
    return isset($region['RId']) && str_starts_with((string)$region['RId'], REGION_ID_PREFIX);
}

// Comparable summary of regions (id => name + rounded box) to avoid needless rewrites
function region_signature($regions) {
    $sig = [];
    foreach ($regions as $r) {
        $b = $r['RegionBoundary'] ?? [];
        $sig[(string)($r['RId'] ?? '')] = implode('|', [
            (string)($r['Name'] ?? ''),
            round((float)($b['RbX'] ?? 0), 4),
            round((float)($b['RbY'] ?? 0), 4),
            round((float)($b['RbW'] ?? 0), 4),
            round((float)($b['RbH'] ?? 0), 4),
        ]);
    }
    ksort($sig);
    return $sig;
}

function write_image_regions($regions, $file) {
    clear_stale_exiftool_tmp($file);
    $tmp = null;

    if (empty($regions)) {
        $cmd = "exiftool -overwrite_original -XMP-iptcExt:ImageRegion= " . escapeshellarg($file);
    } else {
        // Write via a JSON file so names with commas, brackets or quotes need no escaping.
        // The temp file lives on local disk, not the network mount.
        $tmp = tempnam(sys_get_temp_dir(), 'rs_regions_');
        $json = json_encode(
            [['SourceFile' => '*', 'XMP-iptcExt:ImageRegion' => $regions]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        file_put_contents($tmp, $json);
        $cmd = "exiftool -overwrite_original -j=" . escapeshellarg($tmp) . " " . escapeshellarg($file);
    }

    try {
        $output = run_exiftool($cmd);
    } finally {
        if ($tmp !== null) {
            @unlink($tmp);
        }
    }

    if (stripos((string)$output, '1 image files updated') === false) {
        throw new Exception("exiftool did not update $file: " . trim((string)$output));
    }
    echo trim((string)$output) . PHP_EOL;
}

// Replace this script's regions with the current named faces; leave other regions alone.
// Returns true if the file was changed.
function sync_face_regions($ref, $file) {
    $desired  = build_rs_regions($ref);
    $existing = read_image_regions($file);

    $existing_rs = array_values(array_filter($existing, 'is_rs_region'));
    if (region_signature($existing_rs) == region_signature($desired)) {
        echo "Regions already up to date for resource $ref" . PHP_EOL;
        return false;
    }

    $others = array_values(array_filter($existing, fn($r) => !is_rs_region($r)));
    write_image_regions(array_merge($others, $desired), $file);
    echo count($desired) . " named face region(s) written to $file" . PHP_EOL;
    return true;
}

// ---------------------------------------------------------------------------

function create_new_checksum($file){
    global $file_checksums_50k;
    if ($file_checksums_50k) {
        $use = false;
        for ($attempt = 1; $attempt <= NET_RETRIES; $attempt++) {
            clearstatcache(true, $file);
            $data = @file_get_contents($file, false, null, 0, 50000);
            if ($data !== false) {
                $use = filesize_unlimited($file) . "_" . $data;
                break;
            }
            usleep(NET_DELAY_US);
        }
        if ($use === false) {
            throw new Exception("Failed to read '$file' after " . NET_RETRIES . " attempts.");
        }
        $checksum = md5($use);
    } else {
        $checksum = md5_file($file);
    }

    return $checksum;
}

function update_db_checksum($ref, $file){
    $checksum = create_new_checksum($file);
    echo "Checksum: " . $checksum . PHP_EOL;

    $query = "UPDATE resource SET file_checksum = ? WHERE ref = ?;";
    ps_query($query, ['s', $checksum, 'i', $ref]);

    $query = "UPDATE resource SET file_modified=NOW() WHERE ref = ?;";
    ps_query($query, ['i', $ref]);
}

function update_text_file(){
    $query = "SELECT MAX(ref) AS largest_ref FROM node WHERE resource_type_field = 29;";
    $latest_node = ps_query($query, [])[0]['largest_ref'];
    file_put_contents(__DIR__ . '/update_original_metadata_latest_name_node.txt', (string)$latest_node);
}

$to_sync = []; // resource ref => file path: resources whose regions should be checked
$changed = []; // resource ref => file path: resources whose file was modified

if ($only_resource !== null) {
    echo "=====SYNCING REGIONS FOR RESOURCE " . $only_resource . " ONLY=====\n";
    $rows = ps_query("SELECT ref, file_path FROM resource WHERE ref = ?;", ['i', $only_resource]);
    foreach ($rows as $row) {
        $to_sync[$row['ref']] = $syncdir . '/' . $row['file_path'];
    }
} else {
    echo "=====UPDATING IMAGES W " . $date . " DATA=====\n";
    $query = "SELECT rf.ref, rf.resource, rf.created, rf.node, n.name, r.file_path FROM resource_face rf INNER JOIN resource r ON rf.resource = r.ref INNER JOIN node n ON rf.node = n.ref WHERE node IS NOT NULL AND resource IN (SELECT ref FROM resource WHERE resource_type = ?) AND ((rf.created >= ? AND rf.created < ?) OR (rf.node IN (SELECT ref FROM node WHERE ref > ?)));";
    $faces = ps_query($query, ['i', $resource_type, 's', $start, 's', $end, 'i', $latest_name_node]);

    foreach ($faces as $row) {
        $file_path = $syncdir . '/' . $row['file_path'];
        $name = $row['name'];
        $resource_ref = $row['resource'];

        try {
            $file_exists = file_exists_retry($file_path);
            $name_exists = false;
            if ($file_exists) {
                $to_sync[$resource_ref] = $file_path;
                $name_exists = XMP_name_exists($name, $file_path);
            }
            if ($file_exists && !$name_exists) {
                add_XMP_name($name, $file_path);
                echo "$name added to $file_path" . PHP_EOL;
                $changed[$resource_ref] = $file_path;
            } else {
                echo "$name WAS NOT added to $file_path" . PHP_EOL;
                echo "File exists: " . ($file_exists ? 'true' : 'false') . PHP_EOL;
                echo "XMP name exists: " . ($name_exists ? 'true' : 'false') . PHP_EOL;
            }
        } catch (\Throwable $e) {
            echo "ERROR processing face ref {$row['ref']} ($name / $file_path): "
                . $e->getMessage() . PHP_EOL;
        }
        echo "------------" . PHP_EOL;
    }

    if ($backfill) {
        $query = "SELECT DISTINCT rf.resource, r.file_path FROM resource_face rf INNER JOIN resource r ON rf.resource = r.ref WHERE rf.node IS NOT NULL AND r.resource_type = ?;";
        foreach (ps_query($query, ['i', $resource_type]) as $row) {
            $to_sync[$row['resource']] = $syncdir . '/' . $row['file_path'];
        }
    }
}

echo "=====SYNCING FACE REGIONS (" . count($to_sync) . " RESOURCES)=====\n";
foreach ($to_sync as $resource_ref => $file_path) {
    try {
        if (!file_exists_retry($file_path)) {
            echo "Regions skipped, file not found: $file_path" . PHP_EOL;
        } elseif (sync_face_regions($resource_ref, $file_path)) {
            $changed[$resource_ref] = $file_path;
        }
    } catch (\Throwable $e) {
        echo "ERROR syncing regions for resource $resource_ref ($file_path): "
            . $e->getMessage() . PHP_EOL;
    }
    echo "------------" . PHP_EOL;
}

// One checksum update per modified file, after all writes to it are done
foreach ($changed as $resource_ref => $file_path) {
    try {
        update_db_checksum($resource_ref, $file_path);
    } catch (\Throwable $e) {
        echo "ERROR updating checksum for resource $resource_ref ($file_path): "
            . $e->getMessage() . PHP_EOL;
    }
}

if ($only_resource === null) {
    update_text_file();
}

flock($lockFile, LOCK_UN);
fclose($lockFile);