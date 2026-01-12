<?php
/**
 * Seed demo user's photo gallery with generated dummy images.
 * Usage: php scripts/seed-demo-photos.php [--count=12]
 */
require_once __DIR__ . '/../db.php';

$opts = getopt('', ['count::']);
$count = isset($opts['count']) ? max(1, (int)$opts['count']) : 12;

$pdo = jarvis_pdo();
if (!$pdo) { fwrite(STDERR, "DB not configured\n"."\n"); exit(1); }

// Find demo user (by username or email)
$stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = :u OR email = :e LIMIT 1');
$stmt->execute([':u'=>'demo', ':e'=>'demo@example.com']);
$demo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$demo) { fwrite(STDERR, "Demo user not found (username 'demo' or email demo@example.com)\n"."\n"); exit(1); }
$uid = (int)$demo['id'];
echo "Seeding demo photos for user #{$uid} ({$demo['username']} / {$demo['email']})\n";

$baseDir = __DIR__ . '/../storage/photos/' . $uid;
if (!is_dir($baseDir)) @mkdir($baseDir, 0770, true);

function make_demo_image($w, $h, $label){
  if (!function_exists('imagecreatetruecolor')) return null;
  $im = imagecreatetruecolor($w, $h);
  // Background gradient-ish blocks
  $bg1 = imagecolorallocate($im, rand(0,40), rand(0,40), rand(10,60));
  $bg2 = imagecolorallocate($im, rand(10,20), rand(20,40), rand(60,120));
  imagefilledrectangle($im, 0, 0, $w, $h, $bg1);
  imagefilledrectangle($im, 0, (int)($h*0.55), $w, $h, $bg2);
  // Accent circles
  for($i=0;$i<6;$i++){
    $c = imagecolorallocatealpha($im, rand(80,200), rand(120,220), rand(160,255), 70);
    imagefilledellipse($im, rand(20,$w-20), rand(20,$h-20), rand(40,180), rand(40,180), $c);
  }
  // Label text
  $white = imagecolorallocate($im, 240, 248, 255);
  imagestring($im, 5, 12, 12, 'JARVIS Demo', $white);
  imagestring($im, 4, 12, 32, $label, $white);
  return $im;
}

// Helper: download a remote placeholder image
function download_placeholder($w, $h, $text, $dest){
  $url = sprintf('https://placehold.co/%dx%d.jpg?text=%s', $w, $h, urlencode($text));
  $ch = curl_init($url);
  $fp = fopen($dest, 'w');
  curl_setopt_array($ch, [CURLOPT_FILE=>$fp, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>10, CURLOPT_USERAGENT=>'JarvisSeeder/1.0']);
  $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fp);
  return $ok && $code >= 200 && $code < 300 && is_file($dest) && filesize($dest) > 1000;
}

$created = 0;
for ($i=1; $i<=$count; $i++) {
  $w = rand(800, 1280); $h = rand(600, 960);
  $label = sprintf('JARVIS Demo #%02d', $i);
  $im = make_demo_image($w, $h, $label);
  $fname = sprintf('%d_%s.jpg', $uid, bin2hex(random_bytes(6)));
  $path = $baseDir . '/' . $fname;
  if ($im) {
    imagejpeg($im, $path, 88); imagedestroy($im);
  } else {
    echo "GD not available; using remote placeholders...\n"; 
    if (!download_placeholder($w, $h, $label, $path)) { echo "  failed to download placeholder; skipping\n"; continue; }
  }

  // Store DB row
  $meta = ['demo'=>true];
  $pid = jarvis_store_photo($uid, $fname, 'demo_'.$i.'.jpg', $meta);
  if ($pid) {
    // Create thumb
    try {
      $info = @getimagesize($path);
      if ($info && isset($info[0], $info[1]) && function_exists('imagecreatetruecolor')) {
        $max = 600; $ratio = min(1, $max / max($info[0], $info[1]));
        $tw = (int)round($info[0] * $ratio); $th = (int)round($info[1] * $ratio);
        $src = imagecreatefromjpeg($path);
        if ($src) {
          $dst = imagecreatetruecolor($tw, $th);
          imagecopyresampled($dst, $src, 0,0,0,0, $tw, $th, $info[0], $info[1]);
          $thumbName = 'thumb_' . $fname . '.jpg';
          imagejpeg($dst, $baseDir . '/' . $thumbName, 80);
          imagedestroy($dst); imagedestroy($src);
          $pdo->prepare('UPDATE photos SET thumb_filename = :t WHERE id = :id')->execute([':t'=>$thumbName, ':id'=>$pid]);
        }
      }
    } catch (Throwable $e) {
      // non-fatal
    }
    $created++;
  }
}

echo "Created {$created} demo photos.\n";
echo "Tip: run 'php scripts/photo_reprocess.php --limit=200' to ensure EXIF/location and thumbs.\n";
