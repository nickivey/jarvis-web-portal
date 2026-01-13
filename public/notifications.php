<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');

$pdo = jarvis_pdo();
if (!$pdo) { die('DB not configured'); }

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['mark_read'])) {
    $id = (int)($_POST['notif_id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare('UPDATE notifications SET is_read=1, read_at=:t WHERE id=:id AND user_id=:u')
          ->execute([':t' => jarvis_now_sql(), ':id' => $id, ':u' => $userId]);
      jarvis_audit($userId, 'NOTIF_READ', 'notifications', ['notif_id' => $id]);
      $success = 'Notification marked as read.';
    }
  } elseif (isset($_POST['mark_all_read'])) {
    $count = jarvis_mark_all_notifications_read($userId);
    $success = $count > 0 ? "Marked $count notifications as read." : 'All notifications already read.';
  } elseif (isset($_POST['delete_notif'])) {
    $id = (int)($_POST['notif_id'] ?? 0);
    if ($id > 0) {
      $stmt = $pdo->prepare('DELETE FROM notifications WHERE id=:id AND user_id=:u');
      $stmt->execute([':id' => $id, ':u' => $userId]);
      if ($stmt->rowCount() > 0) {
        jarvis_audit($userId, 'NOTIF_DELETED', 'notifications', ['notif_id' => $id]);
        $success = 'Notification deleted.';
      }
    }
  }
}

// Get filter
$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'unread', 'event', 'reminder', 'info', 'warning', 'error'];
if (!in_array($filter, $validFilters)) $filter = 'all';

// Get notifications with filter
$rows = jarvis_recent_notifications_enhanced($userId, 100);
if ($filter === 'unread') {
  $rows = array_filter($rows, fn($r) => (int)$r['is_read'] === 0);
} elseif ($filter !== 'all') {
  $rows = array_filter($rows, fn($r) => $r['type'] === $filter);
}
$rows = array_values($rows);

// Get upcoming events
$upcomingEvents = jarvis_get_upcoming_events_for_reminders($userId);

// Stats
$unreadCount = jarvis_unread_notifications_count($userId);
$totalCount = count(jarvis_recent_notifications_enhanced($userId, 500));
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Notifications • Real-Time Alerts | JARVIS by Simple Functioning Solutions</title>
  <meta name="description" content="Manage and view all your JARVIS notifications. Real-time alerts from automations, messages, and system events. Simple Functioning Solutions, Orlando." />
  <meta name="keywords" content="notifications, alerts, real-time updates, system events" />
  <meta name="author" content="Simple Functioning Solutions" />
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>🔔 Notifications</h1>
    <p>System alerts, event reminders, and assistant updates</p>
  </div>

  <div class="container">
    <?php if($success):?><div class="success"><p><?php echo htmlspecialchars($success); ?></p></div><?php endif;?>
    <?php if($error):?><div class="error"><p><?php echo htmlspecialchars($error); ?></p></div><?php endif;?>

    <div class="notifications-page-layout">
      <!-- Sidebar -->
      <div class="notif-sidebar">
        <div class="card">
          <h3>📊 Overview</h3>
          <div class="notif-stats">
            <div class="stat-item">
              <span class="stat-value"><?php echo (int)$unreadCount; ?></span>
              <span class="stat-label">Unread</span>
            </div>
            <div class="stat-item">
              <span class="stat-value"><?php echo (int)$totalCount; ?></span>
              <span class="stat-label">Total</span>
            </div>
          </div>
          
          <?php if ($unreadCount > 0): ?>
            <form method="post" style="margin-top:12px">
              <button type="submit" name="mark_all_read" value="1" class="btn secondary" style="width:100%">Mark All Read</button>
            </form>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3>🔍 Filter</h3>
          <div class="notif-filters">
            <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?filter=unread" class="filter-btn <?php echo $filter === 'unread' ? 'active' : ''; ?>">Unread</a>
            <a href="?filter=event" class="filter-btn <?php echo $filter === 'event' ? 'active' : ''; ?>">📅 Events</a>
            <a href="?filter=reminder" class="filter-btn <?php echo $filter === 'reminder' ? 'active' : ''; ?>">🔔 Reminders</a>
            <a href="?filter=info" class="filter-btn <?php echo $filter === 'info' ? 'active' : ''; ?>">ℹ️ Info</a>
            <a href="?filter=warning" class="filter-btn <?php echo $filter === 'warning' ? 'active' : ''; ?>">⚠️ Warnings</a>
          </div>
        </div>

        <?php if (!empty($upcomingEvents)): ?>
        <div class="card">
          <h3>📅 Upcoming</h3>
          <div class="upcoming-sidebar-list">
            <?php foreach (array_slice($upcomingEvents, 0, 5) as $evt): ?>
              <div class="upcoming-sidebar-item <?php echo $evt['is_today'] ? 'today' : 'tomorrow'; ?>">
                <span class="upcoming-badge"><?php echo $evt['is_today'] ? 'Today' : 'Tomorrow'; ?></span>
                <span class="upcoming-title"><?php echo htmlspecialchars($evt['title']); ?></span>
                <span class="upcoming-time"><?php echo $evt['time'] ? date('g:i A', strtotime($evt['time'])) : 'All day'; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Main Content -->
      <div class="notif-main">
        <div class="card">
          <div class="card-header-row">
            <h2>
              <?php 
                $filterLabels = ['all' => 'All Notifications', 'unread' => 'Unread', 'event' => 'Event Notifications', 'reminder' => 'Reminders', 'info' => 'Info', 'warning' => 'Warnings', 'error' => 'Errors'];
                echo $filterLabels[$filter] ?? 'Notifications';
              ?>
            </h2>
            <span class="muted" style="margin-left:auto"><?php echo count($rows); ?> notification<?php echo count($rows) !== 1 ? 's' : ''; ?></span>
          </div>

          <?php if(!$rows): ?>
            <div class="empty-state">
              <div class="empty-icon">📭</div>
              <p>No notifications<?php echo $filter !== 'all' ? ' matching this filter' : ''; ?>.</p>
            </div>
          <?php else: ?>
            <div class="notifications-full-list">
              <?php foreach($rows as $n): ?>
                <div class="notification-full-item <?php echo ((int)$n['is_read'] === 0) ? 'unread' : 'read'; ?> type-<?php echo htmlspecialchars($n['type']); ?>">
                  <div class="notif-icon-lg"><?php echo $n['icon']; ?></div>
                  <div class="notif-full-content">
                    <div class="notif-full-header">
                      <span class="notif-type-badge type-<?php echo htmlspecialchars($n['type']); ?>"><?php echo ucfirst($n['type']); ?></span>
                      <span class="notif-full-time"><?php echo htmlspecialchars($n['time_ago']); ?></span>
                      <?php if ((int)$n['is_read'] === 0): ?>
                        <span class="unread-pill">Unread</span>
                      <?php endif; ?>
                    </div>
                    <h4 class="notif-full-title"><?php echo htmlspecialchars($n['title']); ?></h4>
                    <?php if (!empty($n['body'])): ?>
                      <p class="notif-full-body"><?php echo nl2br(htmlspecialchars((string)$n['body'])); ?></p>
                    <?php endif; ?>
                    <div class="notif-full-footer">
                      <span class="notif-full-date"><?php echo htmlspecialchars($n['created_at']); ?></span>
                      <div class="notif-actions">
                        <?php if ((int)$n['is_read'] === 0): ?>
                          <form method="post" style="display:inline">
                            <input type="hidden" name="notif_id" value="<?php echo (int)$n['id']; ?>" />
                            <button type="submit" name="mark_read" value="1" class="btn btn-sm secondary">Mark Read</button>
                          </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="notif_id" value="<?php echo (int)$n['id']; ?>" />
                          <button type="submit" name="delete_notif" value="1" class="btn btn-sm danger" onclick="return confirm('Delete this notification?')">Delete</button>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <script src="navbar.js"></script>
</body></html>
