<?php
/**
 * Back-compat: registers a real scheduled agent job (OpenClaw-style store), not just markdown.
 * The command string is stored in the agent message for context; it is NOT executed as a shell command.
 */
$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron_store.php';

$jobName = trim((string) ($arguments['job_name'] ?? ''));
$schedule = trim((string) ($arguments['schedule'] ?? ''));
$command = trim((string) ($arguments['command'] ?? ''));

if ($jobName === '' || $schedule === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'job_name and schedule are required']);
    exit;
}

$message = $command !== ''
    ? "Scheduled task \"{$jobName}\". Intended action / command (informational — run via agent tools as appropriate, not as raw shell):\n{$command}"
    : "Scheduled task \"{$jobName}\".";

$result = mg_cron_add_job([
    'name' => $jobName,
    'message' => $message,
    'schedule_kind' => 'cron',
    'cron' => $schedule,
    'timezone' => date_default_timezone_get() ?: 'UTC',
    'enabled' => true,
    'delete_after_run' => false,
]);

if (!empty($result['ok'])) {
    $memoryDir = dirname(__DIR__) . '/memory';
    if (!is_dir($memoryDir)) {
        @mkdir($memoryDir, 0777, true);
    }
    $mdPath = $memoryDir . '/cron_jobs.md';
    $entry = "### {$jobName}\n- Job id: " . ($result['job']['id'] ?? '?') . "\n- Schedule: {$schedule}\n- Command (info): {$command}\n\n";
    @file_put_contents($mdPath, $entry, FILE_APPEND);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
