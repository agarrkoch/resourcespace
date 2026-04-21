<?php

include_once __DIR__ . "/../include/boot.php";

if (is_process_lock("staticsync")) {
    echo 'Process lock is in place. Deferring.' . PHP_EOL;
    exit();
}

$watch_folder = '/Volumes/CUNYTVMEDIA/archive_projects/_watch_for_ingest_RSsync';

if (!is_dir($watch_folder)) {
    exit("Invalid directory\n");
}

$iterator = new FilesystemIterator($watch_folder);

foreach ($iterator as $file) {
    if (
        $file->isFile() &&
        str_ends_with($file->getFilename(), '.json')
    ) {
        $filepath = $file->getPathname();

        // Skip partially written files
        $size1 = filesize($filepath);
        sleep(1);
        clearstatcache(true, $filepath);
        $size2 = filesize($filepath);

        if ($size1 !== $size2) {
            continue;
        }

        $json = file_get_contents($filepath);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Bad JSON: $filepath\n";
            continue;
        }

        $lowlatencyfolder = $data['folder'] ?? '';
        $lowlatencyfiles  = $data['files'] ?? [];

        echo "Processing: $filepath\n";

        include __DIR__ . "/../pages/tools/staticsync.php";

        unlink($filepath);
    }
}