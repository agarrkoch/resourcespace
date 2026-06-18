<?php

include "/opt/homebrew/var/www/include/boot.php";

$resource_type = 1; //photos
$date = date('Y-m-d');
$start = $date . " 00:00:00";
$end   = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
$latest_name_node = (int) trim(file_get_contents(__DIR__ . '/update_original_metadata_latest_name_node.txt'));

function XMP_name_exists($name, $file) {
	$cmd = "exiftool -j -XMP-iptcExt:PersonInImage " .
	       escapeshellarg($file);
	$output = shell_exec($cmd);

	if (trim($output) == "") {
	    return false;
	} else {
		$data = json_decode($output, true);
		$value = $data[0]['PersonInImage'] ?? [];
		$people = is_array($value)
		    ? $value
		    : ($value !== '' ? [$value] : []);
		if (in_array($name, $people)) {
		    return true;
		} else {
		    return false;
		}
	}
}

function add_XMP_name($name, $file) {
	$cmd = "exiftool -XMP-iptcExt:PersonInImage+=" . escapeshellarg($name) . " " . 
	       escapeshellarg($file);
	$output = shell_exec($cmd);
}

function create_new_checksum($file){
	global $file_checksums_50k;
    if ($file_checksums_50k) {
        $use = filesize_unlimited($file) . "_" . file_get_contents($file, false, null, 0, 50000);
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

$query = "SELECT rf.ref, rf.resource, rf.created, rf.node, n.name, r.file_path FROM resource_face rf INNER JOIN resource r ON rf.resource = r.ref INNER JOIN node n ON rf.node = n.ref WHERE node IS NOT NULL AND resource IN (SELECT ref FROM resource WHERE resource_type = ?) AND ((rf.created >= ? AND rf.created < ?) OR (rf.node IN (SELECT ref FROM node WHERE ref > ?)));";

$faces = ps_query($query, ['i', $resource_type, 's', $start, 's', $end, 'i', $latest_name_node]);

foreach ($faces as $row) {
	$file_path = $syncdir . '/' . $row['file_path'];
	$name = $row['name'];
	$resource_ref = $row['resource'];
	
	$file_exists = file_exists($file_path);
	$name_exists = false;

	if ($file_exists){
	    $name_exists = XMP_name_exists($name, $file_path);
	}

	if ($file_exists && !$name_exists){
	    add_XMP_name($name, $file_path);
	    echo "$name added to $file_path" . PHP_EOL;
		update_db_checksum($resource_ref, $file_path);
	}
	else{
	    echo "$name WAS NOT added to $file_path" . PHP_EOL;
	    echo "File exists: " . ($file_exists ? 'true' : 'false') . PHP_EOL;
	    echo "XMP name exists: " . ($name_exists ? 'true' : 'false') . PHP_EOL;
	}

	echo "------------" . PHP_EOL;
}

update_text_file();

