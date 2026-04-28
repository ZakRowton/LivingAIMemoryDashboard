<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'sub_agent_store.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $action = is_array($body) ? (string) ($body['action'] ?? '') : '';
}

if ($action === '' || $action === 'list') {
    echo json_encode(['subAgents' => list_sub_agent_files_meta(false)]);
    exit;
}

if ($action === 'read') {
    $name = (string) ($_GET['name'] ?? '');
    $meta = get_sub_agent_meta($name);
    if ($meta === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Sub-agent file not found']);
        exit;
    }
    echo json_encode($meta);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'create') {
    $name = (string) ($input['name'] ?? '');
    $content = (string) ($input['content'] ?? '');
    $result = create_sub_agent_file($name, $content);
    if (isset($result['error'])) http_response_code(400);
    echo json_encode($result);
    exit;
}

if ($action === 'update') {
    $name = (string) ($input['name'] ?? '');
    $content = (string) ($input['content'] ?? '');
    $result = update_sub_agent_file($name, $content);
    if (isset($result['error'])) http_response_code(400);
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $name = (string) ($input['name'] ?? '');
    $result = delete_sub_agent_file_by_name($name);
    if (isset($result['error'])) http_response_code(400);
    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
