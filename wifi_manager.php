<?php
header('Content-Type: application/json');

function executeCommand($command) {
    $output = shell_exec($command . ' 2>&1');
    file_put_contents('/var/log/wifi_manager.log', date('Y-m-d H:i:s') . ": $command\n$output\n", FILE_APPEND);
    return $output;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'status':
        // Get current WiFi status
        $output = executeCommand('nmcli -t -f ACTIVE,SSID dev wifi');
        $lines = explode("\n", trim($output));
        $ssid = 'Not connected';
        foreach ($lines as $line) {
            if (strpos($line, 'yes:') === 0) {
                $ssid = explode(':', $line)[1];
                break;
            }
        }
        echo json_encode(['ssid' => $ssid]);
        break;

    case 'scan':
        // Scan for available WiFi networks
        executeCommand('sudo nmcli dev wifi rescan 2>/dev/null');
        sleep(2); // Wait for scan to complete
        $output = executeCommand('nmcli -t -f SSID,SIGNAL dev wifi');
        $lines = explode("\n", trim($output));
        $networks = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (!empty($parts[0])) {
                $networks[] = [
                    'ssid' => $parts[0],
                    'signal' => isset($parts[1]) ? $parts[1] : 'Unknown'
                ];
            }
        }
        echo json_encode(['networks' => $networks]);
        break;

    case 'connect':
        // Connect to a WiFi network
        $ssid = isset($_POST['ssid']) ? trim($_POST['ssid']) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';

        if (empty($ssid)) {
            echo json_encode(['success' => false, 'error' => 'SSID is required']);
            exit;
        }

        // Validate SSID and password
        if (strlen($ssid) > 32 || !preg_match('/^[a-zA-Z0-9\-_]+$/', $ssid)) {
            echo json_encode(['success' => false, 'error' => 'Invalid SSID']);
            exit;
        }
        if ($password && (strlen($password) < 8 || strlen($password) > 63)) {
            echo json_encode(['success' => false, 'error' => 'Password must be 8-63 characters']);
            exit;
        }

        // Escape SSID and password to prevent injection
        $ssid_escaped = escapeshellarg($ssid);
        $password_escaped = $password ? escapeshellarg($password) : '';

        // Attempt to connect using sudo
        $command = $password ?
            "sudo nmcli --ask dev wifi connect $ssid_escaped password $password_escaped" :
            "sudo nmcli --ask dev wifi connect $ssid_escaped";
        $output = executeCommand($command);

        if (strpos($output, 'successfully activated') !== false) {
            echo json_encode(['success' => true, 'message' => "Connected to $ssid"]);
        } else {
            $error_msg = $output;
            if (strpos($output, 'Secrets were required') !== false) {
                $error_msg = 'Incorrect password or network requires authentication';
            } elseif (strpos($output, 'No network with SSID') !== false) {
                $error_msg = 'Network not found';
            }
            echo json_encode(['success' => false, 'error' => 'Failed to connect: ' . $error_msg]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>