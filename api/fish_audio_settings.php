<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fish_audio_store.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    echo json_encode([
        'ok' => true,
        'settings' => fish_audio_load_settings(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$result = fish_audio_save_settings($input);
if (!empty($result['error'])) {
    http_response_code(500);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

