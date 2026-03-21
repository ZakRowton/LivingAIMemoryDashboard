<?php
/**
 * JSON health check: MCP sidecar reachability (GET /health on MEMORYGRAPH_MCP_PROXY_URL).
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mcp_client.php';

$base = mcp_proxy_base_url();
if ($base === null) {
    echo json_encode([
        'ok' => true,
        'proxyConfigured' => false,
        'message' => 'MEMORYGRAPH_MCP_PROXY_URL is not set; PHP uses in-process MCP only.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$url = $base . '/health';
$ch = curl_init($url);
if ($ch === false) {
    echo json_encode(['ok' => false, 'proxyConfigured' => true, 'error' => 'curl_init failed']);
    exit;
}
$headers = array_merge(['Accept: application/json'], mcp_proxy_auth_header_lines());
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$ok = $code >= 200 && $code < 300 && is_array($decoded) && !empty($decoded['ok']);

echo json_encode([
    'ok' => $ok,
    'proxyConfigured' => true,
    'proxyUrl' => $base,
    'httpCode' => $code,
    'curlError' => $err !== '' ? $err : null,
    'sidecar' => is_array($decoded) ? $decoded : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
