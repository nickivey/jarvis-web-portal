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
