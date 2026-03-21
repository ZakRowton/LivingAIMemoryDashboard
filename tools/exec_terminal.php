<?php
$arguments = isset($arguments) && is_array($arguments) ? $arguments : [];
if (!isset($arguments['command']) || !is_string($arguments['command'])) {
    echo json_encode(['error' => 'Missing or invalid "command" parameter.']);
    exit;
}
$command = $arguments['command'];
// Execute command and capture output
$output = shell_exec($command . ' 2>&1');
if ($output === null) {
    $output = '';
}
header('Content-Type: application/json');
echo json_encode(['output' => $output]);
?>
