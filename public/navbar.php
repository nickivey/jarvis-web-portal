<?php
  $currPath = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
  $curr = basename($currPath ?: '');
  $isHome = ($curr === 'home.php' || $curr === 'index.php');
?>
<div class="navbar">
  <div class="brand">
    <img src="images/logo.svg" alt="JARVIS logo" />
    <span class="dot" aria-hidden="true"></span>
    <span>JARVIS</span>
  </div>
  <button class="nav-toggle" id="navToggle" aria-label="Open menu">☰</button>
  <nav>
    <a href="home.php" class="<?php echo $isHome ? 'active' : ''; ?>">Home</a>
    <a href="photos.php" class="<?php echo ($curr==='photos.php') ? 'active' : ''; ?>">Photos & Videos</a>
    <a href="channel.php" class="<?php echo ($curr==='channel.php') ? 'active' : ''; ?>">Channels</a>
    <a href="preferences.php" class="<?php echo ($curr==='preferences.php') ? 'active' : ''; ?>">Preferences</a>
    <a href="admin.php" class="<?php echo ($curr==='admin.php') ? 'active' : ''; ?>">Admin</a>
    <a href="audit.php" class="<?php echo ($curr==='audit.php') ? 'active' : ''; ?>">Audit Log</a>
    <a href="notifications.php" class="<?php echo ($curr==='notifications.php') ? 'active' : ''; ?>">Notifications</a>
    <a href="siri.php" class="<?php echo ($curr==='siri.php') ? 'active' : ''; ?>">Add to Siri</a>
    <a href="logout.php" class="<?php echo ($curr==='logout.php') ? 'active' : ''; ?>">Logout</a>
  </nav>
</div>
<script src="/navbar.js"></script>