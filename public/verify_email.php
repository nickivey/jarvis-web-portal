<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$userId = (int)$_SESSION['user_id'];
$user = jarvis_user_by_id($userId);
if (!$user) { session_destroy(); header('Location: login.php'); exit; }

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
  if (jarvis_resend_email_verification($userId)) {
    $message = 'Confirmation email has been resent to ' . htmlspecialchars($user['email']);
  } else {
    $error = 'Failed to resend email. Try again.';
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Verify Email</title>
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
      <a href="login.php">Login</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>Verify Email</h1>
    <p>Please confirm your email address to activate your account.</p>
  </div>
  <div class="container">
    <div class="card">
      <h2>Email Verification</h2>
      <p class="muted">We sent a confirmation link to: <strong><?php echo htmlspecialchars($user['email']); ?></strong></p>
      <?php if ($message): ?>
        <div class="success"><p><?php echo htmlspecialchars($message); ?></p></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="error"><p><?php echo htmlspecialchars($error); ?></p></div>
      <?php endif; ?>
      
      <?php if (empty($user['email_verified_at'])): ?>
        <form method="post">
          <button type="submit" name="resend" value="1" class="btn">Resend Confirmation Email</button>
        </form>
      <?php else: ?>
        <div class="success"><p>Your email has been verified! <a href="home.php">Go to Home</a></p></div>
      <?php endif; ?>
    </div>
  </div>
  <script src="navbar.js"></script>
</body></html>
