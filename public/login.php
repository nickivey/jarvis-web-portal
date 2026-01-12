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
    <button class="nav-toggle" id="navToggle" aria-label="Open menu">☰</button>
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
      <form method="post" id="loginForm">
        <label>Username</label>
        <input name="username" required />
        <label>Password</label>
        <input type="password" name="password" required />
        <input type="hidden" name="lat" id="lat">
        <input type="hidden" name="lon" id="lon">
        <input type="hidden" name="accuracy" id="accuracy">
        <button class="btn" type="submit">Enter JARVIS</button>
      </form>

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
