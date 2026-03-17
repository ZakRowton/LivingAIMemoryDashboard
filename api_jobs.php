<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'job_store.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $jobs = array_map(function ($job) {
        unset($job['content']);
        return $job;
    }, list_job_files_meta());
    echo json_encode(['jobs' => array_values($jobs)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($action === 'get') {
    $name = isset($_GET['name']) ? (string) $_GET['name'] : (string) ($input['name'] ?? '');
    $job = get_job_meta($name);
    if ($job === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Job file not found']);
        exit;
    }
    echo json_encode($job);
    exit;
}

if ($action === 'save') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $content = isset($input['content']) ? (string) $input['content'] : '';
    $result = write_job_file($name, $content);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $name = isset($input['name']) ? (string) $input['name'] : '';
    $result = delete_job_file_by_name($name);
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

echo json_encode(['error' => 'invalid action']);
