<?php
$args = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$html = $args['html_content'] ?? '';
$width = $args['width'] ?? '100%';
$height = $args['height'] ?? '600px';
// Escape double quotes and special characters for srcdoc attribute
$escaped = htmlspecialchars($html, ENT_QUOTES);
$iframe = "<iframe srcdoc=\"$escaped\" width=\"$width\" height=\"$height\" style=\"border:none;\"></iframe>";
echo json_encode(['html' => $iframe]);
?>