<?php
session_start();
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$username = $_SESSION['username'];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = $scheme . '://' . $host;
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Add to Siri</title>
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
      <a href="home.php">Home</a>
      <a href="siri.php">Add to Siri</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>

  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>Add JARVIS to Siri</h1>
    <p>Blueprint for a voice shortcut that posts to your Slack channel through the JARVIS REST core.</p>
  </div>

  <div class="container">
    <div class="card">
      <h2>What this does</h2>
      <p class="muted">You’ll build a Shortcut that:</p>
      <ol>
        <li>Shows the <b>blue/black JARVIS sign-in screen</b> (optional)</li>
        <li>Prompts for a message (voice or typed)</li>
        <li>Sends it to your JARVIS REST endpoint</li>
        <li>JARVIS forwards it to Slack and logs it in MySQL</li>
      </ol>
    </div>

    <div class="card">
      <h2>Shortcut steps (Apple Shortcuts)</h2>
      <ol>
        <li>Open <b>Shortcuts</b> → tap <b>+</b> to create a new shortcut.</li>
        <li>(Optional) Add action: <b>Open URLs</b> → URL:
          <pre><code><?php echo htmlspecialchars($base); ?>/public/login.php</code></pre>
        </li>
        <li>Add action: <b>Ask for Input</b>
          <ul>
            <li>Prompt: <b>"JARVIS message"</b></li>
            <li>Input type: Text (or Dictation)</li>
          </ul>
        </li>
        <li>Add action: <b>Get Contents of URL</b>
          <ul>
            <li>URL: <pre><code><?php echo htmlspecialchars($base); ?>/api/messages</code></pre></li>
            <li>Method: <b>POST</b></li>
            <li>Request body: <b>JSON</b></li>
          </ul>
          JSON keys:
          <pre><code>{
  "username": "<?php echo htmlspecialchars($username); ?>",
  "message": "(Provided Input)",
  "channel": "(optional channel id)"
}</code></pre>
        </li>
        <li>Rename shortcut to: <b>JARVIS Message</b></li>
        <li>Tap settings → <b>Add to Siri</b> → record phrase: <b>"Hey Siri, JARVIS message"</b></li>
      </ol>
    </div>

    <div class="card">
      <h2>Pro tip</h2>
      <p class="muted">Keep your portal open on your phone for the “cool effects” feel—JARVIS’ blue nav and neon grid background matches the rest of the platform.</p>
      <p class="muted">If your server is remote, set <code>SITE_URL</code> to your public domain so confirmation emails and Siri links are correct.</p>
    </div>
  </div>
</body></html>
