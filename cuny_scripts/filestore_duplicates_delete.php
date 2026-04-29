<?php

include_once __DIR__ . "/../include/boot.php";

$input = "/opt/homebrew/var/www/cuny_scripts/duplicate_filestore_ids.txt";

$lines = file($input, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$current_id = null;
$paths = [];

foreach ($lines as $line)
{
    if (preg_match('/^ID:\s+(\d+)/', $line, $m))
    {
        process_paths($current_id, $paths);

        $current_id = (int)$m[1];
        $paths = [];
        continue;
    }

    if (preg_match('/-\s+(\/.+)$/', $line, $m))
    {
        $paths[] = trim($m[1]);
    }
}

process_paths($current_id, $paths);


function normalize_path($path)
{
    // Remove extension
    $path = preg_replace('/\.[^.]+$/', '', $path);

    // Keep only relative filestore structure
    if (preg_match('#(?:filestore|rs_fs)/(.*)$#', $path, $m))
    {
        return $m[1];
    }

    return $path;
}


function process_paths($ref, $paths)
{
    if (!$ref || count($paths) < 2)
    {
        return;
    }

    $canonical = dirname(get_resource_path($ref, true, "", false));

    $canonical_norm = normalize_path($canonical);

    echo PHP_EOL;
    echo "RESOURCE {$ref}" . PHP_EOL;
    echo "Canonical: {$canonical_norm}" . PHP_EOL;

    foreach ($paths as $p)
    {
        $path_norm = normalize_path($p);

        if ($path_norm === $canonical_norm)
        {
            echo "KEEP   {$p}" . PHP_EOL;
        }
        else
        {
            echo "DELETE {$p}" . PHP_EOL;

            // Uncomment after testing
            // exec('trash ' . escapeshellarg($p));
        }
    }
}