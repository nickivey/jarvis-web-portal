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

  $host = getenv('DB_HOST') ?: '127.0.0.1';
  $name = getenv('DB_NAME') ?: 'nickive2_jarvisp';
  $user = getenv('DB_USER') ?: 'nickive2_jarvisp';
  $pass = getenv('DB_PASS') ?: 'ZDT?^PK}aMO)#}qU';
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

  // Ensure new columns/tables exist even if table already created earlier
  try { $pdo->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS thread_id BIGINT UNSIGNED NULL'); } catch (Throwable $e) {}
  try { $pdo->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS edited_at DATETIME NULL'); } catch (Throwable $e) {}
  try { $pdo->exec('CREATE TABLE IF NOT EXISTS message_reactions (\n    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n    message_id BIGINT UNSIGNED NOT NULL,\n    user_id BIGINT UNSIGNED NOT NULL,\n    type VARCHAR(32) NOT NULL,\n    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n    PRIMARY KEY(id),\n    UNIQUE KEY ux_msg_reaction (message_id, user_id, type),\n    KEY ix_msg_react_message (message_id),\n    KEY ix_msg_react_user (user_id),\n    CONSTRAINT fk_msg_react_message FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE,\n    CONSTRAINT fk_msg_react_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE\n  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'); } catch (Throwable $e) {}
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

/**
 * List users with optional search and pagination (admin use)
 */
function jarvis_list_users(int $limit = 50, int $offset = 0, ?string $q = null): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  if ($q) {
    $like = '%' . str_replace('%','\\%',$q) . '%';
    $stmt = $pdo->prepare('SELECT id,username,email,role,created_at,last_login_at,email_verified_at FROM users WHERE username LIKE :q OR email LIKE :q ORDER BY id DESC LIMIT :l OFFSET :o');
    $stmt->bindValue(':q', $like, PDO::PARAM_STR);
  } else {
    $stmt = $pdo->prepare('SELECT id,username,email,role,created_at,last_login_at,email_verified_at FROM users ORDER BY id DESC LIMIT :l OFFSET :o');
  }
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function jarvis_set_user_role(int $userId, string $role): bool {
  $pdo = jarvis_pdo();
  if (!$pdo) return false;
  $stmt = $pdo->prepare('UPDATE users SET role=:role WHERE id=:id');
  $stmt->execute([':role'=>$role, ':id'=>$userId]);
  return true;
}

function jarvis_delete_user(int $userId): bool {
  $pdo = jarvis_pdo();
  if (!$pdo) return false;
  try {
    $pdo->prepare('DELETE FROM users WHERE id=:id')->execute([':id'=>$userId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
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
  // Best-effort push delivery to registered devices
  try { jarvis_send_push($userId, $type, $title, $body, $meta); } catch (Throwable $e) { /* ignore push failures */ }
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
// Push Notifications (gateway stub)
// ----------------------------

/**
 * Send a push notification via an external gateway (if configured).
 * Env: PUSH_GATEWAY_URL (optional) – receives JSON { user_id, title, body, type, devices }
 */
function jarvis_send_push(int $userId, string $type, string $title, ?string $body, ?array $meta): bool {
  $url = getenv('PUSH_GATEWAY_URL') ?: null;
  $pdo = jarvis_pdo(); if (!$pdo) return false;
  $devices = jarvis_list_devices($userId);
  $payload = [
    'user_id' => $userId,
    'type' => $type,
    'title' => $title,
    'body' => $body,
    'meta' => $meta,
    'devices' => array_map(function($d){ return [
      'uuid' => $d['device_uuid'] ?? null,
      'platform' => $d['platform'] ?? null,
      'push_provider' => $d['push_provider'] ?? null,
      'push_token' => $d['push_token'] ?? null,
    ]; }, $devices),
  ];
  if (!$url) {
    // No gateway configured; audit only
    jarvis_audit($userId, 'PUSH_STUB', 'push', ['title'=>$title,'type'=>$type,'device_count'=>count($devices)]);
    return true;
  }
  $opts = [
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\n",
      'content' => json_encode($payload),
      'timeout' => 3,
    ]
  ];
  try {
    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);
    jarvis_audit($userId, 'PUSH_SENT', 'push', ['title'=>$title,'type'=>$type,'resp'=>$resp ? substr($resp,0,200) : null]);
    return true;
  } catch (Throwable $e) {
    jarvis_audit($userId, 'PUSH_FAILED', 'push', ['title'=>$title,'type'=>$type,'error'=>$e->getMessage()]);
    return false;
  }
}

// ----------------------------
// Rate Limiting
// ----------------------------

/**
 * Simple per-user, per-endpoint rate limit using api_requests table.
 * Returns true if allowed, false if limit exceeded.
 */
function jarvis_rate_limit(int $userId, string $endpoint, int $limitPerMinute): bool {
  $pdo = jarvis_pdo(); if (!$pdo) return true;
  $stmt = $pdo->prepare('SELECT COUNT(*) c FROM api_requests WHERE user_id=:u AND endpoint=:e AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)');
  $stmt->execute([':u'=>$userId, ':e'=>$endpoint]);
  $count = (int)($stmt->fetch()['c'] ?? 0);
  return $count < $limitPerMinute;
}

// ----------------------------
// Devices registration
// ----------------------------
// Deprecated duplicate: use the ON DUPLICATE KEY UPSERT below

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
    'last_calendar_check_at',
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

/**
 * Simple settings table accessor (key-value store). Keys are case-sensitive.
 */
function jarvis_setting_get(string $key): ?string {
  $pdo = jarvis_pdo();
  if (!$pdo) return getenv($key) ?: null;
  try {
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    if ($row) return $row['value'];
  } catch (Throwable $e) {
    // ignore if table doesn't exist yet
  }
  return getenv($key) ?: null;
}

function jarvis_setting_set(string $key, string $value): void {
  $pdo = jarvis_pdo();
  if (!$pdo) throw new RuntimeException('DB not configured');
  $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([':k'=>$key, ':v'=>$value]);
}

function jarvis_setting_list(string $prefix = ''): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  if ($prefix === '') {
    $stmt = $pdo->prepare('SELECT `key`,`value`,`created_at`,`updated_at` FROM settings ORDER BY `key` ASC');
    $stmt->execute();
  } else {
    $stmt = $pdo->prepare('SELECT `key`,`value`,`created_at`,`updated_at` FROM settings WHERE `key` LIKE :p ORDER BY `key` ASC');
    $stmt->execute([':p'=>$prefix.'%']);
  }
  return $stmt->fetchAll() ?: [];
}

function jarvis_setting_delete(string $key): void {
  $pdo = jarvis_pdo();
  if (!$pdo) throw new RuntimeException('DB not configured');
  $pdo->prepare('DELETE FROM settings WHERE `key` = :k')->execute([':k'=>$key]);
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

// Device upload tokens (for iOS Shortcuts / external upload clients)
function jarvis_create_device_upload_token(int $userId, ?string $label = null, ?int $ttlSeconds = 604800): array {
  $pdo = jarvis_pdo(); if (!$pdo) throw new RuntimeException('DB not configured');
  $token = bin2hex(random_bytes(32));
  $expiresAt = $ttlSeconds ? date('Y-m-d H:i:s', time() + (int)$ttlSeconds) : null;
  $stmt = $pdo->prepare('INSERT INTO device_upload_tokens (user_id, token, label, expires_at) VALUES (:u,:t,:l,:e)');
  $stmt->execute([':u'=>$userId, ':t'=>$token, ':l'=>$label, ':e'=>$expiresAt]);
  $id = (int)$pdo->lastInsertId();
  return ['id'=>$id, 'user_id'=>$userId, 'token'=>$token, 'label'=>$label, 'expires_at'=>$expiresAt];
}

function jarvis_get_user_for_upload_token(string $token): ?array {
  $pdo = jarvis_pdo(); if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT t.id, t.user_id, t.expires_at, t.revoked, u.* FROM device_upload_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=:t LIMIT 1');
  $stmt->execute([':t'=>$token]);
  $row = $stmt->fetch();
  if (!$row) return null;
  if (!empty($row['revoked'])) return null;
  if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return null;
  return $row;
}

function jarvis_list_device_upload_tokens(int $userId): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT id,token,label,expires_at,revoked,created_at FROM device_upload_tokens WHERE user_id=:u ORDER BY id DESC');
  $stmt->execute([':u'=>$userId]);
  return $stmt->fetchAll() ?: [];
}

function jarvis_revoke_device_upload_token(int $id, int $userId): bool {
  $pdo = jarvis_pdo(); if (!$pdo) return false;
  $stmt = $pdo->prepare('UPDATE device_upload_tokens SET revoked=1 WHERE id=:id AND user_id=:u');
  $stmt->execute([':id'=>$id, ':u'=>$userId]);
  return ($stmt->rowCount() > 0);
}

// ----------------------------
// Jobs queue helpers
// ----------------------------
function jarvis_enqueue_job(string $type, array $payload = [], ?string $availableAt = null): int {
  $pdo = jarvis_pdo(); if (!$pdo) throw new RuntimeException('DB not configured');
  $availableAt = $availableAt ?: null;
  $stmt = $pdo->prepare('INSERT INTO jobs (type, payload_json, available_at) VALUES (:t, :p, COALESCE(:a, NOW()))');
  $stmt->execute([':t'=>$type, ':p'=>json_encode($payload), ':a'=>$availableAt]);
  return (int)$pdo->lastInsertId();
}

function jarvis_fetch_next_job(?array $types = null): ?array {
  $pdo = jarvis_pdo(); if (!$pdo) return null;
  if ($types && count($types) > 0) {
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE status='pending' AND available_at <= NOW() AND type IN ({$placeholders}) ORDER BY id ASC LIMIT 1");
    $stmt->execute($types);
  } else {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE status='pending' AND available_at <= NOW() ORDER BY id ASC LIMIT 1");
    $stmt->execute();
  }
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function jarvis_mark_job_started(int $jobId): bool {
  $pdo = jarvis_pdo(); if (!$pdo) return false;
  $stmt = $pdo->prepare('UPDATE jobs SET status = :s, attempts = attempts + 1 WHERE id = :id AND status = :pending');
  $stmt->execute([':s'=>'running', ':id'=>$jobId, ':pending'=>'pending']);
  return ($stmt->rowCount() > 0);
}

function jarvis_mark_job_done(int $jobId): bool {
  $pdo = jarvis_pdo(); if (!$pdo) return false;
  $stmt = $pdo->prepare('UPDATE jobs SET status = :s WHERE id = :id');
  $stmt->execute([':s'=>'done', ':id'=>$jobId]);
  return ($stmt->rowCount() > 0);
}

function jarvis_mark_job_failed(int $jobId, int $retryAfterSeconds = 60): bool {
  $pdo = jarvis_pdo(); if (!$pdo) return false;
  $stmt = $pdo->prepare('UPDATE jobs SET status = :s, available_at = DATE_ADD(NOW(), INTERVAL :sec SECOND) WHERE id = :id');
  $stmt->execute([':s'=>'pending','sec'=>$retryAfterSeconds, ':id'=>$jobId]);
  return ($stmt->rowCount() > 0);
}

/**
 * Process a single pending job in-process. Returns true on processed, false if none or failed.
 */
function jarvis_process_one_job_inprocess(): bool {
  $job = jarvis_fetch_next_job();
  if (!$job) return false;
  $jobId = (int)$job['id'];
  if (!jarvis_mark_job_started($jobId)) return false;
  $payload = json_decode($job['payload_json'] ?? '{}', true) ?: [];
  try {
    if ($job['type'] === 'photo_reprocess') {
      $photoId = isset($payload['photo_id']) ? (int)$payload['photo_id'] : 0;
      if (!$photoId) throw new RuntimeException('photo_id missing');
      $res = jarvis_reprocess_photo($photoId);
      if (empty($res['ok'])) throw new RuntimeException('reprocess failed: ' . json_encode($res));
      jarvis_mark_job_done($jobId);
    } else {
      throw new RuntimeException('unknown job type');
    }
    return true;
  } catch (Throwable $e) {
    jarvis_mark_job_failed($jobId, 60);
    return false;
  }
}

// Save a voice input record (audio file should already be persisted on disk)
function jarvis_save_voice_input(int $userId, string $filename, ?string $transcript=null, ?int $durationMs=null, ?array $meta=null): int {
  $pdo = jarvis_pdo();
  if (!$pdo) throw new RuntimeException('DB not configured');
  $metaJson = $meta ? json_encode($meta) : null;
  $stmt = $pdo->prepare('INSERT INTO voice_inputs (user_id, filename, transcript, duration_ms, metadata_json, created_at) VALUES (:u,:f,:t,:d,:m,NOW())');
  $stmt->execute([':u'=>$userId, ':f'=>$filename, ':t'=>$transcript, ':d'=>$durationMs, ':m'=>$metaJson]);
  return (int)$pdo->lastInsertId();
}

function jarvis_recent_voice_inputs(int $userId, int $limit=20): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $limit = max(1, min(200, (int)$limit));
  $stmt = $pdo->prepare('SELECT id, filename, transcript, duration_ms, metadata_json, created_at FROM voice_inputs WHERE user_id=:u ORDER BY id DESC LIMIT :l');
  $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

// pnut log: store payloads for offline / deep analysis
function jarvis_pnut_log(?int $userId, string $source, array $payload): int {
  $pdo = jarvis_pdo(); if (!$pdo) throw new RuntimeException('DB not configured');
  $stmt = $pdo->prepare('INSERT INTO pnut_logs (user_id, source, payload_json, created_at) VALUES (:u,:s,:p,NOW())');
  $stmt->execute([':u'=>$userId, ':s'=>$source, ':p'=>json_encode($payload)]);
  return (int)$pdo->lastInsertId();
}

function jarvis_voice_input_by_id(int $id): ?array {
  $pdo = jarvis_pdo(); if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT id,user_id,filename,transcript,duration_ms,metadata_json,created_at FROM voice_inputs WHERE id=:id LIMIT 1');
  $stmt->execute([':id'=>$id]);
  $row = $stmt->fetch();
  return $row ?: null;
}

// Save a video input record (video file should already be persisted on disk)
function jarvis_save_video_input(int $userId, string $filename, ?string $thumbFilename=null, ?string $transcript=null, ?int $durationMs=null, ?array $meta=null): int {
  $pdo = jarvis_pdo();
  if (!$pdo) throw new RuntimeException('DB not configured');
  $metaJson = $meta ? json_encode($meta) : null;
  $stmt = $pdo->prepare('INSERT INTO video_inputs (user_id, filename, thumb_filename, transcript, duration_ms, metadata_json, created_at) VALUES (:u,:f,:th,:t,:d,:m,NOW())');
  $stmt->execute([':u'=>$userId, ':f'=>$filename, ':th'=>$thumbFilename, ':t'=>$transcript, ':d'=>$durationMs, ':m'=>$metaJson]);
  return (int)$pdo->lastInsertId();
}

function jarvis_video_input_by_id(int $id): ?array {
  $pdo = jarvis_pdo(); if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT id,user_id,filename,thumb_filename,transcript,duration_ms,metadata_json,created_at FROM video_inputs WHERE id=:id LIMIT 1');
  $stmt->execute([':id'=>$id]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function jarvis_recent_video_inputs(int $userId, int $limit=20): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $limit = max(1, min(200, (int)$limit));
  $stmt = $pdo->prepare('SELECT id, filename, thumb_filename, transcript, duration_ms, metadata_json, created_at FROM video_inputs WHERE user_id=:u ORDER BY id DESC LIMIT :l');
  $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function jarvis_list_all_video_inputs(int $limit=50): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT v.*, u.username, u.email FROM video_inputs v LEFT JOIN users u ON v.user_id = u.id ORDER BY v.created_at DESC LIMIT :l');
  $stmt->bindValue(':l', (int)$limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function jarvis_list_all_voice_inputs(int $limit=50): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT v.*, u.username, u.email FROM voice_inputs v LEFT JOIN users u ON v.user_id = u.id ORDER BY v.created_at DESC LIMIT :l');
  $stmt->bindValue(':l', (int)$limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function jarvis_unregister_device(int $userId, int $deviceId): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->prepare('DELETE FROM devices WHERE id=:id AND user_id=:u')->execute([':id'=>$deviceId, ':u'=>$userId]);
}

function jarvis_update_device_location(int $userId, int $deviceId, float $lat, float $lon, ?float $accuracy=null): int {
  $pdo = jarvis_pdo();
  if (!$pdo) return 0;
  $now = jarvis_now_sql();
  $pdo->prepare('UPDATE devices SET last_location_lat=:lat, last_location_lon=:lon, last_location_at=:ts, last_seen_at=:ts WHERE id=:id AND user_id=:u')
      ->execute([':lat'=>$lat, ':lon'=>$lon, ':ts'=>$now, ':id'=>$deviceId, ':u'=>$userId]);
  // Also record in location_logs for history
  $pdo->prepare('INSERT INTO location_logs (user_id,lat,lon,accuracy_m,source) VALUES (:u,:la,:lo,:a,:s)')
      ->execute([':u'=>$userId, ':la'=>$lat, ':lo'=>$lon, ':a'=>$accuracy, ':s'=>'device']);
  return (int)$pdo->lastInsertId();
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

/**
 * Store a local channel message (provider = 'local') and optional metadata (tags etc)
 */
function jarvis_log_local_message(?int $userId, string $channelId, string $message, ?array $meta=null): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $stmt = $pdo->prepare('INSERT INTO messages (user_id,channel_id,message_text,provider,provider_response_json) VALUES (:u,:c,:m,:p,:r)');
  $stmt->execute([
    ':u' => $userId,
    ':c' => $channelId,
    ':m' => $message,
    ':p' => 'local',
    ':r' => $meta ? json_encode($meta) : null,
  ]);
}

/**
 * Reply to a local message thread (sets thread_id)
 */
function jarvis_log_local_reply(?int $userId, string $channelId, int $parentId, string $message, ?array $meta=null): int {
  $pdo = jarvis_pdo(); if (!$pdo) return 0;
  $stmt = $pdo->prepare('INSERT INTO messages (user_id,channel_id,message_text,provider,provider_response_json,thread_id) VALUES (:u,:c,:m,:p,:r,:t)');
  $stmt->execute([
    ':u'=>$userId,
    ':c'=>$channelId,
    ':m'=>$message,
    ':p'=>'local',
    ':r'=>$meta ? json_encode($meta) : null,
    ':t'=>$parentId,
  ]);
  return (int)$pdo->lastInsertId();
}

/**
 * Edit a local message (owner or admin validation is done at API layer). Updates message_text and edited_at.
 */
function jarvis_edit_local_message(int $messageId, string $newText, ?array $meta=null): bool {
  $pdo = jarvis_pdo(); if (!$pdo) return false;
  $stmt = $pdo->prepare('UPDATE messages SET message_text=:m, provider_response_json=:r, edited_at=NOW() WHERE id=:id AND provider=:p');
  $stmt->execute([':m'=>$newText, ':r'=>$meta ? json_encode($meta) : null, ':id'=>$messageId, ':p'=>'local']);
  return ($stmt->rowCount() > 0);
}

/**
 * List a thread's messages (replies) sorted ascending.
 */
function jarvis_list_thread_messages(int $parentId, int $limit=100, int $offset=0): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT m.id,m.user_id,m.channel_id,m.message_text,m.provider,m.provider_response_json,m.created_at,u.username FROM messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.thread_id=:t AND m.provider=:p ORDER BY m.id ASC LIMIT :l OFFSET :o');
  $stmt->bindValue(':t',$parentId,PDO::PARAM_INT);
  $stmt->bindValue(':p','local',PDO::PARAM_STR);
  $stmt->bindValue(':l',$limit,PDO::PARAM_INT);
  $stmt->bindValue(':o',$offset,PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$r) { if (!empty($r['provider_response_json'])) $r['meta'] = json_decode($r['provider_response_json'], true) ?: null; }
  return $rows;
}

// Reactions helpers
function jarvis_add_message_reaction(int $userId, int $messageId, string $type): bool {
  $pdo = jarvis_pdo(); if (!$pdo) return false;
  $type = strtolower(preg_replace('/[^a-z0-9_\-]/i','', $type)); if ($type==='') return false;
  try {
    $stmt = $pdo->prepare('INSERT IGNORE INTO message_reactions (message_id,user_id,type) VALUES (:m,:u,:t)');
    $stmt->execute([':m'=>$messageId, ':u'=>$userId, ':t'=>$type]);
    return true;
  } catch (Throwable $e) { return false; }
}

function jarvis_remove_message_reaction(int $userId, int $messageId, string $type): bool {
  $pdo = jarvis_pdo(); if (!$pdo) return false;
  $stmt = $pdo->prepare('DELETE FROM message_reactions WHERE message_id=:m AND user_id=:u AND type=:t');
  $stmt->execute([':m'=>$messageId, ':u'=>$userId, ':t'=>$type]);
  return ($stmt->rowCount() > 0);
}

function jarvis_list_message_reactions(int $messageId): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT type, COUNT(*) AS count FROM message_reactions WHERE message_id=:m GROUP BY type ORDER BY type');
  $stmt->execute([':m'=>$messageId]);
  $agg = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $stmt2 = $pdo->prepare('SELECT mr.type, u.username FROM message_reactions mr LEFT JOIN users u ON u.id=mr.user_id WHERE mr.message_id=:m ORDER BY mr.type, u.username');
  $stmt2->execute([':m'=>$messageId]);
  $users = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
  return ['summary'=>$agg,'users'=>$users];
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

/**
 * List messages for a channel (local provider). Optional tag filter (simple LIKE match on metadata_json or message_text).
 */
function jarvis_list_channel_messages(string $channelId, int $limit = 50, int $offset = 0, ?string $tag = null): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $sql = 'SELECT m.id,m.user_id,m.channel_id,m.message_text,m.provider,m.provider_response_json,m.created_at,u.username FROM messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.channel_id = :c AND m.provider = :p';
  $params = [':c'=>$channelId, ':p'=>'local'];
  if ($tag !== null && $tag !== '') {
    $sql .= ' AND (m.provider_response_json LIKE :tag OR m.message_text LIKE :htag)';
    $params[':tag'] = '%"' . str_replace('"','', $tag) . '"%';
    $params[':htag'] = '%' . str_replace('%','\%',$tag) . '%';
  }
  $sql .= ' ORDER BY m.id DESC LIMIT :l OFFSET :o';
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) { $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$r) {
    if (!empty($r['provider_response_json'])) $r['meta'] = json_decode($r['provider_response_json'], true) ?: null;
  }
  return $rows;
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
  $stmt = $pdo->prepare('INSERT INTO command_history (user_id,type,command_text,jarvis_response,metadata_json) VALUES (:u,:t,:c,:r,:m)');
  $stmt->execute([
    ':u'=>$userId,
    ':t'=>$type,
    ':c'=>$commandText,
    ':r'=>$jarvisResponse,
    ':m'=>$meta ? json_encode($meta) : null,
  ]);
  $cmdId = (int)$pdo->lastInsertId();

  // If this command is associated with a voice input, create an audit log entry linking the command and the audio file
  if ($meta && isset($meta['voice_input_id'])) {
    $vid = (int)$meta['voice_input_id'];
    try {
      $v = jarvis_voice_input_by_id($vid);
      $auditMeta = $meta;
      $auditMeta['voice_input'] = $v ? $v : ['id'=>$vid];
      $auditMeta['command_id'] = $cmdId;
      jarvis_audit($userId, 'COMMAND_VOICE_PROCESSED', 'voice', $auditMeta);
    } catch (Throwable $e) {
      // ignore auditing failures
    }
  }
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

/**
 * List command history with optional filtering (admin may request other users)
 * Returns an array of rows with id,user_id,type,command_text,jarvis_response,metadata_json,created_at
 */
function jarvis_list_commands(int $limit = 50, int $offset = 0, ?int $userId = null, ?string $q = null): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $sql = 'SELECT id,user_id,type,command_text,jarvis_response,metadata_json,created_at FROM command_history WHERE 1=1';
  $params = [];
  if ($userId !== null) { $sql .= ' AND user_id = :user'; $params[':user'] = $userId; }
  if ($q !== null && $q !== '') { $sql .= ' AND (command_text LIKE :q OR jarvis_response LIKE :q)'; $params[':q'] = '%' . str_replace('%','\\%',$q) . '%'; }
  $sql .= ' ORDER BY id DESC LIMIT :l OFFSET :o';
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) { $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function jarvis_recent_locations(int $userId, int $limit=20): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT id,lat,lon,accuracy_m,source,created_at FROM location_logs WHERE user_id=:u ORDER BY id DESC LIMIT :l');
  $stmt->bindValue(':u',$userId,PDO::PARAM_INT);
  $stmt->bindValue(':l',$limit,PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll() ?: [];

  // Enrich with reverse-geocoded address where possible (uses cache)
  foreach ($rows as &$r) {
    try {
      $addr = jarvis_reverse_geocode((float)$r['lat'], (float)$r['lon']);
      if ($addr) $r['address'] = $addr;
    } catch (Throwable $e) {
      // non-fatal; leave entry without address
    }
  }
  return $rows;
}

/**
 * Reverse geocode lat/lon using Nominatim (OpenStreetMap) with a simple cache.
 * Returns associative array with keys like 'display_name','city','state','country','postcode' or null on failure.
 */
function jarvis_reverse_geocode(float $lat, float $lon): ?array {
  $pdo = jarvis_pdo();
  if (!$pdo) return null;
  // Round coords to 3 decimal places (~100m) for cache key
  $latr = round($lat, 3);
  $lonr = round($lon, 3);

  $stmt = $pdo->prepare('SELECT address_json FROM location_geocache WHERE lat_round=:la AND lon_round=:lo LIMIT 1');
  $stmt->execute([':la'=>$latr, ':lo'=>$lonr]);
  $row = $stmt->fetch();
  if ($row && !empty($row['address_json'])) {
    return json_decode($row['address_json'], true) ?: null;
  }

  // Query Nominatim reverse endpoint (respectful usage headers)
  $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' . urlencode((string)$lat) . '&lon=' . urlencode((string)$lon) . '&addressdetails=1';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_USERAGENT => 'Jarvis/1.0 (+https://example.org)'
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode($resp ?: '', true);
  if (!is_array($data) || $code !== 200) return null;

  $addr = [];
  $addr['display_name'] = (string)($data['display_name'] ?? '');
  $ad = (array)($data['address'] ?? []);
  $addr['city'] = (string)($ad['city'] ?? $ad['town'] ?? $ad['village'] ?? $ad['hamlet'] ?? '');
  $addr['state'] = (string)($ad['state'] ?? $ad['region'] ?? '');
  $addr['country'] = (string)($ad['country'] ?? '');
  $addr['postcode'] = (string)($ad['postcode'] ?? '');

  // Store in cache (best-effort)
  try {
    $q = $pdo->prepare('INSERT IGNORE INTO location_geocache (lat_round, lon_round, address_json) VALUES (:la, :lo, :aj)');
    $q->execute([':la'=>$latr, ':lo'=>$lonr, ':aj'=>json_encode($addr)]);
  } catch (Throwable $e) {
    // ignore caching errors
  }

  return $addr;
}

function jarvis_get_location_by_id(int $id): ?array {
  $pdo = jarvis_pdo();
  if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT id,user_id,lat,lon,accuracy_m,source,created_at FROM location_logs WHERE id=:id LIMIT 1');
  $stmt->execute([':id'=>$id]);
  $row = $stmt->fetch();
  if ($row) {
    try {
      $addr = jarvis_reverse_geocode((float)$row['lat'], (float)$row['lon']);
      if ($addr) $row['address'] = $addr;
    } catch (Throwable $e) { /* ignore */ }
  }
  return $row ? $row : null;
}

// ----------------------------
// Photos
// ----------------------------

function jarvis_store_photo(int $userId, string $filename, ?string $originalFilename = null, ?array $meta = null): int {
  $pdo = jarvis_pdo(); if (!$pdo) return 0;
  $stmt = $pdo->prepare('INSERT INTO photos (user_id,filename,original_filename,thumb_filename,metadata_json) VALUES (:u,:f,:of,:tf,:m)');
  $stmt->execute([':u'=>$userId, ':f'=>$filename, ':of'=>$originalFilename, ':tf'=>null, ':m'=>$meta ? json_encode($meta) : null]);
  return (int)$pdo->lastInsertId();
}

function jarvis_list_photos(int $userId, int $limit = 50, int $offset = 0): array {
  $pdo = jarvis_pdo(); if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT id,user_id,filename,original_filename,thumb_filename,metadata_json,created_at FROM photos WHERE user_id = :u ORDER BY id DESC LIMIT :l OFFSET :o');
  $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$r) {
    if (!empty($r['metadata_json'])) $r['metadata'] = json_decode($r['metadata_json'], true) ?: null;
  }
  return $rows;
}

function jarvis_get_photo_by_id(int $id): ?array {
  $pdo = jarvis_pdo(); if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT id,user_id,filename,original_filename,thumb_filename,metadata_json,created_at FROM photos WHERE id = :id LIMIT 1');
  $stmt->execute([':id'=>$id]);
  $row = $stmt->fetch();
  if ($row && !empty($row['metadata_json'])) $row['metadata'] = json_decode($row['metadata_json'], true) ?: null;
  return $row ? $row : null;
}

// Calendar events storage
function jarvis_store_calendar_event(int $userId, string $eventId, ?string $summary, ?string $description, ?string $startDt, ?string $endDt, ?string $location, ?array $raw): int {
  $pdo = jarvis_pdo();
  if (!$pdo) return 0;
  $stmt = $pdo->prepare('INSERT INTO user_calendar_events (user_id,event_id,summary,description,start_dt,end_dt,location,raw_json) VALUES (:u,:eid,:s,:d,:sd,:ed,:loc,:raw) ON DUPLICATE KEY UPDATE summary=VALUES(summary), description=VALUES(description), start_dt=VALUES(start_dt), end_dt=VALUES(end_dt), location=VALUES(location), raw_json=VALUES(raw_json)');
  $stmt->execute([':u'=>$userId, ':eid'=>$eventId, ':s'=>$summary, ':d'=>$description, ':sd'=>$startDt, ':ed'=>$endDt, ':loc'=>$location, ':raw'=>$raw ? json_encode($raw) : null]);
  // return the inserted/updated row id (best effort)
  $id = (int)$pdo->lastInsertId();
  if ($id === 0) {
    // fetch existing id
    $q = $pdo->prepare('SELECT id FROM user_calendar_events WHERE user_id=:u AND event_id=:eid LIMIT 1');
    $q->execute([':u'=>$userId, ':eid'=>$eventId]);
    $row = $q->fetch();
    $id = (int)($row['id'] ?? 0);
  }
  return $id;
}

function jarvis_list_calendar_events(int $userId, int $limit=20): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  // Show upcoming events (future only), sorted soonest to latest
  $stmt = $pdo->prepare('SELECT id,event_id,summary,description,start_dt,end_dt,location,raw_json,created_at FROM user_calendar_events WHERE user_id=:u AND start_dt >= NOW() ORDER BY start_dt ASC LIMIT :l');
  $stmt->bindValue(':u',$userId,PDO::PARAM_INT);
  $stmt->bindValue(':l',$limit,PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

// Password reset helpers
function jarvis_initiate_password_reset(string $email): ?string {
  $pdo = jarvis_pdo();
  if (!$pdo) return null;
  $stmt = $pdo->prepare('SELECT id FROM users WHERE email=:e LIMIT 1');
  $stmt->execute([':e'=>$email]);
  $row = $stmt->fetch();
  if (!$row) return null;
  $userId = (int)$row['id'];
  $token = bin2hex(random_bytes(24));
  $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
  $pdo->prepare('UPDATE users SET password_reset_token=:t, password_reset_expires_at=:e WHERE id=:id')
      ->execute([':t'=>$token, ':e'=>$expiresAt, ':id'=>$userId]);
  return $token;
}

function jarvis_reset_password_with_token(string $token, string $newPasswordHash): bool {
  $pdo = jarvis_pdo();
  if (!$pdo) return false;
  $stmt = $pdo->prepare('SELECT id FROM users WHERE password_reset_token=:t AND password_reset_expires_at > NOW() LIMIT 1');
  $stmt->execute([':t'=>$token]);
  $row = $stmt->fetch();
  if (!$row) return false;
  $userId = (int)$row['id'];
  $pdo->prepare('UPDATE users SET password_hash=:p, password_reset_token=NULL, password_reset_expires_at=NULL WHERE id=:id')
      ->execute([':p'=>$newPasswordHash, ':id'=>$userId]);
  jarvis_audit($userId, 'PASSWORD_RESET', 'auth', null);
  return true;
}

function jarvis_resend_email_verification(int $userId): bool {
  $pdo = jarvis_pdo();
  if (!$pdo) return false;
  $user = jarvis_user_by_id($userId);
  if (!$user) return false;
  $token = bin2hex(random_bytes(24));
  $pdo->prepare('UPDATE users SET email_verify_token=:t WHERE id=:id')->execute([':t'=>$token, ':id'=>$userId]);
  jarvis_send_confirmation_email($user['email'], $user['username'], $token);
  jarvis_audit($userId, 'EMAIL_VERIFICATION_RESENT', 'auth', null);
  return true;
}

// ========== Local Calendar Events (user-created events, not from Google) ==========

/**
 * Ensure the local events table exists
 */
function jarvis_ensure_local_events_table(): void {
  $pdo = jarvis_pdo();
  if (!$pdo) return;
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_local_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(255) NOT NULL,
      event_date DATE NOT NULL,
      event_time TIME NULL,
      location VARCHAR(255) NULL,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY ix_local_events_user(user_id),
      KEY ix_local_events_date(event_date),
      CONSTRAINT fk_local_events_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

/**
 * List local events for a user
 */
function jarvis_list_local_events(int $userId, int $limit = 20): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  // Ensure table exists
  jarvis_ensure_local_events_table();
  $stmt = $pdo->prepare('SELECT id, title, event_date, event_time, location, notes, created_at FROM user_local_events WHERE user_id = :u AND event_date >= CURDATE() ORDER BY event_date ASC, event_time ASC LIMIT :l');
  $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

/**
 * Add a local event
 */
function jarvis_add_local_event(int $userId, string $title, string $eventDate, ?string $eventTime = null, ?string $location = null, ?string $notes = null): int {
  $pdo = jarvis_pdo();
  if (!$pdo) return 0;
  jarvis_ensure_local_events_table();
  $stmt = $pdo->prepare('INSERT INTO user_local_events (user_id, title, event_date, event_time, location, notes) VALUES (:u, :t, :d, :tm, :loc, :n)');
  $stmt->execute([
    ':u' => $userId,
    ':t' => $title,
    ':d' => $eventDate,
    ':tm' => $eventTime,
    ':loc' => $location,
    ':n' => $notes
  ]);
  $id = (int)$pdo->lastInsertId();
  jarvis_audit($userId, 'LOCAL_EVENT_CREATED', 'calendar', ['event_id' => $id, 'title' => $title]);
  return $id;
}

/**
 * Delete a local event
 */
function jarvis_delete_local_event(int $userId, int $eventId): bool {
  $pdo = jarvis_pdo();
  if (!$pdo) return false;
  $stmt = $pdo->prepare('DELETE FROM user_local_events WHERE id = :id AND user_id = :u');
  $stmt->execute([':id' => $eventId, ':u' => $userId]);
  if ($stmt->rowCount() > 0) {
    jarvis_audit($userId, 'LOCAL_EVENT_DELETED', 'calendar', ['event_id' => $eventId]);
    return true;
  }
  return false;
}

// ========== Event Reminders & Notifications ==========

/**
 * Get upcoming events for today and tomorrow for reminders
 */
function jarvis_get_upcoming_events_for_reminders(int $userId): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  jarvis_ensure_local_events_table();
  
  $today = date('Y-m-d');
  $tomorrow = date('Y-m-d', strtotime('+1 day'));
  
  // Get local events for today and tomorrow
  $stmt = $pdo->prepare('
    SELECT id, title, event_date, event_time, location, notes, "local" as source 
    FROM user_local_events 
    WHERE user_id = :u AND event_date IN (:today, :tomorrow)
    ORDER BY event_date ASC, event_time ASC
  ');
  $stmt->execute([':u' => $userId, ':today' => $today, ':tomorrow' => $tomorrow]);
  $localEvents = $stmt->fetchAll() ?: [];
  
  // Get Google Calendar events for today and tomorrow
  $stmt2 = $pdo->prepare('
    SELECT id, summary as title, start_dt as event_datetime, location, "google" as source
    FROM user_calendar_events
    WHERE user_id = :u AND DATE(start_dt) IN (:today, :tomorrow)
    ORDER BY start_dt ASC
  ');
  $stmt2->execute([':u' => $userId, ':today' => $today, ':tomorrow' => $tomorrow]);
  $googleEvents = $stmt2->fetchAll() ?: [];
  
  // Combine and format
  $events = [];
  foreach ($localEvents as $e) {
    $events[] = [
      'id' => $e['id'],
      'title' => $e['title'],
      'date' => $e['event_date'],
      'time' => $e['event_time'],
      'location' => $e['location'],
      'source' => 'local',
      'is_today' => ($e['event_date'] === $today),
      'is_tomorrow' => ($e['event_date'] === $tomorrow),
    ];
  }
  foreach ($googleEvents as $e) {
    $dt = $e['event_datetime'] ?? null;
    $events[] = [
      'id' => $e['id'],
      'title' => $e['title'],
      'date' => $dt ? date('Y-m-d', strtotime($dt)) : null,
      'time' => $dt ? date('H:i:s', strtotime($dt)) : null,
      'location' => $e['location'],
      'source' => 'google',
      'is_today' => $dt && date('Y-m-d', strtotime($dt)) === $today,
      'is_tomorrow' => $dt && date('Y-m-d', strtotime($dt)) === $tomorrow,
    ];
  }
  
  // Sort by date/time
  usort($events, function($a, $b) {
    $aKey = ($a['date'] ?? '') . ' ' . ($a['time'] ?? '00:00:00');
    $bKey = ($b['date'] ?? '') . ' ' . ($b['time'] ?? '00:00:00');
    return strcmp($aKey, $bKey);
  });
  
  return $events;
}

/**
 * Create event reminder notifications for upcoming events
 * Should be called on login or page load
 */
function jarvis_create_event_reminders(int $userId): int {
  $pdo = jarvis_pdo();
  if (!$pdo) return 0;
  
  $events = jarvis_get_upcoming_events_for_reminders($userId);
  $created = 0;
  
  foreach ($events as $event) {
    // Check if we already sent a reminder today for this event
    $checkKey = 'event_reminder_' . $event['source'] . '_' . $event['id'] . '_' . date('Y-m-d');
    $stmt = $pdo->prepare('SELECT id FROM notifications WHERE user_id = :u AND title LIKE :key AND DATE(created_at) = CURDATE() LIMIT 1');
    $stmt->execute([':u' => $userId, ':key' => '%' . $event['title'] . '%']);
    if ($stmt->fetch()) continue; // Already sent
    
    $timeStr = $event['time'] ? date('g:i A', strtotime($event['time'])) : 'All day';
    $locationStr = $event['location'] ? ' at ' . $event['location'] : '';
    
    if ($event['is_today']) {
      $title = '📅 Today: ' . $event['title'];
      $body = 'You have an event today at ' . $timeStr . $locationStr;
      $type = 'event';
      jarvis_notify($userId, $type, $title, $body, [
        'event_id' => $event['id'],
        'event_source' => $event['source'],
        'event_date' => $event['date'],
        'event_time' => $event['time'],
      ]);
      jarvis_audit($userId, 'EVENT_REMINDER_SENT', 'calendar', [
        'event_id' => $event['id'],
        'event_title' => $event['title'],
        'reminder_type' => 'today',
      ]);
      $created++;
    } elseif ($event['is_tomorrow']) {
      $title = '🔔 Tomorrow: ' . $event['title'];
      $body = 'Upcoming event tomorrow at ' . $timeStr . $locationStr;
      $type = 'reminder';
      jarvis_notify($userId, $type, $title, $body, [
        'event_id' => $event['id'],
        'event_source' => $event['source'],
        'event_date' => $event['date'],
        'event_time' => $event['time'],
      ]);
      jarvis_audit($userId, 'EVENT_REMINDER_SENT', 'calendar', [
        'event_id' => $event['id'],
        'event_title' => $event['title'],
        'reminder_type' => 'tomorrow',
      ]);
      $created++;
    }
  }
  
  return $created;
}

/**
 * Get notifications with enhanced data including event info
 */
function jarvis_recent_notifications_enhanced(int $userId, int $limit = 20): array {
  $pdo = jarvis_pdo();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('
    SELECT id, type, title, body, metadata_json, is_read, created_at, read_at 
    FROM notifications 
    WHERE user_id = :u 
    ORDER BY id DESC 
    LIMIT :l
  ');
  $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll() ?: [];
  
  // Parse metadata and add computed fields
  foreach ($rows as &$row) {
    $row['metadata'] = $row['metadata_json'] ? json_decode($row['metadata_json'], true) : [];
    $row['time_ago'] = jarvis_time_ago($row['created_at']);
    $row['icon'] = jarvis_notification_icon($row['type']);
  }
  
  return $rows;
}

/**
 * Get notification icon based on type
 */
function jarvis_notification_icon(string $type): string {
  $icons = [
    'info' => 'ℹ️',
    'success' => '✅',
    'warning' => '⚠️',
    'error' => '❌',
    'event' => '📅',
    'reminder' => '🔔',
    'alert' => '🚨',
    'message' => '💬',
    'sync' => '🔄',
    'system' => '⚙️',
  ];
  return $icons[$type] ?? '📌';
}

/**
 * Human-readable time ago
 */
function jarvis_time_ago(string $datetime): string {
  $time = strtotime($datetime);
  $diff = time() - $time;
  
  if ($diff < 60) return 'just now';
  if ($diff < 3600) return floor($diff / 60) . 'm ago';
  if ($diff < 86400) return floor($diff / 3600) . 'h ago';
  if ($diff < 604800) return floor($diff / 86400) . 'd ago';
  return date('M j', $time);
}

/**
 * Mark all notifications as read
 */
function jarvis_mark_all_notifications_read(int $userId): int {
  $pdo = jarvis_pdo();
  if (!$pdo) return 0;
  $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = :t WHERE user_id = :u AND is_read = 0');
  $stmt->execute([':t' => jarvis_now_sql(), ':u' => $userId]);
  $count = $stmt->rowCount();
  if ($count > 0) {
    jarvis_audit($userId, 'NOTIFICATIONS_MARKED_READ', 'notifications', ['count' => $count]);
  }
  return $count;
}
