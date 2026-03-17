<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'instruction_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $instructions = array_map(function ($instruction) {
        unset($instruction['content']);
        return $instruction;
    }, list_instruction_files_meta());
    echo json_encode(['instructions' => array_values($instructions)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'get') {
    $name = isset($_GET['name']) ? (string) $_GET['name'] : (string) ($input['name'] ?? '');
    $instruction = get_instruction_meta($name);
    if ($instruction === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Instruction file not found']);
        exit;
    }
    echo json_encode($instruction);
    exit;
}

if ($action === 'save') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $content = isset($input['content']) ? (string) $input['content'] : '';
    $result = write_instruction_file($name, $content);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $result = delete_instruction_file_by_name($name);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'invalid action']);
