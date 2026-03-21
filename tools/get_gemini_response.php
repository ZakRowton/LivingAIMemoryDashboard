<?php
$arguments = isset($arguments) && is_array($arguments) ? $arguments : [];
$prompt = $arguments['prompt'] ?? '';
if (trim($prompt) === '') {
    echo json_encode(['error' => 'Prompt is required']);
    exit;
}
$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey) {
    // fallback to hardcoded key
    $apiKey = 'AIzaSyAWZJ_8hrXjTxjcSwD-WZYVZJRaf9WjHYw';
}
$url = "https://generativeai.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key={$apiKey}";
$payload = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ]
];
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    echo json_encode(['error' => $error]);
    exit;
}
curl_close($ch);
if ($httpCode !== 200) {
    echo json_encode(['error' => "HTTP $httpCode", 'response' => $response]);
    exit;
}
$data = json_decode($response, true);
if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
    echo json_encode(['response' => $data['candidates'][0]['content']['parts'][0]['text']]);
    exit;
}
echo json_encode(['error' => 'Unexpected response format', 'raw' => $response]);
?>