<?php
/**
 * Detached CLI worker: POSTs to api/chat.php with __memoryGraphSubAgentWorker so the parent
 * Jarvis request can return while this process holds the long-running sub-agent HTTP call.
 *
 * Invoked from api/chat.php when MEMORYGRAPH_PHP_CLI points to php.exe (recommended on Windows XAMPP).
 * Usage: php sub_agent_async_worker.php <taskId> <secret>
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$taskId = isset($argv[1]) ? (string) $argv[1] : '';
$secretArg = isset($argv[2]) ? (string) $argv[2] : '';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
memory_graph_load_env();
$expected = trim((string) memory_graph_env('MEMORYGRAPH_SUB_AGENT_ASYNC_SECRET', ''));
$base = rtrim(trim((string) memory_graph_env('MEMORYGRAPH_PUBLIC_BASE_URL', '')), '/');
if ($taskId === '' || $expected === '' || $secretArg === '' || !hash_equals($expected, $secretArg) || $base === '') {
    exit(2);
}
$safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $taskId);
if ($safe === '') {
    exit(2);
}
$url = $base . '/api/chat.php';
$body = json_encode([
    '__memoryGraphSubAgentWorker' => true,
    '__workerSecret' => $expected,
    'taskId' => $safe,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($body === false || !function_exists('curl_init')) {
    exit(3);
}
$ch = curl_init($url);
if ($ch === false) {
    exit(3);
}
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 600,
]);
$raw = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($raw === false) {
    exit(4);
}
exit($code >= 200 && $code < 300 ? 0 : 5);
