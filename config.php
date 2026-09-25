<?php
/*
 * This version stores data in protected JSON files; no MySQL database is needed.
 */
/* Use a long random value before putting the project online. */
define('DEVICE_API_KEY', 'HER24305_9f4c2a7d61be83e0');
define('RAIN_WET_PERCENT', 30); // Supplied controller: above 30% means light/heavy rain.
define('SOIL_DRY_PERCENT', 40); // Supplied controller: below 40% means dry soil.
date_default_timezone_set('Asia/Kolkata');
?>
