<?php
// Simple TTS proxy endpoint. For production, install a dedicated PHP TTS library
// or configure an external TTS service. This implementation uses Google Translate
// unofficial TTS endpoint as a fallback.

require_once __DIR__ . '/../helpers.php';

$text = trim((string)($_GET['text'] ?? $_POST['text'] ?? ''));
if ($text === '') { http_response_code(400); echo 'text required'; exit; }
$text = mb_substr($text, 0, 500);
$lang = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['lang'] ?? 'en');
if ($lang === '') $lang = 'en';

// Build Google Translate TTS URL
$q = rawurlencode($text);
$url = "https://translate.google.com/translate_tts?ie=UTF-8&tl=" . rawurlencode($lang) . "&client=gtx&q={$q}";

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    'Referer: ' . (jarvis_site_url() ?: 'http://localhost')
  ],
  CURLOPT_TIMEOUT => 10,
]);
$audio = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($audio === false || $http !== 200) {
  http_response_code(502); echo 'TTS service failed'; exit;
}

header('Content-Type: audio/mpeg');
header('Cache-Control: public, max-age=86400');
echo $audio;
exit;
