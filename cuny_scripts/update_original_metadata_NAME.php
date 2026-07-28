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

const NET_RETRIES = 5;
const NET_DELAY_US = 750000; // 750ms

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
            $name_exists = XMP_name_exists($name, $file_path);
        }
        if ($file_exists && !$name_exists) {
            add_XMP_name($name, $file_path);
            echo "$name added to $file_path" . PHP_EOL;
            update_db_checksum($resource_ref, $file_path);
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

update_text_file();

flock($lockFile, LOCK_UN);
fclose($lockFile);