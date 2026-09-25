<?php
	include('file_functions.php');
	
	set_default_value("level", $_GET['level']);
	echo get_default_value("feed_now") . "#";
?>