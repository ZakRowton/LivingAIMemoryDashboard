<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'research_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $research = array_map(function ($r) {
        unset($r['content']);
        return $r;
    }, list_research_files_meta());
    echo json_encode(['research' => array_values($research)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'get') {
    $name = isset($_GET['name']) ? (string) $_GET['name'] : (string) ($input['name'] ?? '');
    $item = get_research_meta($name);
    if ($item === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Research file not found']);
        exit;
    }
    echo json_encode($item);
    exit;
}

if ($action === 'save') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $content = isset($input['content']) ? (string) $input['content'] : '';
    $result = write_research_file($name, $content);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $result = delete_research_file_by_name($name);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'invalid action']);
