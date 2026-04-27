<?php
/**
 * Run one sub-agent turn (same pipeline as run_sub_agent_chat) for dashboard / node panel.
 * Loads api/chat.php in library-only mode so headers and main chat loop are skipped.
 * Writes chat status under statusRequestId so the graph can poll the same way as Jarvis.
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

$resolvedMeta = resolve_sub_agent_meta($name);
if ($resolvedMeta === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Sub-agent not found']);
    exit;
}
$canonicalName = pathinfo((string) ($resolvedMeta['name'] ?? $name), PATHINFO_FILENAME);

$statusRequestId = isset($input['statusRequestId']) ? trim((string) $input['statusRequestId']) : '';
$statusRequestId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $statusRequestId);
if ($statusRequestId === '') {
    $statusRequestId = 'subagent_panel_' . bin2hex(random_bytes(8));
}

$prevRid = isset($GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID']) ? (string) $GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID'] : '';
$GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID'] = $statusRequestId;

$meta = $resolvedMeta;
$nodeId = is_array($meta) ? trim((string) ($meta['nodeId'] ?? '')) : '';
$ed = ['sub_agents' => ['toolName' => 'sub_agent_panel', 'arguments' => ['name' => $canonicalName]]];
if ($nodeId !== '') {
    $ed[$nodeId] = ['toolName' => 'sub_agent_panel', 'arguments' => ['name' => $canonicalName]];
}
$initialStatus = [
    'requestId' => $statusRequestId,
    'thinking' => true,
    'gettingAvailTools' => false,
    'checkingMemory' => false,
    'checkingInstructions' => false,
    'checkingMcps' => false,
    'checkingJobs' => false,
    'activeToolIds' => [],
    'activeMemoryIds' => [],
    'activeInstructionIds' => [],
    'activeMcpIds' => [],
    'activeJobIds' => [],
    'activeSubAgentIds' => $nodeId !== '' ? [$nodeId] : [],
    'executionDetailsByNode' => $ed,
    'lastGettingAvailTools' => false,
    'lastCheckingMemory' => false,
    'lastCheckingInstructions' => false,
    'lastCheckingMcps' => false,
    'lastCheckingJobs' => false,
    'lastActiveToolIds' => [],
    'lastActiveMemoryIds' => [],
    'lastActiveInstructionIds' => [],
    'lastActiveMcpIds' => [],
    'lastActiveJobIds' => [],
    'lastActiveSubAgentIds' => $nodeId !== '' ? [$nodeId] : [],
    'lastExecutionDetailsByNode' => $ed,
    'lastEventExpiresAtMs' => (int) round(microtime(true) * 1000) + 5500,
    'graphRefreshToken' => '',
    'activityLog' => [],
];
writeStatus($statusRequestId, $initialStatus);

$args = [
    'name' => $canonicalName,
    'prompt' => $prompt,
    'messages' => [],
];
if ($chatSessionId !== '') {
    $args['chatSessionId'] = $chatSessionId;
}

$result = null;
try {
    $result = memory_graph_execute_sub_agent_completion($providers, $args);
} finally {
    $path = statusDirPath() . DIRECTORY_SEPARATOR . $statusRequestId . '.json';
    $raw = @file_get_contents($path);
    $st = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
    if (!is_array($st)) {
        $st = [];
    }
    clearStatusFlags($st);
    $st['requestId'] = $statusRequestId;
    writeStatus($statusRequestId, $st);
    $GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID'] = $prevRid;
}

$flags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

if (!is_array($result)) {
    http_response_code(500);
    echo json_encode(['error' => 'Sub-agent run failed', 'statusRequestId' => $statusRequestId], $flags);
    exit;
}

$result['statusRequestId'] = $statusRequestId;

if (isset($result['error'])) {
    http_response_code(502);
}

echo json_encode($result, $flags);
