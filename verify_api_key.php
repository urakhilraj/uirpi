<?php
session_start();
header('Content-Type: application/json');

// Log errors for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/html/php_errors.log');

// Check if user is authorized
if (!isset($_SESSION["rpidbauth"])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check if curl extension is loaded
if (!extension_loaded('curl')) {
    http_response_code(500);
    error_log('PHP cURL extension is not enabled in verify_api_key.php');
    echo json_encode(['success' => false, 'error' => 'PHP cURL extension is not enabled']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';

    if (empty($api_key)) {
        echo json_encode(['success' => false, 'error' => 'No API key provided']);
        exit;
    }

    // Test the API key with a simple OpenAI request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/models');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('cURL error in verify_api_key.php: ' . $curl_error);
        echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curl_error]);
        exit;
    }

    if ($http_code === 200) {
        echo json_encode(['success' => true]);
    } else {
        $error = json_decode($response, true);
        $error_message = isset($error['error']['message']) ? $error['error']['message'] : 'Unknown error (HTTP ' . $http_code . ')';
        error_log('OpenAI API error in verify_api_key.php: ' . $error_message);
        echo json_encode(['success' => false, 'error' => $error_message]);
    }
} else {
    http_response_code(405);
    error_log('Invalid request method in verify_api_key.php');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
