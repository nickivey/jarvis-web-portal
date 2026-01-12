<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

if (isset($_SESSION['username'])) { header('Location: home.php'); exit; }

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  if ($email === '') {
    $error = 'Enter your email address.';
  } else {
    try {
      $token = jarvis_initiate_password_reset($email);
      if ($token) {
        // Log this password reset request
        $user = jarvis_pdo() ? jarvis_pdo()->prepare('SELECT id FROM users WHERE email=:e LIMIT 1') : null;
        if ($user) {
          $user->execute([':e'=>$email]);
          $row = $user->fetch();
          if ($row) {
            $userId = (int)$row['id'];
            jarvis_audit($userId, 'PASSWORD_RESET_REQUESTED', 'auth', ['email'=>$email]);
          }
        }
        $resetUrl = jarvis_site_url() . '/public/reset_password.php?token=' . urlencode($token);
        $subject = 'Reset your JARVIS password';
        $bodyText = "Click here to reset your password:\n$resetUrl\n\nThis link expires in 1 hour.";
        $bodyHtml = "<p>Click here to reset your password: <a href=\"$resetUrl\">Reset Password</a></p><p>This link expires in 1 hour.</p>";
        $sent = jarvis_send_email($email, $subject, $bodyText, $bodyHtml);
        if (!$sent) error_log('Forgot password: failed to send reset email to ' . $email);
        $message = 'Password reset link has been sent to your email. Check your inbox.';
      } else {
        // Don't reveal whether email exists (security)
        $message = 'If an account exists with that email, a password reset link has been sent.';
      }
    } catch (Throwable $e) {
      error_log('Forgot password error: ' . $e->getMessage());
      $error = 'An error occurred while processing your request. Please try again later.';
    }
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Forgot Password</title>
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
    <h1>Forgot Password</h1>
    <p>Reset your password and regain access to JARVIS.</p>
  </div>
  <div class="container">
    <div class="card">
      <h2>Forgot Password</h2>
      <?php if ($message): ?>
        <div class="success"><p><?php echo htmlspecialchars($message); ?></p></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="error"><p><?php echo htmlspecialchars($error); ?></p></div>
      <?php endif; ?>
      <form method="post">
        <label>Email Address</label>
        <input type="email" name="email" required />
        <button class="btn" type="submit">Send Reset Link</button>
      </form>
      <div class="nav-links"><a href="login.php">Back to login</a></div>
    </div>
  </div>
  <script src="navbar.js"></script>
</body></html>
