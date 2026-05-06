<?php

include_once __DIR__ . "/../include/boot.php";

/**
* Resorts collections within a parent collection
*
* @uses ps_query()
*
* @param integer $ref              Parent collection name
* @param string $order_by          ASC or DESC
* @param string $sort_column       name or created; default name
*
* @return string
*/
function resort_collections(
    $ref,
	$order_by,
	$sort_column='name'
) {
    $query = "SELECT ref FROM collection WHERE parent=? ORDER BY $sort_column $order_by;";	   
    $c_array = ps_query($query, ['i', $ref]);
	
	$i = 10;
	foreach ($c_array as $c){
	    $query = "UPDATE collection SET order_by = ? WHERE ref = ?;";	   
	     ps_query($query, ['i', $i, 'i', $c['ref']]);
		 $i += 10;
	}
}

/**
* Resorts collections within a parent collection
*
* @uses ps_query()
*
* @param integer $ref              Parent collection name
* @param string $order_by          ASC or DESC
* @param string $sort_column       field8; default name
*
* @return string
*/
function resort_resource_collection(
    $ref,
	$order_by,
	$sort_column='field8'
) {
    $query = "SELECT cr.resource FROM collection_resource cr JOIN resource r ON r.ref = cr.resource WHERE cr.collection = ? order by r.$sort_column $order_by;";	   
    $c_array = ps_query($query, ['i', $ref]);
	
	$i = 1;
	foreach ($c_array as $c){
	    $query = "UPDATE collection_resource SET sortorder = ? WHERE resource = ?;";	   
	     ps_query($query, ['i', $i, 'i', $c['resource']]);
		 $i += 1;
	}
}