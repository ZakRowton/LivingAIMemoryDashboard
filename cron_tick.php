#!/usr/bin/env php
<?php
/**
 * CLI entry: run the MemoryGraph scheduler once (OpenClaw-style gateway tick).
 * Example (Windows Task Scheduler, every minute):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\MemoryGraph\cron_tick.php
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cron_invoker.php';

memory_graph_load_env();

if (function_exists('set_time_limit')) {
    @set_time_limit(600);
}

$result = mg_cron_run_tick();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
