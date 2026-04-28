<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'design_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list' || $action === '') {
    echo json_encode(['designs' => list_designs_meta()]);

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'get') {
    $name = (string) ($input['name'] ?? $_GET['name'] ?? '');
    $part = isset($input['part']) ? strtolower(trim((string) $input['part'])) : '';
    if ($part !== '' && in_array($part, ['html', 'css', 'js', 'md'], true)) {
        echo json_encode(design_read_part($name, $part));
        exit;
    }
    $all = design_read_all($name);
    if ($all === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Design not found']);
        exit;
    }
    $meta = design_get_meta($name);
    echo json_encode([
        'design' => $meta,
        'files' => $all,
    ]);
    exit;
}

if ($action === 'save_part') {
    $name = (string) ($input['name'] ?? '');
    $part = (string) ($input['part'] ?? '');
    $content = isset($input['content']) ? (string) $input['content'] : '';
    echo json_encode(design_write_part($name, $part, $content));
    exit;
}

if ($action === 'create') {
    echo json_encode(design_create(
        (string) ($input['name'] ?? ''),
        isset($input['html']) ? (string) $input['html'] : null,
        isset($input['css']) ? (string) $input['css'] : null,
        isset($input['js']) ? (string) $input['js'] : null,
        isset($input['md']) ? (string) $input['md'] : null
    ));
    exit;
}

if ($action === 'delete') {
    echo json_encode(design_delete((string) ($input['name'] ?? '')));
    exit;
}

echo json_encode(['error' => 'invalid action']);
