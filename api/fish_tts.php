<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fish_audio_store.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$text = trim((string) ($input['text'] ?? ''));
if ($text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'text is required']);
    exit;
}
$maxChars = 3500;
if (strlen($text) > $maxChars) {
    $text = substr($text, 0, $maxChars);
}

$settings = fish_audio_load_settings();
if (isset($input['settings']) && is_array($input['settings'])) {
    $merged = array_merge($settings, $input['settings']);
    if (isset($input['settings']['voicePresets']) && is_array($input['settings']['voicePresets'])) {
        $merged['voicePresets'] = array_merge($settings['voicePresets'], $input['settings']['voicePresets']);
    }
    $settings = fish_audio_sanitize_settings($merged);
}

$apiKey = trim((string) ($settings['apiKey'] ?? ''));
$endpoint = trim((string) ($settings['endpoint'] ?? ''));
$voiceId = fish_audio_resolve_voice_id($settings);
if ($apiKey === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Fish Audio API key is not set']);
    exit;
}
if ($endpoint === '' || !preg_match('#^https?://#i', $endpoint)) {
    http_response_code(400);
    echo json_encode(['error' => 'Fish Audio endpoint is invalid']);
    exit;
}
if ($voiceId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Voice ID is not set for selected style']);
    exit;
}

$payload = [
    'voiceId' => $voiceId,
    'modelId' => (string) ($settings['modelId'] ?? 'fishaudio-s2pro'),
    'text' => $text,
    'format' => (string) ($settings['format'] ?? 'mp3'),
    'speed' => (float) ($settings['speed'] ?? 1.0),
    'volume' => (float) ($settings['volume'] ?? 0.0),
    'stability' => (float) ($settings['stability'] ?? 1.0),
    'similarity' => (float) ($settings['similarity'] ?? 1.0),
];

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$raw = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if (!is_string($raw)) {
    $raw = '';
}
if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'Fish Audio request failed: ' . $curlErr]);
    exit;
}
if ($httpCode < 200 || $httpCode >= 300) {
    $msg = trim($raw);
    if ($msg === '') {
        $msg = 'HTTP ' . $httpCode;
    }
    http_response_code(502);
    echo json_encode(['error' => 'Fish Audio API error: ' . $msg]);
    exit;
}

// Fish returns binary audio; if JSON appears, bubble it up as an error payload.
$trimmed = ltrim($raw);
if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
    $j = json_decode($raw, true);
    if (is_array($j) && !empty($j['error'])) {
        http_response_code(502);
        echo json_encode(['error' => 'Fish Audio API error: ' . (string) $j['error']]);
        exit;
    }
}

if ($contentType === '') {
    $fmt = strtolower((string) ($payload['format'] ?? 'mp3'));
    $contentType = $fmt === 'wav' ? 'audio/wav' : ($fmt === 'ogg' ? 'audio/ogg' : 'audio/mpeg');
}

echo json_encode([
    'ok' => true,
    'mimeType' => $contentType,
    'audioBase64' => base64_encode($raw),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

