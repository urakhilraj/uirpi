<?php
// load_robo_data.php
header('Content-Type: text/plain');
try {
    $promptFile = 'acubotzPrompt.txt';
    if (file_exists($promptFile)) {
        echo file_get_contents($promptFile);
    } else {
        echo '';
    }
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error loading prompt data';
}
?>