<?php
header('Content-Type: application/json; charset=utf-8');

$requestId = isset($_GET['request_id']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $_GET['request_id']) : '';
if ($requestId === '') {
    echo json_encode([
        'requestId' => '',
        'thinking' => false,
        'gettingAvailTools' => false,
        'activeToolIds' => [],
        'checkingMemory' => false,
        'checkingInstructions' => false,
        'checkingResearch' => false,
        'checkingRules' => false,
        'checkingMcps' => false,
        'checkingJobs' => false,
        'activeMemoryIds' => [],
        'activeInstructionIds' => [],
        'activeResearchIds' => [],
        'activeRulesIds' => [],
        'activeMcpIds' => [],
        'activeJobIds' => [],
        'activeSubAgentIds' => [],
        'memoryToolExecuting' => false,
        'toolExecuting' => false,
        'instructionToolExecuting' => false,
        'isAccessingMemoryFile' => false,
        'graphRefreshToken' => '',
    ]);
    exit;
}

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'chat-status' . DIRECTORY_SEPARATOR . $requestId . '.json';
if (!file_exists($path)) {
    echo json_encode([
        'requestId' => $requestId,
        'fileExists' => false,
        'thinking' => false,
        'gettingAvailTools' => false,
        'activeToolIds' => [],
        'checkingMemory' => false,
        'checkingInstructions' => false,
        'checkingResearch' => false,
        'checkingRules' => false,
        'checkingMcps' => false,
        'checkingJobs' => false,
        'activeMemoryIds' => [],
        'activeInstructionIds' => [],
        'activeResearchIds' => [],
        'activeRulesIds' => [],
        'activeMcpIds' => [],
        'activeJobIds' => [],
        'activeSubAgentIds' => [],
        'memoryToolExecuting' => false,
        'toolExecuting' => false,
        'instructionToolExecuting' => false,
        'isAccessingMemoryFile' => false,
        'graphRefreshToken' => '',
    ]);
    exit;
}

$data = json_decode((string) file_get_contents($path), true);
$data = is_array($data) ? $data : [];
$nowMs = (int) round(microtime(true) * 1000);
$hasRecentActivity = !empty($data['lastEventExpiresAtMs']) && $nowMs < (int) $data['lastEventExpiresAtMs'];
$effectiveGettingAvailTools = !empty($data['gettingAvailTools']) || ($hasRecentActivity && !empty($data['lastGettingAvailTools']));
$effectiveCheckingMemory = !empty($data['checkingMemory']) || ($hasRecentActivity && !empty($data['lastCheckingMemory']));
$effectiveCheckingInstructions = !empty($data['checkingInstructions']) || ($hasRecentActivity && !empty($data['lastCheckingInstructions']));
$effectiveCheckingMcps = !empty($data['checkingMcps']) || ($hasRecentActivity && !empty($data['lastCheckingMcps']));
$effectiveCheckingJobs = !empty($data['checkingJobs']) || ($hasRecentActivity && !empty($data['lastCheckingJobs']));
$effectiveCheckingResearch = !empty($data['checkingResearch']) || ($hasRecentActivity && !empty($data['lastCheckingResearch']));
$effectiveCheckingRules = !empty($data['checkingRules']) || ($hasRecentActivity && !empty($data['lastCheckingRules']));
$effectiveActiveToolIds = isset($data['activeToolIds']) && is_array($data['activeToolIds']) && count($data['activeToolIds']) > 0
    ? array_values($data['activeToolIds'])
    : ($hasRecentActivity && isset($data['lastActiveToolIds']) && is_array($data['lastActiveToolIds']) ? array_values($data['lastActiveToolIds']) : []);
$effectiveActiveMemoryIds = isset($data['activeMemoryIds']) && is_array($data['activeMemoryIds']) && count($data['activeMemoryIds']) > 0
    ? array_values($data['activeMemoryIds'])
    : ($hasRecentActivity && isset($data['lastActiveMemoryIds']) && is_array($data['lastActiveMemoryIds']) ? array_values($data['lastActiveMemoryIds']) : []);
$effectiveActiveInstructionIds = isset($data['activeInstructionIds']) && is_array($data['activeInstructionIds']) && count($data['activeInstructionIds']) > 0
    ? array_values($data['activeInstructionIds'])
    : ($hasRecentActivity && isset($data['lastActiveInstructionIds']) && is_array($data['lastActiveInstructionIds']) ? array_values($data['lastActiveInstructionIds']) : []);
$effectiveActiveResearchIds = isset($data['activeResearchIds']) && is_array($data['activeResearchIds']) && count($data['activeResearchIds']) > 0
    ? array_values($data['activeResearchIds'])
    : ($hasRecentActivity && isset($data['lastActiveResearchIds']) && is_array($data['lastActiveResearchIds']) ? array_values($data['lastActiveResearchIds']) : []);
$effectiveActiveRulesIds = isset($data['activeRulesIds']) && is_array($data['activeRulesIds']) && count($data['activeRulesIds']) > 0
    ? array_values($data['activeRulesIds'])
    : ($hasRecentActivity && isset($data['lastActiveRulesIds']) && is_array($data['lastActiveRulesIds']) ? array_values($data['lastActiveRulesIds']) : []);
$effectiveActiveMcpIds = isset($data['activeMcpIds']) && is_array($data['activeMcpIds']) && count($data['activeMcpIds']) > 0
    ? array_values($data['activeMcpIds'])
    : ($hasRecentActivity && isset($data['lastActiveMcpIds']) && is_array($data['lastActiveMcpIds']) ? array_values($data['lastActiveMcpIds']) : []);
$effectiveActiveJobIds = isset($data['activeJobIds']) && is_array($data['activeJobIds']) && count($data['activeJobIds']) > 0
    ? array_values($data['activeJobIds'])
    : ($hasRecentActivity && isset($data['lastActiveJobIds']) && is_array($data['lastActiveJobIds']) ? array_values($data['lastActiveJobIds']) : []);
$effectiveActiveSubAgentIds = isset($data['activeSubAgentIds']) && is_array($data['activeSubAgentIds']) && count($data['activeSubAgentIds']) > 0
    ? array_values($data['activeSubAgentIds'])
    : ($hasRecentActivity && isset($data['lastActiveSubAgentIds']) && is_array($data['lastActiveSubAgentIds']) ? array_values($data['lastActiveSubAgentIds']) : []);
$effectiveExecutionDetails = isset($data['executionDetailsByNode']) && is_array($data['executionDetailsByNode']) && count($data['executionDetailsByNode']) > 0
    ? $data['executionDetailsByNode']
    : ($hasRecentActivity && isset($data['lastExecutionDetailsByNode']) && is_array($data['lastExecutionDetailsByNode']) ? $data['lastExecutionDetailsByNode'] : []);
$effectiveThinking = !empty($data['thinking']) || $effectiveGettingAvailTools || $effectiveCheckingMemory || $effectiveCheckingInstructions || $effectiveCheckingMcps || $effectiveCheckingJobs || count($effectiveActiveToolIds) > 0 || count($effectiveActiveMemoryIds) > 0 || count($effectiveActiveInstructionIds) > 0 || count($effectiveActiveMcpIds) > 0 || count($effectiveActiveJobIds) > 0 || count($effectiveActiveSubAgentIds) > 0 || (!empty($effectiveExecutionDetails['sub_agents']));
$effectiveMemoryToolExecuting = $effectiveCheckingMemory || count($effectiveActiveMemoryIds) > 0;
$effectiveToolExecuting = $effectiveGettingAvailTools || count($effectiveActiveToolIds) > 0;
$effectiveInstructionToolExecuting = $effectiveCheckingInstructions || count($effectiveActiveInstructionIds) > 0;
$effectiveAccessingMemoryFile = $effectiveCheckingMemory || count($effectiveActiveMemoryIds) > 0;

echo json_encode([
    'requestId' => $requestId,
    'fileExists' => true,
    'thinking' => $effectiveThinking,
    'gettingAvailTools' => $effectiveGettingAvailTools,
    'activeToolIds' => $effectiveActiveToolIds,
    'checkingMemory' => $effectiveCheckingMemory,
    'checkingInstructions' => $effectiveCheckingInstructions,
    'checkingMcps' => $effectiveCheckingMcps,
    'checkingJobs' => $effectiveCheckingJobs,
    'checkingResearch' => $effectiveCheckingResearch,
    'checkingRules' => $effectiveCheckingRules,
    'activeMemoryIds' => $effectiveActiveMemoryIds,
    'activeInstructionIds' => $effectiveActiveInstructionIds,
    'activeResearchIds' => $effectiveActiveResearchIds,
    'activeRulesIds' => $effectiveActiveRulesIds,
    'activeMcpIds' => $effectiveActiveMcpIds,
    'activeJobIds' => $effectiveActiveJobIds,
    'activeSubAgentIds' => $effectiveActiveSubAgentIds,
    'memoryToolExecuting' => $effectiveMemoryToolExecuting,
    'toolExecuting' => $effectiveToolExecuting,
    'instructionToolExecuting' => $effectiveInstructionToolExecuting,
    'isAccessingMemoryFile' => $effectiveAccessingMemoryFile,
    'executionDetailsByNode' => $effectiveExecutionDetails,
    'graphRefreshToken' => isset($data['graphRefreshToken']) ? (string) $data['graphRefreshToken'] : '',
]);
