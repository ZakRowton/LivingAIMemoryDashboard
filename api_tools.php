<?php
header('Content-Type: application/json');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tool_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $toolsData = read_tool_registry_data();
    $results = get_builtin_tools();

    if (isset($toolsData['tools']) && is_array($toolsData['tools'])) {
        foreach ($toolsData['tools'] as $tool) {
            $code = read_tool_file_content((string) ($tool['name'] ?? '')) ?? '';
            $tool['code'] = $code;
            $results[] = $tool;
        }
    }

    echo json_encode(['tools' => $results]);
    exit;
}

if ($action === 'toggle') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $active = $input['active'] ?? false;

    foreach (get_builtin_tools() as $builtinTool) {
        if (($builtinTool['name'] ?? '') === $name) {
            http_response_code(400);
            echo json_encode(['error' => 'Built-in tools cannot be toggled']);
            exit;
        }
    }
    
    $toolsData = read_tool_registry_data();
    $updated = false;
    
    if (isset($toolsData['tools']) && is_array($toolsData['tools'])) {
        foreach ($toolsData['tools'] as &$tool) {
            if ($tool['name'] === $name) {
                $tool['active'] = $active;
                $updated = true;
            }
        }
    }

    if (!$updated) {
        http_response_code(404);
        echo json_encode(['error' => 'Tool not found']);
        exit;
    }
    
    save_tool_registry_data($toolsData);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'toggle_all') {
    $input = json_decode(file_get_contents('php://input'), true);
    $active = !empty($input['active']);

    $toolsData = read_tool_registry_data();

    if (isset($toolsData['tools']) && is_array($toolsData['tools'])) {
        foreach ($toolsData['tools'] as &$tool) {
            $tool['active'] = $active;
        }
        unset($tool);
    }

    save_tool_registry_data($toolsData);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'save_code') {
    $input = json_decode(file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];
    $name = (string) ($input['name'] ?? '');
    $code = (string) ($input['code'] ?? '');

    foreach (get_builtin_tools() as $builtinTool) {
        if (($builtinTool['name'] ?? '') === $name) {
            http_response_code(400);
            echo json_encode(['error' => 'Built-in tools cannot be modified']);
            exit;
        }
    }

    $result = edit_tool_file_artifact($name, $code);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];
    $name = (string) ($input['name'] ?? '');

    foreach (get_builtin_tools() as $builtinTool) {
        if (($builtinTool['name'] ?? '') === $name) {
            http_response_code(400);
            echo json_encode(['error' => 'Built-in tools cannot be deleted']);
            exit;
        }
    }

    $result = delete_tool_artifact($name);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'invalid action']);
