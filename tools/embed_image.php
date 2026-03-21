<?php
$arguments = isset($arguments) && is_array($arguments) ? $arguments : [];
$url = $arguments['url'] ?? '';
if (trim($url) === '') {
    echo json_encode(['error' => 'Missing url parameter']);
    exit;
}
// Return markdown image tag
echo "![Image]($url)";
?>