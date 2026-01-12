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
  if ($action === 'add') {
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
  }
}

$settings = jarvis_setting_list();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Admin - Settings</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav>
    <a href="/home.php">Home</a>
    <a href="/preferences.php">Preferences</a>
    <a href="/logout.php">Logout</a>
  </nav>

  <main>
    <h1>Admin: Settings</h1>
    <?php if ($notice): ?><p class="notice"><?= htmlspecialchars($notice) ?></p><?php endif; ?>

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
  </main>
</body>
</html>
