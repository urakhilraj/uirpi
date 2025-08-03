<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 'On');

$poster_dir = "posters/";
$settings_file = $poster_dir . "poster_settings.json";

$response = ['success' => false, 'error' => ''];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Load existing settings
    $settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];

    // Check if original_filename is provided
    if (!isset($_POST["original_filename"]) || empty($_POST["original_filename"])) {
        $response['error'] = 'No original filename provided.';
        echo json_encode($response);
        exit;
    }

    $original_filename = basename($_POST["original_filename"]);
    $original_file = $poster_dir . $original_filename;

    if (!file_exists($original_file)) {
        $response['error'] = 'Original file not found.';
        echo json_encode($response);
        exit;
    }

    // Check if a new file is uploaded
    if (isset($_FILES["new_poster_file"]) && $_FILES["new_poster_file"]["size"] > 0) {
        $target_file = $poster_dir . basename($_FILES["new_poster_file"]["name"]);
        $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'mp4'];

        // Check if file is an image or video
        $is_image = in_array($fileType, ['jpg', 'jpeg', 'png', 'gif']);
        $is_video = $fileType === 'mp4';
        $check = $is_image ? getimagesize($_FILES["new_poster_file"]["tmp_name"]) : true;

        if ($check !== false || $is_video) {
            // Verify resolution for images only
            if ($is_image && ($check[0] != 1280 || $check[1] != 800)) {
                $response['error'] = 'Image resolution must be exactly 1280x800 pixels.';
            } elseif (in_array($fileType, $allowed_types)) {
                // Check file size (max 5MB)
                if ($_FILES["new_poster_file"]["size"] <= 5000000) {
                    // Delete original file
                    unlink($original_file);
                    // Move new file
                    if (move_uploaded_file($_FILES["new_poster_file"]["tmp_name"], $target_file)) {
                        // Update settings with new filename
                        if (isset($settings[$original_filename])) {
                            $settings[basename($_FILES["new_poster_file"]["name"])] = $settings[$original_filename];
                            unset($settings[$original_filename]);
                            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
                        }
                        $response['success'] = true;
                    } else {
                        $response['error'] = 'Error uploading new file.';
                    }
                } else {
                    $response['error'] = 'File is too large. Maximum size is 5MB.';
                }
            } else {
                $response['error'] = 'Only JPG, JPEG, PNG, GIF, and MP4 files are allowed.';
            }
        } else {
            $response['error'] = 'File is not a valid image or video.';
        }
    } else {
        $response['error'] = 'No new file uploaded.';
    }
} else {
    $response['error'] = 'Invalid request method. Use POST.';
}

echo json_encode($response);
?>