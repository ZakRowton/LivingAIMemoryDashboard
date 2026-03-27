<?php
$input = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$url = $input['url'] ?? '';
$width = $input['width'] ?? '100%';
$height = $input['height'] ?? '480px';
if (empty($url)) {
    echo json_encode(['error' => 'URL is required']);
    exit;
}
// Basic sanitization: ensure URL starts with http:// or https://
if (!preg_match('#^https?://#i', $url)) {
    echo json_encode(['error' => 'Invalid URL scheme']);
    exit;
}
$html = "<iframe src=\"{$url}\" width=\"{$width}\" height=\"{$height}\" style=\"border:none;\"></iframe>";
echo json_encode(['html' => $html]);
?>