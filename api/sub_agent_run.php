<?php
/**
 * Run one sub-agent turn (same pipeline as run_sub_agent_chat) for dashboard / node panel.
 * Loads api/chat.php in library-only mode so headers and main chat loop are skipped.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (function_exists('set_time_limit')) {
    @set_time_limit(600);
}

define('MEMORYGRAPH_CHAT_LIBRARY_ONLY', true);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'chat.php';

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$name = trim((string) ($input['name'] ?? ''));
$prompt = trim((string) ($input['prompt'] ?? ''));
$chatSessionId = isset($input['chatSessionId']) ? trim((string) $input['chatSessionId']) : '';

if ($name === '' || $prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'name and prompt are required']);
    exit;
}

if (get_sub_agent_meta($name) === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Sub-agent not found']);
    exit;
}

$args = [
    'name' => $name,
    'prompt' => $prompt,
    'messages' => [],
];
if ($chatSessionId !== '') {
    $args['chatSessionId'] = $chatSessionId;
}

$result = memory_graph_execute_sub_agent_completion($providers, $args);

$flags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

if (isset($result['error'])) {
    http_response_code(502);
}

echo json_encode($result, $flags);
