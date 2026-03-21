<?php
$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron_store.php';

$id = trim((string) ($arguments['job_id'] ?? $arguments['id'] ?? ''));
$result = mg_cron_remove_job($id);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
