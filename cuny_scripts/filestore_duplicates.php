<?php

$root = '/Volumes/LTO9-85/rs_fs';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS
    ),
    RecursiveIteratorIterator::SELF_FIRST
);

$results = [];

foreach ($iterator as $item)
{
    if (!$item->isDir()) {
        continue;
    }

    $basename = $item->getBasename();

    if (!preg_match('/^\d+_.+/', $basename)) {
        continue;
    }

    $dirPath = $item->getPathname();

    $files = glob($dirPath . '/*');

    if (!$files || count($files) === 0) {
        continue;
    }

    sort($files);

    $firstFile = $files[0];
    $fileBase = basename($firstFile);

   if (preg_match('/^(\d+)\D/', $fileBase, $m)) {
        $id = $m[1];

        if (!isset($results[$id])) {
            $results[$id] = [];
        }

        $results[$id][] = $dirPath;
    }
}

ksort($results, SORT_NUMERIC);

foreach ($results as $id => $paths) {

    if (count($paths) <= 1) {
        continue;
    }

    echo "ID: $id (" . count($paths) . " occurrences)" . PHP_EOL;

    foreach ($paths as $path) {
        echo "  - $path" . PHP_EOL;
    }

    echo PHP_EOL;
}