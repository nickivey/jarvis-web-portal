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
<?php
// Fetch voice inputs for the timeline
$voiceInputs = jarvis_recent_voice_inputs($userId, 50);
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Audit Log • System Activity Tracking | JARVIS by Simple Functioning Solutions</title>
  <meta name="description" content="Monitor all system activities and user actions through JARVIS audit log. Security and transparency for your smart home platform. Simple Functioning Solutions, Orlando." />
  <meta name="keywords" content="audit log, activity tracking, system security, user monitoring" />
  <meta name="author" content="Simple Functioning Solutions" />
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <div class="navbar">
    <div class="brand">
      <img src="images/logo.svg" alt="JARVIS logo" />
      <span class="dot" aria-hidden="true"></span>
      <span>JARVIS</span>
    </div>
    <button class="nav-toggle" id="navToggle" aria-label="Open menu">☰</button>
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
            <?php foreach($rows as $r):
            $meta = null;
            if (!empty($r['metadata_json'])) {
              $meta = json_decode($r['metadata_json'], true);
              if (!is_array($meta)) $meta = null;
            }
          ?>
              <tr>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td><?php echo htmlspecialchars($r['action']); ?></td>
                <td><?php echo htmlspecialchars((string)($r['entity'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($r['ip'] ?? '')); ?></td>
                <td>
                  <?php if ($meta): ?>
                    <?php if (isset($meta['location_id'])): ?>
                      <div><a href="location_history.php?focus=<?php echo (int)$meta['location_id']; ?>">View location</a></div>
                    <?php elseif (isset($meta['lat']) && isset($meta['lon'])): ?>
                      <div><a href="location_history.php?lat=<?php echo htmlspecialchars($meta['lat']); ?>&amp;lon=<?php echo htmlspecialchars($meta['lon']); ?>">View location</a></div>
                    <?php endif; ?>
                    <div><code><?php echo htmlspecialchars(json_encode($meta)); ?></code></div>
                  <?php else: ?>
                    <code><?php echo htmlspecialchars((string)($r['metadata_json'] ?? '')); ?></code>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Voice Timeline Section -->
    <div class="card" style="margin-top:20px">
      <h3>Voice Session Timeline</h3>
      <p class="muted">Recent raw audio inputs and dictations.</p>
      <div style="overflow-x:auto">
        <table class="table">
          <thead><tr><th>Time</th><th>Transcript</th><th>Audio</th><th>Metadata</th></tr></thead>
          <tbody>
            <?php foreach($voiceInputs as $v): $vm = json_decode($v['metadata_json']??'{}',true); ?>
              <tr>
                <td><?php echo htmlspecialchars($v['created_at']); ?></td>
                <td><?php echo htmlspecialchars($v['transcript'] ?? '(no text)'); ?></td>
                <td>
                  <audio controls src="/api/voice/<?php echo (int)$v['id']; ?>/download" style="height:32px; width:240px"></audio>
                </td>
                <td>
                  <?php if (!empty($vm)): ?>
                     <code><?php echo htmlspecialchars(json_encode($vm)); ?></code>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <script src="navbar.js"></script>
</body></html>
