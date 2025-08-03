<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php system("hostname"); ?> - Poster Slider</title>

    <style>
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            background: black;
            user-select: none;
            -webkit-user-select: none;
            -ms-user-select: none;
            -webkit-touch-callout: none;
        }
        #slideshow-container {
            width: 100%;
            height: 100%;
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .slide {
            display: none;
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            top: 0;
            left: 0;
        }
        .slide.active {
            display: block;
        }
        .slide img, .slide video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        #no-media-message {
            color: white;
            font-size: 24px;
            text-align: center;
            font-family: Arial, sans-serif;
        }
    </style>

    <?php
    $poster_dir = "posters/";
    $settings_file = $poster_dir . "poster_settings.json";
    $media_items = [];

    // Load and parse settings
    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true);
        if ($settings !== null) {
            foreach ($settings as $filename => $config) {
                if (isset($config['include_in_slider']) && $config['include_in_slider'] && file_exists($poster_dir . $filename)) {
                    $media_items[] = [
                        'filename' => $filename,
                        'path' => $poster_dir . $filename,
                        'is_video' => strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4',
                        'order' => isset($config['order']) ? (int)$config['order'] : 0,
                        'duration' => isset($config['duration']) ? (float)$config['duration'] * 1000 : 5000
                    ];
                }
            }
            // Sort by order
            usort($media_items, function($a, $b) {
                return $a['order'] <=> $b['order'];
            });
        }
    }
    ?>
</head>
<body>
    <div id="slideshow-container">
        <?php if (empty($media_items)): ?>
            <div id="no-media-message">No media selected for slider.</div>
        <?php else: ?>
            <?php foreach ($media_items as $index => $item): ?>
                <div class="slide" data-duration="<?php echo $item['duration']; ?>">
                    <?php if ($item['is_video']): ?>
                        <video src="<?php echo htmlspecialchars($item['path']); ?>" autoplay muted loop></video>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($item['path']); ?>" alt="<?php echo htmlspecialchars($item['filename']); ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        // Disable all interactions
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('mousedown', e => e.preventDefault());
        document.addEventListener('keydown', e => e.preventDefault());
        document.addEventListener('touchstart', e => e.preventDefault());

        // Slideshow logic
        const slides = document.querySelectorAll('.slide');
        let currentSlide = 0;

        function showSlide(index) {
            if (slides.length === 0) return;
            slides.forEach((slide, i) => {
                slide.classList.toggle('active', i === index);
                const video = slide.querySelector('video');
                if (video) {
                    if (i === index) {
                        video.currentTime = 0;
                        video.play().catch(error => console.error('Video playback error:', error));
                    } else {
                        video.pause();
                    }
                }
            });
        }

        function nextSlide() {
            if (slides.length === 0) return;
            currentSlide = (currentSlide + 1) % slides.length;
            showSlide(currentSlide);
            const duration = parseFloat(slides[currentSlide].getAttribute('data-duration')) || 5000;
            setTimeout(nextSlide, duration);
        }

        // Start slideshow
        if (slides.length > 0) {
            showSlide(currentSlide);
            setTimeout(nextSlide, parseFloat(slides[currentSlide].getAttribute('data-duration')) || 5000);
        }
    </script>
</body>
</html>