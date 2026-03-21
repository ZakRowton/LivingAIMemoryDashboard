<?php
$arguments = isset($arguments) && is_array($arguments) ? $arguments : [];
if (!isset($arguments['query']) || !is_string($arguments['query']) || trim($arguments['query']) === '') {
    echo json_encode(['error' => 'Missing or empty "query" parameter.']);
    exit;
}
$query = $arguments['query'];
$apiKey = getenv('BRAVE_SEARCH_API_KEY');
if (!$apiKey) {
    echo json_encode(['error' => 'Missing Brave Search API key in environment variable BRAVE_SEARCH_API_KEY.']);
    exit;
}
$endpoint = 'https://api.search.brave.com/res/v1/web/search';
$url = $endpoint . '?q=' . urlencode($query);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'X-Subscription-Token: ' . $apiKey,
]);
$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo json_encode(['error' => 'cURL error: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode !== 200) {
    echo json_encode(['error' => 'HTTP error code: ' . $httpCode, 'response' => $response]);
    exit;
}
echo $response;
?>