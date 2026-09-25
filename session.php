<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
if (!isset($_SESSION['normal_user'])) { header('Location: index.php'); exit; }
?>
