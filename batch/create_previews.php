#!/usr/bin/php
<?php

if (PHP_SAPI != 'cli') {
    exit("Command line execution only.");
}

include __DIR__ . "/../include/boot.php";
include_once __DIR__ . "/../include/image_processing.php";

# Prevent this script from creating offline jobs for tasks such as extracting text.
# Offline jobs shouldn't be created here as they require a valid user ref to be processed.
# This is running offline anyway so no need to create more jobs.
$offline_job_queue = false;

$ignoremaxsize = false;
$noimage = false;
if ($argc >= 2) {
    $validargs = false;
    if (in_array($argv[1], array('--help', '-help', '-h', '-?'))) {
        echo "To clear the lock after a failed run, ";
        echo "pass in '-clearlock'\n";
        echo "To ignore the maximum preview size configured ($preview_generate_max_file_size), ";
        echo "pass in '-ignoremaxsize'.\n";
        exit("Bye!");
    }
    if (in_array('-ignoremaxsize', $argv)) {
        $ignoremaxsize = true;
        $validargs = true;
    }
    if (in_array('-noimage', $argv)) {
        $noimage = true; #
        $validargs = true;
    }
    if (in_array('-clearlock', $argv)) {
        if (is_process_lock("create_previews")) {
            clear_process_lock("create_previews");
        }
        $validargs = true;
    }
    if (!$validargs) {
        exit("Unknown argv: " . $argv[1]);
    }
}


# Check for a process lock
if (is_process_lock("create_previews")) {
    exit("Process lock is in place. Deferring.");
}
set_process_lock("create_previews");

if (function_exists("pcntl_signal")) {
    $multiprocess = true;
} else {
    $multiprocess = false;
}

// We store the start date.
$global_start_time = microtime(true);

// We define the number of threads.
$max_forks = 3;

$lock_directory = '.';

// We create an array to store children pids.
$children = array();

/**
 * This function clean up the list of children pids.
 * This allow to detect the freeing of a thread slot.
 */
function reap_children()
{
    global $children;

    $tmp = array();

    foreach ($children as $pid) {
        if (pcntl_waitpid($pid, $status, WNOHANG) != $pid) {
            array_push($tmp, $pid);
        }
    }

    $children = $tmp;

    return count($tmp);
} // reap_children()



/**
 * This function is used to process SIGALRM signal.
 * This is usefull when the parent process is killed.
 */
function sigalrm_handler()
{
    die("[SIGALRM] hang in thumbnails creation ?\n");
}



/**
 * This function is used to process SIGCHLD signal.
 *
 */
function sigchld_handler($signal)
{
    reap_children();

    pcntl_waitpid(-1, $status, WNOHANG);
}



/**
 * This function is used to process SIGINT signal.
 *
 */
function sigint_handler()
{
    die("[SIGINT] exiting.\n");
}


error_log("=== PREVIEW SCRIPT START ===");
error_log("Multiprocess: " . ($multiprocess ? "YES" : "NO"));

/* ---------------- Signal handling ---------------- */
if ($multiprocess) {
    error_log("[1] Setting up signal handlers");
    pcntl_signal(SIGALRM, 'sigalrm_handler');
    pcntl_signal(SIGCHLD, 'sigchld_handler');
}

/* ---------------- Build SQL ---------------- */
error_log("[2] Building resource SQL");

$sql = "SELECT ref,
               file_extension,
               IFNULL(preview_attempts, 1) AS preview_attempts,
               creation_date
          FROM resource 
         WHERE ref > 0
           AND no_file <> 1
           AND (preview_attempts < ? OR preview_attempts IS NULL)
           AND file_extension IS NOT NULL
           AND LENGTH(file_extension) > 0
           AND LOWER(file_extension) NOT IN (" . ps_param_insert(count($no_preview_extensions)) . ")";

$params = array_merge(
    ["i", SYSTEM_MAX_PREVIEW_ATTEMPTS],
    ps_param_fill($no_preview_extensions, "s")
);

$extraconditions = "";
if (!$noimage) {
    error_log("[3] Adding image-only filter");
    $extraconditions .= " AND has_image != ? ";
    $params[] = "i";
    $params[] = RESOURCE_PREVIEWS_ALL;
}

/* ---------------- Query resources ---------------- */
error_log("[4] Executing resource query");
$resources = ps_query($sql . $extraconditions, $params);
error_log("[5] Resource query returned " . count($resources) . " rows");

/* ---------------- Main loop ---------------- */
$loop_counter = 0;

foreach ($resources as $resource) {

    $loop_counter++;
    error_log("[6] LOOP START #$loop_counter | Resource {$resource['ref']}");

    /* ---------- Fork throttling ---------- */
    if ($multiprocess) {
        error_log("[7] Children count BEFORE throttle: " . count($children));

        while (count($children) >= $max_forks) {
            error_log("[8] Max forks reached (" . count($children) . "), waiting...");
            reap_children();
            error_log("[9] After reap_children(), children: " . count($children));
            sleep(1);
        }
    }

    /* ---------- Fork decision ---------- */
    if (!$multiprocess || count($children) < $max_forks) {

        error_log("[10] Forking decision reached for resource {$resource['ref']}");

        if (!$multiprocess) {
            $pid = false;
            error_log("[11] Multiprocess OFF – running inline");
        } else {
            $pid = pcntl_fork();
            error_log("[11] pcntl_fork() returned PID = " . var_export($pid, true));
        }

        /* ---------- Fork failure ---------- */
        if ($pid == -1) {
            error_log("[12] FORK FAILED – exiting");
            die("fork failed!\n");
        }

        /* ---------- Parent ---------- */
        elseif ($pid) {
            error_log("[13] Parent process – child PID $pid registered");
            $children[] = $pid;
        }

        /* ---------- Child ---------- */
        else {
            error_log("[14] CHILD STARTED | PID " . getmypid() . " | Resource {$resource['ref']}");

            if ($multiprocess) {
                pcntl_signal(SIGCHLD, SIG_IGN);
                pcntl_signal(SIGINT, SIG_DFL);
                error_log("[15] Child signal handlers set");
            }

            error_log("[16] Processing resource {$resource['ref']} (attempt {$resource['preview_attempts']})");

            $start_time = microtime(true);

            /* ---------- DB reconnect ---------- */
            error_log("[17] Child reconnecting to database");
            sql_connect();
            error_log("[18] Child database connection OK");

            /* ---------- Age check ---------- */
            error_log("[19] Resource creation date: {$resource['creation_date']}");
            $resourceage = time() - strtotime($resource['creation_date']);
            error_log("[20] Resource age (seconds): $resourceage");

            if ($resource['preview_attempts'] > 3 && $resourceage < 1000) {
                error_log("[21] Resource too new; resetting preview_attempts and skipping");
                ps_query(
                    "UPDATE resource SET preview_attempts = 0 WHERE ref = ?",
                    array("i", $resource['ref'])
                );
                error_log("[22] preview_attempts reset; exiting child early");
                exit(0);
            }

            /* ---------- MP3 preview shortcut ---------- */
            if (
                $resource['file_extension'] != "mp3"
                && in_array($resource['file_extension'], $ffmpeg_audio_extensions)
                && file_exists(get_resource_path($resource['ref'], true, "", false, "mp3"))
            ) {
                error_log("[23] MP3 preview already exists");
                ps_query(
                    "UPDATE resource SET preview_attempts = 5 WHERE ref = ?",
                    array("i", $resource['ref'])
                );
                error_log("[24] preview_attempts set to 5");
            }

            /* ---------- Preview generation ---------- */
            elseif ($resource['preview_attempts'] < 5 && $resource['file_extension'] != "") {

                $ingested = empty($resource['file_path']);
                error_log("[25] Ingested = " . ($ingested ? "YES" : "NO"));

                error_log("[26] Incrementing preview_attempts");
                ps_query(
                    "UPDATE resource SET preview_attempts = IFNULL(preview_attempts, 1) + 1 WHERE ref = ?",
                    array("i", $resource['ref'])
                );

                error_log("[27] Calling create_previews()");
                $success = create_previews(
                    $resource['ref'],
                    false,
                    $resource['file_extension'],
                    false,
                    false,
                    -1,
                    $ignoremaxsize,
                    $ingested
                );

                error_log("[28] create_previews() returned: " . var_export($success, true));

                hook('after_batch_create_preview');
                error_log("[29] after_batch_create_preview hook executed");

                error_log(
                    "[30] Finished resource {$resource['ref']} in " .
                    round(microtime(true) - $start_time, 2) . " seconds"
                );
            }

            /* ---------- Child exit ---------- */
            if ($multiprocess) {
                error_log("[31] Child exiting cleanly | PID " . getmypid());
                exit(0);
            }
        }
    }

    error_log("[32] LOOP END #$loop_counter");
}


// We wait for all forks to exit.
if ($multiprocess) {
    while (count($children)) {
      // We clean children list.
        reap_children();
        sleep(1);
    }
}

echo sprintf("Completed in %01.2f seconds.\n", microtime(true) - $global_start_time);

clear_process_lock("create_previews");
