<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 'On');

header('Content-Type: application/json');

// Check if user is authenticated
$auth = isset($_SESSION["rpidbauth"]) ? true : false;
if (!$auth) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'scan':
        // Scan for available WiFi networks using iwlist
        $output = shell_exec('sudo iwlist wlan0 scan | grep ESSID');
        $networks = [];
        if ($output) {
            preg_match_all('/ESSID:"(.*?)"/', $output, $matches);
            $networks = array_unique($matches[1]);
        }
        echo json_encode(['networks' => $networks]);
        break;

    case 'connect':
        // Connect to a WiFi network
        $ssid = isset($_POST['ssid']) ? $_POST['ssid'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if (empty($ssid)) {
            echo json_encode(['error' => 'SSID is required']);
            exit();
        }

        // Generate wpa_supplicant configuration
        $config = "network={\n";
        $config .= "\tssid=\"$ssid\"\n";
        $config .= "\tpsk=\"$password\"\n";
        $config .= "}\n";

        // Save configuration to a temporary file
        file_put_contents('/tmp/wpa_supplicant.conf', $config, FILE_APPEND);

        // Apply configuration and restart networking
        $result = shell_exec('sudo mv /tmp/wpa_supplicant.conf /etc/wpa_supplicant/wpa_supplicant.conf && sudo systemctl restart networking');
        if ($result === null) {
            echo json_encode(['success' => true, 'message' => 'Connected to ' . $ssid]);
        } else {
            echo json_encode(['error' => 'Failed to connect']);
        }
        break;

    case 'status':
        // Get current WiFi status
        $status = shell_exec('iwconfig wlan0 | grep ESSID');
        preg_match('/ESSID:"(.*?)"/', $status, $match);
        $current_ssid = isset($match[1]) ? $match[1] : 'Not connected';
        echo json_encode(['ssid' => $current_ssid]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>