<?php

function jarvis_site_url(): string {
  $u = getenv('SITE_URL');
  return $u ? rtrim($u,'/') : '';
}

function jarvis_mail_from(): string {
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
    curl_close($ch);
    return $resp !== false && $code >= 200 && $code < 300;
  }
  $headers = 'From: ' . $from . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
  return @mail($toEmail, $subject, $bodyText, $headers);
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
