<?php
/**
 * Seed demo user's video gallery with generated placeholder videos
 * and forest-themed demo photos.
 * Usage: php scripts/seed-demo-videos.php [--videos=6] [--photos=12]
 */
require_once __DIR__ . '/../db.php';

$opts = getopt('', ['videos::', 'photos::']);
$videoCount = isset($opts['videos']) ? max(0, (int)$opts['videos']) : 6;
$photoCount = isset($opts['photos']) ? max(0, (int)$opts['photos']) : 12;

$pdo = jarvis_pdo();
if (!$pdo) { fwrite(STDERR, "DB not configured\n"); exit(1); }

// Find demo user (by username or email)
$stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = :u OR email = :e LIMIT 1');
$stmt->execute([':u'=>'demo', ':e'=>'demo@example.com']);
$demo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$demo) { fwrite(STDERR, "Demo user not found (username 'demo' or email demo@example.com)\n"); exit(1); }
$uid = (int)$demo['id'];
echo "Seeding demo media for user #{$uid} ({$demo['username']} / {$demo['email']})\n";

// Ensure video_inputs table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS video_inputs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            filename VARCHAR(255) NOT NULL,
            thumb_filename VARCHAR(255) NULL,
            transcript TEXT NULL,
            duration_ms INT UNSIGNED NULL,
            metadata_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_video_user (user_id),
            CONSTRAINT fk_video_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ video_inputs table ready\n";
} catch (Exception $e) {
    // Table might already exist with constraint
    echo "  video_inputs table check: " . $e->getMessage() . "\n";
}

// Setup directories
$videoDir = __DIR__ . '/../storage/video/' . $uid;
$photoDir = __DIR__ . '/../storage/photos/' . $uid;
if (!is_dir($videoDir)) @mkdir($videoDir, 0770, true);
if (!is_dir($photoDir)) @mkdir($photoDir, 0770, true);

// ============= VIDEO GENERATION =============
echo "\n--- Generating Demo Videos ---\n";

// Video themes for demo content
$videoThemes = [
    ['title' => 'Forest Walk', 'duration' => 15000, 'color1' => [20, 60, 30], 'color2' => [10, 40, 20]],
    ['title' => 'Morning Routine', 'duration' => 30000, 'color1' => [60, 50, 30], 'color2' => [40, 30, 20]],
    ['title' => 'Quick Update', 'duration' => 12000, 'color1' => [30, 40, 60], 'color2' => [20, 25, 45]],
    ['title' => 'Project Demo', 'duration' => 45000, 'color1' => [50, 30, 60], 'color2' => [35, 20, 45]],
    ['title' => 'Coffee Break', 'duration' => 8000, 'color1' => [55, 35, 25], 'color2' => [40, 25, 15]],
    ['title' => 'Evening Thoughts', 'duration' => 22000, 'color1' => [25, 25, 45], 'color2' => [15, 15, 35]],
    ['title' => 'Nature Sounds', 'duration' => 60000, 'color1' => [15, 50, 35], 'color2' => [10, 35, 25]],
    ['title' => 'Daily Log', 'duration' => 18000, 'color1' => [45, 45, 50], 'color2' => [30, 30, 35]],
];

/**
 * Generate a simple placeholder video frame image (simulates a video thumbnail)
 */
function make_video_placeholder($w, $h, $title, $theme) {
    if (!function_exists('imagecreatetruecolor')) return null;
    $im = imagecreatetruecolor($w, $h);
    
    // Gradient background
    $bg1 = imagecolorallocate($im, $theme['color1'][0], $theme['color1'][1], $theme['color1'][2]);
    $bg2 = imagecolorallocate($im, $theme['color2'][0], $theme['color2'][1], $theme['color2'][2]);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg1);
    imagefilledrectangle($im, 0, (int)($h*0.5), $w, $h, $bg2);
    
    // Add some visual elements
    for ($i = 0; $i < 5; $i++) {
        $c = imagecolorallocatealpha($im, 
            min(255, $theme['color1'][0] + rand(30, 80)), 
            min(255, $theme['color1'][1] + rand(30, 80)), 
            min(255, $theme['color1'][2] + rand(30, 80)), 
            rand(60, 90));
        imagefilledellipse($im, rand(0, $w), rand(0, $h), rand(50, 150), rand(50, 150), $c);
    }
    
    // Play button circle
    $centerX = (int)($w / 2);
    $centerY = (int)($h / 2);
    $playBg = imagecolorallocatealpha($im, 0, 0, 0, 50);
    imagefilledellipse($im, $centerX, $centerY, 80, 80, $playBg);
    
    // Play triangle
    $white = imagecolorallocate($im, 255, 255, 255);
    $points = [
        $centerX - 12, $centerY - 20,
        $centerX - 12, $centerY + 20,
        $centerX + 18, $centerY
    ];
    imagefilledpolygon($im, $points, $white);
    
    // Title text
    imagestring($im, 5, 12, 12, 'JARVIS Video', $white);
    imagestring($im, 4, 12, 32, $title, $white);
    
    return $im;
}

/**
 * Create a minimal valid WebM file (placeholder)
 * This creates a tiny WebM with a single frame
 */
function create_placeholder_webm($path, $thumbPath, $title, $theme) {
    // Create thumbnail image
    $w = 640;
    $h = 360;
    $im = make_video_placeholder($w, $h, $title, $theme);
    if ($im) {
        imagejpeg($im, $thumbPath, 85);
        imagedestroy($im);
    }
    
    // For actual video, we'll create a minimal WebM placeholder
    // This is a minimal valid WebM file header (not playable, but valid format)
    // In production, you'd use ffmpeg to generate actual videos
    
    // Create a simple binary placeholder (browsers won't play it but API works)
    $webmHeader = hex2bin(
        '1a45dfa3' . // EBML
        '01000000' . 
        '0000001f' . 
        '4286' . '8101' .  // Version
        '42f7' . '8101' .  // ReadVersion
        '42f2' . '8104' .  // MaxIDLength
        '42f3' . '8108' .  // MaxSizeLength
        '4282' . '8477' . '65626d' . // DocType: webm
        '4287' . '8102' .  // DocTypeVersion
        '4285' . '8102'    // DocTypeReadVersion
    );
    file_put_contents($path, $webmHeader . random_bytes(1024)); // Add some content
    
    return file_exists($path);
}

$videosCreated = 0;
for ($i = 0; $i < $videoCount; $i++) {
    $theme = $videoThemes[$i % count($videoThemes)];
    $fname = sprintf('demo_video_%d_%s.webm', $i + 1, bin2hex(random_bytes(4)));
    $thumbName = 'thumb_' . str_replace('.webm', '.jpg', $fname);
    $videoPath = $videoDir . '/' . $fname;
    $thumbPath = $videoDir . '/' . $thumbName;
    
    if (create_placeholder_webm($videoPath, $thumbPath, $theme['title'], $theme)) {
        try {
            $meta = ['demo' => true, 'title' => $theme['title']];
            $stmt = $pdo->prepare('INSERT INTO video_inputs (user_id, filename, thumb_filename, duration_ms, metadata_json, created_at) VALUES (:u, :f, :t, :d, :m, NOW())');
            $stmt->execute([
                ':u' => $uid,
                ':f' => $fname,
                ':t' => $thumbName,
                ':d' => $theme['duration'],
                ':m' => json_encode($meta)
            ]);
            $videosCreated++;
            echo "  ✓ Created: {$theme['title']} ({$fname})\n";
        } catch (Exception $e) {
            echo "  ✗ DB Error: " . $e->getMessage() . "\n";
        }
    }
}
echo "Created {$videosCreated} demo videos.\n";

// ============= FOREST PHOTO GENERATION =============
if ($photoCount > 0) {
    echo "\n--- Generating Forest-Themed Photos ---\n";
    
    $forestThemes = [
        ['name' => 'Misty Morning', 'query' => 'forest+mist+morning'],
        ['name' => 'Golden Hour', 'query' => 'forest+sunset+golden'],
        ['name' => 'Deep Woods', 'query' => 'deep+woods+trees'],
        ['name' => 'Autumn Trail', 'query' => 'autumn+forest+trail'],
        ['name' => 'Spring Bloom', 'query' => 'spring+forest+flowers'],
        ['name' => 'Rainy Forest', 'query' => 'rainy+forest+nature'],
        ['name' => 'Sunset Canopy', 'query' => 'forest+canopy+sunset'],
        ['name' => 'Winter Woods', 'query' => 'winter+forest+snow'],
        ['name' => 'Mountain Forest', 'query' => 'mountain+pine+forest'],
        ['name' => 'Creek Path', 'query' => 'forest+creek+stream'],
        ['name' => 'Foggy Trees', 'query' => 'foggy+trees+nature'],
        ['name' => 'Sunlit Path', 'query' => 'sunlit+forest+path'],
    ];
    
    // Download placeholder image from remote service
    function download_forest_placeholder($w, $h, $text, $dest) {
        // Using picsum.photos for nice placeholder images
        $url = sprintf('https://picsum.photos/%d/%d', $w, $h);
        $ch = curl_init($url);
        $fp = fopen($dest, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'JarvisSeeder/1.0'
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $ok && $code >= 200 && $code < 400 && is_file($dest) && filesize($dest) > 1000;
    }
    
    // Alternative: use placehold.co for labeled placeholders
    function download_labeled_placeholder($w, $h, $label, $dest) {
        $url = sprintf('https://placehold.co/%dx%d/1a3d22/ffffff.jpg?text=%s', $w, $h, urlencode($label));
        $ch = curl_init($url);
        $fp = fopen($dest, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'JarvisSeeder/1.0'
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $ok && $code >= 200 && $code < 400 && is_file($dest) && filesize($dest) > 500;
    }
    
    $photosCreated = 0;
    for ($i = 0; $i < $photoCount; $i++) {
        $theme = $forestThemes[$i % count($forestThemes)];
        $w = rand(800, 1200);
        $h = rand(600, 900);
        
        $fname = sprintf('forest_%d_%s.jpg', $i + 1, bin2hex(random_bytes(4)));
        $path = $photoDir . '/' . $fname;
        
        // Try picsum first, fall back to labeled placeholder
        $downloaded = download_forest_placeholder($w, $h, $theme['name'], $path);
        if (!$downloaded) {
            echo "  Trying labeled placeholder for {$theme['name']}...\n";
            $downloaded = download_labeled_placeholder($w, $h, $theme['name'], $path);
        }
        
        if (!$downloaded) {
            echo "  ✗ Failed to download: {$theme['name']}\n";
            continue;
        }
        
        // Store in DB
        $meta = ['demo' => true, 'theme' => $theme['name'], 'forest' => true];
        $pid = jarvis_store_photo($uid, $fname, 'forest_' . ($i + 1) . '.jpg', $meta);
        if ($pid) {
            $photosCreated++;
            echo "  ✓ Created: {$theme['name']} ({$fname})\n";
        }
    }
    echo "Created {$photosCreated} forest-themed photos.\n";
}

echo "\n✨ Demo media seeding complete!\n";
echo "Videos: {$videosCreated}, Photos: " . ($photoCount > 0 ? $photosCreated : 'skipped') . "\n";
