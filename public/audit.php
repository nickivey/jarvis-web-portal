<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');

$pdo = jarvis_pdo();
if (!$pdo) { die('DB not configured'); }

$limit = 200;
$stmt = $pdo->prepare('SELECT action,entity,metadata_json,created_at,ip FROM audit_log WHERE user_id=:u ORDER BY id DESC LIMIT :l');
$stmt->bindValue(':u',$userId,PDO::PARAM_INT);
$stmt->bindValue(':l',$limit,PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll() ?: [];
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Audit Log</title>
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
      <a href="audit.php" class="active">Audit Log</a>
      <a href="notifications.php">Notifications</a>
      <a href="siri.php">Add to Siri</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>

  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>Audit Log</h1>
    <p>Every login, action, sync, and API request—timestamped in MySQL.</p>
  </div>

  <div class="container">
    <div class="card">
      <h2>Audit Log</h2>
      <p class="muted">User: <?php echo htmlspecialchars($username); ?> • Showing latest <?php echo (int)$limit; ?> events</p>
      <div style="overflow:auto">
        <table class="table">
          <thead><tr><th>Time</th><th>Action</th><th>Entity</th><th>IP</th><th>Metadata</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td><?php echo htmlspecialchars($r['action']); ?></td>
                <td><?php echo htmlspecialchars((string)($r['entity'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($r['ip'] ?? '')); ?></td>
                <td><code><?php echo htmlspecialchars((string)($r['metadata_json'] ?? '')); ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body></html>
