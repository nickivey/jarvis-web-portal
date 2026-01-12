<?php
session_start();
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

if (isset($_SESSION['username'])) { header('Location: home.php'); exit; }

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u=trim($_POST['username']??'');
  $p=(string)($_POST['password']??'');
  $email=trim($_POST['email']??'');
  $phone=trim($_POST['phone']??'');
  if ($u===''||$p===''||$email==='') {
    $errors[]='Username, password, and email are required.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[]='Enter a valid email.';
  } else {
    $existing = jarvis_user_by_username($u);
    if ($existing) {
      $errors[] = 'Username already exists.';
    } else {
      // NOTE: unique email is enforced by DB, but we pre-check for nicer errors.
      $pdo = jarvis_pdo();
      if (!$pdo) {
        $errors[] = 'Database is not configured. Set DB_HOST/DB_NAME/DB_USER/DB_PASS.';
      } else {
        $emailExists = $pdo->prepare('SELECT id FROM users WHERE email=:e LIMIT 1');
        $emailExists->execute([':e'=>$email]);
        if ($emailExists->fetch()) {
          $errors[] = 'Email already exists.';
        } else {
          $token = bin2hex(random_bytes(24));
          $userId = jarvis_create_user($u, $email, $phone ?: null, password_hash($p, PASSWORD_DEFAULT), $token);
          jarvis_audit($userId, 'REGISTER', 'auth', ['email'=>$email]);
          jarvis_send_confirmation_email($email,$u,$token);
          if ($phone) jarvis_send_sms($phone, "JARVIS: Welcome {$u}! Check your email to confirm your account.");
          $_SESSION['pending_user']=$u;
          header('Location: register_success.php'); exit;
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Register</title>
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
    <h1>Create account</h1>
    <p>Register, confirm your email, and enter the JARVIS command center.</p>
  </div>
  <div class="container">
    <div class="card">
      <h2>Register</h2>
      <?php if($errors):?><div class="error"><?php foreach($errors as $e){echo '<p>'.htmlspecialchars($e).'</p>';}?></div><?php endif;?>
      <form method="post">
        <label>Username</label>
        <input name="username" required />
        <label>Email</label>
        <input name="email" type="email" required />
        <label>Phone (optional, for SMS)</label>
        <input name="phone" placeholder="+1..." />
        <label>Password</label>
        <input type="password" name="password" required />
        <button type="submit">Create account</button>
      </form>
      <div class="nav-links"><a href="login.php">Already have an account? Log in</a></div>
    </div>
  </div>
</body></html>
