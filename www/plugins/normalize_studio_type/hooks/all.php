<?php

function HookNormalize_studio_typeAllUpdate_field($resource, $field, $value, $existing, $fieldinfo, $newnodes, $newvalues)
{
    $st_field = 90;
        
    if ($field == $st_field) {
        echo " - Field matched {$st_field}, processing value..." . PHP_EOL;
		$new_value = '';
		if (str_contains(strtolower($value), 'iso')) {
		    $new_value = 'ISO';
		}
		if (str_contains(strtolower(str_replace(' ', '', $value)), 'linecut')) {
		    $new_value = 'Line Cut';
		}
		
        
        if ($new_value != $value) {
            echo " - Updating field with value: {$new_value} . PHP_EOL";
            update_field($resource, $field, $new_value);
        }
    }
}
