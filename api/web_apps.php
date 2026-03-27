<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    echo json_encode(['apps' => list_web_apps_meta()], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'get') {
    $name = isset($_GET['name']) ? (string) $_GET['name'] : (string) ($input['name'] ?? '');
    $r = get_web_app($name);
    if (isset($r['error'])) {
        http_response_code(404);
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'create') {
    $r = create_web_app(
        (string) ($input['name'] ?? ''),
        (string) ($input['title'] ?? ''),
        (string) ($input['html'] ?? '')
    );
    if (isset($r['error'])) {
        http_response_code(400);
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update') {
    $r = update_web_app(
        (string) ($input['name'] ?? ''),
        array_key_exists('title', $input) ? (string) $input['title'] : null,
        array_key_exists('html', $input) ? (string) $input['html'] : null
    );
    if (isset($r['error'])) {
        http_response_code(400);
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    $r = delete_web_app((string) ($input['name'] ?? ''));
    if (isset($r['error'])) {
        http_response_code(400);
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action'], JSON_UNESCAPED_UNICODE);
