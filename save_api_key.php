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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';
    
    if (empty($api_key)) {
        http_response_code(400);
        error_log('No API key provided in save_api_key.php');
        echo "No API key provided";
        exit;
    }

    $file_path = '/var/www/html/api_key.txt';
    
    // Attempt to write the API key to the file
    if (file_put_contents($file_path, $api_key) === false) {
        http_response_code(500);
        error_log('Error writing to api_key.txt in save_api_key.php. Check file permissions.');
        echo "Error writing to api_key.txt. Check file permissions.";
        exit;
    }
    
    // Set file permissions to secure the API key
    if (!chmod($file_path, 0600)) {
        error_log('Failed to set permissions on api_key.txt in save_api_key.php');
    }
    
    echo "API key saved successfully";
} else {
    http_response_code(405);
    error_log('Invalid request method in save_api_key.php');
    echo "Method not allowed";
}
?>
