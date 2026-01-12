<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/instagram_basic.php';

/**
 * Compose a JARVIS briefing payload.
 * Returns: ['text'=>string, 'cards'=>array]
 */
function jarvis_compose_briefing(int $userId, string $mode='briefing'): array {
  require_once __DIR__ . '/helpers.php';
  
  $prefs = jarvis_preferences($userId);
  if (!$prefs) {
    jarvis_pdo()->prepare('INSERT INTO preferences (user_id) VALUES (:id)')->execute([':id'=>$userId]);
    $prefs = jarvis_preferences($userId);
  }
  $u = jarvis_user_by_id($userId);
  $unread = jarvis_unread_notifications_count($userId);

  // Integration status
  $slackToken = jarvis_setting_get('SLACK_BOT_TOKEN') ?: getenv('SLACK_BOT_TOKEN');
  $slackOk = (bool)$slackToken;
  $igToken = jarvis_oauth_get($userId, 'instagram');

  // Instagram update check (Basic Display: media only)
  $ig = jarvis_instagram_check_media_updates($userId);

  // Get current weather from last location
  $weather = null;
  $recentLocs = jarvis_recent_locations($userId, 1);
  if (!empty($recentLocs) && isset($recentLocs[0]['lat'], $recentLocs[0]['lon'])) {
    try {
      $weather = jarvis_fetch_weather((float)$recentLocs[0]['lat'], (float)$recentLocs[0]['lon']);
    } catch (Throwable $e) {
      $weather = null;
    }
  }

  // Slack updates (if connected)
  $slackInfo = ['status'=>'not_connected', 'recent_messages'=>[]];
  if ($slackOk && !empty($prefs['default_slack_channel'])) {
    $slackInfo['status'] = 'connected';
    $slackInfo['channel'] = $prefs['default_slack_channel'];
  }

  $cards = [
    'notifications_unread' => $unread,
    'integrations' => [
      'slack' => $slackOk ? 'connected' : 'not_connected',
      'instagram' => $igToken ? 'connected' : 'not_connected',
    ],
    'instagram' => $ig,
    'slack' => $slackInfo,
    'weather' => $weather,
  ];

  $name = $u['username'] ?? 'operator';
  $lines = [];
  if ($mode === 'wake') {
    $lines[] = "Wake sequence initiated. Welcome back, {$name}.";
  } else {
    $lines[] = "Briefing ready, {$name}.";
  }
  $lines[] = "Unread notifications: {$unread}.";

  // Weather information (if available)
  if ($mode === 'wake' && $weather) {
    $temp = (int)($weather['main']['temp'] ?? 0);
    $desc = ucfirst((string)($weather['weather'][0]['description'] ?? 'unknown'));
    $lines[] = "Weather: {$desc}, {$temp}°F.";
  } elseif ($mode === 'wake') {
    $lines[] = "Weather: location unavailable. Use browser location to refresh.";
  }

  // Instagram updates
  if (!empty($prefs['instagram_watch_username'])) {
    $watch = '@' . ltrim((string)$prefs['instagram_watch_username'], '@');
    if (!empty($ig['ok'])) {
      $lines[] = "Instagram watch {$watch}: New media since last check: " . (int)$ig['new_count'] . ".";
      if (!empty($ig['latest_timestamp'])) {
        $lines[] = "Latest media timestamp: {$ig['latest_timestamp']} UTC.";
      }
      $lines[] = "Stories: not available in Basic Display.";
    } else {
      $lines[] = "Instagram watch {$watch}: {$ig['note']}";
    }
  } else {
    $lines[] = "Instagram watch: not configured.";
  }

  // Slack status for wake briefing
  if ($mode === 'wake') {
    if ($slackOk) {
      $lines[] = "Slack: connected to {$prefs['default_slack_channel']}.";
    } else {
      $lines[] = "Slack: not connected or configured.";
    }
  }

  $text = implode("\n• ", array_merge([$lines[0]], array_slice($lines, 1)));

  return ['text' => $text, 'cards' => $cards];
}

/**
 * Check Instagram media updates using the stored Basic Display token.
 * This is safe to call frequently; it performs a single API request.
 */
function jarvis_instagram_check_media_updates(int $userId): array {
  $prefs = jarvis_preferences($userId);
  $watch = (string)($prefs['instagram_watch_username'] ?? '');
  if ($watch === '') {
    return ['ok'=>false, 'note'=>'watch username not configured'];
  }

  $tok = jarvis_oauth_get($userId, 'instagram');
  if (!$tok || empty($tok['access_token'])) {
    return ['ok'=>false, 'note'=>'instagram not connected'];
  }

  $access = (string)$tok['access_token'];

  // Refresh token if near expiry (best-effort).
  if (!empty($tok['expires_at'])) {
    $expiresAt = strtotime((string)$tok['expires_at'] . ' UTC');
    if ($expiresAt !== false && $expiresAt < time() + 7*24*3600) {
      $ref = jarvis_instagram_refresh_long_lived($access);
      if (!empty($ref['access_token'])) {
        $access = (string)$ref['access_token'];
        $expiresIn = (int)($ref['expires_in'] ?? 5184000);
        $newExpires = gmdate('Y-m-d H:i:s', time() + max(60, $expiresIn));
        jarvis_oauth_set($userId, 'instagram', $access, null, $newExpires, $tok['scopes'] ?? null);
        jarvis_audit($userId, 'OAUTH_REFRESH', 'instagram', ['ok'=>true]);
      }
    }
  }

  // Use a raw call so we can detect OAuth errors and prompt reconnect.
  $url = 'https://graph.instagram.com/me/media?' . http_build_query([
    'fields' => 'id,caption,media_type,media_url,permalink,timestamp,username',
    'limit' => 5,
    'access_token' => $access,
  ]);
  $media = jarvis_http_json($url);
  if (empty($media['data']) || !is_array($media['data'])) {
    jarvis_audit($userId, 'INSTAGRAM_CHECK_FAIL', 'instagram', ['resp'=>$media]);
    // Common case: token revoked/expired.
    $note = 'could not read media';
    if (!empty($media['error']['message'])) {
      $note = 'auth error: ' . (string)$media['error']['message'];
    }
    if (!empty($prefs['notif_inapp'])) {
      jarvis_notify($userId, 'warn', 'Instagram needs attention', 'Reconnect Instagram in Preferences to restore updates.', ['provider'=>'instagram']);
    }
    return ['ok'=>false, 'note'=>$note];
  }
  $latest = $media['data'][0] ?? null;
  if (!$latest || empty($latest['timestamp'])) {
    jarvis_audit($userId, 'INSTAGRAM_CHECK_FAIL', 'instagram', ['resp'=>$media]);
    return ['ok'=>false, 'note'=>'could not read media'];
  }

  $latestTs = (string)$latest['timestamp'];
  $lastCheck = $prefs['last_instagram_check_at'] ?? null;
  $newCount = 0;

  // If we have a last check timestamp and latest is newer, treat as new.
  if ($lastCheck) {
    $last = strtotime((string)$lastCheck . ' UTC');
    $lt = strtotime($latestTs . ' UTC');
    if ($last !== false && $lt !== false && $lt > $last) {
      $newCount = 1;
    }
  } else {
    // First run: don't spam, just set baseline.
    $newCount = 0;
  }

  // Update last check to now.
  jarvis_update_preferences($userId, ['last_instagram_check_at' => jarvis_now_sql()]);

  jarvis_audit($userId, 'INSTAGRAM_CHECK', 'instagram', ['watch'=>$watch, 'new_count'=>$newCount]);
  if ($newCount > 0 && !empty($prefs['notif_inapp'])) {
    jarvis_notify($userId, 'info', 'New Instagram update detected', "New media detected for @" . ltrim($watch,'@'), ['latest'=>$latest]);
  }

  return [
    'ok' => true,
    'watch' => $watch,
    'new_count' => $newCount,
    'latest_timestamp' => $latestTs,
    'latest' => $latest,
    'stories_supported' => false,
  ];
}
