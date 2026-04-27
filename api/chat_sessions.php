<?php
/**
 * Simple UI: list/load/delete chat sessions backed by runtime/chat-history/exchanges.json.
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'chat_history_store.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$flags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

if ($method === 'GET') {
    $action = isset($_GET['action']) ? trim((string) $_GET['action']) : 'list_sessions';
    if ($action === 'list_sessions') {
        $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 80;
        echo json_encode(['ok' => true, 'sessions' => list_chat_sessions($limit)], $flags);
        exit;
    }
    if ($action === 'session_turns') {
        $sid = isset($_GET['session_id']) ? trim((string) $_GET['session_id']) : '';
        if ($sid === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'session_id required'], $flags);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'sessionId' => $sid,
            'turns' => list_chat_history_turns_for_session($sid, 250),
        ], $flags);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action'], $flags);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], $flags);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = trim((string) ($input['action'] ?? ''));

if ($action === 'delete_session') {
    $sid = trim((string) ($input['sessionId'] ?? ''));
    $n = delete_chat_history_for_session($sid);
    echo json_encode(['ok' => true, 'removed' => $n], $flags);
    exit;
}

if ($action === 'delete_sessions') {
    $ids = $input['sessionIds'] ?? [];
    if (!is_array($ids)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'sessionIds array required'], $flags);
        exit;
    }
    $total = 0;
    $seen = [];
    $legacy = false;
    foreach ($ids as $raw) {
        $sid = trim((string) $raw);
        if ($sid === '') {
            $legacy = true;
            continue;
        }
        if (isset($seen[$sid])) {
            continue;
        }
        $seen[$sid] = true;
        $total += delete_chat_history_for_session($sid);
    }
    if ($legacy) {
        $total += delete_chat_history_unassigned();
    }
    echo json_encode(['ok' => true, 'removed' => $total], $flags);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action'], $flags);
