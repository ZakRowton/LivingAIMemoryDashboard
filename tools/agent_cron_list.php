<?php
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron_store.php';

$jobs = mg_cron_list_jobs();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'jobs' => $jobs,
    'hint' => 'Scheduler runs when: (1) Windows Task Scheduler or cron executes cron_tick.php every minute, and/or (2) MEMORYGRAPH_CRON_BROWSER_TICK=1 with dashboard open on localhost. Set MEMORYGRAPH_PUBLIC_BASE_URL so PHP can POST to api/chat.php.',
], JSON_UNESCAPED_UNICODE);
