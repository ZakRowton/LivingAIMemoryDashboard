<?php
/**
 * Multi-provider chat proxy with iterative tool execution.
 * Flow: model -> tool call -> tool result -> model ... until a final answer is returned.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'provider_config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'chat_history_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'memory_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'instruction_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'research_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'rules_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'category_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'job_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mcp_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mcp_client.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tool_store.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Long-running jobs (e.g. AI news) can exceed default 120s; allow up to 10 minutes
if (function_exists('set_time_limit')) {
    @set_time_limit(600);
}

$providers = [
    'mercury' => [
        'endpoint' => 'https://api.inceptionlabs.ai/v1/chat/completions',
        'apiKey' => memory_graph_env('MERCURY_API_KEY', ''),
        'type' => 'openai',
        'defaultModel' => 'mercury-2',
    ],
    'featherless' => [
        'endpoint' => 'https://api.featherless.ai/v1/chat/completions',
        'apiKey' => memory_graph_env('FEATHERLESS_API_KEY', ''),
        'type' => 'openai',
        'defaultModel' => 'glm47-flash',
    ],
    'alibaba' => [
        'endpoint' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
        'apiKey' => memory_graph_env('ALIBABA_API_KEY', ''),
        'type' => 'openai',
        'defaultModel' => 'qwen-plus',
    ],
    'gemini' => [
        'endpointBase' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'apiKey' => memory_graph_env('GEMINI_API_KEY', ''),
        'type' => 'gemini',
        'defaultModel' => 'gemini-2.5-flash',
    ],
];
$customProviders = get_custom_provider_definitions_for_chat();
foreach ($customProviders as $key => $def) {
    $providers[$key] = $def;
}

function statusDirPath(): string {
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'chat-status';
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

function sanitizeRequestId(?string $requestId): string {
    $requestId = is_string($requestId) ? $requestId : '';
    $requestId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $requestId);
    return $requestId !== '' ? $requestId : uniqid('chat_', true);
}

function writeStatus(string $requestId, array $status): void {
    $path = statusDirPath() . DIRECTORY_SEPARATOR . $requestId . '.json';
    file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT));
}

function clearStatusFlags(array &$status): void {
    $status['thinking'] = false;
    $status['gettingAvailTools'] = false;
    $status['checkingMemory'] = false;
    $status['checkingInstructions'] = false;
    $status['checkingMcps'] = false;
    $status['checkingJobs'] = false;
    $status['checkingResearch'] = false;
    $status['checkingRules'] = false;
    $status['activeToolIds'] = [];
    $status['activeMemoryIds'] = [];
    $status['activeInstructionIds'] = [];
    $status['activeResearchIds'] = [];
    $status['activeRulesIds'] = [];
    $status['activeMcpIds'] = [];
    $status['activeJobIds'] = [];
    $status['executionDetailsByNode'] = [];
}

function markExecutionStatus(array &$status, string $requestId, bool $gettingAvailTools, bool $checkingMemory, bool $checkingInstructions, bool $checkingResearch, bool $checkingRules, bool $checkingMcps, bool $checkingJobs, array $activeToolIds, array $activeMemoryIds, array $activeInstructionIds, array $activeResearchIds, array $activeRulesIds, array $activeMcpIds, array $activeJobIds, array $executionDetailsByNode): void {
    $status['gettingAvailTools'] = $gettingAvailTools;
    $status['checkingMemory'] = $checkingMemory;
    $status['checkingInstructions'] = $checkingInstructions;
    $status['checkingResearch'] = $checkingResearch;
    $status['checkingRules'] = $checkingRules;
    $status['checkingMcps'] = $checkingMcps;
    $status['checkingJobs'] = $checkingJobs;
    $status['activeToolIds'] = array_values($activeToolIds);
    $status['activeMemoryIds'] = array_values($activeMemoryIds);
    $status['activeInstructionIds'] = array_values($activeInstructionIds);
    $status['activeResearchIds'] = array_values($activeResearchIds);
    $status['activeRulesIds'] = array_values($activeRulesIds);
    $status['activeMcpIds'] = array_values($activeMcpIds);
    $status['activeJobIds'] = array_values($activeJobIds);
    $status['executionDetailsByNode'] = $executionDetailsByNode;
    $status['lastGettingAvailTools'] = $gettingAvailTools;
    $status['lastCheckingMemory'] = $checkingMemory;
    $status['lastCheckingInstructions'] = $checkingInstructions;
    $status['lastCheckingResearch'] = $checkingResearch;
    $status['lastCheckingRules'] = $checkingRules;
    $status['lastCheckingMcps'] = $checkingMcps;
    $status['lastCheckingJobs'] = $checkingJobs;
    $status['lastActiveToolIds'] = array_values($activeToolIds);
    $status['lastActiveMemoryIds'] = array_values($activeMemoryIds);
    $status['lastActiveInstructionIds'] = array_values($activeInstructionIds);
    $status['lastActiveResearchIds'] = array_values($activeResearchIds);
    $status['lastActiveRulesIds'] = array_values($activeRulesIds);
    $status['lastActiveMcpIds'] = array_values($activeMcpIds);
    $status['lastActiveJobIds'] = array_values($activeJobIds);
    $status['lastExecutionDetailsByNode'] = $executionDetailsByNode;
    $status['lastEventExpiresAtMs'] = (int) round(microtime(true) * 1000) + 5500;
    writeStatus($requestId, $status);
}

function clearCurrentExecutionStatus(array &$status, string $requestId): void {
    $status['gettingAvailTools'] = false;
    $status['checkingMemory'] = false;
    $status['checkingInstructions'] = false;
    $status['checkingResearch'] = false;
    $status['checkingRules'] = false;
    $status['checkingMcps'] = false;
    $status['checkingJobs'] = false;
    $status['activeToolIds'] = [];
    $status['activeMemoryIds'] = [];
    $status['activeInstructionIds'] = [];
    $status['activeResearchIds'] = [];
    $status['activeRulesIds'] = [];
    $status['activeMcpIds'] = [];
    $status['activeJobIds'] = [];
    $status['executionDetailsByNode'] = [];
    writeStatus($requestId, $status);
}

function isMemoryToolName(string $toolName): bool {
    return in_array($toolName, [
        'list_memory_files',
        'read_memory_file',
        'add_memory_file',
        'create_memory_file',
        'update_memory_file',
        'delete_memory_file',
    ], true);
}

function isInstructionToolName(string $toolName): bool {
    return in_array($toolName, [
        'list_instruction_files',
        'read_instruction_file',
        'create_instruction_file',
        'update_instruction_file',
        'delete_instruction_file',
    ], true);
}

function isJobToolName(string $toolName): bool {
    return in_array($toolName, [
        'list_job_files',
        'read_job_file',
        'create_job_file',
        'update_job_file',
        'delete_job_file',
        'execute_job_file',
    ], true);
}

function isResearchToolName(string $toolName): bool {
    return in_array($toolName, [
        'list_research_files',
        'read_research_file',
        'add_research_file',
        'create_research_file',
        'update_research_file',
        'delete_research_file',
    ], true);
}

function isRulesToolName(string $toolName): bool {
    return in_array($toolName, [
        'list_rules_files',
        'read_rules_file',
        'add_rules_file',
        'create_rules_file',
        'update_rules_file',
        'delete_rules_file',
    ], true);
}

function isCategoryToolName(string $toolName): bool {
    return in_array($toolName, [
        'create_category_node',
        'list_category_nodes',
        'delete_category_node',
    ], true);
}

function isMcpManagementToolName(string $toolName): bool {
    return in_array($toolName, [
        'list_mcp_servers',
        'read_mcp_server',
        'list_mcp_server_tools',
        'create_mcp_server',
        'update_mcp_server',
        'configure_mcp_server',
        'set_mcp_server_env_var',
        'remove_mcp_server_env_var',
        'set_mcp_server_header',
        'remove_mcp_server_header',
        'set_mcp_server_active',
        'delete_mcp_server',
    ], true);
}

function shouldRefreshGraphForToolResult(string $toolName, array $toolResult): bool {
    if (isset($toolResult['error'])) {
        return false;
    }
    if (!empty($toolResult['__mcp_registry_changed'])) {
        return true;
    }
    return in_array($toolName, [
        'create_or_update_tool',
        'delete_tool',
        'add_memory_file',
        'create_memory_file',
        'delete_memory_file',
        'create_instruction_file',
        'delete_instruction_file',
        'create_job_file',
        'delete_job_file',
        'create_mcp_server',
        'update_mcp_server',
        'configure_mcp_server',
        'set_mcp_server_env_var',
        'remove_mcp_server_env_var',
        'set_mcp_server_header',
        'remove_mcp_server_header',
        'set_mcp_server_active',
        'delete_mcp_server',
        'create_category_node',
        'delete_category_node',
    ], true);
}

function queueGraphRefresh(array &$status, string $requestId): void {
    $status['graphRefreshToken'] = uniqid('graph_', true);
    $status['graphRefreshNeeded'] = true;
    writeStatus($requestId, $status);
}

function loadToolRegistry(): array {
    $data = read_tool_registry_data();
    $tools = [];
    foreach (get_builtin_tools() as $tool) {
        $tools[$tool['name']] = $tool;
    }
    if (!is_array($data) || !isset($data['tools']) || !is_array($data['tools'])) {
        return $tools;
    }
    foreach ($data['tools'] as $tool) {
        if (!is_array($tool) || empty($tool['name'])) {
            continue;
        }
        $name = (string) $tool['name'];
        if (is_builtin_tool_name($name)) {
            continue;
        }
        $tool['parameters'] = normalize_tool_parameters($tool['parameters'] ?? null);
        $tool['active'] = !empty($tool['active']);
        $tools[$name] = $tool;
    }
    foreach (list_active_mcp_servers_meta() as $server) {
        $remoteTools = mcp_list_server_tools($server);
        if (!empty($remoteTools['error']) || empty($remoteTools['tools']) || !is_array($remoteTools['tools'])) {
            continue;
        }
        foreach ($remoteTools['tools'] as $remoteTool) {
            if (!is_array($remoteTool) || empty($remoteTool['name'])) {
                continue;
            }
            $exposedName = mcp_exposed_tool_name((string) ($server['name'] ?? ''), (string) $remoteTool['name']);
            $tools[$exposedName] = [
                'name' => $exposedName,
                'description' => trim('MCP server "' . ($server['name'] ?? '') . '" tool "' . (string) $remoteTool['name'] . '". ' . (string) ($remoteTool['description'] ?? '')),
                'active' => true,
                'builtin' => false,
                'mcp' => true,
                'mcpServerName' => $server['name'] ?? '',
                'mcpServerSlug' => $server['slug'] ?? '',
                'mcpServerNodeId' => $server['nodeId'] ?? '',
                'mcpToolName' => (string) $remoteTool['name'],
                'parameters' => normalize_tool_parameters($remoteTool['inputSchema'] ?? null),
                'code' => '// MCP tool proxy for server "' . ($server['name'] ?? '') . '"',
            ];
        }
    }
    return $tools;
}

function buildExecutionStateForToolCall(string $toolName, array $arguments, array $activeTools): array {
    $normalizedFunctionName = normalizeToolName($toolName);
    $activeToolIds = ['tool_' . $normalizedFunctionName];
    $activeMemoryIds = [];
    $activeInstructionIds = [];
    $activeResearchIds = [];
    $activeRulesIds = [];
    $activeMcpIds = [];
    $activeJobIds = [];
    $executionDetails = [
        'tool_' . $normalizedFunctionName => [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ],
    ];
    $gettingAvailTools = in_array($normalizedFunctionName, ['list_available_tools', 'list_tools', 'get_tools'], true);
    $checkingMemory = isMemoryToolName($normalizedFunctionName);
    $checkingInstructions = isInstructionToolName($normalizedFunctionName);
    $checkingResearch = isResearchToolName($normalizedFunctionName);
    $checkingRules = isRulesToolName($normalizedFunctionName);
    $checkingCategories = isCategoryToolName($normalizedFunctionName);
    $checkingMcps = isMcpManagementToolName($normalizedFunctionName) || !empty($activeTools[$normalizedFunctionName]['mcp']);
    $checkingJobs = isJobToolName($normalizedFunctionName);

    if ($gettingAvailTools) {
        $executionDetails['tools'] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingMemory) {
        $executionDetails['memory'] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingInstructions) {
        $executionDetails['instructions'] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingMcps) {
        $executionDetails['mcps'] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingJobs) {
        $executionDetails['jobs'] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingResearch) {
        $executionDetails['research'] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingRules) {
        $executionDetails['rules'] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingCategories) {
        $executionDetails['categories'] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }

    if (in_array($normalizedFunctionName, ['create_or_update_tool', 'delete_tool'], true) && !empty($arguments['name'])) {
        $newToolId = 'tool_' . normalizeToolName((string) $arguments['name']);
        if (!in_array($newToolId, $activeToolIds, true)) {
            $activeToolIds[] = $newToolId;
        }
        $executionDetails[$newToolId] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingMemory && !empty($arguments['name'])) {
        $memoryNodeId = memory_node_id((string) $arguments['name']);
        $activeMemoryIds = [$memoryNodeId];
        $executionDetails[$memoryNodeId] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingInstructions && !empty($arguments['name'])) {
        $instructionNodeId = instruction_node_id((string) $arguments['name']);
        $activeInstructionIds = [$instructionNodeId];
        $executionDetails[$instructionNodeId] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingJobs && !empty($arguments['name'])) {
        $jobNodeId = job_node_id((string) $arguments['name']);
        $activeJobIds = [$jobNodeId];
        $executionDetails[$jobNodeId] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($normalizedFunctionName === 'read_memory_file') {
        $memoryMeta = get_memory_meta((string) ($arguments['name'] ?? ''));
        if ($memoryMeta !== null) {
            $activeMemoryIds = [$memoryMeta['nodeId']];
            $executionDetails[$memoryMeta['nodeId']] = [
                'toolName' => $normalizedFunctionName,
                'arguments' => $arguments,
            ];
        }
    }
    if (in_array($normalizedFunctionName, ['add_memory_file', 'create_memory_file', 'update_memory_file', 'delete_memory_file'], true) && !empty($arguments['name'])) {
        $memoryMeta = get_memory_meta((string) $arguments['name']);
        $memoryNodeId = $memoryMeta !== null ? $memoryMeta['nodeId'] : memory_node_id((string) $arguments['name']);
        if (!in_array($memoryNodeId, $activeMemoryIds, true)) {
            $activeMemoryIds[] = $memoryNodeId;
        }
        $executionDetails[$memoryNodeId] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($normalizedFunctionName === 'read_instruction_file') {
        $instructionMeta = get_instruction_meta((string) ($arguments['name'] ?? ''));
        if ($instructionMeta !== null) {
            $activeInstructionIds = [$instructionMeta['nodeId']];
            $executionDetails[$instructionMeta['nodeId']] = [
                'toolName' => $normalizedFunctionName,
                'arguments' => $arguments,
            ];
        }
    }
    if (in_array($normalizedFunctionName, ['read_job_file', 'execute_job_file'], true)) {
        $jobMeta = get_job_meta((string) ($arguments['name'] ?? ''));
        if ($jobMeta !== null) {
            $activeJobIds = [$jobMeta['nodeId']];
            $executionDetails[$jobMeta['nodeId']] = [
                'toolName' => $normalizedFunctionName,
                'arguments' => $arguments,
            ];
        }
    }
    if ($checkingMcps && isMcpManagementToolName($normalizedFunctionName)) {
        $mcpTargetName = (string) ($arguments['name'] ?? $arguments['original_name'] ?? '');
        if ($mcpTargetName !== '') {
            $mcpNodeId = mcp_server_node_id($mcpTargetName);
            $activeMcpIds = [$mcpNodeId];
            $executionDetails[$mcpNodeId] = [
                'toolName' => $normalizedFunctionName,
                'arguments' => $arguments,
            ];
        }
    }
    if (!empty($activeTools[$normalizedFunctionName]['mcp'])) {
        $mcpNodeId = (string) ($activeTools[$normalizedFunctionName]['mcpServerNodeId'] ?? '');
        if ($mcpNodeId !== '') {
            $activeMcpIds = [$mcpNodeId];
            $executionDetails[$mcpNodeId] = [
                'toolName' => (string) ($activeTools[$normalizedFunctionName]['mcpToolName'] ?? $normalizedFunctionName),
                'arguments' => $arguments,
            ];
        }
    }
    if ($checkingResearch && !empty($arguments['name'])) {
        $researchMeta = get_research_meta((string) $arguments['name']);
        $researchNodeId = $researchMeta !== null ? $researchMeta['nodeId'] : research_node_id((string) $arguments['name']);
        $activeResearchIds = [$researchNodeId];
        $executionDetails[$researchNodeId] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingRules && !empty($arguments['name'])) {
        $rulesMeta = get_rules_meta((string) $arguments['name']);
        $rulesNodeId = $rulesMeta !== null ? $rulesMeta['nodeId'] : rules_node_id((string) $arguments['name']);
        $activeRulesIds = [$rulesNodeId];
        $executionDetails[$rulesNodeId] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingResearch && empty($arguments['name']) && in_array($normalizedFunctionName, ['list_research_files'], true)) {
        $executionDetails['research'] = $executionDetails['research'] ?? [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingRules && empty($arguments['name']) && in_array($normalizedFunctionName, ['list_rules_files'], true)) {
        $executionDetails['rules'] = $executionDetails['rules'] ?? [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if (in_array($normalizedFunctionName, ['create_category_node', 'delete_category_node'], true) && !empty($arguments['name'])) {
        $categoryNodeId = category_node_id((string) $arguments['name']);
        $executionDetails[$categoryNodeId] = [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }
    if ($checkingCategories && in_array($normalizedFunctionName, ['list_category_nodes'], true)) {
        $executionDetails['categories'] = $executionDetails['categories'] ?? [
            'toolName' => $normalizedFunctionName,
            'arguments' => $arguments,
        ];
    }

    return [
        'gettingAvailTools' => $gettingAvailTools,
        'checkingMemory' => $checkingMemory,
        'checkingInstructions' => $checkingInstructions,
        'checkingResearch' => $checkingResearch,
        'checkingRules' => $checkingRules,
        'checkingMcps' => $checkingMcps,
        'checkingJobs' => $checkingJobs,
        'activeToolIds' => $activeToolIds,
        'activeMemoryIds' => $activeMemoryIds,
        'activeInstructionIds' => $activeInstructionIds,
        'activeResearchIds' => $activeResearchIds,
        'activeRulesIds' => $activeRulesIds,
        'activeMcpIds' => $activeMcpIds,
        'activeJobIds' => $activeJobIds,
        'executionDetails' => $executionDetails,
    ];
}

function buildOpenAiTools(array $tools): array {
    $out = [];
    foreach ($tools as $tool) {
        if (empty($tool['active'])) {
            continue;
        }
        $out[] = [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['parameters'] ?? [
                    'type' => 'object',
                    'properties' => new stdClass(),
                ],
            ],
        ];
    }
    return $out;
}

function normalizeToolName(string $name): string {
    $normalized = strtolower(str_replace(['-', ' '], '_', trim($name)));
    $aliases = [
        'temp' => 'get_temperature',
        'temperature' => 'get_temperature',
        'list_all_memory_files' => 'list_memory_files',
        'list_all_memory' => 'list_memory_files',
        'list_memory' => 'list_memory_files',
        'get_memory' => 'read_memory_file',
        'modify_memory_file' => 'update_memory_file',
        'list_instructions' => 'list_instruction_files',
        'get_instruction' => 'read_instruction_file',
        'list_tool' => 'list_available_tools',
        'get_tool' => 'list_available_tools',
        'create_category' => 'create_category_node',
        'createcategorynode' => 'create_category_node',
        'add_category' => 'create_category_node',
        'add_category_node' => 'create_category_node',
    ];
    return $aliases[$normalized] ?? $aliases[$name] ?? $normalized;
}

function normalizeToolArguments(string $toolName, array $args): array {
    if ($toolName === 'get_temperature' && !isset($args['city']) && isset($args['location'])) {
        $args['city'] = $args['location'];
    }
    return $args;
}

function parseInlineToolCall(?string $content): ?array {
    if (!is_string($content)) {
        return null;
    }
    $trimmed = trim($content);
    if ($trimmed === '') {
        return null;
    }
    // Try direct parse first (content is raw JSON)
    $jsonStr = $trimmed;
    if ($trimmed[0] !== '{') {
        // Extract JSON from markdown code block or surrounding text
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $trimmed, $m)) {
            $jsonStr = trim($m[1]);
        } else {
            $start = strpos($trimmed, '{');
            if ($start === false) {
                return null;
            }
            $depth = 0;
            $len = strlen($trimmed);
            for ($i = $start; $i < $len; $i++) {
                $c = $trimmed[$i];
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $jsonStr = substr($trimmed, $start, $i - $start + 1);
                        break;
                    }
                }
            }
            if ($depth !== 0) {
                return null;
            }
        }
    }
    $decoded = json_decode($jsonStr, true);
    if (!is_array($decoded)) {
        return null;
    }
    $name = $decoded['tool'] ?? $decoded['name'] ?? null;
    $arguments = $decoded['arguments'] ?? $decoded['parameters'] ?? [];
    if (!is_string($name) || !is_array($arguments)) {
        return null;
    }
    return [
        'name' => normalizeToolName($name),
        'arguments' => normalizeToolArguments(normalizeToolName($name), $arguments),
    ];
}

function executePhpTool(string $toolName, array $arguments): array {
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $toolName);
    $toolPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . $safeName . '.php';
    if ($safeName === '' || !file_exists($toolPath)) {
        return ['error' => 'Tool file not found'];
    }

    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    try {
        $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] = $arguments;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        include $toolPath;
        $rawOutput = trim((string) ob_get_clean());

        unset($GLOBALS['MEMORY_GRAPH_TOOL_INPUT']);
        if ($previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousMethod;
        }

        $decoded = json_decode($rawOutput, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // If output looks like PHP error, convert to error format so AI gets fix directive
        if (preg_match('/^(Fatal error|Parse error|Warning|Notice|Deprecated):\s*(.+)$/im', $rawOutput, $m)) {
            return [
                'error' => 'Tool output PHP error: ' . trim($m[2]),
                'raw_output' => $rawOutput,
            ];
        }
        if (stripos($rawOutput, 'Fatal error') !== false || stripos($rawOutput, 'Parse error') !== false) {
            return [
                'error' => 'Tool produced PHP error. Check and fix the tool code.',
                'raw_output' => $rawOutput,
            ];
        }
        return ['result' => $rawOutput];
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        unset($GLOBALS['MEMORY_GRAPH_TOOL_INPUT']);
        if ($previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousMethod;
        }
        $msg = $e->getMessage();
        $file = $e->getFile();
        $line = $e->getLine();
        $class = get_class($e);
        return [
            'error' => "Tool exception ($class): $msg",
            'exception' => $class,
            'message' => $msg,
            'file' => basename($file),
            'line' => $line,
        ];
    }
}

function executeBuiltInTool(string $toolName, array $arguments, array $activeTools): array {
    if (in_array($toolName, ['list_available_tools', 'list_tools', 'get_tools'], true)) {
        $toolCallsPath = tool_registry_path();
        $rawJson = file_exists($toolCallsPath) ? (string) file_get_contents($toolCallsPath) : '{"tools":[]}';
        $activeOnly = array_filter($activeTools, function ($tool) {
            return !empty($tool['active']);
        });
        return [
            'tools' => array_values(array_map(function ($tool) {
                return [
                    'name' => $tool['name'] ?? '',
                    'description' => $tool['description'] ?? '',
                    'active' => true,
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new stdClass()],
                    'builtin' => !empty($tool['builtin']),
                    'code' => !empty($tool['builtin']) ? ($tool['code'] ?? '') : (read_tool_file_content((string) ($tool['name'] ?? '')) ?? ''),
                ];
            }, $activeOnly)),
            'tool_calls_json' => $rawJson,
        ];
    }
    if ($toolName === 'list_memory_files') {
        $all = list_memory_files_meta();
        $memories = array_values(array_filter($all, function ($m) {
            return !empty($m['active']);
        }));
        $memories = array_map(function ($memory) {
            unset($memory['content']);
            return $memory;
        }, $memories);
        return ['memories' => $memories];
    }
    if ($toolName === 'list_instruction_files') {
        $instructions = array_map(function ($instruction) {
            unset($instruction['content']);
            return $instruction;
        }, list_instruction_files_meta());
        return ['instructions' => array_values($instructions)];
    }
    if ($toolName === 'list_job_files') {
        $jobs = array_map(function ($job) {
            unset($job['content']);
            return $job;
        }, list_job_files_meta());
        return ['jobs' => array_values($jobs)];
    }
    if ($toolName === 'list_mcp_servers') {
        $all = list_mcp_servers_meta();
        $servers = array_values(array_filter($all, function ($s) {
            return !empty($s['active']);
        }));
        return ['servers' => $servers];
    }
    if ($toolName === 'read_mcp_server') {
        $server = get_mcp_server_meta((string) ($arguments['name'] ?? ''));
        if ($server === null) {
            return ['error' => 'MCP server not found'];
        }
        return $server;
    }
    if ($toolName === 'list_mcp_server_tools') {
        $server = get_mcp_server_meta((string) ($arguments['name'] ?? ''));
        if ($server === null) {
            return ['error' => 'MCP server not found'];
        }
        return mcp_list_server_tools($server);
    }
    if ($toolName === 'read_instruction_file') {
        $instruction = get_instruction_meta((string) ($arguments['name'] ?? ''));
        if ($instruction === null) {
            return ['error' => 'Instruction file not found'];
        }
        return $instruction;
    }
    if ($toolName === 'read_job_file' || $toolName === 'execute_job_file') {
        $job = get_job_meta((string) ($arguments['name'] ?? ''));
        if ($job === null) {
            return ['error' => 'Job file not found'];
        }
        return $job;
    }
    if ($toolName === 'create_instruction_file') {
        return create_instruction_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'update_instruction_file') {
        return update_instruction_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'delete_instruction_file') {
        return delete_instruction_file_by_name((string) ($arguments['name'] ?? ''));
    }
    if ($toolName === 'list_research_files') {
        return ['research' => array_map(function ($r) {
            unset($r['content']);
            return $r;
        }, list_research_files_meta())];
    }
    if ($toolName === 'read_research_file') {
        $research = get_research_meta((string) ($arguments['name'] ?? ''));
        if ($research === null) {
            return ['error' => 'Research file not found'];
        }
        return $research;
    }
    if ($toolName === 'add_research_file') {
        return write_research_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'create_research_file') {
        return create_research_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'update_research_file') {
        return update_research_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'delete_research_file') {
        return delete_research_file_by_name((string) ($arguments['name'] ?? ''));
    }
    if ($toolName === 'list_rules_files') {
        return ['rules' => array_map(function ($r) {
            unset($r['content']);
            return $r;
        }, list_rules_files_meta())];
    }
    if ($toolName === 'read_rules_file') {
        $rules = get_rules_meta((string) ($arguments['name'] ?? ''));
        if ($rules === null) {
            return ['error' => 'Rules file not found'];
        }
        return $rules;
    }
    if ($toolName === 'add_rules_file') {
        return write_rules_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'create_rules_file') {
        return create_rules_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'update_rules_file') {
        return update_rules_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'delete_rules_file') {
        return delete_rules_file_by_name((string) ($arguments['name'] ?? ''));
    }
    if ($toolName === 'create_job_file') {
        return create_job_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'update_job_file') {
        return update_job_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($toolName === 'delete_job_file') {
        return delete_job_file_by_name((string) ($arguments['name'] ?? ''));
    }
    if ($toolName === 'create_category_node') {
        return create_category_node(
            (string) ($arguments['name'] ?? ''),
            (string) ($arguments['title'] ?? ''),
            (string) ($arguments['description'] ?? '')
        );
    }
    if ($toolName === 'list_category_nodes') {
        return ['categories' => list_category_nodes_meta()];
    }
    if ($toolName === 'delete_category_node') {
        return delete_category_node_by_name((string) ($arguments['name'] ?? ''));
    }
    if ($toolName === 'create_mcp_server') {
        return upsert_mcp_server_artifact($arguments);
    }
    if ($toolName === 'update_mcp_server') {
        $server = get_mcp_server_meta((string) ($arguments['original_name'] ?? ''));
        if ($server === null) {
            return ['error' => 'MCP server not found'];
        }
        $merged = array_merge($server, $arguments);
        if (!empty($arguments['original_name']) && empty($arguments['name'])) {
            $merged['name'] = (string) $arguments['original_name'];
        }
        return upsert_mcp_server_artifact($merged, (string) ($arguments['original_name'] ?? ''));
    }
    if ($toolName === 'configure_mcp_server') {
        return configure_mcp_server_artifact((string) ($arguments['name'] ?? ''), $arguments);
    }
    if ($toolName === 'set_mcp_server_env_var') {
        return set_mcp_server_env_var_artifact(
            (string) ($arguments['name'] ?? ''),
            (string) ($arguments['key'] ?? ''),
            (string) ($arguments['value'] ?? '')
        );
    }
    if ($toolName === 'remove_mcp_server_env_var') {
        return remove_mcp_server_env_var_artifact(
            (string) ($arguments['name'] ?? ''),
            (string) ($arguments['key'] ?? '')
        );
    }
    if ($toolName === 'set_mcp_server_header') {
        return set_mcp_server_header_artifact(
            (string) ($arguments['name'] ?? ''),
            (string) ($arguments['key'] ?? ''),
            (string) ($arguments['value'] ?? '')
        );
    }
    if ($toolName === 'remove_mcp_server_header') {
        return remove_mcp_server_header_artifact(
            (string) ($arguments['name'] ?? ''),
            (string) ($arguments['key'] ?? '')
        );
    }
    if ($toolName === 'set_mcp_server_active') {
        return set_mcp_server_active_artifact((string) ($arguments['name'] ?? ''), !empty($arguments['active']));
    }
    if ($toolName === 'delete_mcp_server') {
        return delete_mcp_server_artifact((string) ($arguments['name'] ?? ''));
    }
    if ($toolName === 'create_or_update_tool') {
        return create_or_update_tool_artifact(
            (string) ($arguments['name'] ?? ''),
            (string) ($arguments['description'] ?? ''),
            $arguments['parameters'] ?? null,
            (string) ($arguments['php_code'] ?? ''),
            array_key_exists('active', $arguments)
                ? !empty($arguments['active'])
                : true
        );
    }
    if ($toolName === 'edit_tool_file') {
        return edit_tool_file_artifact(
            (string) ($arguments['name'] ?? ''),
            (string) ($arguments['php_code'] ?? '')
        );
    }
    if ($toolName === 'edit_tool_registry_entry') {
        return edit_tool_registry_entry_artifact(
            (string) ($arguments['name'] ?? ''),
            $arguments
        );
    }
    if ($toolName === 'delete_tool') {
        return delete_tool_artifact((string) ($arguments['name'] ?? ''));
    }
    if ($toolName === 'get_current_provider_model') {
        return get_current_provider_model();
    }
    if ($toolName === 'set_provider_model') {
        $provider = (string) ($arguments['provider'] ?? $arguments['providerKey'] ?? '');
        $model = (string) ($arguments['model'] ?? $arguments['modelId'] ?? '');
        if ($provider === '') {
            return ['error' => 'provider is required'];
        }
        return set_current_provider_model($provider, $model);
    }
    if ($toolName === 'list_providers_models') {
        return list_providers_models_for_tool();
    }
    if ($toolName === 'list_providers_available') {
        return list_providers_available();
    }
    if ($toolName === 'list_models_for_provider') {
        $providerKey = (string) ($arguments['providerKey'] ?? $arguments['provider'] ?? '');
        if ($providerKey === '') {
            return ['error' => 'providerKey is required'];
        }
        return list_models_for_provider($providerKey);
    }
    if ($toolName === 'list_chat_history') {
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 20;
        $offset = isset($arguments['offset']) ? (int) $arguments['offset'] : 0;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return list_chat_history($limit, $offset);
    }
    if ($toolName === 'get_chat_history') {
        $id = (string) ($arguments['id'] ?? $arguments['requestId'] ?? '');
        if ($id === '') {
            return ['error' => 'id or requestId is required'];
        }
        $exchange = get_chat_history($id);
        if ($exchange === null) {
            return ['error' => 'Chat exchange not found', 'id' => $id];
        }
        return $exchange;
    }
    if ($toolName === 'add_provider') {
        $key = (string) ($arguments['key'] ?? $arguments['providerKey'] ?? '');
        $name = (string) ($arguments['name'] ?? $key);
        $endpoint = (string) ($arguments['endpoint'] ?? $arguments['endpointBase'] ?? '');
        $type = (string) ($arguments['type'] ?? 'openai');
        $defaultModel = (string) ($arguments['defaultModel'] ?? '');
        $envVar = (string) ($arguments['envVar'] ?? '');
        if ($key === '') {
            return ['error' => 'key is required'];
        }
        return add_custom_provider($key, $name, $endpoint, $type, $defaultModel, $envVar);
    }
    if ($toolName === 'add_model_to_provider') {
        $providerKey = (string) ($arguments['providerKey'] ?? $arguments['provider'] ?? '');
        $modelId = (string) ($arguments['modelId'] ?? $arguments['model'] ?? '');
        if ($providerKey === '' || $modelId === '') {
            return ['error' => 'providerKey and modelId are required'];
        }
        return add_model_to_provider($providerKey, $modelId);
    }
    return ['error' => 'Unknown built-in tool: ' . $toolName . '. Use list_available_tools to see valid tool names.'];
}

function executeToolCall(string $toolName, array $arguments, array $activeTools): array {
    try {
        return executeToolCallInner($toolName, $arguments, $activeTools);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $class = get_class($e);
        return [
            'error' => "Tool execution failed ($class): $msg",
            'exception' => $class,
            'message' => $msg,
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ];
    }
}

function executeToolCallInner(string $toolName, array $arguments, array $activeTools): array {
    $normalizedName = normalizeToolName($toolName);
    if (!isset($activeTools[$normalizedName])) {
        return ['error' => 'Tool is not active or not registered', '__disabled' => false];
    }
    if (empty($activeTools[$normalizedName]['active'])) {
        return [
            'error' => 'That tool has been disabled for me, please enable it if you want me to use that tool.',
            '__disabled' => true,
        ];
    }
    if (!empty($activeTools[$normalizedName]['mcp'])) {
        $server = get_mcp_server_meta((string) ($activeTools[$normalizedName]['mcpServerName'] ?? ''));
        if ($server === null) {
            return ['error' => 'MCP server not found'];
        }
        if (empty($server['active'])) {
            return [
                'error' => 'That MCP server has been disabled for me, please enable it if you want me to use that MCP server.',
                '__disabled' => true,
            ];
        }
        $result = mcp_call_server_tool($server, (string) ($activeTools[$normalizedName]['mcpToolName'] ?? $normalizedName), $arguments);
        $result['__mcp_server_name'] = $server['name'] ?? '';
        $result['__mcp_node_id'] = $server['nodeId'] ?? '';
        return $result;
    }
    if ($normalizedName === 'read_memory_file') {
        $memory = get_memory_meta((string) ($arguments['name'] ?? ''));
        if ($memory === null) {
            return ['error' => 'Memory file not found'];
        }
        if (empty($memory['active'])) {
            return ['error' => 'That memory file has been disabled for me, please enable it if you want me to use that memory.', '__disabled_memory' => true];
        }
        return [
            'name' => $memory['name'],
            'title' => $memory['title'],
            'active' => $memory['active'],
            'nodeId' => $memory['nodeId'],
            'content' => $memory['content'],
        ];
    }
    if ($normalizedName === 'add_memory_file') {
        return write_memory_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($normalizedName === 'create_memory_file') {
        return create_memory_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($normalizedName === 'update_memory_file') {
        return update_memory_file((string) ($arguments['name'] ?? ''), (string) ($arguments['content'] ?? ''));
    }
    if ($normalizedName === 'delete_memory_file') {
        return delete_memory_file_by_name((string) ($arguments['name'] ?? ''));
    }
    if (!empty($activeTools[$normalizedName]['builtin'])) {
        return executeBuiltInTool($normalizedName, $arguments, $activeTools);
    }
    return executePhpTool($normalizedName, normalizeToolArguments($normalizedName, $arguments));
}


function buildToolUsageInstruction(array $activeTools): string {
    $toolNames = array_values(array_map(function ($tool) {
        return (string) ($tool['name'] ?? '');
    }, array_filter($activeTools, function ($tool) {
        return !empty($tool['active']);
    })));
    sort($toolNames);
    $toolList = implode(', ', array_slice($toolNames, 0, 80));

    return trim(
        "You have access to tools, including tools for reading/writing memory when they are active.\n" .
        "If the model/provider supports native function calling, use it.\n" .
        "If native function calling is unavailable or not used, you must call a tool by replying with ONLY valid JSON in this exact shape and nothing else:\n" .
        "{\"tool\":\"tool_name\",\"arguments\":{\"arg\":\"value\"}}\n" .
        "Do not wrap that JSON in markdown fences.\n" .
        "To discover what tools are currently available, call list_available_tools.\n" .
        "To work with memory, use the memory tools such as list_memory_files and read_memory_file when available.\n" .
        "To configure MCP servers, use the MCP config tools such as configure_mcp_server or set_mcp_server_env_var when available.\n" .
        "When the user asks to create a category node (e.g. database, api, cache) under the Agent, use create_category_node; the node appears in the graph in real-time.\n" .
        "If you need context from earlier conversations, use list_chat_history and get_chat_history to look up past exchanges.\n" .
        "If the user explicitly provides local credentials, private keys, API keys, env vars, headers, or similar config values for a tool or MCP server, you may use them to configure the local app and MCP servers. Do not refuse solely because the value looks secret.\n" .
        "CRITICAL - Tool creation: When you use create_or_update_tool, you MUST immediately call the newly created tool to test it. If it fails (error in result), use edit_tool_file to fix the PHP code and call the tool again. Repeat until the tool succeeds. Never respond to the user or report success until you have tested the tool and it works. This applies no matter what - always test, always fix until success.\n" .
        "When any tool returns an error (result contains an 'error' field), you MUST fix the tool and retry: use edit_tool_file to change the tool's PHP code, or edit_tool_registry_entry to change its description/parameters. Then call the tool again. Keep editing and retrying until the tool succeeds; do not give up or report the error to the user until you have retried by fixing the tool.\n" .
        ($toolList !== '' ? "Currently active tools include: " . $toolList . "\n" : '') .
        "When you are not calling a tool, answer normally."
    );
}

/** Build the user/tool message content for a tool result; when result has 'error', append fix-and-retry directive. */
function formatToolResultForModel(string $toolName, array $toolResult, bool $inlineFormat = false): string {
    $fixDirective = ' You MUST fix the tool using edit_tool_file (to change the PHP code) or edit_tool_registry_entry (to change parameters), then call the tool again. Keep retrying until the tool succeeds; do not report the error to the user until you have retried.';
    if (isset($toolResult['error']) && isset($toolResult['file']) && isset($toolResult['line'])) {
        $fixDirective = ' Fix the error in tools/' . $toolResult['file'] . ' at line ' . $toolResult['line'] . ' using edit_tool_file, then call the tool again. Keep retrying until the tool succeeds; do not report the error to the user until you have retried.';
    } elseif (isset($toolResult['error'])) {
        $fixDirective = ' You MUST fix the tool using edit_tool_file (to change the PHP code) or edit_tool_registry_entry (to change parameters), then call the tool again. Keep retrying until the tool succeeds; do not report the error to the user until you have retried.';
    }
    $json = json_encode($toolResult);
    if ($inlineFormat) {
        $base = 'Tool "' . $toolName . '" returned: ' . $json;
        if (isset($toolResult['error'])) {
            return $base . '. The result contains an error.' . $fixDirective;
        }
        if ($toolName === 'create_or_update_tool' && !isset($toolResult['error'])) {
            $name = isset($toolResult['name']) ? (string) $toolResult['name'] : '';
            return $base . '. You MUST now call the tool "' . $name . '" to test it. If it fails, use edit_tool_file to fix the code and test again. Never respond to the user until the tool works successfully.';
        }
        return $base . '. Continue and answer the original user request.';
    }
    if (isset($toolResult['error'])) {
        $loc = (isset($toolResult['file']) && isset($toolResult['line']))
            ? ' Fix tools/' . $toolResult['file'] . ' line ' . $toolResult['line'] . '.'
            : '';
        return 'Error - fix this tool with edit_tool_file or edit_tool_registry_entry and call it again.' . $loc . ' ' . $json;
    }
    if ($toolName === 'create_or_update_tool') {
        $name = isset($toolResult['name']) ? (string) $toolResult['name'] : '';
        return $json . "\n\nYou MUST now call the tool \"" . $name . "\" to test it. If it fails, use edit_tool_file to fix the code and test again. Never respond to the user until the tool works successfully.";
    }
    return $json;
}

function normalizeConversation(array $messages, string $systemPrompt, string $providerType): array {
    $conversation = [];
    if ($providerType === 'openai' && $systemPrompt !== '') {
        $conversation[] = ['role' => 'system', 'content' => $systemPrompt];
    }
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $role = $message['role'] ?? 'user';
        $content = $message['content'] ?? '';
        if (!is_string($content)) {
            $content = $content === null || $content === false ? '' : json_encode($content);
        }
        $content = (string) $content;
        if ($role === 'system') {
            continue;
        }
        $entry = ['role' => $role, 'content' => $content];
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            $entry['tool_calls'] = $message['tool_calls'];
        }
        if ($role === 'tool' && isset($message['tool_call_id'])) {
            $entry['tool_call_id'] = $message['tool_call_id'];
            $entry['name'] = $message['name'] ?? '';
        }
        $conversation[] = $entry;
    }
    return $conversation;
}

/** Truncate conversation to avoid context length limits. Keeps system (if first) + last N messages; caps each content length. */
function truncateConversationForContext(array $conversation, int $maxMessages = 28, int $maxContentChars = 12000): array {
    if (count($conversation) <= $maxMessages) {
        $out = $conversation;
    } else {
        $system = [];
        if (isset($conversation[0]) && is_array($conversation[0]) && ($conversation[0]['role'] ?? '') === 'system') {
            $system = [array_shift($conversation)];
        }
        $out = array_merge($system, array_slice($conversation, -($maxMessages - count($system))));
    }
    $result = [];
    foreach ($out as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $content = $msg['content'] ?? '';
        if (is_string($content) && strlen($content) > $maxContentChars) {
            $msg = array_merge($msg, ['content' => substr($content, 0, $maxContentChars) . "\n\n[truncated for context length]"]);
        }
        $result[] = $msg;
    }
    return $result;
}

/** Ensure every message has string content for OpenAI-compatible APIs (avoids "Input should be a valid string"). */
function sanitizeConversationForApi(array $conversation): array {
    $out = [];
    foreach ($conversation as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $role = $msg['role'] ?? 'user';
        $content = $msg['content'] ?? '';
        if (!is_string($content)) {
            $content = ($content === null || $content === false) ? '' : json_encode($content);
        }
        $content = (string) $content;
        $entry = ['role' => $role, 'content' => $content];
        if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
            $entry['tool_calls'] = $msg['tool_calls'];
        }
        if ($role === 'tool') {
            $entry['tool_call_id'] = $msg['tool_call_id'] ?? '';
            $entry['name'] = $msg['name'] ?? '';
        }
        $out[] = $entry;
    }
    return $out;
}

function requestOpenAiCompatible(array $provider, string $model, array $conversation, float $temperature, array $tools): array {
    $payload = [
        'model' => $model,
        'messages' => $conversation,
        'temperature' => $temperature,
    ];
    if (!empty($tools)) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = 'auto';
    }

    $ch = curl_init($provider['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $provider['apiKey'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'Gateway error: ' . $err, 'httpCode' => 502];
    }
    if ($httpCode >= 400) {
        return ['error' => $response ?: 'Provider request failed', 'httpCode' => $httpCode, 'raw' => $response];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid provider response', 'httpCode' => 502];
    }
    return ['data' => $decoded, 'httpCode' => $httpCode];
}

function requestGemini(array $provider, string $model, array $conversation, float $temperature, string $systemPrompt): array {
    $contents = [];
    foreach ($conversation as $message) {
        $role = $message['role'] ?? 'user';
        if ($role === 'system') {
            continue;
        }
        $contents[] = [
            'role' => $role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => (string) ($message['content'] ?? '')]],
        ];
    }
    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => $temperature,
        ],
    ];
    if ($systemPrompt !== '') {
        $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
    }

    $url = $provider['endpointBase'] . '/' . $model . ':generateContent?key=' . $provider['apiKey'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'Gateway error: ' . $err, 'httpCode' => 502];
    }
    if ($httpCode >= 400) {
        return ['error' => $response ?: 'Provider request failed', 'httpCode' => $httpCode, 'raw' => $response];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid provider response', 'httpCode' => 502];
    }
    return ['data' => $decoded, 'httpCode' => $httpCode];
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$messages = $input['messages'] ?? [];
$providerKey = $input['provider'] ?? 'mercury';
$model = isset($input['model']) ? (string) $input['model'] : null;
$systemPrompt = isset($input['systemPrompt']) ? (string) $input['systemPrompt'] : '';
$temperature = isset($input['temperature']) ? (float) $input['temperature'] : 0.7;
$requestId = sanitizeRequestId(isset($input['requestId']) ? (string) $input['requestId'] : null);

$status = [
    'requestId' => $requestId,
    'thinking' => true,
    'gettingAvailTools' => false,
    'checkingMemory' => false,
    'checkingInstructions' => false,
    'checkingMcps' => false,
    'checkingJobs' => false,
    'activeToolIds' => [],
    'activeMemoryIds' => [],
    'activeInstructionIds' => [],
    'activeMcpIds' => [],
    'activeJobIds' => [],
    'executionDetailsByNode' => [],
    'lastGettingAvailTools' => false,
    'lastCheckingMemory' => false,
    'lastCheckingInstructions' => false,
    'lastCheckingMcps' => false,
    'lastCheckingJobs' => false,
    'lastActiveToolIds' => [],
    'lastActiveMemoryIds' => [],
    'lastActiveInstructionIds' => [],
    'lastActiveMcpIds' => [],
    'lastActiveJobIds' => [],
    'lastExecutionDetailsByNode' => [],
    'lastEventExpiresAtMs' => 0,
    'graphRefreshToken' => '',
];
writeStatus($requestId, $status);

if (empty($messages)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing messages']);
    exit;
}
$initialUserContent = '';
foreach (array_reverse($messages) as $m) {
    if (is_array($m) && isset($m['role']) && $m['role'] === 'user' && isset($m['content'])) {
        $initialUserContent = is_string($m['content']) ? $m['content'] : json_encode($m['content']);
        break;
    }
}
if (!isset($providers[$providerKey])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown provider']);
    exit;
}

$provider = $providers[$providerKey];
if (($provider['apiKey'] ?? '') === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Missing API key for provider "' . $providerKey . '". Set it in .env.']);
    exit;
}
// Resolve model: must be valid for this provider to avoid sending e.g. mercury-2 to Gemini API
$uiProviders = get_providers_for_ui();
$allowedModels = isset($uiProviders['providers'][$providerKey]['models']) && is_array($uiProviders['providers'][$providerKey]['models'])
    ? $uiProviders['providers'][$providerKey]['models']
    : [];
if ($model !== null && $model !== '' && !in_array($model, $allowedModels, true)) {
    $model = $provider['defaultModel'] ?? '';
}
$modelId = ($model !== null && $model !== '') ? $model : ($provider['defaultModel'] ?? '');
$activeTools = loadToolRegistry();
$openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
$effectiveSystemPrompt = trim(($systemPrompt !== '' ? $systemPrompt . "\n\n" : '') . buildToolUsageInstruction($activeTools));
$conversation = normalizeConversation($messages, $effectiveSystemPrompt, $provider['type']);
$finalContent = '';
$loopCount = 0;
$jobsToRun = [];

$apiRetryCount = 0;
$apiErrorRetryMax = 3;

while (true) {
    $loopCount++;
    if ($loopCount > 25) {
        $finalContent = trim($finalContent) !== '' ? $finalContent : 'Stopped after too many tool iterations.';
        break;
    }

    $conversationToSend = sanitizeConversationForApi(truncateConversationForContext($conversation, 20, 6000));
    $result = $provider['type'] === 'gemini'
        ? requestGemini($provider, $modelId, $conversationToSend, $temperature, $effectiveSystemPrompt)
        : requestOpenAiCompatible($provider, $modelId, $conversationToSend, $temperature, $openAiTools);

    if (isset($result['error'])) {
        $rawBody = isset($result['raw']) ? $result['raw'] : '';
        $isValidationError = ($rawBody !== '' && (stripos($rawBody, 'invalid_request_error') !== false || stripos($rawBody, 'Input should be a valid string') !== false || stripos($rawBody, 'Input should be a valid list') !== false))
            || (is_array($result['error']) && isset($result['error']['type']) && (strpos((string) $result['error']['type'], 'invalid') !== false));
        if ($isValidationError && $apiRetryCount < $apiErrorRetryMax) {
            $apiRetryCount++;
            $conversation = sanitizeConversationForApi($conversation);
            continue;
        }
        clearStatusFlags($status);
        writeStatus($requestId, $status);
        http_response_code($result['httpCode'] ?? 502);
        if (isset($result['raw'])) {
            echo $result['raw'];
        } else {
            $err = $result['error'];
            if (is_array($err) || is_object($err)) {
                $err = isset($err['message']) ? (string) $err['message'] : json_encode($err);
            } else {
                $err = (string) $err;
            }
            echo json_encode(['error' => $err]);
        }
        exit;
    }
    $apiRetryCount = 0;

    $data = $result['data'];

    if ($provider['type'] === 'openai') {
        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            clearStatusFlags($status);
            writeStatus($requestId, $status);
            http_response_code(502);
            echo json_encode(['error' => 'Invalid provider response']);
            exit;
        }

        $rawContent = $message['content'] ?? null;
        if (is_array($rawContent)) {
            $assistantContent = '';
            foreach ($rawContent as $part) {
                if (is_array($part) && isset($part['type'], $part['text']) && $part['type'] === 'text') {
                    $assistantContent .= (string) $part['text'];
                }
            }
        } elseif ($rawContent === null || $rawContent === false) {
            $assistantContent = '';
        } else {
            $assistantContent = (string) $rawContent;
        }
        $toolCalls = $message['tool_calls'] ?? [];

        if (!empty($toolCalls) && is_array($toolCalls)) {
            $conversation[] = [
                'role' => 'assistant',
                'content' => $assistantContent,
                'tool_calls' => $toolCalls,
            ];
            foreach ($toolCalls as $toolCall) {
                $callId = $toolCall['id'] ?? uniqid('tool_', true);
                $functionName = $toolCall['function']['name'] ?? '';
                $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);
                $arguments = is_array($arguments) ? $arguments : [];
                $normalizedFunctionName = normalizeToolName($functionName);
                $executionState = buildExecutionStateForToolCall($normalizedFunctionName, $arguments, $activeTools);
                markExecutionStatus(
                    $status,
                    $requestId,
                    $executionState['gettingAvailTools'],
                    $executionState['checkingMemory'],
                    $executionState['checkingInstructions'],
                    $executionState['checkingResearch'],
                    $executionState['checkingRules'],
                    $executionState['checkingMcps'],
                    $executionState['checkingJobs'],
                    $executionState['activeToolIds'],
                    $executionState['activeMemoryIds'],
                    $executionState['activeInstructionIds'],
                    $executionState['activeResearchIds'],
                    $executionState['activeRulesIds'],
                    $executionState['activeMcpIds'],
                    $executionState['activeJobIds'],
                    $executionState['executionDetails']
                );
                usleep(120000);
                try {
                    $toolResult = executeToolCall($functionName, $arguments, $activeTools);
                } catch (Throwable $e) {
                    $toolResult = [
                        'error' => 'Tool execution threw: ' . $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                    ];
                }
                $toolResult = is_array($toolResult) ? $toolResult : ['error' => 'Tool returned invalid result'];
                if ($normalizedFunctionName === 'execute_job_file' && !isset($toolResult['error']) && !empty($toolResult['name']) && !empty($toolResult['content'])) {
                    $jobsToRun[] = [
                        'name' => $toolResult['name'],
                        'content' => $toolResult['content'],
                        'nodeId' => $toolResult['nodeId'] ?? job_node_id($toolResult['name']),
                    ];
                }
                if (shouldRefreshGraphForToolResult($normalizedFunctionName, is_array($toolResult) ? $toolResult : [])) {
                    queueGraphRefresh($status, $requestId);
                }
                if (!empty($toolResult['__disabled'])) {
                    $finalContent = 'That tool has been disabled for me, please enable it if you want me to use that tool.';
                    break 2;
                }
                if (!empty($toolResult['__disabled_memory'])) {
                    $finalContent = 'That memory file has been disabled for me, please enable it if you want me to use that memory.';
                    break 2;
                }
                $toolResultArr = is_array($toolResult) ? $toolResult : ['result' => $toolResult];
                $conversation[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'name' => normalizeToolName($functionName),
                    'content' => formatToolResultForModel($normalizedFunctionName, $toolResultArr, false),
                ];
                $activeTools = loadToolRegistry();
                $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
                if ($normalizedFunctionName === 'set_provider_model' && is_array($toolResult) && !empty($toolResult['ok'])) {
                    $cfg = get_current_provider_model();
                    $providerKey = $cfg['provider'];
                    $model = $cfg['model'];
                    if (isset($providers[$providerKey])) {
                        $provider = $providers[$providerKey];
                        $uiProviders = get_providers_for_ui();
                        $allowedModels = isset($uiProviders['providers'][$providerKey]['models']) && is_array($uiProviders['providers'][$providerKey]['models'])
                            ? $uiProviders['providers'][$providerKey]['models']
                            : [];
                        if (!in_array($model, $allowedModels, true)) {
                            $model = $provider['defaultModel'] ?? $model;
                        }
                        $modelId = $model;
                        $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
                    }
                }
            }
            continue;
        }

        $inlineToolCall = parseInlineToolCall($assistantContent);
        if ($inlineToolCall !== null) {
            $executionState = buildExecutionStateForToolCall($inlineToolCall['name'], $inlineToolCall['arguments'], $activeTools);
            markExecutionStatus(
                $status,
                $requestId,
                $executionState['gettingAvailTools'],
                $executionState['checkingMemory'],
                $executionState['checkingInstructions'],
                $executionState['checkingResearch'],
                $executionState['checkingRules'],
                $executionState['checkingMcps'],
                $executionState['checkingJobs'],
                $executionState['activeToolIds'],
                $executionState['activeMemoryIds'],
                $executionState['activeInstructionIds'],
                $executionState['activeResearchIds'],
                $executionState['activeRulesIds'],
                $executionState['activeMcpIds'],
                $executionState['activeJobIds'],
                $executionState['executionDetails']
            );
            usleep(45000);
            try {
                $toolResult = executeToolCall($inlineToolCall['name'], $inlineToolCall['arguments'], $activeTools);
            } catch (Throwable $e) {
                $toolResult = [
                    'error' => 'Tool execution threw: ' . $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ];
            }
            $toolResult = is_array($toolResult) ? $toolResult : ['error' => 'Tool returned invalid result'];
            $normalizedInlineName = normalizeToolName($inlineToolCall['name']);
            if ($normalizedInlineName === 'execute_job_file' && !isset($toolResult['error']) && !empty($toolResult['name']) && !empty($toolResult['content'])) {
                $jobsToRun[] = [
                    'name' => $toolResult['name'],
                    'content' => $toolResult['content'],
                    'nodeId' => $toolResult['nodeId'] ?? job_node_id($toolResult['name']),
                ];
            }
            if (shouldRefreshGraphForToolResult($inlineToolCall['name'], is_array($toolResult) ? $toolResult : [])) {
                queueGraphRefresh($status, $requestId);
            }
            if (!empty($toolResult['__disabled'])) {
                $finalContent = 'That tool has been disabled for me, please enable it if you want me to use that tool.';
                break;
            }
            if (!empty($toolResult['__disabled_memory'])) {
                $finalContent = 'That memory file has been disabled for me, please enable it if you want me to use that memory.';
                break;
            }
            $conversation[] = ['role' => 'assistant', 'content' => $assistantContent];
            $toolResultArr = is_array($toolResult) ? $toolResult : ['result' => $toolResult];
            $conversation[] = [
                'role' => 'user',
                'content' => formatToolResultForModel($inlineToolCall['name'], $toolResultArr, true),
            ];
            $activeTools = loadToolRegistry();
            $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
            if ($inlineToolCall['name'] === 'set_provider_model' && is_array($toolResult) && !empty($toolResult['ok'])) {
                $cfg = get_current_provider_model();
                $providerKey = $cfg['provider'];
                $model = $cfg['model'];
                if (isset($providers[$providerKey])) {
                    $provider = $providers[$providerKey];
                    $uiProviders = get_providers_for_ui();
                    $allowedModels = isset($uiProviders['providers'][$providerKey]['models']) && is_array($uiProviders['providers'][$providerKey]['models'])
                        ? $uiProviders['providers'][$providerKey]['models']
                        : [];
                    if (!in_array($model, $allowedModels, true)) {
                        $model = $provider['defaultModel'] ?? $model;
                    }
                    $modelId = $model;
                    $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
                }
            }
            continue;
        }

        $finalContent = $assistantContent;
        break;
    }

    $assistantContent = '';
    if (isset($data['candidates'][0]['content']['parts']) && is_array($data['candidates'][0]['content']['parts'])) {
        foreach ($data['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) {
                $assistantContent .= (string) $part['text'];
            }
        }
    }

    $inlineToolCall = parseInlineToolCall($assistantContent);
    if ($inlineToolCall !== null) {
        $executionState = buildExecutionStateForToolCall($inlineToolCall['name'], $inlineToolCall['arguments'], $activeTools);
        markExecutionStatus(
            $status,
            $requestId,
            $executionState['gettingAvailTools'],
            $executionState['checkingMemory'],
            $executionState['checkingInstructions'],
            $executionState['checkingResearch'],
            $executionState['checkingRules'],
            $executionState['checkingMcps'],
            $executionState['checkingJobs'],
            $executionState['activeToolIds'],
            $executionState['activeMemoryIds'],
            $executionState['activeInstructionIds'],
            $executionState['activeResearchIds'],
            $executionState['activeRulesIds'],
            $executionState['activeMcpIds'],
            $executionState['activeJobIds'],
            $executionState['executionDetails']
        );
        usleep(120000);
        try {
            $toolResult = executeToolCall($inlineToolCall['name'], $inlineToolCall['arguments'], $activeTools);
        } catch (Throwable $e) {
            $toolResult = [
                'error' => 'Tool execution threw: ' . $e->getMessage(),
                'exception' => get_class($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ];
        }
        $toolResult = is_array($toolResult) ? $toolResult : ['error' => 'Tool returned invalid result'];
        $normalizedInlineName = normalizeToolName($inlineToolCall['name']);
        if ($normalizedInlineName === 'execute_job_file' && !isset($toolResult['error']) && !empty($toolResult['name']) && !empty($toolResult['content'])) {
            $jobsToRun[] = [
                'name' => $toolResult['name'],
                'content' => $toolResult['content'],
                'nodeId' => $toolResult['nodeId'] ?? job_node_id($toolResult['name']),
            ];
        }
        if (shouldRefreshGraphForToolResult($inlineToolCall['name'], is_array($toolResult) ? $toolResult : [])) {
            queueGraphRefresh($status, $requestId);
        }
        if (!empty($toolResult['__disabled'])) {
            $finalContent = 'That tool has been disabled for me, please enable it if you want me to use that tool.';
            break;
        }
        if (!empty($toolResult['__disabled_memory'])) {
            $finalContent = 'That memory file has been disabled for me, please enable it if you want me to use that memory.';
            break;
        }
        $conversation[] = ['role' => 'assistant', 'content' => $assistantContent];
        $toolResultArr = is_array($toolResult) ? $toolResult : ['result' => $toolResult];
        $conversation[] = [
            'role' => 'user',
            'content' => formatToolResultForModel($inlineToolCall['name'], $toolResultArr, true),
        ];
        $activeTools = loadToolRegistry();
        $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
        if ($inlineToolCall['name'] === 'set_provider_model' && is_array($toolResult) && !empty($toolResult['ok'])) {
            $cfg = get_current_provider_model();
            $providerKey = $cfg['provider'];
            $model = $cfg['model'];
            if (isset($providers[$providerKey])) {
                $provider = $providers[$providerKey];
                $uiProviders = get_providers_for_ui();
                $allowedModels = isset($uiProviders['providers'][$providerKey]['models']) && is_array($uiProviders['providers'][$providerKey]['models'])
                    ? $uiProviders['providers'][$providerKey]['models']
                    : [];
                if (!in_array($model, $allowedModels, true)) {
                    $model = $provider['defaultModel'] ?? $model;
                }
                $modelId = $model;
                $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
            }
        }
        continue;
    }

    $finalContent = $assistantContent;
    break;
}

clearStatusFlags($status);
clearCurrentExecutionStatus($status, $requestId);
writeStatus($requestId, $status);

if ($initialUserContent !== '' && trim($finalContent) !== '') {
    append_chat_exchange($requestId, $initialUserContent, $finalContent);
}

$response = [
    'choices' => [
        [
            'message' => [
                'role' => 'assistant',
                'content' => $finalContent,
            ],
        ],
    ],
    'request_id' => $requestId,
];
if (!empty($jobsToRun)) {
    $response['jobToRun'] = $jobsToRun;
}
if (!empty($status['graphRefreshNeeded'])) {
    $response['graphRefreshNeeded'] = true;
}
echo json_encode($response);
