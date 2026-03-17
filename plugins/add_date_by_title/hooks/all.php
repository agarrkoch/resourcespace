<?php
#After_update_resource
//function HookAdd_date_by_titleAllAfter_update_resource($resourceId)
//{
//    $dt_field = 12;
//	$string = get_title($resourceId);
//	$date = extractDateFromString($string);
	
//	if (is_null($date)) {
//	        return; // exit the function if $date is null
//	    }
	
//	update_field($resourceId, $dt_field, $date);       
//}

function HookAdd_date_by_titleAllUpdate_field($resource,$field,$value,$existing,$fieldinfo,$newnodes,$newvalues)
{	
	if ($field != 8){ // 8 corresponds to title metadata field
		return;
	}
	
    $dt_field = 12;
	$date = extractDateFromString($value);
	
	if (is_null($date)) {
	        return; // exit the function if $date is null
	    }
	
	update_field($resource, $dt_field, $date);       
}

// useful only for After_update_resource hook
function get_title($r) {
    $data = get_resource_field_data($r);
	$title = null;

	foreach ($data as $item) {
	    if (isset($item['resource_type_field']) && $item['resource_type_field'] == 8) {
	        $title = $item['value'];
	        break;
	    }
	}
    
    return $title;
}


function extractDateFromString($string) {
    // Find all 8-digit numbers
    preg_match_all('/\d{8}/', $string, $matches);
	
    foreach ($matches[0] as $match) {
        // Validate date format
        $year = substr($match, 0, 4);
        $month = substr($match, 4, 2);
        $day = substr($match, 6, 2);
		
        if (checkdate((int)$month, (int)$day, (int)$year)) {
            return "$year-$month-$day"; // Return the first valid date found
        }
    }

    return null; // No valid date found
}