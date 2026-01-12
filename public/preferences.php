<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');

$user = jarvis_user_by_id($userId);
if (!$user) { session_destroy(); header('Location: login.php'); exit; }

$prefs = jarvis_preferences($userId);
$igToken = jarvis_oauth_get($userId, 'instagram');
$googleToken = jarvis_oauth_get($userId, 'google');
$devices = jarvis_list_devices($userId);
$success = '';
$error = '';

if (isset($_GET['ok'])) {
  $success = match ((string)$_GET['ok']) {
    'instagram_connected' => 'Instagram connected successfully.',
    'instagram_disconnected' => 'Instagram disconnected.',
    'google_connected' => 'Google connected successfully.',
    'google_disconnected' => 'Google disconnected.',
    default => $success,
  };
}
if (isset($_GET['err'])) {
  $error = match ((string)$_GET['err']) {
    'instagram_env_missing' => 'Instagram client env vars are missing. Set INSTAGRAM_CLIENT_ID and INSTAGRAM_CLIENT_SECRET.',
    'instagram_state' => 'Instagram login failed: invalid state.',
    'instagram_exchange' => 'Instagram login failed during token exchange.',
    'invalid_id_token' => 'Google sign-in failed: invalid ID token.',
    default => $error,
  };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $updates = [];
  if (isset($_POST['save_prefs'])) {
    $updates['instagram_watch_username'] = trim($_POST['instagram_watch_username'] ?? '') ?: null;
    $updates['location_logging_enabled'] = isset($_POST['location_logging_enabled']) ? 1 : 0;
    $updates['notif_email'] = isset($_POST['notif_email']) ? 1 : 0;
    $updates['notif_sms'] = isset($_POST['notif_sms']) ? 1 : 0;
    $updates['notif_inapp'] = isset($_POST['notif_inapp']) ? 1 : 0;
    $updates['default_slack_channel'] = trim($_POST['default_slack_channel'] ?? '') ?: null;

    jarvis_update_preferences($userId, $updates);
    jarvis_audit($userId, 'PREF_UPDATE', 'preferences', $updates);
    $prefs = jarvis_preferences($userId);
    $success = 'Preferences saved.';
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Preferences</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <div class="navbar">
    <div class="brand">
      <img src="images/logo.svg" alt="JARVIS logo" />
      <span class="dot" aria-hidden="true"></span>
      <span>JARVIS</span>
    </div>
    <nav>
      <a href="home.php">Home</a>
      <a href="preferences.php" class="active">Preferences</a>
      <a href="audit.php">Audit Log</a>
      <a href="notifications.php">Notifications</a>
      <a href="siri.php">Add to Siri</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>

  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>Preferences</h1>
    <p>Integrations, watch targets, notifications, and privacy controls — all timestamped in MySQL.</p>
  </div>

  <div class="container">
    <?php if($success):?><div class="success"><p><?php echo htmlspecialchars($success); ?></p></div><?php endif;?>
    <?php if($error):?><div class="error"><p><?php echo htmlspecialchars($error); ?></p></div><?php endif;?>

    <div class="grid" style="grid-template-columns: repeat(2, minmax(0,1fr));">
      <div class="card">
        <h3>Preferences</h3>
        <form method="post">
          <label>Default Slack Channel ID</label>
          <input name="default_slack_channel" value="<?php echo htmlspecialchars((string)($prefs['default_slack_channel'] ?? '')); ?>" />

          <label style="margin-top:12px">Instagram watch username</label>
          <input name="instagram_watch_username" placeholder="@target" value="<?php echo htmlspecialchars((string)($prefs['instagram_watch_username'] ?? '')); ?>" />

          <div style="margin-top:12px">
            <label><input type="checkbox" name="location_logging_enabled" <?php echo !empty($prefs['location_logging_enabled']) ? 'checked' : ''; ?> /> Enable browser location logging</label>
          </div>

          <div style="margin-top:12px">
            <label><input type="checkbox" name="notif_inapp" <?php echo !empty($prefs['notif_inapp']) ? 'checked' : ''; ?> /> In-app notifications</label><br/>
            <label><input type="checkbox" name="notif_email" <?php echo !empty($prefs['notif_email']) ? 'checked' : ''; ?> /> Email notifications</label><br/>
            <label><input type="checkbox" name="notif_sms" <?php echo !empty($prefs['notif_sms']) ? 'checked' : ''; ?> /> SMS notifications (Twilio)</label>
          </div>

          <button type="submit" name="save_prefs" value="1" style="margin-top:12px">Save Preferences</button>
        </form>
      </div>

      <div class="card">
        <h3>Integrations Setup</h3>
        <p class="muted">Connect services to unlock real sync checks, wake briefings, and notifications. All connect/disconnect actions are logged to MySQL.</p>

        <div class="mini">
          <h4>Instagram (Basic Display)</h4>
          <p class="muted">Media updates supported. Stories are <b>not</b> available in Basic Display.</p>
          <p>Status: <b><?php echo $igToken ? 'Connected' : 'Not connected'; ?></b>
            <?php if ($igToken && !empty($igToken['expires_at'])): ?>
              <span class="muted">(expires <?php echo htmlspecialchars((string)$igToken['expires_at']); ?> UTC)</span>
            <?php endif; ?>
          </p>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php if (!$igToken): ?>
              <a class="btn" href="connect_instagram.php">Connect Instagram</a>
            <?php else: ?>
              <a class="btn" href="disconnect_instagram.php">Disconnect Instagram</a>
            <?php endif; ?>
          </div>
        </div>

        <div class="mini" style="margin-top:12px">
          <h4>Google (Sign in / Connect)</h4>
          <p class="muted">Sign in with Google or connect your Google account for calendar and API access.</p>
          <p>Status: <b><?php echo $googleToken ? 'Connected' : 'Not connected'; ?></b>
            <?php if ($googleToken && !empty($googleToken['expires_at'])): ?>
              <span class="muted">(expires <?php echo htmlspecialchars((string)$googleToken['expires_at']); ?> UTC)</span>
            <?php endif; ?>
          </p>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php if (!$googleToken): ?>
              <a class="btn" href="connect_google.php">Connect / Sign in with Google</a>
            <?php else: ?>
              <a class="btn" href="disconnect_google.php">Disconnect Google</a>
            <?php endif; ?>
          </div>
        </div>

        <div class="mini" style="margin-top:12px">
          <h4>Registered Devices</h4>
          <p class="muted">Register mobile devices (iOS/Android) via the mobile app to receive push notifications and share location.</p>
          <?php if (!$devices): ?>
            <p class="muted">No devices registered.</p>
          <?php else: ?>
            <ul>
              <?php foreach($devices as $d): ?>
                <li>
                  <b><?php echo htmlspecialchars($d['platform']); ?></b> • <?php echo htmlspecialchars($d['device_uuid']); ?>
                  <?php if (!empty($d['last_seen_at'])): ?><span class="muted"> • last seen <?php echo htmlspecialchars($d['last_seen_at']); ?> UTC</span><?php endif; ?>
                  <?php if (!empty($d['last_location_lat'])): ?><div class="muted">Location: <?php echo htmlspecialchars($d['last_location_lat']); ?>, <?php echo htmlspecialchars($d['last_location_lon']); ?> (<?php echo htmlspecialchars((string)$d['last_location_at']); ?> UTC)</div><?php endif; ?>
                  <div style="margin-top:6px"><a class="btn" href="disconnect_device.php?id=<?php echo (int)$d['id']; ?>">Disconnect</a></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <ul style="margin-top:14px">
          <li><b>Slack</b>: set <code>SLACK_BOT_TOKEN</code> and <code>SLACK_CHANNEL_ID</code>.</li>
          <li><b>Google Calendar</b>: OAuth 2.0 connect flow (store tokens in <code>oauth_tokens</code>).</li>
          <li><b>Spotify</b>: OAuth 2.0 connect flow (store tokens in <code>oauth_tokens</code>).</li>
          <li><b>Instagram</b>: Basic Display connected above for reliable media updates (stories not supported).</li>
          <li><b>Yahoo Weather</b>: OAuth1 signing (location-triggered refresh).</li>
          <li><b>Twilio</b>: set <code>TWILIO_*</code> env vars and enable SMS in preferences.</li>
        </ul>
      </div>
    </div>
  </div>
</body></html>
