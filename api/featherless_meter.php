<?php
/**
 * Featherless tokenize + concurrency snapshot for the dashboard (server holds API key).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env.php';
memory_graph_load_env();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'provider_config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'featherless_api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$apiKey = trim((string) (function_exists('memory_graph_env') ? (memory_graph_env('FEATHERLESS_API_KEY', '') ?? '') : ''));
$ov = get_provider_api_key_override('featherless');
if ($ov !== '') {
    $apiKey = $ov;
}
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Featherless API key not configured. Set FEATHERLESS_API_KEY in .env or save a key in agent config.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$postBody = [];
if ($method === 'POST') {
    $rawIn = file_get_contents('php://input');
    $postBody = is_string($rawIn) && $rawIn !== '' ? json_decode($rawIn, true) : [];
    $postBody = is_array($postBody) ? $postBody : [];
}

$action = '';
if ($method === 'GET') {
    $action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
} else {
    $action = isset($postBody['action']) ? trim((string) $postBody['action']) : '';
}

if ($action === 'concurrency') {
    $out = memory_graph_featherless_concurrency_snapshot($apiKey);
    if (empty($out['ok'])) {
        http_response_code((int) ($out['httpCode'] ?? 502));
        echo json_encode(['error' => (string) ($out['error'] ?? 'Concurrency request failed')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'limit' => $out['limit'],
        'used_cost' => $out['used_cost'],
        'request_count' => $out['request_count'],
        'requests' => $out['requests'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'tokenize') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required for tokenize']);
        exit;
    }
    $model = isset($postBody['model']) ? trim((string) $postBody['model']) : '';
    $text = isset($postBody['text']) ? (string) $postBody['text'] : '';
    if (strlen($text) > 500000) {
        $text = substr($text, 0, 500000);
    }
    $out = memory_graph_featherless_tokenize($apiKey, $model, $text);
    if (empty($out['ok'])) {
        http_response_code((int) ($out['httpCode'] ?? 502));
        echo json_encode([
            'error' => (string) ($out['error'] ?? 'Tokenize failed'),
            'raw' => isset($out['raw']) ? (string) $out['raw'] : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'token_count' => (int) ($out['tokens'] ?? 0)], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action. Use action=concurrency (GET or POST) or POST action=tokenize with model and text.']);
