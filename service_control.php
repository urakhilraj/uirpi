<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 'On');

$response = ['success' => false, 'error' => ''];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $service = 'kiosk-browser.service';

    // Verify sudo permissions before executing
    $sudo_test = shell_exec('sudo -u www-data sudo -n true 2>&1');
    if (!empty($sudo_test) && strpos($sudo_test, 'password') !== false) {
        $response['error'] = 'Sudo permission denied: www-data requires passwordless sudo for systemctl commands';
        echo json_encode($response);
        exit;
    }

    switch ($action) {
        case 'reload':
            $output = shell_exec("sudo systemctl restart $service 2>&1");
            if ($output === null || strpos($output, 'Failed') !== false) {
                $response['error'] = $output ?: 'Unknown error during reload';
            } else {
                $response['success'] = true;
            }
            break;
        case 'start':
            $output = shell_exec("sudo systemctl enable $service 2>&1 && sudo systemctl start $service 2>&1");
            if ($output === null || strpos($output, 'Failed') !== false) {
                $response['error'] = $output ?: 'Unknown error during start';
            } else {
                $response['success'] = true;
            }
            break;
        case 'stop':
            $output = shell_exec("sudo systemctl disable $service 2>&1 && sudo systemctl stop $service 2>&1");
            if ($output === null || strpos($output, 'Failed') !== false) {
                $response['error'] = $output ?: 'Unknown error during stop';
            } else {
                $response['success'] = true;
            }
            break;
        default:
            $response['error'] = 'Invalid action';
    }
} else {
    $response['error'] = 'Invalid request';
}

echo json_encode($response);
?>