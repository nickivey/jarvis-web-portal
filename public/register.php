<?php
session_start();
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$googleConfigured = (bool)(jarvis_setting_get('GOOGLE_CLIENT_ID') && jarvis_setting_get('GOOGLE_CLIENT_SECRET')) || (bool)(getenv('GOOGLE_CLIENT_ID') && getenv('GOOGLE_CLIENT_SECRET'));

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
  <title>JARVIS Registration • Create Your Account | Simple Functioning Solutions</title>
  <meta name="description" content="Create your JARVIS account and start controlling your smart home. Simple Functioning Solutions, Orlando." />
  <meta name="author" content="Simple Functioning Solutions" />
  <meta name="robots" content="noindex, nofollow" />
  <link rel="stylesheet" href="style.css" />
  <style>
    body { background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%); min-height: 100vh; }
    .register-container { max-width: 480px; margin: 60px auto; padding: 0 20px; }
    .register-hero { text-align: center; margin-bottom: 32px; }
    .register-hero-icon { font-size: 64px; margin-bottom: 16px; animation: float 3s ease-in-out infinite; }
    @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
    .register-hero h1 { font-size: 2rem; color: #fff; margin-bottom: 8px; font-weight: 700; }
    .register-hero p { color: rgba(255,255,255,.7); font-size: 1rem; line-height: 1.6; }
    .register-card { background: linear-gradient(135deg, rgba(26,26,46,.95), rgba(22,33,62,.95)); border: 1px solid rgba(255,255,255,.1); border-radius: 16px; padding: 32px; box-shadow: 0 20px 60px rgba(0,0,0,.5); backdrop-filter: blur(10px); }
    .register-card h2 { color: #fff; margin: 0 0 24px 0; font-size: 1.5rem; font-weight: 600; text-align: center; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; color: rgba(255,255,255,.9); font-weight: 500; margin-bottom: 8px; font-size: .9rem; }
    .form-group input { width: 100%; padding: 12px 16px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); border-radius: 8px; color: #fff; font-size: 1rem; transition: all .2s ease; }
    .form-group input:focus { outline: none; background: rgba(255,255,255,.08); border-color: rgba(29,155,209,.5); box-shadow: 0 0 0 3px rgba(29,155,209,.1); }
    .form-group input::placeholder { color: rgba(255,255,255,.4); }
    .error-alert { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.3); border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; color: #fca5a5; font-size: .9rem; }
    .error-alert p { margin: 4px 0; }
    .btn-register { width: 100%; padding: 14px; background: linear-gradient(135deg, #1d9bd1, #0ea5e9); border: none; border-radius: 8px; color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer; transition: all .2s ease; }
    .btn-register:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(29,155,209,.4); }
    .divider { display: flex; align-items: center; text-align: center; margin: 24px 0; color: rgba(255,255,255,.5); font-size: .85rem; }
    .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid rgba(255,255,255,.1); }
    .divider span { padding: 0 12px; }
    .btn-google { width: 100%; padding: 12px; background: #fff; border: 1px solid rgba(255,255,255,.2); border-radius: 8px; color: #222; font-weight: 600; font-size: .95rem; cursor: pointer; transition: all .2s ease; display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
    .btn-google:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255,255,255,.2); }
    .google-disabled { opacity: .5; cursor: not-allowed; color: rgba(255,255,255,.5); background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.1); }
    .register-footer { text-align: center; margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,.1); }
    .register-footer a { color: #1d9bd1; text-decoration: none; font-weight: 500; transition: color .2s ease; }
    .register-footer a:hover { color: #0ea5e9; }
    .features-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 24px; }
    .feature-item { background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08); border-radius: 8px; padding: 16px 12px; text-align: center; }
    .feature-item-icon { font-size: 24px; margin-bottom: 8px; }
    .feature-item-text { color: rgba(255,255,255,.8); font-size: .8rem; font-weight: 500; }
  </style>
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
      <a href="register.php" class="active">Register</a>
    </nav>
  </div>

  <div class="register-container">
    <div class="register-hero">
      <div class="register-hero-icon">🚀</div>
      <h1>Join JARVIS</h1>
      <p>Your intelligent personal assistant for home automation, voice commands, and seamless control—powered by Simple Functioning Solutions.</p>
    </div>

    <div class="register-card">
      <h2>Create Your Account</h2>
      
      <?php if($errors):?>
        <div class="error-alert">
          <?php foreach($errors as $e){echo '<p>'.htmlspecialchars($e).'</p>';}?>
        </div>
      <?php endif;?>
      
      <form method="post">
        <div class="form-group">
          <label>Username</label>
          <input name="username" required placeholder="Choose a username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" />
        </div>

        <div class="form-group">
          <label>Email Address</label>
          <input name="email" type="email" required placeholder="your@email.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />
        </div>

        <div class="form-group">
          <label>Phone Number (Optional)</label>
          <input name="phone" type="tel" placeholder="+1 (555) 123-4567" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" />
          <small style="color:rgba(255,255,255,.6);font-size:.8rem;margin-top:4px;display:block;">For SMS notifications and two-factor authentication</small>
        </div>

        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" required placeholder="Create a strong password" />
        </div>

        <button type="submit" class="btn-register">Create Account</button>
      </form>

      <?php if ($googleConfigured): ?>
      <div class="divider"><span>OR</span></div>
      <a href="connect_google.php" class="btn-google">
        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
        Continue with Google
      </a>
      <?php else: ?>
      <div class="divider"><span>OR</span></div>
      <div class="btn-google google-disabled">
        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/></svg>
        Google Sign-in Not Configured
      </div>
      <?php endif; ?>

      <div class="register-footer">
        Already have an account? <a href="login.php">Sign in here</a>
      </div>
    </div>

    <div class="features-grid">
      <div class="feature-item">
        <div class="feature-item-icon">🏠</div>
        <div class="feature-item-text">Smart Home Control</div>
      </div>
      <div class="feature-item">
        <div class="feature-item-icon">🎤</div>
        <div class="feature-item-text">Voice Commands</div>
      </div>
      <div class="feature-item">
        <div class="feature-item-icon">📱</div>
        <div class="feature-item-text">Mobile Ready</div>
      </div>
    </div>
  </div>

  <script src="navbar.js"></script>
</body></html>
