<?php
/*
 * This version stores data in protected JSON files; no MySQL database is needed.
 */
/* The device API key lives in config.local.php (gitignored); see config.local.example.php. */
if (file_exists(__DIR__ . '/config.local.php')) require_once __DIR__ . '/config.local.php';
if (!defined('DEVICE_API_KEY')) define('DEVICE_API_KEY', '');
define('RAIN_WET_PERCENT', 30); // Supplied controller: above 30% means light/heavy rain.
define('SOIL_DRY_PERCENT', 40); // Supplied controller: below 40% means dry soil.
date_default_timezone_set('Asia/Kolkata');
?>
