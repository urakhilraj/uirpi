<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION["rpidbauth"]) || $_SESSION["rpidbauth"] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$status = shell_exec("iwgetid -r") ?: "Not connected";
$signal = shell_exec("cat /proc/net/wireless | grep wlan0 | awk '{print $4}'") ?: "N/A";

echo json_encode([
    'status' => trim($status),
    'signal' => trim($signal) . ' dBm'
]);
?>