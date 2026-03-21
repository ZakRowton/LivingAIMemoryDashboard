<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mcp_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mcp_client.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    echo json_encode(['servers' => list_mcp_servers_meta()]);
    exit;
}

if ($action === 'get') {
    $server = get_mcp_server_meta((string) ($_GET['name'] ?? ''));
    if ($server === null) {
        http_response_code(404);
        echo json_encode(['error' => 'MCP server not found']);
        exit;
    }
    echo json_encode($server);
    exit;
}

if ($action === 'tools') {
    $server = get_mcp_server_meta((string) ($_GET['name'] ?? ''));
    if ($server === null) {
        http_response_code(404);
        echo json_encode(['error' => 'MCP server not found']);
        exit;
    }
    if (!empty($_GET['nocache'])) {
        $k = mcp_tools_list_cache_key($server);
        @unlink(mcp_tools_list_cache_path($k));
        $result = mcp_list_server_tools_uncached($server);
    } else {
        $result = mcp_list_server_tools($server);
    }
    if (isset($result['error'])) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    echo json_encode($result);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'save') {
    $result = upsert_mcp_server_artifact($input, (string) ($input['originalName'] ?? ''));
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'toggle') {
    $result = set_mcp_server_active_artifact((string) ($input['name'] ?? ''), !empty($input['active']));
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'toggle_all') {
    echo json_encode(set_all_mcp_servers_active_artifact(!empty($input['active'])));
    exit;
}

if ($action === 'delete') {
    $result = delete_mcp_server_artifact((string) ($input['name'] ?? ''));
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
