<?php
session_start();
if (!isset($_SESSION["rpidbauth"])) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';
    
    if (empty($api_key)) {
        echo "No API key provided";
        exit;
    }

    $file_path = '/var/www/html/api_key.txt';
    
    // Ensure the web server has write permissions
    if (file_put_contents($file_path, $api_key) === false) {
        http_response_code(500);
        echo "Error writing to api_key.txt. Check file permissions.";
        exit;
    }
    
    // Set file permissions to secure the API key
    chmod($file_path, 0600);
    
    echo "API key saved successfully";
} else {
    http_response_code(405);
    echo "Method not allowed";
}
?>