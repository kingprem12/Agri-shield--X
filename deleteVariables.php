<?php

include('file_functions.php');

if(isset($_GET['RECORD']))
{
	if(strpos($_GET['RECORD'], '>') === FALSE)
	{
		$ini_array = parse_ini_file("flat_file.ini", true /* will scope sectionally */);
		
		foreach($ini_array as $section => $keys)
		{
			if($section != "default")
			{
				delete_ini_value($section, $_GET['RECORD']);
			}
		}
	}
	else
	{
		$file = substr($_GET['RECORD'], 0, strpos($_GET['RECORD'], '>')) . ".ini";
		$field = substr($_GET['RECORD'], strpos($_GET['RECORD'], '>') + 1);
		
		$ini_array = parse_ini_file($file, true /* will scope sectionally */);
		
		foreach($ini_array as $section => $keys)
		{
			if($section != "default")
			{
				delete_ini_value_in($file, $section, $field);
			}
		}
		
	}
}

if(isset($_GET['FIELD']))
{
	if(strpos($_GET['FIELD'], '>') === FALSE)
	{
		$ini_array = parse_ini_file("flat_file.ini", true /* will scope sectionally */);
		
		foreach($ini_array as $section => $keys)
		{
			if($section == $_GET['FIELD'])
			{
				foreach($keys as $key => $value)
				{
					delete_ini_value($section, $_GET['RECORD']);
				}
			}
			
		}
	}
	else
	{
		$file = substr($_GET['FIELD'], 0, strpos($_GET['FIELD'], '>')) . ".ini";
		$field = substr($_GET['FIELD'], strpos($_GET['FIELD'], '>') + 1);
		
		$ini_array = parse_ini_file($file, true /* will scope sectionally */);
		
		foreach($ini_array as $section => $keys)
		{
			if($section == $_GET['FIELD'])
			{
				foreach($keys as $key => $value)
				{
					delete_ini_value_in($file, $section, $field);
				}
			}
			
		}
	}
}


foreach($_GET as $key => $value)
{
	if(isset($value) AND $value == 1)
	{
		if(strpos($key, '>') === FALSE)
		{
			delete_default_value($key);
		}
		else
		{
			if(substr_count($key, '>') == 2)
			{
				$file = substr($key, 0, strpos($key, '>')) . ".ini";
				$key = substr($key, strpos($key, '>') + 1);
				$section = substr($key, 0, strpos($key, '>'));
				$field = substr($key, strpos($key, '>') + 1);
				
				delete_ini_value_in($file, $section, $field);
			}
			else
			{
				$section = substr($key, 0, strpos($key, '>'));
				$field = substr($key, strpos($key, '>') + 1);
				
				delete_ini_value($section, $field);
			}
		}
	}
}

if(isset($_GET['return_back']) AND $_GET['return_back'] == 1)
{
	if(isset($_GET['return_url']))
	{
		header("location:" . $_GET['return_url']);
	}
	else
	{
		header("location:app.php");
	}
}

?>