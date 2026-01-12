<?php
/**
 * JARVIS DB layer (MySQL via PDO)
 *
 * Env:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 */

function jarvis_pdo(): ?PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $host = 'localhost';
  $name = 'nickive2_jarvisp';
  $user = 'nickive2_jarvisp';
  $pass = 'ZDT?^PK}aMO)#}qU';
  if (!$host || !$name || !$user) return null;

  try {
    $pdo = new PDO(
      "mysql:host={$host};dbname={$name};charset=utf8mb4",
      $user,
      $pass ?: '',
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
    );
    jarvis_init_schema($pdo);
    return $pdo;
  } catch (Throwable $e) {
    error_log('DB error: ' . $e->getMessage());
    return null;
  }
}

/**
 * Idempotently apply schema.sql.
 */
function jarvis_init_schema(PDO $pdo): void {
  static $did = false;
  if ($did) return;
  $did = true;

  $schemaFile = __DIR__ . '/sql/schema.sql';
  if (!file_exists($schemaFile)) return;
  $sql = file_get_contents($schemaFile);
  if ($sql === false) return;

  // Split on semicolons that end statements.
  $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
  foreach ($stmts as $stmt) {
    $pdo->exec($stmt);
  }
}

function jarvis_now_sql(): string {
  return gmdate('Y-m-d H:i:s');
}

// ----------------------------
// Users
// ----------------------------

function jarvis_user_by_username(string $username): ?array {
  $pdo = jarvis_pdo();
  if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
  $stmt->execute([':u' => $username]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function jarvis_user_by_id(int $id): ?array {
  $pdo = jarvis_pdo();
  if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function jarvis_user_by_email(string $email): ?array {
  $pdo = jarvis_pdo();
  if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
  $stmt->execute([':e' => $email]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function jarvis_mark_email_verified(int $userId): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('UPDATE users SET email_verified_at = :ts, email_verify_token = NULL WHERE id = :id')
      ->execute([':ts' => jarvis_now_sql(), ':id' => $userId]);
}

function jarvis_create_user(string $username, string $email, ?string $phoneE164, string $passwordHash, string $verifyToken): int {
  $pdo = jarvis_pdo();
  if (!$pdo) throw new RuntimeException('DB not configured');

  $stmt = $pdo->prepare('INSERT INTO users (username,email,phone_e164,password_hash,email_verify_token) VALUES (:u,:e,:p,:h,:t)');
  $stmt->execute([
    ':u' => $username,
    ':e' => $email,
    ':p' => $phoneE164,
    ':h' => $passwordHash,
    ':t' => $verifyToken,
  ]);
  $userId = (int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO preferences (user_id) VALUES (:id)')->execute([':id' => $userId]);
  return $userId;
}

function jarvis_verify_email(string $username, string $token): bool {
  $pdo = jarvis_pdo();
  if (!$pdo) return false;
  $stmt = $pdo->prepare('SELECT id,email_verify_token,email_verified_at FROM users WHERE username=:u LIMIT 1');
  $stmt->execute([':u' => $username]);
  $row = $stmt->fetch();
  if (!$row) return false;
  if (!empty($row['email_verified_at'])) return true;
  if (!hash_equals((string)$row['email_verify_token'], (string)$token)) return false;
  $pdo->prepare('UPDATE users SET email_verified_at = :ts, email_verify_token = NULL WHERE id = :id')
      ->execute([':ts' => jarvis_now_sql(), ':id' => $row['id']]);
  jarvis_audit((int)$row['id'], 'EMAIL_VERIFIED', 'auth', null);
  return true;
}

function jarvis_update_last_login(int $userId): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('UPDATE users SET last_login_at=:t, last_seen_at=:t WHERE id=:id')
      ->execute([':t' => jarvis_now_sql(), ':id' => $userId]);
}

function jarvis_update_last_seen(int $userId): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('UPDATE users SET last_seen_at=:t WHERE id=:id')
      ->execute([':t' => jarvis_now_sql(), ':id' => $userId]);
}

function jarvis_update_phone(int $userId, ?string $phoneE164): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('UPDATE users SET phone_e164=:p WHERE id=:id')->execute([':p'=>$phoneE164, ':id'=>$userId]);
}

// ----------------------------
// Audit + Notifications
// ----------------------------

function jarvis_audit(?int $userId, string $action, ?string $entity=null, ?array $metadata=null): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $stmt = $pdo->prepare('INSERT INTO audit_log (user_id,action,entity,metadata_json,ip,user_agent) VALUES (:uid,:a,:e,:m,:ip,:ua)');
  $stmt->execute([
    ':uid' => $userId,
    ':a' => $action,
    ':e' => $entity,
    ':m' => $metadata ? json_encode($metadata) : null,
    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);
}

function jarvis_notify(int $userId, string $type, string $title, ?string $body=null, ?array $meta=null): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('INSERT INTO notifications (user_id,type,title,body,metadata_json) VALUES (:u,:t,:ti,:b,:m)')
      ->execute([
        ':u' => $userId,
        ':t' => $type,
        ':ti' => $title,
        ':b' => $body,
        ':m' => $meta ? json_encode($meta) : null,
      ]);
}

function jarvis_unread_notifications_count(int $userId): int {
  $pdo = jarvis_pdo();
  if (!$pdo) return 0;
  $stmt = $pdo->prepare('SELECT COUNT(*) c FROM notifications WHERE user_id=:u AND is_read=0');
  $stmt->execute([':u'=>$userId]);
  return (int)($stmt->fetch()['c'] ?? 0);
}

function jarvis_latest_audit(int $userId, int $limit=10): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT action,entity,created_at,metadata_json FROM audit_log WHERE user_id=:u ORDER BY id DESC LIMIT :l');
  $stmt->bindValue(':u',$userId,PDO::PARAM_INT);
  $stmt->bindValue(':l',$limit,PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function jarvis_recent_notifications(int $userId, int $limit=10): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT id,type,title,body,is_read,created_at FROM notifications WHERE user_id=:u ORDER BY id DESC LIMIT :l');
  $stmt->bindValue(':u',$userId,PDO::PARAM_INT);
  $stmt->bindValue(':l',$limit,PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

// ----------------------------
// Preferences
// ----------------------------

function jarvis_preferences(int $userId): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT * FROM preferences WHERE user_id=:u LIMIT 1');
  $stmt->execute([':u'=>$userId]);
  return $stmt->fetch() ?: [];
}

function jarvis_update_preferences(int $userId, array $fields): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $allowed = [
    'default_slack_channel',
    'instagram_watch_username',
    'location_logging_enabled',
    'notif_email','notif_sms','notif_inapp',
    // internal timestamps for sync checks
    'last_instagram_check_at',
    'last_instagram_story_check_at',
    'last_weather_check_at',
  ];
  $sets=[]; $params=[':u'=>$userId];
  foreach ($fields as $k=>$v) {
    if (!in_array($k,$allowed,true)) continue;
    $sets[] = "$k = :$k";
    $params[":$k"] = $v;
  }
  if (!$sets) return;
  $sql = 'UPDATE preferences SET ' . implode(', ', $sets) . ' WHERE user_id=:u';
  $pdo->prepare($sql)->execute($params);
}

// ----------------------------
// Messages
// ----------------------------

// ----------------------------
// OAuth token helpers
// ----------------------------

function jarvis_oauth_get(int $userId, string $provider): ?array {
  $pdo = jarvis_pdo();
  if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT provider, access_token, refresh_token, expires_at, scopes FROM oauth_tokens WHERE user_id=:u AND provider=:p LIMIT 1');
  $stmt->execute([':u'=>$userId, ':p'=>$provider]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function jarvis_oauth_set(int $userId, string $provider, ?string $accessToken, ?string $refreshToken, ?string $expiresAt, ?string $scopes=null): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('INSERT INTO oauth_tokens (user_id,provider,access_token,refresh_token,expires_at,scopes) VALUES (:u,:p,:a,:r,:e,:s) ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token), expires_at=VALUES(expires_at), scopes=VALUES(scopes)')
      ->execute([':u'=>$userId, ':p'=>$provider, ':a'=>$accessToken, ':r'=>$refreshToken, ':e'=>$expiresAt, ':s'=>$scopes]);
}

function jarvis_oauth_delete(int $userId, string $provider): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('DELETE FROM oauth_tokens WHERE user_id=:u AND provider=:p')->execute([':u'=>$userId, ':p'=>$provider]);
}

function jarvis_register_device(int $userId, string $deviceUuid, string $platform, ?string $pushToken=null, ?string $pushProvider=null, ?array $meta=null): int {
  $pdo = jarvis_pdo();
  if (!$pdo) throw new RuntimeException('DB not configured');
  $metaJson = $meta ? json_encode($meta) : null;
  $now = jarvis_now_sql();
  $pdo->prepare('INSERT INTO devices (user_id, device_uuid, platform, push_provider, push_token, metadata_json, last_seen_at) VALUES (:u,:du,:p,:pp,:pt,:m,:ls) ON DUPLICATE KEY UPDATE platform=VALUES(platform), push_provider=VALUES(push_provider), push_token=VALUES(push_token), metadata_json=VALUES(metadata_json), last_seen_at=VALUES(last_seen_at)')
      ->execute([':u'=>$userId, ':du'=>$deviceUuid, ':p'=>$platform, ':pp'=>$pushProvider, ':pt'=>$pushToken, ':m'=>$metaJson, ':ls'=>$now]);
  $stmt = $pdo->prepare('SELECT id FROM devices WHERE user_id=:u AND device_uuid=:du LIMIT 1');
  $stmt->execute([':u'=>$userId, ':du'=>$deviceUuid]);
  $row = $stmt->fetch();
  return (int)($row['id'] ?? 0);
}

function jarvis_unregister_device(int $userId, int $deviceId): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('DELETE FROM devices WHERE id=:id AND user_id=:u')->execute([':id'=>$deviceId, ':u'=>$userId]);
}

function jarvis_update_device_location(int $userId, int $deviceId, float $lat, float $lon, ?float $accuracy=null): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $now = jarvis_now_sql();
  $pdo->prepare('UPDATE devices SET last_location_lat=:lat, last_location_lon=:lon, last_location_at=:ts, last_seen_at=:ts WHERE id=:id AND user_id=:u')
      ->execute([':lat'=>$lat, ':lon'=>$lon, ':ts'=>$now, ':id'=>$deviceId, ':u'=>$userId]);
  // Also record in location_logs for history
  $pdo->prepare('INSERT INTO location_logs (user_id,lat,lon,accuracy_m,source) VALUES (:u,:la,:lo,:a,:s)')
      ->execute([':u'=>$userId, ':la'=>$lat, ':lo'=>$lon, ':a'=>$accuracy, ':s'=>'device']);
}

function jarvis_list_devices(int $userId): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT id,device_uuid,platform,push_provider,push_token,last_location_lat,last_location_lon,last_location_at,last_seen_at,metadata_json,created_at FROM devices WHERE user_id=:u ORDER BY id DESC');
  $stmt->execute([':u'=>$userId]);
  return $stmt->fetchAll() ?: [];
}

function jarvis_log_slack_message(?int $userId, ?string $channelId, string $message, ?array $response=null): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $stmt = $pdo->prepare('INSERT INTO messages (user_id,channel_id,message_text,provider_response_json) VALUES (:u,:c,:m,:r)');
  $stmt->execute([
    ':u' => $userId,
    ':c' => $channelId,
    ':m' => $message,
    ':r' => $response ? json_encode($response) : null,
  ]);
}

function jarvis_fetch_messages(int $userId, int $limit=50): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT id, channel_id, message_text, created_at FROM messages WHERE user_id=:u ORDER BY id DESC LIMIT :l');
  $stmt->bindValue(':u',$userId,PDO::PARAM_INT);
  $stmt->bindValue(':l',$limit,PDO::PARAM_INT);
  $stmt->execute();
  return array_reverse($stmt->fetchAll() ?: []);
}

// ----------------------------
// API request logging
// ----------------------------

function jarvis_log_api_request(?int $userId, string $clientType, string $endpoint, string $method, ?array $req, ?array $resp, int $status): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('INSERT INTO api_requests (user_id,client_type,endpoint,method,request_json,response_json,status_code) VALUES (:u,:c,:e,:m,:rq,:rs,:s)')
      ->execute([
        ':u'=>$userId,
        ':c'=>$clientType,
        ':e'=>$endpoint,
        ':m'=>$method,
        ':rq'=>$req ? json_encode($req) : null,
        ':rs'=>$resp ? json_encode($resp) : null,
        ':s'=>$status,
      ]);
}

// ----------------------------
// Command History
// ----------------------------

function jarvis_log_command(int $userId, string $type, ?string $commandText, string $jarvisResponse, ?array $meta=null): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('INSERT INTO command_history (user_id,type,command_text,jarvis_response,metadata_json) VALUES (:u,:t,:c,:r,:m)')
      ->execute([
        ':u'=>$userId,
        ':t'=>$type,
        ':c'=>$commandText,
        ':r'=>$jarvisResponse,
        ':m'=>$meta ? json_encode($meta) : null,
      ]);
}

function jarvis_recent_commands(int $userId, int $limit=20): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT type,command_text,jarvis_response,created_at FROM command_history WHERE user_id=:u ORDER BY id DESC LIMIT :l');
  $stmt->bindValue(':u',$userId,PDO::PARAM_INT);
  $stmt->bindValue(':l',$limit,PDO::PARAM_INT);
  $stmt->execute();
  return array_reverse($stmt->fetchAll() ?: []);
}
