<?php
session_start();
require_once __DIR__ . '/../db.php';
if (isset($_SESSION['username'])) { header('Location: home.php'); exit; }

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u=trim($_POST['username']??'');
  $p=(string)($_POST['password']??'');
  $user = $u ? jarvis_user_by_username($u) : null;
  if (!$u || !$p || !$user || !password_verify($p, $user['password_hash'] ?? '')) {
    $errors[]='Invalid username or password.';
    jarvis_audit($user['id'] ?? null, 'LOGIN_FAIL', 'auth', ['username'=>$u]);
  } elseif (empty($user['email_verified_at'])) {
    $errors[]='Please confirm your email before logging in.';
  } else {
    $_SESSION['username']=$u;
    $_SESSION['user_id']=(int)$user['id'];
    jarvis_update_last_login((int)$user['id']);
    jarvis_audit((int)$user['id'], 'LOGIN_SUCCESS', 'auth', null);
    header('Location: home.php'); exit;
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Login</title>
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
      <a href="login.php">Login</a>
      <a href="register.php">Register</a>
    </nav>
  </div>
  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>JARVIS</h1>
    <p>Blue/black secure sign-in console</p>
  </div>
  <div class="container">
    <div class="card">
      <h2>Login</h2>
      <p class="muted" style="margin-top:-4px">If you just registered, confirm your email to activate access.</p>
      <?php if($errors):?><div class="error"><?php foreach($errors as $e){echo '<p>'.htmlspecialchars($e).'</p>';}?></div><?php endif;?>
      <form method="post">
        <label>Username</label>
        <input name="username" required />
        <label>Password</label>
        <input type="password" name="password" required />
        <button class="btn" type="submit">Enter JARVIS</button>
      </form>

      <?php $googleConfigured = (bool)(getenv('GOOGLE_CLIENT_ID') && getenv('GOOGLE_CLIENT_SECRET')); ?>
      <div style="margin-top:12px;text-align:center;">
        <?php if ($googleConfigured): ?>
        <a href="connect_google.php" style="display:inline-block;padding:8px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;color:#222;text-decoration:none;font-weight:600;">
          Sign in with Google
        </a>
        <?php else: ?>
        <div style="color:#888;font-size:13px;">Google Sign-in not configured</div>
        <?php endif; ?>
      </div>

      <div class="nav-links"><a href="register.php">Create an account</a></div>
    </div>
  </div>
</body></html>
