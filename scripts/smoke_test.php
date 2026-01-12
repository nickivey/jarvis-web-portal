<?php
// Simple smoke test script for common JARVIS API flows
// Usage: php scripts/smoke_test.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

function ok($msg){ echo "[OK] $msg\n"; }
function fail($msg){ echo "[FAIL] $msg\n"; exit(1); }

// Use helper generator to get a JWT for user id 1
$token = trim(shell_exec('php scripts/generate_test_jwt.php 2>/dev/null'));
if (!$token) fail('Unable to generate test JWT (scripts/generate_test_jwt.php)');
ok('Generated test JWT');

$base = getenv('TEST_BASE') ?: 'http://localhost:8000';

// Ping
$r = @file_get_contents($base . '/api/ping');
$j = $r ? json_decode($r, true) : null;
if (!$j || empty($j['ok'])) fail('Ping failed');
ok('Ping OK');

// /api/me
$opts = [ 'http' => [ 'method' => 'GET', 'header' => "Authorization: Bearer $token\r\nAccept: application/json\r\n" ] ];
$ctx = stream_context_create($opts);
$r = @file_get_contents($base . '/api/me', false, $ctx);
$j = $r ? json_decode($r, true) : null;
if (!$j || empty($j['id'])) fail('/api/me failed');
ok('/api/me OK: user_id=' . ($j['id'] ?? '?'));

// Post a location
$locBody = json_encode(['lat'=>40.7128, 'lon'=>-74.0060, 'accuracy'=>10]);
$opts = [ 'http' => [ 'method' => 'POST', 'header' => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n", 'content'=>$locBody, 'ignore_errors'=>true ] ];
$r = @file_get_contents($base . '/api/location', false, stream_context_create($opts));
$j = $r ? json_decode($r, true) : null;
if (!$j || empty($j['location_id'])) fail('/api/location post failed');
ok('/api/location posted and returned id=' . $j['location_id']);

// GET locations
$r = @file_get_contents($base . '/api/locations?limit=5', false, $ctx);
$j = $r ? json_decode($r, true) : null;
if (!$j || empty($j['locations'])) fail('/api/locations failed');
ok('/api/locations OK (count=' . count($j['locations']) . ')');

// Upload sample audio (fetch remote sample then POST)
$tmp = sys_get_temp_dir() . '/jarvis_smoke_sample.webm';
// Create a small dummy audio-like payload for upload (no external fetches)
file_put_contents($tmp, random_bytes(512));
if (!is_file($tmp) || filesize($tmp) < 100) fail('Failed to create local sample audio file');

$ch = curl_init($base . '/api/voice');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
$post = [ 'file' => new CURLFile($tmp, 'audio/mpeg', 'sample.mp3'), 'transcript' => 'Test upload'];
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmp);
$j = $resp ? json_decode($resp, true) : null;
if (!$j || empty($j['id']) || $code < 200 || $code >= 300) fail('/api/voice upload failed: http=' . $code . ' resp=' . substr($resp,0,200));
$voiceId = $j['id'];
ok('/api/voice upload OK id=' . $voiceId);

// Send command with voice_input_id (auto-response)
$cmd = json_encode(['text'=>'', 'type'=>'voice', 'meta'=>['voice_input_id'=>$voiceId]]);
$opts = [ 'http' => [ 'method' => 'POST', 'header' => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n", 'content'=>$cmd, 'ignore_errors'=>true ] ];
$r = @file_get_contents($base . '/api/command', false, stream_context_create($opts));
$j = $r ? json_decode($r, true) : null;
if (!$j || !isset($j['jarvis_response'])) fail('/api/command with voice failed');
ok('/api/command with voice OK (reply length=' . strlen($j['jarvis_response']) . ')');

// HEAD download voice
$ch = curl_init($base . '/api/voice/' . $voiceId . '/download');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code < 200 || $code >= 400) fail('Voice download HEAD failed: http=' . $code);
ok('Voice download HEAD OK');

ok('Smoke tests completed successfully');
return 0;
