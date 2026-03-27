<?php
/**
 * Procedural tool — safe when run multiple times per request (include path in executePhpTool).
 * Do not use top-level "return" here: it would return from the host function in PHP.
 */
header('Content-Type: application/json');

$args = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$args = is_array($args) ? $args : [];
$description = isset($args['description']) ? (string) $args['description'] : '';

$memPath = __DIR__ . '/../memory/gemini_api.md';
if (!file_exists($memPath)) {
    echo json_encode(['error' => 'gemini_api.md not found']);
} else {
    $memContent = (string) file_get_contents($memPath);
    $apiKey = null;
    $endpoint = null;
    if (preg_match('/APIKey:\s*([^\n]+)/', $memContent, $m)) {
        $apiKey = trim($m[1]);
    }
    if (preg_match('/Endpoint:\s*([^\n]+)/', $memContent, $m)) {
        $endpoint = trim($m[1]);
    }
    if (!$apiKey || !$endpoint) {
        echo json_encode(['error' => 'Missing API key or endpoint in gemini_api.md']);
    } else {
        $url = $endpoint . '?key=' . $apiKey;
        $payload = [
            'contents' => [
                ['parts' => [['text' => 'Generate an image of ' . $description]]],
            ],
            'generationConfig' => [
                'response_mime_type' => 'image/png',
            ],
        ];
        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($payload),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            $placeholder = 'https://source.unsplash.com/featured/?' . urlencode($description);
            echo json_encode(['image_url' => $placeholder]);
        } else {
            $json = json_decode($result, true);
            if (is_array($json) && isset($json['candidates'][0]['content']['parts'][0]['blob'])) {
                $blob = $json['candidates'][0]['content']['parts'][0]['blob'];
                $dataUrl = 'data:image/png;base64,' . $blob;
                echo json_encode(['image_url' => $dataUrl]);
            } else {
                $placeholder = 'https://source.unsplash.com/featured/?' . urlencode($description);
                echo json_encode(['image_url' => $placeholder]);
            }
        }
    }
}
