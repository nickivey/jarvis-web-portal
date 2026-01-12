<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../instagram_basic.php';
require_once __DIR__ . '/../briefing.php';

if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$username = $_SESSION['username'];

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { session_destroy(); header('Location: login.php'); exit; }

$dbUser = jarvis_user_by_id($userId);
if (!$dbUser) { session_destroy(); header('Location: login.php'); exit; }
$isAdmin = (($dbUser['role'] ?? '') === 'admin');
$prefs = jarvis_preferences($userId);
$igToken = jarvis_oauth_get($userId, 'instagram');

// JWT for browser-side API calls (location, command sync, etc.)
$webJwt = null;
try { $webJwt = jarvis_jwt_issue($userId, $username, 3600); } catch (Throwable $e) { $webJwt = null; }


// Update last seen for auditing + wake logic
jarvis_update_last_seen($userId);

// Wake prompt if user has been away for >= 6 hours
$wakePrompt = null;
$wakeCards = null;
try {
  $last = $dbUser['last_login_at'] ?: $dbUser['last_seen_at'];
  if ($last) {
    $lastTs = strtotime($last . ' UTC');
    $awaySeconds = time() - ($lastTs ?: time());
    if ($awaySeconds >= 6*60*60) {
      $out = jarvis_compose_briefing($userId, 'wake');
      $wakePrompt = (string)$out['text'];
      $wakeCards = (array)($out['cards'] ?? []);
       // Log the wake greeting as a system command/response pair
       jarvis_log_command($userId, 'wake', 'User login after 6+ hours away', $wakePrompt, ['away_seconds'=>$awaySeconds,'cards'=>$wakeCards]);
       // Log to audit as a SYSTEM_WAKE_GREETING with full details
       jarvis_audit($userId, 'SYSTEM_WAKE_GREETING', 'system', [
         'away_seconds'=>$awaySeconds,
         'message'=>$wakePrompt,
         'notifications_unread'=>(int)($wakeCards['notifications_unread'] ?? 0),
         'slack_status'=>(string)($wakeCards['integrations']['slack'] ?? 'unknown'),
         'instagram_status'=>(string)($wakeCards['integrations']['instagram'] ?? 'unknown'),
       ]);
       // Create notification with comprehensive greeting
       jarvis_notify($userId, 'info', 'Welcome back! 👋 Wake sequence initiated', $wakePrompt, $wakeCards);
    }
  }
} catch (Throwable $e) {
  // non-fatal: $e->getMessage() could be logged if needed
}

$success = ''; $error = '';
$recentLocations = jarvis_recent_locations($userId, 20);
$lastWeather = null;
if (!empty($recentLocations) && isset($recentLocations[0]['lat']) && isset($recentLocations[0]['lon'])) {
  try { $lastWeather = jarvis_fetch_weather((float)$recentLocations[0]['lat'], (float)$recentLocations[0]['lon']); } catch (Throwable $e) { $lastWeather = null; }
}

function slack_post_message_portal(string $token, string $channel, string $text): array {
  $ch = curl_init('https://slack.com/api/chat.postMessage');
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>[
      'Authorization: Bearer ' . $token,
      'Content-Type: application/json; charset=utf-8',
    ],
    CURLOPT_POSTFIELDS=>json_encode(['channel'=>$channel,'text'=>$text])
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode($resp?:'',true);
  if (!is_array($data)) $data=['ok'=>false,'error'=>'invalid_json','raw'=>$resp];
  $data['_http']=$code;
  return $data;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['save_phone'])) {
    $phone = trim($_POST['phone_number'] ?? '') ?: null;
    $pdo = jarvis_pdo();
    if ($pdo) {
      $pdo->prepare('UPDATE users SET phone_e164=:p WHERE id=:id')->execute([':p'=>$phone, ':id'=>$userId]);
      jarvis_audit($userId, 'PROFILE_UPDATE', 'phone', ['phone_set'=>(bool)$phone]);
      $dbUser = jarvis_user_by_id($userId);
    }
    $success = 'Phone number saved.';
  }
}

// recent chat messages
$recent = jarvis_fetch_messages($userId, 50);
// recent calendar events (show a few)
$calendarEvents = jarvis_list_calendar_events($userId, 6);

$commands = jarvis_recent_commands($userId, 20);
$auditItems = jarvis_latest_audit($userId, 12);
$notifCount = jarvis_unread_notifications_count($userId);
$notifs = jarvis_recent_notifications($userId, 8);

// Bottom-right panel content
$restLogs = [];

$phone = (string)($dbUser['phone_e164'] ?? '');
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Portal</title>
  <link rel="stylesheet" href="style.css" />
  <!-- Leaflet map for location history -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
      <a href="home.php">Home</a>
      <a href="preferences.php">Preferences</a>
      <?php if (!empty($isAdmin)): ?>
        <a href="admin.php">Admin</a>
      <?php endif; ?>
      <a href="audit.php">Audit Log</a>
      <a href="notifications.php">Notifications</a>
      <a href="siri.php">Add to Siri</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>

  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>JARVIS</h1>
    <p>Blue / Black command portal • REST + Slack messaging • Timestamped logs</p>
  </div>

  <div class="container">
    <?php if($success):?><div class="success"><p><?php echo htmlspecialchars($success); ?></p></div><?php endif;?>
    <?php if($error):?><div class="error"><p><?php echo htmlspecialchars($error); ?></p></div><?php endif;?>

    <div class="grid">
      <!-- 1 -->
      <div class="card">
        <h3>Connection Status</h3>
        <p class="muted">Slack: <?php echo jarvis_setting_get('SLACK_BOT_TOKEN') || jarvis_setting_get('SLACK_APP_TOKEN') || getenv('SLACK_BOT_TOKEN') || getenv('SLACK_APP_TOKEN') ? 'Configured' : 'Not configured'; ?></p>
        <p class="muted">Instagram (Basic Display): <?php echo $igToken ? 'Connected' : 'Not connected'; ?></p>
        <p class="muted">MySQL: <?php echo jarvis_pdo() ? 'Connected' : 'Not configured / unavailable'; ?></p>
        <p class="muted">REST Base: <span class="badge">/api</span></p>
        <p class="muted">Notifications: <span class="badge"><?php echo (int)$notifCount; ?> unread</span></p>
        <p class="muted">Weather: <span id="jarvisWeather"><?php echo $lastWeather ? htmlspecialchars($lastWeather['desc'] . ' • ' . ($lastWeather['temp_c'] !== null ? $lastWeather['temp_c'].'°C' : '')) : '(enable location logging in Preferences)'; ?></span></p>
        <?php if ($wakePrompt): ?>
          <div class="terminal" style="margin-top:12px">
            <div class="term-title">JARVIS Wake Prompt</div>
            <pre><?php echo htmlspecialchars($wakePrompt); ?></pre>
          </div>
        <?php endif; ?>
      </div>

      <div class="card" id="locationCard">
        <h3>Location & Weather</h3>
        <?php if (empty($recentLocations)): ?>
          <p class="muted">No location data yet. Enable location logging in Preferences and allow location access in your browser.</p>
        <?php else: ?>
          <div id="map" style="height:240px;border:1px solid #ddd;margin-bottom:8px"></div>
          <div id="weatherSummary">
            <?php if ($lastWeather): ?>
              <p><strong><?php echo htmlspecialchars($lastWeather['desc'] ?? ''); ?></strong> — <?php echo ($lastWeather['temp_c'] !== null) ? htmlspecialchars($lastWeather['temp_c'].'°C') : ''; ?></p>
            <?php else: ?>
              <p class="muted">Weather data not available for last known location (configure OPENWEATHER_API_KEY in DB or env).</p>
            <?php endif; ?>
          </div>
          <details>
            <summary>Recent locations (latest first)</summary>
            <table style="width:100%;margin-top:8px">
              <thead><tr><th>When</th><th>Lat</th><th>Lon</th><th>Source</th></tr></thead>
              <tbody>
                <?php foreach($recentLocations as $loc): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($loc['created_at']); ?></td>
                    <td><?php echo htmlspecialchars($loc['lat']); ?></td>
                    <td><?php echo htmlspecialchars($loc['lon']); ?></td>
                    <td><?php echo htmlspecialchars($loc['source']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div style="margin-top:8px"><a href="location_history.php">View full history</a></div>
          </details>
        <?php endif; ?>
      </div>

      <!-- 2 -->
      <div class="card">
        <h3>JARVIS Chat</h3>
        <div class="chatbox">
          <div class="chatlog">
            <?php if(!$recent): ?>
              <p class="muted">No messages yet. Send your first message below.</p>
            <?php else: ?>
              <?php foreach($recent as $m): ?>
                <div class="msg me">
                  <div class="bubble">
                    <div><?php echo htmlspecialchars($m['message_text']); ?></div>
                    <div class="meta"><?php echo htmlspecialchars($m['created_at']); ?> • <?php echo htmlspecialchars($m['channel_id'] ?? ''); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <form method="post" class="chatinput" id="chatForm">
            <div style="display:flex;gap:8px;align-items:flex-end;">
              <textarea name="message" id="messageInput" placeholder="Type a message to Slack..." style="flex:1"></textarea>
              <div style="display:flex;flex-direction:column;gap:8px;">
                <button type="button" id="micBtn" class="btn" title="Start/Stop voice input">🎤</button>
                <button type="button" id="voiceCmdBtn" class="btn" title="Voice-only command">🎙️ Voice Cmd</button>
                <button type="submit" name="send_chat" value="1" id="sendBtn" class="btn">Send</button>
              </div>
            </div>
            <div style="margin-top:8px;display:flex;gap:12px;align-items:center;">
              <label style="font-size:13px"><input type="checkbox" id="enableTTS" checked /> Speak responses</label>
              <label style="font-size:13px"><input type="checkbox" id="enableNotif" checked /> Show notifications</label>
              <label style="font-size:13px"><input type="checkbox" id="voiceOnlyMode" /> Voice-only mode</label>
            </div>
          </form>
        </div>
      </div>

      <!-- 3 -->
      <div class="card">
        <h3>REST for .NET Desktop</h3>
        <p class="muted">Use these endpoints from a desktop app:</p>
        <pre><code>POST /api/auth/login
POST /api/command
POST /api/messages
POST /api/location

Example: send a Slack message
Content-Type: application/json
{
  "message": "Hello from .NET"
}</code></pre>
      </div>

      <!-- 4 -->
      <div class="card">
        <h3>User Settings</h3>
        <p class="muted">Manage Slack channel in <a href="preferences.php">Preferences</a>.</p>
        <form method="post" style="margin-top:12px">
          <label>Phone Number (for SMS)</label>
          <input name="phone_number" value="<?php echo htmlspecialchars($phone); ?>" placeholder="+1..." />
          <button type="submit" name="save_phone" value="1">Save Phone</button>
        </form>
      </div>

      <!-- 5 -->
      <div class="card">
        <h3>Shortcuts</h3>
        <p class="muted">Add “Hey Siri, JARVIS message” to send text hands-free.</p>
        <div class="nav-links"><a href="siri.php">Open Siri setup</a></div>
      </div>

      <!-- 6 (BOTTOM RIGHT) -->
      <div class="card">
        <h3>Audit & Notifications</h3>
        <p class="muted">All logins, actions, requests, and JARVIS responses are timestamped and stored in MySQL.</p>

        <div class="terminal" style="margin-top:10px">
          <div class="term-title">Recent Notifications</div>
          <div class="term-body" style="max-height:140px; overflow:auto">
            <?php if(!$notifs): ?>
              <p class="muted">No notifications yet.</p>
            <?php else: ?>
              <?php foreach($notifs as $n): ?>
                <div class="muted" style="margin-bottom:8px">
                  <b><?php echo htmlspecialchars($n['title']); ?></b>
                  <div><?php echo htmlspecialchars((string)($n['body'] ?? '')); ?></div>
                  <div class="meta"><?php echo htmlspecialchars($n['created_at']); ?><?php echo ((int)$n['is_read']===0) ? ' • UNREAD' : ''; ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="terminal" style="margin-top:10px">
          <div class="term-title">Recent Audit Events</div>
          <div class="term-body" style="max-height:140px; overflow:auto">
            <?php if(!$auditItems): ?>
              <p class="muted">No audit events yet.</p>
            <?php else: ?>
              <?php foreach($auditItems as $a): ?>
                <div class="muted" style="margin-bottom:8px">
                  <b><?php echo htmlspecialchars($a['action']); ?></b> <?php echo htmlspecialchars((string)($a['entity'] ?? '')); ?>
                  <div class="meta"><?php echo htmlspecialchars($a['created_at']); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <h3>Upcoming Calendar Events</h3>
      <?php if (empty($calendarEvents)): ?>
        <p class="muted">No upcoming events. Connect Google Calendar in Preferences.</p>
      <?php else: ?>
        <ul>
          <?php foreach($calendarEvents as $ce): ?>
            <li><strong><?php echo htmlspecialchars($ce['summary'] ?? '(no title)'); ?></strong>
              <div class="muted"><?php echo htmlspecialchars((string)($ce['start_dt'] ?? '')); ?> — <?php echo htmlspecialchars((string)($ce['location'] ?? '')); ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // Browser location -> /api/location (logged to MySQL)
    (function(){
      const enabled = <?php echo !empty($prefs['location_logging_enabled']) ? 'true' : 'false'; ?>;
      const token = <?php echo $webJwt ? json_encode($webJwt) : 'null'; ?>;
      const recentLocations = <?php echo json_encode(array_values($recentLocations)); ?>;
      const lastWeather = <?php echo json_encode($lastWeather); ?>;

      // Init map if we have locations
      async function initMap() {
        if (!(recentLocations && recentLocations.length && typeof L !== 'undefined')) return;
        const map = L.map('map');
        const last = recentLocations[0];
        map.setView([parseFloat(last.lat), parseFloat(last.lon)], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
        for (const r of recentLocations) {
          const marker = L.marker([parseFloat(r.lat), parseFloat(r.lon)]).addTo(map);
          marker.bindPopup(`<div><b>${r.source}</b><br>${r.created_at}<br>${parseFloat(r.lat).toFixed(5)}, ${parseFloat(r.lon).toFixed(5)}</div>`);
        }
      }

      if (enabled && token && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(async (pos)=>{
          try {
            const body = { lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy };
            const r = await fetch('/api/location', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer '+token },
              body: JSON.stringify(body)
            });
            const data = await r.json().catch(()=>null);
            const el = document.getElementById('jarvisWeather');
            if (el && data) {
              el.textContent = data.weather && data.weather.desc ? (data.weather.desc + ' • ' + (data.weather.temp_c !== null ? data.weather.temp_c + '°C' : '')) : ('Location saved: '+body.lat.toFixed(3)+', '+body.lon.toFixed(3));
            }
            // refresh map after new location
            if (typeof L !== 'undefined') setTimeout(initMap, 350);
          } catch (e) {}
        }, ()=>{ if (typeof L !== 'undefined') setTimeout(initMap, 50); }, { enableHighAccuracy: true, maximumAge: 10*60*1000, timeout: 8000 });
      } else {
        // just init map with existing locations
        if (typeof L !== 'undefined') setTimeout(initMap, 50);
      }

      // Pre-fill weather info if server-side fetched
      if (lastWeather && document.getElementById('jarvisWeather')) {
        document.getElementById('jarvisWeather').textContent = lastWeather.desc + ' • ' + (lastWeather.temp_c !== null ? lastWeather.temp_c + '°C' : '');
      }
    })();
  </script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="navbar.js"></script>
    // ----------------------------
    // Voice input / output + Notifications
    // ----------------------------
    (function(){
      const token = <?php echo $webJwt ? json_encode($webJwt) : 'null'; ?>;
      const chatLog = document.querySelector('.chatlog');
      const msgInput = document.getElementById('messageInput');
      const micBtn = document.getElementById('micBtn');
      const enableTTS = document.getElementById('enableTTS');
      const enableNotif = document.getElementById('enableNotif');
      const voiceOnlyMode = document.getElementById('voiceOnlyMode');
      let recognizing = false;
      let recognition = null;
      let lastInputType = 'text';

      // Request Notification permission up front (user gesture recommended)
      async function ensureNotificationPermission(){
        if (!('Notification' in window)) return false;
        if (Notification.permission === 'granted') return true;
        try {
          const p = await Notification.requestPermission();
          return p === 'granted';
        } catch(e){ return false; }
      }

      // Try to initialize Web Speech Recognition if available
      function initRecognition(){
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition || null;
        if (!SpeechRec) return null;
        const r = new SpeechRec();
        r.lang = navigator.language || 'en-US';
        r.interimResults = false;
        r.maxAlternatives = 1;
        r.onresult = async (evt) => {
          const text = evt.results[0][0].transcript || '';
          lastInputType = 'voice';
          if (voiceOnlyMode && voiceOnlyMode.checked) {
            if (!text.trim()) return;
            appendMessage(text, 'me');
            try {
              const r = await fetch('/api/command', {
                method: 'POST', headers: { 'Content-Type':'application/json', 'Authorization': token ? 'Bearer '+token : '' }, body: JSON.stringify({ text, type: 'voice' })
              });
              const data = await r.json().catch(()=>null);
              if (data && typeof data.jarvis_response === 'string' && data.jarvis_response.trim() !== ''){
                appendMessage(data.jarvis_response, 'jarvis');
                speakText(data.jarvis_response);
                showNotification(data.jarvis_response);
              }
            } catch(e) {}
          } else {
            msgInput.value = text;
          }
        };
        r.onend = () => { recognizing = false; micBtn.classList.remove('active'); };
        r.onerror = () => { recognizing = false; micBtn.classList.remove('active'); };
        return r;
      }

      // When user types, mark type as text
      msgInput.addEventListener('input', ()=>{ lastInputType = 'text'; });

      micBtn.addEventListener('click', async ()=>{
        if (recognizing) {
          if (recognition) recognition.stop();
          recognizing = false; micBtn.classList.remove('active');
          return;
        }
        // ask for microphone by starting recognition/getUserMedia
        if (!recognition) {
          recognition = initRecognition();
        }
        if (!recognition) {
          // Fallback: prompt for getUserMedia permission so user can record externally
          try { await navigator.mediaDevices.getUserMedia({audio:true}); }
          catch(e){ alert('Microphone not available or permission denied.'); return; }
        }
        try {
          if (recognition) recognition.start();
          recognizing = true; micBtn.classList.add('active');
        } catch(e) { recognizing=false; micBtn.classList.remove('active'); }
      });

      // Voice-only command: capture speech and immediately send to /api/command
      const voiceCmdBtn = document.getElementById('voiceCmdBtn');
      if (voiceCmdBtn) voiceCmdBtn.addEventListener('click', async ()=>{
        if (!recognition) recognition = initRecognition();
        if (!recognition) { alert('Voice recognition not supported in this browser.'); return; }
        recognition.onresult = async (evt) => {
          const text = evt.results[0][0].transcript || '';
          if (!text.trim()) return;
          appendMessage(text, 'me');
          lastInputType = 'voice';
          try {
            const r = await fetch('/api/command', {
              method: 'POST', headers: { 'Content-Type':'application/json', 'Authorization': token ? 'Bearer '+token : '' }, body: JSON.stringify({ text, type: 'voice' })
            });
            const data = await r.json().catch(()=>null);
            if (data && typeof data.jarvis_response === 'string' && data.jarvis_response.trim() !== ''){
              appendMessage(data.jarvis_response, 'jarvis');
              speakText(data.jarvis_response);
              showNotification(data.jarvis_response);
            }
          } catch(e) {}
        };
        try { recognition.start(); recognizing = true; voiceCmdBtn.classList.add('active'); }
        catch(e){ recognizing=false; voiceCmdBtn.classList.remove('active'); }
      });

      // Append message to chat log
      function appendMessage(text, who='jarvis'){
        const wrapper = document.createElement('div');
        wrapper.className = who === 'me' ? 'msg me' : 'msg jarvis';
        const bubble = document.createElement('div'); bubble.className='bubble';
        const content = document.createElement('div'); content.textContent = text;
        const meta = document.createElement('div'); meta.className='meta';
        const now = new Date(); meta.textContent = now.toISOString().replace('T',' ').replace('Z',' UTC');
        bubble.appendChild(content); bubble.appendChild(meta); wrapper.appendChild(bubble);
        if (chatLog) chatLog.appendChild(wrapper);
        // scroll
        if (chatLog) chatLog.parentNode.scrollTop = chatLog.parentNode.scrollHeight;
      }

      // Speak text using server-side TTS endpoint or fallback to Web SpeechSynthesis
      async function speakText(text){
        if (!enableTTS.checked || !text) return;
        // Try server-side TTS first
        try {
          const url = '/public/tts.php?text=' + encodeURIComponent(text);
          const audio = new Audio(url);
          await audio.play().catch(async (e)=>{
            // Fallback to speechSynthesis
            if ('speechSynthesis' in window) {
              const ut = new SpeechSynthesisUtterance(text);
              ut.lang = navigator.language || 'en-US';
              speechSynthesis.speak(ut);
            }
          });
          return;
        } catch(e){
          if ('speechSynthesis' in window) {
            const ut = new SpeechSynthesisUtterance(text);
            ut.lang = navigator.language || 'en-US';
            speechSynthesis.speak(ut);
          }
        }
      }

      // Show browser notification if allowed
      function showNotification(text){
        if (!enableNotif.checked || !('Notification' in window) || Notification.permission !== 'granted') return;
        try { new Notification('JARVIS', { body: text }); } catch(e){}
      }

      // Intercept chat form submit and use /api/command (Jarvis), fallback to /api/messages for Slack
      const chatForm = document.getElementById('chatForm');
      if (chatForm) chatForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const msg = (msgInput.value || '').trim();
        if (!msg) return;
        appendMessage(msg, 'me');
        msgInput.value = '';

        // Call /api/command
        try {
          const r = await fetch('/api/command', {
            method: 'POST', headers: { 'Content-Type':'application/json', 'Authorization': token ? 'Bearer '+token : '' }, body: JSON.stringify({ text: msg, type: lastInputType })
          });
          const data = await r.json().catch(()=>null);
          if (data && typeof data.jarvis_response === 'string' && data.jarvis_response.trim() !== ''){
            appendMessage(data.jarvis_response, 'jarvis');
            speakText(data.jarvis_response);
            showNotification(data.jarvis_response);
            return;
          }
        } catch(e){}

        // Fallback: send to Slack via /api/messages if JWT available (uses default channel)
        try {
          const r2 = await fetch('/api/messages', {
            method: 'POST', headers: { 'Content-Type':'application/json', 'Authorization': token ? 'Bearer '+token : '' }, body: JSON.stringify({ message: msg })
          });
          const data2 = await r2.json().catch(()=>null);
          if (data2 && data2.ok) {
            appendMessage('Sent to Slack (default channel)', 'jarvis');
            if (enableNotif.checked && Notification.permission === 'granted') showNotification('Slack message sent: '+msg);
          } else {
            appendMessage('Failed to send to Slack', 'jarvis');
          }
        } catch(e){ appendMessage('Failed to send message', 'jarvis'); }
      });

      // On load, request notification permission quietly
      (async ()=>{ if ('Notification' in window) await ensureNotificationPermission(); })();

    })();
  </script>
</body></html>
