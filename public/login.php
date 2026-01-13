<?php
session_start();
require_once __DIR__ . '/../db.php';
if (isset($_SESSION['username'])) { header('Location: home.php'); exit; }

$errors=[];
$externalErrors = [];
$showResendPrompt = false;
$resendEmail = '';

// Surface OAuth/provider errors when redirected back (e.g., access_denied, token_exchange failures)
if (isset($_GET['error'])) {
  $err = $_GET['error'];
  if ($err === 'google_access_denied') {
    $externalErrors[] = 'Google access was denied or blocked. Check OAuth settings and Redirect URI in Admin > Settings.';
  } elseif ($err === 'token_exchange') {
    $externalErrors[] = 'OAuth token exchange failed. Check client secret and redirect URI.';
  } else {
    $externalErrors[] = 'Authentication error: ' . htmlspecialchars($err);
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u=trim((string)($_POST['email']??''));
  $p=(string)($_POST['password']??'');
  $user = $u ? jarvis_user_by_email($u) : null;
  // Auto-provision demo user on demand (private env convenience)
  if ((!$user || empty($user['id'])) && strcasecmp($u, 'demo@example.com')===0 && $p==='password') {
    try {
      $existing = jarvis_user_by_username('demo');
      $pwHash = password_hash('password', PASSWORD_DEFAULT);
      if ($existing && !empty($existing['id'])) {
        $pdo = jarvis_pdo(); if ($pdo) {
          $pdo->prepare('UPDATE users SET email=:e, password_hash=:p, email_verified_at=NOW() WHERE id=:id')
              ->execute([':e'=>'demo@example.com', ':p'=>$pwHash, ':id'=>(int)$existing['id']]);
          $user = jarvis_user_by_email('demo@example.com');
        }
      } else {
        $uid = jarvis_create_user('demo', 'demo@example.com', null, $pwHash, bin2hex(random_bytes(12)));
        jarvis_mark_email_verified($uid);
        $user = jarvis_user_by_email('demo@example.com');
      }
    } catch (Throwable $e) { /* fall through to normal error handling */ }
  }
  if (!$u || !$p || !$user || !password_verify($p, $user['password_hash'] ?? '')) {
    $errors[]='Invalid email or password.';
    jarvis_audit($user['id'] ?? null, 'LOGIN_FAIL', 'auth', ['email'=>$u]);
  } elseif (empty($user['email_verified_at'])) {
    $errors[]='Please confirm your email before logging in.';
    // Prompt the UI to offer resend (preserve the entered email)
    $showResendPrompt = true;
    $resendEmail = $u;
  } else {
    // Preserve username for display throughout the app, but authenticate by email
    $_SESSION['username']=$user['username'];
    $_SESSION['user_id']=(int)$user['id'];
    jarvis_update_last_login((int)$user['id']);

    // If client provided geolocation at sign-on, record it
    $lat = isset($_POST['lat']) ? (float)$_POST['lat'] : 0.0;
    $lon = isset($_POST['lon']) ? (float)$_POST['lon'] : 0.0;
    $acc = isset($_POST['accuracy']) ? (float)$_POST['accuracy'] : null;
    if ($lat && $lon) {
      $pdo = jarvis_pdo();
      if ($pdo) {
        $stmt = $pdo->prepare('INSERT INTO location_logs (user_id,lat,lon,accuracy_m,source) VALUES (:u,:la,:lo,:a,:s)');
        $stmt->execute([':u'=>$user['id'], ':la'=>$lat, ':lo'=>$lon, ':a'=>$acc, ':s'=>'login']);
        $locId = (int)$pdo->lastInsertId();
        jarvis_audit((int)$user['id'], 'LOCATION_AT_LOGIN', 'location', ['lat'=>$lat,'lon'=>$lon,'accuracy'=>$acc,'location_id'=>$locId]);
      }
    }

    jarvis_audit((int)$user['id'], 'LOGIN_SUCCESS', 'auth', null);
    header('Location: home.php'); exit;
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS Login • Secure Portal Access | Simple Functioning Solutions</title>
  <meta name="description" content="Access your JARVIS command center—control smart devices, manage photos & videos, send voice commands, and stay connected from anywhere. Secure login powered by Simple Functioning Solutions in Orlando." />
  <meta name="author" content="Simple Functioning Solutions" />
  <meta name="robots" content="noindex, nofollow" />
  <meta property="og:title" content="Sign in to JARVIS" />
  <meta property="og:description" content="Your intelligent home automation and personal assistant platform awaits." />
  <link rel="stylesheet" href="style.css" />
  <style>
    .login-hero {
      position: relative;
      min-height: 40vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 60px 20px 40px;
      overflow: hidden;
    }
    .login-hero::before {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(600px 400px at 50% 50%, rgba(30,120,255,.28), transparent 70%);
      opacity: 0.5;
      pointer-events: none;
    }
    .login-hero h1 {
      font-size: 3.5rem;
      font-weight: 800;
      letter-spacing: 0.05em;
      margin: 0;
      text-shadow: 0 0 40px rgba(0,212,255,0.6), 0 0 20px rgba(30,120,255,0.4);
      position: relative;
      z-index: 1;
    }
    .login-hero p {
      font-size: 1.1rem;
      color: var(--muted);
      margin: 12px 0 0;
      position: relative;
      z-index: 1;
    }
    .login-container {
      max-width: 480px;
      margin: -40px auto 60px;
      padding: 0 20px;
      position: relative;
      z-index: 10;
    }
    .login-card {
      background: linear-gradient(135deg, rgba(7,18,42,.95), rgba(5,16,42,.92));
      border: 1px solid rgba(30,120,255,.35);
      border-radius: 12px;
      padding: 32px;
      box-shadow: 0 20px 60px rgba(0,0,0,.7), 0 0 0 1px rgba(0,212,255,.1) inset;
      transition: transform .2s ease, box-shadow .3s ease;
      position: relative;
      overflow: hidden;
    }
    .login-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, transparent, rgba(0,212,255,.6), transparent);
      opacity: 0.8;
    }
    .login-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 24px 80px rgba(0,0,0,.8), 0 0 40px rgba(0,212,255,.2);
    }
    .login-card h2 {
      margin: 0 0 8px;
      font-size: 1.75rem;
      font-weight: 700;
      background: linear-gradient(135deg, #fff, rgba(0,212,255,.95));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .login-card .subtitle {
      color: var(--muted);
      font-size: 0.9rem;
      margin-bottom: 24px;
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: rgba(255,255,255,.9);
      font-size: 0.9rem;
      font-weight: 500;
    }
    .form-group input {
      width: 100%;
      padding: 12px 14px;
      background: rgba(2,7,18,.7);
      border: 1px solid rgba(30,120,255,.3);
      border-radius: 6px;
      color: var(--txt);
      font-size: 1rem;
      transition: all .2s ease;
    }
    .form-group input:focus {
      border-color: rgba(0,212,255,.6);
      box-shadow: 0 0 0 3px rgba(0,212,255,.15), 0 0 20px rgba(0,212,255,.1);
      outline: none;
    }
    .btn-login {
      width: 100%;
      padding: 14px;
      background: linear-gradient(135deg, rgba(30,120,255,.85), rgba(0,212,255,.7));
      border: 1px solid rgba(0,212,255,.5);
      border-radius: 6px;
      color: #fff;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all .2s ease;
      box-shadow: 0 4px 16px rgba(0,212,255,.2);
    }
    .btn-login:hover {
      background: linear-gradient(135deg, rgba(30,120,255,.95), rgba(0,212,255,.85));
      transform: translateY(-2px);
      box-shadow: 0 6px 24px rgba(0,212,255,.4);
    }
    .btn-login:active {
      transform: translateY(0);
    }
    .divider {
      display: flex;
      align-items: center;
      margin: 24px 0;
      color: var(--muted);
      font-size: 0.85rem;
    }
    .divider::before,
    .divider::after {
      content: "";
      flex: 1;
      height: 1px;
      background: rgba(30,120,255,.2);
    }
    .divider span {
      padding: 0 12px;
    }
    .google-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      padding: 12px;
      background: rgba(255,255,255,.95);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 6px;
      color: #222;
      font-weight: 600;
      text-decoration: none;
      transition: all .2s ease;
    }
    .google-btn:hover {
      background: #fff;
      transform: translateY(-2px);
      box-shadow: 0 4px 16px rgba(255,255,255,.2);
    }
    .footer-links {
      text-align: center;
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid rgba(30,120,255,.15);
    }
    .footer-links a {
      color: var(--blue2);
      text-decoration: none;
      font-size: 0.9rem;
      transition: color .2s ease;
    }
    .footer-links a:hover {
      color: #fff;
      text-decoration: underline;
    }
    .footer-links span {
      color: var(--muted);
      margin: 0 8px;
    }
    .resend-section {
      margin-top: 20px;
      padding: 16px;
      background: rgba(30,120,255,.08);
      border: 1px solid rgba(30,120,255,.2);
      border-radius: 6px;
      text-align: center;
    }
    .resend-section p {
      margin: 0 0 12px;
      color: var(--muted);
      font-size: 0.9rem;
    }
    .btn-secondary {
      padding: 10px 20px;
      background: transparent;
      border: 1px solid rgba(30,120,255,.4);
      border-radius: 6px;
      color: var(--txt);
      font-weight: 500;
      cursor: pointer;
      transition: all .2s ease;
    }
    .btn-secondary:hover {
      background: rgba(30,120,255,.15);
      border-color: rgba(0,212,255,.5);
    }
  </style>
</head>
<body>
  <canvas id="fxCanvas" class="fx-canvas" aria-hidden="true"></canvas>
  
  <div class="navbar">
    <div class="brand">
      <img src="images/logo.svg" alt="JARVIS logo" />
      <span class="dot" aria-hidden="true"></span>
      <span>JARVIS</span>
    </div>
    <button class="nav-toggle" id="navToggle" aria-label="Open menu">☰</button>
    <nav>
      <a href="login.php" class="active">Login</a>
      <a href="register.php">Register</a>
    </nav>
  </div>

  <div class="login-hero">
    <div class="scanlines" aria-hidden="true"></div>
    <h1>JARVIS</h1>
    <p>AI Command Center • Secure Access Portal</p>
  </div>

  <div class="login-container">
    <div class="login-card">
      <h2>Welcome Back</h2>
      <p class="subtitle">Sign in to access your intelligent command center</p>
      
      <?php if($errors):?>
        <div class="error" style="margin-bottom:20px">
          <?php foreach($errors as $e){echo '<p>'.htmlspecialchars($e).'</p>';}?>
        </div>
      <?php endif;?>
      
      <?php if(!empty($externalErrors)):?>
        <div class="error" style="margin-bottom:20px">
          <?php foreach($externalErrors as $e){echo '<p>'.htmlspecialchars($e).'</p>';}?>
        </div>
      <?php endif;?>
      
      <form method="post" id="loginForm">
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" required value="<?php echo htmlspecialchars($u ?? '', ENT_QUOTES); ?>" placeholder="your.email@example.com" />
        </div>
        
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" required placeholder="••••••••" />
        </div>
        
        <input type="hidden" name="lat" id="lat">
        <input type="hidden" name="lon" id="lon">
        <input type="hidden" name="accuracy" id="accuracy">
        
        <button class="btn-login" type="submit">Enter JARVIS</button>
      </form>

      <?php if (!empty($showResendPrompt) && $resendEmail): ?>
        <div class="resend-section">
          <p>Didn't receive your confirmation email?</p>
          <form method="post" action="resend_confirmation.php" id="resendInlineForm" style="display:inline-block">
            <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($resendEmail, ENT_QUOTES); ?>">
            <button class="btn-secondary" type="submit" id="resendInlineBtn">Resend confirmation email</button>
          </form>
          <div id="resendInlineMessage" style="margin-top:12px"></div>
        </div>

        <script>
        (function(){
          const form = document.getElementById('resendInlineForm');
          const btn = document.getElementById('resendInlineBtn');
          const msg = document.getElementById('resendInlineMessage');
          if (form && btn && msg) {
            form.addEventListener('submit', function(e){
              e.preventDefault();
              msg.innerHTML = '';
              btn.disabled = true;
              if (window.jarvisShowLoader) jarvisShowLoader();
              const fd = new FormData(form);
              fetch('resend_confirmation.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd
              }).then(r=>r.json()).then(data=>{
                if (data && data.success) {
                  msg.innerHTML = '<div class="success"><p>' + (data.message || 'Confirmation email resent.') + '</p></div>';
                } else {
                  msg.innerHTML = '<div class="error"><p>' + (data.message || 'Failed to resend confirmation.') + '</p></div>';
                }
              }).catch(err=>{
                msg.innerHTML = '<div class="error"><p>Network error. Try again later.</p></div>';
              }).finally(()=>{
                btn.disabled = false;
                if (window.jarvisHideLoader) jarvisHideLoader();
              });
            });
          }
        })();
        </script>
      <?php endif; ?>

      <script>
        (function(){
          const form = document.getElementById('loginForm');
          form.addEventListener('submit', function(e){
            // short-circuit to gather geolocation first (3s timeout)
            if (!navigator.geolocation) return; // same as no-op
            e.preventDefault();
            let submitted = false;
            const submitNow = ()=>{ if (!submitted) { submitted = true; form.submit(); } };
            const done = (pos)=>{
              document.getElementById('lat').value = pos.coords.latitude;
              document.getElementById('lon').value = pos.coords.longitude;
              document.getElementById('accuracy').value = pos.coords.accuracy;
              submitNow();
            };
            const fail = ()=>{ submitNow(); };
            try {
              navigator.geolocation.getCurrentPosition(done, fail, {maximumAge:60000, timeout:3000, enableHighAccuracy:true});
              // ensure we don't wait forever
              setTimeout(submitNow, 3500);
            } catch (e) { submitNow(); }
          });
        })();
      </script>

      <?php $googleConfigured = (bool)(jarvis_setting_get('GOOGLE_CLIENT_ID') && jarvis_setting_get('GOOGLE_CLIENT_SECRET')) || (bool)(getenv('GOOGLE_CLIENT_ID') && getenv('GOOGLE_CLIENT_SECRET')); ?>
      <?php if ($googleConfigured): ?>
        <div class="divider"><span>or continue with</span></div>
        <a href="connect_google.php" class="google-btn">
          <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
            <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z" fill="#4285F4"/>
            <path d="M9.003 18c2.43 0 4.467-.806 5.956-2.18L12.05 13.56c-.806.54-1.836.86-3.047.86-2.344 0-4.328-1.584-5.036-3.711H.96v2.332C2.44 15.983 5.485 18 9.003 18z" fill="#34A853"/>
            <path d="M3.964 10.71c-.18-.54-.282-1.117-.282-1.71 0-.593.102-1.17.282-1.71V4.958H.957C.347 6.173 0 7.548 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
            <path d="M9.003 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.464.891 11.426 0 9.003 0 5.485 0 2.44 2.017.96 4.958L3.967 7.29c.708-2.127 2.692-3.71 5.036-3.71z" fill="#EA4335"/>
          </svg>
          Sign in with Google
        </a>
      <?php endif; ?>

      <div class="footer-links">
        <a href="register.php">Create an account</a>
        <span>•</span>
        <a href="forgot_password.php">Forgot password?</a>
      </div>
      
      <div style="margin-top:20px;padding-top:20px;border-top:1px solid rgba(30,120,255,.1);text-align:center">
        <button class="btn-secondary" id="demoLoginBtn" type="button" style="width:100%">
          🚀 Quick Demo Login
        </button>
      </div>
    </div>
  </div>

  <script>
  // Pixel dust canvas animation (ambient particles)
  (function(){
    const cvs = document.getElementById('fxCanvas');
    if (!cvs) return;
    cvs.width = window.innerWidth; cvs.height = window.innerHeight;
    const ctx = cvs.getContext('2d');
    const particles = [];
    const maxParticles = 80;

    class Particle {
      constructor(x, y, opts = {}) {
        this.x = x; this.y = y;
        this.vx = (Math.random() - 0.5) * (opts.power || 1);
        this.vy = (Math.random() - 0.5) * (opts.power || 1) - Math.random() * 0.5;
        this.size = (Math.random() * 1.2 + 0.8) * (opts.size || 1);
        this.life = (Math.random() * 0.8 + 1.0) * (opts.life || 1);
        this.alpha = 1;
        this.hue = 190 + Math.random() * 20;
      }
      update(dt) {
        this.x += this.vx * 60 * dt;
        this.y += this.vy * 60 * dt;
        this.vy += 0.3 * dt;
        this.alpha -= dt / this.life;
        return this.alpha > 0;
      }
    }

    function spawn(x, y, opts = {}) {
      const count = opts.count || 6;
      for (let i = 0; i < count; i++) {
        if (particles.length < maxParticles * 2) {
          particles.push(new Particle(x, y, opts));
        }
      }
    }

    function ambient() {
      if (Math.random() < 0.08 && particles.length < maxParticles) {
        spawn(Math.random() * cvs.width, Math.random() * cvs.height, { power: 0.4, size: 0.9, life: 2 });
      }
    }

    function step(dt) {
      for (let i = particles.length - 1; i >= 0; i--) {
        if (!particles[i].update(dt)) particles.splice(i, 1);
      }
    }

    function draw() {
      ctx.clearRect(0, 0, cvs.width, cvs.height);
      for (const p of particles) {
        if (p.alpha <= 0) continue;
        ctx.globalAlpha = p.alpha;
        ctx.fillStyle = `hsl(${p.hue}, 100%, 60%)`;
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
        ctx.fill();
        ctx.globalAlpha = Math.max(0, Math.min(0.25, p.alpha * 0.25));
        ctx.fillStyle = 'rgba(0,212,255,0.15)';
        ctx.beginPath();
        ctx.arc(p.x, p.y, Math.max(1.5, p.size * 1.6), 0, Math.PI * 2);
        ctx.fill();
        ctx.globalAlpha = 1;
      }
    }

    let last = performance.now();
    function loop(now) {
      const dt = Math.min(0.06, (now - last) / 1000);
      last = now;
      ambient();
      step(dt);
      draw();
      requestAnimationFrame(loop);
    }
    requestAnimationFrame(loop);

    window.addEventListener('resize', () => {
      cvs.width = window.innerWidth;
      cvs.height = window.innerHeight;
    });

    // Cursor sparkle
    let lastX = 0, lastY = 0;
    window.addEventListener('pointermove', (ev) => {
      const x = ev.clientX, y = ev.clientY;
      const speed = Math.hypot(x - lastX, y - lastY);
      lastX = x; lastY = y;
      if (speed > 2) {
        spawn(x, y, { count: Math.min(4, Math.max(1, Math.floor(speed * 0.03))), power: 0.5, size: 0.7, life: 0.8 });
      }
    }, { passive: true });

    // Initial hero sparkle
    setTimeout(() => {
      const hero = document.querySelector('.login-hero h1');
      if (hero) {
        const r = hero.getBoundingClientRect();
        spawn(r.left + r.width * 0.5, r.top + r.height * 0.5, { count: 40, power: 1.5, size: 1.2, life: 1.8 });
      }
    }, 300);

    // Login button sparkle on click
    const loginBtn = document.querySelector('.btn-login');
    if (loginBtn) {
      loginBtn.addEventListener('click', () => {
        const r = loginBtn.getBoundingClientRect();
        spawn(r.left + r.width * 0.5, r.top + r.height * 0.5, { count: 30, power: 1.3, size: 1.1, life: 1.5 });
      });
    }
  })();
  </script>

  <script src="navbar.js"></script>
  <script>
  (function(){
    const btn = document.getElementById('demoLoginBtn');
    const form = document.getElementById('loginForm');
    if (btn && form) {
      btn.addEventListener('click', function(){
        try {
          form.querySelector('input[name="email"]').value = 'demo@example.com';
          form.querySelector('input[name="password"]').value = 'password';
        } catch(e){}
        form.submit();
      });
    }
  })();
  </script>
</body></html>
