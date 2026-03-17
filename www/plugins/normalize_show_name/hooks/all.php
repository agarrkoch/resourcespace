<?php
require_once '/opt/homebrew/var/www/plugins/normalize_show_name/cunymediaids.php';

function HookNormalize_show_nameAllUpdate_field($resource, $field, $value, $existing, $fieldinfo, $newnodes, $newvalues)
{
    $st_field = 89;
        
    // Ensure that the field ID matches the one we're interested in
    if ($field == $st_field) {	
        $show = CheckShowName($value);
        
        if ($show != $value) {
            echo " - Updating field with value: {$show}" . PHP_EOL;
            update_field($resource, $field, $show);
        }
	}
}

function CheckShowName($value){
	global $shows_dict;
    $value_c = str_replace('_', ' ', $value);
    
    $show = check_similarity($value_c, $shows_dict);
    echo " - Similarity check result: {$show}" . PHP_EOL;
    
    return $show;
	
}

function CheckShowNode($show){
	$nodes = get_nodes(89);
	$match_id = 0;
		
	foreach ($nodes as $node) {
	    if (isset($node["name"]) && $node["name"] === $show) {
	        $match_id = $node["ref"];
	        break;
	    }
	}
	
	if ($match_id == 0){
		set_node(null, 89, $show, null, '');
	}	
}
