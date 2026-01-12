<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');

$pdo = jarvis_pdo();
if (!$pdo) { die('DB not configured'); }

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
  $id = (int)($_POST['notif_id'] ?? 0);
  if ($id > 0) {
    $pdo->prepare('UPDATE notifications SET is_read=1, read_at=:t WHERE id=:id AND user_id=:u')
        ->execute([':t' => jarvis_now_sql(), ':id' => $id, ':u' => $userId]);
    jarvis_audit($userId, 'NOTIF_READ', 'notifications', ['notif_id' => $id]);
    $success = 'Notification marked as read.';
  }
}

$rows = jarvis_recent_notifications($userId, 100);
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Notifications</title>
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
      <a href="preferences.php">Preferences</a>
      <a href="audit.php">Audit Log</a>
      <a href="notifications.php" class="active">Notifications</a>
      <a href="siri.php">Add to Siri</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>

  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>Notifications</h1>
    <p>System alerts, sync results, and assistant updates—tracked per user.</p>
  </div>

  <div class="container">
    <?php if($success):?><div class="success"><p><?php echo htmlspecialchars($success); ?></p></div><?php endif;?>

    <div class="card">
      <h2>Notifications</h2>
      <p class="muted">User: <?php echo htmlspecialchars($username); ?></p>

      <?php if(!$rows): ?>
        <p class="muted">No notifications yet.</p>
      <?php else: ?>
        <?php foreach($rows as $n): ?>
          <div class="terminal" style="margin:10px 0">
            <div class="term-title">
              <?php echo htmlspecialchars($n['type']); ?> • <?php echo htmlspecialchars($n['title']); ?>
              <?php if ((int)$n['is_read'] === 0): ?><span class="badge" style="margin-left:10px">UNREAD</span><?php endif; ?>
            </div>
            <div class="term-body">
              <div><?php echo htmlspecialchars((string)($n['body'] ?? '')); ?></div>
              <div class="meta" style="margin-top:8px"><?php echo htmlspecialchars($n['created_at']); ?></div>
              <?php if ((int)$n['is_read'] === 0): ?>
                <form method="post" style="margin-top:10px">
                  <input type="hidden" name="notif_id" value="<?php echo (int)$n['id']; ?>" />
                  <button type="submit" name="mark_read" value="1">Mark read</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</body></html>
