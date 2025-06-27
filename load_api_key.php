<?php
session_start();
if (!isset($_SESSION["rpidbauth"])) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

$file_path = '/var/www/html/api_key.txt';
if (file_exists($file_path)) {
    echo file_get_contents($file_path);
} else {
    echo "";
}
?>