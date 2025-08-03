<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <link rel="apple-touch-icon" sizes="180x180" href="rpidb_ico/apple-touch-icon.png?v=PYAg5Ko93z">
    <link rel="icon" type="image/png" sizes="32x32" href="rpidb_ico/favicon-32x32.png?v=PYAg5Ko93z">
    <link rel="icon" type="image/png" sizes="16x16" href="rpidb_ico/favicon-16x16.png?v=PYAg5Ko93z">
    <link rel="manifest" href="rpidb_ico/site.webmanifest?v=PYAg5Ko93z">
    <link rel="mask-icon" href="rpidb_ico/safari-pinned-tab.svg?v=PYAg5Ko93z" color="#b91d47">
    <link rel="shortcut icon" href="rpidb_ico/favicon.ico?v=PYAg5Ko93z">
    <meta name="apple-mobile-web-app-title" content="Raspberry Pi Dashboard">
    <meta name="application-name" content="Raspberry Pi Dashboard">
    <meta name="msapplication-TileColor" content="#b91d47">
    <meta name="msapplication-TileImage" content="rpidb_ico/mstile-144x144.png?v=PYAg5Ko93z">
    <meta name="msapplication-config" content="rpidb_ico/browserconfig.xml?v=PYAg5Ko93z">
    <meta name="theme-color" content="#b91d47">

    <link rel="stylesheet" href="css/bootstrap-5.3.2.min.css">
    <link rel="stylesheet" href="css/bootstrap-icons-1.11.1.css">
    <link rel="stylesheet" href="css/mdtoast.min.css?v=2.0.2">

    <title><?php system("hostname"); ?> - Poster Manager</title>

    <style>
        .poster-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .poster-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px;
        }
        .poster-item img, .poster-item video {
            max-width: 100px;
            max-height: 100px;
            margin-right: 10px;
        }
        [data-bs-theme="dark"] .poster-item {
            background-color: #343a40;
            color: #e9ecef;
        }
        .alert {
            margin-bottom: 10px;
        }
        #viewPosterImage, #viewPosterVideo {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }
        [data-bs-theme="dark"] #viewPosterImage, [data-bs-theme="dark"] #viewPosterVideo {
            background-color: #343a40;
        }
        .slider-settings {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .slider-settings input[type="number"] {
            width: 80px;
        }
    </style>

    <?php
    session_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');

    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    require "backend/Config.php";
    $config = new Config;
    $config->load("local.config", "defaults.php");

    // Check authentication
    $auth = (isset($_SESSION["rpidbauth"])) ? true : false;
    if (!$auth) {
        header("Location: index.php");
        exit;
    }

    // Directory for storing posters and settings
    $poster_dir = "posters/";
    if (!is_dir($poster_dir)) {
        mkdir($poster_dir, 0755, true);
    }
    $settings_file = $poster_dir . "poster_settings.json";

    // Load existing settings
    $settings = [];
    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true) ?: [];
    }

    // List all media files
    $media_files = glob($poster_dir . "*.{jpg,jpeg,png,gif,mp4}", GLOB_BRACE);

    // Handle file upload
    $upload_message = "";
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["poster_file"])) {
        $target_file = $poster_dir . basename($_FILES["poster_file"]["name"]);
        $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'mp4'];

        // Check if file is an image or video
        $is_image = in_array($fileType, ['jpg', 'jpeg', 'png', 'gif']);
        $is_video = $fileType === 'mp4';
        $check = $is_image ? getimagesize($_FILES["poster_file"]["tmp_name"]) : true;

        if ($check !== false || $is_video) {
            // Verify resolution for images only
            if ($is_image && ($check[0] != 1280 || $check[1] != 800)) {
                $upload_message = '<div class="alert alert-danger">Image resolution must be exactly 1280x800 pixels.</div>';
            } elseif (in_array($fileType, $allowed_types)) {
                // Check file size (max 5MB)
                if ($_FILES["poster_file"]["size"] <= 5000000) {
                    if (move_uploaded_file($_FILES["poster_file"]["tmp_name"], $target_file)) {
                        $upload_message = '<div class="alert alert-success">File uploaded successfully!</div>';
                        // Initialize settings for new file
                        $filename = basename($_FILES["poster_file"]["name"]);
                        $settings[$filename] = [
                            'include_in_slider' => true,
                            'order' => count($settings) + 1,
                            'duration' => 5
                        ];
                        file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
                    } else {
                        $upload_message = '<div class="alert alert-danger">Error uploading file.</div>';
                    }
                } else {
                    $upload_message = '<div class="alert alert-danger">File is too large. Maximum size is 5MB.</div>';
                }
            } else {
                $upload_message = '<div class="alert alert-danger">Only JPG, JPEG, PNG, GIF, and MP4 files are allowed.</div>';
            }
        } else {
            $upload_message = '<div class="alert alert-danger">File is not a valid image or video.</div>';
        }
    }

    // Handle poster deletion
    if (isset($_GET["delete"])) {
        $file_to_delete = $poster_dir . basename($_GET["delete"]);
        $filename = basename($_GET["delete"]);
        if (file_exists($file_to_delete)) {
            unlink($file_to_delete);
            if (isset($settings[$filename])) {
                unset($settings[$filename]);
                file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
            }
            $upload_message = '<div class="alert alert-success">File deleted successfully!</div>';
        } else {
            $upload_message = '<div class="alert alert-danger">File not found.</div>';
        }
    }

    // Handle slider settings update
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_settings"])) {
        $new_settings = [];
        if (!empty($media_files)) {
            foreach ($media_files as $media) {
                $filename = basename($media);
                $new_settings[$filename] = [
                    'include_in_slider' => isset($_POST["include_in_slider"][$filename]) && $_POST["include_in_slider"][$filename] === 'on',
                    'order' => isset($_POST["order"][$filename]) ? (int)$_POST["order"][$filename] : 0,
                    'duration' => isset($_POST["duration"][$filename]) ? (float)$_POST["duration"][$filename] : 5
                ];
            }
        }
        $settings = $new_settings;
        file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
        $upload_message = '<div class="alert alert-success">Slider settings updated successfully!</div>';
    }
    ?>
</head>
<body>
    <div class="dropdown position-fixed bottom-0 end-0 mb-3 me-3 bd-mode-toggle">
        <button class="btn btn-bd-primary py-2 dropdown-toggle d-flex align-items-center" id="bd-theme" type="button" aria-expanded="false" data-bs-toggle="dropdown" aria-label="Toggle theme (auto)">
            <svg class="bi my-1 theme-icon-active" width="1em" height="1em"><use href="#circle-half"></use></svg>
            <span class="visually-hidden" id="bd-theme-text">Toggle theme</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="bd-theme-text">
            <li>
                <button type="button" class="dropdown-item d-flex align-items-center" data-bs-theme-value="light" aria-pressed="false">
                    <svg class="bi me-2 opacity-50 theme-icon" width="1em" height="1em"><use href="#sun-fill"></use></svg>
                    Light
                    <svg class="bi ms-auto d-none" width="1em" height="1em"><use href="#check2"></use></svg>
                </button>
            </li>
            <li>
                <button type="button" class="dropdown-item d-flex align-items-center" data-bs-theme-value="dark" aria-pressed="false">
                    <svg class="bi me-2 opacity-50 theme-icon" width="1em" height="1em"><use href="#moon-stars-fill"></use></svg>
                    Dark
                    <svg class="bi ms-auto d-none" width="1em" height="1em"><use href="#check2"></use></svg>
                </button>
            </li>
            <li>
                <button type="button" class="dropdown-item d-flex align-items-center active" data-bs-theme-value="auto" aria-pressed="true">
                    <svg class="bi me-2 opacity-50 theme-icon" width="1em" height="1em"><use href="#circle-half"></use></svg>
                    Auto
                    <svg class="bi ms-auto d-none" width="1em" height="1em"><use href="#check2"></use></svg>
                </button>
            </li>
        </ul>
    </div>

    <svg xmlns="http://www.w3.org/2000/svg" class="d-none">
        <symbol id="check2" viewBox="0 0 16 16">
            <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 0 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"></path>
        </symbol>
        <symbol id="circle-half" viewBox="0 0 16 16">
            <path d="M8 15A7 7 0 1 0 8 1v14zm0 1A8 8 0 1 1 8 0a8 8 0 0 1 0 16z"></path>
        </symbol>
        <symbol id="moon-stars-fill" viewBox="0 0 16 16">
            <path d="M6 .278a.768.768 0 0 1 .08.858 7.208 7.208 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277.527 0 1.04-.055 1.533-.16a.787.787 0 0 1 .81.316.733.733 0 0 1-.031.893A8.349 8.349 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.752.752 0 0 1 6 .278z"></path>
            <path d="M10.794 3.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387a1.734 1.734 0 0 0-1.097 1.097l-.387 1.162a.217.217 0 0 1-.412 0l-.387-1.162A1.734 1.734 0 0 0 9.31 6.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387a1.734 1.734 0 0 0 1.097-1.097l.387-1.162zM13.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.156 1.156 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.156 1.156 0 0 0-.732-.732l-.774-.258a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732L13.863.1z"></path>
        </symbol>
        <symbol id="sun-fill" viewBox="0 0 16 16">
            <path d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 13zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 0 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193 2.121a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 0 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 0 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .708z"></path>
        </symbol>
    </svg>

    <div class="container">
        <header class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-between py-3 mb-4 border-bottom">
            <div class="col-md-3 mb-2 mb-md-0">
                <a href="index.php" class="d-inline-flex link-body-emphasis text-decoration-none" style="line-height: 32px;">
                    <img src="img/acubotzlogo.png" width="30" height="30" class="d-inline-block align-top me-1" alt="RPi Logo">
                    Acubotz Dashboard
                </a>
            </div>
            <ul class="nav col-12 col-md-auto mb-2 justify-content-center mb-md-0">
                <p style="line-height:15px;margin-bottom:0px"><b>Hostname:</b> <?php system("hostname"); ?> · <b>Internal IP:</b> <?php echo $_SERVER["SERVER_ADDR"]; ?><br>
                    <b>Access from:</b> <?php echo $_SERVER["REMOTE_ADDR"]; ?> · <b>Port:</b> <?php echo $_SERVER['SERVER_PORT']; ?></p>
            </ul>
            <div class="col-md-3 text-end">
                <button class="btn btn-outline-primary mb-2" onclick="window.location.href='index.php';"><i class="bi bi-arrow-left"></i> Back to Dashboard</button>
                <button class="btn btn-outline-info mb-2" onclick="window.location.href='poster_slider.php';"><i class="bi bi-slideshow"></i> View Slider</button>
            </div>
        </header>

        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header border-primary text-primary"><i class="bi bi-image"></i> Poster Manager</div>
                    <div class="card-body">
                        <h5 class="card-title">Upload New Media</h5>
                        <form method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="poster_file" class="form-label">Select Image or Video (Images: 1280x800, JPG/PNG/GIF; Videos: MP4, Max 5MB)</label>
                                <input type="file" class="form-control" id="poster_file" name="poster_file" accept="image/jpeg,image/png,image/gif,video/mp4" required>
                            </div>
                            <button type="submit" class="btn btn-outline-success"><i class="bi bi-upload"></i> Upload</button>
                        </form>
                        <?php if ($upload_message) echo $upload_message; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row pt-3">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header border-primary text-primary"><i class="bi bi-list"></i> Existing Media</div>
                    <div class="card-body">
                        <?php if (empty($media_files)): ?>
                            <p class="text-muted">No media files found.</p>
                        <?php else: ?>
                            <form method="post" id="settingsForm">
                                <div class="poster-list">
                                    <?php foreach ($media_files as $media): ?>
                                        <?php
                                        $filename = basename($media);
                                        $is_video = pathinfo($media, PATHINFO_EXTENSION) === 'mp4';
                                        ?>
                                        <div class="poster-item border-bottom">
                                            <div>
                                                <?php if ($is_video): ?>
                                                    <video src="<?php echo htmlspecialchars($media); ?>" muted></video>
                                                <?php else: ?>
                                                    <img src="<?php echo htmlspecialchars($media); ?>" alt="<?php echo htmlspecialchars($filename); ?>">
                                                <?php endif; ?>
                                                <span><?php echo htmlspecialchars($filename); ?></span>
                                            </div>
                                            <div class="slider-settings">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="include_in_slider[<?php echo htmlspecialchars($filename); ?>]" <?php echo isset($settings[$filename]['include_in_slider']) && $settings[$filename]['include_in_slider'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label">Include in Slider</label>
                                                </div>
                                                <div>
                                                    <label class="form-label">Order</label>
                                                    <input type="number" class="form-control" name="order[<?php echo htmlspecialchars($filename); ?>]" value="<?php echo isset($settings[$filename]['order']) ? $settings[$filename]['order'] : 0; ?>" min="0">
                                                </div>
                                                <div>
                                                    <label class="form-label">Duration (s)</label>
                                                    <input type="number" class="form-control" name="duration[<?php echo htmlspecialchars($filename); ?>]" value="<?php echo isset($settings[$filename]['duration']) ? $settings[$filename]['duration'] : 5; ?>" min="1" step="0.1">
                                                </div>
                                                <button type="button" class="btn btn-outline-info btn-sm" onclick="viewMedia('<?php echo htmlspecialchars($media); ?>', '<?php echo $is_video ? 'video' : 'image'; ?>')"><i class="bi bi-eye"></i> View</button>
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="editMedia('<?php echo htmlspecialchars($filename); ?>')"><i class="bi bi-pencil"></i> Edit</button>
                                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDelete('<?php echo htmlspecialchars($filename); ?>')"><i class="bi bi-trash"></i> Delete</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="update_settings" class="btn btn-outline-success mt-3"><i class="bi bi-save"></i> Save Slider Settings</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Media Modal -->
    <div class="modal fade" id="editMediaModal" tabindex="-1" aria-labelledby="editMediaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editMediaModalLabel"><i class="bi bi-pencil"></i> Edit Media</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editMediaForm" method="post" enctype="multipart/form-data">
                        <input type="hidden" id="original_filename" name="original_filename">
                        <div class="mb-3">
                            <label for="new_poster_file" class="form-label">Upload New Image or Video (Images: 1280x800, JPG/PNG/GIF; Videos: MP4, Max 5MB)</label>
                            <input type="file" class="form-control" id="new_poster_file" name="new_poster_file" accept="image/jpeg,image/png,image/gif,video/mp4">
                        </div>
                        <div id="editFeedback"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-success" onclick="saveEditedMedia()">Save</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Media Modal -->
    <div class="modal fade" id="viewMediaModal" tabindex="-1" aria-labelledby="viewMediaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewMediaModalLabel"><i class="bi bi-image"></i> View Media</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="viewPosterImage" src="" alt="Poster Preview" style="display: none;">
                    <video id="viewPosterVideo" controls muted style="display: none;"></video>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.5.1.min.js"></script>
    <script src="js/bootstrap-5.3.2.bundle.min.js"></script>
    <script src="js/mdtoast.min.js?v=2.0.2"></script>
    <script src="js/color-modes.js"></script>

    <script>
        function viewMedia(mediaPath, type) {
            const imageElement = document.getElementById('viewPosterImage');
            const videoElement = document.getElementById('viewPosterVideo');
            if (type === 'video') {
                imageElement.style.display = 'none';
                videoElement.style.display = 'block';
                videoElement.src = mediaPath;
            } else {
                videoElement.style.display = 'none';
                imageElement.style.display = 'block';
                imageElement.src = mediaPath;
            }
            $('#viewMediaModal').modal('show');
        }

        function editMedia(filename) {
            document.getElementById('original_filename').value = filename;
            document.getElementById('editFeedback').innerHTML = '';
            $('#editMediaModal').modal('show');
        }

        function saveEditedMedia() {
            const form = document.getElementById('editMediaForm');
            const formData = new FormData(form);
            fetch('edit_poster.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    document.getElementById('editFeedback').innerHTML = '<div class="alert alert-success">Media updated successfully!</div>';
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    document.getElementById('editFeedback').innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                }
            })
            .catch(error => {
                document.getElementById('editFeedback').innerHTML = `<div class="alert alert-danger">Error updating media: ${error.message}</div>`;
                console.error('Error updating media:', error);
            });
        }

        function confirmDelete(filename) {
            if (confirm(`Are you sure you want to delete ${filename}?`)) {
                window.location.href = `poster_manager.php?delete=${encodeURIComponent(filename)}`;
            }
        }
    </script>
</body>
</html>