<?php
session_start();
header('Content-Type: text/plain');

// Log errors for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/html/php_errors.log');

// Check if user is authorized
if (!isset($_SESSION["rpidbauth"])) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

$file_path = '/var/www/html/api_key.txt';
if (file_exists($file_path)) {
    $content = file_get_contents($file_path);
    if ($content === false) {
        error_log('Error reading api_key.txt in load_api_key.php');
        echo "";
    } else {
        echo trim($content);
    }
} else {
    error_log('api_key.txt does not exist in load_api_key.php');
    echo "";
}
?>
