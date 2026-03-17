<?php
include_once "/opt/homebrew/var/www/plugins/normalize_show_name/cunymediaids.php";

function HookStaticsync_sanitize_mapped_fieldAllStaticsync_mapvalue($resource, $value, $field=null)
{
    global $shows_dict;
    $formatted_value = ucfirst(str_replace('_', ' ', $value));

    if ($field == 89) { //89 is corresponds to prodution title field
        
        $show = CheckShowName($formatted_value, $shows_dict);
        
        if ($show != $value) {
            $formatted_value = $show;
            echo " - Updating field with value: {$show}" . PHP_EOL;
        }
		
		CheckShowNode($formatted_value);
    }
	
	if ($field == 88) {
	    if (stripos($value, 'Camera Card Delivery') !== false) {
	        return 'Remote';
	    } elseif (stripos($value, 'Studio') !== false) {
	        return 'Studio';
	    } elseif (stripos($value, 'Remote') !== false) {
	        return 'Remote';
	    }
	}

    return $formatted_value;
}