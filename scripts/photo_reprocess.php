<?php
/**
 * Reprocess photos: regenerate thumbs and extract EXIF + link location if missing
 * Usage: php scripts/photo_reprocess.php [--limit=100]
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$opts = getopt('', ['limit::']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 200;
$pdo = jarvis_pdo();
if (!$pdo) {
  echo "DB not configured\n"; exit(1);
}
$stmt = $pdo->prepare('SELECT id,user_id,filename,thumb_filename FROM photos WHERE 1=1 ORDER BY id ASC LIMIT :l');
$stmt->bindValue(':l', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as $r) {
  $id = (int)$r['id'];
  $uid = (int)$r['user_id'];
  $baseDir = __DIR__ . '/../storage/photos/' . $uid;
  $file = $baseDir . '/' . $r['filename'];
  echo "Processing photo {$id} -> {$file}\n";
  if (!is_file($file)) { echo "  file missing\n"; continue; }

  // create thumb if missing
  $thumbName = $r['thumb_filename'];
  if (empty($thumbName) || !is_file($baseDir . '/' . $thumbName)) {
    try {
      $info = @getimagesize($file);
      if ($info && isset($info[0], $info[1]) && function_exists('imagecreatetruecolor')) {
        $max = 600;
        $ratio = min(1, $max / max($info[0], $info[1]));
        $tw = (int)round($info[0] * $ratio);
        $th = (int)round($info[1] * $ratio);
        $mime = $info['mime'] ?? '';
        $src = null;
        if ($mime === 'image/jpeg' || $mime === 'image/pjpeg') $src = imagecreatefromjpeg($file);
        elseif ($mime === 'image/png') $src = imagecreatefrompng($file);
        elseif ($mime === 'image/gif') $src = imagecreatefromgif($file);
        if ($src) {
          $dst = imagecreatetruecolor($tw, $th);
          imagecopyresampled($dst, $src, 0,0,0,0, $tw, $th, $info[0], $info[1]);
          $thumbName = 'thumb_' . $r['filename'] . '.jpg';
          imagejpeg($dst, $baseDir . '/' . $thumbName, 80);
          imagedestroy($dst); imagedestroy($src);
          $pdo->prepare('UPDATE photos SET thumb_filename = :t WHERE id = :id')->execute([':t'=>$thumbName, ':id'=>$id]);
          echo "  thumbnail created: {$thumbName}\n";
        }
      }
    } catch (Throwable $e) { echo "  thumb failed: {$e->getMessage()}\n"; }
  }

  // EXIF extraction if missing
  $q = $pdo->prepare('SELECT metadata_json FROM photos WHERE id=:id LIMIT 1'); $q->execute([':id'=>$id]); $meta = $q->fetchColumn(); $meta = $meta ? json_decode($meta, true) : [];
  if (empty($meta['exif_present']) && function_exists('exif_read_data')) {
    try {
      $exif = @exif_read_data($file, 0, true);
      if (is_array($exif) && !empty($exif)) {
        if (!empty($exif['EXIF']['DateTimeOriginal'])) $meta['exif_datetime'] = (string)$exif['EXIF']['DateTimeOriginal'];
        $gps = jarvis_exif_get_gps($exif);
        if ($gps) {
          $meta['exif_gps'] = $gps;
          // create location_logs
          $s = $pdo->prepare('INSERT INTO location_logs (user_id,lat,lon,accuracy_m,source) VALUES (:u,:la,:lo,:a,:s)');
          $s->execute([':u'=>$uid, ':la'=>$gps['lat'], ':lo'=>$gps['lon'], ':a'=>null, ':s'=>'photo']);
          $locId = (int)$pdo->lastInsertId(); if ($locId) $meta['photo_location_id'] = $locId;
        }
        $meta['exif_present'] = true;
        $pdo->prepare('UPDATE photos SET metadata_json = :m WHERE id = :id')->execute([':m'=>json_encode($meta), ':id'=>$id]);
        echo "  exif extracted\n";
      }
    } catch (Throwable $e) { echo "  exif failed: {$e->getMessage()}\n"; }
  }
}

echo "Done.\n";
