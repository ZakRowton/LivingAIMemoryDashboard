<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'category_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $categories = list_category_nodes_meta();
    echo json_encode(['categories' => array_values($categories)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'create') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $title = isset($input['title']) ? (string) $input['title'] : '';
    $description = isset($input['description']) ? (string) $input['description'] : '';
    $result = create_category_node($name, $title, $description);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $result = delete_category_node_by_name($name);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'invalid action']);
