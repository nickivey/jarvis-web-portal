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

// Create event reminders for upcoming events
try {
  jarvis_create_event_reminders($userId);
} catch (Throwable $e) {
  // non-fatal
}

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
$weatherConfigured = (bool)(jarvis_setting_get('OPENWEATHER_API_KEY') ?: getenv('OPENWEATHER_API_KEY') ?: getenv('OPENWEATHER_API_KEY_DEFAULT'));
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
$notifs = jarvis_recent_notifications_enhanced($userId, 12);
$upcomingEvents = jarvis_get_upcoming_events_for_reminders($userId);

// Google Calendar connection status
$googleToken = jarvis_oauth_get($userId, 'google');
$googleCalendarConnected = !empty($googleToken) && !empty($googleToken['access_token']);

// Local events for user (from user_local_events table)
$localEvents = jarvis_list_local_events($userId, 10);

// Bottom-right panel content
$restLogs = [];

$phone = (string)($dbUser['phone_e164'] ?? '');
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
  <meta name="theme-color" content="#1e78ff" />
  
  <!-- Primary Meta Tags -->
  <title>JARVIS Portal • AI Command Center | Simple Functioning Solutions - Orlando</title>
  <meta name="title" content="JARVIS Portal • AI Command Center | Smart Home Automation" />
  <meta name="description" content="Your intelligent command center for smart home automation, voice control, media management, and real-time notifications. JARVIS brings AI-powered simplicity to your connected life—built by Simple Functioning Solutions in Orlando." />
  <meta name="keywords" content="home automation, AI assistant, voice control, smart home, media management, IoT control, Orlando, Simple Functioning Solutions, JARVIS, command center" />
  <meta name="author" content="Simple Functioning Solutions" />
  
  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website" />
  <meta property="og:url" content="https://jarvis.simplefunctioningsolutions.com/" />
  <meta property="og:title" content="JARVIS Portal • AI-Powered Smart Home Command Center" />
  <meta property="og:description" content="Control your smart home with voice commands, manage media, track locations, and stay connected—all from one intelligent platform." />
  <meta property="og:image" content="https://jarvis.simplefunctioningsolutions.com/images/social/og-image.svg" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:image:alt" content="JARVIS AI Command Center Dashboard" />
  <meta property="og:site_name" content="JARVIS by Simple Functioning Solutions" />
  <meta property="og:locale" content="en_US" />
  
  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:url" content="https://jarvis.simplefunctioningsolutions.com/" />
  <meta name="twitter:title" content="JARVIS Portal • AI-Powered Smart Home Command Center" />
  <meta name="twitter:description" content="Control your smart home with voice commands, manage media, track locations, and stay connected—all from one intelligent AI platform." />
  <meta name="twitter:image" content="https://jarvis.simplefunctioningsolutions.com/images/social/og-image.svg" />
  <meta name="twitter:image:alt" content="JARVIS AI Command Center Dashboard" />
  
  <!-- Favicons & App Icons -->
  <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
  <link rel="icon" type="image/png" sizes="32x32" href="/images/app_icons/icon-32x32.svg" />
  <link rel="icon" type="image/png" sizes="16x16" href="/images/app_icons/icon-16x16.svg" />
  <link rel="apple-touch-icon" sizes="180x180" href="/images/app_icons/apple-touch-icon.svg" />
  <link rel="manifest" href="/manifest.json" />
  
  <!-- iOS Meta Tags -->
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="JARVIS" />
  
  <!-- Microsoft Tiles -->
  <meta name="msapplication-TileColor" content="#1e78ff" />
  <meta name="msapplication-TileImage" content="/images/app_icons/icon-144x144.svg" />
  <meta name="msapplication-config" content="/browserconfig.xml" />
  
  <!-- Canonical URL -->
  <link rel="canonical" href="https://jarvis.simplefunctioningsolutions.com/" />
  
  <link rel="stylesheet" href="style.css" />
  <!-- Using embedded third-party maps (Google Maps iframe) for location previews -->
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>JARVIS</h1>
    <p>Your intelligent personal assistant • Voice-powered smart home control • Seamless media & location management • Real-time notifications & communications</p>
  </div>
  <!-- Pixel Dust FX Canvas -->
  <canvas id="fxCanvas" class="fx-canvas" aria-hidden="true"></canvas>

  <div class="container">
    <!-- Welcome Greeting Dialog (appears on login) -->
    <div id="welcomeGreetingDialog" class="welcome-greeting-modal" style="display:none">
      <div class="welcome-greeting-content">
        <div class="greeting-header">
          <h2>🤖 Welcome Back, <?php echo htmlspecialchars($username); ?></h2>
          <p class="greeting-subtitle">JARVIS Command Center Online</p>
        </div>
        <div class="greeting-message" id="greetingMessage">
          <p id="greetingText"></p>
        </div>
        <div class="greeting-controls">
          <button type="button" id="playGreetingBtn" class="btn btn-primary" title="Play greeting">
            <span id="playBtnIcon">🔊</span> <span id="playBtnText">Play Greeting</span>
          </button>
          <button type="button" id="closeGreetingBtn" class="btn btn-secondary">Continue</button>
        </div>
        <audio id="greetingAudio" preload="auto"></audio>
      </div>
    </div>

    <!-- Permission banner (hidden when not needed) -->
    <div id="permBanner" class="perm-banner" style="display:none">
      <div class="list"><div style="font-weight:700">Permissions required:</div><div id="permList" style="display:flex;gap:8px;align-items:center;margin-left:8px"></div></div>
      <div style="display:flex;gap:8px;align-items:center"><button id="permRequestBtn" class="perm-cta">Request access</button><a href="#perminfo" style="color:var(--muted);font-size:13px;text-decoration:underline">How to allow</a></div>
    </div>

    <?php if($success):?><div class="success"><p><?php echo htmlspecialchars($success); ?></p></div><?php endif;?>
    <?php if($error):?><div class="error"><p><?php echo htmlspecialchars($error); ?></p></div><?php endif;?>

    <?php if (!$googleCalendarConnected): ?>
    <div class="calendar-connect-banner">
      <div class="banner-icon">📅</div>
      <div class="banner-content">
        <strong>Google Calendar Not Connected</strong>
        <p>Connect your Google Calendar to sync events and receive reminders.</p>
      </div>
      <a href="connect_google.php" class="btn">Connect Google Calendar</a>
    </div>
    <?php endif; ?>

    <div class="grid">
      <!-- JARVIS Chat: full-width first row -->
      <div class="card wide" id="commandCard">
        <h3>JARVIS Chat</h3>
        <div class="chatbox">
          <form class="chatinput" id="chatForm">
            <div style="display:flex;flex-direction:column;gap:8px;">
              <textarea name="message" id="messageInput" placeholder="Type a message to JARVIS..." style="flex:1;min-height:56px"></textarea>
              <div style="margin-top:6px"><small class="muted">Tip: Press <b>Enter</b> to send. Use <b>Shift+Enter</b> to insert a newline.</small></div>
              <div style="display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:6px">
                <div style="display:flex;gap:8px;align-items:center">
                  <button type="button" id="micBtn" class="btn" title="Start/Stop voice input">🎤</button>
                  <button type="button" id="voiceCmdBtn" class="btn" title="Voice-only command">🎙️ Voice Cmd</button>
                  <button type="button" id="videoBtn" class="btn video-btn" title="Record selfie video message">📹 Video</button>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                  <button type="submit" name="send_chat" value="1" id="sendBtn" class="btn">Send</button>
                </div>
              </div>
              <div style="margin-top:8px;display:flex;gap:12px;align-items:center;justify-content:flex-start;flex-wrap:wrap">
                <label style="font-size:13px"><input type="checkbox" id="enableTTS" checked /> Speak responses</label>
                <label style="font-size:13px"><input type="checkbox" id="enableNotif" checked /> Show notifications</label>
                <label style="font-size:13px"><input type="checkbox" id="voiceOnlyMode" /> Voice-only mode</label>
              </div>
            </div>
          </form>
          
          <!-- Video Recording Modal -->
          <div id="videoRecordingModal" class="video-recording-modal" style="display:none">
            <div class="video-modal-content">
              <div class="video-modal-header">
                <h3>📹 Record Video Message</h3>
                <button type="button" id="closeVideoModal" class="video-modal-close">&times;</button>
              </div>
              <div class="video-preview-container">
                <video id="videoPreview" autoplay muted playsinline></video>
                <div class="video-recording-indicator" id="videoRecIndicator" style="display:none">
                  <span class="rec-dot"></span> REC
                </div>
                <div class="video-timer" id="videoTimer">00:00</div>
              </div>
              <div class="video-controls">
                <button type="button" id="startVideoRecBtn" class="btn video-rec-btn">🔴 Start Recording</button>
                <button type="button" id="stopVideoRecBtn" class="btn video-stop-btn" style="display:none">⏹️ Stop</button>
                <button type="button" id="switchCameraBtn" class="btn video-switch-btn" title="Switch Camera">🔄</button>
              </div>
              <div class="video-status" id="videoStatus"></div>
            </div>
          </div>

          <div id="jarvisChatLog" class="chatlog">
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
        </div>
      </div>

      <!-- 1 -->
      <div class="card notification-card">
        <div class="card-header-row">
          <h3>🔔 Notifications</h3>
          <?php if ($notifCount > 0): ?>
            <span class="notif-badge"><?php echo (int)$notifCount; ?> unread</span>
          <?php endif; ?>
          <a href="notifications.php" class="btn btn-sm secondary" style="margin-left:auto">View All</a>
        </div>

        <?php if (!empty($upcomingEvents)): ?>
        <div class="upcoming-events-alert">
          <div class="alert-header">
            <span class="alert-icon">📅</span>
            <span class="alert-title">Upcoming Events</span>
          </div>
          <div class="upcoming-events-list">
            <?php foreach (array_slice($upcomingEvents, 0, 3) as $evt): ?>
              <div class="upcoming-event-item <?php echo $evt['is_today'] ? 'today' : 'tomorrow'; ?>">
                <div class="event-badge"><?php echo $evt['is_today'] ? 'Today' : 'Tomorrow'; ?></div>
                <div class="event-info">
                  <strong><?php echo htmlspecialchars($evt['title']); ?></strong>
                  <span class="event-time"><?php echo $evt['time'] ? date('g:i A', strtotime($evt['time'])) : 'All day'; ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="notifications-list" id="notifList">
          <?php if(!$notifs): ?>
            <p class="muted">No notifications yet.</p>
          <?php else: ?>
            <?php foreach($notifs as $n): ?>
              <div class="notification-item <?php echo ((int)$n['is_read'] === 0) ? 'unread' : 'read'; ?> type-<?php echo htmlspecialchars($n['type']); ?>">
                <div class="notif-icon"><?php echo $n['icon']; ?></div>
                <div class="notif-content">
                  <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                  <?php if (!empty($n['body'])): ?>
                    <div class="notif-body"><?php echo htmlspecialchars(mb_substr((string)$n['body'], 0, 100)); ?><?php echo mb_strlen((string)$n['body']) > 100 ? '...' : ''; ?></div>
                  <?php endif; ?>
                  <div class="notif-meta"><?php echo htmlspecialchars($n['time_ago']); ?></div>
                </div>
                <?php if ((int)$n['is_read'] === 0): ?>
                  <span class="unread-dot"></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Audit Log Card -->
      <div class="card audit-card">
        <div class="card-header-row">
          <h3>📋 Audit Log</h3>
          <a href="audit.php" class="btn btn-sm secondary" style="margin-left:auto">View All</a>
        </div>
        <div class="audit-list">
          <?php if(!$auditItems): ?>
            <p class="muted">No audit events yet.</p>
          <?php else: ?>
            <?php foreach($auditItems as $a): ?>
              <div class="audit-item">
                <div class="audit-action"><?php echo htmlspecialchars($a['action']); ?></div>
                <div class="audit-entity"><?php echo htmlspecialchars((string)($a['entity'] ?? '')); ?></div>
                <div class="audit-time"><?php echo htmlspecialchars($a['created_at']); ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <?php
        // Determine weather condition class for dynamic background
        $weatherCondition = 'default';
        if ($lastWeather) {
          $anim = $lastWeather['icon_anim'] ?? 'cloudy';
          if (in_array($anim, ['sunny'])) $weatherCondition = 'sunny';
          elseif (in_array($anim, ['moon'])) $weatherCondition = 'night';
          elseif (in_array($anim, ['partly-cloudy'])) $weatherCondition = 'partly-cloudy';
          elseif (in_array($anim, ['cloudy', 'foggy'])) $weatherCondition = 'cloudy';
          elseif (in_array($anim, ['rainy', 'stormy'])) $weatherCondition = 'rainy';
          elseif (in_array($anim, ['thunderstorm'])) $weatherCondition = 'stormy';
          elseif (in_array($anim, ['snowy'])) $weatherCondition = 'snowy';
        }
      ?>
      <div class="card weather-card-featured <?php echo htmlspecialchars($weatherCondition); ?>" id="weatherCard">
        <div class="weather-bg-effects">
          <div class="weather-bg-gradient"></div>
          <?php if ($weatherCondition === 'sunny'): ?>
            <div class="sun-rays-bg"></div>
          <?php elseif ($weatherCondition === 'night'): ?>
            <div class="stars-bg"></div>
          <?php elseif ($weatherCondition === 'rainy' || $weatherCondition === 'stormy'): ?>
            <div class="rain-bg"></div>
          <?php elseif ($weatherCondition === 'snowy'): ?>
            <div class="snow-bg"></div>
          <?php endif; ?>
        </div>
        
        <div class="weather-header">
          <div class="weather-header-left">
            <div class="weather-icon-wrapper">
              <?php if ($lastWeather): ?>
                <?php $animClass = $lastWeather['icon_anim'] ?? 'cloudy'; ?>
                <div class="weather-icon-animated <?php echo htmlspecialchars($animClass); ?>">
                  <?php if ($animClass === 'sunny'): ?>
                    <div class="sun"><div class="sun-rays"></div></div>
                  <?php elseif ($animClass === 'moon'): ?>
                    <div class="moon"><div class="moon-crater"></div><div class="moon-crater c2"></div></div>
                  <?php elseif ($animClass === 'partly-cloudy'): ?>
                    <div class="sun small"><div class="sun-rays"></div></div>
                    <div class="cloud front"></div>
                  <?php elseif ($animClass === 'cloudy'): ?>
                    <div class="cloud"></div>
                    <div class="cloud back"></div>
                  <?php elseif ($animClass === 'foggy'): ?>
                    <div class="fog-line"></div>
                    <div class="fog-line f2"></div>
                    <div class="fog-line f3"></div>
                  <?php elseif ($animClass === 'rainy'): ?>
                    <div class="cloud"></div>
                    <div class="rain"><div class="drop"></div><div class="drop d2"></div><div class="drop d3"></div></div>
                  <?php elseif ($animClass === 'stormy'): ?>
                    <div class="cloud dark"></div>
                    <div class="rain heavy"><div class="drop"></div><div class="drop d2"></div><div class="drop d3"></div><div class="drop d4"></div></div>
                  <?php elseif ($animClass === 'snowy'): ?>
                    <div class="cloud"></div>
                    <div class="snow"><div class="flake"></div><div class="flake f2"></div><div class="flake f3"></div></div>
                  <?php elseif ($animClass === 'thunderstorm'): ?>
                    <div class="cloud dark"></div>
                    <div class="lightning"></div>
                    <div class="rain heavy"><div class="drop"></div><div class="drop d2"></div><div class="drop d3"></div></div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="weather-icon-placeholder">🌤️</div>
              <?php endif; ?>
            </div>
            <div class="weather-title-block">
              <h3>⛅ Weather</h3>
              <p class="weather-subtitle">Current conditions & forecast</p>
            </div>
          </div>
          <?php if ($lastWeather): ?>
          <div class="weather-status-badge">
            <span class="status-dot"></span>
            <span>Live Data</span>
          </div>
          <?php endif; ?>
        </div>

        <div id="weatherSummary" class="weather-content">
          <?php if ($lastWeather): ?>
            <?php
              $weatherCity = '';
              $weatherState = '';
              if (!empty($recentLocations[0]['address'])) {
                $addr = $recentLocations[0]['address'];
                $weatherCity = $addr['city'] ?? '';
                $weatherState = $addr['state'] ?? '';
              }
              $locationStr = trim($weatherCity . ($weatherCity && $weatherState ? ', ' : '') . $weatherState);
            ?>
            
            <div class="weather-hero">
              <div class="weather-hero-content">
                <div class="weather-current-label">CURRENT CONDITIONS</div>
                <div class="weather-temp-display">
                  <span class="temp-value"><?php echo ($lastWeather['temp_f'] !== null) ? round($lastWeather['temp_f']) : '--'; ?></span>
                  <span class="temp-degree">°F</span>
                </div>
                <div class="weather-condition-text"><?php echo htmlspecialchars($lastWeather['desc'] ?? 'Unknown'); ?></div>
                <?php if ($locationStr): ?>
                  <div class="weather-location-text">📍 <?php echo htmlspecialchars($locationStr); ?></div>
                <?php endif; ?>
              </div>
              
              <div class="weather-meta-panel">
                <?php if ($lastWeather['high_f'] !== null && $lastWeather['low_f'] !== null): ?>
                <div class="meta-item">
                  <div class="meta-icon">🌡️</div>
                  <div class="meta-content">
                    <div class="meta-label">High / Low</div>
                    <div class="meta-value"><?php echo round($lastWeather['high_f']); ?>° / <?php echo round($lastWeather['low_f']); ?>°</div>
                  </div>
                </div>
                <?php endif; ?>
                <?php if (isset($lastWeather['humidity'])): ?>
                <div class="meta-item">
                  <div class="meta-icon">💧</div>
                  <div class="meta-content">
                    <div class="meta-label">Humidity</div>
                    <div class="meta-value"><?php echo (int)$lastWeather['humidity']; ?>%</div>
                  </div>
                </div>
                <?php endif; ?>
                <?php if (isset($lastWeather['wind_speed'])): ?>
                <div class="meta-item">
                  <div class="meta-icon">💨</div>
                  <div class="meta-content">
                    <div class="meta-label">Wind Speed</div>
                    <div class="meta-value"><?php echo round($lastWeather['wind_speed']); ?> mph</div>
                  </div>
                </div>
                <?php endif; ?>
                <?php if (isset($lastWeather['feels_like_f'])): ?>
                <div class="meta-item">
                  <div class="meta-icon">🤒</div>
                  <div class="meta-content">
                    <div class="meta-label">Feels Like</div>
                    <div class="meta-value"><?php echo round($lastWeather['feels_like_f']); ?>°F</div>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!empty($lastWeather['forecast'])): ?>
            <div class="weather-forecast-section">
              <div class="forecast-header">
                <h4>📅 7-Day Forecast</h4>
              </div>
              <div class="forecast-grid">
                <?php foreach ($lastWeather['forecast'] as $day): ?>
                  <div class="forecast-card">
                    <div class="forecast-day-name"><?php echo htmlspecialchars($day['day']); ?></div>
                    <div class="forecast-icon"><?php echo $day['icon']; ?></div>
                    <div class="forecast-temps">
                      <span class="forecast-high"><?php echo round($day['high_f'] ?? $day['high_c']); ?>°</span>
                      <span class="forecast-low"><?php echo round($day['low_f'] ?? $day['low_c']); ?>°</span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="weather-empty-state">
              <div class="empty-icon">🌤️</div>
              <p>Weather data will appear here once location is detected.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card location-card-featured" id="locationCard">
        <div class="location-header">
          <div class="location-header-left">
            <div class="location-icon-wrapper">
              <div class="location-pin-animated">
                <div class="pin-head"></div>
                <div class="pin-body"></div>
                <div class="pin-pulse"></div>
                <div class="pin-pulse p2"></div>
              </div>
            </div>
            <div class="location-title-block">
              <h3>📍 Location Tracker</h3>
              <p class="location-subtitle">Real-time location monitoring & history</p>
            </div>
          </div>
          <div class="location-status-badge <?php echo !empty($recentLocations) ? 'active' : 'inactive'; ?>">
            <span class="status-dot"></span>
            <span><?php echo !empty($recentLocations) ? 'Tracking Active' : 'Awaiting Location'; ?></span>
          </div>
        </div>

        <?php
          $currentLocation = !empty($recentLocations) ? $recentLocations[0] : null;
          $currentCity = $currentLocation['address']['city'] ?? '';
          $currentState = $currentLocation['address']['state'] ?? '';
          $currentCountry = $currentLocation['address']['country'] ?? '';
          $currentDisplay = trim($currentCity . ($currentCity && $currentState ? ', ' : '') . $currentState);
          $clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
          if (strpos($clientIP, ',') !== false) $clientIP = trim(explode(',', $clientIP)[0]);
        ?>

        <div class="location-main-display">
          <!-- Current Location Hero -->
          <div class="location-hero">
            <div class="location-hero-content">
              <div class="current-location-label">CURRENT LOCATION</div>
              <div class="current-location-city"><?php echo htmlspecialchars($currentCity ?: 'Detecting...'); ?></div>
              <?php if ($currentState): ?>
                <div class="current-location-state"><?php echo htmlspecialchars($currentState); ?><?php echo $currentCountry ? ', ' . htmlspecialchars($currentCountry) : ''; ?></div>
              <?php endif; ?>
              
              <div class="location-coords">
                <?php if ($currentLocation): ?>
                  <div class="coord-item">
                    <span class="coord-label">LAT</span>
                    <span class="coord-value"><?php echo number_format((float)$currentLocation['lat'], 6); ?></span>
                  </div>
                  <div class="coord-divider">|</div>
                  <div class="coord-item">
                    <span class="coord-label">LON</span>
                    <span class="coord-value"><?php echo number_format((float)$currentLocation['lon'], 6); ?></span>
                  </div>
                <?php else: ?>
                  <div class="coord-item"><span class="muted">Coordinates pending...</span></div>
                <?php endif; ?>
              </div>
            </div>
            
            <div class="location-meta-panel">
              <div class="meta-item">
                <div class="meta-icon">🌐</div>
                <div class="meta-content">
                  <div class="meta-label">IP Address</div>
                  <div class="meta-value ip-address"><?php echo htmlspecialchars($clientIP); ?></div>
                </div>
              </div>
              <div class="meta-item">
                <div class="meta-icon">📡</div>
                <div class="meta-content">
                  <div class="meta-label">Source</div>
                  <div class="meta-value"><?php echo htmlspecialchars($currentLocation['source'] ?? 'Pending'); ?></div>
                </div>
              </div>
              <div class="meta-item">
                <div class="meta-icon">🕐</div>
                <div class="meta-content">
                  <div class="meta-label">Last Update</div>
                  <div class="meta-value"><?php echo $currentLocation ? htmlspecialchars(date('M j, g:i A', strtotime($currentLocation['created_at']))) : 'N/A'; ?></div>
                </div>
              </div>
              <?php if ($currentLocation && isset($currentLocation['accuracy_m'])): ?>
              <div class="meta-item">
                <div class="meta-icon">🎯</div>
                <div class="meta-content">
                  <div class="meta-label">Accuracy</div>
                  <div class="meta-value"><?php echo round((float)$currentLocation['accuracy_m']); ?>m</div>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Interactive Map -->
          <div class="location-map-container">
            <div class="map-header">
              <span class="map-title">🗺️ Live Map View</span>
              <div class="map-controls">
                <button type="button" class="map-control-btn" id="refreshMapBtn" title="Refresh Map">🔄</button>
                <button type="button" class="map-control-btn" id="centerMapBtn" title="Center on Location">🎯</button>
              </div>
            </div>
            <div id="map" class="location-map-view"></div>
            <div class="map-legend">
              <div class="legend-item"><span class="legend-dot current"></span> Current</div>
              <div class="legend-item"><span class="legend-dot history"></span> History</div>
            </div>
          </div>
        </div>

        <!-- Location History Timeline -->
        <div class="location-history-section">
          <div class="history-header">
            <h4>📜 Location Timeline</h4>
            <a href="location_history.php" class="btn btn-sm secondary">View Full History →</a>
          </div>
          
          <?php if (!empty($recentLocations)): ?>
          <div class="location-timeline">
            <?php foreach(array_slice($recentLocations, 0, 6) as $idx => $loc): ?>
              <div class="timeline-item <?php echo $idx === 0 ? 'current' : ''; ?>" data-lat="<?php echo htmlspecialchars($loc['lat']); ?>" data-lon="<?php echo htmlspecialchars($loc['lon']); ?>">
                <div class="timeline-marker">
                  <div class="marker-dot"></div>
                  <?php if ($idx < 5): ?><div class="marker-line"></div><?php endif; ?>
                </div>
                <div class="timeline-content">
                  <div class="timeline-location">
                    <?php 
                      $locCity = $loc['address']['city'] ?? '';
                      $locState = $loc['address']['state'] ?? '';
                      $locDisplay = $locCity ?: ($loc['address']['display_name'] ?? 'Unknown location');
                      if ($locCity && $locState) $locDisplay = $locCity . ', ' . $locState;
                    ?>
                    <strong><?php echo htmlspecialchars($locDisplay); ?></strong>
                  </div>
                  <div class="timeline-meta">
                    <span class="timeline-time"><?php echo htmlspecialchars(date('M j, g:i A', strtotime($loc['created_at']))); ?></span>
                    <span class="timeline-source"><?php echo htmlspecialchars($loc['source']); ?></span>
                  </div>
                  <div class="timeline-coords">
                    <?php echo htmlspecialchars(number_format((float)$loc['lat'], 4)); ?>, <?php echo htmlspecialchars(number_format((float)$loc['lon'], 4)); ?>
                  </div>
                </div>
                <button class="timeline-focus-btn" data-lat="<?php echo htmlspecialchars($loc['lat']); ?>" data-lon="<?php echo htmlspecialchars($loc['lon']); ?>">
                  <span>👁️</span>
                </button>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="no-location-data">
            <div class="no-data-icon">📍</div>
            <p>No location data yet.</p>
            <p class="muted">Enable location logging in <a href="preferences.php">Preferences</a> and allow location access in your browser.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- 2 - Photos Gallery -->

      <div class="card photos-card-featured" id="photosCard">
        <div class="photos-header">
          <div class="photos-header-left">
            <div class="photos-icon-wrapper">
              <span class="photos-icon">📸</span>
            </div>
            <div class="photos-title-block">
              <h3>Photo Gallery</h3>
              <p class="photos-subtitle">Your uploaded memories</p>
            </div>
          </div>
          <div class="photos-actions">
            <a href="/ios_upload_setup.php" class="btn btn-sm photos-upload-btn" title="Upload from iOS">
              <span>📱</span> iOS Upload
            </a>
          </div>
        </div>
        
        <div class="photos-gallery-grid" id="photoPreview">
          <div class="photos-loading">
            <div class="photo-skeleton"></div>
            <div class="photo-skeleton"></div>
            <div class="photo-skeleton"></div>
            <div class="photo-skeleton"></div>
            <div class="photo-skeleton"></div>
            <div class="photo-skeleton"></div>
          </div>
        </div>
        
        <div class="photos-empty" id="photosEmptyState" style="display:none;">
          <div class="photos-empty-icon">🖼️</div>
          <p>No photos yet</p>
          <p class="muted">Upload photos from your iPhone using iOS Shortcuts</p>
          <a href="/ios_upload_setup.php" class="btn secondary">Set up iOS Photo Upload</a>
        </div>
        
        <div class="photos-footer">
          <div class="photos-stats" id="photosStats"></div>
          <div class="photos-links">
            <a href="photos.php" class="photos-link-btn">
              <span>🖼️</span> Full Gallery
            </a>
            <a href="/public/ios_photos.php" class="photos-link-btn">
              <span>📲</span> iOS Setup
            </a>
          </div>
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
      <div class="card settings-card">
        <div class="card-header-row">
          <h3>⚙️ User Settings</h3>
          <a href="preferences.php" class="btn btn-sm secondary">Open Preferences</a>
        </div>
        <p class="muted">Control contact details and integrations. Preferences includes Slack and other options.</p>
        <form method="post" class="settings-form">
          <div class="form-row">
            <label for="phone_number">Phone Number (for SMS)</label>
            <div class="form-inline">
              <input id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phone); ?>" placeholder="+1..." />
              <button type="submit" class="btn" name="save_phone" value="1">Save</button>
            </div>
          </div>
        </form>
      </div>

      <!-- 5 -->
      <div class="card smart-home-card" id="homeAutoCard">
        <div class="smart-home-header">
          <h3>🏠 Smart Home</h3>
          <div class="home-status-pill" id="homeStatusPill">Local • Demo</div>
        </div>
        <div id="smartHomeWidget" class="smart-home-widget">
          <div class="thermostat-panel">
            <div class="thermostat-dial" id="thermostatDial">
              <div class="thermo-temp"><span id="thermoTempVal">72</span>°</div>
              <div class="thermo-controls">
                <button class="thermo-btn" id="thermoDown" title="Decrease">−</button>
                <button class="thermo-btn" id="thermoUp" title="Increase">+</button>
              </div>
            </div>
            <div class="thermo-meta">
              <div>Mode: <span id="thermoMode">Heat</span></div>
              <div>Inside: <span id="thermoInside">71°</span> • Outside: <span id="thermoOutside">64°</span></div>
            </div>
          </div>
          <div class="home-right">
            <div class="scenes-row" id="scenesRow"></div>
            <div class="devices-grid" id="devicesGrid"></div>
            <div class="energy-row">
              <div class="energy-donut">
                <div class="donut" id="energyDonut" data-percent="42"><span id="energyPct">42%</span></div>
              </div>
              <div class="energy-meta">
                <div class="energy-title">Energy Today</div>
                <div class="energy-detail"><span id="energyKwh">12.4</span> kWh • <span id="energyCost">$1.58</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shortcuts-card">
        <div class="card-header-row">
          <h3>⚡ Shortcuts</h3>
          <a href="siri.php" class="btn btn-sm secondary" data-shortcut="siri_setup">Siri Setup</a>
        </div>
        <p class="muted">Quick access to common areas and actions.</p>
        <div class="shortcut-grid">
          <a class="shortcut-tile" href="channel.php" data-shortcut="open_channels">
            <div class="shortcut-icon">#</div>
            <div class="shortcut-title">Channels</div>
            <div class="shortcut-sub">Chat with JARVIS</div>
          </a>
          <a class="shortcut-tile" href="photos.php" data-shortcut="open_photos">
            <div class="shortcut-icon">🖼️</div>
            <div class="shortcut-title">Photos</div>
            <div class="shortcut-sub">Gallery & timeline</div>
          </a>
          <a class="shortcut-tile" href="audit.php" data-shortcut="open_audit">
            <div class="shortcut-icon">📋</div>
            <div class="shortcut-title">Audit Log</div>
            <div class="shortcut-sub">Recent activity</div>
          </a>
          <a class="shortcut-tile" href="preferences.php" data-shortcut="open_preferences">
            <div class="shortcut-icon">⚙️</div>
            <div class="shortcut-title">Preferences</div>
            <div class="shortcut-sub">Integrations & more</div>
          </a>
        </div>
      </div>

      <!-- 6 (BOTTOM RIGHT) -->
      <div class="card connection-card">
        <h3>Connection Status</h3>
        <?php $slackOk = (jarvis_setting_get('SLACK_BOT_TOKEN') || jarvis_setting_get('SLACK_APP_TOKEN') || getenv('SLACK_BOT_TOKEN') || getenv('SLACK_APP_TOKEN'));
              $instaOk = (bool)$igToken;
              $mysqlOk = (bool)jarvis_pdo();
              $restBase = '/api';
              $notifN = (int)$notifCount;
              $weatherOk = (bool)$lastWeather; ?>
        <div class="status-grid">
          <div class="status-pill <?php echo $slackOk?'ok':'warn'; ?>">Slack: <?php echo $slackOk?'Configured':'Not set'; ?></div>
          <div class="status-pill <?php echo $instaOk?'ok':'warn'; ?>">Instagram: <?php echo $instaOk?'Connected':'Not connected'; ?></div>
          <div class="status-pill <?php echo $mysqlOk?'ok':'warn'; ?>">MySQL: <?php echo $mysqlOk?'Connected':'Unavailable'; ?></div>
          <button type="button" class="status-pill copyable" data-copy="<?php echo htmlspecialchars($restBase); ?>">REST Base: <?php echo htmlspecialchars($restBase); ?></button>
          <div class="status-pill info">Notifications: <?php echo $notifN; ?> unread</div>
          <div class="status-pill <?php echo $weatherOk?'ok':'info'; ?>">Weather: <span id="jarvisWeather"><?php if ($lastWeather) { echo htmlspecialchars($lastWeather['desc'] . ' • ' . ($lastWeather['temp_f'] !== null ? round($lastWeather['temp_f']).'°F' : '')); } else { echo ($weatherConfigured ? '(no weather data for current location)' : '(OPENWEATHER_API_KEY not configured; add it in Admin > Settings)'); } ?></span></div>
        </div>
        <?php if ($wakePrompt): ?>
          <div class="terminal" style="margin-top:12px">
            <div class="term-title">JARVIS Wake Prompt</div>
            <pre><?php echo htmlspecialchars($wakePrompt); ?></pre>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- iOS Photo Upload Quick Access -->
  <div class="container">
    <a href="/ios_upload_setup.php" class="ios-photo-quick-link">
      <div class="ios-link-icon">📱</div>
      <div class="ios-link-content">
        <strong>iOS Photo Upload</strong>
        <span>Set up automatic photo uploads from your iPhone using Shortcuts</span>
      </div>
      <div class="ios-link-arrow">→</div>
    </a>
  </div>

  <div class="container">
    <div class="card" id="calendarCard">
      <h3>📅 <?php echo $googleCalendarConnected ? 'Upcoming Calendar Events' : 'My Calendar'; ?></h3>
      
      <?php if ($googleCalendarConnected): ?>
        <!-- Google Calendar Events -->
        <?php if (empty($calendarEvents)): ?>
          <p class="muted">No upcoming events from Google Calendar.</p>
        <?php else: ?>
          <ul class="calendar-events-list">
            <?php foreach($calendarEvents as $ce): ?>
              <li class="calendar-event">
                <div class="event-time"><?php echo htmlspecialchars(date('M j', strtotime($ce['start_dt'] ?? 'now'))); ?></div>
                <div class="event-details">
                  <strong><?php echo htmlspecialchars($ce['summary'] ?? '(no title)'); ?></strong>
                  <div class="muted"><?php echo htmlspecialchars(date('g:i A', strtotime($ce['start_dt'] ?? 'now'))); ?><?php echo !empty($ce['location']) ? ' • ' . htmlspecialchars($ce['location']) : ''; ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php else: ?>
        <!-- Local Events Calendar -->
        <div class="local-calendar">
          <div class="calendar-month-view" id="localCalendarView">
            <?php
              $today = new DateTime();
              $currentMonth = $today->format('F Y');
              $daysInMonth = (int)$today->format('t');
              $firstDayOfMonth = (new DateTime($today->format('Y-m-01')))->format('w');
            ?>
            <div class="calendar-header">
              <span class="calendar-month-name"><?php echo $currentMonth; ?></span>
            </div>
            <div class="calendar-weekdays">
              <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
            </div>
            <div class="calendar-days">
              <?php
                // Empty cells before first day
                for ($i = 0; $i < $firstDayOfMonth; $i++) {
                  echo '<span class="calendar-day empty"></span>';
                }
                // Days of the month
                for ($day = 1; $day <= $daysInMonth; $day++) {
                  $isToday = $day == (int)$today->format('j');
                  $dayDate = $today->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                  $hasEvent = false;
                  foreach ($localEvents as $evt) {
                    if (date('Y-m-d', strtotime($evt['event_date'])) === $dayDate) {
                      $hasEvent = true;
                      break;
                    }
                  }
                  $classes = 'calendar-day' . ($isToday ? ' today' : '') . ($hasEvent ? ' has-event' : '');
                  echo '<span class="' . $classes . '" data-date="' . $dayDate . '">' . $day . '</span>';
                }
              ?>
            </div>
          </div>
          
          <div class="local-events-list">
            <div class="local-events-header">
              <h4>Upcoming Events</h4>
              <button type="button" class="btn btn-sm" id="addLocalEventBtn">+ Add Event</button>
            </div>
            <?php if (empty($localEvents)): ?>
              <p class="muted">No local events. Add your first event above!</p>
            <?php else: ?>
              <ul class="calendar-events-list">
                <?php foreach($localEvents as $evt): ?>
                  <li class="calendar-event">
                    <div class="event-time"><?php echo htmlspecialchars(date('M j', strtotime($evt['event_date']))); ?></div>
                    <div class="event-details">
                      <strong><?php echo htmlspecialchars($evt['title']); ?></strong>
                      <div class="muted"><?php echo htmlspecialchars(date('g:i A', strtotime($evt['event_time'] ?? '00:00'))); ?><?php echo !empty($evt['location']) ? ' • ' . htmlspecialchars($evt['location']) : ''; ?></div>
                    </div>
                    <button class="btn btn-sm secondary delete-local-event" data-id="<?php echo (int)$evt['id']; ?>">×</button>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:14px;padding:0;overflow:hidden" id="channelsActivity">
      <div class="home-channels-widget">
        <!-- Compact Channel Header -->
        <div class="hc-header">
          <div class="hc-title">
            <h3 style="margin:0">💬 Channels & Activity</h3>
            <a href="channel.php" class="btn btn-sm secondary">Open Full View</a>
          </div>
        </div>
        
        <!-- Two Column Layout: Messages + Sidebar -->
        <div class="hc-body">
          <!-- Left: Messages Area -->
          <div class="hc-main">
            <div class="hc-channel-bar">
              <select id="hcChannelSelect" class="hc-channel-dropdown">
                <option value="local:rhats"># rhats</option>
                <option value="local:general"># general</option>
                <option value="local:jarvis"># jarvis</option>
                <option value="local:projects"># projects</option>
              </select>
            </div>
            
            <div class="hc-messages" id="hcMessages">
              <div class="loading-messages">Loading messages...</div>
            </div>
            
            <div class="hc-input-container">
              <div class="hc-input-wrapper">
                <textarea 
                  id="hcMessageInput" 
                  class="hc-message-input" 
                  placeholder="Message #rhats (@ for mentions, # for tags)"
                  rows="1"
                ></textarea>
                <div class="hc-input-toolbar">
                  <div class="hc-input-actions">
                    <button class="hc-input-btn" id="hcAttachBtn" title="Attach photo/video">📎</button>
                    <button class="hc-input-btn" id="hcEmojiBtn" title="Add emoji">😊</button>
                    <button class="hc-input-btn" id="hcMentionBtn" title="Mention">@</button>
                    <button class="hc-input-btn" id="hcHashtagBtn" title="Hashtag">#</button>
                    <input type="file" id="hcFileInput" style="display:none" accept="image/*,video/*">
                  </div>
                  <button class="hc-send-btn" id="hcSendBtn" disabled>Send</button>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Right: Audit Log Sidebar -->
          <div class="hc-sidebar">
            <div class="hc-sidebar-header">
              <h4 style="margin:0;font-size:14px">Recent Audit Log</h4>
            </div>
            <div class="hc-audit" id="hcAudit"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Image Annotation Modal -->
  <div class="annotation-modal" id="hcAnnotationModal">
    <div class="annotation-container">
      <div class="annotation-header">
        <h3 id="hcAnnotationTitle">✏️ Draw on Image</h3>
        <button class="annotation-close" id="hcCloseAnnotation">×</button>
      </div>
      <div class="annotation-body">
        <div class="annotation-canvas-wrapper">
          <video id="hcAnnotationVideo" style="display:none; max-width:100%; max-height:calc(90vh - 200px)" controls></video>
          <canvas id="hcAnnotationCanvas"></canvas>
          <div class="video-controls" id="hcVideoControls" style="display:none; margin-top:12px">
            <button class="tool-btn" id="hcCaptureFrame" style="width:100%">📸 Capture This Frame to Draw</button>
            <div style="margin-top:8px; font-size:0.85rem; color:rgba(255,255,255,0.5); text-align:center" id="hcVideoTime"></div>
          </div>
        </div>
        <div class="annotation-toolbar">
          <div class="tool-group">
            <div class="tool-group-title">Tools</div>
            <button class="tool-btn active" data-tool="draw">✏️ Draw</button>
            <button class="tool-btn" data-tool="text">📝 Text</button>
            <button class="tool-btn" data-tool="arrow">➡️ Arrow</button>
            <button class="tool-btn" data-tool="highlight">🖍️ Highlight</button>
          </div>
          <div class="tool-group">
            <div class="tool-group-title">Colors</div>
            <div class="color-palette">
              <button class="color-btn active" style="background: #ef4444" data-color="#ef4444"></button>
              <button class="color-btn" style="background: #f59e0b" data-color="#f59e0b"></button>
              <button class="color-btn" style="background: #10b981" data-color="#10b981"></button>
              <button class="color-btn" style="background: #3b82f6" data-color="#3b82f6"></button>
              <button class="color-btn" style="background: #8b5cf6" data-color="#8b5cf6"></button>
              <button class="color-btn" style="background: #ec4899" data-color="#ec4899"></button>
              <button class="color-btn" style="background: #ffffff" data-color="#ffffff"></button>
              <button class="color-btn" style="background: #000000" data-color="#000000"></button>
            </div>
          </div>
          <div class="tool-group">
            <div class="tool-group-title">Brush Size</div>
            <input type="range" class="brush-size" id="hcBrushSize" min="1" max="20" value="3">
            <div style="text-align: center; color: rgba(255,255,255,0.5); font-size: 0.8rem; margin-top: 4px;" id="hcBrushSizeLabel">3px</div>
          </div>
          <div class="tool-group">
            <button class="tool-btn" id="hcClearCanvas">🗑️ Clear All</button>
            <button class="tool-btn" id="hcUndoCanvas">↶ Undo</button>
          </div>
        </div>
      </div>
      <div class="annotation-footer">
        <button class="btn btn-cancel" id="hcCancelAnnotation">Cancel</button>
        <button class="btn btn-save" id="hcSaveAnnotation">Use This Image</button>
      </div>
    </div>
  </div>

  <script>
    // Browser location -> /api/location (logged to MySQL)
    (function(){
      const enabled = <?php echo !empty($prefs['location_logging_enabled']) ? 'true' : 'false'; ?>;
      const token = <?php echo $webJwt ? json_encode($webJwt) : 'null'; ?>;
      // Global diagnostic: expose token to window and capture parse/runtime errors early
      try { window.jarvisJwt = token; } catch(e){}
      window.addEventListener('error', (e) => { try{ console.log('PAGE_JS_ERROR', e.message, e.filename, e.lineno, e.colno, e && e.error && e.error.stack ? e.error.stack : null); }catch(e){} });
      window.addEventListener('unhandledrejection', (e) => { try{ console.log('PAGE_PROMISE_REJECTION', e.reason && (e.reason.stack || e.reason)); }catch(e){} });
      const recentLocations = <?php echo json_encode(array_values($recentLocations)); ?>;
      const lastWeather = <?php echo json_encode($lastWeather); ?>;

      // Map management and location refreshing
      let _mainMap = null, _miniMap = null; window._sending = window._sending || false;

      // Smart Home Widget (Demo data with rich UI)
      (function(){
        const storageKey = 'jarvis.demoHome.v1';
        const initialDemo = {
          thermostat: { target: 72, mode: 'Heat', inside: 71, outside: 64 },
          energy: { percent: 42, kwh: 12.4, cost: 1.58 },
          scenes: [
            { id:'scene_relax', name:'Relax', icon:'🛋️', color:'#6d28d9' },
            { id:'scene_focus', name:'Focus', icon:'💡', color:'#0ea5e9' },
            { id:'scene_movie', name:'Movie', icon:'🎬', color:'#ef4444' },
            { id:'scene_goodnight', name:'Goodnight', icon:'🌙', color:'#111827' }
          ],
          devices: [
            { id:'d_living_light', name:'Living Room Lights', type:'light', room:'Living', on:true, icon:'💡' },
            { id:'d_kitchen_light', name:'Kitchen', type:'light', room:'Kitchen', on:false, icon:'🍳' },
            { id:'d_bedroom_light', name:'Bedroom', type:'light', room:'Bedroom', on:true, icon:'🛏️' },
            { id:'d_door', name:'Front Door', type:'lock', room:'Entry', locked:true, icon:'🚪' },
            { id:'d_cam', name:'Driveway Cam', type:'camera', room:'Outdoor', on:true, icon:'📷' }
          ]
        };

        function loadDemo(){
          try { const raw = localStorage.getItem(storageKey); if (!raw) return JSON.parse(JSON.stringify(initialDemo));
            const data = JSON.parse(raw); return Object.assign({}, initialDemo, data);
          } catch(e){ return JSON.parse(JSON.stringify(initialDemo)); }
        }
        function saveDemo(data){ try{ localStorage.setItem(storageKey, JSON.stringify(data)); }catch(e){} }

        function setDonut(el, pct){
          const p = Math.max(0, Math.min(100, Math.round(pct)));
          if (el) el.style.background = `conic-gradient(#00d4ff ${p*3.6}deg, rgba(255,255,255,0.08) 0)`;
          const label = document.getElementById('energyPct'); if (label) label.textContent = p + '%';
        }

        function renderThermostat(state){
          const dial = document.getElementById('thermostatDial');
          const val = document.getElementById('thermoTempVal');
          const mode = document.getElementById('thermoMode');
          const inside = document.getElementById('thermoInside');
          const outside = document.getElementById('thermoOutside');
          if (!dial || !val) return;
          val.textContent = state.thermostat.target;
          mode.textContent = state.thermostat.mode;
          inside.textContent = (state.thermostat.inside|0) + '°';
          outside.textContent = (state.thermostat.outside|0) + '°';
          const min=55, max=80, t = Math.max(min, Math.min(max, state.thermostat.target));
          const pct = (t - min) / (max - min);
          dial.style.setProperty('--dial-angle', (pct*270+135) + 'deg');
          dial.style.background = `conic-gradient(#00d4ff ${(pct*300+30)}deg, rgba(255,255,255,0.06) 0)`;
        }

        function renderScenes(state){
          const row = document.getElementById('scenesRow'); if (!row) return; row.innerHTML='';
          state.scenes.forEach(s=>{
            const chip = document.createElement('button');
            chip.className = 'scene-chip';
            chip.style.setProperty('--scene-color', s.color);
            chip.innerHTML = `<span class="scene-icon">${s.icon}</span><span>${s.name}</span>`;
            chip.addEventListener('click', ()=>{
              // Pulse animation
              chip.classList.add('active'); setTimeout(()=>chip.classList.remove('active'), 350);
              // Audit
              try{ if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_SCENE_ACTIVATED','home_scene',{ scene_id: s.id, scene_name: s.name, timestamp: new Date().toISOString() }); }catch(e){}
            });
            row.appendChild(chip);
          });
        }

        function renderDevices(state){
          const grid = document.getElementById('devicesGrid'); if (!grid) return; grid.innerHTML='';
          state.devices.forEach(d=>{
            const tile = document.createElement('div'); tile.className='device-tile';
            const isOn = d.type==='lock' ? !d.locked : !!d.on;
            tile.classList.toggle('on', isOn);
            const sub = d.type==='lock' ? (d.locked ? 'Locked' : 'Unlocked') : (isOn ? 'On' : 'Off');
            const toggleLabel = d.type==='lock' ? (d.locked ? 'Unlock' : 'Lock') : (isOn ? 'Turn Off' : 'Turn On');
            tile.innerHTML = `
              <div class="device-icon">${d.icon}</div>
              <div class="device-name">${d.name}</div>
              <div class="device-sub">${d.room} • ${sub}</div>
              <button class="device-toggle">${toggleLabel}</button>
            `;
            const btn = tile.querySelector('.device-toggle');
            btn.addEventListener('click', ()=>{
              // update local state
              if (d.type==='lock') { d.locked = !d.locked; } else { d.on = !d.on; }
              saveDemo(state); renderDevices(state);
              // audit
              try{ if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_DEVICE_TOGGLE','home_device',{
                device_id: d.id, type: d.type, new_state: (d.type==='lock' ? (d.locked?'locked':'unlocked') : (d.on?'on':'off')), timestamp: new Date().toISOString()
              }); }catch(e){}
            });
            grid.appendChild(tile);
          });
        }

        function renderEnergy(state){
          const donut = document.getElementById('energyDonut');
          const k = document.getElementById('energyKwh');
          const c = document.getElementById('energyCost');
          if (donut) setDonut(donut, state.energy.percent);
          if (k) k.textContent = state.energy.kwh.toFixed(1);
          if (c) c.textContent = '$' + state.energy.cost.toFixed(2);
        }

        function wireThermostat(state){
          const up = document.getElementById('thermoUp');
          const dn = document.getElementById('thermoDown');
          up && up.addEventListener('click', ()=>{ state.thermostat.target = Math.min(80, state.thermostat.target+1); saveDemo(state); renderThermostat(state); try{ if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_THERMOSTAT_ADJUST','home_thermostat',{ target: state.thermostat.target, dir:'+1', timestamp: new Date().toISOString() }); }catch(e){} });
          dn && dn.addEventListener('click', ()=>{ state.thermostat.target = Math.max(55, state.thermostat.target-1); saveDemo(state); renderThermostat(state); try{ if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_THERMOSTAT_ADJUST','home_thermostat',{ target: state.thermostat.target, dir:'-1', timestamp: new Date().toISOString() }); }catch(e){} });
        }

        function init(){
          const state = loadDemo();
          renderThermostat(state); renderScenes(state); renderDevices(state); renderEnergy(state); wireThermostat(state);
          const pill = document.getElementById('homeStatusPill'); if (pill) { pill.textContent = 'Demo Mode'; }
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
      })();

      // Lightweight audit + helpers for new tiles and status pills
      (function(){
        function audit(type, subject, data){ try{ if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog(type, subject, data); }catch(e){} }
        function onReady(fn){ if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }
        onReady(()=>{
          document.querySelectorAll('.shortcut-tile,[data-shortcut]').forEach(el=>{
            el.addEventListener('click', ()=>{
              const id = el.getAttribute('data-shortcut') || (el.querySelector('.shortcut-title') ? el.querySelector('.shortcut-title').textContent : 'unknown');
              audit('HOME_SHORTCUT_CLICK','home_shortcut',{ id, timestamp: new Date().toISOString() });
            });
          });
          document.querySelectorAll('.status-pill.copyable').forEach(el=>{
            el.addEventListener('click', ()=>{
              const val = el.getAttribute('data-copy') || '';
              try{ if (navigator.clipboard) navigator.clipboard.writeText(val); }catch(e){}
              el.classList.add('copied'); setTimeout(()=>el.classList.remove('copied'), 900);
              audit('HOME_COPY_VALUE','home_copy',{ key: 'REST Base', value: val, timestamp: new Date().toISOString() });
            });
          });
        });
      })();

      // Helper to update weather UI components
      window.jarvisUpdateWeather = function(data){
        if (!data || !data.weather) return;
        const w = data.weather;
        const addr = data.address || {};
        const desc = w.desc || '';
        const temp = w.temp_f !== null ? w.temp_f : null;
        const high = w.high_f !== null ? w.high_f : null;
        const low = w.low_f !== null ? w.low_f : null;
        const feelsLike = w.feels_like_f !== null ? w.feels_like_f : null;
        const humidity = w.humidity !== null ? w.humidity : null;
        const windSpeed = w.wind_speed !== null ? w.wind_speed : null;
        const forecast = w.forecast || [];
        const iconAnim = w.icon_anim || 'cloudy';
        
        // Build location string
        const city = addr.city || '';
        const state = addr.state || '';
        const locationStr = city && state ? `${city}, ${state}` : (city || state || '');
        
        // Update small badge
        const badge = document.getElementById('jarvisWeather');
        if (badge) badge.textContent = desc + ' • ' + (temp !== null ? Math.round(temp) + '°F' : '');
        
        // Determine weather condition class for dynamic background
        let weatherCondition = 'default';
        if (['sunny'].includes(iconAnim)) weatherCondition = 'sunny';
        else if (['moon'].includes(iconAnim)) weatherCondition = 'night';
        else if (['partly-cloudy'].includes(iconAnim)) weatherCondition = 'partly-cloudy';
        else if (['cloudy', 'foggy'].includes(iconAnim)) weatherCondition = 'cloudy';
        else if (['rainy', 'stormy'].includes(iconAnim)) weatherCondition = 'rainy';
        else if (['thunderstorm'].includes(iconAnim)) weatherCondition = 'stormy';
        else if (['snowy'].includes(iconAnim)) weatherCondition = 'snowy';
        
        // Update card background class
        const weatherCard = document.getElementById('weatherCard');
        if (weatherCard) {
          weatherCard.className = weatherCard.className.replace(/\b(sunny|night|partly-cloudy|cloudy|rainy|stormy|snowy|default)\b/g, '').trim();
          weatherCard.classList.add(weatherCondition);
          
          // Update background effects
          const bgEffects = weatherCard.querySelector('.weather-bg-effects');
          if (bgEffects) {
            let bgHtml = '<div class="weather-bg-gradient"></div>';
            if (weatherCondition === 'sunny') bgHtml += '<div class="sun-rays-bg"></div>';
            else if (weatherCondition === 'night') bgHtml += '<div class="stars-bg"></div>';
            else if (weatherCondition === 'rainy' || weatherCondition === 'stormy') bgHtml += '<div class="rain-bg"></div>';
            else if (weatherCondition === 'snowy') bgHtml += '<div class="snow-bg"></div>';
            bgEffects.innerHTML = bgHtml;
          }
          
          // Update animated weather icon in header
          const iconWrapper = weatherCard.querySelector('.weather-icon-wrapper');
          if (iconWrapper) {
            let iconHtml = '';
            if (iconAnim === 'sunny') {
              iconHtml = '<div class="weather-icon-animated sunny"><div class="sun"><div class="sun-rays"></div></div></div>';
            } else if (iconAnim === 'moon') {
              iconHtml = '<div class="weather-icon-animated moon"><div class="moon"><div class="moon-crater"></div><div class="moon-crater c2"></div></div></div>';
            } else if (iconAnim === 'partly-cloudy') {
              iconHtml = '<div class="weather-icon-animated partly-cloudy"><div class="sun small"><div class="sun-rays"></div></div><div class="cloud front"></div></div>';
            } else if (iconAnim === 'cloudy') {
              iconHtml = '<div class="weather-icon-animated cloudy"><div class="cloud"></div><div class="cloud back"></div></div>';
            } else if (iconAnim === 'foggy') {
              iconHtml = '<div class="weather-icon-animated foggy"><div class="fog-line"></div><div class="fog-line f2"></div><div class="fog-line f3"></div></div>';
            } else if (iconAnim === 'rainy') {
              iconHtml = '<div class="weather-icon-animated rainy"><div class="cloud"></div><div class="rain"><div class="drop"></div><div class="drop d2"></div><div class="drop d3"></div></div></div>';
            } else if (iconAnim === 'stormy') {
              iconHtml = '<div class="weather-icon-animated stormy"><div class="cloud dark"></div><div class="rain heavy"><div class="drop"></div><div class="drop d2"></div><div class="drop d3"></div><div class="drop d4"></div></div></div>';
            } else if (iconAnim === 'snowy') {
              iconHtml = '<div class="weather-icon-animated snowy"><div class="cloud"></div><div class="snow"><div class="flake"></div><div class="flake f2"></div><div class="flake f3"></div></div></div>';
            } else if (iconAnim === 'thunderstorm') {
              iconHtml = '<div class="weather-icon-animated thunderstorm"><div class="cloud dark"></div><div class="lightning"></div><div class="rain heavy"><div class="drop"></div><div class="drop d2"></div><div class="drop d3"></div></div></div>';
            } else {
              iconHtml = '<div class="weather-icon-placeholder">🌤️</div>';
            }
            iconWrapper.innerHTML = iconHtml;
          }
        }
        
        // Update main card content with full weather display
        const card = document.getElementById('weatherSummary');
        if (card) {
          let metaHtml = '';
          if (high !== null && low !== null) {
            metaHtml += `<div class="meta-item"><div class="meta-icon">🌡️</div><div class="meta-content"><div class="meta-label">High / Low</div><div class="meta-value">${Math.round(high)}° / ${Math.round(low)}°</div></div></div>`;
          }
          if (humidity !== null) {
            metaHtml += `<div class="meta-item"><div class="meta-icon">💧</div><div class="meta-content"><div class="meta-label">Humidity</div><div class="meta-value">${Math.round(humidity)}%</div></div></div>`;
          }
          if (windSpeed !== null) {
            metaHtml += `<div class="meta-item"><div class="meta-icon">💨</div><div class="meta-content"><div class="meta-label">Wind Speed</div><div class="meta-value">${Math.round(windSpeed)} mph</div></div></div>`;
          }
          if (feelsLike !== null) {
            metaHtml += `<div class="meta-item"><div class="meta-icon">🤒</div><div class="meta-content"><div class="meta-label">Feels Like</div><div class="meta-value">${Math.round(feelsLike)}°F</div></div></div>`;
          }
          
          let forecastHtml = '';
          if (forecast.length > 0) {
            forecastHtml = `<div class="weather-forecast-section"><div class="forecast-header"><h4>📅 7-Day Forecast</h4></div><div class="forecast-grid">`;
            forecast.forEach(day => {
              const dayHigh = day.high_f !== null ? Math.round(day.high_f) : (day.high_c !== null ? Math.round(day.high_c) : '--');
              const dayLow = day.low_f !== null ? Math.round(day.low_f) : (day.low_c !== null ? Math.round(day.low_c) : '--');
              forecastHtml += `<div class="forecast-card"><div class="forecast-day-name">${day.day || ''}</div><div class="forecast-icon">${day.icon || '🌡️'}</div><div class="forecast-temps"><span class="forecast-high">${dayHigh}°</span><span class="forecast-low">${dayLow}°</span></div></div>`;
            });
            forecastHtml += `</div></div>`;
          }
          
          card.innerHTML = `
            <div class="weather-hero">
              <div class="weather-hero-content">
                <div class="weather-current-label">CURRENT CONDITIONS</div>
                <div class="weather-temp-display">
                  <span class="temp-value">${temp !== null ? Math.round(temp) : '--'}</span>
                  <span class="temp-degree">°F</span>
                </div>
                <div class="weather-condition-text">${desc}</div>
                ${locationStr ? `<div class="weather-location-text">📍 ${locationStr}</div>` : ''}
              </div>
              <div class="weather-meta-panel">
                ${metaHtml}
              </div>
            </div>
            ${forecastHtml}`;
        }
      };

      function destroyMap(elId, mapRef){ try{ if (mapRef) { mapRef.remove(); } }catch(e){}
        try{ const el = document.getElementById(elId); if (el) el.innerHTML=''; }catch(e){}
      }
      // Use an embedded third-party map (Google Maps embed) instead of Leaflet
      function osmEmbedUrl(centerLat, centerLon, zoom=13){
        // Build a small bbox around the center to center the embedded map
        const lat = parseFloat(centerLat); const lon = parseFloat(centerLon); const z = parseInt(zoom) || 13;
        const delta = 0.02; // ~2km box; simple fixed delta is fine for small zoom levels
        const left = (lon - delta).toFixed(6), bottom = (lat - delta).toFixed(6), right = (lon + delta).toFixed(6), top = (lat + delta).toFixed(6);
        return `https://www.openstreetmap.org/export/embed.html?bbox=${left},${bottom},${right},${top}&layer=mapnik&marker=${lat},${lon}`;
      }

      // Map embed diagnostics: show a banner and send an audit event when the iframe fails to load
      function showMapIssueBanner(el){ try{
        if (!el) return;
        let b = el.querySelector('.mapIssueBanner');
        const src = el.dataset && el.dataset.src ? el.dataset.src.replace('/export/embed.html','/') : '#';
        if (!b) {
          b = document.createElement('div'); b.className = 'mapIssueBanner';
          b.innerHTML = `<div style="padding:8px;background:linear-gradient(90deg,rgba(255,165,0,.06),rgba(255,165,0,.03));border:1px solid rgba(255,165,0,.12);border-radius:8px">Map failed to load. <a class="open-osm-link" href="${src}" target="_blank">Open in OpenStreetMap</a> • <a class="retry-osm" href="#" style="margin-left:8px">Retry</a></div>`;
          el.insertBefore(b, el.firstChild);
          b.querySelector('.retry-osm').addEventListener('click',(ev)=>{ ev.preventDefault(); try{ const iframe = el.querySelector('iframe'); if (iframe){ iframe.src = iframe.src; } b.style.display='none'; if (el.querySelector('.embedFallback')) el.querySelector('.embedFallback').style.display='none'; }catch(e){} });
        } else { b.style.display = 'block'; }
      }catch(e){}
      }
      function hideMapIssueBanner(el){ try{ const b = el.querySelector('.mapIssueBanner'); if (b) b.style.display = 'none'; }catch(e){}
      }
      function sendMapEmbedFailure(lat, lon, src){ try{
        const payload = { action: 'MAP_EMBED_FAILED', entity: 'location_map', metadata: { lat: lat, lon: lon, source: src } };
        if (window.jarvisApi && typeof window.jarvisApi.post === 'function') {
          window.jarvisApi.post('/api/audit', { action: payload.action, entity: payload.entity, metadata: payload.metadata }).catch(()=>{});
        } else {
          try{ const headers = window.jarvisApiToken ? { 'Content-Type':'application/json','Authorization':'Bearer '+window.jarvisApiToken } : { 'Content-Type':'application/json' }; fetch('/api/audit', { method:'POST', headers: headers, body: JSON.stringify({ action: payload.action, entity: payload.entity, metadata: payload.metadata }) }).catch(()=>{}); }catch(e){}
        }
      }catch(e){}
      }

      function makeEmbedMap(elId, centerLat, centerLon, zoom=13){
        destroyMap(elId, elId === 'map' ? _mainMap : _miniMap);
        const el = document.getElementById(elId);
        if (!el) return null;
        const src = osmEmbedUrl(centerLat, centerLon, zoom);
        el.innerHTML = `<div class="embedMapWrap" data-src="${src}"><iframe class="embedMapIframe" src="${src}" style="width:100%;height:100%;border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe><div class="embedFallback" style="display:none;margin-top:8px;font-size:13px"><a class="openOsm" href="${src.replace('/export/embed.html','/')}" target="_blank">Open in OpenStreetMap</a> • <a class="reloadMap" href="#">Reload map</a></div></div>`;
        const iframe = el.querySelector('iframe');
        const fallback = el.querySelector('.embedFallback');
        if (iframe) {
          let loaded = false;
          iframe.addEventListener('load', ()=>{ loaded = true; if (fallback) { fallback.style.display = 'none'; hideMapIssueBanner(el); } });
          // If iframe hasn't loaded in 3s, show a fallback link to open map externally and send telemetry
          setTimeout(()=>{ try{ if (!loaded && fallback) { fallback.style.display = 'block'; showMapIssueBanner(el); sendMapEmbedFailure(centerLat, centerLon, (elId==='map'?'home':'home-mini')); } }catch(e){} }, 3000);
          // reload handler
          const rl = el.querySelector('.reloadMap'); if (rl) rl.addEventListener('click',(ev)=>{ ev.preventDefault(); try{ if (fallback) fallback.style.display='none'; hideMapIssueBanner(el); iframe.src = iframe.src; }catch(e){} });
        }
        if (elId === 'map') _mainMap = 'embed'; else _miniMap = 'embed';
        return true;
      }

      // Store current locations globally for focus buttons
      let _currentLocations = [];

      function renderLocationsOnMaps(locs){
        if (!locs || !locs.length) return;
        _currentLocations = locs;
        const c = locs[0];
        makeEmbedMap('map', c.lat, c.lon, 13);
      }

      // Initialize timeline focus buttons and map controls
      function initLocationControls(){
        // Timeline focus buttons
        document.querySelectorAll('.timeline-focus-btn').forEach(btn => {
          btn.addEventListener('click', (ev) => {
            ev.preventDefault();
            const lat = btn.getAttribute('data-lat');
            const lon = btn.getAttribute('data-lon');
            if (lat && lon) {
              makeEmbedMap('map', lat, lon, 14);
              // Scroll to map on mobile
              const mapContainer = document.querySelector('.location-map-container');
              if (mapContainer && window.innerWidth < 900) {
                mapContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
              }
            }
          });
        });

        // Refresh map button
        const refreshBtn = document.getElementById('refreshMapBtn');
        if (refreshBtn) {
          refreshBtn.addEventListener('click', (ev) => {
            ev.preventDefault();
            if (_currentLocations.length > 0) {
              const c = _currentLocations[0];
              makeEmbedMap('map', c.lat, c.lon, 13);
            }
            refreshBtn.style.transform = 'rotate(360deg)';
            setTimeout(() => { refreshBtn.style.transform = ''; }, 300);
          });
        }

        // Center map button
        const centerBtn = document.getElementById('centerMapBtn');
        if (centerBtn) {
          centerBtn.addEventListener('click', (ev) => {
            ev.preventDefault();
            if (_currentLocations.length > 0) {
              const c = _currentLocations[0];
              makeEmbedMap('map', c.lat, c.lon, 15);
            }
          });
        }
      }

      // Initialize the location map on page load with PHP data
      (function initLocationMapFromPHP(){
        <?php if (!empty($recentLocations)): ?>
        const serverLocs = <?php echo json_encode($recentLocations); ?>;
        if (serverLocs && serverLocs.length > 0) {
          _currentLocations = serverLocs;
          makeEmbedMap('map', serverLocs[0].lat, serverLocs[0].lon, 13);
        }
        <?php endif; ?>
        // Initialize controls after a short delay
        setTimeout(initLocationControls, 100);
      })();

      async function refreshLocations(){
        if (!window.jarvisApi) return;
        try{
          const data = await window.jarvisApi.get('/api/locations?limit=8', { ttl:0, force:true });
          if (!data || !data.ok) return;
          const locs = data.locations || [];
          // Hide no-location message if we have data
          if (locs.length > 0) { const msg = document.getElementById('noLocMsg'); if(msg) msg.style.display='none'; }
          // update table
          const tbody = document.getElementById('recentLocationsTbody');
          if (tbody) {
            tbody.innerHTML = '';
            for (const r of locs) {
              const tr = document.createElement('tr'); tr.setAttribute('data-id', r.id);
              tr.className = 'new';
              const addrText = (r.address && (r.address.city || r.address.display_name)) ? (r.address.city || r.address.display_name) : '';
              tr.innerHTML = `<td>${(r.created_at||'')}</td><td>${addrText}</td><td>${r.lat}</td><td>${r.lon}</td><td>${r.source||''}</td><td><button class="btn secondary focusLocationBtn" data-id="${r.id}">Focus</button></td>`;
              tbody.appendChild(tr);
              // remove 'new' class after animation
              setTimeout(()=>{ try{ tr.classList.remove('new'); }catch(e){} }, 1000);
            }
            // attach focus handlers
            document.querySelectorAll('.focusLocationBtn').forEach(b=>b.addEventListener('click', (ev)=>{
              const id = b.getAttribute('data-id');
              const loc = locs.find(x=>String(x.id)===String(id));
              if (loc) {
                if (_mainMap) { _mainMap.setView([parseFloat(loc.lat), parseFloat(loc.lon)], 13); }
                // open popup on nearest marker is complex; recompute maps
                renderLocationsOnMaps(locs);
              }
            }));
          }
          // render maps
          renderLocationsOnMaps(locs);
        }catch(e){}
      }

      if (enabled && token && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(async (pos)=>{
          try {
            const body = { lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy };
            // Store centrally for other features (voice meta)
            window.jarvisLastLoc = body;
            const r = await fetch('/api/location', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer '+token },
              body: JSON.stringify(body)
            });
            const data = await r.json().catch(()=>null);
            if (data && data.weather) {
               if (window.jarvisUpdateWeather) window.jarvisUpdateWeather(data);
            } else if (data) {
                const el = document.getElementById('jarvisWeather');
                if (el) el.textContent = 'Location saved: '+body.lat.toFixed(3)+', '+body.lon.toFixed(3);
            }
            // refresh map after new location
            if (typeof L !== 'undefined') setTimeout(()=>refreshLocations(), 350);
          } catch (e) {}
        }, ()=>{ if (typeof L !== 'undefined') setTimeout(()=>refreshLocations(), 50); }, { enableHighAccuracy: true, maximumAge: 10*60*1000, timeout: 8000 });
      } else {
        // just init map with existing locations
        if (typeof L !== 'undefined') setTimeout(()=>refreshLocations(), 50);
      }

      // Pre-fill weather info if server-side fetched
      if (lastWeather && document.getElementById('jarvisWeather')) {
        document.getElementById('jarvisWeather').textContent = lastWeather.desc + ' • ' + (lastWeather.temp_f !== null ? Math.round(lastWeather.temp_f) + '°F' : '');
      }

      // Show connect calendar prompt if not connected
      const googleToken = <?php echo json_encode(jarvis_oauth_get($userId, 'google')); ?>;
      const calCard = document.querySelector('#weatherCard');
      if (!googleToken || !googleToken.access_token) {
        const connectHtml = document.createElement('div');
        connectHtml.className = 'terminal';
        connectHtml.style.marginTop = '10px';
        connectHtml.innerHTML = `<div class="term-title">Google Calendar</div><div class="term-body"><p class="muted">Google Calendar is not connected. <a href="connect_google.php">Connect Calendar</a> to show events and receive reminders here.</p></div>`;
        if (calCard) calCard.appendChild(connectHtml);
      } else {
        // If connected, fetch upcoming events and show at top of Home
        (async function(){
          try{
            const resp = await (window.jarvisApi ? window.jarvisApi.get('/api/calendar?limit=6',{ttl:0,force:true}) : null);
            if (resp && resp.ok && Array.isArray(resp.events) && resp.events.length) {
              const eventsHtml = document.createElement('div');
              eventsHtml.className = 'terminal';
              eventsHtml.style.marginTop = '10px';
              let inner = '<div class="term-title">Upcoming Events</div><div class="term-body"><ul style="margin:0;padding-left:18px">';
              resp.events.forEach(e=>{ inner += `<li><strong>${e.summary||'(no title)'}</strong><div class="muted">${e.start_dt||''} ${e.location?(' • '+e.location):''}</div></li>` });
              inner += '</ul></div>';
              eventsHtml.innerHTML = inner;
              if (calCard) calCard.appendChild(eventsHtml);
            }
          }catch(e){}
        })();
      }
    })();
  </script>
  
  <!-- Local Calendar Events Script -->
  <script>
  (function(){
    try {
      const addBtn = document.getElementById('addLocalEventBtn');
      const modal = document.getElementById('addEventModal');
      const closeBtn = document.getElementById('closeEventModal');
      const form = document.getElementById('addLocalEventForm');
      
      if (!addBtn || !modal || !form) {
        console.warn('Add event elements not found on page');
        return;
      }
      
      // Set default date to today when modal opens
      addBtn.addEventListener('click', () => {
        try {
          const dateInput = form.querySelector('input[name="event_date"]');
          if (dateInput && !dateInput.value) {
            const today = new Date().toISOString().split('T')[0];
            dateInput.value = today;
          }
          modal.classList.add('active');
        } catch (e) {
          console.error('Error opening add event modal:', e);
        }
      });
      
      // Close modal handlers
      if (closeBtn) {
        closeBtn.addEventListener('click', () => {
          try {
            modal.classList.remove('active');
          } catch (e) {
            console.error('Error closing event modal:', e);
          }
        });
      }
      
      modal.addEventListener('click', (e) => {
        try {
          if (e.target === modal) modal.classList.remove('active');
        } catch (e) {
          console.error('Error handling modal click:', e);
        }
      });
      
      // Form submission handler
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          const formData = new FormData(form);
          const data = {
            title: (formData.get('title') || '').trim(),
            event_date: (formData.get('event_date') || '').trim(),
            event_time: (formData.get('event_time') || '').trim() || null,
            location: (formData.get('location') || '').trim() || null,
            notes: (formData.get('notes') || '').trim() || null
          };
          
          // Validate required fields
          if (!data.title || !data.event_date) {
            alert('Please fill in event title and date');
            return;
          }
          
          const token = window.jarvisJwt || '';
          if (!token) {
            alert('Authentication token not available');
            console.warn('No JWT token available for add event');
            return;
          }
          
          const fetchOptions = {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify(data)
          };
          
          const response = await fetch('/api/local-events', fetchOptions);
          const respData = await response.json();
          
          if (response.ok && respData && respData.ok) {
            modal.classList.remove('active');
            form.reset();
            window.location.reload();
          } else {
            const errorMsg = (respData && respData.error) || response.statusText || 'Unknown error';
            alert('Failed to add event: ' + errorMsg);
            console.error('Add event error:', respData);
          }
        } catch (err) {
          alert('Error adding event: ' + (err.message || 'Unknown error'));
          console.error('Add event exception:', err);
        }
      });
      
      // Delete event handlers
      document.querySelectorAll('.delete-local-event').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          e.preventDefault();
          try {
            const eventId = btn.getAttribute('data-id');
            if (!eventId) {
              console.warn('No event ID found on delete button');
              return;
            }
            
            if (!confirm('Delete this event?')) return;
            
            const token = window.jarvisJwt || '';
            if (!token) {
              alert('Authentication token not available');
              return;
            }
            
            const response = await fetch('/api/local-events/' + eventId, {
              method: 'DELETE',
              headers: { 'Authorization': 'Bearer ' + token }
            });
            
            const respData = await response.json();
            
            if (response.ok && respData && respData.ok) {
              const eventEl = btn.closest('.calendar-event');
              if (eventEl) eventEl.remove();
            } else {
              const errorMsg = (respData && respData.error) || response.statusText || 'Unknown error';
              alert('Failed to delete event: ' + errorMsg);
              console.error('Delete event error:', respData);
            }
          } catch (err) {
            alert('Error deleting event: ' + (err.message || 'Unknown error'));
            console.error('Delete event exception:', err);
          }
        });
      });
    } catch (err) {
      console.error('Error initializing event handlers:', err);
    }
  })();
  </script>
  
  <!-- No Leaflet required when using embedded maps -->
    
    <script>
    // Ambient pixel-dust particles (Pixar-like sparkle swirls)
    (function(){
      const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const canvas = document.getElementById('fxCanvas');
      if (!canvas || prefersReduced) return;
      const ctx = canvas.getContext('2d');
      let w=0,h=0, dpr = Math.max(1, Math.min(2, window.devicePixelRatio||1));
      function resize(){ w = window.innerWidth; h = window.innerHeight; canvas.width = Math.floor(w*dpr); canvas.height = Math.floor(h*dpr); canvas.style.width = w+'px'; canvas.style.height = h+'px'; ctx.setTransform(dpr,0,0,dpr,0,0); }
      resize(); window.addEventListener('resize', resize);

      const particles = []; const MAX = 160; const COLORS = ['#7dd3fc','#00d4ff','#1e78ff','#a5b4fc'];
      function rand(min,max){ return Math.random()*(max-min)+min; }
      function pick(arr){ return arr[Math.floor(Math.random()*arr.length)]; }

      function spawn(x, y, opts={}){
        for (let i=0;i<(opts.count||24);i++){
          const angle = rand(0, Math.PI*2);
          const speed = rand(0.4, 2.2) * (opts.power||1);
          const size = rand(1.25, 3.5) * (opts.size||1);
          const life = rand(0.8, 2.2) * (opts.life||1);
          const color = pick(COLORS);
          const shape = Math.random() < 0.6 ? 'square' : 'tri';
          particles.push({ x, y, vx: Math.cos(angle)*speed, vy: Math.sin(angle)*speed, ax: 0, ay: 0.02, size, life, alpha: 1, color, shape, t: 0 });
          if (particles.length > MAX) particles.shift();
        }
      }

      // Gentle ambient swirl
      let tGlobal = 0; function ambient(){
        tGlobal += 0.016; const cx = w*0.5 + Math.sin(tGlobal*0.6)*w*0.12; const cy = h*0.28 + Math.cos(tGlobal*0.8)*h*0.06;
        spawn(cx, cy, { count: 6, power: 0.8, size: 1, life: 1.4 });
      }

      function step(dt){
        for (let p of particles){
          p.t += dt; p.vx += p.ax*dt; p.vy += p.ay*dt; p.x += p.vx*dt*60; p.y += p.vy*dt*60;
          // Fade and shrink
          p.alpha = Math.max(0, 1 - (p.t / p.life));
          p.size *= 0.995;
        }
        // Remove dead
        for (let i=particles.length-1;i>=0;i--){ if (particles[i].alpha <= 0 || particles[i].size <= 0.2) particles.splice(i,1); }
      }
      function draw(){
        ctx.clearRect(0,0,w,h);
        // soft glow trail overlay to keep motion subtle
        ctx.fillStyle = 'rgba(2,7,18,0.06)'; ctx.fillRect(0,0,w,h);
        for (let p of particles){
          ctx.globalAlpha = Math.max(0, Math.min(1, p.alpha));
          ctx.fillStyle = p.color;
          // slight pixel-saw look via crisp edges
          if (p.shape === 'square'){
            ctx.fillRect(Math.floor(p.x), Math.floor(p.y), Math.max(1, Math.floor(p.size)), Math.max(1, Math.floor(p.size)));
          } else {
            ctx.beginPath();
            ctx.moveTo(Math.floor(p.x), Math.floor(p.y));
            ctx.lineTo(Math.floor(p.x + p.size), Math.floor(p.y));
            ctx.lineTo(Math.floor(p.x + p.size/2), Math.floor(p.y - p.size));
            ctx.closePath();
            ctx.fill();
          }
          // halo glow
          ctx.globalAlpha = Math.max(0, Math.min(0.25, p.alpha*0.25));
          ctx.fillStyle = 'rgba(0,212,255,0.15)';
          ctx.beginPath(); ctx.arc(p.x, p.y, Math.max(1.5, p.size*1.6), 0, Math.PI*2); ctx.fill();
          ctx.globalAlpha = 1;
        }
      }
      let last = performance.now();
      function loop(now){ const dt = Math.min(0.06, (now - last)/1000); last = now; ambient(); step(dt); draw(); requestAnimationFrame(loop); }
      requestAnimationFrame(loop);

      // Expose a burst API for UX hooks (e.g., on send)
      window.jarvisFx = window.jarvisFx || {};
      window.jarvisFx.spawnBurst = (x,y,opts)=>spawn(x,y,opts||{});

      // Hook message send to spawn a burst near chat input
      try {
        const sendBtn = document.getElementById('sendBtn');
        sendBtn && sendBtn.addEventListener('click', ()=>{
          const el = document.getElementById('messageInput');
          if (!el) return;
          const r = el.getBoundingClientRect();
          const x = (r.left + r.right)/2; const y = r.top; spawn(x, y, { count: 26, power: 1.4, size: 1.2, life: 1.6 });
        });
      } catch(e){}

      // Cursor sparkle: tiny trail follows pointer
      (function(){
        let lastX=0,lastY=0; const trail = (ev)=>{ const x = ev.clientX, y = ev.clientY; if (!x && !y) return; const speed = Math.hypot(x-lastX,y-lastY); lastX=x; lastY=y; spawn(x, y, { count: Math.min(8, Math.max(2, Math.floor(speed*0.05))), power: 0.6, size: 0.8, life: 0.9 }); };
        window.addEventListener('pointermove', trail, { passive: true });
      })();

      // Wake prompt sparkle
      try {
        const hero = document.querySelector('.hero');
        if (hero) {
          const r = hero.getBoundingClientRect(); spawn(r.left + r.width*0.5, r.top + r.height*0.35, { count: 32, power: 1.2 });
        }
      } catch(e){}
    })();
    </script>
    <script>
    // ----------------------------
    // Voice input / output + Notifications
    // ----------------------------
    (function(){

      const token = <?php echo $webJwt ? json_encode($webJwt) : 'null'; ?>;
      // Expose JWT to global scope for AJAX polling and API helper
      window.jarvisJwt = token;
      // Notify listeners that auth token is set
      if (token) window.dispatchEvent(new CustomEvent('jarvis.token.set'));
      window.jarvisBus && window.jarvisEmit && window.jarvisEmit('auth.token.set');
      const chatLog = document.getElementById('jarvisChatLog');
      const msgInput = document.getElementById('messageInput');
      const micBtn = document.getElementById('micBtn');
      const enableTTS = document.getElementById('enableTTS');
      const enableNotif = document.getElementById('enableNotif');
      const voiceOnlyMode = document.getElementById('voiceOnlyMode');
      let recognizing = false;
      let recognition = null;
      let lastInputType = 'text';

      // Helper to append a chat message to the output log (with animation)
      function appendMessage(content, who='jarvis'){
        if (!chatLog) return;
        const wrapper = document.createElement('div'); wrapper.className = 'msg ' + (who==='me' ? 'me' : 'jarvis');
        const bubble = document.createElement('div'); bubble.className = 'bubble';
        const body = document.createElement('div');
        if (typeof content === 'string') {
          body.textContent = content;
        } else if (content instanceof Node) {
          body.appendChild(content);
        } else {
          try { body.textContent = JSON.stringify(content); } catch(e){ body.textContent = String(content); }
        }
        const meta = document.createElement('div'); meta.className = 'meta';
        const now = new Date(); meta.textContent = now.toISOString().replace('T',' ').replace('Z',' UTC');
        bubble.appendChild(body); bubble.appendChild(meta); wrapper.appendChild(bubble);
        // mark new for animation
        wrapper.classList.add('new'); bubble.classList.add('new');
        chatLog.appendChild(wrapper);
        // expose last appended message for tests/debugging
        try{ if (typeof window !== 'undefined') {
          try { window._lastAppendedMessage = (typeof content === 'string') ? content : (content instanceof Node ? (content.textContent || '') : JSON.stringify(content)); } catch(e){}
        } } catch(e){}
        // remove 'new' class after animation completes
        setTimeout(()=>{ wrapper.classList.remove('new'); bubble.classList.remove('new'); }, 900);
        // scroll to bottom
        chatLog.parentNode.scrollTop = chatLog.parentNode.scrollHeight;
      }

      // Helper to append an audio message (uses appendMessage)
      function appendAudioMessage(blob, who='me', transcript=null){
        try{
          const container = document.createElement('div');
          const audioEl = document.createElement('audio');
          audioEl.controls = true; audioEl.preload = 'none'; audioEl.style.height='32px'; audioEl.style.width='240px';
          audioEl.src = URL.createObjectURL(blob);
          container.appendChild(audioEl);
          if (transcript) {
            const cap = document.createElement('div'); cap.style.marginTop='6px'; cap.style.fontStyle='italic'; cap.textContent = '"' + transcript + '"';
            container.appendChild(cap);
          }
          // status element to show recording/upload/send state
          const status = document.createElement('div'); status.className = 'audio-status muted'; status.style.marginTop='6px'; status.textContent = 'Recording...';
          container.appendChild(status);
          appendMessage(container, who);
          return container;
        }catch(e){ return null; }
      }

      // Touch/click helper to avoid duplicate events on touch devices
      function attachGesture(elem, handler){
        if (!elem) return;
        let recentTouch = 0;
        elem.addEventListener('touchstart', (ev)=>{ recentTouch = Date.now(); ev.preventDefault(); handler(ev); });
        elem.addEventListener('click', (ev)=>{ if (Date.now() - recentTouch < 700) return; handler(ev); });
      }

      // Wire mic/voice buttons to support touch and click and ensure permissions
      function micStartHandler(){ return async (ev)=>{
        ev.preventDefault && ev.preventDefault();
        if (recognizing) { if (recognition) recognition.stop(); recognizing=false; micBtn.classList.remove('active'); return; }
        if (!recognition) recognition = initRecognition();
        const ok = await ensureMicrophonePermission();
        if (!ok) { alert('Microphone not available or permission denied. Please allow microphone access in your browser.'); return; }
        try { if (recognition) recognition.start(); recognizing=true; micBtn.classList.add('active'); } catch(e){ recognizing=false; micBtn.classList.remove('active'); }
      }}
      attachGesture(micBtn, micStartHandler());
      // Manual one-shot Voice Command button: record audio, capture transcript, upload but DO NOT auto-send — wait for user to press Send.
      (function(){
        const vcBtn = document.getElementById('voiceCmdBtn');
        let vcActive = false;
        attachGesture(vcBtn, async (ev)=>{
          ev.preventDefault && ev.preventDefault();
          if (!recognition) recognition = initRecognition();
          if (!recognition) { alert('Voice recognition not supported in this browser.'); return; }
          const ok = await ensureMicrophonePermission(); if (!ok) { alert('Microphone not available or permission denied.'); return; }

          // Toggle recording state for one-shot command
          if (!vcActive) {
            vcActive = true; vcBtn.classList.add('active');
            // temporary onresult handler that captures transcript and stops recorder
            recognition.onresult = async (evt) => {
              const len = evt.results.length; const latest = evt.results[len-1];
              const text = latest[0].transcript || '';
              if (!text.trim()) return;
              _lastTranscript = text;
              appendMessage(text, 'me'); lastInputType='voice';
              // Stop recognition & recorder; onstop will upload and set pending-to-send
              try{ recognition.stop(); }catch(e){}
              try{ stopRecorder(); }catch(e){}
            };
            try { recognition.start(); recognizing=true; vcBtn.classList.add('active'); await startRecorder(); } catch(e){ console.error('voice-cmd start failed', e); vcActive=false; vcBtn.classList.remove('active'); }
          } else {
            // User clicked again to cancel/stop
            vcActive = false; vcBtn.classList.remove('active');
            try{ recognition.stop(); }catch(e){}
            try{ stopRecorder(); }catch(e){}
          }
        });
      })();


      // Toggle / auto-start behavior for Voice-Only mode
      if (voiceOnlyMode) {
        voiceOnlyMode.addEventListener('change', async ()=>{
          if (voiceOnlyMode.checked) {
            if (!recognition) recognition = initRecognition();
            if (!recognition) { alert('Voice recognition not supported in this browser.'); voiceOnlyMode.checked=false; return; }
            const ok = await ensureMicrophonePermission(); if (!ok) { alert('Microphone not available or permission denied.'); voiceOnlyMode.checked=false; return; }
            try { recognition.start(); recognizing = true; micBtn.classList.add('active'); await startRecorder(); } catch(e){ console.error('voice-only start failed',e); }
          } else {
            try{ if (recognition && recognizing) recognition.stop(); }catch(e){}
            try{ stopRecorder(); }catch(e){}
            micBtn.classList.remove('active');
          }
        });
      }

      // Polling for locations (auto-refresh)
      let _locPoll = null;
      let _locPostInterval = null;
      function startLocationPolling(){ if (_locPoll) clearInterval(_locPoll); _locPoll = setInterval(()=>{ try{ if (document.visibilityState==='visible') refreshLocations(); }catch(e){} }, 30000); }
      function stopLocationPolling(){ if (_locPoll) clearInterval(_locPoll); _locPoll = null; }

      // Periodic poster to send current location to server (e.g., every 5 minutes)
      function startLocationPoster(){ if (_locPostInterval) clearInterval(_locPostInterval); _locPostInterval = setInterval(async ()=>{ try{ if (document.visibilityState!=='visible') return; if (!enabled || !token || !navigator.geolocation) return; navigator.geolocation.getCurrentPosition(async (pos)=>{ try{ const body={lat:pos.coords.latitude, lon:pos.coords.longitude, accuracy:pos.coords.accuracy}; window.jarvisLastLoc = body; const r = await fetch('/api/location',{method:'POST', headers:{'Content-Type':'application/json','Authorization':'Bearer '+token}, body:JSON.stringify(body)}); const data = await r.json().catch(()=>null); if (data && data.weather && window.jarvisUpdateWeather) window.jarvisUpdateWeather(data); if (typeof refreshLocations === 'function') refreshLocations(); }catch(e){} }, ()=>{}, {enableHighAccuracy:true, timeout:8000}); }catch(e){} }, 300000); }
      function stopLocationPoster(){ if (_locPostInterval) clearInterval(_locPostInterval); _locPostInterval = null; }

      document.addEventListener('visibilitychange', ()=>{ if (document.visibilityState==='hidden') { stopLocationPolling(); stopLocationPoster(); } else { startLocationPolling(); startLocationPoster(); } });
      // start polling when page ready
      startLocationPolling();
      startLocationPoster();

      // Ensure maps resize on layout changes (e.g., mobile nav open/close)
      window.addEventListener('resize', ()=>{ try{ if (typeof _mainMap !== 'undefined' && _mainMap && typeof _mainMap.invalidateSize === 'function') _mainMap.invalidateSize(); if (typeof _miniMap !== 'undefined' && _miniMap && typeof _miniMap.invalidateSize === 'function') _miniMap.invalidateSize(); }catch(e){} });

      // Permission change listeners: run wake briefing on geolocation permission grant
      if (navigator.permissions && navigator.permissions.query) {
        try {
          navigator.permissions.query({name:'geolocation'}).then(p=>{ if (p && typeof p.onchange !== 'undefined') { p.onchange = ()=>{ if (p.state === 'granted') try{ postLocationAndRunWake(); }catch(e){} }; } if (p && p.state === 'granted') { try{ postLocationAndRunWake(); }catch(e){} } });
        } catch(e){}
      }

      // Use global notification helper to request permission on user gesture
      // (window.ensureNotificationPermission will be set by the permissions module)


      // MediaRecorder helpers to capture raw audio and upload to the server
      let _mediaStream = null, _mediaRecorder = null, _audioChunks = [], _lastTranscript = null, _voiceContextId = null, _pendingVoiceCmd = null;
      async function startRecorder(){
        if (_mediaRecorder && _mediaRecorder.state !== 'inactive') return true;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return false;
        try {
          _mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
          try { _mediaRecorder = new MediaRecorder(_mediaStream); } catch(e) { _mediaRecorder = null; }
          if (!_mediaRecorder) { _mediaStream.getTracks().forEach(t=>t.stop()); _mediaStream=null; return false; }
          _audioChunks = [];
          _voiceContextId = crypto.randomUUID ? crypto.randomUUID() : ('vc-'+Date.now()+'-'+Math.random());
          _mediaRecorder.ondataavailable = (evt)=>{ if (evt && evt.data) _audioChunks.push(evt.data); };
          _mediaRecorder.onstop = async ()=>{
            let blob = null;
            try {
              blob = new Blob(_audioChunks, { type: _audioChunks[0] ? _audioChunks[0].type || 'audio/webm' : 'audio/webm' });
              // Immediately add audio element to chat for user to see/play
              const container = appendAudioMessage(blob, 'me', _lastTranscript);
              // Upload in background; update UI when done
              const statusEl = container ? container.querySelector('.audio-status') : null;
              if (statusEl) statusEl.textContent = 'Uploading...';

              try {
                const resp = await sendAudioBlob(blob, _lastTranscript, Math.floor((blob.size/16000)), _voiceContextId);
                if (resp && resp.ok && resp.id) {
                  const link = document.createElement('a'); link.href = '/api/voice/' + resp.id + '/download'; link.target='_blank'; link.textContent = 'Download'; link.style.marginLeft='8px';
                  if (statusEl) { statusEl.textContent = 'Uploaded • id: ' + resp.id; statusEl.appendChild(link); }
                  else { const note = document.createElement('div'); note.className='muted'; note.style.marginTop='6px'; note.textContent = 'Uploaded • id: ' + resp.id; note.appendChild(link); if (container) container.appendChild(note); }

                  // Make this uploaded audio available to be sent via the Send button (but auto-send below)
                  window._pendingVoiceToSend = { id: resp.id, transcript: _lastTranscript, contextId: _voiceContextId };
                  const sendBtnEl = document.getElementById('sendBtn');
                  if (sendBtnEl) { sendBtnEl.dataset.pendingVoice = String(resp.id); sendBtnEl.textContent = 'Send (voice)'; }

                  // If there's a pending voice command (voice-only mode), send it now with voice_input_id
                  if (_pendingVoiceCmd && _pendingVoiceCmd.text) {
                    try {
                      await sendCommand(_pendingVoiceCmd.text, 'voice', { voice_input_id: resp.id, voice_context_id: _pendingVoiceCmd.contextId });
                      if (statusEl) statusEl.textContent = 'Sent • id: ' + resp.id;
                    } catch(e) { console.error('sendCommand after upload failed', e); if (statusEl) statusEl.textContent = 'Send failed'; }
                    _pendingVoiceCmd = null;
                    try{ if (window.jarvisJwt) fetch('/api/audit', { method:'POST', headers: { 'Content-Type':'application/json', 'Authorization': 'Bearer '+window.jarvisJwt }, body: JSON.stringify({ action: 'VOICE_AUTO_SENT', entity: 'voice', metadata: { id: resp.id } }) }); }catch(e){}
                  } else {
                    // Auto-send uploaded audio as a command (for manual voice-cmd flow)
                    try{
                      const sendText = (window._pendingVoiceToSend && window._pendingVoiceToSend.transcript) ? window._pendingVoiceToSend.transcript : '';
                      await sendCommand(sendText || '', 'voice', { voice_input_id: resp.id, voice_context_id: window._pendingVoiceToSend ? window._pendingVoiceToSend.contextId : null });
                      if (statusEl) statusEl.textContent = 'Sent • id: ' + resp.id;
                      try{ if (window.jarvisJwt) fetch('/api/audit', { method:'POST', headers: { 'Content-Type':'application/json', 'Authorization': 'Bearer '+window.jarvisJwt }, body: JSON.stringify({ action: 'VOICE_AUTO_SENT', entity: 'voice', metadata: { id: resp.id } }) }); }catch(e){}
                      // Clear pending
                      window._pendingVoiceToSend = null;
                    } catch(e) { console.error('auto-send failed', e); if (statusEl) statusEl.textContent = 'Send failed'; }
                  }

                  try{ if (window.jarvisJwt) fetch('/api/audit', { method:'POST', headers: { 'Content-Type':'application/json', 'Authorization': 'Bearer '+window.jarvisJwt }, body: JSON.stringify({ action: 'VOICE_UPLOAD_SUCCESS', entity: 'voice', metadata: { id: resp.id, size: blob.size } }) }); }catch(e){}
                } else {
                  if (statusEl) statusEl.textContent = 'Upload failed';
                  const note = document.createElement('div'); note.className='muted'; note.style.marginTop='6px'; note.textContent = 'Upload failed'; if (container) container.appendChild(note);
                  // Clear any pending send button flag
                  try { const sb = document.getElementById('sendBtn'); if (sb && sb.dataset && sb.dataset.pendingVoice) { delete sb.dataset.pendingVoice; sb.textContent = 'Send'; } } catch(e){}
                  try{ if (window.jarvisJwt) fetch('/api/audit', { method:'POST', headers:{ 'Content-Type':'application/json','Authorization':'Bearer '+window.jarvisJwt }, body: JSON.stringify({ action: 'VOICE_UPLOAD_FAILED', entity: 'voice' }) }); }catch(e){}
                }
              } catch(e) { console.warn('upload voice failed', e); const note = document.createElement('div'); note.className='muted'; note.style.marginTop='6px'; note.textContent = 'Upload failed'; if (container) container.appendChild(note); }
            } catch(e) { /* ignore */ }
            try{ if (_mediaStream) { _mediaStream.getTracks().forEach(t=>t.stop()); _mediaStream=null; } }catch(e){}
            _mediaRecorder = null; _audioChunks=[]; _lastTranscript=null; _voiceContextId=null; _pendingVoiceCmd = null;
          };
          _mediaRecorder.start();
          return true;
        } catch(e){ return false; }
      }
      function stopRecorder(){ try{ if (_mediaRecorder && _mediaRecorder.state !== 'inactive') _mediaRecorder.stop(); }catch(e){} }

      // POST audio blob + transcript to /api/voice
      async function sendAudioBlob(blob, transcript, durationMs, contextId){
        if (!blob) return null;
        try {
          const fd = new FormData();
          fd.append('file', blob, 'voice_input.webm');
          if (transcript) fd.append('transcript', transcript);
          if (typeof durationMs !== 'undefined' && durationMs !== null) fd.append('duration', String(durationMs));
          // meta: include channel/type and location if available
          const meta = { source:'web', input_type:lastInputType };
          if (contextId) meta.voice_context_id = contextId;
          if (window.jarvisLastLoc) { meta.location = window.jarvisLastLoc; }
          fd.append('meta', JSON.stringify(meta));
          const opts = { method: 'POST', body: fd, headers: {} };
          if (token) opts.headers['Authorization'] = 'Bearer ' + token;
          const resp = await fetch('/api/voice', opts);
          return resp.json().catch(()=>null);
        } catch(e){ return null; }
      }

      // Test Voice button removed

      // Try to initialize Web Speech Recognition if available
      function initRecognition(){
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition || null;
        if (!SpeechRec) return null;
        const r = new SpeechRec();
        r.lang = navigator.language || 'en-US';
        r.interimResults = false;
        r.maxAlternatives = 1;
        // Continuous to simulate "waiting" for keyword
        r.continuous = true; 
        r.onresult = async (evt) => {
          // Process latest result
          const len = evt.results.length;
          const latest = evt.results[len-1];
          const text = latest[0].transcript || '';
          if(!text.trim()) return;

          // Wake word logic: Must contain "jarvis"
          const lower = text.toLowerCase();
          if (lower.includes('jarvis')) {
            // Trigger command processing
            _lastTranscript = text;
            lastInputType = 'voice';
            const cleanText = text.replace(/jarvis/ig, '').trim(); 
            // If empty after strip, maybe they just said "Jarvis?" - we can still send it or prompt
            if (voiceOnlyMode && voiceOnlyMode.checked) {
              appendMessage(text, 'me'); // Show full text with Jarvis
              // Queue command to be sent after the recorded audio uploads
              _pendingVoiceCmd = { text: cleanText || text, contextId: _voiceContextId };
              // Stop recognition and recorder — onstop will upload and send the queued command
              try { stopRecorder(); } catch(e){}
              r.stop();
            } else {
              if(!msgInput.value) msgInput.value = text;
              else msgInput.value += ' ' + text;
            }
          }
        };
        r.onstart = async ()=>{
          // Attempt to start recorder in parallel
          try{ await startRecorder(); }catch(e){}
        };
        r.onend = () => { 
          recognizing = false; 
          micBtn.classList.remove('active'); 
          // If voice-only is enabled and we don't have a pending upload, auto-restart to keep listening for the wake word
          if (voiceOnlyMode && voiceOnlyMode.checked && !_pendingVoiceCmd) {
            setTimeout(()=>{
              try{ r.start(); recognizing = true; micBtn.classList.add('active'); startRecorder().catch(()=>{}); }catch(e){}
            }, 500);
            return;
          }
          try{ if (!voiceOnlyMode || !voiceOnlyMode.checked) stopRecorder(); }catch(e){}
        };
        r.onerror = (ev) => { 
          recognizing = false; 
          micBtn.classList.remove('active'); 
          try{ stopRecorder(); }catch(e){}
          if (voiceOnlyMode && voiceOnlyMode.checked) {
            setTimeout(()=>{ try{ r.start(); recognizing=true; micBtn.classList.add('active'); startRecorder().catch(()=>{}); }catch(e){} }, 1000);
          }
        };
        return r;
      }

      // Ensure microphone permission is requested when needed
      async function ensureMicrophonePermission(){
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return false;
        try {
          const s = await navigator.mediaDevices.getUserMedia({ audio: true });
          s.getTracks().forEach(t=>t.stop());
          return true;
        } catch(e){ return false; }
      }

      // When user types, mark type as text
      msgInput.addEventListener('input', ()=>{ lastInputType = 'text'; });

      // Use attachGesture handlers defined earlier for mic and voice command buttons to avoid duplicate events


      // Speak text using server-side TTS endpoint or fallback to Web SpeechSynthesis
      async function speakText(text){
        if (!enableTTS.checked || !text) return;
        // Try server-side TTS first
        try {
          const url = '/tts.php?text=' + encodeURIComponent(text);
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

      // Centralized AJAX command sender used by text + voice
      async function sendCommand(msg, type='text', meta={}){
        // allow empty msg for voice-only submissions when voice_input_id is provided
        if ((!msg || !(msg||'').trim()) && !(type === 'voice' && meta && meta.voice_input_id)) return null;
        const sendBtn = document.getElementById('sendBtn');
        msgInput.disabled = true; if (sendBtn) sendBtn.disabled = true;
        // if (window.jarvisShowLoader) jarvisShowLoader();
        if (window.jarvisEmit) window.jarvisEmit('command.sent', { text: msg, type });
        // Show status in chat log
        const processingMsg = document.createElement('div');
        processingMsg.className = 'msg jarvis processing';
        processingMsg.innerHTML = '<div class="bubble"><div class="loader-dots"><span></span><span></span><span></span></div> Processing...</div>';
        if (chatLog) {
            chatLog.appendChild(processingMsg);
            chatLog.parentNode.scrollTop = chatLog.parentNode.scrollHeight;
        }
        
        try {
          const isBrief = (msg || '').trim().toLowerCase() === 'briefing' || (msg || '').trim().toLowerCase() === '/brief';
          const payload = { text: msg, type, meta };
          const data = await (window.jarvisApi ? window.jarvisApi.post('/api/command', payload, { cacheTTL: isBrief ? 30000 : null }) : (async ()=>{ const r=await fetch('/api/command',{method:'POST',headers:{'Content-Type':'application/json','Authorization': token? 'Bearer '+token : ''},body:JSON.stringify(payload)}); return r.json(); })());

          // Remove processing indicator
          if (processingMsg && processingMsg.parentNode) processingMsg.parentNode.removeChild(processingMsg);

          if (data && typeof data.jarvis_response === 'string' && data.jarvis_response.trim() !== ''){
            appendMessage(data.jarvis_response, 'jarvis');
            // If server returned a voice_input_id, fetch the audio and append it as an audio bubble
            if (data && data.voice_input_id) {
              (async ()=>{
                try{
                  const headers = token ? { 'Authorization': 'Bearer ' + token } : {};
                  const r = await fetch('/api/voice/' + data.voice_input_id + '/download', { method: 'GET', headers });
                  if (r && r.ok) {
                    const blob = await r.blob();
                    appendAudioMessage(blob, 'jarvis', data.voice_transcript || null);
                  }
                }catch(e){ console.error('fetch voice blob failed', e); }
              })();
            }
            // Update weather UI if present in cards
            try {
              const cards = data.cards || {};
              const w = cards.weather || cards['weather'] || null;
              if (w && document.getElementById('jarvisWeather')) {
                let txt = '';
                if (typeof w.desc === 'string' && typeof w.temp_f !== 'undefined') txt = w.desc + ' • ' + (w.temp_f !== null ? Math.round(w.temp_f) + '°F' : '');
                else if (w && w.main && w.weather) {
                  const desc = (w.weather && w.weather[0] && w.weather[0].description) ? w.weather[0].description : '';
                  const t = (w.main && typeof w.main.temp !== 'undefined') ? Math.round(w.main.temp) : null;
                  txt = (desc ? (desc + ' • ') : '') + (t !== null ? (t + '°') : '');
                } else if (w && w.raw && w.raw.weather && w.raw.main) {
                  const desc = (w.raw.weather && w.raw.weather[0] && w.raw.weather[0].description) ? w.raw.weather[0].description : '';
                  const t = (w.raw.main && typeof w.raw.main.temp !== 'undefined') ? Math.round(w.raw.main.temp) : null;
                  txt = (desc ? (desc + ' • ') : '') + (t !== null ? (t + '°') : '');
                }
                if (txt) document.getElementById('jarvisWeather').textContent = txt;
              }
            } catch(e){}

            speakText(data.jarvis_response);
            showNotification(data.jarvis_response);
            window.jarvisEmit('command.response', data);
            if (window.jarvisInvalidateNotifications) window.jarvisInvalidateNotifications();
            if (window.jarvisInvalidateAudit) window.jarvisInvalidateAudit();
            return data;
          }

          // Fallback: Slack messaging endpoint
          const data2 = await (window.jarvisApi ? window.jarvisApi.post('/api/messages', { message: msg }) : (async ()=>{ const r2=await fetch('/api/messages',{method:'POST',headers:{'Content-Type':'application/json','Authorization': token? 'Bearer '+token : ''},body:JSON.stringify({message:msg})}); return r2.json(); })());
          
          // Remove processing indicator
          if (processingMsg && processingMsg.parentNode) processingMsg.parentNode.removeChild(processingMsg);
          
          if (data2 && data2.ok) {
            appendMessage('Sent to Slack (default channel)', 'jarvis');
            if (enableNotif && enableNotif.checked && Notification.permission === 'granted') showNotification('Slack message sent: '+msg);
            window.jarvisEmit('message.sent', { message: msg, slack: data2.slack || null });
            return data2;
          }

          let errText = 'Failed to send message';
          if (data2 && (data2.error || data2.message)) errText += ': ' + (data2.error || data2.message);
          else if (data2 && typeof data2 === 'object') errText += ': ' + JSON.stringify(data2);
          appendMessage(errText, 'jarvis');
          console.error('message send failed', data2);
          return null;
        } catch(e){
          // Remove processing indicator
          if (processingMsg && processingMsg.parentNode) processingMsg.parentNode.removeChild(processingMsg);
          appendMessage('Failed to process command: '+(e && e.message ? e.message : String(e)), 'jarvis');
          console.error('sendCommand error', e);
          return null;
        } finally {
          if (window.jarvisHideLoader) jarvisHideLoader();
          msgInput.disabled = false; if (sendBtn) sendBtn.disabled = false; msgInput.focus();
        }
      }

      const chatForm = document.getElementById('chatForm');
      async function handleSendAction(){
        if (window._sending) return;
        window._sending = true;
        try {
          try{ if (typeof window !== 'undefined') window._handleSendActionInvoked = true; }catch(e){}

          if (sendBtn && sendBtn.dataset && sendBtn.dataset.pendingVoice) {
            const pendingId = sendBtn.dataset.pendingVoice;
            // Ensure the uploaded voice file actually exists before sending; if HEAD fails, clear pending and fall through so regular text send works
            let headOk = false;
            try{
              const r = await fetch('/api/voice/'+pendingId+'/download', { method: 'HEAD', headers: sendBtn && (window.jarvisJwt||token) ? { 'Authorization': 'Bearer ' + (window.jarvisJwt||token) } : {} });
              headOk = !!(r && r.ok);
            }catch(e){ headOk = false; }
            if (!headOk) {
              try { delete sendBtn.dataset.pendingVoice; sendBtn.textContent = 'Send'; window._pendingVoiceToSend = null; } catch(e){}
              // fall through to regular text send behavior
            } else {
              try{ if (window.jarvisJwt||token) fetch('/api/audit', { method:'POST', headers:{ 'Content-Type':'application/json','Authorization':'Bearer '+(window.jarvisJwt||token) }, body: JSON.stringify({ action: 'VOICE_SUBMIT', entity: 'voice', metadata: { id: pendingId } }) }); }catch(e){}
              await sendCommand('', 'voice', { voice_input_id: pendingId });
              try{ delete sendBtn.dataset.pendingVoice; sendBtn.textContent = 'Send'; window._pendingVoiceToSend = null; }catch(e){}
              return;
            }
          }

          const msg = (msgInput.value || '').trim();
          if (!msg) return;
          appendMessage(msg, 'me');
          msgInput.value = '';
          await sendCommand(msg, lastInputType);
        } catch (e) {
          console.error('handleSendAction failed', e);
        } finally {
          window._sending = false;
        }
      }

      if (chatForm) chatForm.addEventListener('submit', async (ev) => {
        // test/debug hook
        try{ if (typeof window !== 'undefined') window._chatFormSubmitFired = true; } catch(e){}
        ev.preventDefault(); ev.stopPropagation(); ev.stopImmediatePropagation && ev.stopImmediatePropagation();
        await handleSendAction();
      });
      // Ensure clicking the Send button triggers the same submit handler reliably (handles some headless/browser race conditions)
      try {
        const sendBtnEl = document.getElementById('sendBtn');
        if (sendBtnEl && !sendBtnEl.dataset.sendListenerAttached) {
          const sendHandler = async (ev)=>{
            try{ if (typeof window !== 'undefined') window._sendBtnClicked = true; }catch(e){}
            ev && ev.preventDefault && ev.preventDefault();
            await handleSendAction();
          };
          sendBtnEl.addEventListener('click', sendHandler);
          sendBtnEl.addEventListener('pointerup', sendHandler);
          sendBtnEl.dataset.sendListenerAttached = '1';
        }
      } catch(e) { console.error('attach sendBtn click failed', e); }

      // Support Enter-to-send: Enter sends, Shift+Enter inserts a newline, Ctrl/Cmd/Alt+Enter ignored (user-intent modifiers)
      try{
        const msgInputEl = document.getElementById('messageInput');
        if (msgInputEl) {
          msgInputEl.addEventListener('keydown', async (ev)=>{
            if (ev.key === 'Enter' || ev.keyCode === 13) {
              // If user intends a newline or using modifier keys, skip sending
              if (ev.shiftKey || ev.ctrlKey || ev.metaKey || ev.altKey) return;
              ev.preventDefault(); ev.stopPropagation();
              await handleSendAction();
            }
          });
        }
      }catch(e){ console.error('Enter-to-send handler failed', e); }

      const sendBtnEl = document.getElementById('sendBtn');
      if (sendBtnEl && !sendBtnEl.dataset.sendListenerAttached) {
        const sendHandler = async (ev)=>{
          ev && ev.preventDefault && ev.preventDefault(); ev && ev.stopPropagation && ev.stopPropagation();
          await handleSendAction();
        };
        sendBtnEl.addEventListener('click', sendHandler);
        sendBtnEl.addEventListener('pointerup', sendHandler);
        sendBtnEl.dataset.sendListenerAttached = '1';
      }

      // On load, do not prompt for notifications automatically; just sync checkbox state
      if ('Notification' in window && enableNotif) { enableNotif.checked = (Notification.permission === 'granted'); }

      // Listen for notification updates from the global event bus and show new ones
      if (window.jarvisOn) {
        let lastNotifId = sessionStorage.getItem('jarvis_last_notif_id') ? parseInt(sessionStorage.getItem('jarvis_last_notif_id')) : 0;
        window.jarvisOn('notifications.updated', (ev)=>{
          const data = (ev && ev.detail) ? ev.detail : null;
          if (!data || !Array.isArray(data.notifications)) return;
          // find newest id (assumes notifications are descending by id)
          const newest = data.notifications.length ? (data.notifications[0].id || 0) : 0;
          if (newest > (lastNotifId||0)) {
            // show browser notification for each new item newer than lastNotifId
            data.notifications.slice().reverse().forEach(n=>{
              const nid = n.id || 0;
              if (nid > (lastNotifId||0)) {
                // show lightweight toast in-page
                if (document.getElementById('notifList')) {
                  const top = document.createElement('div'); top.className='success'; top.style.marginBottom='8px'; top.innerHTML = '<b>'+ (n.title||'') +'</b><div>'+ (n.body||'') +'</div>';
                  document.getElementById('notifList').insertBefore(top, document.getElementById('notifList').firstChild);
                }
                if (enableNotif && enableNotif.checked && 'Notification' in window && Notification.permission === 'granted') {
                  try { new Notification(n.title || 'JARVIS', { body: n.body || '' }); } catch(e){}
                }
              }
            });
            lastNotifId = newest;
            sessionStorage.setItem('jarvis_last_notif_id', String(lastNotifId));
          }
        });
      }

    })();
  </script>
  
  <!-- Video Recording Module -->
  <script>
  (function(){
    const videoBtn = document.getElementById('videoBtn');
    const videoModal = document.getElementById('videoRecordingModal');
    const closeVideoModal = document.getElementById('closeVideoModal');
    const videoPreview = document.getElementById('videoPreview');
    const startVideoRecBtn = document.getElementById('startVideoRecBtn');
    const stopVideoRecBtn = document.getElementById('stopVideoRecBtn');
    const switchCameraBtn = document.getElementById('switchCameraBtn');
    const videoRecIndicator = document.getElementById('videoRecIndicator');
    const videoTimer = document.getElementById('videoTimer');
    const videoStatus = document.getElementById('videoStatus');
    
    let videoStream = null;
    let videoRecorder = null;
    let videoChunks = [];
    let recordingStartTime = null;
    let timerInterval = null;
    let facingMode = 'user'; // 'user' for front camera, 'environment' for back
    
    // Check if we're on a mobile device
    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
    
    // Show/hide video button based on device capability
    if (videoBtn && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
      videoBtn.style.display = 'inline-flex';
      // Add mobile-specific class
      if (isMobile) {
        videoBtn.classList.add('mobile-video-btn');
      }
    }
    
    async function startVideoPreview() {
      try {
        if (videoStream) {
          videoStream.getTracks().forEach(t => t.stop());
        }
        
        const constraints = {
          video: { 
            facingMode: facingMode,
            width: { ideal: 1280 },
            height: { ideal: 720 }
          },
          audio: true
        };
        
        videoStream = await navigator.mediaDevices.getUserMedia(constraints);
        videoPreview.srcObject = videoStream;
        await videoPreview.play();
        videoStatus.textContent = 'Camera ready. Tap "Start Recording" to begin.';
        return true;
      } catch(e) {
        console.error('Camera access error:', e);
        videoStatus.textContent = 'Could not access camera: ' + e.message;
        return false;
      }
    }
    
    function stopVideoPreview() {
      if (videoStream) {
        videoStream.getTracks().forEach(t => t.stop());
        videoStream = null;
      }
      if (videoPreview) {
        videoPreview.srcObject = null;
      }
    }
    
    function formatTime(seconds) {
      const mins = Math.floor(seconds / 60);
      const secs = Math.floor(seconds % 60);
      return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }
    
    function startTimer() {
      recordingStartTime = Date.now();
      timerInterval = setInterval(() => {
        const elapsed = (Date.now() - recordingStartTime) / 1000;
        videoTimer.textContent = formatTime(elapsed);
      }, 100);
    }
    
    function stopTimer() {
      if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
      }
    }
    
    async function startRecording() {
      if (!videoStream) {
        const ok = await startVideoPreview();
        if (!ok) return;
      }
      
      videoChunks = [];
      
      try {
        // Try to get a supported MIME type
        const mimeTypes = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
        let selectedMime = '';
        for (const mime of mimeTypes) {
          if (MediaRecorder.isTypeSupported(mime)) {
            selectedMime = mime;
            break;
          }
        }
        
        const options = selectedMime ? { mimeType: selectedMime } : {};
        videoRecorder = new MediaRecorder(videoStream, options);
        
        videoRecorder.ondataavailable = (evt) => {
          if (evt.data && evt.data.size > 0) {
            videoChunks.push(evt.data);
          }
        };
        
        videoRecorder.onstop = async () => {
          stopTimer();
          videoRecIndicator.style.display = 'none';
          startVideoRecBtn.style.display = 'inline-flex';
          stopVideoRecBtn.style.display = 'none';
          
          if (videoChunks.length === 0) {
            videoStatus.textContent = 'No video data recorded.';
            return;
          }
          
          const mimeType = videoChunks[0].type || 'video/webm';
          const blob = new Blob(videoChunks, { type: mimeType });
          const duration = recordingStartTime ? Math.floor((Date.now() - recordingStartTime)) : 0;
          
          videoStatus.textContent = 'Uploading video...';
          
          try {
            const resp = await uploadVideoBlob(blob, duration);
            if (resp && resp.ok && resp.id) {
              videoStatus.innerHTML = '✅ Video uploaded! ID: ' + resp.id + ' <a href="/api/video/' + resp.id + '/download" target="_blank">Download</a>';
              
              // Add video message to chat
              appendVideoMessage(blob, 'me', resp.id);
              
              // Audit
              try {
                if (window.jarvisJwt) {
                  fetch('/api/audit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + window.jarvisJwt },
                    body: JSON.stringify({ action: 'VIDEO_UPLOAD_SUCCESS', entity: 'video', metadata: { id: resp.id, size: blob.size, duration_ms: duration } })
                  });
                }
              } catch(e){}
              
              // Close modal after success
              setTimeout(() => {
                closeModal();
              }, 2000);
            } else {
              videoStatus.textContent = '❌ Upload failed. Please try again.';
            }
          } catch(e) {
            console.error('Video upload error:', e);
            videoStatus.textContent = '❌ Upload error: ' + e.message;
          }
        };
        
        videoRecorder.start(1000); // Collect data every second
        startTimer();
        videoRecIndicator.style.display = 'flex';
        startVideoRecBtn.style.display = 'none';
        stopVideoRecBtn.style.display = 'inline-flex';
        videoStatus.textContent = 'Recording...';
        
      } catch(e) {
        console.error('Recording error:', e);
        videoStatus.textContent = 'Recording error: ' + e.message;
      }
    }
    
    function stopRecording() {
      if (videoRecorder && videoRecorder.state !== 'inactive') {
        videoRecorder.stop();
      }
    }
    
    async function uploadVideoBlob(blob, durationMs) {
      const fd = new FormData();
      const ext = blob.type.includes('mp4') ? 'mp4' : 'webm';
      fd.append('file', blob, 'video_input.' + ext);
      fd.append('duration', String(durationMs));
      
      const meta = {
        source: isMobile ? 'mobile-web' : 'web',
        input_type: 'video',
        facing_mode: facingMode,
        device: navigator.userAgent
      };
      if (window.jarvisLastLoc) meta.location = window.jarvisLastLoc;
      fd.append('meta', JSON.stringify(meta));
      
      const opts = { method: 'POST', body: fd, headers: {} };
      if (window.jarvisJwt) opts.headers['Authorization'] = 'Bearer ' + window.jarvisJwt;
      
      const resp = await fetch('/api/video', opts);
      return resp.json();
    }
    
    function appendVideoMessage(blob, sender, videoId) {
      const log = document.getElementById('jarvisChatLog');
      if (!log) return;
      
      const msgDiv = document.createElement('div');
      msgDiv.className = 'msg ' + sender;
      
      const bubble = document.createElement('div');
      bubble.className = 'bubble video-bubble';
      
      const video = document.createElement('video');
      video.controls = true;
      video.style.maxWidth = '100%';
      video.style.borderRadius = '8px';
      video.src = URL.createObjectURL(blob);
      
      const meta = document.createElement('div');
      meta.className = 'meta';
      meta.innerHTML = new Date().toLocaleString() + ' • video • <a href="/api/video/' + videoId + '/download" target="_blank">Download</a>';
      
      bubble.appendChild(video);
      bubble.appendChild(meta);
      msgDiv.appendChild(bubble);
      log.appendChild(msgDiv);
      log.scrollTop = log.scrollHeight;
    }
    
    async function switchCamera() {
      facingMode = facingMode === 'user' ? 'environment' : 'user';
      if (videoStream) {
        await startVideoPreview();
      }
      videoStatus.textContent = facingMode === 'user' ? 'Front camera' : 'Back camera';
    }
    
    function openModal() {
      videoModal.style.display = 'flex';
      startVideoPreview();
    }
    
    function closeModal() {
      stopRecording();
      stopTimer();
      stopVideoPreview();
      videoModal.style.display = 'none';
      videoTimer.textContent = '00:00';
      videoStatus.textContent = '';
      videoRecIndicator.style.display = 'none';
      startVideoRecBtn.style.display = 'inline-flex';
      stopVideoRecBtn.style.display = 'none';
    }
    
    // Event listeners
    if (videoBtn) {
      videoBtn.addEventListener('click', openModal);
    }
    if (closeVideoModal) {
      closeVideoModal.addEventListener('click', closeModal);
    }
    if (startVideoRecBtn) {
      startVideoRecBtn.addEventListener('click', startRecording);
    }
    if (stopVideoRecBtn) {
      stopVideoRecBtn.addEventListener('click', stopRecording);
    }
    if (switchCameraBtn) {
      switchCameraBtn.addEventListener('click', switchCamera);
    }
    
    // Close modal on escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && videoModal && videoModal.style.display !== 'none') {
        closeModal();
      }
    });
    
    // Close modal on backdrop click
    if (videoModal) {
      videoModal.addEventListener('click', (e) => {
        if (e.target === videoModal) {
          closeModal();
        }
      });
    }
    
  })();
  </script>
  
  <script>
  (function(){
    // Permission + Notification handling
    window.ensureNotificationPermission = async function(){
      if (!('Notification' in window)) return false;
      if (Notification.permission === 'granted') return true;
      try {
        const p = await Notification.requestPermission();
        return p === 'granted';
      } catch(e) { return false; }
    }

    async function checkAndShowPermissions(){
      const banner = document.getElementById('permBanner');
      const permListEl = document.getElementById('permList');
      if(!banner || !permListEl) return;
      const missing = [];
      try{
        // Geolocation/microphone/camera
        if (navigator.permissions) {
          const geo = await navigator.permissions.query({name:'geolocation'}).catch(()=>({state:'prompt'}));
          if (geo.state !== 'granted') missing.push('Location');
          const mic = await navigator.permissions.query({name:'microphone'}).catch(()=>({state:'prompt'}));
          if (mic.state !== 'granted') missing.push('Microphone');
          const cam = await navigator.permissions.query({name:'camera'}).catch(()=>({state:'prompt'}));
          if (cam.state !== 'granted') missing.push('Camera');
        } else {
          if(!navigator.geolocation) missing.push('Location');
        }
        // Notifications (special case)
        if ('Notification' in window && Notification.permission !== 'granted') {
          missing.push('Notifications');
        }
      }catch(e){/* ignore */}

      if(missing.length===0){ banner.style.display='none'; return; }
      permListEl.innerHTML='';
      missing.forEach(m=>{ const it=document.createElement('div'); it.className='perm-item'; it.textContent=m; permListEl.appendChild(it); });
      banner.style.display='flex';
    }

    async function postLocationAndRunWake(){
      if (!window.jarvisJwt || !navigator.geolocation) return;
      try{ if (sessionStorage.getItem('jarvis_wake_prompt_shown')) return; }catch(e){}
      try{
        navigator.geolocation.getCurrentPosition(async (pos)=>{
          try {
            const body = { lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy };
            const r = await fetch('/api/location', { method:'POST', headers:{ 'Content-Type':'application/json', 'Authorization': 'Bearer ' + (window.jarvisJwt || '') }, body: JSON.stringify(body) });
            const data = await r.json().catch(()=>null);
            if (data && data.weather && window.jarvisUpdateWeather) {
              window.jarvisUpdateWeather(data);
            } else if (data && document.getElementById('jarvisWeather')) {
               // ... fallback logic if weather not present but data is
               const w = data.weather || null;
               if (w) { /* handled by updateWeather */ } 
               else document.getElementById('jarvisWeather').textContent = 'Location saved: '+body.lat.toFixed(3)+', '+body.lon.toFixed(3);
            }
            // Run a wake briefing to surface the new weather & info
            try {
              const wakeResp = await sendCommand('wake','system');
              if (wakeResp) {
                // appended by sendCommand
              }
            } catch(e){}
            try{ sessionStorage.setItem('jarvis_wake_prompt_shown', String(Date.now())); }catch(e){}
            // Refresh locations in UI
            try{ if (typeof refreshLocations === 'function') refreshLocations(); }catch(e){}
          } catch(e){}
        }, ()=>{}, { enableHighAccuracy: true, timeout: 8000 });
      } catch(e){}
    }

    document.getElementById('permRequestBtn')?.addEventListener('click', async ()=>{
      // On mobile, request geolocation first
      const isMobile = window.innerWidth <= 599;
      
      if (isMobile) {
        // Mobile: request geolocation first
        try{ 
          await new Promise((res)=> navigator.geolocation.getCurrentPosition(()=>res(),()=>res(), {timeout:8000})); 
        }catch(e){}
        try{ 
          await navigator.mediaDevices.getUserMedia({ audio:true }); 
          // Auto-start dictation if permission granted
          const mb = document.getElementById('micBtn');
          if(mb && !mb.classList.contains('active')) mb.click();
        }catch(e){}
        try{ await navigator.mediaDevices.getUserMedia({ video:true }); }catch(e){}
      } else {
        // Desktop: request mic/cam/geo as before
        try{ 
          await navigator.mediaDevices.getUserMedia({ audio:true }); 
          // Auto-start dictation if permission granted
          const mb = document.getElementById('micBtn');
          if(mb && !mb.classList.contains('active')) mb.click();
        }catch(e){}
        try{ await navigator.mediaDevices.getUserMedia({ video:true }); }catch(e){}
        try{ await new Promise((res)=> navigator.geolocation.getCurrentPosition(()=>res(),()=>res(), {timeout:8000})); }catch(e){}
      }
      
      try{ await ensureNotificationPermission(); }catch(e){}
      // update UI (e.g., enableNotif checkbox) after permission attempt
      if (document.getElementById('enableNotif')) {
        document.getElementById('enableNotif').checked = ('Notification' in window && Notification.permission === 'granted');
      }
      setTimeout(()=>checkAndShowPermissions(),800);
      // Post location and run wake briefing if possible
      try{ postLocationAndRunWake(); }catch(e){}
    });

    // Initial state for the enableNotif checkbox
    document.addEventListener('DOMContentLoaded', ()=>{
      const cb = document.getElementById('enableNotif');
      if (cb && 'Notification' in window) {
        cb.checked = Notification.permission === 'granted';
        cb.addEventListener('change', async ()=>{
          if (cb.checked && Notification.permission !== 'granted') {
            const ok = await ensureNotificationPermission();
            cb.checked = ok;
            if (!ok) alert('Notifications not granted. Please allow notifications from your browser settings.');
          }
        });
      }
      checkAndShowPermissions();
      // If permissions look ok and we haven't shown the wake prompt, try to post location and run wake
      try{ if (sessionStorage.getItem('jarvis_wake_prompt_shown') === null) postLocationAndRunWake(); }catch(e){}

      // Fetch recent photos for preview
      (async function(){
        const el = document.getElementById('photoPreview');
        const emptyState = document.getElementById('photosEmptyState');
        const statsEl = document.getElementById('photosStats');

        // Home Channels Widget: Full implementation
        try{
          const hcMessages = document.getElementById('hcMessages');
          const hcMessageInput = document.getElementById('hcMessageInput');
          const hcSendBtn = document.getElementById('hcSendBtn');
          const hcChannelSelect = document.getElementById('hcChannelSelect');
          const hcAudit = document.getElementById('hcAudit');
          const hcEmojiBtn = document.getElementById('hcEmojiBtn');
          const hcMentionBtn = document.getElementById('hcMentionBtn');
          const hcHashtagBtn = document.getElementById('hcHashtagBtn');
          
          let currentChannel = 'local:rhats';
          
          // Load messages for current channel
          async function loadMessages(){
            if (!hcMessages) return;
            try {
              const r = await fetch(`/api/messages?channel=${currentChannel}&limit=20`, { 
                headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') } 
              });
              const j = await r.json().catch(()=>null);
              if (j && Array.isArray(j.messages)) {
                hcMessages.innerHTML = '';
                if (j.messages.length === 0) {
                  hcMessages.innerHTML = '<div class="loading-messages">No messages yet. Start the conversation!</div>';
                  return;
                }
                j.messages.reverse().forEach(m=>{
                  const group = document.createElement('div');
                  group.className = 'hc-message-group';
                  
                  const avatar = document.createElement('div');
                  avatar.className = 'hc-message-avatar';
                  avatar.textContent = (m.username || 'U')[0].toUpperCase();
                  
                  const content = document.createElement('div');
                  content.className = 'hc-message-content';
                  
                  const header = document.createElement('div');
                  header.className = 'hc-message-header';
                  const author = document.createElement('span');
                  author.className = 'hc-message-author';
                  author.textContent = m.username || 'you';
                  const time = document.createElement('span');
                  time.className = 'hc-message-time';
                  time.textContent = new Date(m.created_at).toLocaleTimeString();
                  header.appendChild(author);
                  header.appendChild(time);
                  
                  const text = document.createElement('div');
                  text.className = 'hc-message-text';
                  let msgHtml = (m.message_text || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                  msgHtml = msgHtml.replace(/(#([A-Za-z0-9_\-]+))/g, '<span class="hashtag">$1</span>');
                  msgHtml = msgHtml.replace(/@([A-Za-z0-9_\-]+)/g, '<span class="mention">$&</span>');
                  text.innerHTML = msgHtml;
                  
                  const actions = document.createElement('div');
                  actions.className = 'hc-message-actions';
                  actions.innerHTML = `
                    <button class="hc-msg-action-btn" data-action="reply">💬 Reply</button>
                    <button class="hc-msg-action-btn" data-action="react">😊 React</button>
                  `;
                  
                  content.appendChild(header);
                  content.appendChild(text);
                  content.appendChild(actions);
                  
                  group.appendChild(avatar);
                  group.appendChild(content);
                  hcMessages.appendChild(group);
                });
                hcMessages.scrollTop = hcMessages.scrollHeight;
              }
            } catch(e) {
              console.error('Failed to load messages:', e);
              if (hcMessages) hcMessages.innerHTML = '<div class="loading-messages">Failed to load messages</div>';
            }
          }
          
          // Load audit log
          async function loadAudit(){
            if (!hcAudit) return;
            try {
              const r = await fetch('/api/audit?limit=15', { 
                headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') } 
              });
              const j = await r.json().catch(()=>null);
              if (j && Array.isArray(j.audit)) {
                hcAudit.innerHTML = '';
                j.audit.forEach(a => {
                  const entry = document.createElement('div');
                  entry.className = 'muted';
                  const ts = new Date(a.created_at).toLocaleTimeString();
                  entry.textContent = `${ts} • ${a.event_type} • ${a.event_subject}`;
                  hcAudit.appendChild(entry);
                });
              }
            } catch(e) {
              console.error('Failed to load audit:', e);
            }
          }
          
          // Send message
          async function sendMessage(){
            const msg = hcMessageInput.value.trim();
            if (!msg) return;
            try {
              const r = await fetch('/api/messages', {
                method: 'POST',
                headers: { 
                  'Authorization': 'Bearer ' + (window.jarvisJwt || ''),
                  'Content-Type': 'application/json'
                },
                body: JSON.stringify({ message: msg, channel: currentChannel })
              });
              if (r.ok) {
                hcMessageInput.value = '';
                hcSendBtn.disabled = true;
                await loadMessages();
                await loadAudit();
              }
            } catch(e) {
              console.error('Failed to send message:', e);
            }
          }
          
          // Enable/disable send button
          if (hcMessageInput) {
            hcMessageInput.addEventListener('input', ()=>{
              if (hcSendBtn) hcSendBtn.disabled = !hcMessageInput.value.trim();
            });
            hcMessageInput.addEventListener('keydown', (e)=>{
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
              }
            });
          }
          
          // Send button
          if (hcSendBtn) {
            hcSendBtn.addEventListener('click', sendMessage);
          }
          
          // Channel switcher
          if (hcChannelSelect) {
            hcChannelSelect.addEventListener('change', ()=>{
              currentChannel = hcChannelSelect.value;
              loadMessages();
            });
          }
          
          // Emoji button
          if (hcEmojiBtn) {
            hcEmojiBtn.addEventListener('click', ()=>{
              if (hcMessageInput) {
                const emojis = ['😊','👍','❤️','😂','🎉','🚀','💡','✅'];
                const emoji = emojis[Math.floor(Math.random() * emojis.length)];
                hcMessageInput.value += emoji;
                hcMessageInput.focus();
                if (hcSendBtn) hcSendBtn.disabled = !hcMessageInput.value.trim();
              }
              try{ if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_CHANNEL_EMOJI','home_channel',{ timestamp: new Date().toISOString() }); }catch(e){}
            });
          }
          
          // Mention button
          if (hcMentionBtn) {
            hcMentionBtn.addEventListener('click', ()=>{
              if (hcMessageInput) {
                hcMessageInput.value += '@';
                hcMessageInput.focus();
              }
              try{ if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_CHANNEL_MENTION','home_channel',{ timestamp: new Date().toISOString() }); }catch(e){}
            });
          }
          
          // Hashtag button
          if (hcHashtagBtn) {
            hcHashtagBtn.addEventListener('click', ()=>{
              if (hcMessageInput) {
                hcMessageInput.value += '#';
                hcMessageInput.focus();
              }
              try{ if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_CHANNEL_HASHTAG','home_channel',{ timestamp: new Date().toISOString() }); }catch(e){}
            });
          }
          
          // Initial load
          loadMessages();
          loadAudit();
          
          // Refresh periodically
          setInterval(()=>{ loadMessages(); loadAudit(); }, 15000);
          
          // ===== HOME CHANNELS IMAGE ANNOTATION =====
          const hcAttachBtn = document.getElementById('hcAttachBtn');
          const hcFileInput = document.getElementById('hcFileInput');
          let hcAnnotationCanvas, hcAnnotationCtx, hcAnnotationImage, hcOriginalFile;
          let hcIsDrawing = false, hcCurrentTool = 'draw', hcCurrentColor = '#ef4444', hcBrushSize = 3;
          let hcDrawHistory = [], hcLastX = 0, hcLastY = 0;
          
          if (hcAttachBtn && hcFileInput) {
            hcAttachBtn.addEventListener('click', ()=> hcFileInput.click());
            
            hcFileInput.addEventListener('change', (e)=>{
              const file = e.target.files[0];
              if (!file) return;
              
              hcOriginalFile = file;
              const modal = document.getElementById('hcAnnotationModal');
              hcAnnotationCanvas = document.getElementById('hcAnnotationCanvas');
              hcAnnotationCtx = hcAnnotationCanvas.getContext('2d');
              
              if (file.type.startsWith('image/')) {
                // Image annotation
                const video = document.getElementById('hcAnnotationVideo');
                const videoControls = document.getElementById('hcVideoControls');
                video.style.display = 'none';
                videoControls.style.display = 'none';
                hcAnnotationCanvas.style.display = 'block';
                document.getElementById('hcAnnotationTitle').textContent = '✏️ Draw on Image';
                
                const reader = new FileReader();
                reader.onload = (ev)=>{
                  const img = new Image();
                  img.onload = ()=>{
                    hcAnnotationImage = img;
                    const maxW = Math.min(window.innerWidth*.6, img.width);
                    const maxH = Math.min(window.innerHeight*.6, img.height);
                    const scale = Math.min(maxW/img.width, maxH/img.height, 1);
                    hcAnnotationCanvas.width = img.width*scale;
                    hcAnnotationCanvas.height = img.height*scale;
                    hcAnnotationCtx.drawImage(img, 0, 0, hcAnnotationCanvas.width, hcAnnotationCanvas.height);
                    hcDrawHistory = [hcAnnotationCanvas.toDataURL()];
                    modal.classList.add('active');
                    if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_CHANNEL_IMAGE_ANNOTATION','home_channel',{file_name:file.name, timestamp:new Date().toISOString()});
                  };
                  img.src = ev.target.result;
                };
                reader.readAsDataURL(file);
              } else if (file.type.startsWith('video/')) {
                // Video annotation
                const video = document.getElementById('hcAnnotationVideo');
                const videoControls = document.getElementById('hcVideoControls');
                const videoTime = document.getElementById('hcVideoTime');
                video.style.display = 'block';
                videoControls.style.display = 'block';
                hcAnnotationCanvas.style.display = 'none';
                document.getElementById('hcAnnotationTitle').textContent = '🎬 Select Frame from Video';
                
                const reader = new FileReader();
                reader.onload = (ev)=>{
                  video.src = ev.target.result;
                  video.load();
                  video.addEventListener('timeupdate', ()=>{
                    const current = Math.floor(video.currentTime);
                    const duration = Math.floor(video.duration) || 0;
                    videoTime.textContent = `${current}s / ${duration}s - Pause and click "Capture Frame" to annotate`;
                  });
                  modal.classList.add('active');
                  if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_CHANNEL_VIDEO_ANNOTATION','home_channel',{file_name:file.name, timestamp:new Date().toISOString()});
                };
                reader.readAsDataURL(file);
              }
            });
          }
          
          // Capture frame from video (home widget)
          document.getElementById('hcCaptureFrame')?.addEventListener('click', ()=>{
            const video = document.getElementById('hcAnnotationVideo');
            const canvas = document.getElementById('hcAnnotationCanvas');
            if (!video || !canvas || !hcAnnotationCtx) return;
            
            const maxW = Math.min(window.innerWidth*.6, video.videoWidth);
            const maxH = Math.min(window.innerHeight*.6, video.videoHeight);
            const scale = Math.min(maxW/video.videoWidth, maxH/video.videoHeight, 1);
            canvas.width = video.videoWidth*scale;
            canvas.height = video.videoHeight*scale;
            hcAnnotationCtx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const img = new Image();
            img.onload = ()=>{
              hcAnnotationImage = img;
              hcDrawHistory = [canvas.toDataURL()];
              video.style.display = 'none';
              document.getElementById('hcVideoControls').style.display = 'none';
              canvas.style.display = 'block';
              document.getElementById('hcAnnotationTitle').textContent = '✏️ Draw on Video Frame';
              if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_CHANNEL_VIDEO_FRAME_CAPTURED','home_channel',{timestamp:new Date().toISOString(), video_time:Math.floor(video.currentTime)});
            };
            img.src = canvas.toDataURL();
          });
          
          // Drawing
          document.getElementById('hcAnnotationCanvas')?.addEventListener('mousedown', (e)=>{
            const canvas = document.getElementById('hcAnnotationCanvas');
            if (!canvas) return;
            hcIsDrawing = true;
            const rect = canvas.getBoundingClientRect();
            hcLastX = e.clientX - rect.left;
            hcLastY = e.clientY - rect.top;
            if (hcCurrentTool === 'text') {
              const text = prompt('Enter text:');
              if (text && hcAnnotationCtx) {
                hcAnnotationCtx.font = `${hcBrushSize*8}px Arial`;
                hcAnnotationCtx.fillStyle = hcCurrentColor;
                hcAnnotationCtx.fillText(text, hcLastX, hcLastY);
                hcDrawHistory.push(canvas.toDataURL());
              }
              hcIsDrawing = false;
            }
          });
          
          document.getElementById('hcAnnotationCanvas')?.addEventListener('mousemove', (e)=>{
            if (!hcIsDrawing || !hcAnnotationCtx) return;
            const canvas = document.getElementById('hcAnnotationCanvas');
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            if (hcCurrentTool === 'draw') {
              hcAnnotationCtx.beginPath();
              hcAnnotationCtx.moveTo(hcLastX, hcLastY);
              hcAnnotationCtx.lineTo(x, y);
              hcAnnotationCtx.strokeStyle = hcCurrentColor;
              hcAnnotationCtx.lineWidth = hcBrushSize;
              hcAnnotationCtx.lineCap = 'round';
              hcAnnotationCtx.stroke();
            } else if (hcCurrentTool === 'highlight') {
              hcAnnotationCtx.beginPath();
              hcAnnotationCtx.moveTo(hcLastX, hcLastY);
              hcAnnotationCtx.lineTo(x, y);
              hcAnnotationCtx.strokeStyle = hcCurrentColor + '80';
              hcAnnotationCtx.lineWidth = hcBrushSize*3;
              hcAnnotationCtx.lineCap = 'round';
              hcAnnotationCtx.stroke();
            }
            hcLastX = x; hcLastY = y;
          });
          
          document.getElementById('hcAnnotationCanvas')?.addEventListener('mouseup', ()=>{ if (hcIsDrawing) { hcIsDrawing = false; hcDrawHistory.push(document.getElementById('hcAnnotationCanvas').toDataURL()); } });
          document.getElementById('hcAnnotationCanvas')?.addEventListener('mouseleave', ()=>{ hcIsDrawing = false; });
          
          // Tools & colors for home widget modal
          document.querySelectorAll('#hcAnnotationModal [data-tool]').forEach(btn=>{ btn.addEventListener('click',()=>{ document.querySelectorAll('#hcAnnotationModal [data-tool]').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); hcCurrentTool = btn.getAttribute('data-tool'); }); });
          document.querySelectorAll('#hcAnnotationModal .color-btn').forEach(btn=>{ btn.addEventListener('click',()=>{ document.querySelectorAll('#hcAnnotationModal .color-btn').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); hcCurrentColor = btn.getAttribute('data-color'); }); });
          document.getElementById('hcBrushSize')?.addEventListener('input',(e)=>{ hcBrushSize = parseInt(e.target.value); document.getElementById('hcBrushSizeLabel').textContent = hcBrushSize+'px'; });
          document.getElementById('hcClearCanvas')?.addEventListener('click',()=>{ if (hcAnnotationCanvas && hcAnnotationImage && hcAnnotationCtx) { hcAnnotationCtx.clearRect(0,0,hcAnnotationCanvas.width,hcAnnotationCanvas.height); hcAnnotationCtx.drawImage(hcAnnotationImage, 0, 0, hcAnnotationCanvas.width, hcAnnotationCanvas.height); hcDrawHistory.push(hcAnnotationCanvas.toDataURL()); } });
          document.getElementById('hcUndoCanvas')?.addEventListener('click',()=>{ if (hcDrawHistory.length > 1) { hcDrawHistory.pop(); const img = new Image(); img.onload = ()=>{ hcAnnotationCtx.clearRect(0,0,hcAnnotationCanvas.width,hcAnnotationCanvas.height); hcAnnotationCtx.drawImage(img,0,0); }; img.src = hcDrawHistory[hcDrawHistory.length-1]; } });
          document.getElementById('hcCancelAnnotation')?.addEventListener('click',()=>{ document.getElementById('hcAnnotationModal').classList.remove('active'); hcFileInput.value=''; });
          document.getElementById('hcCloseAnnotation')?.addEventListener('click',()=>{ document.getElementById('hcAnnotationModal').classList.remove('active'); hcFileInput.value=''; });
          document.getElementById('hcSaveAnnotation')?.addEventListener('click',()=>{ 
            if (!hcAnnotationCanvas || !hcOriginalFile) return;
            hcAnnotationCanvas.toBlob((blob)=>{
              const annotatedFile = new File([blob], hcOriginalFile.name, {type:'image/png'});
              // Could upload here or insert into input field - for now just close and show success
              document.getElementById('hcAnnotationModal').classList.remove('active');
              alert('Image annotated! (Upload functionality can be added next)');
              if (window.jarvisApi && window.jarvisApi.auditLog) window.jarvisApi.auditLog('HOME_CHANNEL_IMAGE_SAVED','home_channel',{file_name:hcOriginalFile.name, timestamp:new Date().toISOString()});
            }, 'image/png');
          });
        }catch(e){
          console.error('Home channels widget error:', e);
        }

        if (!el || typeof window.jarvisJwt === 'undefined') return;
        try {
          const r = await fetch('/api/photos?limit=8', { headers: { 'Authorization': 'Bearer ' + window.jarvisJwt } });
          const j = await r.json().catch(()=>null);
          
          // Clear loading skeletons
          el.innerHTML = '';
          
          if (!j || !Array.isArray(j.photos) || j.photos.length === 0) {
            if (emptyState) emptyState.style.display = 'flex';
            return;
          }
          
          // Update stats
          if (statsEl) {
            const gpsCount = j.photos.filter(p => p.metadata && p.metadata.exif_gps).length;
            statsEl.innerHTML = `<span class="photos-stat">📷 ${j.count || j.photos.length} photos</span>` +
              (gpsCount > 0 ? `<span class="photos-stat">📍 ${gpsCount} with GPS</span>` : '');
          }
          
          j.photos.forEach((p, idx) => {
            const photoItem = document.createElement('div');
            photoItem.className = 'photo-gallery-item' + (idx === 0 ? ' featured' : '');
            
            const img = document.createElement('img');
            img.src = '/api/photos/' + p.id + '/download?thumb=1';
            img.alt = p.original_filename || 'Photo';
            img.loading = 'lazy';
            
            const overlay = document.createElement('div');
            overlay.className = 'photo-overlay';
            
            let overlayContent = '';
            if (p.metadata && p.metadata.exif_datetime) {
              overlayContent += `<span class="photo-date">${new Date(p.metadata.exif_datetime.replace(/:/g, '-').replace(' ', 'T')).toLocaleDateString()}</span>`;
            }
            if (p.metadata && p.metadata.exif_gps) {
              overlayContent += '<span class="photo-gps">📍</span>';
            }
            overlay.innerHTML = overlayContent;
            
            photoItem.appendChild(img);
            photoItem.appendChild(overlay);
            
            photoItem.addEventListener('click', ()=>{
              openPhotoModal(p.id, p.original_filename, p.metadata);
            });
            
            el.appendChild(photoItem);
          });
        } catch(e) { 
          console.error('Photo load error:', e);
          el.innerHTML = '';
          if (emptyState) emptyState.style.display = 'flex';
        }
      })();

      function openPhotoModal(id, title, metadata){
        let modal = document.getElementById('photoModal');
        if (!modal) {
          modal = document.createElement('div'); 
          modal.id='photoModal'; 
          modal.className = 'photo-modal';
          modal.innerHTML = `
            <div class="photo-modal-content">
              <button class="photo-modal-close" title="Close">&times;</button>
              <img id="photoModalImg" class="photo-modal-img" />
              <div class="photo-modal-info">
                <div id="photoModalCaption" class="photo-modal-caption"></div>
                <div id="photoModalMeta" class="photo-modal-meta"></div>
              </div>
              <div class="photo-modal-actions">
                <a id="photoModalDownload" href="#" download class="btn secondary">⬇️ Download</a>
                <a id="photoModalLocation" href="#" class="btn secondary" style="display:none">📍 View Location</a>
              </div>
            </div>
          `;
          modal.addEventListener('click', (e)=>{ if (e.target === modal) modal.remove(); });
          modal.querySelector('.photo-modal-close').addEventListener('click', ()=>modal.remove());
          document.body.appendChild(modal);
        }
        const imgEl = document.getElementById('photoModalImg');
        const capEl = document.getElementById('photoModalCaption');
        const metaEl = document.getElementById('photoModalMeta');
        const downloadEl = document.getElementById('photoModalDownload');
        const locationEl = document.getElementById('photoModalLocation');
        
        imgEl.src = '/api/photos/' + id + '/download';
        capEl.textContent = title || 'Photo';
        downloadEl.href = '/api/photos/' + id + '/download';
        
        let metaHtml = '';
        if (metadata) {
          if (metadata.exif_datetime) {
            metaHtml += `<span>📅 ${metadata.exif_datetime}</span>`;
          }
          if (metadata.exif_gps) {
            metaHtml += `<span>📍 ${metadata.exif_gps.lat.toFixed(4)}, ${metadata.exif_gps.lon.toFixed(4)}</span>`;
            locationEl.href = '/public/location_history.php?lat=' + metadata.exif_gps.lat + '&lon=' + metadata.exif_gps.lon;
            locationEl.style.display = 'inline-flex';
          } else {
            locationEl.style.display = 'none';
          }
        } else {
          locationEl.style.display = 'none';
        }
        metaEl.innerHTML = metaHtml;
      }
    });


  })();
  </script>

  <style>
    /* Small utility for compact buttons */
    .btn.btn-sm { padding:6px 10px; font-size:12px }

    /* Smart Home Widget Styles */
    .smart-home-card { overflow: hidden; position: relative; }
    .smart-home-header { display:flex; align-items:center; gap:10px; margin-bottom:8px }
    .home-status-pill { margin-left:auto; padding:6px 10px; font-size:12px; border-radius:999px; background:rgba(0,212,255,0.12); border:1px solid rgba(0,212,255,0.3); color:#9be8ff }

    .smart-home-widget { display:grid; grid-template-columns: 340px 1fr; gap:16px; align-items:stretch; }
    @media (max-width: 980px){ .smart-home-widget { grid-template-columns: 1fr; } }

    .thermostat-panel { position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; background: radial-gradient(1000px 400px at -20% -50%, rgba(0,212,255,0.12), transparent 60%), linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0)); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:18px }
    .thermostat-dial { --dial-angle: 180deg; width: 220px; height: 220px; border-radius: 50%; background: conic-gradient(#00d4ff 180deg, rgba(255,255,255,0.08) 0); display:grid; place-items:center; box-shadow: inset 0 0 26px rgba(0, 212, 255, 0.15), 0 0 26px rgba(0, 212, 255, 0.06); position:relative }
    .thermostat-dial::after { content:''; position:absolute; inset:12px; border-radius:50%; background: radial-gradient(200px 120px at 30% 20%, rgba(255,255,255,0.06), rgba(255,255,255,0.02)); border:1px solid rgba(255,255,255,0.06) }
    .thermo-temp { font-size: 54px; font-weight:800; color:#e6faff; text-shadow: 0 0 16px rgba(0,212,255,0.25) }
    .thermo-controls { position:absolute; bottom:16px; display:flex; gap:8px }
    .thermo-btn { width:40px; height:40px; border-radius:50%; border:1px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.06); color:#e6faff; font-size:18px; font-weight:700; cursor:pointer }
    .thermo-btn:hover { background:rgba(0,212,255,0.15); border-color: rgba(0,212,255,0.4) }
    .thermo-meta { margin-top:12px; font-size:13px; color:#a7c7d8; text-align:center }

    .home-right { display:flex; flex-direction:column; gap:14px }
    .scenes-row { display:flex; flex-wrap:wrap; gap:10px }
    .scene-chip { display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; background:linear-gradient(90deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)); border:1px solid rgba(255,255,255,0.06); color:#dbeafe; cursor:pointer; transition: transform .12s ease, box-shadow .12s ease }
    .scene-chip .scene-icon { filter: drop-shadow(0 0 8px rgba(0,212,255,.3)) }
    .scene-chip:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(0,212,255,.08) }
    .scene-chip.active { box-shadow: 0 0 0 2px var(--scene-color, #00d4ff) inset }

    .devices-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:12px }
    .device-tile { position:relative; padding:14px; border-radius:12px; border:1px solid rgba(255,255,255,0.06); background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02)); transition:transform .14s ease, box-shadow .14s ease }
    .device-tile:hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(0,212,255,.08) }
    .device-tile.on { border-color: rgba(0,212,255,.35); box-shadow: 0 0 0 1px rgba(0,212,255,.25) inset }
    .device-icon { font-size:26px; margin-bottom:8px }
    .device-name { font-weight:700; color:#e6faff }
    .device-sub { font-size:12px; color:#9fb8c8; margin-top:2px }
    .device-toggle { margin-top:10px; font-size:12px; padding:6px 10px; border-radius:8px; border:1px solid rgba(255,255,255,0.14); background:rgba(255,255,255,0.06); color:#cfefff; cursor:pointer }
    .device-tile.on .device-toggle { background: rgba(0,212,255,0.15); border-color: rgba(0,212,255,0.35); color: #00141a }

    .energy-row { display:flex; align-items:center; gap:14px; padding:10px; border-radius:12px; border:1px solid rgba(255,255,255,0.06); background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01)) }
    .energy-donut .donut { width:80px; height:80px; border-radius:50%; display:grid; place-items:center; background: conic-gradient(#00d4ff 160deg, rgba(255,255,255,0.08) 0) }
    .energy-donut .donut span { font-size:14px; font-weight:700; color:#e6faff; text-shadow:0 0 10px rgba(0,212,255,0.25) }
    .energy-meta { line-height:1.3 }
    .energy-title { font-weight:700; color:#cfefff }
    .energy-detail { font-size:12px; color:#9fb8c8 }
    /* Welcome Greeting Modal Styles */
    .welcome-greeting-modal {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.85);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      animation: fadeIn 0.3s ease-in;
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
      }
      to {
        opacity: 1;
      }
    }

    .welcome-greeting-content {
      background: linear-gradient(135deg, #0a0e27 0%, #1a1d2e 100%);
      border: 2px solid #00d4ff;
      border-radius: 12px;
      padding: 40px;
      max-width: 500px;
      width: 90%;
      box-shadow: 0 0 30px rgba(0, 212, 255, 0.2),
                  0 0 60px rgba(0, 212, 255, 0.1),
                  inset 0 0 20px rgba(0, 212, 255, 0.05);
      animation: slideUp 0.4s ease-out;
    }

    @keyframes slideUp {
      from {
        transform: translateY(30px);
        opacity: 0;
      }
      to {
        transform: translateY(0);
        opacity: 1;
      }
    }

    .greeting-header {
      text-align: center;
      margin-bottom: 24px;
      border-bottom: 1px solid rgba(0, 212, 255, 0.3);
      padding-bottom: 16px;
    }

    .greeting-header h2 {
      color: #00d4ff;
      font-size: 24px;
      margin: 0 0 8px 0;
      text-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
    }

    .greeting-subtitle {
      color: #7fb3d5;
      font-size: 12px;
      margin: 0;
      letter-spacing: 1px;
      text-transform: uppercase;
    }

    .greeting-message {
      background: rgba(0, 0, 0, 0.4);
      border-left: 3px solid #00ff00;
      padding: 16px;
      margin: 20px 0;
      border-radius: 4px;
      min-height: 60px;
      display: flex;
      align-items: center;
    }

    .greeting-message p {
      color: #e0e0e0;
      font-size: 14px;
      line-height: 1.6;
      margin: 0;
      font-family: 'Courier New', monospace;
    }

    .greeting-controls {
      display: flex;
      gap: 12px;
      justify-content: center;
      margin-top: 24px;
    }

    .greeting-controls button {
      padding: 12px 24px;
      border: none;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-primary {
      background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
      color: #000;
      box-shadow: 0 0 15px rgba(0, 212, 255, 0.4);
    }

    .btn-primary:hover {
      transform: scale(1.05);
      box-shadow: 0 0 25px rgba(0, 212, 255, 0.6);
    }

    .btn-primary:active {
      transform: scale(0.98);
    }

    .btn-secondary {
      background: transparent;
      color: #00d4ff;
      border: 2px solid #00d4ff;
    }

    .btn-secondary:hover {
      background: rgba(0, 212, 255, 0.1);
    }

    #playBtnIcon {
      display: inline-block;
      transition: transform 0.2s ease;
    }

    .playing #playBtnIcon {
      animation: pulse 0.6s infinite;
    }

    @keyframes pulse {
      0%, 100% {
        transform: scale(1);
      }
      50% {
        transform: scale(1.1);
      }
    }

    /* Settings, Shortcuts, and Connection Status restyle */
    .card-header-row { display:flex; align-items:center; gap:10px; }
    .card-header-row h3 { margin:0 }
    .settings-form .form-row { display:flex; flex-direction:column; gap:6px; margin-top:10px }
    .settings-form .form-inline { display:flex; gap:8px; align-items:center }
    .settings-card input { flex:1 }
    .shortcuts-card .shortcut-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap:12px }
    .shortcut-tile { display:block; padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,.06); background:linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)); text-decoration:none; color:inherit; transition:transform .12s ease, box-shadow .12s ease }
    .shortcut-tile:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,212,255,.08) }
    .shortcut-icon { font-size:22px; margin-bottom:6px }
    .shortcut-title { font-weight:700 }
    .shortcut-sub { font-size:12px; color:#97b3c4 }
    .status-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(180px,1fr)); gap:8px; margin-top:8px }
    .status-pill { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.04); font-size:12px; color:#d3ebf8 }
    .status-pill.ok { border-color: rgba(0,200,120,.35); background: rgba(0,200,120,.12); color:#b6f6d9 }
    .status-pill.warn { border-color: rgba(255,165,0,.35); background: rgba(255,165,0,.10); color:#ffe0b3 }
    .status-pill.info { border-color: rgba(0,212,255,.30); background: rgba(0,212,255,.10); color:#b6efff }
    .status-pill.copyable { cursor:pointer }
    .status-pill.copyable.copied { outline: 2px solid rgba(0,212,255,.4) }

    /* Home Channels Widget */
    .home-channels-widget { background: linear-gradient(180deg, #1a1d21, #121417); }
    .hc-header { padding:16px 20px; border-bottom:1px solid rgba(255,255,255,.06); background:#1a1d21 }
    .hc-title { display:flex; align-items:center; justify-content:space-between; gap:12px }
    .hc-body { display:grid; grid-template-columns:1fr 300px; min-height:400px }
    @media(max-width:900px){ .hc-body { grid-template-columns:1fr } }
    .hc-main { display:flex; flex-direction:column; border-right:1px solid rgba(255,255,255,.06) }
    .hc-channel-bar { padding:10px 16px; border-bottom:1px solid rgba(255,255,255,.06); background:rgba(255,255,255,.02) }
    .hc-channel-dropdown { width:100%; padding:8px 12px; background:rgba(0,0,0,.3); border:1px solid rgba(255,255,255,.15); border-radius:6px; color:#fff; font-size:14px; font-weight:600 }
    .hc-channel-dropdown:focus { outline:none; border-color:rgba(29,155,209,.5) }
    .hc-messages { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:8px; max-height:280px }
    .hc-message-group { display:flex; gap:10px; padding:8px; border-radius:6px; transition:background .15s ease }
    .hc-message-group:hover { background:rgba(255,255,255,.03) }
    .hc-message-avatar { width:32px; height:32px; border-radius:6px; background:linear-gradient(135deg,#d946ef,#ec4899); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; color:#fff; flex-shrink:0 }
    .hc-message-content { flex:1; min-width:0 }
    .hc-message-header { display:flex; align-items:baseline; gap:8px; margin-bottom:2px }
    .hc-message-author { font-weight:700; font-size:14px; color:#fff }
    .hc-message-time { font-size:11px; color:rgba(255,255,255,.4) }
    .hc-message-text { color:rgba(255,255,255,.9); font-size:14px; line-height:1.4; word-wrap:break-word }
    .hc-message-text .mention { background:rgba(29,155,209,.15); color:#1d9bd1; padding:1px 3px; border-radius:3px; font-weight:500 }
    .hc-message-text .hashtag { color:#1d9bd1; cursor:pointer; font-weight:500 }
    .hc-message-actions { margin-top:4px; display:flex; gap:6px }
    .hc-msg-action-btn { padding:3px 8px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); border-radius:4px; color:rgba(255,255,255,.6); font-size:11px; cursor:pointer }
    .hc-msg-action-btn:hover { background:rgba(255,255,255,.1); color:#fff }
    .hc-input-container { padding:12px 16px; border-top:1px solid rgba(255,255,255,.06); background:#1a1d21 }
    .hc-input-wrapper { background:#121417; border:2px solid rgba(255,255,255,.1); border-radius:6px }
    .hc-input-wrapper:focus-within { border-color:rgba(29,155,209,.5) }
    .hc-message-input { width:100%; min-height:36px; max-height:80px; padding:10px 12px; background:transparent; border:none; color:#fff; font-size:14px; resize:none; outline:none }
    .hc-message-input::placeholder { color:rgba(255,255,255,.4) }
    .hc-input-toolbar { display:flex; align-items:center; justify-content:space-between; padding:6px 10px; border-top:1px solid rgba(255,255,255,.06) }
    .hc-input-actions { display:flex; gap:6px }
    .hc-input-btn { width:28px; height:28px; border-radius:4px; background:rgba(255,255,255,.05); border:none; color:rgba(255,255,255,.6); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:16px }
    .hc-input-btn:hover { background:rgba(255,255,255,.1); color:#fff }
    .hc-send-btn { padding:5px 14px; background:linear-gradient(135deg,#d946ef,#ec4899); border:none; border-radius:4px; color:#fff; font-weight:600; font-size:13px; cursor:pointer }
    .hc-send-btn:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(217,70,239,.4) }
    .hc-send-btn:disabled { opacity:.5; cursor:not-allowed; transform:none }
    .hc-sidebar { background:#0d0f12; border-left:1px solid rgba(255,255,255,.06); display:flex; flex-direction:column }
    @media(max-width:900px){ .hc-sidebar { display:none } }
    .hc-sidebar-header { padding:12px 16px; border-bottom:1px solid rgba(255,255,255,.06); background:rgba(255,255,255,.02) }
    .hc-audit { flex:1; overflow-y:auto; padding:12px; font-size:12px; color:rgba(255,255,255,.7); max-height:360px }
    .hc-audit .muted { padding:6px 0; border-bottom:1px solid rgba(255,255,255,.02) }
    .hc-messages::-webkit-scrollbar, .hc-audit::-webkit-scrollbar { width:6px }
    .hc-messages::-webkit-scrollbar-track, .hc-audit::-webkit-scrollbar-track { background:rgba(0,0,0,.2) }
    .hc-messages::-webkit-scrollbar-thumb, .hc-audit::-webkit-scrollbar-thumb { background:rgba(255,255,255,.2); border-radius:3px }
    .loading-messages { text-align:center; padding:40px; color:rgba(255,255,255,.5); font-size:13px }

    /* Image Annotation Modal */
    .annotation-modal { position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.95); display:none; align-items:center; justify-content:center; padding:20px }
    .annotation-modal.active { display:flex }
    .annotation-container { background:#1a1d21; border-radius:12px; border:1px solid rgba(255,255,255,.1); max-width:90vw; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.8) }
    .annotation-header { padding:16px 20px; border-bottom:1px solid rgba(255,255,255,.1); display:flex; align-items:center; justify-content:space-between }
    .annotation-header h3 { margin:0; font-size:1.1rem; color:#fff }
    .annotation-close { background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:#fff; width:32px; height:32px; border-radius:6px; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center }
    .annotation-close:hover { background:rgba(255,255,255,.1) }
    .annotation-body { padding:20px; display:flex; gap:20px; overflow:auto }
    .annotation-canvas-wrapper { position:relative; background:#000; border-radius:8px; overflow:hidden }
    #annotationCanvas, #hcAnnotationCanvas { display:block; max-width:100%; max-height:calc(90vh - 200px); cursor:crosshair }
    .annotation-toolbar { min-width:200px; display:flex; flex-direction:column; gap:16px }
    .tool-group { background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.06); border-radius:8px; padding:12px }
    .tool-group-title { font-size:.75rem; font-weight:600; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px }
    .tool-btn { width:100%; padding:8px 12px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); border-radius:6px; color:rgba(255,255,255,.7); cursor:pointer; font-size:.9rem; margin-bottom:6px; display:flex; align-items:center; gap:8px; transition:all .15s ease }
    .tool-btn:hover { background:rgba(255,255,255,.1); color:#fff }
    .tool-btn.active { background:rgba(29,155,209,.2); border-color:rgba(29,155,209,.4); color:#fff }
    .color-palette { display:grid; grid-template-columns:repeat(4,1fr); gap:8px }
    .color-btn { width:100%; aspect-ratio:1; border-radius:6px; border:2px solid transparent; cursor:pointer; transition:all .15s ease }
    .color-btn:hover { transform:scale(1.1) }
    .color-btn.active { border-color:#fff; box-shadow:0 0 0 2px rgba(255,255,255,.3) }
    .brush-size { width:100%; margin-top:8px }
    .annotation-footer { padding:16px 20px; border-top:1px solid rgba(255,255,255,.1); display:flex; justify-content:space-between; gap:12px }
    .annotation-footer .btn { padding:10px 20px; border-radius:6px; font-weight:600; cursor:pointer; transition:all .2s ease }
    .btn-cancel { background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,.7) }
    .btn-cancel:hover { background:rgba(255,255,255,.1); color:#fff }
    .btn-save { background:linear-gradient(135deg,#d946ef,#ec4899); border:none; color:#fff }
    .btn-save:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(217,70,239,.4) }
    @media(max-width:900px){ .annotation-body { flex-direction:column } .annotation-toolbar { min-width:auto } }
    #addEventModal { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:9999 }
    #addEventModal.active { display:flex }
    #addEventModal .modal-content { background:linear-gradient(135deg,#1a1a2e,#16213e); border:1px solid rgba(255,255,255,.1); border-radius:12px; padding:24px; max-width:500px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,.5) }
    #addEventModal h4 { margin:0 0 20px 0; font-size:1.25rem; color:#fff }
    #addEventModal label { display:block; margin-top:12px; margin-bottom:6px; font-weight:500; color:rgba(255,255,255,.9) }
    #addEventModal input, #addEventModal textarea { width:100%; padding:10px 12px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); border-radius:6px; color:#fff; font-family:inherit; margin-bottom:8px }
    #addEventModal input:focus, #addEventModal textarea:focus { outline:none; background:rgba(255,255,255,.08); border-color:rgba(29,155,209,.4) }
  </style>

  <!-- Add Local Event Modal -->
  <div id="addEventModal" class="modal">
    <div class="modal-content">
      <h4>Add Local Event</h4>
      <form id="addLocalEventForm">
        <label>Event Title</label>
        <input type="text" name="title" required placeholder="Meeting, Birthday, etc." />
        <label>Date</label>
        <input type="date" name="event_date" required />
        <label>Time</label>
        <input type="time" name="event_time" value="09:00" />
        <label>Location (optional)</label>
        <input type="text" name="location" placeholder="Office, Home, etc." />
        <label>Notes (optional)</label>
        <textarea name="notes" rows="2" placeholder="Additional details..."></textarea>
        <div style="display:flex;gap:8px;margin-top:12px">
          <button type="submit" class="btn">Save Event</button>
          <button type="button" class="btn secondary" id="closeEventModal">Cancel</button>
        </div>
      </form>
    </div>
  </div>

</body></html>
