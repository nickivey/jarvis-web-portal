<?php
/**
 * Instagram Basic Display API helpers
 *
 * Env:
 *  INSTAGRAM_CLIENT_ID
 *  INSTAGRAM_CLIENT_SECRET
 *  INSTAGRAM_REDIRECT_URI (optional; otherwise derived from SITE_URL)
 *
 * Docs: Basic Display API provides user profile and media (posts/reels).
 */

function jarvis_instagram_redirect_uri(): string {
  $explicit = getenv('INSTAGRAM_REDIRECT_URI');
  if ($explicit) return $explicit;
  $site = rtrim(getenv('SITE_URL') ?: '', '/');
  if ($site === '') {
    // fallback: attempt to derive from current request
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $site = $proto . '://' . $host;
  }
  return $site . '/public/instagram_callback.php';
}

function jarvis_instagram_auth_url(string $state): string {
  $clientId = getenv('INSTAGRAM_CLIENT_ID') ?: '';
  $redirectUri = jarvis_instagram_redirect_uri();
  $scope = 'user_profile,user_media';
  $params = http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => $scope,
    'response_type' => 'code',
    'state' => $state,
  ]);
  return 'https://api.instagram.com/oauth/authorize?' . $params;
}

function jarvis_http_json(string $url, string $method='GET', ?array $headers=null, $body=null): array {
  $ch = curl_init($url);
  $h = $headers ?: [];
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $h,
  ]);
  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }
  $raw = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $data = json_decode($raw ?: '', true);
  if (!is_array($data)) $data = ['_raw' => $raw, '_curl_error' => $err];
  $data['_http'] = $code;
  return $data;
}

function jarvis_instagram_exchange_code(string $code): array {
  $clientId = getenv('INSTAGRAM_CLIENT_ID') ?: '';
  $secret = getenv('INSTAGRAM_CLIENT_SECRET') ?: '';
  $redirect = jarvis_instagram_redirect_uri();

  $payload = http_build_query([
    'client_id' => $clientId,
    'client_secret' => $secret,
    'grant_type' => 'authorization_code',
    'redirect_uri' => $redirect,
    'code' => $code,
  ]);

  return jarvis_http_json(
    'https://api.instagram.com/oauth/access_token',
    'POST',
    ['Content-Type: application/x-www-form-urlencoded'],
    $payload
  );
}

function jarvis_instagram_exchange_long_lived(string $shortToken): array {
  $secret = getenv('INSTAGRAM_CLIENT_SECRET') ?: '';
  $url = 'https://graph.instagram.com/access_token?' . http_build_query([
    'grant_type' => 'ig_exchange_token',
    'client_secret' => $secret,
    'access_token' => $shortToken,
  ]);
  return jarvis_http_json($url);
}

function jarvis_instagram_refresh_long_lived(string $accessToken): array {
  $url = 'https://graph.instagram.com/refresh_access_token?' . http_build_query([
    'grant_type' => 'ig_refresh_token',
    'access_token' => $accessToken,
  ]);
  return jarvis_http_json($url);
}

function jarvis_instagram_latest_media(string $accessToken): ?array {
  $url = 'https://graph.instagram.com/me/media?' . http_build_query([
    'fields' => 'id,caption,media_type,media_url,permalink,timestamp,username',
    'limit' => 5,
    'access_token' => $accessToken,
  ]);
  $data = jarvis_http_json($url);
  if (empty($data['data']) || !is_array($data['data'])) return null;
  // Items are usually returned in reverse chronological order.
  return $data['data'][0] ?? null;
}

function jarvis_instagram_can_check_stories_basic_display(): bool {
  // Basic Display API does not provide Stories. We expose this to keep the UI consistent.
  return false;
}
