<?php
// JARVIS REST API (JWT + MySQL)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/instagram_basic.php';
require_once __DIR__ . '/briefing.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Debugging: log incoming requests to help diagnose route mismatches (temporary)
@file_put_contents('/tmp/jarvis_req.log', json_encode(['ts'=>time(),'path'=>$path,'method'=>$method,'headers'=>getallheaders()]) . PHP_EOL, FILE_APPEND);

if ($path !== '/' && substr($path, -1) === '/') $path = rtrim($path, '/');

function slack_post_message_api(string $token, string $channel, string $text): array {
  $ch = curl_init('https://slack.com/api/chat.postMessage');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $token,
      'Content-Type: application/json; charset=utf-8',
    ],
    CURLOPT_POSTFIELDS => json_encode(['channel' => $channel, 'text' => $text]),
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode($resp ?: '', true);
  if (!is_array($data)) $data = ['ok' => false, 'error' => 'invalid_json', 'raw' => $resp];
  $data['_http'] = $code;
  return $data;
}

// Reprocess a photo and optionally override GPS (test helper)
if (preg_match('#^/api/photos/(\d+)/reprocess$#', $path, $m)) {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $photoId = (int)$m[1];
  $photo = jarvis_get_photo_by_id($photoId);
  if (!$photo) jarvis_respond(404, ['error'=>'not found']);
  if ((int)$photo['user_id'] !== (int)$userId && ($u['role'] ?? '') !== 'admin') jarvis_respond(403, ['error'=>'forbidden']);
  $in = jarvis_json_input();
  $override = null;
  if (is_array($in) && isset($in['gps_lat'], $in['gps_lon'])) {
    $override = ['gps_lat' => (float)$in['gps_lat'], 'gps_lon' => (float)$in['gps_lon']];
  }
  $res = jarvis_reprocess_photo($photoId, $override);
  jarvis_log_api_request($userId, 'desktop', $path, $method, $in, $res, $res['ok'] ? 200 : 500);
  jarvis_respond($res['ok'] ? 200 : 500, $res);
}

function require_jwt_user(): array {
  $payload = jarvis_jwt_verify(jarvis_bearer_token());
  if (!$payload || empty($payload['sub'])) {
    jarvis_respond(401, ['error' => 'Unauthorized']);
  }
  $userId = (int)$payload['sub'];
  $u = jarvis_user_by_id($userId);
  if (!$u) jarvis_respond(401, ['error' => 'Unauthorized']);
  return [$userId, $u, $payload];
}

// ----------------------------
// Health
// ----------------------------

if ($path === '/api/ping') {
  jarvis_respond(200, ['ok' => true, 'service' => 'JARVIS', 'db' => (bool)jarvis_pdo()]);
}

// ----------------------------
// Auth
// ----------------------------

if ($path === '/api/auth/register') {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  $in = jarvis_json_input();
  $username = trim((string)($in['username'] ?? ''));
  $email = trim((string)($in['email'] ?? ''));
  $password = (string)($in['password'] ?? '');
  $phone = trim((string)($in['phone_e164'] ?? ''));
  $phone = $phone !== '' ? $phone : null;

  if ($username === '' || $email === '' || $password === '') {
    jarvis_respond(400, ['error' => 'username, email, password required']);
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jarvis_respond(400, ['error' => 'invalid email']);
  }

  if (jarvis_user_by_username($username)) {
    jarvis_respond(409, ['error' => 'username exists']);
  }
  $pdo = jarvis_pdo();
  if (!$pdo) jarvis_respond(500, ['error' => 'DB not configured']);
  $stmt = $pdo->prepare('SELECT id FROM users WHERE email=:e LIMIT 1');
  $stmt->execute([':e'=>$email]);
  if ($stmt->fetch()) jarvis_respond(409, ['error' => 'email exists']);

  $token = bin2hex(random_bytes(24));
  $userId = jarvis_create_user($username, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $token);
  jarvis_audit($userId, 'REGISTER', 'auth', ['email'=>$email]);

  jarvis_send_confirmation_email($email, $username, $token);
  if ($phone) jarvis_send_sms($phone, "JARVIS: Welcome {$username}! Confirm your email to activate your account.");

  jarvis_respond(201, ['ok'=>true, 'user_id'=>$userId, 'message'=>'registered; confirm email']);
}

if ($path === '/api/auth/login') {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  $in = jarvis_json_input();
  $username = trim((string)($in['username'] ?? ''));
  $password = (string)($in['password'] ?? '');
  if ($username === '' || $password === '') jarvis_respond(400, ['error' => 'username and password required']);

  $u = jarvis_user_by_username($username);
  if (!$u || !password_verify($password, $u['password_hash'] ?? '')) {
    jarvis_audit($u['id'] ?? null, 'LOGIN_FAIL', 'auth', ['username'=>$username]);
    jarvis_respond(401, ['error' => 'invalid credentials']);
  }
  if (empty($u['email_verified_at'])) {
    jarvis_respond(403, ['error' => 'email not verified']);
  }

  $token = jarvis_jwt_issue((int)$u['id'], $username, 3600);
  jarvis_update_last_login((int)$u['id']);
  jarvis_audit((int)$u['id'], 'LOGIN_SUCCESS', 'auth', ['client'=>'api']);

  jarvis_respond(200, ['ok'=>true, 'token'=>$token, 'expires_in'=>3600]);
}

// ----------------------------
// Me
// ----------------------------

if ($path === '/api/me') {
  if ($method !== 'GET') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $prefs = jarvis_preferences($userId);
  jarvis_log_api_request($userId, 'desktop', $path, $method, null, ['ok'=>true], 200);
  jarvis_respond(200, [
    'id'=>(int)$u['id'],
    'username'=>$u['username'],
    'email'=>$u['email'],
    'phone_e164'=>$u['phone_e164'],
    'prefs'=>$prefs,
  ]);
}

// ----------------------------
// Notifications
// ----------------------------

if ($path === '/api/notifications') {
  [$userId, $u] = require_jwt_user();
  if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? min(200, (int)$_GET['limit']) : 20;
    $notifs = jarvis_recent_notifications($userId, $limit);
    $count = jarvis_unread_notifications_count($userId);
    jarvis_log_api_request($userId, 'desktop', $path, $method, null, ['ok'=>true,'count'=>$count], 200);
    jarvis_respond(200, ['ok'=>true,'count'=>$count,'notifications'=>$notifs]);
  }
  jarvis_respond(405, ['error'=>'Method not allowed']);
}

// Mark notification as read
if (preg_match('#^/api/notifications/([0-9]+)/read$#', $path, $m)) {
  [$userId, $u] = require_jwt_user();
  if ($method !== 'POST') jarvis_respond(405, ['error'=>'Method not allowed']);
  $nid = (int)$m[1];
  $pdo = jarvis_pdo();
  if (!$pdo) jarvis_respond(500, ['error'=>'DB not configured']);
  $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=:id AND user_id=:u');
  $stmt->execute([':id'=>$nid, ':u'=>$userId]);
  jarvis_log_api_request($userId, 'desktop', $path, $method, null, ['ok'=>true,'id'=>$nid], 200);
  jarvis_respond(200, ['ok'=>true,'id'=>$nid]);
}

// ----------------------------
// Audit (recent events)
// ----------------------------
if ($path === '/api/audit') {
  [$userId, $u] = require_jwt_user();
  if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? min(200, (int)$_GET['limit']) : 20;
    $items = jarvis_latest_audit($userId, $limit);
    jarvis_log_api_request($userId, 'desktop', $path, $method, null, ['ok'=>true,'count'=>count($items)], 200);
    jarvis_respond(200, ['ok'=>true,'count'=>count($items),'audit'=>$items]);
  }
  jarvis_respond(405, ['error'=>'Method not allowed']);
}

// ----------------------------
// Voice inputs (top-level handler) - ensure this is available outside of command block
if ($path === '/api/voice') {
  if ($method === 'POST') {
    // debug: log files/post content
    @file_put_contents('/tmp/jarvis_req.log', json_encode(['ts'=>time(),'voice_post_files'=>array_keys($_FILES),'post_keys'=>array_keys($_POST)]) . PHP_EOL, FILE_APPEND);
    [$userId, $u] = require_jwt_user();
    $uploadedFile = $_FILES['file'] ?? null;
    if (!$uploadedFile || ($uploadedFile['error'] ?? 1) !== 0) jarvis_respond(400, ['error'=>'no file uploaded']);
    $baseDir = __DIR__ . '/storage/voice/' . (int)$userId;
    if (!is_dir($baseDir)) @mkdir($baseDir, 0770, true);
    $ext = pathinfo($uploadedFile['name'] ?? 'blob', PATHINFO_EXTENSION) ?: 'webm';
    $fname = sprintf('%s_%s.%s', (int)$userId, bin2hex(random_bytes(6)), $ext);
    $dest = $baseDir . '/' . $fname;
    if (!move_uploaded_file($uploadedFile['tmp_name'], $dest)) jarvis_respond(500, ['error'=>'failed to save file']);

    $transcript = isset($_POST['transcript']) ? trim((string)$_POST['transcript']) : null;
    $duration = isset($_POST['duration']) ? (int)$_POST['duration'] : null;
    $meta = [];
    if (isset($_POST['meta'])) $meta = json_decode((string)$_POST['meta'], true) ?: [];

    $filePathForDb = 'storage/voice/' . (int)$userId . '/' . $fname;
    $vid = jarvis_save_voice_input($userId, $filePathForDb, $transcript, $duration, $meta);
    jarvis_audit($userId, 'VOICE_INPUT', 'voice', ['voice_id'=>$vid,'filename'=>$filePathForDb,'duration_ms'=>$duration,'transcript'=>substr($transcript?:'',0,512)]);
    jarvis_pnut_log($userId, 'voice', ['voice_id'=>$vid,'filename'=>$filePathForDb,'duration_ms'=>$duration,'transcript'=>$transcript,'meta'=>$meta]);
    jarvis_respond(200, ['ok'=>true,'id'=>$vid,'filename'=>$filePathForDb]);
  }
  if ($method === 'GET') {
    [$userId, $u] = require_jwt_user();
    $limit = isset($_GET['limit']) ? min(200, (int)$_GET['limit']) : 20;
    $items = jarvis_recent_voice_inputs($userId, $limit);
    jarvis_respond(200, ['ok'=>true,'count'=>count($items),'voice'=>$items]);
  }
  jarvis_respond(405, ['error'=>'Method not allowed']);
}

// Download a recorded voice blob (authenticated via JWT or Session for Admin UI) - TOP-LEVEL
if (preg_match('#^/api/voice/([0-9]+)/download$#', $path, $m)) {
  $userId = 0;
  $isAdmin = false;
  $token = jarvis_bearer_token();
  if ($token) {
    $payload = jarvis_jwt_verify($token);
    if ($payload && !empty($payload['sub'])) {
      $userId = (int)$payload['sub'];
      $u = jarvis_user_by_id($userId);
      if ($u && ($u['role']??'')==='admin') $isAdmin = true;
    }
  } else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['user_id'])) {
      $userId = (int)$_SESSION['user_id'];
      $u = jarvis_user_by_id($userId);
      if ($u && ($u['role']??'')==='admin') $isAdmin = true;
    }
  }

  if ($userId <= 0) jarvis_respond(401, ['error'=>'Unauthorized']);

  $vid = (int)$m[1];
  $v = jarvis_voice_input_by_id($vid);
  if (!$v) jarvis_respond(404, ['error'=>'not found']);
  if ((int)$v['user_id'] !== (int)$userId && !$isAdmin) jarvis_respond(403, ['error'=>'forbidden']);
  $f = __DIR__ . '/' . $v['filename'];
  if (!is_file($f)) jarvis_respond(404, ['error'=>'file not found']);
  $mime = mime_content_type($f) ?: 'application/octet-stream';
  header('Content-Type: ' . $mime);
  header('Content-Disposition: attachment; filename="' . basename($f) . '"');
  readfile($f);
  exit;
}

// ----------------------------
// Video inputs (selfie video recording from mobile devices)
// ----------------------------
if ($path === '/api/video') {
  if ($method === 'POST') {
    [$userId, $u] = require_jwt_user();
    if (!jarvis_rate_limit($userId, '/api/video', 10)) jarvis_respond(429, ['error'=>'rate limited']);
    
    $uploadedFile = $_FILES['file'] ?? null;
    if (!$uploadedFile || ($uploadedFile['error'] ?? 1) !== 0) jarvis_respond(400, ['error'=>'no file uploaded']);
    
    // Ensure storage dir exists
    $baseDir = __DIR__ . '/storage/video/' . (int)$userId;
    if (!is_dir($baseDir)) @mkdir($baseDir, 0770, true);
    $ext = pathinfo($uploadedFile['name'] ?? 'blob', PATHINFO_EXTENSION) ?: 'webm';
    $fname = sprintf('%s_%s.%s', (int)$userId, bin2hex(random_bytes(6)), $ext);
    $dest = $baseDir . '/' . $fname;
    if (!move_uploaded_file($uploadedFile['tmp_name'], $dest)) jarvis_respond(500, ['error'=>'failed to save file']);
    
    $transcript = isset($_POST['transcript']) ? trim((string)$_POST['transcript']) : null;
    $duration = isset($_POST['duration']) ? (int)$_POST['duration'] : null;
    $meta = [];
    if (isset($_POST['meta'])) $meta = json_decode((string)$_POST['meta'], true) ?: [];
    
    // Try to generate a thumbnail from video (first frame)
    $thumbFilename = null;
    // Skip thumbnail generation for now, can be added later with FFmpeg
    
    $filePathForDb = 'storage/video/' . (int)$userId . '/' . $fname;
    $vid = jarvis_save_video_input($userId, $filePathForDb, $thumbFilename, $transcript, $duration, $meta);
    jarvis_audit($userId, 'VIDEO_INPUT', 'video', ['video_id'=>$vid,'filename'=>$filePathForDb,'duration_ms'=>$duration,'transcript'=>substr($transcript?:'',0,512)]);
    jarvis_pnut_log($userId, 'video', ['video_id'=>$vid,'filename'=>$filePathForDb,'duration_ms'=>$duration,'transcript'=>$transcript,'meta'=>$meta]);
    jarvis_respond(200, ['ok'=>true,'id'=>$vid,'filename'=>$filePathForDb]);
  }
  if ($method === 'GET') {
    [$userId, $u] = require_jwt_user();
    $limit = isset($_GET['limit']) ? min(200, (int)$_GET['limit']) : 20;
    $items = jarvis_recent_video_inputs($userId, $limit);
    jarvis_respond(200, ['ok'=>true,'count'=>count($items),'videos'=>$items]);
  }
  jarvis_respond(405, ['error'=>'Method not allowed']);
}

// Download a recorded video (authenticated via JWT or Session)
if (preg_match('#^/api/video/([0-9]+)/download$#', $path, $m)) {
  $userId = 0;
  $isAdmin = false;
  $token = jarvis_bearer_token();
  if ($token) {
    $payload = jarvis_jwt_verify($token);
    if ($payload && !empty($payload['sub'])) {
      $userId = (int)$payload['sub'];
      $u = jarvis_user_by_id($userId);
      if ($u && ($u['role']??'')==='admin') $isAdmin = true;
    }
  } else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['user_id'])) {
      $userId = (int)$_SESSION['user_id'];
      $u = jarvis_user_by_id($userId);
      if ($u && ($u['role']??'')==='admin') $isAdmin = true;
    }
  }

  if ($userId <= 0) jarvis_respond(401, ['error'=>'Unauthorized']);

  $vid = (int)$m[1];
  $v = jarvis_video_input_by_id($vid);
  if (!$v) jarvis_respond(404, ['error'=>'not found']);
  if ((int)$v['user_id'] !== (int)$userId && !$isAdmin) jarvis_respond(403, ['error'=>'forbidden']);
  $f = __DIR__ . '/' . $v['filename'];
  if (!is_file($f)) jarvis_respond(404, ['error'=>'file not found']);
  $mime = mime_content_type($f) ?: 'video/webm';
  header('Content-Type: ' . $mime);
  header('Content-Disposition: attachment; filename="' . basename($f) . '"');
  readfile($f);
  exit;
}

// ----------------------------
// Command
// ----------------------------

if ($path === '/api/command') {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $in = jarvis_json_input();
  $text = trim((string)($in['text'] ?? ''));
  $inputType = trim((string)($in['type'] ?? 'text')); // text or voice
  $clientMeta = isset($in['meta']) && is_array($in['meta']) ? $in['meta'] : [];
  $voiceId = isset($clientMeta['voice_input_id']) ? (int)$clientMeta['voice_input_id'] : null;
  // If a voice input id is supplied but no text, prefer the transcript from the stored voice input so
  // voice submissions are processed like normal text commands and return the typical dialoged response.
  if ($voiceId && $text === '') {
    $v = jarvis_voice_input_by_id($voiceId);
    if ($v) {
      $text = (string)($v['transcript'] ?? '') ?: $text;
      // add original voice meta to client meta so later logging will include it
      $clientMeta['voice_input_id'] = $voiceId;
      $clientMeta['voice_transcript'] = (string)($v['transcript'] ?? '');
    }
  }
  if ($text === '' && !$voiceId) jarvis_respond(400, ['error'=>'text required']);
  if ($voiceId) {
    // Record an audit that a voice command was submitted and tie to the voice input id
    jarvis_audit($userId, 'COMMAND_VOICE_SUBMIT', 'voice', array_merge($clientMeta, ['voice_input_id'=>$voiceId, 'question'=>$text]));
  }

  // Response metadata to include voice linkage when applicable
  $respMeta = [];
  if ($voiceId) {
    $respMeta['voice_input_id'] = $voiceId;
    if (!empty($clientMeta['voice_transcript'])) {
      $respMeta['voice_transcript'] = (string)$clientMeta['voice_transcript'];
    } else {
      try {
        $vv = jarvis_voice_input_by_id($voiceId);
        if ($vv && !empty($vv['transcript'])) $respMeta['voice_transcript'] = (string)$vv['transcript'];
      } catch (Throwable $e) { /* ignore */ }
    }
  }

  // ----------------------------
  // Basic command processing (simple built-in commands)
  // ----------------------------
  $respText = '';
  $cards = [];
  try {
    $lc = strtolower(trim($text));
    if ($lc === 'whoami' || strpos($lc, 'whoami') !== false) {
      $name = $u['username'] ?? ($u['email'] ?? 'operator');
      $email = $u['email'] ?? '';
      $role = $u['role'] ?? '';
      $respText = "You are @" . $name . ($email ? " ({$email})" : '') . ($role ? " — role: {$role}" : '');
    } elseif ($lc === 'weather' || strpos($lc, 'weather') !== false) {
      $recent = jarvis_recent_locations($userId, 1);
      if (empty($recent)) {
        $respText = "Location not available. Submit a location or enable browser location.";
      } else {
        $loc = $recent[0];
        $weather = null;
        try { $weather = jarvis_fetch_weather((float)$loc['lat'], (float)$loc['lon']); } catch (Throwable $e) { $weather = null; }
        if ($weather) {
          $desc = (string)($weather['desc'] ?? ($weather['raw']['weather'][0]['description'] ?? ''));
          $temp = isset($weather['temp_c']) ? ($weather['temp_c'] . '°C') : null;
          $respText = trim("Weather: " . ($desc ? $desc : 'unknown') . ($temp ? ' • ' . $temp : ''));
          $cards['weather'] = $weather;
          $respMeta['weather'] = $weather;
        } else {
          $respText = "Weather unavailable (API error or key missing).";
        }
      }
    } elseif ($lc === 'briefing' || $lc === '/brief' || $lc === 'brief') {
      $brief = jarvis_compose_briefing($userId, 'briefing');
      $respText = $brief['text'] ?? 'Briefing failed';
      if (!empty($brief['cards'])) $cards = $brief['cards'];
    } else {
      // Default fallback: inform the user
      $respText = "I don't know that command. Try 'whoami', 'weather', or 'briefing'.";
    }
  } catch (Throwable $e) {
    $respText = "Error processing command.";
  }

  // Log the command and response
  $logMeta = $respMeta;
  if (!empty($cards)) $logMeta['cards'] = $cards;
  jarvis_log_command($userId, $inputType, $text, $respText, $logMeta);
  jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['ok'=>true,'jarvis_response'=>$respText], 200);

  $response = ['ok'=>true,'jarvis_response'=>$respText];
  if (!empty($cards)) $response['cards'] = $cards;
  if (!empty($respMeta)) $response = array_merge($response, $respMeta);
  jarvis_respond(200, $response);

// ----------------------------
// Voice inputs: save audio blobs + transcript for deep dictation analysis
// ----------------------------
if ($path === '/api/voice') {
  @file_put_contents('/tmp/jarvis_req.log', json_encode(['ts'=>time(),'enter_voice_handler'=>true,'method'=>$method]) . PHP_EOL, FILE_APPEND);
  if ($method === 'POST') {
    [$userId, $u] = require_jwt_user();
    if (!jarvis_rate_limit($userId, '/api/voice', 20)) jarvis_respond(429, ['error'=>'rate limited']);
    // Accept multipart/form-data with 'file', 'transcript', 'duration'
    $uploadedFile = $_FILES['file'] ?? null;
    if (!$uploadedFile || ($uploadedFile['error'] ?? 1) !== 0) jarvis_respond(400, ['error'=>'no file uploaded']);

    // Ensure storage dir exists
    $baseDir = __DIR__ . '/storage/voice/' . (int)$userId;
    if (!is_dir($baseDir)) @mkdir($baseDir, 0770, true);
    $ext = pathinfo($uploadedFile['name'] ?? 'blob', PATHINFO_EXTENSION) ?: 'webm';
    $fname = sprintf('%s_%s.%s', (int)$userId, bin2hex(random_bytes(6)), $ext);
    $dest = $baseDir . '/' . $fname;
    if (!move_uploaded_file($uploadedFile['tmp_name'], $dest)) jarvis_respond(500, ['error'=>'failed to save file']);

    $transcript = isset($_POST['transcript']) ? trim((string)$_POST['transcript']) : null;
    $duration = isset($_POST['duration']) ? (int)$_POST['duration'] : null;
    $meta = [];
    if (isset($_POST['meta'])) {
      $meta = json_decode((string)$_POST['meta'], true) ?: [];
    }

    $filePathForDb = 'storage/voice/' . (int)$userId . '/' . $fname;

  }
}

// ----------------------------
// Photos (uploads from iOS Shortcuts or other sources)
// ----------------------------
if ($path === '/api/photos') {
  if ($method === 'POST') {
    // Allow either a JWT user or a device upload token (Bearer)
    $bearer = jarvis_bearer_token();
    $userId = 0; $u = null; $clientType = 'web'; $used_device_token = false;
    if ($bearer) {
      $payload = jarvis_jwt_verify($bearer);
      if ($payload && !empty($payload['sub'])) {
        $userId = (int)$payload['sub'];
        $u = jarvis_user_by_id($userId);
        $clientType = 'web';
      } else {
        $t = jarvis_get_user_for_upload_token($bearer);
        if ($t && !empty($t['user_id'])) {
          $userId = (int)$t['user_id'];
          $u = jarvis_user_by_id($userId);
          $clientType = 'ios-shortcut';
          $used_device_token = true;
        }
      }
    }
    // If no bearer token or invalid, reject
    if (!$userId || !$u) jarvis_respond(401, ['error'=>'Unauthorized']);
    // Rate limit uploads: 15/min per user
    if (!jarvis_rate_limit($userId, '/api/photos', 15)) jarvis_respond(429, ['error'=>'rate limited']);

    $uploadedFile = $_FILES['file'] ?? null;
    if (!$uploadedFile || ($uploadedFile['error'] ?? 1) !== 0) jarvis_respond(400, ['error'=>'no file uploaded']);

    // Basic validation: size <= 25MB and allowed extensions
    $size = (int)($uploadedFile['size'] ?? 0);
    if ($size <= 0 || $size > 25*1024*1024) jarvis_respond(413, ['error'=>'file too large (max 25MB)']);
    $origName = $uploadedFile['name'] ?? null;
    $ext = strtolower(pathinfo($origName ?? 'photo', PATHINFO_EXTENSION)) ?: 'jpg';
    if (!in_array($ext, ['jpg','jpeg','png','gif'])) jarvis_respond(415, ['error'=>'unsupported file type']);

    $baseDir = __DIR__ . '/storage/photos/' . (int)$userId;
    if (!is_dir($baseDir)) @mkdir($baseDir, 0770, true);
    $origName = $uploadedFile['name'] ?? null;
    $ext = strtolower(pathinfo($origName ?? 'photo', PATHINFO_EXTENSION)) ?: 'jpg';
    // sanitize extension
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg';
    $fname = sprintf('%s_%s.%s', (int)$userId, bin2hex(random_bytes(6)), $ext);
    $dest = $baseDir . '/' . $fname;
    if (!move_uploaded_file($uploadedFile['tmp_name'], $dest)) jarvis_respond(500, ['error'=>'failed to save file']);

    // Attempt to create a thumbnail (best-effort) using GD
    $thumbName = null;
    try {
      if (function_exists('getimagesize') && function_exists('imagecreatetruecolor')) {
        $info = @getimagesize($dest);
        if ($info && isset($info[0], $info[1])) {
          $max = 600;
          $ratio = min(1, $max / max($info[0], $info[1]));
          $tw = (int)round($info[0] * $ratio);
          $th = (int)round($info[1] * $ratio);
          $src = null;
          $mime = $info['mime'] ?? '';
          if ($mime === 'image/jpeg' || $mime === 'image/pjpeg') $src = imagecreatefromjpeg($dest);
          elseif ($mime === 'image/png') $src = imagecreatefrompng($dest);
          elseif ($mime === 'image/gif') $src = imagecreatefromgif($dest);
          if ($src) {
            $dst = imagecreatetruecolor($tw, $th);
            imagecopyresampled($dst, $src, 0,0,0,0, $tw, $th, $info[0], $info[1]);
            $thumbName = 'thumb_' . $fname . '.jpg';
            $thumbPath = $baseDir . '/' . $thumbName;
            imagejpeg($dst, $thumbPath, 80);
            imagedestroy($dst);
            imagedestroy($src);
          }
        }
      }
    } catch (Throwable $e) { /* non-fatal */ }

    $meta = [];
    if (isset($_POST['meta'])) $meta = json_decode((string)$_POST['meta'], true) ?: [];

    // Attempt to extract EXIF (GPS/time) if available (best-effort)
    try {
      if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($dest, 0, true);
        if (is_array($exif) && !empty($exif)) {
          $meta['exif_present'] = true;
          // store some common fields (DateTimeOriginal)
          if (!empty($exif['EXIF']['DateTimeOriginal'])) $meta['exif_datetime'] = (string)$exif['EXIF']['DateTimeOriginal'];
          // embed raw-ish GPS metadata if present
          $gps = jarvis_exif_get_gps($exif);
          if ($gps) {
            $meta['exif_gps'] = $gps;
            // Create a location log for this photo (best-effort) and attach id
            try {
              $pdo = jarvis_pdo();
              if ($pdo) {
                $stmt = $pdo->prepare('INSERT INTO location_logs (user_id,lat,lon,accuracy_m,source) VALUES (:u,:la,:lo,:a,:s)');
                $stmt->execute([':u'=>$userId, ':la'=>$gps['lat'], ':lo'=>$gps['lon'], ':a'=>null, ':s'=>'photo']);
                $locId = (int)$pdo->lastInsertId();
                if ($locId) $meta['photo_location_id'] = $locId;
              }
            } catch (Throwable $e) {
              // ignore location insertion errors
            }
          }
        }
      }
    } catch (Throwable $e) { /* ignore exif errors */ }

    $photoId = jarvis_store_photo($userId, $fname, $origName, $meta);

    // If thumbnail was created, update the db thumb_filename (best-effort)
    if ($thumbName && $photoId) {
      $pdo = jarvis_pdo(); if ($pdo) {
        $pdo->prepare('UPDATE photos SET thumb_filename = :t WHERE id = :id')->execute([':t'=>$thumbName, ':id'=>$photoId]);
      }
    }

    // Enqueue a reprocess job (best-effort) so a worker can ensure thumbnails/EXIF/location are created
    try {
      jarvis_enqueue_job('photo_reprocess', ['photo_id'=>$photoId]);
    } catch (Throwable $e) { /* ignore enqueue errors */ }

    jarvis_audit($userId, 'PHOTO_UPLOAD', 'photo', array_merge($meta, ['photo_id'=>$photoId,'filename'=>$fname]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, [], ['ok'=>true,'id'=>$photoId,'url'=>'/api/photos/'.$photoId.'/download','thumb_url'=>'/api/photos/'.$photoId.'/download?thumb=1'], 200);
    jarvis_respond(201, ['ok'=>true,'id'=>$photoId,'url'=>'/api/photos/'.$photoId.'/download','thumb_url'=>'/api/photos/'.$photoId.'/download?thumb=1']);
  }

  if ($method === 'GET') {
    [$userId, $u] = require_jwt_user();
    $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $rows = jarvis_list_photos($userId, $limit, $offset);
    jarvis_respond(200, ['ok'=>true,'count'=>count($rows),'photos'=>$rows]);
  }

  jarvis_respond(405, ['error'=>'Method not allowed']);
}

// ----------------------------
// Device upload tokens (manage per-user tokens for iOS Shortcuts)
// ----------------------------
if ($path === '/api/device_tokens') {
  [$userId, $u] = require_jwt_user();
  if ($method === 'POST') {
    $in = jarvis_json_input();
    $label = isset($in['label']) ? trim((string)$in['label']) : null;
    $ttl = isset($in['ttl_seconds']) ? max(0, (int)$in['ttl_seconds']) : 604800;
    $token = jarvis_create_device_upload_token($userId, $label, $ttl);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['ok'=>true,'token_id'=>$token['id']], 201);
    jarvis_respond(201, ['ok'=>true,'token'=>$token]);
  }
  if ($method === 'GET') {
    $rows = jarvis_list_device_upload_tokens($userId);
    jarvis_respond(200, ['ok'=>true,'tokens'=>$rows]);
  }
  jarvis_respond(405, ['error'=>'Method not allowed']);
}

if (preg_match('#^/api/device_tokens/([0-9]+)$#', $path, $m)) {
  [$userId, $u] = require_jwt_user();
  if ($method === 'DELETE') {
    $id = (int)$m[1];
    $ok = jarvis_revoke_device_upload_token($id, $userId);
    jarvis_log_api_request($userId, 'desktop', $path, $method, null, ['ok'=>$ok,'id'=>$id], $ok ? 200 : 404);
    if ($ok) jarvis_respond(200, ['ok'=>true,'id'=>$id]);
    jarvis_respond(404, ['error'=>'not found']);
  }
  jarvis_respond(405, ['error'=>'Method not allowed']);
}

if (preg_match('#^/api/photos/(\d+)/download$#', $path, $m)) {
  $id = (int)$m[1];
  [$userId, $u] = require_jwt_user();
  $photo = jarvis_get_photo_by_id($id);
  if (!$photo) jarvis_respond(404, ['error'=>'not found']);
  if ((int)$photo['user_id'] !== (int)$userId && ($u['role'] ?? '') !== 'admin') jarvis_respond(403, ['error'=>'forbidden']);
  $thumb = isset($_GET['thumb']) && ($_GET['thumb'] === '1' || $_GET['thumb'] === 'true');
  $baseDir = __DIR__ . '/storage/photos/' . (int)$photo['user_id'];
  $file = $thumb && !empty($photo['thumb_filename']) ? ($baseDir . '/' . $photo['thumb_filename']) : ($baseDir . '/' . $photo['filename']);
  if (!is_file($file)) jarvis_respond(404, ['error'=>'file not found']);
  $mime = mime_content_type($file) ?: 'application/octet-stream';
  header('Content-Type: ' . $mime);
  header('Content-Disposition: attachment; filename="' . basename($photo['original_filename'] ?: $file) . '"');
  readfile($file);
  exit;
}

// Download a recorded voice blob (authenticated via JWT or Session for Admin UI)
if (preg_match('#^/api/voice/([0-9]+)/download$#', $path, $m)) {
  // Download handler moved to top-level to support direct GET/HEAD requests
}

  $prefs = jarvis_preferences($userId);
  $lower = strtolower($text);
  $cards = [];
  $commandType = 'user_command';

  $auditMeta = function(array $m=[]) use ($clientMeta, $inputType, $text) {
    return array_merge($clientMeta, $m, ['type'=>$inputType, 'question'=>$text]);
  };

  // ------------------
  // Simple automated responses
  // ------------------
  if (in_array($lower, ['whoami','who am i'])) {
    $response = "You are " . $u['username'] . " (" . $u['email'] . ")";
    jarvis_log_command($userId, 'system', $text, $response, $clientMeta);
    jarvis_audit($userId, 'COMMAND_WHOAMI', 'command', $auditMeta(['answer'=>$response]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
  }

  if ($lower === 'last login' || $lower === 'when did i last login' || $lower === 'last login time') {
    $response = 'Last login: ' . ($u['last_login_at'] ?: 'Never');
    jarvis_log_command($userId, 'system', $text, $response, $clientMeta);
    jarvis_audit($userId, 'COMMAND_LAST_LOGIN', 'command', $auditMeta(['answer'=>$response]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
  }

  if ($lower === 'notifications' || $lower === 'unread notifications') {
    $count = jarvis_unread_notifications_count($userId);
    $response = 'You have ' . (int)$count . ' unread notifications.';
    jarvis_log_command($userId, 'system', $text, $response, $clientMeta);
    jarvis_audit($userId, 'COMMAND_NOTIF_COUNT', 'command', $auditMeta(['answer'=>$response,'count'=>$count]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
  }

  // Time / Date
  if (strpos($lower, 'time') !== false || strpos($lower, 'date') !== false || strpos($lower, 'day is it') !== false) {
    if (strpos($lower, 'time') !== false) $response = "It is currently " . date('g:i A');
    else $response = "It is " . date('l, F jS, Y');
    jarvis_log_command($userId, 'system', $text, $response, $clientMeta);
    jarvis_audit($userId, 'COMMAND_TIME', 'command', $auditMeta(['answer'=>$response]));
    jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
  }

  // Home Automation Voice Control
  if (preg_match('/(turn|switch) (on|off) (.+)/', $lower, $m)) {
    $action = $m[2]; // on/off
    $target = trim($m[3]); // "the lights", "office lights"
    // simplistic match against device names
    $pdo = jarvis_pdo();
    $stmt = $pdo->prepare("SELECT * FROM home_devices WHERE user_id=:u");
    $stmt->execute([':u'=>$userId]);
    $allDevs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $matched = null;
    foreach($allDevs as $d) {
        if (strpos(strtolower($target), strtolower($d['name'])) !== false) {
            $matched = $d; break;
        }
        // if user said "lights" and device is "Office Lights", match if it's the only light? 
        // For now, simple substring match is enough or exact match
        if (strpos(strtolower($d['name']), strtolower($target)) !== false) {
             $matched = $d; break;
        }
    }
    
    if ($matched) {
        $pdo->prepare("UPDATE home_devices SET status=:s WHERE id=:id")->execute([':s'=>$action, ':id'=>$matched['id']]);
        $response = "Turning " . htmlspecialchars($matched['name']) . " " . $action . ".";
        jarvis_log_command($userId, 'home_control', $text, $response, array_merge($clientMeta, ['device_id'=>$matched['id']]));
        jarvis_audit($userId, 'HOME_DEVICE_TOGGLE', 'home_device', ['device_id'=>$matched['id'], 'new_status'=>$action, 'trigger'=>'voice']);
        // Refresh client UI
        jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
    } else {
        $response = "I could not find a device named '$target'.";
        jarvis_log_command($userId, 'home_control', $text, $response, $clientMeta);
        jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
    }
  }

  // Weather query (new): if user asks about weather, try to fetch weather for most recent location
  if (strpos($lower, 'weather') !== false) {
    // Attempt to get last known location
    $locs = jarvis_recent_locations($userId, 1);
    $lat = null; $lon = null;
    if (!empty($locs) && isset($locs[0]['lat']) && isset($locs[0]['lon'])) {
      $lat = (float)$locs[0]['lat']; $lon = (float)$locs[0]['lon'];
    }
    if ($lat === null || $lon === null) {
      $response = 'No recent location found. Please share your location to get local weather.';
      jarvis_log_command($userId, 'weather', $text, $response, $clientMeta);
      jarvis_audit($userId, 'COMMAND_WEATHER_NO_LOCATION', 'command', $auditMeta(['answer'=>$response]));
      jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
    }

    $weather = null;
    try { $weather = jarvis_fetch_weather($lat, $lon); } catch (Throwable $e) { $weather = null; }
    if (!$weather) {
      $response = 'Unable to fetch weather for your location at this time.';
      jarvis_log_command($userId, 'weather', $text, $response, $clientMeta);
      jarvis_audit($userId, 'COMMAND_WEATHER_FAIL', 'command', $auditMeta(['answer'=>$response]));
      jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
    }

    // Build a user-friendly message
    if (!empty($weather['demo'])) {
      $response = 'Weather (demo): ' . ($weather['desc'] ?? 'n/a');
    } else {
      $temp = isset($weather['temp_c']) && $weather['temp_c'] !== null ? round($weather['temp_c']) . '°C' : 'N/A';
      $response = 'Weather: ' . ($weather['desc'] ?? 'unknown') . ' • ' . $temp;
    }
    jarvis_log_command($userId, 'weather', $text, $response, array_merge($clientMeta, ['weather'=>$weather]));
    jarvis_audit($userId, 'COMMAND_WEATHER', 'command', $auditMeta(['answer'=>$response]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response,'weather'=>$weather], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response, 'cards'=>['weather'=>$weather]], $respMeta));
  }
  
  // Calendar Check
  if (strpos($lower, 'calendar') !== false || strpos($lower, 'schedule') !== false || strpos($lower, 'appointments') !== false) {
      $events = jarvis_list_calendar_events($userId, 3);
      if (empty($events)) {
          $response = "Your calendar is clear for the immediate future.";
      } else {
          $response = "You have " . count($events) . " upcoming events. ";
          $first = $events[0];
          $response .= "Next is " . ($first['summary']??'event') . " at " . ($first['start_time']??'unknown time') . ".";
      }
      jarvis_log_command($userId, 'calendar', $text, $response, array_merge($clientMeta, ['events_count'=>count($events)]));
      jarvis_audit($userId, 'COMMAND_CALENDAR', 'command', $auditMeta(['answer'=>$response]));
      jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
  }

  // General conversational responses
  $hello = ['hello', 'hi', 'hey', 'greetings', 'hello jarvis', 'hi jarvis', 'hey jarvis'];
  if (in_array($lower, $hello)) {
    $r = ["Hello, sir.", "Greetings.", "Online and ready.", "Hello. How can I help?", "At your service."];
    $response = $r[array_rand($r)];
    jarvis_log_command($userId, 'system', $text, $response, $clientMeta);
    jarvis_audit($userId, 'COMMAND_CHAT', 'command', $auditMeta(['answer'=>$response]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
  }

  if (strpos($lower, 'how are you') !== false) {
    $response = "I am functioning within normal parameters. Ready to assist.";
    jarvis_log_command($userId, 'system', $text, $response, $clientMeta);
    jarvis_audit($userId, 'COMMAND_CHAT', 'command', $auditMeta(['answer'=>$response]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
  }
  
  if (strpos($lower, 'thank you') !== false || strpos($lower, 'thanks') !== false) {
    $response = "You are welcome.";
    jarvis_log_command($userId, 'system', $text, $response, $clientMeta);
    jarvis_audit($userId, 'COMMAND_CHAT', 'command', $auditMeta(['answer'=>$response]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
  }

  // Existing behaviours
  if ($lower === 'briefing' || $lower === '/brief') {
    $out = jarvis_compose_briefing($userId, 'briefing');
    $response = (string)$out['text'];
    $cards = (array)($out['cards'] ?? []);
    $commandType = 'briefing';
    jarvis_log_command($userId, 'briefing', $text, $response, array_merge($clientMeta, ['cards'=>$cards]));
    jarvis_audit($userId, 'COMMAND_BRIEFING', 'command', $auditMeta(['answer'=>$response]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response,'cards'=>$cards], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response, 'cards'=>$cards], $respMeta));
  }

  // Wake mode: produce a wake briefing (includes immediate weather and integrations)
  if ($lower === 'wake') {
    $out = jarvis_compose_briefing($userId, 'wake');
    $response = (string)$out['text'];
    $cards = (array)($out['cards'] ?? []);
    $commandType = 'wake';
    jarvis_log_command($userId, 'wake', $text, $response, array_merge($clientMeta, ['cards'=>$cards]));
    jarvis_audit($userId, 'COMMAND_WAKE', 'command', $auditMeta(['answer'=>$response]));
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response,'cards'=>$cards], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response, 'cards'=>$cards], $respMeta));
  }

  if ($lower === 'check ig' || $lower === '/ig') {
    $ig = jarvis_instagram_check_media_updates($userId);
    if (!empty($ig['ok'])) {
      $watch = '@' . ltrim((string)($ig['watch'] ?? ''), '@');
      $response = "Instagram check complete for {$watch}. New media since last check: " . (int)($ig['new_count'] ?? 0) . ".\nStories: not available in Basic Display.";
    } else {
      $response = "Instagram check: " . (string)($ig['note'] ?? 'unable to check');
    }
    $cards = ['instagram' => $ig];
    $commandType = 'instagram_check';
    jarvis_log_command($userId, 'integration', $text, $response, $cards);
    jarvis_audit($userId, 'COMMAND_INSTAGRAM_CHECK', 'command', ['type'=>$inputType, 'question'=>$text, 'answer'=>$response, 'ok'=>(bool)($ig['ok'] ?? false)]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response,'cards'=>$cards], 200);
    jarvis_respond(200, array_merge(['jarvis_response'=>$response, 'cards'=>$cards], $respMeta));
  }

  $response = "Command not recognized. Try: briefing, check ig.";
  $commandType = 'unrecognized';
  jarvis_log_command($userId, 'system', $text, $response, $clientMeta);
  jarvis_audit($userId, 'COMMAND_UNRECOGNIZED', 'command', $auditMeta(['answer'=>$response]));
  jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
  jarvis_respond(200, array_merge(['jarvis_response'=>$response], $respMeta));
}

// ----------------------------
// Messages (Slack)
// ----------------------------

// ----------------------------
// Instagram (Basic Display)
// ----------------------------
// Devices: register/list
// ----------------------------
if ($path === '/api/devices') {
  [$userId, $u] = require_jwt_user();
  if ($method === 'POST') {
    $in = jarvis_json_input();
    $uuid = trim((string)($in['uuid'] ?? ''));
    $platform = trim((string)($in['platform'] ?? ''));
    $provider = isset($in['push_provider']) ? (string)$in['push_provider'] : null;
    $token = isset($in['push_token']) ? (string)$in['push_token'] : null;
    if ($uuid === '' || $platform === '') jarvis_respond(400, ['error'=>'uuid and platform required']);
    $id = jarvis_register_device($userId, $uuid, $platform, $provider, $token, null);
    jarvis_audit($userId, 'DEVICE_REGISTER', 'device', ['id'=>$id,'uuid'=>$uuid,'platform'=>$platform,'provider'=>$provider]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['ok'=>true,'id'=>$id], 200);
    jarvis_respond(200, ['ok'=>true,'id'=>$id]);
  }
  if ($method === 'GET') {
    $rows = jarvis_list_devices($userId);
    jarvis_respond(200, ['ok'=>true,'devices'=>$rows]);
  }
  jarvis_respond(405, ['error'=>'Method not allowed']);
}

if ($path === '/api/instagram/check') {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId] = require_jwt_user();
  $in = jarvis_json_input();
  // optional: force check even if watch username not set
  $ig = jarvis_instagram_check_media_updates($userId);
  $resp = ['ok'=> (bool)($ig['ok'] ?? false), 'instagram'=>$ig];
  jarvis_log_api_request($userId, 'desktop', $path, $method, $in, $resp, 200);
  jarvis_respond(200, $resp);
}

if ($path === '/api/messages') {
  [$userId, $u] = require_jwt_user();

  if ($method === 'POST') {
    $in = jarvis_json_input();
    $message = trim((string)($in['message'] ?? ''));
    $channel = trim((string)($in['channel'] ?? ''));
    $provider = trim((string)($in['provider'] ?? '')) ?: 'slack';
    $threadId = isset($in['thread_id']) ? (int)$in['thread_id'] : null;
    // Rate limit local posts: 30/min per user
    if ($provider === 'local' || stripos($channel, 'local:') === 0) {
      if (!jarvis_rate_limit($userId, '/api/messages', 30)) jarvis_respond(429, ['error'=>'rate limited']);
    }
    if ($message === '') jarvis_respond(400, ['error' => 'message is required']);
    if ($channel === '') {
      $prefs = jarvis_preferences($userId);
      $channel = trim((string)($prefs['default_slack_channel'] ?? ''));
      if ($channel === '') $channel = trim((string)(getenv('SLACK_CHANNEL_ID') ?: ''));
      if ($channel === '') jarvis_respond(400, ['error' => 'channel required']);
    }

    // If provider explicitly 'local' or channel namespaced as local:, store locally
    if ($provider === 'local' || stripos($channel, 'local:') === 0) {
      // Parse hashtags
      $tags = [];
      if (preg_match_all('/#([A-Za-z0-9_\-]+)/', $message, $m)) {
        $tags = array_values(array_unique($m[1]));
      }
      // Parse mentions @username
      $mentions = [];
      if (preg_match_all('/@([A-Za-z0-9_\-]+)/', $message, $mm)) {
        $mentions = array_values(array_unique($mm[1]));
      }

      $meta = [];
      if (!empty($tags)) $meta['tags'] = $tags;
      if (!empty($mentions)) $meta['mentions'] = $mentions;

      // Store the local message or reply
      if ($threadId) {
        $mid = jarvis_log_local_reply($userId, $channel, $threadId, $message, $meta);
      } else {
        jarvis_log_local_message($userId, $channel, $message, $meta);
      }

      // Notify mentioned users (best-effort)
      foreach ($mentions as $un) {
        try {
          $target = jarvis_user_by_username($un);
          if ($target && isset($target['id'])) {
            jarvis_notify((int)$target['id'], 'mention', "Mentioned in {$channel}", substr($message ?: '', 0, 280), ['from'=>$u['username'] ?? null, 'channel'=>$channel, 'message'=>$message]);
            jarvis_audit((int)$target['id'], 'MENTIONED', 'channel', ['by'=>$u['username'] ?? null, 'channel'=>$channel, 'mention'=>$un]);
          }
        } catch (Throwable $e) { /* ignore mention failures */ }
      }

      jarvis_audit($userId, 'CHANNEL_MSG', 'channel', ['channel'=>$channel,'tags'=>$tags,'mentions'=>$mentions]);
      jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['ok'=>true,'channel'=>$channel,'tags'=>$tags,'mentions'=>$mentions], 201);
        jarvis_respond(201, ['ok'=>true,'channel'=>$channel,'tags'=>$tags,'mentions'=>$mentions,'thread_id'=>$threadId]);
    }

    // Otherwise, attempt to post to Slack (existing behavior)
    $token = jarvis_setting_get('SLACK_BOT_TOKEN') ?: jarvis_setting_get('SLACK_APP_TOKEN') ?: getenv('SLACK_BOT_TOKEN') ?: getenv('SLACK_APP_TOKEN');
    if (!$token) jarvis_respond(500, ['error' => 'SLACK is not configured (missing SLACK_APP_TOKEN / SLACK_BOT_TOKEN)']);
    $resp = slack_post_message_api($token, $channel, $message);

    jarvis_log_slack_message($userId, $channel, $message, $resp);
    jarvis_audit($userId, 'SLACK_SEND', 'slack', ['channel'=>$channel, 'ok'=>(bool)($resp['ok'] ?? false)]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['slack'=>$resp], (int)($resp['ok'] ? 200 : 502));

    if (!empty($resp['ok'])) {
      jarvis_notify($userId, 'success', 'Slack message sent', $message, ['channel'=>$channel]);
      $prefs = jarvis_preferences($userId);
      if (!empty($u['phone_e164']) && !empty($prefs['notif_sms'])) {
        jarvis_send_sms($u['phone_e164'], "JARVIS → Slack: {$message}");
      }
      jarvis_respond(200, ['ok' => true, 'slack' => $resp]);
    }
    jarvis_respond(502, ['ok' => false, 'slack' => $resp]);
  }

  if ($method === 'GET') {
    [$userId, $u] = require_jwt_user();
    $channel = isset($_GET['channel']) ? trim((string)$_GET['channel']) : null;
    $tag = isset($_GET['tag']) ? trim((string)$_GET['tag']) : null;
    $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $thread = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
    if ($thread > 0) {
      $rows = jarvis_list_thread_messages($thread, $limit, $offset);
      jarvis_log_api_request($userId, 'desktop', $path, $method, ['thread_id'=>$thread,'limit'=>$limit,'offset'=>$offset], ['ok'=>true,'count'=>count($rows)], 200);
      jarvis_respond(200, ['ok'=>true,'count'=>count($rows),'messages'=>$rows]);
    }
    if ($channel === null) jarvis_respond(400, ['error'=>'channel required']);
    $rows = jarvis_list_channel_messages($channel, $limit, $offset, $tag);
    jarvis_log_api_request($userId, 'desktop', $path, $method, ['channel'=>$channel,'tag'=>$tag,'limit'=>$limit,'offset'=>$offset], ['ok'=>true,'count'=>count($rows)], 200);
    jarvis_respond(200, ['ok'=>true,'count'=>count($rows),'messages'=>$rows]);
  }

  // Delete a local message (owner or admin can delete)
  if ($method === 'DELETE' && preg_match('#^/api/messages/([0-9]+)$#', $path, $md)) {
    [$userId, $u] = require_jwt_user();
    $mid = (int)$md[1];
    $pdo = jarvis_pdo(); if (!$pdo) jarvis_respond(500, ['error'=>'DB not configured']);
    // Check ownership or admin
    $stmt = $pdo->prepare('SELECT user_id,provider FROM messages WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$mid]); $mrow = $stmt->fetch();
    if (!$mrow) jarvis_respond(404, ['error'=>'not found']);
    if ((int)$mrow['user_id'] !== (int)$userId && ($u['role'] ?? '') !== 'admin') jarvis_respond(403, ['error'=>'forbidden']);
    // Only allow deleting local messages via this API
    if (($mrow['provider'] ?? '') !== 'local') jarvis_respond(403, ['error'=>'only local messages may be deleted here']);
    $pdo->prepare('DELETE FROM messages WHERE id=:id')->execute([':id'=>$mid]);
    jarvis_audit($userId, 'CHANNEL_MESSAGE_DELETED', 'channel', ['message_id'=>$mid]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, null, ['ok'=>true,'id'=>$mid], 200);
    jarvis_respond(200, ['ok'=>true,'id'=>$mid]);
  }

  // Edit a local message (owner or admin only)
  if ($method === 'PATCH' && preg_match('#^/api/messages/([0-9]+)$#', $path, $md)) {
    [$userId, $u] = require_jwt_user();
    $mid = (int)$md[1];
    $pdo = jarvis_pdo(); if (!$pdo) jarvis_respond(500, ['error'=>'DB not configured']);
    $stmt = $pdo->prepare('SELECT user_id,provider FROM messages WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$mid]); $mrow = $stmt->fetch();
    if (!$mrow) jarvis_respond(404, ['error'=>'not found']);
    if ((int)$mrow['user_id'] !== (int)$userId && ($u['role'] ?? '') !== 'admin') jarvis_respond(403, ['error'=>'forbidden']);
    if (($mrow['provider'] ?? '') !== 'local') jarvis_respond(403, ['error'=>'only local messages may be edited here']);
    $in = jarvis_json_input(); $text = trim((string)($in['message'] ?? ''));
    if ($text === '') jarvis_respond(400, ['error'=>'message required']);
    // re-parse tags and mentions
    $tags = []; if (preg_match_all('/#([A-Za-z0-9_\-]+)/', $text, $m)) $tags = array_values(array_unique($m[1]));
    $mentions = []; if (preg_match_all('/@([A-Za-z0-9_\-]+)/', $text, $mm)) $mentions = array_values(array_unique($mm[1]));
    $meta = []; if (!empty($tags)) $meta['tags'] = $tags; if (!empty($mentions)) $meta['mentions'] = $mentions;
    $ok = jarvis_edit_local_message($mid, $text, $meta);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['ok'=>$ok,'id'=>$mid], $ok ? 200 : 500);
    if ($ok) jarvis_respond(200, ['ok'=>true,'id'=>$mid]);
    jarvis_respond(500, ['ok'=>false]);
  }

  // Reactions: add/remove/list
  if (preg_match('#^/api/messages/(\d+)/reactions$#', $path, $rm)) {
    [$userId, $u] = require_jwt_user();
    $mid = (int)$rm[1];
    if ($method === 'POST') {
      $in = jarvis_json_input(); $type = trim((string)($in['type'] ?? ''));
      if ($type==='') jarvis_respond(400, ['error'=>'type required']);
      $ok = jarvis_add_message_reaction($userId, $mid, $type);
      jarvis_respond($ok ? 200 : 500, ['ok'=>$ok]);
    }
    if ($method === 'DELETE') {
      $in = jarvis_json_input(); $type = trim((string)($in['type'] ?? ''));
      if ($type==='') jarvis_respond(400, ['error'=>'type required']);
      $ok = jarvis_remove_message_reaction($userId, $mid, $type);
      jarvis_respond($ok ? 200 : 500, ['ok'=>$ok]);
    }
    if ($method === 'GET') {
      $data = jarvis_list_message_reactions($mid);
      jarvis_respond(200, ['ok'=>true] + $data);
    }
    jarvis_respond(405, ['error'=>'Method not allowed']);
  }

  jarvis_respond(405, ['error' => 'Method not allowed']);
}

// ----------------------------
// Location (browser -> Yahoo weather placeholder)
// ----------------------------

if ($path === '/api/location') {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $in = jarvis_json_input();
  $lat = (float)($in['lat'] ?? 0);
  $lon = (float)($in['lon'] ?? 0);
  $acc = isset($in['accuracy']) ? (float)$in['accuracy'] : null;
  if (!$lat || !$lon) jarvis_respond(400, ['error'=>'lat/lon required']);

  $pdo = jarvis_pdo();
  $stmt = $pdo->prepare('INSERT INTO location_logs (user_id,lat,lon,accuracy_m,source) VALUES (:u,:la,:lo,:a,:s)');
  $stmt->execute([':u'=>$userId, ':la'=>$lat, ':lo'=>$lon, ':a'=>$acc, ':s'=>'browser']);
  $locId = (int)$pdo->lastInsertId();

  // Fetch weather if API key is configured
  $weather = null;
  try {
    $weather = jarvis_fetch_weather($lat, $lon);
  } catch (Throwable $e) { $weather = null; }

  $meta = ['lat'=>$lat,'lon'=>$lon,'accuracy'=>$acc,'location_id'=>$locId];
  if ($weather) $meta['weather'] = $weather;

  // Enrich with reverse-geocode information (city/state/country) when available
  try {
    $addr = jarvis_reverse_geocode((float)$lat, (float)$lon);
    if ($addr) {
      $meta['address'] = $addr;
    }
  } catch (Throwable $e) { /* non-fatal */ }

  jarvis_audit($userId, 'LOCATION_UPDATE', 'location', $meta);

  $resp = ['ok'=>true, 'lat'=>$lat, 'lon'=>$lon, 'weather'=>$weather, 'location_id'=>$locId];
  if (!empty($addr)) $resp['address'] = $addr;
  jarvis_log_api_request($userId, 'web', $path, $method, $in, $resp, 200);
  jarvis_respond(200, $resp);
}

// Recent locations for user (JWT required)
if ($path === '/api/locations') {
  if ($method !== 'GET') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
  $locations = jarvis_recent_locations($userId, $limit);
  jarvis_respond(200, ['ok'=>true, 'locations'=>$locations]);
}

// Simple client-driven audit endpoint to record UI-driven events (POST only)
if ($path === '/api/audit') {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $in = jarvis_json_input();
  $action = trim((string)($in['action'] ?? ''));
  $entity = isset($in['entity']) ? (string)$in['entity'] : null;
  $meta = isset($in['metadata']) && is_array($in['metadata']) ? $in['metadata'] : null;
  if ($action === '') jarvis_respond(400, ['error'=>'action required']);
  jarvis_audit($userId, $action, $entity, $meta);
  jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['ok'=>true], 200);
  jarvis_respond(200, ['ok'=>true]);
}

// List commands (GET) - returns recent command history for current user (or other user if admin)
if ($path === '/api/commands') {
  if ($method !== 'GET') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
  $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
  $q = isset($_GET['q']) ? trim((string)$_GET['q']) : null;
  $filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
  // Only allow filtering by other user if current user is admin
  if ($filterUser !== null && (($u['role'] ?? '') !== 'admin')) { $filterUser = $userId; }
  // If no filterUser provided, default to current user
  if ($filterUser === null) $filterUser = $userId;
  $rows = jarvis_list_commands($limit, $offset, $filterUser, $q);
  jarvis_log_api_request($userId, 'desktop', $path, $method, ['limit'=>$limit,'offset'=>$offset,'q'=>$q,'user_id'=>$filterUser], ['ok'=>true,'count'=>count($rows)], 200);
  jarvis_respond(200, ['ok'=>true,'count'=>count($rows),'commands'=>$rows]);
}

// Calendar events endpoint (GET upcoming)
if ($path === '/api/calendar') {
  if ($method !== 'GET') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;
  $events = jarvis_list_calendar_events($userId, $limit);
  jarvis_respond(200, ['ok'=>true, 'events'=>$events]);
}

// ----------------------------
// Devices (mobile app / push tokens)
// ----------------------------

if ($path === '/api/devices') {
  if ($method === 'POST') {
    [$userId, $u] = require_jwt_user();
    $in = jarvis_json_input();
    $deviceUuid = trim((string)($in['device_uuid'] ?? ''));
    $platform = trim((string)($in['platform'] ?? ''));
    $pushToken = trim((string)($in['push_token'] ?? '')) ?: null;
    $pushProvider = trim((string)($in['push_provider'] ?? '')) ?: null;
    $meta = isset($in['metadata']) && is_array($in['metadata']) ? $in['metadata'] : null;
    if ($deviceUuid === '' || $platform === '') jarvis_respond(400, ['error'=>'device_uuid and platform required']);
    $deviceId = jarvis_register_device($userId, $deviceUuid, $platform, $pushToken, $pushProvider, $meta);
    jarvis_audit($userId, 'DEVICE_REGISTERED', 'device', ['device_id'=>$deviceId,'platform'=>$platform]);
    jarvis_respond(201, ['device_id'=>$deviceId]);
  }

  if ($method === 'GET') {
    [$userId, $u] = require_jwt_user();
    $devices = jarvis_list_devices($userId);
    jarvis_respond(200, ['devices'=>$devices]);
  }

  jarvis_respond(405, ['error'=>'Method not allowed']);
}

if (preg_match('#^/api/devices/(\d+)/location$#', $path, $m)) {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $deviceId = (int)$m[1];
  $in = jarvis_json_input();
  $lat = (float)($in['lat'] ?? 0);
  $lon = (float)($in['lon'] ?? 0);
  $acc = isset($in['accuracy']) ? (float)$in['accuracy'] : null;
  if (!$lat || !$lon) jarvis_respond(400, ['error'=>'lat/lon required']);
  $locId = jarvis_update_device_location($userId, $deviceId, $lat, $lon, $acc);
  jarvis_audit($userId, 'DEVICE_LOCATION_UPDATE', 'device', ['device_id'=>$deviceId,'lat'=>$lat,'lon'=>$lon,'accuracy'=>$acc,'location_id'=>$locId]);
  jarvis_log_api_request($userId, 'device', $path, $method, $in, ['ok'=>true,'location_id'=>$locId], 200);
  jarvis_respond(200, ['ok'=>true,'location_id'=>$locId]);
}

if (preg_match('#^/api/devices/(\d+)$#', $path, $m)) {
  $deviceId = (int)$m[1];
  if ($method === 'DELETE') {
    [$userId, $u] = require_jwt_user();
    jarvis_unregister_device($userId, $deviceId);
    jarvis_audit($userId, 'DEVICE_UNREGISTERED', 'device', ['device_id'=>$deviceId]);
    jarvis_respond(200, ['ok'=>true]);
  }
}

// ----------------------------
// Home Automation (IoT Devices)
// ----------------------------

if ($path === '/api/home/devices') {
  [$userId, $u] = require_jwt_user();
  $pdo = jarvis_pdo();
  if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM home_devices WHERE user_id=:u ORDER BY name ASC");
    $stmt->execute([':u'=>$userId]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jarvis_respond(200, ['ok'=>true, 'devices'=>$list]);
  }
  if ($method === 'POST') {
    // Create new device
    $in = jarvis_json_input();
    $name = trim($in['name'] ?? '');
    $type = trim($in['type'] ?? 'switch');
    if (!$name) jarvis_respond(400,['error'=>'name required']);
    $stmt = $pdo->prepare("INSERT INTO home_devices (user_id, name, type) VALUES (:u, :n, :t)");
    $stmt->execute([':u'=>$userId, ':n'=>$name, ':t'=>$type]);
    jarvis_respond(201, ['ok'=>true, 'id'=>$pdo->lastInsertId()]);
  }
  jarvis_respond(405, ['error'=>'Method not allowed']);
}

if (preg_match('#^/api/home/devices/(\d+)/toggle$#', $path, $m)) {
  if ($method !== 'POST') jarvis_respond(405,['error'=>'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $did = (int)$m[1];
  $pdo = jarvis_pdo();
  // Fetch current
  $stmt = $pdo->prepare("SELECT id, status FROM home_devices WHERE id=:id AND user_id=:u");
  $stmt->execute([':id'=>$did, ':u'=>$userId]);
  $dev = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$dev) jarvis_respond(404,['error'=>'device not found']);
  
  $newStatus = ($dev['status'] === 'on') ? 'off' : 'on';
  $pdo->prepare("UPDATE home_devices SET status=:s WHERE id=:id")->execute([':s'=>$newStatus, ':id'=>$did]);
  
  jarvis_audit($userId, 'HOME_DEVICE_TOGGLE', 'home_device', ['device_id'=>$did, 'new_status'=>$newStatus, 'old_status'=>$dev['status']]);
  jarvis_respond(200, ['ok'=>true, 'device_id'=>$did, 'status'=>$newStatus]);
}

// ==================== Local Calendar Events API ====================

// GET /api/local-events - List local events
if ($path === '/api/local-events' && $method === 'GET') {
  [$userId, $u] = require_jwt_user();
  $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;
  $events = jarvis_list_local_events($userId, $limit);
  jarvis_respond(200, ['ok' => true, 'events' => $events]);
}

// POST /api/local-events - Add a local event
if ($path === '/api/local-events' && $method === 'POST') {
  [$userId, $u] = require_jwt_user();
  $body = json_decode(file_get_contents('php://input'), true) ?: [];
  $title = trim($body['title'] ?? '');
  $eventDate = trim($body['event_date'] ?? '');
  $eventTime = !empty($body['event_time']) ? trim($body['event_time']) : null;
  $location = !empty($body['location']) ? trim($body['location']) : null;
  $notes = !empty($body['notes']) ? trim($body['notes']) : null;
  
  if (!$title || !$eventDate) {
    jarvis_respond(400, ['ok' => false, 'error' => 'Title and event_date are required']);
  }
  
  $eventId = jarvis_add_local_event($userId, $title, $eventDate, $eventTime, $location, $notes);
  if ($eventId) {
    jarvis_respond(201, ['ok' => true, 'event_id' => $eventId]);
  } else {
    jarvis_respond(500, ['ok' => false, 'error' => 'Failed to create event']);
  }
}

// DELETE /api/local-events/:id - Delete a local event
if (preg_match('#^/api/local-events/(\d+)$#', $path, $m) && $method === 'DELETE') {
  [$userId, $u] = require_jwt_user();
  $eventId = (int)$m[1];
  $deleted = jarvis_delete_local_event($userId, $eventId);
  if ($deleted) {
    jarvis_respond(200, ['ok' => true, 'deleted' => $eventId]);
  } else {
    jarvis_respond(404, ['ok' => false, 'error' => 'Event not found or not owned by user']);
  }
}

jarvis_respond(404, ['error' => 'Not found']);
