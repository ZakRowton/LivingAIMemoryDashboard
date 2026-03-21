<?php
/**
 * VBASoftware helper tool — safe for multiple includes per request (no top-level function redeclare).
 */
$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];

$api = isset($arguments['api']) ? (string) $arguments['api'] : '';
$path = isset($arguments['path']) ? (string) $arguments['path'] : '';
$method = isset($arguments['method']) ? strtoupper((string) $arguments['method']) : '';
$query = isset($arguments['query']) && is_array($arguments['query']) ? $arguments['query'] : [];
$body = $arguments['body'] ?? null;

if ($api === '' || $path === '' || $method === '') {
    echo json_encode(['error' => 'Missing required parameters: api, path, and method are required.']);
    return;
}

$base = 'https://vbapi-docs.vbasoftware.com/mcp';
$url = $base . '/' . ltrim($api, '/') . '/' . ltrim($path, '/');
if (!empty($query)) {
    $url .= '?' . http_build_query($query);
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
$headers = ['Accept: application/json'];
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $json = json_encode($body ?? new stdClass());
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $headers[] = 'Content-Type: application/json';
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    echo json_encode(['error' => $error]);
    return;
}
curl_close($ch);
if ($httpCode >= 400) {
    echo json_encode(['error' => 'HTTP ' . $httpCode . ': ' . $response, 'httpCode' => $httpCode]);
    return;
}
$data = json_decode((string) $response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'JSON decode error: ' . json_last_error_msg(), 'raw' => $response]);
    return;
}
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
