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
  $body = "Hi {$username},\n\nConfirm your account:\n{$link}\n\n- JARVIS";
  $headers = 'From: ' . jarvis_mail_from();
  return @mail($toEmail, $subject, $body, $headers);
}

function jarvis_send_sms(?string $toPhone, string $text): bool {
  if (!$toPhone) return false;
  $sid   = getenv('TWILIO_SID');
  $token = getenv('TWILIO_AUTH_TOKEN');
  $from  = getenv('TWILIO_FROM_NUMBER');
  if (!$sid || !$token || !$from) return false;
  $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
  $payload = http_build_query(['From'=>$from,'To'=>$toPhone,'Body'=>$text]);
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_USERPWD=>$sid . ':' . $token,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $resp !== false && $code >= 200 && $code < 300;
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

  $modulusPart = pack('Ca*a*', 0x02, encode_length(strlen($modulus)) . $modulus);
  $exponentPart = pack('Ca*a*', 0x02, encode_length(strlen($exponent)) . $exponent);
  $components = $modulusPart . $exponentPart;
  $sequence = pack('Ca*a*', 0x30, encode_length(strlen($components)) . $components);

  $bitstring = chr(0x00) . $sequence;
  $algorithm = pack('H*', '300d06092a864886f70d0101010500');
  $subject = pack('Ca*a*', 0x30, encode_length(strlen($algorithm . pack('Ca*a*', 0x03, encode_length(strlen($bitstring)) . $bitstring))) . $algorithm . pack('Ca*a*', 0x03, encode_length(strlen($bitstring)) . $bitstring);

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
