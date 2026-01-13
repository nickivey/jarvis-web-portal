<?php

function jarvis_site_url(): string {
  $u = getenv('SITE_URL');
  return $u ? rtrim($u,'/') : '';
}

function jarvis_mail_from(): string {
  // Prefer DB-configured setting, fallback to env, then default
  $from = jarvis_setting_get('MAIL_FROM');
  if ($from && is_string($from) && trim($from) !== '') return trim($from);
  return getenv('MAIL_FROM') ?: 'jarvis@nickivey.com';
}

function jarvis_send_confirmation_email(string $toEmail, string $username, string $token): bool {
  $base = jarvis_site_url();
  if (!$base) return false;
  $link = $base . '/public/confirm.php?user=' . rawurlencode($username) . '&token=' . rawurlencode($token);
  $subject = 'Confirm your JARVIS account';
  $bodyText = "Hi {$username},\n\nConfirm your account:\n{$link}\n\n- JARVIS";
  $bodyHtml = "<p>Hi " . htmlspecialchars($username) . ",</p><p>Confirm your account: <a href=\"{$link}\">Confirm email</a></p><p>- JARVIS</p>";
  // Prefer SendGrid if configured
  return jarvis_send_email($toEmail, $subject, $bodyText, $bodyHtml);
}

/**
 * Send email via SendGrid if SENDGRID_API_KEY is configured (in DB or env), otherwise fall back to mail().
 */
function jarvis_send_email(string $toEmail, string $subject, string $bodyText, ?string $bodyHtml = null): bool {
  $apiKey = jarvis_setting_get('SENDGRID_API_KEY') ?: getenv('SENDGRID_API_KEY') ?: '';
  $from = jarvis_mail_from();
  if ($apiKey) {
    $payload = [
      'personalizations' => [[ 'to' => [[ 'email' => $toEmail ]] ]],
      'from' => ['email' => $from],
      'subject' => $subject,
      'content' => [[ 'type' => 'text/plain', 'value' => $bodyText ]]
    ];
    if ($bodyHtml) {
      $payload['content'][] = ['type' => 'text/html', 'value' => $bodyHtml];
    }
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($payload),
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
      ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Log detailed error information
    if ($resp === false) {
      error_log("SendGrid curl error: {$curlError}");
      return false;
    }
    if ($code < 200 || $code >= 300) {
      error_log("SendGrid API error: HTTP {$code}, Response: " . substr($resp, 0, 512));
      error_log("SendGrid payload: " . json_encode($payload));
      return false;
    }
    error_log("SendGrid email sent successfully to {$toEmail}, HTTP {$code}");
    return true;
  }
  error_log("No SendGrid API key configured, falling back to PHP mail() for {$toEmail}");
  $headers = 'From: ' . $from . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
  $result = @mail($toEmail, $subject, $bodyText, $headers);
  if (!$result) {
    error_log("PHP mail() failed for {$toEmail}");
  }
  return $result;
}

function jarvis_send_sms(?string $toPhone, string $text): bool {
  if (!$toPhone) return false;
  // Prefer DB settings, fall back to env
  $sid   = jarvis_setting_get('TWILIO_SID') ?: getenv('TWILIO_SID');
  $token = jarvis_setting_get('TWILIO_AUTH_TOKEN') ?: getenv('TWILIO_AUTH_TOKEN');
  $from  = jarvis_setting_get('TWILIO_FROM_NUMBER') ?: getenv('TWILIO_FROM_NUMBER');
  if (!$sid || !$token || !$from) return false;

  $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
  $payload = http_build_query(['From'=>$from,'To'=>$toPhone,'Body'=>$text]);
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_USERPWD=>$sid . ':' . $token,
    CURLOPT_TIMEOUT=>10,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($resp === false) {
    error_log('Twilio curl error: ' . curl_error($ch));
    curl_close($ch);
    return false;
  }
  curl_close($ch);
  if ($code < 200 || $code >= 300) {
    error_log('Twilio API error: HTTP ' . $code . ' resp: ' . substr($resp,0,512));
    return false;
  }
  return true;
}

function jarvis_json_input(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: '', true);
  return is_array($data) ? $data : [];
}

function jarvis_respond(int $status, array $body): void {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($body);
  exit;
}

// ----------------------------
// Google ID token verification (JWKS)
// ----------------------------

function base64url_decode_no_eq(string $data): string {
  $remainder = strlen($data) % 4;
  if ($remainder) $data .= str_repeat('=', 4 - $remainder);
  return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function encode_length(int $length): string {
  if ($length < 128) return chr($length);
  $hex = ltrim(pack('N', $length), "\x00");
  $len = strlen($hex);
  return chr(0x80 | $len) . $hex;
}

function jwk_to_pem(string $n, string $e): string {
  $modulus = base64url_decode_no_eq($n);
  $exponent = base64url_decode_no_eq($e);
  $modulus = ltrim($modulus, "\x00");

  // Build ASN.1 INTEGERs for modulus and exponent
  $modulusPart = "\x02" . encode_length(strlen($modulus)) . $modulus;
  $exponentPart = "\x02" . encode_length(strlen($exponent)) . $exponent;

  // Sequence of modulus+exponent
  $components = $modulusPart . $exponentPart;
  $sequence = "\x30" . encode_length(strlen($components)) . $components;

  // Bit string wrapper
  $bitstring = "\x00" . $sequence;

  // Algorithm identifier for rsaEncryption
  $algorithm = pack('H*', '300d06092a864886f70d0101010500');

  // Subject: sequence(algorithm, bitstring)
  $subjectBody = $algorithm . "\x03" . encode_length(strlen($bitstring)) . $bitstring;
  $subject = "\x30" . encode_length(strlen($subjectBody)) . $subjectBody;

  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($subject),64) . "-----END PUBLIC KEY-----\n";
}

function jarvis_fetch_google_jwks(): ?array {
  static $cache = null;
  static $ts = 0;
  if ($cache && (time() - $ts) < 3600) return $cache;
  $jwks = @file_get_contents('https://www.googleapis.com/oauth2/v3/certs');
  if ($jwks === false) return null;
  $data = json_decode($jwks, true);
  if (!is_array($data) || empty($data['keys'])) return null;
  $cache = $data['keys'];
  $ts = time();
  return $cache;
}

// Default Google Calendar API key (hardcoded fallback as requested)
if (!defined('DEFAULT_GOOGLE_CALENDAR_API_KEY')) define('DEFAULT_GOOGLE_CALENDAR_API_KEY', 'AIzaSyDaoFH7o7pPu9VXG6XC8wuopaMF1SZlgGY');

function jarvis_google_calendar_key(): ?string {
  $k = jarvis_setting_get('GOOGLE_CALENDAR_API_KEY') ?: getenv('GOOGLE_CALENDAR_API_KEY');
  if ($k) return $k;
  return DEFAULT_GOOGLE_CALENDAR_API_KEY;
}

/**
 * Import events from the user's primary Google Calendar (requires oauth tokens in oauth_tokens table)
 * - Fetches events for the next 30 days and stores them in `user_calendar_events` table
 * - Creates a notification for events starting within the next 24 hours
 */
function jarvis_import_google_calendar(int $userId): array {
  $tok = jarvis_oauth_get($userId, 'google');
  if (!$tok || empty($tok['access_token'])) return ['ok'=>false,'error'=>'no_google_token'];
  $access = $tok['access_token'];
  $now = new DateTime('now', new DateTimeZone('UTC'));
  $timeMin = $now->format(DateTime::ATOM);
  $timeMax = $now->add(new DateInterval('P30D'))->format(DateTime::ATOM);
  $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events?timeMin=' . urlencode($timeMin) . '&timeMax=' . urlencode($timeMax) . '&singleEvents=true&orderBy=startTime&maxResults=250';
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>["Authorization: Bearer {$access}"], CURLOPT_TIMEOUT=>10]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($resp === false || $code < 200 || $code >= 300) return ['ok'=>false,'error'=>'google_api_error','http'=>$code];
  $data = json_decode($resp, true);
  if (!is_array($data) || empty($data['items'])) return ['ok'=>true,'imported'=>0];
  $imported = 0; $notified = 0;
  foreach ($data['items'] as $ev) {
    $eid = (string)($ev['id'] ?? uniqid('ev_'));
    $summary = isset($ev['summary']) ? (string)$ev['summary'] : null;
    $description = isset($ev['description']) ? (string)$ev['description'] : null;
    $location = isset($ev['location']) ? (string)$ev['location'] : null;
    // start / end can be dateTime or date
    $startRaw = $ev['start']['dateTime'] ?? ($ev['start']['date'] ? $ev['start']['date'] . 'T00:00:00Z' : null);
    $endRaw = $ev['end']['dateTime'] ?? ($ev['end']['date'] ? $ev['end']['date'] . 'T00:00:00Z' : null);
    $startDt = $startRaw ? (new DateTime($startRaw)) : null;
    $endDt = $endRaw ? (new DateTime($endRaw)) : null;
    $startSql = $startDt ? $startDt->format('Y-m-d H:i:s') : null;
    $endSql = $endDt ? $endDt->format('Y-m-d H:i:s') : null;
    $id = jarvis_store_calendar_event($userId, $eid, $summary, $description, $startSql, $endSql, $location, $ev);
    if ($id) $imported++;
    // notify if within next 24 hours
    if ($startDt) {
      $now = new DateTime('now', new DateTimeZone('UTC'));
      $diff = $startDt->getTimestamp() - $now->getTimestamp();
      if ($diff > 0 && $diff <= 24*60*60) {
        $note = "Upcoming event: " . ($summary ?: '(no title)') . " at " . $startDt->format('Y-m-d H:i');
        jarvis_notify($userId, 'reminder', 'Upcoming calendar event', $note, ['event_id'=>$eid,'calendar_event_db_id'=>$id]);
        jarvis_audit($userId, 'CALENDAR_EVENT_REMINDER', 'calendar', ['event_id'=>$eid,'db_id'=>$id,'starts_at'=>$startSql]);
        // email and SMS
        $user = jarvis_user_by_id($userId);
        if (!empty($user['email'])) jarvis_send_confirmation_email($user['email'], $user['username'] ?? ($user['email'] ?? 'user'), 'Event reminder: ' . ($summary ?: 'Event'));
        if (!empty($user['phone_e164'])) jarvis_send_sms($user['phone_e164'], $note);
        $notified++;
      }
    }
  }
  jarvis_audit($userId, 'CALENDAR_IMPORT', 'calendar', ['imported'=>$imported,'notified'=>$notified]);
  return ['ok'=>true,'imported'=>$imported,'notified'=>$notified];
}


function jarvis_fetch_weather(float $lat, float $lon): ?array {
  // Use Open-Meteo API (free, no API key required)
  // Docs: https://open-meteo.com/en/docs
  // Request Fahrenheit and mph units for US users
  $url = sprintf(
    'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&current=temperature_2m,apparent_temperature,weather_code,wind_speed_10m,relative_humidity_2m,is_day&daily=temperature_2m_max,temperature_2m_min,weather_code&timezone=auto&temperature_unit=fahrenheit&wind_speed_unit=mph',
    urlencode((string)$lat),
    urlencode((string)$lon)
  );
  
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  
  if ($resp === false || $code < 200 || $code >= 300) return null;
  $data = json_decode($resp, true);
  if (!is_array($data) || !isset($data['current'])) return null;
  
  $current = $data['current'];
  $weatherCode = (int)($current['weather_code'] ?? 0);
  $isDay = (bool)($current['is_day'] ?? true);
  
  // Map WMO weather codes to descriptions, emojis, and animation classes
  // https://open-meteo.com/en/docs (see WMO Weather interpretation codes)
  $weatherData = [
    0 => ['desc' => 'Clear sky', 'icon_day' => '☀️', 'icon_night' => '🌙', 'anim_day' => 'sunny', 'anim_night' => 'moon'],
    1 => ['desc' => 'Mainly clear', 'icon_day' => '🌤️', 'icon_night' => '🌙', 'anim_day' => 'partly-cloudy', 'anim_night' => 'moon'],
    2 => ['desc' => 'Partly cloudy', 'icon_day' => '⛅', 'icon_night' => '☁️', 'anim_day' => 'partly-cloudy', 'anim_night' => 'cloudy'],
    3 => ['desc' => 'Overcast', 'icon_day' => '☁️', 'icon_night' => '☁️', 'anim_day' => 'cloudy', 'anim_night' => 'cloudy'],
    45 => ['desc' => 'Foggy', 'icon_day' => '🌫️', 'icon_night' => '🌫️', 'anim_day' => 'foggy', 'anim_night' => 'foggy'],
    48 => ['desc' => 'Rime fog', 'icon_day' => '🌫️', 'icon_night' => '🌫️', 'anim_day' => 'foggy', 'anim_night' => 'foggy'],
    51 => ['desc' => 'Light drizzle', 'icon_day' => '🌦️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    53 => ['desc' => 'Drizzle', 'icon_day' => '🌦️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    55 => ['desc' => 'Dense drizzle', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    56 => ['desc' => 'Freezing drizzle', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    57 => ['desc' => 'Heavy freezing drizzle', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    61 => ['desc' => 'Light rain', 'icon_day' => '🌦️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    63 => ['desc' => 'Rain', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    65 => ['desc' => 'Heavy rain', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'stormy', 'anim_night' => 'stormy'],
    66 => ['desc' => 'Freezing rain', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    67 => ['desc' => 'Heavy freezing rain', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'stormy', 'anim_night' => 'stormy'],
    71 => ['desc' => 'Light snow', 'icon_day' => '🌨️', 'icon_night' => '🌨️', 'anim_day' => 'snowy', 'anim_night' => 'snowy'],
    73 => ['desc' => 'Snow', 'icon_day' => '❄️', 'icon_night' => '❄️', 'anim_day' => 'snowy', 'anim_night' => 'snowy'],
    75 => ['desc' => 'Heavy snow', 'icon_day' => '❄️', 'icon_night' => '❄️', 'anim_day' => 'snowy', 'anim_night' => 'snowy'],
    77 => ['desc' => 'Snow grains', 'icon_day' => '🌨️', 'icon_night' => '🌨️', 'anim_day' => 'snowy', 'anim_night' => 'snowy'],
    80 => ['desc' => 'Light showers', 'icon_day' => '🌦️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    81 => ['desc' => 'Showers', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'rainy', 'anim_night' => 'rainy'],
    82 => ['desc' => 'Heavy showers', 'icon_day' => '🌧️', 'icon_night' => '🌧️', 'anim_day' => 'stormy', 'anim_night' => 'stormy'],
    85 => ['desc' => 'Snow showers', 'icon_day' => '🌨️', 'icon_night' => '🌨️', 'anim_day' => 'snowy', 'anim_night' => 'snowy'],
    86 => ['desc' => 'Heavy snow showers', 'icon_day' => '❄️', 'icon_night' => '❄️', 'anim_day' => 'snowy', 'anim_night' => 'snowy'],
    95 => ['desc' => 'Thunderstorm', 'icon_day' => '⛈️', 'icon_night' => '⛈️', 'anim_day' => 'thunderstorm', 'anim_night' => 'thunderstorm'],
    96 => ['desc' => 'Thunderstorm with hail', 'icon_day' => '⛈️', 'icon_night' => '⛈️', 'anim_day' => 'thunderstorm', 'anim_night' => 'thunderstorm'],
    99 => ['desc' => 'Severe thunderstorm', 'icon_day' => '⛈️', 'icon_night' => '⛈️', 'anim_day' => 'thunderstorm', 'anim_night' => 'thunderstorm'],
  ];
  
  $info = $weatherData[$weatherCode] ?? ['desc' => 'Unknown', 'icon_day' => '🌡️', 'icon_night' => '🌡️', 'anim_day' => 'cloudy', 'anim_night' => 'cloudy'];
  $desc = $info['desc'];
  $icon = $isDay ? $info['icon_day'] : $info['icon_night'];
  $animClass = $isDay ? $info['anim_day'] : $info['anim_night'];
  
  // Get today's high/low from daily forecast
  $highTemp = null;
  $lowTemp = null;
  if (isset($data['daily']['temperature_2m_max'][0])) {
    $highTemp = (float)$data['daily']['temperature_2m_max'][0];
  }
  if (isset($data['daily']['temperature_2m_min'][0])) {
    $lowTemp = (float)$data['daily']['temperature_2m_min'][0];
  }
  
  // Build 7-day forecast array
  $forecast = [];
  if (isset($data['daily']['time']) && is_array($data['daily']['time'])) {
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    for ($i = 0; $i < count($data['daily']['time']) && $i < 7; $i++) {
      $date = $data['daily']['time'][$i];
      $dayCode = (int)($data['daily']['weather_code'][$i] ?? 0);
      $dayInfo = $weatherData[$dayCode] ?? ['desc' => 'Unknown', 'icon_day' => '🌡️', 'icon_night' => '🌡️', 'anim_day' => 'cloudy', 'anim_night' => 'cloudy'];
      $dayTs = strtotime($date);
      $dayName = $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : $days[date('w', $dayTs)]);
      
      $forecast[] = [
        'date' => $date,
        'day' => $dayName,
        'high_c' => isset($data['daily']['temperature_2m_max'][$i]) ? (float)$data['daily']['temperature_2m_max'][$i] : null,
        'low_c' => isset($data['daily']['temperature_2m_min'][$i]) ? (float)$data['daily']['temperature_2m_min'][$i] : null,
        'high_f' => isset($data['daily']['temperature_2m_max'][$i]) ? (float)$data['daily']['temperature_2m_max'][$i] : null, // Now in Fahrenheit from API
        'low_f' => isset($data['daily']['temperature_2m_min'][$i]) ? (float)$data['daily']['temperature_2m_min'][$i] : null, // Now in Fahrenheit from API
        'weather_code' => $dayCode,
        'desc' => $dayInfo['desc'],
        'icon' => $dayInfo['icon_day'],
        'icon_anim' => $dayInfo['anim_day'],
      ];
    }
  }
  
  return [
    'temp_c' => isset($current['temperature_2m']) ? (float)$current['temperature_2m'] : null,
    'temp_f' => isset($current['temperature_2m']) ? (float)$current['temperature_2m'] : null, // Now in Fahrenheit from API
    'high_c' => $highTemp,
    'high_f' => $highTemp, // Now in Fahrenheit from API
    'low_c' => $lowTemp,
    'low_f' => $lowTemp, // Now in Fahrenheit from API
    'feels_like_f' => isset($current['apparent_temperature']) ? (float)$current['apparent_temperature'] : null,
    'desc' => $desc,
    'icon' => $icon,
    'icon_anim' => $animClass,
    'is_day' => $isDay,
    'humidity' => isset($current['relative_humidity_2m']) ? (int)$current['relative_humidity_2m'] : null,
    'wind_speed' => isset($current['wind_speed_10m']) ? (float)$current['wind_speed_10m'] : null, // Now in mph from API
    'weather_code' => $weatherCode,
    'forecast' => $forecast,
    'raw' => $data,
  ];
}

/**
 * Convert an EXIF GPS coordinate (array with 3 rationals) to decimal degrees.
 * Supports values like ['52/1','30/1','1234/100'] or numeric arrays returned by exif_read_data.
 */
function jarvis_exif_gps_to_decimal($coord): ?float {
  if (!is_array($coord) || count($coord) < 3) return null;
  try {
    $parts = [];
    foreach ($coord as $c) {
      if (is_array($c) && isset($c['0']) && isset($c['1'])) {
        // sometimes represented as array
        $num = (float)$c[0]; $den = (float)$c[1];
        $parts[] = $den != 0 ? ($num / $den) : 0.0;
      } elseif (is_string($c) && strpos($c, '/') !== false) {
        [$n,$d] = explode('/', $c, 2);
        $parts[] = ((float)$n) / ((float)$d ?: 1.0);
      } else {
        $parts[] = (float)$c;
      }
    }
    if (count($parts) < 3) return null;
    $deg = $parts[0] + ($parts[1] / 60.0) + ($parts[2] / 3600.0);
    return $deg;
  } catch (Throwable $e) {
    return null;
  }
}

/**
 * Parse EXIF data for GPS coordinates and returns ['lat'=>..., 'lon'=>..., 'alt'=>...] or null.
 */
function jarvis_exif_get_gps(array $exif): ?array {
  if (empty($exif)) return null;
  $gps = $exif['GPS'] ?? ($exif['GPSInfo'] ?? null);
  if (!$gps || !is_array($gps)) return null;
  $lat = $lon = null;
  if (!empty($gps['GPSLatitude']) && !empty($gps['GPSLatitudeRef']) && !empty($gps['GPSLongitude']) && !empty($gps['GPSLongitudeRef'])) {
    $lat = jarvis_exif_gps_to_decimal($gps['GPSLatitude']);
    $lon = jarvis_exif_gps_to_decimal($gps['GPSLongitude']);
    if ($lat === null || $lon === null) return null;
    $latref = strtoupper(trim((string)$gps['GPSLatitudeRef']));
    $lonref = strtoupper(trim((string)$gps['GPSLongitudeRef']));
    if ($latref === 'S') $lat = -1 * $lat;
    if ($lonref === 'W') $lon = -1 * $lon;
  }
  $alt = null;
  if (!empty($gps['GPSAltitude'])) {
    $alt = jarvis_exif_gps_to_decimal([$gps['GPSAltitude']]);
  }
  if ($lat === null || $lon === null) return null;
  return ['lat'=>$lat,'lon'=>$lon,'altitude'=>$alt];
}

/**
 * Reprocess a single photo by id: generate thumbnail if missing, extract EXIF and create location if GPS present.
 * Returns an array with keys: ok(bool), messages(array)
 */
function jarvis_reprocess_photo(int $photoId, ?array $override = null): array {
  $pdo = jarvis_pdo(); if (!$pdo) return ['ok'=>false,'messages'=>['db not configured']];
  $stmt = $pdo->prepare('SELECT id,user_id,filename,thumb_filename,metadata_json FROM photos WHERE id=:id LIMIT 1');
  $stmt->execute([':id'=>$photoId]);
  $r = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$r) return ['ok'=>false,'messages'=>['photo not found']];
  $id = (int)$r['id']; $uid = (int)$r['user_id'];
  $baseDir = __DIR__ . '/storage/photos/' . $uid;
  $file = $baseDir . '/' . $r['filename'];
  $messages = [];
  if (!is_file($file)) return ['ok'=>false,'messages'=>['file missing']];

  // thumbnail
  $thumbName = $r['thumb_filename'];
  if (empty($thumbName) || !is_file($baseDir . '/' . $thumbName)) {
    try {
      $info = @getimagesize($file);
      if ($info && isset($info[0], $info[1]) && function_exists('imagecreatetruecolor')) {
        $max = 600;
        $ratio = min(1, $max / max($info[0], $info[1]));
        $tw = (int)round($info[0] * $ratio);
        $th = (int)round($info[1] * $ratio);
        $mime = $info['mime'] ?? '';
        $src = null;
        if ($mime === 'image/jpeg' || $mime === 'image/pjpeg') $src = imagecreatefromjpeg($file);
        elseif ($mime === 'image/png') $src = imagecreatefrompng($file);
        elseif ($mime === 'image/gif') $src = imagecreatefromgif($file);
        if ($src) {
          $dst = imagecreatetruecolor($tw, $th);
          imagecopyresampled($dst, $src, 0,0,0,0, $tw, $th, $info[0], $info[1]);
          $thumbName = 'thumb_' . $r['filename'] . '.jpg';
          imagejpeg($dst, $baseDir . '/' . $thumbName, 80);
          imagedestroy($dst); imagedestroy($src);
          $pdo->prepare('UPDATE photos SET thumb_filename = :t WHERE id = :id')->execute([':t'=>$thumbName, ':id'=>$id]);
          $messages[] = "thumbnail created: {$thumbName}";
        }
      }
    } catch (Throwable $e) { $messages[] = 'thumb failed: ' . $e->getMessage(); }
  }

  // EXIF (or override for tests)
  $meta = $r['metadata_json'] ? json_decode($r['metadata_json'], true) : [];
  // If override provides GPS lat/lon, set metadata and create location log immediately (used in tests)
  if (is_array($override) && isset($override['gps_lat'], $override['gps_lon']) && is_numeric($override['gps_lat']) && is_numeric($override['gps_lon'])) {
    $lat = (float)$override['gps_lat']; $lon = (float)$override['gps_lon'];
    $meta['exif_gps'] = ['lat'=>$lat,'lon'=>$lon,'altitude'=>null];
    $s = $pdo->prepare('INSERT INTO location_logs (user_id,lat,lon,accuracy_m,source) VALUES (:u,:la,:lo,:a,:s)');
    $s->execute([':u'=>$uid, ':la'=>$lat, ':lo'=>$lon, ':a'=>null, ':s'=>'photo']);
    $locId = (int)$pdo->lastInsertId(); if ($locId) $meta['photo_location_id'] = $locId;
    $meta['exif_present'] = true;
    $pdo->prepare('UPDATE photos SET metadata_json = :m WHERE id = :id')->execute([':m'=>json_encode($meta), ':id'=>$id]);
    $messages[] = 'gps override applied';
  }
  if (empty($meta['exif_present']) && function_exists('exif_read_data')) {
    try {
      $exif = @exif_read_data($file, 0, true);
      if (is_array($exif) && !empty($exif)) {
        if (!empty($exif['EXIF']['DateTimeOriginal'])) $meta['exif_datetime'] = (string)$exif['EXIF']['DateTimeOriginal'];
        $gps = jarvis_exif_get_gps($exif);
        if ($gps) {
          $meta['exif_gps'] = $gps;
          $s = $pdo->prepare('INSERT INTO location_logs (user_id,lat,lon,accuracy_m,source) VALUES (:u,:la,:lo,:a,:s)');
          $s->execute([':u'=>$uid, ':la'=>$gps['lat'], ':lo'=>$gps['lon'], ':a'=>null, ':s'=>'photo']);
          $locId = (int)$pdo->lastInsertId(); if ($locId) $meta['photo_location_id'] = $locId;
        }
        $meta['exif_present'] = true;
        $pdo->prepare('UPDATE photos SET metadata_json = :m WHERE id = :id')->execute([':m'=>json_encode($meta), ':id'=>$id]);
        $messages[] = 'exif extracted';
      }
    } catch (Throwable $e) { $messages[] = 'exif failed: ' . $e->getMessage(); }
  }

  return ['ok'=>true,'messages'=>$messages];
}
function jarvis_verify_google_id_token(string $idToken, string $clientId): ?array {
  $parts = explode('.', $idToken);
  if (count($parts) !== 3) return null;
  [$h64,$p64,$s64] = $parts;
  $header = json_decode(base64url_decode_no_eq($h64), true);
  $payload = json_decode(base64url_decode_no_eq($p64), true);
  if (!is_array($header) || !is_array($payload)) return null;
  $kid = $header['kid'] ?? null;
  $alg = $header['alg'] ?? null;
  if ($alg !== 'RS256' || !$kid) return null;
  $jwks = jarvis_fetch_google_jwks();
  if (!$jwks) return null;
  $jwk = null;
  foreach ($jwks as $k) { if (($k['kid'] ?? '') === $kid) { $jwk = $k; break; } }
  if (!$jwk) return null;
  $pem = jwk_to_pem($jwk['n'], $jwk['e']);
  $sig = base64url_decode_no_eq($s64);
  $verified = openssl_verify("$h64.$p64", $sig, $pem, OPENSSL_ALGO_SHA256);
  if ($verified !== 1) return null;
  // validate claims
  $iss = $payload['iss'] ?? '';
  if (!in_array($iss, ['https://accounts.google.com','accounts.google.com','https://accounts.google.com/'])) return null;
  if (($payload['aud'] ?? '') !== $clientId) return null;
  if (!isset($payload['exp']) || time() > (int)$payload['exp']) return null;
  return $payload;
}
