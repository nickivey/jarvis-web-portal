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
          $notice = 'SendGrid API error: HTTP ' . $code . ' — ' . htmlspecialchars(substr($resp,0,512));
        } else {
          $notice = 'SendGrid test email sent successfully to ' . htmlspecialchars($to);
        }
      }
    } else { $notice = 'Provide a valid email address to test.'; }
  }
}

$settings = jarvis_setting_list();

// User management defaults
$users = jarvis_list_users(50,0, (string)($_GET['q'] ?? ''));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Admin - Settings & Users</title>
  <link rel="stylesheet" href="style.css">
  <style>table td form{display:inline-block;margin:0}</style>
</head>
<body>
  <nav>
    <a href="/home.php">Home</a>
    <a href="/preferences.php">Preferences</a>
    <a href="/logout.php">Logout</a>
  </nav>

  <main>
    <h1>Admin Console</h1>
    <?php if ($notice): ?><p class="notice"><?= htmlspecialchars($notice) ?></p><?php endif; ?>

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
      <?php if (empty($settings)): ?>
        <p>No settings yet.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Key</th><th>Value (hidden)</th><th>Created</th><th>Updated</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($settings as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['key']) ?></td>
              <td><form method="post" style="display:inline-block;"><input type="hidden" name="action" value="update"><input type="hidden" name="key" value="<?= htmlspecialchars($s['key']) ?>"><input name="value" value="<?= htmlspecialchars($s['value']) ?>" style="width:360px;"></form></td>
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
      <h2>Notes</h2>
      <ul>
        <li>Values are visible in the edit box here; avoid pasting secrets you don't intend to keep.</li>
        <li>Consider rotating keys that were previously leaked and removed from history.</li>
      </ul>
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
