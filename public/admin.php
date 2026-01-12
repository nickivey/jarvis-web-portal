<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo "Forbidden: login as an admin to access this page.";
  exit;
}
$user = jarvis_user_by_id((int)$_SESSION['user_id']);
if (!$user || ($user['role'] ?? '') !== 'admin') {
  http_response_code(403);
  echo "Forbidden: admin access only.";
  exit;
}

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // User actions
  if (in_array($action, ['user_promote','user_demote','user_delete','user_resetpw','user_resend'])) {
    $uid = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($uid <= 0) { $notice = 'User id required.'; }
    else {
      if ($action === 'user_promote') {
        jarvis_set_user_role($uid, 'admin');
        jarvis_audit((int)$_SESSION['user_id'], 'USER_PROMOTE', 'admin', ['user_id'=>$uid]);
        $notice = 'User promoted to admin.';
      } elseif ($action === 'user_demote') {
        jarvis_set_user_role($uid, 'user');
        jarvis_audit((int)$_SESSION['user_id'], 'USER_DEMOTE', 'admin', ['user_id'=>$uid]);
        $notice = 'User demoted to user.';
      } elseif ($action === 'user_delete') {
        if (jarvis_delete_user($uid)) { jarvis_audit((int)$_SESSION['user_id'], 'USER_DELETE', 'admin', ['user_id'=>$uid]); $notice = 'User deleted.'; } else { $notice = 'Failed to delete user.'; }
      } elseif ($action === 'user_resetpw') {
        // Create a password reset token and email it
        $u = jarvis_user_by_id($uid);
        if ($u) {
          $token = jarvis_initiate_password_reset($u['email']);
          if ($token) {
            $resetUrl = jarvis_site_url() . '/public/reset_password.php?token=' . urlencode($token);
            jarvis_send_email($u['email'], 'Reset your password', "Reset: {$resetUrl}", "<p>Reset: <a href=\"{$resetUrl}\">Reset password</a></p>");
            jarvis_audit((int)$_SESSION['user_id'], 'USER_RESET_REQUEST', 'admin', ['user_id'=>$uid]);
            $notice = 'Password reset link sent.';
          } else { $notice = 'Failed to create reset token.'; }
        } else { $notice = 'User not found.'; }
      } elseif ($action === 'user_resend') {
        if (jarvis_resend_email_verification($uid)) { jarvis_audit((int)$_SESSION['user_id'], 'USER_RESEND_CONFIRM', 'admin', ['user_id'=>$uid]); $notice = 'Confirmation resent.'; }
        else { $notice = 'Failed to resend confirmation.'; }
      }
    }

  // Existing settings actions
  } elseif ($action === 'add') {
    $k = trim($_POST['key'] ?? '');
    $v = trim($_POST['value'] ?? '');
    if ($k === '') $notice = 'Key required.';
    else {
      jarvis_setting_set($k, $v);
      $notice = 'Added/Updated setting.';
    }
  } elseif ($action === 'delete') {
    $k = $_POST['key'] ?? '';
    if ($k !== '') {
      jarvis_setting_delete($k);
      $notice = 'Deleted setting.';
    }
  } elseif ($action === 'update') {
    $k = $_POST['key'] ?? '';
    $v = $_POST['value'] ?? '';
    if ($k !== '') {
      jarvis_setting_set($k, $v);
      $notice = 'Updated setting.';
    }
  } elseif ($action === 'weather_test') {
    // Test server-side weather fetch for given coords (default NYC)
    $lat = isset($_POST['test_lat']) ? (float)$_POST['test_lat'] : 40.7128;
    $lon = isset($_POST['test_lon']) ? (float)$_POST['test_lon'] : -74.0060;
    $w = null;
    try {
      $w = jarvis_fetch_weather($lat, $lon);
      if (!$w) {
        $notice = 'Weather fetch failed. Is OPENWEATHER_API_KEY configured?';
      } else {
        $notice = 'Weather OK: ' . htmlspecialchars(($w['desc'] ?? '(no desc)') . ' • ' . (($w['temp_c']!==null)?$w['temp_c'].'°C':''));
      }
    } catch (Throwable $e) {
      $notice = 'Weather test error: ' . htmlspecialchars($e->getMessage());
    }
  } elseif ($action === 'sendgrid_test') {
    $to = trim((string)($_POST['test_email'] ?? ''));
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $apiKey = jarvis_setting_get('SENDGRID_API_KEY') ?: getenv('SENDGRID_API_KEY') ?: '';
      $from = jarvis_mail_from();
      if (!$apiKey) { $notice = 'SendGrid API key not configured.'; }
      else {
        $payload = [
          'personalizations' => [[ 'to' => [[ 'email' => $to ]] ]],
          'from' => ['email' => $from],
          'subject' => 'JARVIS Admin SendGrid Test',
          'content' => [[ 'type' => 'text/plain', 'value' => 'This is a test email from JARVIS (admin test).' ]]
        ];
        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST => true,
          CURLOPT_POSTFIELDS => json_encode($payload),
          CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
          ],
          CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
          $notice = 'cURL error: ' . $curlErr;
        } elseif ($code < 200 || $code >= 300) {
          // Provide a clearer actionable message for common SendGrid 403 sender identity issues
          $short = htmlspecialchars(substr($resp,0,512));
          if ($code === 403 && stripos($short, 'from address') !== false) {
            $notice = 'SendGrid rejected the message: your MAIL_FROM is not a verified Sender Identity. Current MAIL_FROM: ' . htmlspecialchars($from) . '. Verify the sender identity in SendGrid or set MAIL_FROM to a verified address (see SENDGRID_SETUP.md).';
          } else {
            $notice = 'SendGrid API error: HTTP ' . $code . ' — ' . $short;
          }
        } else {
          $notice = 'SendGrid test email sent successfully to ' . htmlspecialchars($to);
        }
      }
    } else { $notice = 'Provide a valid email address to test.'; }
  }
}

// Ensure GOOGLE_REDIRECT_URI defaults to the current site URL if not configured
if (!jarvis_setting_get('GOOGLE_REDIRECT_URI')) {
  $defaultRedirect = jarvis_site_url() ? jarvis_site_url() . '/public/google_callback.php' : '';
  if ($defaultRedirect) {
    jarvis_setting_set('GOOGLE_REDIRECT_URI', $defaultRedirect);
    $notice = 'GOOGLE_REDIRECT_URI set to current site URL: ' . $defaultRedirect;
  }
}

$settings = jarvis_setting_list();

// Warn if GOOGLE_REDIRECT_URI is configured but differs from detected site URL
$siteRedirect = jarvis_site_url() ? jarvis_site_url() . '/public/google_callback.php' : null;
$googleRedirectConfigured = jarvis_setting_get('GOOGLE_REDIRECT_URI');
$googleRedirectMismatch = ($googleRedirectConfigured && $siteRedirect && trim($googleRedirectConfigured) !== trim($siteRedirect));

// OAuth diagnostics: fetch recent google oauth-related audit events for troubleshooting
$oauthAudits = [];
try {
  $pdo = jarvis_pdo();
  if ($pdo) {
    $q = $pdo->prepare("SELECT id, action, entity, metadata_json, created_at FROM audit_log WHERE entity = 'google' OR action LIKE 'OAUTH_%' ORDER BY id DESC LIMIT 10");
    $q->execute();
    $oauthAudits = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {
  // ignore
}

// Voice Data Timeline
$voiceRows = jarvis_list_all_voice_inputs(50);

// User management defaults
$users = jarvis_list_users(50,0, (string)($_GET['q'] ?? ''));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Admin - Settings & Users</title>
  <link rel="stylesheet" href="style.css">
  <style>
    table td form{display:inline-block;margin:0}
    .term-code { font-family:monospace; font-size:12px; white-space:pre-wrap; max-height:100px; overflow-y:auto; background:#111; color:#0f0; padding:4px; border-radius:4px; }
    audio { height: 32px; width: 240px; }
  </style>
</head>
<body>
  <nav>
    <a href="home.php">Home</a>
    <a href="preferences.php">Preferences</a>
    <a href="logout.php">Logout</a>
  </nav>

  <main>
    <h1>Admin Console</h1>
    <?php if ($notice): ?><p class="notice"><?= htmlspecialchars($notice) ?></p><?php endif; ?>

    <section>
      <h2>Voice Data Timeline</h2>
      <p class="muted">Recent voice inputs recorded by clients for deep dictation analysis.</p>
      <?php if (empty($voiceRows)): ?>
        <p class="muted">No voice inputs recorded yet.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Date/Time</th><th>User</th><th>Audio / Transcript</th><th>Metadata & Location</th></tr></thead>
          <tbody>
            <?php foreach ($voiceRows as $v): ?>
              <?php $meta = $v['metadata_json'] ? json_decode($v['metadata_json'], true) : []; ?>
              <tr>
                <td style="white-space:nowrap"><?= htmlspecialchars($v['created_at']) ?></td>
                <td>
                  <div><b><?= htmlspecialchars($v['username'] ?: 'User #'.$v['user_id']) ?></b></div>
                  <div class="muted"><?= htmlspecialchars($v['email'] ?? '') ?></div>
                </td>
                <td>
                  <audio controls preload="none">
                    <source src="/api/voice/<?= (int)$v['id'] ?>/download" type="audio/webm">
                    Download not supported
                  </audio>
                  <div style="margin-top:4px; font-style:italic">"<?= htmlspecialchars($v['transcript'] ?: '(no transcript)') ?>"</div>
                  <div class="muted" style="font-size:12px">Duration: <?= (int)($v['duration_ms'] ?? 0) ?>ms • <?= htmlspecialchars(basename($v['filename'])) ?></div>
                </td>
                <td>
                  <?php if (!empty($meta['location'])): ?>
                    <?php $loc = $meta['location']; ?>
                    <div>Accessed: <a href="https://www.google.com/maps?q=<?= htmlspecialchars($loc['lat']) ?>,<?= htmlspecialchars($loc['lon']) ?>" target="_blank"><?= number_format((float)$loc['lat'],4) ?>, <?= number_format((float)$loc['lon'],4) ?></a></div>
                    <div class="muted">Acc: <?= (int)($loc['accuracy']??0) ?>m</div>
                  <?php else: ?>
                    <div class="muted">No location data</div>
                  <?php endif; ?>
                  <?php if (!empty($meta)): ?>
                     <details><summary>Raw Meta</summary><div class="term-code"><?= htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT)) ?></div></details>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section>
      <h2>Manage Users</h2>
      <form method="get" style="margin-bottom:12px">
        <label>Search by email or username: <input name="q" value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>"></label>
        <button type="submit">Search</button>
      </form>

      <?php if (empty($users)): ?>
        <p class="muted">No users found.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Verified</th><th>Last Login</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['role']) ?></td>
                <td><?= $u['email_verified_at'] ? 'Yes' : 'No' ?></td>
                <td><?= htmlspecialchars($u['last_login_at'] ?? '') ?></td>
                <td>
                  <?php if (($u['role'] ?? '') !== 'admin'): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="user_promote"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn" type="submit">Promote to Admin</button></form>
                  <?php else: ?>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="user_demote"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn secondary" type="submit">Demote</button></form>
                  <?php endif; ?>
                  <form method="post" style="display:inline"><input type="hidden" name="action" value="user_resend"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn secondary" type="submit">Resend Confirmation</button></form>
                  <form method="post" style="display:inline"><input type="hidden" name="action" value="user_resetpw"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn" type="submit">Send Reset Link</button></form>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>?');"><input type="hidden" name="action" value="user_delete"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn secondary" type="submit">Delete</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <hr>

    <section>
      <h2>Add / Update Setting</h2>
      <form method="post">
        <input type="hidden" name="action" value="add">
        <label>Key: <input name="key" required></label><br>
        <label>Value: <input name="value"></label><br>
        <button type="submit">Add / Update</button>
      </form>
    </section>

    <section>
      <h2>Existing Settings</h2>
      <p class="muted">Update keys here; changes take effect immediately for the running application (no restart required).</p>
      <?php if ($googleRedirectMismatch): ?>
        <p class="muted">⚠️ <b>Redirect mismatch:</b> the configured <code>GOOGLE_REDIRECT_URI</code> (<?= htmlspecialchars($googleRedirectConfigured) ?>) does not match the site-detected default (<?= htmlspecialchars($siteRedirect) ?>). This often causes <code>access_denied</code> / <code>redirect_uri_mismatch</code> errors. Use "Set to current site" to fix it or adjust the Redirect URI in Google Cloud Console to match exactly.</p>
      <?php endif; ?>
      <?php if (empty($settings)): ?>
        <p>No settings yet.</p>
      <?php else: ?>
        <tr><td colspan="5"><small class="muted">Tip: When using Google OAuth with access_type=offline you may need to set <code>GOOGLE_REDIRECT_URI</code> to your public URL and use prompt=consent to obtain a refresh token. If users see "access_denied", verify that your OAuth Client in Google Cloud Console has the Redirect URI registered exactly as configured here.</small></td></tr>
        <table>
          <thead><tr><th>Key</th><th>Value (hidden)</th><th>Created</th><th>Updated</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($settings as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['key']) ?></td>
              <td>
                <form method="post" style="display:inline-block;">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="key" value="<?= htmlspecialchars($s['key']) ?>">
                  <input name="value" value="<?= htmlspecialchars($s['value']) ?>" style="width:320px;">
                  <button type="submit" style="margin-left:8px;">Update</button>
                </form>
                <?php if ($s['key'] === 'GOOGLE_REDIRECT_URI'): ?>
                  <form method="post" style="display:inline;margin-left:8px;">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="key" value="GOOGLE_REDIRECT_URI">
                    <input type="hidden" name="value" value="<?= htmlspecialchars(jarvis_site_url() ? jarvis_site_url() . '/public/google_callback.php' : '') ?>">
                    <button type="submit">Set to current site</button>
                  </form>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($s['created_at'] ?? '') ?></td>
              <td><?= htmlspecialchars($s['updated_at'] ?? '') ?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="key" value="<?= htmlspecialchars($s['key']) ?>">
                  <button type="submit" onclick="return confirm('Delete <?= htmlspecialchars($s['key']) ?>?')">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section>
      <h2>Mail SEND From</h2>
      <p class="muted">The value below is used as the "From" address for outgoing email. It can be set here or via the environment variable <code>MAIL_FROM</code>.</p>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="action" value="update">
        <label style="flex:1;min-width:240px">MAIL_FROM: <input name="value" value="<?= htmlspecialchars(jarvis_mail_from()) ?>" placeholder="jarvis@yourdomain.com"></label>
        <input type="hidden" name="key" value="MAIL_FROM">
        <button class="btn" type="submit">Save MAIL_FROM</button>
      </form>
    </section>

    <section>
      <h2>OAuth Diagnostics (Google)</h2>
      <p class="muted">Recent Google OAuth events recorded by the application; use this to diagnose <code>access_denied</code>, <code>redirect_uri_mismatch</code>, and token exchange errors.</p>
      <?php if (empty($oauthAudits)): ?>
        <p class="muted">No recent Google OAuth audit events.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>When</th><th>Action</th><th>Metadata</th></tr></thead>
          <tbody>
            <?php foreach ($oauthAudits as $a): ?>
              <tr>
                <td style="white-space:nowrap"><?= htmlspecialchars($a['created_at'] ?? '') ?></td>
                <td><?= htmlspecialchars($a['action'] ?? '') ?></td>
                <td>
                  <?php $m = $a['metadata_json'] ? json_decode($a['metadata_json'], true) : null; ?>
                  <?php if ($m && is_array($m)): ?>
                    <div style="font-size:13px;line-height:1.2">
                      <?php foreach ($m as $mk=>$mv): ?>
                        <div><b><?= htmlspecialchars($mk) ?></b>: <?= htmlspecialchars(is_scalar($mv) ? (string)$mv : json_encode($mv)) ?></div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="muted">No metadata</div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section>
      <h2>Notes</h2>
      <ul>
        <li>Values are visible in the edit box here; avoid pasting secrets you don't intend to keep.</li>
        <li>Consider rotating keys that were previously leaked and removed from history.</li>
      </ul>
    </section>

    <section>
      <h2>Weather API</h2>
      <p class="muted">JARVIS can fetch local weather when `OPENWEATHER_API_KEY` is configured. Use the test below to verify server-side weather lookups.</p>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="action" value="weather_test">
        <label style="flex:1;min-width:240px">Test coordinates: <input name="test_lat" placeholder="lat" value="40.7128">,<input name="test_lon" placeholder="lon" value="-74.0060"></label>
        <button class="btn" type="submit">Test Weather</button>
      </form>
    </section>

    <section>
      <h2>SendGrid Diagnostic</h2>
      <p class="muted">If you're not receiving email, verify your sender identity in SendGrid and use the test below to see API responses.</p>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="action" value="sendgrid_test">
        <label style="flex:1;min-width:240px">Test recipient: <input name="test_email" placeholder="you@example.com"></label>
        <button class="btn" type="submit">Send test email</button>
      </form>
    </section>
  </main>
</body>
</html>
