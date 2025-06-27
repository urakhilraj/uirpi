<?php
session_start();
if (!isset($_SESSION["rpidbauth"])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
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

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        echo json_encode(['success' => true]);
    } else {
        $error = json_decode($response, true);
        $error_message = isset($error['error']['message']) ? $error['error']['message'] : 'Unknown error';
        echo json_encode(['success' => false, 'error' => $error_message]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}