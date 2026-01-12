<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $identifier = trim((string)($_POST['identifier'] ?? ''));
  $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

  if ($identifier === '') {
    $error = 'Enter your email or username.';
    if ($isAjax) {
      header('Content-Type: application/json'); http_response_code(400);
      echo json_encode(['success'=>false,'message'=>$error]); exit;
    }
  } else {
    // Try email first, then username
    $user = null;
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
      $user = jarvis_user_by_email($identifier);
    }
    if (!$user) {
      $user = jarvis_user_by_username($identifier);
    }

    if ($user) {
      $userId = (int)$user['id'];
      if (!empty($user['email_verified_at'])) {
        $message = 'Email already verified. You can log in.';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>$message]); exit; }
      } else {
        if (jarvis_resend_email_verification($userId)) {
          $message = 'Confirmation email has been resent to ' . htmlspecialchars((string)$user['email']);
          if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>$message]); exit; }
        } else {
          $error = 'Failed to resend confirmation. Try again later.';
          if ($isAjax) { header('Content-Type: application/json'); http_response_code(500); echo json_encode(['success'=>false,'message'=>$error]); exit; }
        }
      }
    } else {
      // Avoid leaking which accounts exist
      $message = 'If an account exists for that identifier, a confirmation email has been resent.';
      if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>$message]); exit; }
    }
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Resend Confirmation Email</title>
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
      <a href="register.php">Register</a>
    </nav>
  </div>
  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>Resend Confirmation</h1>
    <p>Enter your email or username to receive a new confirmation link.</p>
  </div>
  <div class="container">
    <div class="card">
      <h2>Resend Email Confirmation</h2>
      <?php if($message):?><div class="success"><p><?php echo $message; ?></p></div><?php endif;?>
      <?php if($error):?><div class="error"><p><?php echo htmlspecialchars($error); ?></p></div><?php endif;?>
      <form method="post">
        <label>Email or Username</label>
        <input name="identifier" placeholder="you@example.com or username" required />
        <button type="submit" name="resend" value="1">Resend Confirmation</button>
      </form>
      <div class="nav-links" style="margin-top:10px"><a href="login.php">Back to login</a></div>
    </div>
  </div>
  <script src="navbar.js"></script>
</body></html>
