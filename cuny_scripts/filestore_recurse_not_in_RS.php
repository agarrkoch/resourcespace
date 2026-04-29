<?php

include_once __DIR__ . "/../include/boot.php";


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

	if (!preg_match('/^\d+\D.+/', $basename)) {
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

    if (preg_match('/^(\d+)_/', $fileBase, $m)) {
        $id = $m[1];

        if (!isset($results[$id])) {
            $results[$id] = [];
        }
        $results[$id][] = $dirPath;
    }
}

// sort IDs
ksort($results, SORT_NUMERIC);

// output
foreach ($results as $id => $paths) {

    foreach ($paths as $path) {
	    $query = "SELECT ref FROM resource WHERE ref = ?;";
	    $match = ps_query($query, ['i', $id]);
		
		if (empty($match)) {
			echo $id . " => " . $path . PHP_EOL;
		}
    }
}