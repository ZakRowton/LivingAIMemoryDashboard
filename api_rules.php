<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'rules_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $rules = list_rules_files_meta(false);
    echo json_encode(['rules' => array_values($rules)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'get') {
    $name = isset($_GET['name']) ? (string) $_GET['name'] : (string) ($input['name'] ?? '');
    $item = get_rules_meta($name);
    if ($item === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Rules file not found']);
        exit;
    }
    echo json_encode($item);
    exit;
}

if ($action === 'save') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $content = isset($input['content']) ? (string) $input['content'] : '';
    $result = write_rules_file($name, $content);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $result = delete_rules_file_by_name($name);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'invalid action']);
