#!/opt/homebrew/opt/php@8.1/bin/php
<?php

date_default_timezone_set('America/New_York');

$source = '/Volumes/LTO9-85/rs_fs/';
$destination = '/Volumes/RS_FS_BACKUP/rs_fs';
$logfile = '/Users/libraryad/Documents/logs/rsync_rs_fs.log';

$command = '/opt/homebrew/bin/rsync -avh --delete --exclude="filestore" '
    . escapeshellarg($source) . ' '
    . escapeshellarg($destination) . ' 2>&1';

$timestamp = date('Y-m-d H:i:s');

$output = [];
$return_var = 0;

file_put_contents($logfile, "\n\n========== $timestamp ==========\n", FILE_APPEND);

exec($command, $output, $return_var);

file_put_contents($logfile, implode("\n", $output) . "\n", FILE_APPEND);
file_put_contents($logfile, "Exit Code: $return_var\n", FILE_APPEND);

exit($return_var);