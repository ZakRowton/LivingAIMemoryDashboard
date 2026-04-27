<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $full = isset($_GET['full']) && $_GET['full'] === '1';
    echo json_encode(['sessions' => list_session_files_meta($full)]);
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
    $meta = get_session_meta($name);
    if ($meta === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Session file not found']);
        exit;
    }
    $path = session_dir_path() . DIRECTORY_SEPARATOR . normalize_session_filename($name);
    $raw = is_file($path) ? (string) file_get_contents($path) : '';
    $decoded = json_decode($raw, true);
    echo json_encode([
        'session' => $meta,
        'document' => is_array($decoded) ? session_normalize_document($decoded) : session_default_document(),
    ]);
    exit;
}

if ($action === 'save') {
    $name = (string) ($input['name'] ?? '');
    $document = isset($input['document']) && is_array($input['document']) ? $input['document'] : null;
    if ($document === null) {
        http_response_code(400);
        echo json_encode(['error' => 'document required']);
        exit;
    }
    echo json_encode(write_session_file($name, $document));
    exit;
}

if ($action === 'delete') {
    echo json_encode(delete_session_file_by_name((string) ($input['name'] ?? '')));
    exit;
}

echo json_encode(['error' => 'invalid action']);
