<?php
$arguments = isset($arguments) && is_array($arguments) ? $arguments : [];
if (!isset($arguments['command']) || !is_string($arguments['command'])) {
    echo json_encode(['error' => 'Missing or invalid "command" parameter.']);
    exit;
}
$command = $arguments['command'];

// Try to execute with elevated privileges on Windows
if (PHP_OS_FAMILY === 'Windows') {
    // For Windows, we can try to use PowerShell with elevated privileges
    // First, check if we can run PowerShell
    $powershell_check = exec('where powershell 2>&1', $output, $return_code);
    if ($return_code === 0) {
        // Use PowerShell to execute the command with potential elevation
        // Note: True elevation requires UAC prompts which can't be automated in web context
        // So we'll use PowerShell's execution capabilities
        $ps_command = 'powershell -Command "& {' . escapeshellarg($command) . '} 2>&1"';
        $output = shell_exec($ps_command);
    } else {
        // Fall back to regular shell_exec
        $output = shell_exec($command . ' 2>&1');
    }
} else {
    // For Linux/Mac, use regular shell_exec
    $output = shell_exec($command . ' 2>&1');
}

if ($output === null) {
    $output = '';
}
header('Content-Type: application/json');
echo json_encode(['output' => $output]);
?>