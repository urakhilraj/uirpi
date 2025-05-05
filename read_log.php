<?php
session_start();
header('Content-Type: text/plain');

// Check if user is authenticated
if (!isset($_SESSION["rpidbauth"])) {
    http_response_code(403);
    echo "Unauthorized access";
    exit;
}

$logFile = 'program_log.txt';
try {
    if (file_exists($logFile)) {
        // Read the last 1000 lines to avoid overwhelming the browser
        $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -1000);
        echo implode("\n", $lines);
    } else {
        echo "Log file not found";
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "Error reading log file: " . $e->getMessage();
}
?>
