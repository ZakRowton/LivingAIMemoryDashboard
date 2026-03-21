<?php
/**
 * VBASoftware list-apis helper — safe for multiple includes per request.
 */
$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];

$filter = isset($arguments['filter']) ? (string) $arguments['filter'] : '';
$page = isset($arguments['page']) ? (int) $arguments['page'] : 1;
$limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 10;
if ($page < 1) {
    $page = 1;
}
if ($limit < 1) {
    $limit = 10;
}

$url = 'https://vbapi-docs.vbasoftware.com/mcp/list-apis';
$query = [];
if ($filter !== '') {
    $query['filter'] = $filter;
}
$query['page'] = $page;
$query['limit'] = $limit;
$url .= '?' . http_build_query($query);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
