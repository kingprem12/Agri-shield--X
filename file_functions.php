<?php

function read_ini_file($config_file, $section, $key)
{
	
	$ini_array = parse_ini_file($config_file, true /* will scope sectionally */);
	return isset($ini_array[$section][$key]) ? ($ini_array[$section][$key]) : NULL; #prints fooooo
}

function write_ini_file($config_file, $section, $key, $value)
{
    $config_data = file_exists($config_file) ? parse_ini_file($config_file, true) : NULL;
    $config_data[$section][$key] = $value;
    $new_content = '';
    foreach ($config_data as $section => $section_content)
{
        $section_content = array_map(function($value, $key)
{
            return "$key=$value";
        }, array_values($section_content), array_keys($section_content));
        $section_content = implode("\n", $section_content);
        $new_content .= "[$section]\n$section_content\n";
    }
    file_put_contents($config_file, $new_content);
}

function delete_ini_file($config_file, $section, $key)
{
    $config_data = parse_ini_file($config_file, true);
    $new_content = '';
    foreach ($config_data as $file_section => $section_content)
{
        $section_content = array_map(function($value, $key)
{
            return "$key=$value";
        }, array_values($section_content), array_keys($section_content));
		
		if($file_section == $section)
		{
			foreach($section_content as $inner_key => $inner_content)
			{
				if(substr($inner_content, 0, strpos($inner_content, '=')) == $key)
				{
					unset($section_content[$inner_key]);
				}
			}
		}
        $section_content = implode("\n", $section_content);
        $new_content .= "[$file_section]\n$section_content\n";
    }
    file_put_contents($config_file, $new_content);
}

function get_default_value($record)
{
	return read_ini_file("flat_file.ini", "default", $record);
}

function get_ini_value($section, $record)
{
	return read_ini_file("flat_file.ini", $section, $record);
}

function get_ini_value_in($file, $section, $record)
{
	return read_ini_file($file, $section, $record);
}

function foreach_ini_value($display_function)
{
	$ini_array = parse_ini_file("flat_file.ini", true /* will scope sectionally */);
	$keys = array_keys($ini_array);
	foreach($ini_array[$keys[0]] as $key => $value)
	{
		$display_function($key);
	}
}

function foreach_ini_value_in($display_function, $file)
{
	$ini_array = parse_ini_file($file, true /* will scope sectionally */);
	$keys = array_keys($ini_array);
	foreach($ini_array[$keys[0]] as $key => $value)
	{
		$display_function($key);
	}
}

function set_default_value($key, $value)
{
	write_ini_file("flat_file.ini", "default", $key, $value);
}

function set_ini_value($section, $key, $value)
{
	write_ini_file("flat_file.ini", $section, $key, $value);
}

function set_ini_value_in($file, $section, $key, $value)
{
	write_ini_file($file, $section, $key, $value);
}

function delete_default_value($key)
{
	delete_ini_file("flat_file.ini", "default", $key);
}

function delete_ini_value($section, $key)
{
	delete_ini_file("flat_file.ini", $section, $key);
}

function delete_ini_value_in($file, $section, $key)
{
	delete_ini_file($file, $section, $key);
}

?>