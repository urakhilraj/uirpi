<?php
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["data"])) {
    $data = trim($_POST["data"]);

    if (!empty($data)) {
        file_put_contents("acubotzPrompt.txt", $data);
        echo "Success";
    } else {
        echo "No data provided";
    }
} else {
    echo "Invalid request";
}
?>
