<?php
/**
 * Minimal HS256 JWT helper (no external deps)
 *
 * Env:
 *   JWT_SECRET (required for API auth)
 *   JWT_ISSUER (optional)
 */

function b64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string {
  $remainder = strlen($data) % 4;
  if ($remainder) $data .= str_repeat('=', 4 - $remainder);
  return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function jarvis_jwt_issue(int $userId, string $username, int $ttlSeconds=3600): string {
  $secret = getenv('JWT_SECRET') ?: '';
  if ($secret === '') throw new RuntimeException('JWT_SECRET not configured');
  $now = time();
  $payload = [
    'iss' => getenv('JWT_ISSUER') ?: 'jarvis',
    'sub' => (string)$userId,
    'usr' => $username,
    'iat' => $now,
    'exp' => $now + $ttlSeconds,
  ];
  $header = ['alg'=>'HS256','typ'=>'JWT'];
  $h = b64url_encode(json_encode($header));
  $p = b64url_encode(json_encode($payload));
  $sig = hash_hmac('sha256', "$h.$p", $secret, true);
  return "$h.$p." . b64url_encode($sig);
}

function jarvis_jwt_verify(?string $token): ?array {
  if (!$token) return null;
  $secret = getenv('JWT_SECRET') ?: '';
  if ($secret === '') return null;
  $parts = explode('.', $token);
  if (count($parts) !== 3) return null;
  [$h,$p,$s] = $parts;
  $expected = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
  if (!hash_equals($expected, $s)) return null;
  $payload = json_decode(b64url_decode($p), true);
  if (!is_array($payload)) return null;
  if (!isset($payload['exp']) || time() > (int)$payload['exp']) return null;
  return $payload;
}

function jarvis_bearer_token(): ?string {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
  if (!$hdr) return null;
  if (preg_match('/^Bearer\s+(.*)$/i', $hdr, $m)) return trim($m[1]);
  return null;
}
