<?php

$lowlatencyfolder = '';
$lowlatencyfiles  = [];
$watch_folder = '/Volumes/CUNYTVMEDIA/archive_projects/_watch_for_ingest_RSsync';

$dir = opendir($watch_folder);
$has_json = false;

while (($file = readdir($dir)) !== false) {
    if (substr($file, -5) === '.json') {
        $has_json = true;
        break;
    }
}

closedir($dir);

if (!$has_json) exit();

include_once __DIR__ . "/../include/boot.php";
include __DIR__ . "/../pages/tools/staticsync.php";