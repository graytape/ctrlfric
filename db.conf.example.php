<?php
$host = ''; // Database host
$db   = ''; // Database name
$user = ''; // Database username
$pass = ''; // Database password

// Copy this variable into your existing db.conf.php. Keep false in production.
$show_errors = false;
ini_set('display_errors', $show_errors ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset('utf8mb4');
