<?php

/**************************************

If the HTTP request is:  http://127.0.0.1/project/setVariables.php?moisture=11&root>temperature=31&new_db>data>switch_status=1

The response will be: NULL

The values will be updated or created in flat_file.ini, under >>> [default] section puts >>> moisture=11
The values will be updated or created in flat_file.ini, under >>> [root] section puts >>> temperature=31
Creates new_db.ini if not exists and the values will be updated or created under >>> [data] section puts >>> switch_status=1; 

If return_back is set to 1: the page redirects to app.php

If return_url is set the page redirects to the set page

***************************************/

include('file_functions.php');

foreach($_GET as $key => $value)
{
	if(strpos($key, '>') === FALSE)
	{
		if($key != "return_back" AND $key != "return_url")
		{
			set_default_value($key, $value);
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
			
			set_ini_value_in($file, $section, $name, $value);
		}
		else
		{
			$section = substr($key, 0, strpos($key, '>'));
			$name = substr($key, strpos($key, '>') + 1);
			
			set_ini_value($section, $name, $value);
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