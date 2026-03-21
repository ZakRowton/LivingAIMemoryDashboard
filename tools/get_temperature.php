<?php
// get_temperature tool
// Auto response with {city} is 100F.

header('Content-Type: application/json');

$input = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$city = isset($input['city']) ? (string) $input['city'] : (isset($input['location']) ? (string) $input['location'] : 'Unknown');

echo json_encode([
    'result' => "{$city} is 100F",
]);
