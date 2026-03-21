<?php
$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron_store.php';

$result = mg_cron_add_job([
    'name' => $arguments['name'] ?? '',
    'message' => $arguments['message'] ?? '',
    'schedule_kind' => $arguments['schedule_kind'] ?? $arguments['kind'] ?? '',
    'at' => $arguments['at'] ?? '',
    'every_ms' => isset($arguments['every_ms']) ? (int) $arguments['every_ms'] : (isset($arguments['everyMs']) ? (int) $arguments['everyMs'] : 0),
    'cron' => $arguments['cron'] ?? $arguments['cron_expression'] ?? '',
    'timezone' => $arguments['timezone'] ?? $arguments['tz'] ?? '',
    'delete_after_run' => array_key_exists('delete_after_run', $arguments) ? (bool) $arguments['delete_after_run'] : false,
    'enabled' => array_key_exists('enabled', $arguments) ? (bool) $arguments['enabled'] : true,
    'provider' => $arguments['provider'] ?? '',
    'model' => $arguments['model'] ?? '',
    'temperature' => $arguments['temperature'] ?? null,
]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
