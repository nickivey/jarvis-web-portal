<?php
session_start();
$u = $_SESSION['pending_user'] ?? null;
unset($_SESSION['pending_user']);
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Confirm Email</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <div class="hero">
    <div class="overlay"></div>
    <h1>JARVIS</h1>
    <p>Confirmation packet dispatched</p>
  </div>
  <div class="container">
    <div class="card">
      <h2>Check your email</h2>
      <div class="success">
        <p>If an email service is configured, we sent a confirmation link<?php echo $u ? ' for <b>'.htmlspecialchars($u).'</b>' : ''; ?>.</p>
        <p>Open the link to activate your account, then return to login.</p>
        <p style="margin-top:12px;font-size:13px">Didn't receive it? <a href="verify_email.php" style="color:var(--blue2)">Click here to resend</a> (you'll need to log in first).</p>
      </div>
      <div class="nav-links"><a href="login.php">Back to login</a></div>
    </div>
  </div>
</body></html>
