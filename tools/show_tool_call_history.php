<?php
// Get the tool call history from the session or global state
$tool_history = [];

// Check if we have tool call history in the session
if (isset($_SESSION['tool_call_history'])) {
    $tool_history = $_SESSION['tool_call_history'];
}

// If no history, create a sample for demonstration
if (empty($tool_history)) {
    $tool_history = [
        [
            'timestamp' => date('Y-m-d H:i:s'),
            'tool' => 'list_available_tools',
            'arguments' => '{}',
            'result' => '{"tools":[{"name":"list_available_tools","description":"List all available tools..."}]}'
        ],
        [
            'timestamp' => date('Y-m-d H:i:s', strtotime('-1 minute')),
            'tool' => 'get_current_provider_model',
            'arguments' => '{}',
            'result' => '{"provider":"alibaba","model":"qwen-plus"}'
        ]
    ];
}

// Generate HTML for the dropdown panel
$html = '<div class="tool-history-panel" style="border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 20px; background-color: #f9f9f9;">';
$html .= '<h3 style="margin: 0; padding: 12px 16px; background-color: #f0f0f0; border-bottom: 1px solid #e0e0e0; font-size: 16px; font-weight: 600;">Tool Call History</h3>';
$html .= '<div style="padding: 16px;">';

if (empty($tool_history)) {
    $html .= '<p style="color: #666; font-style: italic;">No tool calls executed yet.</p>';
} else {
    $html .= '<details style="margin-bottom: 12px; border-left: 4px solid #2196F3; padding-left: 12px;">';
    $html .= '<summary style="cursor: pointer; font-weight: 600; color: #2196F3;">Click to view ' . count($tool_history) . ' tool call' . (count($tool_history) !== 1 ? 's' : '') . '</summary>';
    
    foreach ($tool_history as $index => $call) {
        $html .= '<div style="margin-top: 12px; padding: 12px; background-color: white; border-radius: 4px; border: 1px solid #e0e0e0;">';
        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">';
        $html .= '<strong style="color: #333;">' . htmlspecialchars($call['tool']) . '</strong>';
        $html .= '<span style="font-size: 12px; color: #666;">' . htmlspecialchars($call['timestamp']) . '</span>';
        $html .= '</div>';
        
        if (!empty($call['arguments'])) {
            $html .= '<div style="margin-bottom: 8px;">';
            $html .= '<strong style="color: #555; font-size: 13px;">Arguments:</strong>';
            $html .= '<pre style="background-color: #f5f5f5; padding: 8px; border-radius: 4px; margin: 4px 0; overflow-x: auto; font-size: 12px; max-height: 100px; overflow-y: auto;">' . htmlspecialchars($call['arguments']) . '</pre>';
            $html .= '</div>';
        }
        
        if (!empty($call['result'])) {
            $html .= '<div>';
            $html .= '<strong style="color: #555; font-size: 13px;">Result:</strong>';
            $html .= '<pre style="background-color: #f5f5f5; padding: 8px; border-radius: 4px; margin: 4px 0; overflow-x: auto; font-size: 12px; max-height: 150px; overflow-y: auto;">' . htmlspecialchars($call['result']) . '</pre>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
    }
    
    $html .= '</details>';
}

$html .= '</div>';
$html .= '</div>';

echo json_encode(['html' => $html]);
?>