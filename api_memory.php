<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'memory_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $memories = list_memory_files_meta(false);
    $includeAll = isset($_GET['all']) && $_GET['all'] === '1';
    if (!$includeAll) {
        $memories = array_values(array_filter($memories, function ($m) {
            return empty($m['hidden']);
        }));
    }
    echo json_encode(['memories' => array_values($memories)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'get') {
    $name = isset($_GET['name']) ? (string) $_GET['name'] : (string) ($input['name'] ?? '');
    $memory = get_memory_meta($name);
    if ($memory === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Memory file not found']);
        exit;
    }
    echo json_encode($memory);
    exit;
}

if ($action === 'save') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $content = isset($input['content']) ? (string) $input['content'] : '';
    $result = write_memory_file($name, $content);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'toggle') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $active = !empty($input['active']);
    $result = set_memory_active_state($name, $active);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $result = delete_memory_file_by_name($name);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'invalid action']);
