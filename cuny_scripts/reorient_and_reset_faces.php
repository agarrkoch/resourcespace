<?php
/**
 * reorient_and_reset_faces.php
 *
 * Reads a text file listing photos that need EXIF re-orientation, in the format:
 *
 *   /Volumes/CUNYTVMEDIA/archive_projects/Photos/.../CK2A8573.JPG	(orientation=8)
 *
 * one entry per line (path and "(orientation=N)" separated by a tab — the
 * orientation part is only used for logging, not required for the fix).
 *
 * For each file:
 *   1. Runs `mogrify -auto-orient` on the ORIGINAL file in place.
 *   2. Strips the leading "/Volumes" from the path and looks up the
 *      matching resource in the `resource` table via `file_path`.
 *   3. If found: sets faces_processed = 0 and deletes all rows from
 *      resource_face for that resource, so face detection will re-run
 *      against the corrected (now upright) image.
 *
 * Usage:
 *   php reorient_and_reset_faces.php /path/to/list.txt
 *
 * Add --dry-run to preview what would happen without touching the file or the DB.
 */

chdir(dirname(__FILE__));

// Force immediate, unbuffered output. Without this, PHP CLI output can be
// fully buffered (only flushed at script end) when stdout isn't a TTY, and
// boot.php (built primarily for web requests) may also open its own output
// buffer via ob_start() that silently swallows echoes until it's closed.
ob_implicit_flush(true);
while (ob_get_level() > 0)
    {
    ob_end_flush();
    }

include '/opt/homebrew/var/www/include/boot.php';

// boot.php may have opened its own buffer during include — close/flush it too.
while (ob_get_level() > 0)
    {
    ob_end_flush();
    }
ob_implicit_flush(true);

function rf_log($message)
    {
    $line = '[' . date('Y-m-d H:i:s') . "] $message\n";
    fwrite(STDOUT, $line);
    flush();
    }

$args = $argv;
array_shift($args); // drop script name

$dry_run = false;
$list_file = null;

foreach ($args as $arg)
    {
    if ($arg === '--dry-run')
        {
        $dry_run = true;
        }
    else
        {
        $list_file = $arg;
        }
    }

if ($list_file === null || !file_exists($list_file))
    {
    fwrite(STDERR, "Usage: php reorient_and_reset_faces.php <list.txt> [--dry-run]\n");
    exit(1);
    }

if ($dry_run)
    {
    rf_log("DRY RUN — no files will be modified, no DB rows will be changed.");
    }

$lines = file($list_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$total          = 0;
$reoriented_ok  = 0;
$reoriented_fail = 0;
$matched        = 0;
$unmatched      = 0;

foreach ($lines as $line)
    {
    $line = trim($line);
    if ($line === '')
        {
        continue;
        }

    // Split on the first tab; everything before it is the path.
    $parts = explode("\t", $line, 2);
    $file_path = trim($parts[0]);
    $orientation_note = isset($parts[1]) ? trim($parts[1]) : '';

    if ($file_path === '')
        {
        continue;
        }

    $total++;
    rf_log("---- [$total] $file_path $orientation_note");

    if (!file_exists($file_path))
        {
        rf_log("  [SKIP] File does not exist on disk.");
        continue;
        }

    // ---- Step 1: reorient the original file in place (only if needed) ----
    $current_orientation = trim(shell_exec(
        'identify -format "%[EXIF:Orientation]" ' . escapeshellarg($file_path) . ' 2>/dev/null'
    ));
    // identify can return multiple lines for multi-frame files; only the first matters here.
    $current_orientation = strtok($current_orientation, "\n");

    $needs_reorient = ($current_orientation !== '' && $current_orientation !== '1');

    if (!$needs_reorient)
        {
        rf_log("  [SKIP REORIENT] Already orientation=1 (or none) — continuing with DB/preview steps anyway.");
        }
    elseif ($dry_run)
        {
        rf_log("  [DRY RUN] Would run: mogrify -auto-orient " . escapeshellarg($file_path)
            . " (current orientation=$current_orientation)");
        }
    else
        {
        $cmd = 'mogrify -auto-orient ' . escapeshellarg($file_path) . ' 2>&1';
        exec($cmd, $output, $return_code);

        if ($return_code !== 0)
            {
            $reoriented_fail++;
            rf_log("  [FAIL] mogrify failed (exit $return_code): " . implode(' | ', $output));
            $output = [];
            continue; // don't touch the DB if the reorient itself actually failed
            }

        $reoriented_ok++;
        rf_log("  [OK] Reoriented (was orientation=$current_orientation).");
        }
    $output = []; // reset for next iteration

    // ---- Step 2: map file path -> resource ref ----
    // DB stores paths without the "/Volumes" mount prefix.
    $db_path = preg_replace('#^/Volumes/#', '', $file_path);

    $resource_rows = ps_query(
        "SELECT ref FROM resource WHERE file_path = ?",
        ["s", $db_path]
    );

    if (empty($resource_rows))
        {
        $unmatched++;
        rf_log("  [NO MATCH] No resource found for file_path = '$db_path'");
        continue;
        }

    if (count($resource_rows) > 1)
        {
        rf_log("  [WARN] Multiple resources matched '$db_path' — processing all of them: "
            . implode(', ', array_column($resource_rows, 'ref')));
        }

    foreach ($resource_rows as $row)
        {
        $resource_ref = (int) $row['ref'];
        $matched++;

        if ($dry_run)
            {
            rf_log("  [DRY RUN] Would set faces_processed=0, delete resource_face rows, "
                . "and recreate previews for resource $resource_ref");
            continue;
            }

        // ---- Step 3: reset faces_processed ----
        ps_query(
            "UPDATE resource SET faces_processed = 0 WHERE ref = ?",
            ["i", $resource_ref]
        );

        // ---- Step 4: clear existing face rows so they'll be re-detected ----
        $deleted = ps_query(
            "DELETE FROM resource_face WHERE resource = ?",
            ["i", $resource_ref]
        );

        rf_log("  [DB OK] resource $resource_ref: faces_processed reset, resource_face rows cleared.");

        // ---- Step 5: regenerate previews/thumbnails, since the pixel data changed ----
        $preview_cmd = 'php /opt/homebrew/var/www/batch/recreate_previews.php resource '
            . escapeshellarg($resource_ref) . ' ' . escapeshellarg($resource_ref) . ' 2>&1';
        exec($preview_cmd, $preview_output, $preview_return_code);

        if ($preview_return_code !== 0)
            {
            rf_log("  [WARN] recreate_previews.php failed for resource $resource_ref (exit $preview_return_code): "
                . implode(' | ', $preview_output));
            }
        else
            {
            rf_log("  [OK] Previews recreated for resource $resource_ref.");
            }
        $preview_output = []; // reset for next iteration
        }
    }

rf_log("=================================================");
rf_log("Done. Total lines: $total");
if (!$dry_run)
    {
    rf_log("Reoriented OK: $reoriented_ok   Reorient failed: $reoriented_fail");
    }
rf_log("Resources matched: $matched   Unmatched: $unmatched");