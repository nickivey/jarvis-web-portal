<?php
// JARVIS REST API (JWT + MySQL)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/instagram_basic.php';
require_once __DIR__ . '/briefing.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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
// Command
// ----------------------------

if ($path === '/api/command') {
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $in = jarvis_json_input();
  $text = trim((string)($in['text'] ?? ''));
  $inputType = trim((string)($in['type'] ?? 'text')); // text or voice
  if ($text === '') jarvis_respond(400, ['error'=>'text required']);

  $prefs = jarvis_preferences($userId);
  $lower = strtolower($text);
  $cards = [];
  $commandType = 'user_command';

  // ------------------
  // Simple automated responses
  // ------------------
  if (in_array($lower, ['whoami','who am i'])) {
    $response = "You are " . $u['username'] . " (" . $u['email'] . ")";
    jarvis_log_command($userId, 'system', $text, $response, null);
    jarvis_audit($userId, 'COMMAND_WHOAMI', 'command', ['question'=>$text,'answer'=>$response]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, ['jarvis_response'=>$response]);
  }

  if ($lower === 'last login' || $lower === 'when did i last login' || $lower === 'last login time') {
    $response = 'Last login: ' . ($u['last_login_at'] ?: 'Never');
    jarvis_log_command($userId, 'system', $text, $response, null);
    jarvis_audit($userId, 'COMMAND_LAST_LOGIN', 'command', ['question'=>$text,'answer'=>$response]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, ['jarvis_response'=>$response]);
  }

  if ($lower === 'notifications' || $lower === 'unread notifications') {
    $count = jarvis_unread_notifications_count($userId);
    $response = 'You have ' . (int)$count . ' unread notifications.';
    jarvis_log_command($userId, 'system', $text, $response, null);
    jarvis_audit($userId, 'COMMAND_NOTIF_COUNT', 'command', ['question'=>$text,'answer'=>$response,'count'=>$count]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
    jarvis_respond(200, ['jarvis_response'=>$response]);
  }

  // Existing behaviours
  if ($lower === 'briefing' || $lower === '/brief') {
    $out = jarvis_compose_briefing($userId, 'briefing');
    $response = (string)$out['text'];
    $cards = (array)($out['cards'] ?? []);
    $commandType = 'briefing';
    jarvis_log_command($userId, 'briefing', $text, $response, $cards);
    jarvis_audit($userId, 'COMMAND_BRIEFING', 'command', ['type'=>$inputType, 'question'=>$text, 'answer'=>$response]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response,'cards'=>$cards], 200);
    jarvis_respond(200, ['jarvis_response'=>$response, 'cards'=>$cards]);
  }

  // Wake mode: produce a wake briefing (includes immediate weather and integrations)
  if ($lower === 'wake') {
    $out = jarvis_compose_briefing($userId, 'wake');
    $response = (string)$out['text'];
    $cards = (array)($out['cards'] ?? []);
    $commandType = 'wake';
    jarvis_log_command($userId, 'wake', $text, $response, $cards);
    jarvis_audit($userId, 'COMMAND_WAKE', 'command', ['type'=>$inputType, 'question'=>$text, 'answer'=>$response]);
    jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response,'cards'=>$cards], 200);
    jarvis_respond(200, ['jarvis_response'=>$response, 'cards'=>$cards]);
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
    jarvis_respond(200, ['jarvis_response'=>$response, 'cards'=>$cards]);
  }

  $response = "Command not recognized. Try: briefing, check ig.";
  $commandType = 'unrecognized';
  jarvis_log_command($userId, 'system', $text, $response, null);
  jarvis_audit($userId, 'COMMAND_UNRECOGNIZED', 'command', ['type'=>$inputType, 'question'=>$text, 'answer'=>$response]);
  jarvis_log_api_request($userId, 'desktop', $path, $method, $in, ['jarvis_response'=>$response], 200);
  jarvis_respond(200, ['jarvis_response'=>$response]);
}

// ----------------------------
// Messages (Slack)
// ----------------------------

// ----------------------------
// Instagram (Basic Display)
// ----------------------------

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
  if ($method !== 'POST') jarvis_respond(405, ['error' => 'Method not allowed']);
  [$userId, $u] = require_jwt_user();
  $in = jarvis_json_input();
  $message = trim((string)($in['message'] ?? ''));
  $channel = trim((string)($in['channel'] ?? ''));
  if ($message === '') jarvis_respond(400, ['error' => 'message is required']);
  if ($channel === '') {
    $prefs = jarvis_preferences($userId);
    $channel = trim((string)($prefs['default_slack_channel'] ?? ''));
    if ($channel === '') $channel = trim((string)(getenv('SLACK_CHANNEL_ID') ?: ''));
    if ($channel === '') jarvis_respond(400, ['error' => 'default Slack channel not configured']);
  }

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
  jarvis_audit($userId, 'LOCATION_UPDATE', 'location', $meta);

  $resp = ['ok'=>true, 'lat'=>$lat, 'lon'=>$lon, 'weather'=>$weather, 'location_id'=>$locId];
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

jarvis_respond(404, ['error' => 'Not found']);
