<?php
include "/opt/homebrew/var/www/include/boot.php";

$query = "SELECT ref FROM resource WHERE archive=3;";
$refs = ps_query($query, []);

print_r($refs);
foreach ($refs as $ref){
	echo $ref['ref'] . "\n";
	delete_resource($ref['ref']);
}