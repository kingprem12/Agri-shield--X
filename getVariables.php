<?php

/**************************************

If the HTTP request is:  http://127.0.0.1/project/getVariables.php?moisture=1&root>temperature=1&new_db>data>switch_status=1&no_seperator=1


The values will be loaded from flat_file.ini, under >>> [default] section for moisture
The values will be loaded from flat_file.ini, under >>> [root] section for temperature
The values will be loaded from new_db.ini, under >>> [data] section for switch_status

The response will be: *moisture_value*temperature_value*switch_status#

If no_seperator=1 is appended the response will be: moisture_value-temperature_value-switch_status with no seperators ( - included for clarity)

If the HTTP request is:  http://127.0.0.1/project/getVariables.php?root>temperature=1

If single variable is requested, only the variable is sent

The values will be loaded from flat_file.ini, under >>> [root] section for temperature

The response will be: temperature_value

***************************************/

include('file_functions.php');

$variable_count = 0;
$content = "";
$no_seperator = 0;

foreach($_GET as $key => $value)
{
	if(isset($value) AND $value == 1)
	{
		if(strpos($key, '>') === FALSE)
		{
			if($key == "no_seperator")
			{
				$no_seperator = 1;
			}
			else
			{
				$content .= "*" . get_default_value($key);
			}
		}
		else
		{
			if(substr_count($key, '>') == 2)
			{
				$file = substr($key, 0, strpos($key, '>')) . ".ini";
				$key = substr($key, strpos($key, '>') + 1);
				$section = substr($key, 0, strpos($key, '>'));
				$name = substr($key, strpos($key, '>') + 1);
				
				$content .= "*" . get_ini_value_in($file, $section, $name);
			}
			else
			{
				$section = substr($key, 0, strpos($key, '>'));
				$name = substr($key, strpos($key, '>') + 1);
				
				$content .= "*" . get_ini_value($section, $name);
			}
		}
	}
	$variable_count++;
}
if($variable_count == 1)
{
	echo substr($content, 1) . "#";
}
else
{
	if($no_seperator == 1)
	{
		echo str_replace("*", "", $content);
	}
	else
	{
		echo $content . "#";
	}
}

?>