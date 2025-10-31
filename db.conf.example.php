<?php
$host = ''; // Replace with your database host
$db   = ''; // Replace with your database name
$user = ''; // Replace with your database username
$pass = ''; // Replace with your database password

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

mysqli_set_charset($conn, "utf8");

?>




