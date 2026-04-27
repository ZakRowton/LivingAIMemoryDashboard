<?php
/**
 * Multi-provider chat proxy with iterative tool execution.
 * Flow: model -> tool call -> tool result -> model ... until a final answer is returned.
 */
$memoryGraphChatLibraryOnly = defined('MEMORYGRAPH_CHAT_LIBRARY_ONLY') && MEMORYGRAPH_CHAT_LIBRARY_ONLY;
if (!$memoryGraphChatLibraryOnly) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'provider_config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'chat_history_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'memory_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'instruction_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'research_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'rules_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'job_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mcp_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mcp_client.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tool_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sub_agent_store.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron_pending.php';

if (!$memoryGraphChatLibraryOnly) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
}

// Long-running jobs (e.g. AI news) can exceed default 120s; allow up to 10 minutes
if (function_exists('set_time_limit')) {
    @set_time_limit(600);
}

const MEMORY_GRAPH_DASHSCOPE_CN = 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions';
const MEMORY_GRAPH_DASHSCOPE_INTL = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions';

/**
 * Append /chat/completions when env uses OpenAI-SDK-style base only (e.g. .../compatible-mode/v1).
 */
function memory_graph_dashscope_chat_completions_url(string $baseOrFull): string {
    $u = rtrim(trim($baseOrFull), '/');
    if ($u === '') {
        return $u;
    }
    if (preg_match('#/chat/completions$#', $u) === 1) {
        return $u;
    }
    return $u . '/chat/completions';
}

/**
 * DashScope OpenAI-compatible URL.
 * Default matches Alibaba Model Studio intl sample: https://dashscope-intl.aliyuncs.com/compatible-mode/v1
 * China (Beijing): set ALIBABA_DASHSCOPE_REGION=cn — keys differ by region per Alibaba docs.
 * Override full URL: DASHSCOPE_COMPATIBLE_ENDPOINT or ALIBABA_COMPATIBLE_ENDPOINT (may be base .../v1 or full .../chat/completions).
 */
function memory_graph_alibaba_dashscope_endpoint(): string {
    $custom = trim((string) memory_graph_env('DASHSCOPE_COMPATIBLE_ENDPOINT', ''));
    if ($custom !== '') {
        return memory_graph_dashscope_chat_completions_url($custom);
    }
    $custom = trim((string) memory_graph_env('ALIBABA_COMPATIBLE_ENDPOINT', ''));
    if ($custom !== '') {
        return memory_graph_dashscope_chat_completions_url($custom);
    }
    $region = strtolower(trim((string) memory_graph_env('ALIBABA_DASHSCOPE_REGION', 'intl')));
    if ($region === 'cn' || $region === 'china' || $region === 'beijing' || $region === 'cn-shanghai') {
        return MEMORY_GRAPH_DASHSCOPE_CN;
    }
    return MEMORY_GRAPH_DASHSCOPE_INTL;
}

/** Normalize DashScope / Alibaba API key from .env (BOM, quotes, accidental Bearer prefix). */
function memory_graph_alibaba_sanitize_api_key(string $k): string {
    $k = preg_replace('/^\xEF\xBB\xBF/', '', $k);
    $k = trim($k, " \t\n\r\0\x0B\"'");
    if ($k !== '' && stripos($k, 'bearer ') === 0) {
        $k = trim(substr($k, 7));
    }
    return $k;
}

/**
 * Resolve API key from env. DashScope console often documents DASHSCOPE_API_KEY; older docs use ALIBABA_API_KEY.
 */
function memory_graph_alibaba_api_key(): string {
    if (function_exists('get_provider_api_key_override')) {
        $ov = get_provider_api_key_override('alibaba');
        if ($ov !== '') {
            return memory_graph_alibaba_sanitize_api_key($ov);
        }
    }
    foreach (['DASHSCOPE_API_KEY', 'ALIBABA_API_KEY', 'ALIBABA_CLOUD_API_KEY', 'ALIYUN_API_KEY'] as $envKey) {
        $v = memory_graph_env($envKey, '');
        if ($v === null || $v === '') {
            continue;
        }
        $v = memory_graph_alibaba_sanitize_api_key((string) $v);
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

function memory_graph_alibaba_toggle_endpoint(string $current): string {
    return (strpos($current, 'dashscope-intl') !== false) ? MEMORY_GRAPH_DASHSCOPE_CN : MEMORY_GRAPH_DASHSCOPE_INTL;
}

/** Re-read DashScope key + URL each request (avoids stale $providers snapshot; matches Python/Java SDK env usage). */
function memory_graph_alibaba_provider_runtime(array $base): array {
    $base['apiKey'] = memory_graph_alibaba_api_key();
    $base['endpoint'] = memory_graph_alibaba_dashscope_endpoint();
    return $base;
}

/** Alibaba often returns "Incorrect API key" when the key is valid for the other region (cn vs Singapore). */
function memory_graph_alibaba_should_retry_alternate_region(int $httpCode, string $rawBody): bool {
    if ($httpCode === 401 || $httpCode === 403) {
        return true;
    }
    if ($httpCode < 400) {
        return false;
    }
    $l = strtolower($rawBody);
    if (strpos($l, 'incorrect') !== false && strpos($l, 'api') !== false && strpos($l, 'key') !== false) {
        return true;
    }
    if (strpos($l, 'invalidapikey') !== false || strpos($l, 'invalid_api_key') !== false) {
        return true;
    }
    if (strpos($l, 'accesskeyid') !== false && (strpos($l, 'invalid') !== false || strpos($l, 'notfound') !== false)) {
        return true;
    }
    return false;
}

/**
 * Single POST to OpenAI-compatible endpoint; returns same shape as requestOpenAiCompatible.
 */
function memory_graph_openai_compatible_post(string $endpoint, string $apiKey, array $payload, string $providerKeyForHeaders = ''): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['error' => 'Failed to encode request JSON', 'httpCode' => 500];
    }
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    $epLower = strtolower($endpoint);
    if ($providerKeyForHeaders === 'openrouter' || strpos($epLower, 'openrouter.ai') !== false) {
        $referer = function_exists('memory_graph_env')
            ? trim((string) memory_graph_env('OPENROUTER_HTTP_REFERER', ''))
            : '';
        if ($referer === '' && function_exists('memory_graph_env')) {
            $referer = trim((string) memory_graph_env('MEMORYGRAPH_PUBLIC_BASE_URL', ''));
        }
        if ($referer === '') {
            $referer = 'https://localhost';
        }
        $headers[] = 'HTTP-Referer: ' . $referer;
        $headers[] = 'X-Title: MemoryGraph';
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'Gateway error: ' . $err, 'httpCode' => 502];
    }
    if ($httpCode >= 400) {
        return ['error' => $response ?: 'Provider request failed', 'httpCode' => $httpCode, 'raw' => (string) $response];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid provider response', 'httpCode' => 502];
    }
    return ['data' => $decoded, 'httpCode' => $httpCode];
}

function memory_graph_openrouter_chat_endpoint(): string {
    $base = trim((string) memory_graph_env('OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1'));
    if ($base === '') $base = 'https://openrouter.ai/api/v1';
    $base = rtrim($base, '/');
    if (preg_match('#/chat/completions$#', $base) === 1) {
        return $base;
    }
    return $base . '/chat/completions';
}

function memory_graph_nvidia_nim_chat_endpoint(): string {
    $base = trim((string) memory_graph_env('NVIDIA_NIM_ENDPOINT', 'https://integrate.api.nvidia.com/v1'));
    if ($base === '') $base = 'https://integrate.api.nvidia.com/v1';
    $base = rtrim($base, '/');
    if (preg_match('#/chat/completions$#', $base) === 1) {
        return $base;
    }
    return $base . '/chat/completions';
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
        'endpoint' => memory_graph_alibaba_dashscope_endpoint(),
        'apiKey' => memory_graph_alibaba_api_key(),
        'type' => 'openai',
        'defaultModel' => 'qwen-plus',
    ],
    'gemini' => [
        'endpointBase' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'apiKey' => memory_graph_env('GEMINI_API_KEY', ''),
        'type' => 'gemini',
        'defaultModel' => 'gemini-2.5-flash',
    ],
    'openrouter' => [
        'endpoint' => memory_graph_openrouter_chat_endpoint(),
        'apiKey' => memory_graph_env('OPENROUTER_API_KEY', ''),
        'type' => 'openai',
        'defaultModel' => trim((string) memory_graph_env('OPENROUTER_MODEL', 'google/gemma-4-31b-it:free')),
    ],
    'nvidia_nim' => [
        'endpoint' => memory_graph_nvidia_nim_chat_endpoint(),
        'apiKey' => memory_graph_env('NVIDIA_NIM_API_KEY', ''),
        'type' => 'openai',
        'defaultModel' => trim((string) memory_graph_env('NVIDIA_NIM_MODEL', 'deepseek-ai/deepseek-v4-flash')),
    ],
];
$customProviders = get_custom_provider_definitions_for_chat();
foreach ($customProviders as $key => $def) {
    $providers[$key] = $def;
}
foreach ($providers as $_pk => &$_pdef) {
    if (!is_array($_pdef)) {
        continue;
    }
    $ov = get_provider_api_key_override($_pk);
    if ($ov === '') {
        continue;
    }
    if ($_pk === 'alibaba') {
        $_pdef['apiKey'] = memory_graph_alibaba_sanitize_api_key($ov);
    } else {
        $_pdef['apiKey'] = $ov;
    }
}
unset($_pdef);

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

/**
 * While a nested sub-agent chat runs, merge its live execution flags into the parent Jarvis request status
 * so the main graph pulses tools / memory / etc. in sync (same as prompting Jarvis).
 */
function memory_graph_propagate_child_status_to_parent(string $childRequestId, array $childStatus): void {
    $parentId = trim((string) ($GLOBALS['MEMORYGRAPH_PARENT_STATUS_REQUEST_ID'] ?? ''));
    if ($parentId === '' || $parentId === $childRequestId) {
        return;
    }
    if (strpos($parentId, 'subagent_worker_') === 0) {
        return;
    }
    $subNode = trim((string) ($GLOBALS['MEMORYGRAPH_PARENT_SUB_AGENT_NODE_ID'] ?? ''));
    $parentPath = statusDirPath() . DIRECTORY_SEPARATOR . $parentId . '.json';
    $parentRaw = @file_get_contents($parentPath);
    $parent = ($parentRaw !== false && $parentRaw !== '') ? json_decode($parentRaw, true) : [];
    if (!is_array($parent)) {
        $parent = [];
    }
    $union = static function ($a, $b): array {
        $a = is_array($a) ? $a : [];
        $b = is_array($b) ? $b : [];

        return array_values(array_unique(array_merge($a, $b)));
    };
    $parent['activeToolIds'] = $union($parent['activeToolIds'] ?? [], $childStatus['activeToolIds'] ?? []);
    $parent['activeMemoryIds'] = $union($parent['activeMemoryIds'] ?? [], $childStatus['activeMemoryIds'] ?? []);
    $parent['activeInstructionIds'] = $union($parent['activeInstructionIds'] ?? [], $childStatus['activeInstructionIds'] ?? []);
    $parent['activeResearchIds'] = $union($parent['activeResearchIds'] ?? [], $childStatus['activeResearchIds'] ?? []);
    $parent['activeRulesIds'] = $union($parent['activeRulesIds'] ?? [], $childStatus['activeRulesIds'] ?? []);
    $parent['activeMcpIds'] = $union($parent['activeMcpIds'] ?? [], $childStatus['activeMcpIds'] ?? []);
    $parent['activeJobIds'] = $union($parent['activeJobIds'] ?? [], $childStatus['activeJobIds'] ?? []);
    $parent['activeSubAgentIds'] = $union($parent['activeSubAgentIds'] ?? [], $childStatus['activeSubAgentIds'] ?? []);
    if ($subNode !== '') {
        $parent['activeSubAgentIds'] = $union($parent['activeSubAgentIds'] ?? [], [$subNode]);
    }
    $execFlags = [
        'gettingAvailTools' => 'lastGettingAvailTools',
        'checkingMemory' => 'lastCheckingMemory',
        'checkingInstructions' => 'lastCheckingInstructions',
        'checkingResearch' => 'lastCheckingResearch',
        'checkingRules' => 'lastCheckingRules',
        'checkingMcps' => 'lastCheckingMcps',
        'checkingJobs' => 'lastCheckingJobs',
    ];
    foreach ($execFlags as $flag => $lastKey) {
        if (!empty($childStatus[$flag])) {
            $parent[$flag] = true;
            $parent[$lastKey] = true;
        }
    }
    if (!empty($childStatus['thinking'])) {
        $parent['thinking'] = true;
    }
    $ped = isset($parent['executionDetailsByNode']) && is_array($parent['executionDetailsByNode']) ? $parent['executionDetailsByNode'] : [];
    $ced = isset($childStatus['executionDetailsByNode']) && is_array($childStatus['executionDetailsByNode']) ? $childStatus['executionDetailsByNode'] : [];
    foreach ($ced as $k => $v) {
        $ped[$k] = $v;
    }
    $parent['executionDetailsByNode'] = $ped;
    $parent['lastExecutionDetailsByNode'] = $ped;
    $nowMs = (int) round(microtime(true) * 1000) + 5500;
    $parent['lastEventExpiresAtMs'] = max((int) ($parent['lastEventExpiresAtMs'] ?? 0), (int) ($childStatus['lastEventExpiresAtMs'] ?? 0), $nowMs);
    if (!isset($parent['requestId'])) {
        $parent['requestId'] = $parentId;
    }
    @file_put_contents($parentPath, json_encode($parent, JSON_PRETTY_PRINT));
}

function writeStatus(string $requestId, array $status): void {
    $path = statusDirPath() . DIRECTORY_SEPARATOR . $requestId . '.json';
    file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT));
    memory_graph_propagate_child_status_to_parent($requestId, $status);
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
    $status['activeSubAgentIds'] = [];
    $status['executionDetailsByNode'] = [];
}

function markExecutionStatus(array &$status, string $requestId, bool $gettingAvailTools, bool $checkingMemory, bool $checkingInstructions, bool $checkingResearch, bool $checkingRules, bool $checkingMcps, bool $checkingJobs, array $activeToolIds, array $activeMemoryIds, array $activeInstructionIds, array $activeResearchIds, array $activeRulesIds, array $activeMcpIds, array $activeJobIds, array $activeSubAgentIds, array $executionDetailsByNode): void {
    $status['gettingAvailTools'] = $gettingAvailTools;
    $status['checkingMemory'] = $checkingMemory;
    $status['checkingInstructions'] = $checkingInstructions;
    $status['checkingResearch'] = $checkingResearch;
    $status['checkingRules'] = $checkingRules;
    $status['checkingMcps'] = $checkingMcps;
    $status['activeToolIds'] = array_values($activeToolIds);
    $status['activeMemoryIds'] = array_values($activeMemoryIds);
    $status['activeInstructionIds'] = array_values($activeInstructionIds);
    $status['activeResearchIds'] = array_values($activeResearchIds);
    $status['activeRulesIds'] = array_values($activeRulesIds);
    $status['activeMcpIds'] = array_values($activeMcpIds);
    $cronNid = isset($GLOBALS['MEMORY_GRAPH_CRON_NODE_ID']) ? trim((string) $GLOBALS['MEMORY_GRAPH_CRON_NODE_ID']) : '';
    if ($cronNid !== '') {
        if (!in_array($cronNid, $activeJobIds, true)) {
            $activeJobIds[] = $cronNid;
        }
        $checkingJobs = $checkingJobs || true;
    }
    $status['checkingJobs'] = $checkingJobs;
    $status['activeJobIds'] = array_values($activeJobIds);
    $status['activeSubAgentIds'] = array_values($activeSubAgentIds);
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
    $status['lastActiveSubAgentIds'] = array_values($activeSubAgentIds);
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
    $status['activeSubAgentIds'] = [];
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
        'create_sub_agent_file',
        'update_sub_agent_file',
        'delete_sub_agent_file',
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
        'create_web_app',
        'update_web_app',
        'delete_web_app',
        'agent_cron_add',
        'agent_cron_remove',
        'agent_cron_set_enabled',
        'cron_job_manager',
        'create_mcp_server',
        'update_mcp_server',
        'configure_mcp_server',
        'set_mcp_server_env_var',
        'remove_mcp_server_env_var',
        'set_mcp_server_header',
        'remove_mcp_server_header',
        'set_mcp_server_active',
        'delete_mcp_server',
        'create_sub_agent_file',
        'update_sub_agent_file',
        'delete_sub_agent_file',
    ], true);
}

function memory_graph_normalize_provider_slug(string $raw): string {
    $k = strtolower(trim($raw));
    $k = str_replace(['-', ' '], '_', $k);
    $k = preg_replace('/[^a-z0-9_]/', '', $k);
    $k = preg_replace('/_+/', '_', $k);
    return trim((string) $k, '_');
}

/** Map sub-agent config provider string to a key in $providers (built-in + custom). */
function memory_graph_resolve_sub_agent_provider_key(string $raw, array $providers): string {
    $k = memory_graph_normalize_provider_slug($raw);
    if ($k !== '' && isset($providers[$k])) {
        return $k;
    }
    if ($k === 'open_router') {
        $k = 'openrouter';
    }
    if ($k === 'nvidianim') {
        $k = 'nvidia_nim';
    }
    if ($k === 'nvidia' && isset($providers['nvidia_nim'])) {
        $k = 'nvidia_nim';
    }
    // Docs often say "OpenAI" / gpt-*; MemoryGraph keys are openrouter, mercury, etc.
    if ($k === 'openai') {
        if (isset($providers['openrouter'])) {
            return 'openrouter';
        }
        if (isset($providers['mercury'])) {
            return 'mercury';
        }
    }
    if ($k !== '' && isset($providers[$k])) {
        return $k;
    }
    return $k;
}

function memory_graph_sub_agent_public_base_url(): string {
    $u = trim((string) memory_graph_env('MEMORYGRAPH_PUBLIC_BASE_URL', ''));
    return rtrim($u, '/');
}

function memory_graph_sub_agent_worker_secret(): string {
    return trim((string) memory_graph_env('MEMORYGRAPH_SUB_AGENT_ASYNC_SECRET', ''));
}

/**
 * Fire-and-forget POST to api/chat.php worker. Short client timeout: the worker may run for minutes.
 *
 * @return bool True if the request was sent (including client-side timeout while the server keeps running).
 */
function memory_graph_fire_sub_agent_worker_http(string $taskId): bool {
    $base = memory_graph_sub_agent_public_base_url();
    $secret = memory_graph_sub_agent_worker_secret();
    if ($base === '' || $secret === '') {
        return false;
    }
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $taskId);
    if ($safe === '') {
        return false;
    }
    $url = $base . '/api/chat.php';
    $body = json_encode([
        '__memoryGraphSubAgentWorker' => true,
        '__workerSecret' => $secret,
        'taskId' => $safe,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return false;
    }
    if (!function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_NOSIGNAL => 1,
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    // 0 = response (possibly full); 28 = operation timed out — server may still be executing the worker.
    return $errno === 0 || $errno === 28;
}

function memory_graph_sub_agent_spawn_cli_worker(string $taskId): bool {
    $php = trim((string) memory_graph_env('MEMORYGRAPH_PHP_CLI', ''));
    if ($php === '' || !is_file($php)) {
        return false;
    }
    $secret = memory_graph_sub_agent_worker_secret();
    if ($secret === '') {
        return false;
    }
    $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sub_agent_async_worker.php';
    if (!is_file($worker)) {
        return false;
    }
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $taskId);
    if ($safe === '') {
        return false;
    }
    $phpQ = escapeshellarg($php);
    $workerQ = escapeshellarg($worker);
    $tidQ = escapeshellarg($safe);
    $secQ = escapeshellarg($secret);
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start /B "" ' . $phpQ . ' ' . $workerQ . ' ' . $tidQ . ' ' . $secQ;
        pclose(popen($cmd, 'r'));
    } else {
        exec($phpQ . ' ' . $workerQ . ' ' . $tidQ . ' ' . $secQ . ' > /dev/null 2>&1 &');
    }

    return true;
}

/**
 * Start a background worker once per task (CLI preferred, then HTTP trigger).
 *
 * @return array{mode:string,note:string}
 */
function memory_graph_sub_agent_try_spawn_async_worker(string $taskId): array {
    $secret = memory_graph_sub_agent_worker_secret();
    if ($secret === '') {
        return [
            'mode' => 'none',
            'note' => 'Set MEMORYGRAPH_SUB_AGENT_ASYNC_SECRET to enable background sub-agent runs. On single-thread PHP hosts, set MEMORYGRAPH_PHP_CLI to a real php.exe so a detached process can POST the worker.',
        ];
    }
    $task = get_sub_agent_task($taskId);
    if (!is_array($task) || (($task['status'] ?? '') !== 'queued')) {
        return ['mode' => 'skip', 'note' => ''];
    }
    if (!empty($task['asyncSpawned'])) {
        return ['mode' => 'already', 'note' => ''];
    }
    $mode = 'none';
    if (memory_graph_sub_agent_spawn_cli_worker($taskId)) {
        $mode = 'cli';
    } elseif (memory_graph_fire_sub_agent_worker_http($taskId)) {
        $mode = 'http';
    }
    if ($mode !== 'none') {
        update_sub_agent_task($taskId, ['asyncSpawned' => true]);
    }

    return [
        'mode' => $mode,
        'note' => $mode === 'none'
            ? 'Could not spawn worker: set MEMORYGRAPH_PHP_CLI (recommended on Windows) and/or MEMORYGRAPH_PUBLIC_BASE_URL for HTTP trigger.'
            : '',
    ];
}

/** Resolve sub-agent slug from tool arguments (models vary key names). */
function memory_graph_sub_agent_slug_from_tool_arguments(array $arguments): string {
    foreach (['name', 'sub_agent', 'sub_agent_name', 'subAgent', 'agent', 'slug', 'file'] as $k) {
        if (!empty($arguments[$k]) && is_string($arguments[$k])) {
            $t = trim($arguments[$k]);
            if ($t !== '') {
                return $t;
            }
        }
    }

    return '';
}

/**
 * Re-read/write chat status so the UI can pulse Sub-Agents during long run_sub_agent_chat (e.g. nested HTTP),
 * and to survive single-worker environments where status was not merged yet.
 */
function memory_graph_reinforce_sub_agent_status_pulse(string $subAgentSlugOrFilename, array $arguments): void {
    $rid = trim((string) ($GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID'] ?? ''));
    if ($rid === '') {
        return;
    }
    $fn = normalize_sub_agent_filename($subAgentSlugOrFilename);
    if ($fn === '') {
        return;
    }
    $nid = sub_agent_node_id($fn);
    $path = statusDirPath() . DIRECTORY_SEPARATOR . $rid . '.json';
    $raw = @file_get_contents($path);
    $status = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
    if (!is_array($status)) {
        $status = [];
    }
    $existing = isset($status['activeSubAgentIds']) && is_array($status['activeSubAgentIds']) ? $status['activeSubAgentIds'] : [];
    if (!in_array($nid, $existing, true)) {
        $existing[] = $nid;
    }
    $status['activeSubAgentIds'] = array_values($existing);
    $status['lastActiveSubAgentIds'] = $status['activeSubAgentIds'];
    $ed = isset($status['executionDetailsByNode']) && is_array($status['executionDetailsByNode']) ? $status['executionDetailsByNode'] : [];
    $ed['sub_agents'] = ['toolName' => 'run_sub_agent_chat', 'arguments' => $arguments];
    $ed[$nid] = ['toolName' => 'run_sub_agent_chat', 'arguments' => $arguments];
    $status['executionDetailsByNode'] = $ed;
    $status['lastExecutionDetailsByNode'] = $ed;
    $holdMs = 120000;
    $rawHold = $_ENV['MEMORYGRAPH_SUB_AGENT_STATUS_MS'] ?? getenv('MEMORYGRAPH_SUB_AGENT_STATUS_MS');
    if ($rawHold !== false && $rawHold !== null && (string) $rawHold !== '') {
        $holdMs = max(8000, (int) $rawHold);
    }
    $status['lastEventExpiresAtMs'] = (int) round(microtime(true) * 1000) + $holdMs;
    $status['thinking'] = true;
    writeStatus($rid, $status);
}

/**
 * Run a sub-agent through the same api/chat.php pipeline (tools, merged memory/rules, MCP, etc.).
 * Requires MEMORYGRAPH_PUBLIC_BASE_URL so the server can POST to itself (needed for multi-step tool loops).
 */
function memory_graph_execute_sub_agent_completion(array $providers, array $arguments): array {
    $name = (string) ($arguments['name'] ?? $arguments['subAgent'] ?? '');
    if ($name === '') {
        return ['error' => 'name is required'];
    }
    $meta = get_sub_agent_meta($name);
    if ($meta === null) {
        return ['error' => 'Sub-agent not found'];
    }
    $cfg = is_array($meta['config'] ?? null) ? $meta['config'] : [];
    $providerKeyRaw = trim((string) ($cfg['provider'] ?? ''));
    $providerKey = memory_graph_resolve_sub_agent_provider_key($providerKeyRaw, $providers);
    $model = trim((string) ($cfg['model'] ?? ''));
    $systemPrompt = (string) ($cfg['system_prompt'] ?? '');
    $temperature = isset($cfg['temperature']) && is_numeric($cfg['temperature']) ? (float) $cfg['temperature'] : 0.7;
    $chatType = strtolower(trim((string) ($cfg['chat_type'] ?? '')));
    $endpoint = (string) ($cfg['endpoint'] ?? '');
    $apiKey = (string) ($cfg['api_key'] ?? '');
    $messages = isset($arguments['messages']) && is_array($arguments['messages']) ? $arguments['messages'] : [];
    $prompt = (string) ($arguments['prompt'] ?? '');
    if ($prompt !== '') {
        $messages[] = ['role' => 'user', 'content' => $prompt];
    }
    if ($messages === []) {
        return ['error' => 'prompt or messages is required'];
    }

    $provider = isset($providers[$providerKey]) && is_array($providers[$providerKey]) ? $providers[$providerKey] : null;
    if ($provider === null && $endpoint !== '') {
        $provider = [
            'endpoint' => $endpoint,
            'apiKey' => $apiKey,
            'type' => ($chatType === 'gemini') ? 'gemini' : 'openai',
            'defaultModel' => $model !== '' ? $model : 'default',
        ];
    }
    if ($provider === null) {
        return [
            'error' => 'Invalid sub-agent provider: "' . $providerKeyRaw . '" (resolved: "' . $providerKey . '"). Use a built-in key like openrouter, nvidia_nim, gemini, mercury, featherless, alibaba, or set endpoint + api_key in the sub-agent file.',
        ];
    }
    if ($endpoint !== '' && ($provider['type'] ?? 'openai') === 'openai') {
        $provider['endpoint'] = $endpoint;
    }
    if ($endpoint !== '' && ($provider['type'] ?? 'openai') === 'gemini') {
        $provider['endpointBase'] = $endpoint;
    }
    if ($apiKey !== '') {
        $provider['apiKey'] = $apiKey;
    }
    if ($model === '') {
        $model = (string) ($provider['defaultModel'] ?? '');
    }
    if ($model === '') {
        return ['error' => 'Sub-agent model is required'];
    }
    if ($providerKey === 'alibaba') {
        $provider = memory_graph_alibaba_provider_runtime($provider);
    }
    $providerType = (string) ($provider['type'] ?? 'openai');

    $base = memory_graph_sub_agent_public_base_url();
    $registeredKey = isset($providers[$providerKey]) && is_array($providers[$providerKey]);
    if ($base !== '' && $registeredKey) {
        $depth = (int) ($GLOBALS['MEMORYGRAPH_SUB_AGENT_DEPTH'] ?? 0);
        if ($depth >= 8) {
            return ['error' => 'Sub-agent nesting depth exceeded (max 8).'];
        }
        $GLOBALS['MEMORYGRAPH_SUB_AGENT_DEPTH'] = $depth + 1;
        try {
            $url = $base . '/api/chat.php';
            $parentRid = isset($GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID']) && is_string($GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID'])
                ? trim($GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID'])
                : '';
            $childId = $parentRid !== ''
                ? ($parentRid . '_sub_' . bin2hex(random_bytes(4)))
                : ('subchat_' . bin2hex(random_bytes(8)));
            $rt = [];
            if ($endpoint !== '') {
                if (($provider['type'] ?? 'openai') === 'gemini') {
                    $rt['endpointBase'] = $endpoint;
                } else {
                    $rt['endpoint'] = $endpoint;
                }
            }
            if ($apiKey !== '') {
                $rt['apiKey'] = $apiKey;
            }
            if ($chatType !== '') {
                $rt['type'] = $chatType === 'gemini' ? 'gemini' : 'openai';
            }
            $displayTitle = pathinfo((string) ($meta['name'] ?? $name), PATHINFO_FILENAME);
            $identity = 'You are a MemoryGraph Sub-Agent (“' . $displayTitle . '”, config file: ' . ($meta['name'] ?? $name) . '). '
                . 'You run on the same host as Jarvis with the same capabilities: PHP tools, merged memory & rules, instructions, research files, MCP servers, jobs/cron, and dashboard web apps. '
                . 'Use tools proactively when they help; you may call run_sub_agent_chat / wait_for_sub_agent_chat to coordinate with other sub-agents unless nesting limits apply.';
            $payload = [
                'requestId' => $childId,
                'chatSessionId' => (string) ($arguments['chatSessionId'] ?? ($GLOBALS['MEMORY_GRAPH_CHAT_SESSION_ID'] ?? '')),
                'provider' => $providerKey,
                'model' => $model,
                'systemPrompt' => $systemPrompt,
                'temperature' => $temperature,
                'messages' => $messages,
                'skipCronPendingDelivery' => true,
                'skipChatHistoryAppend' => true,
                'subAgentIdentity' => $identity,
            ];
            if ($parentRid !== '' && strpos($parentRid, 'subagent_worker_') !== 0) {
                $payload['parentStatusRequestId'] = $parentRid;
                $subNodeId = trim((string) ($meta['nodeId'] ?? ''));
                if ($subNodeId !== '') {
                    $payload['parentSubAgentNodeId'] = $subNodeId;
                }
            }
            if ($rt !== []) {
                $payload['subAgentProviderRuntime'] = $rt;
            }
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                return ['error' => 'Failed to encode sub-agent chat payload'];
            }
            memory_graph_reinforce_sub_agent_status_pulse($name, $arguments);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 580,
            ]);
            $raw = curl_exec($ch);
            $cerr = curl_error($ch);
            $chc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false || $raw === '') {
                return [
                    'error' => 'Sub-agent internal HTTP failed (check MEMORYGRAPH_PUBLIC_BASE_URL reaches this app from PHP): ' . ($cerr !== '' ? $cerr : 'empty response'),
                    'httpCode' => $chc,
                ];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['error' => 'Sub-agent response was not JSON', 'httpCode' => $chc, 'preview' => substr($raw, 0, 400)];
            }
            if (!empty($decoded['error'])) {
                $em = $decoded['error'];
                if (!is_string($em)) {
                    $em = json_encode($em);
                }

                return ['error' => 'Sub-agent chat error: ' . $em, 'httpCode' => $chc];
            }
            $msg = $decoded['choices'][0]['message'] ?? null;
            $assistant = openai_extract_assistant_message_text(is_array($msg) ? $msg : null);
            $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : null;

            return [
                'ok' => true,
                'subAgent' => $meta['name'] ?? $name,
                'provider' => $providerKey,
                'provider_config' => $providerKeyRaw,
                'model' => $model,
                'response' => $assistant,
                'usage' => $usage,
                'via' => 'internal_full_chat',
            ];
        } finally {
            $GLOBALS['MEMORYGRAPH_SUB_AGENT_DEPTH'] = $depth;
        }
    }

    // Fallback: one-shot completion (no tool loop) when base URL is missing or provider is not a registered server key.
    $memoryRulesBlock = buildMemoryAndRulesSystemPromptSection();
    $activeTools = loadToolRegistry();
    $toolInstr = buildToolUsageInstruction($activeTools);
    $mergedSystem = trim(
        $systemPrompt . "\n\n"
        . ($memoryRulesBlock !== '' ? $memoryRulesBlock . "\n\n" : '')
        . $toolInstr
        . "\n\n[Sub-agent simple mode: MEMORYGRAPH_PUBLIC_BASE_URL is not set or provider is endpoint-only — tool calls are not executed in this fallback.]"
    );
    $conversation = normalizeConversation($messages, $mergedSystem, $providerType);
    memory_graph_reinforce_sub_agent_status_pulse($name, $arguments);
    $result = $providerType === 'gemini'
        ? requestGemini($provider, $model, $conversation, $temperature, $mergedSystem)
        : requestOpenAiCompatible($provider, $model, $conversation, $temperature, [], $providerKey);
    if (isset($result['error'])) {
        return $result;
    }

    $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : $result;
    $assistant = '';
    if ($providerType === 'gemini') {
        $assistant = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    } else {
        $assistant = openai_extract_assistant_message_text($data['choices'][0]['message'] ?? null);
    }

    return [
        'ok' => true,
        'subAgent' => $meta['name'] ?? $name,
        'provider' => $providerKey,
        'provider_config' => $providerKeyRaw,
        'model' => $model,
        'response' => $assistant,
        'usage' => is_array($data) ? ($data['usage'] ?? null) : null,
        'via' => 'simple_one_shot',
        'note' => $base === '' ? 'Set MEMORYGRAPH_PUBLIC_BASE_URL so sub-agents can use the full tool/MCP/memory loop.' : 'Full tool loop requires a registered provider key in agent config (not endpoint-only).',
    ];
}

/**
 * Block until a sub-agent task finishes, polling when another worker holds execution.
 * Falls back to executing inline if the task stays queued (e.g. single-thread host with no async worker).
 */
function memory_graph_poll_sub_agent_task_until_done(string $taskId, int $timeoutSec, array $providers): array {
    $deadline = time() + max(30, min(900, $timeoutSec));
    $inlineAfterSec = 3;
    $createdEpoch = null;
    while (time() < $deadline) {
        $task = get_sub_agent_task($taskId);
        if ($task === null) {
            return ['error' => 'Task not found'];
        }
        $st = (string) ($task['status'] ?? '');
        if ($st === 'done' || $st === 'failed') {
            return $task;
        }
        if ($st === 'queued') {
            memory_graph_sub_agent_try_spawn_async_worker($taskId);
            $createdRaw = (string) ($task['createdAt'] ?? '');
            $createdEpoch = $createdEpoch ?? (strtotime($createdRaw) ?: time());
            if ((time() - $createdEpoch) >= $inlineAfterSec) {
                $payload = try_claim_sub_agent_task_for_execution($taskId);
                if ($payload !== null) {
                    $result = memory_graph_execute_sub_agent_completion($providers, $payload);
                    if (isset($result['error'])) {
                        return update_sub_agent_task($taskId, ['status' => 'failed', 'error' => (string) $result['error'], 'result' => null]);
                    }

                    return update_sub_agent_task($taskId, ['status' => 'done', 'result' => $result, 'error' => null]);
                }
            }
            usleep(400000);
            continue;
        }
        if ($st === 'running') {
            usleep(400000);
            continue;
        }
        usleep(400000);
    }
    $task = get_sub_agent_task($taskId);

    return ['error' => 'Sub-agent task timed out', 'task' => $task];
}

function clearMemoryGraphToolRegistryCache(): void {
    unset($GLOBALS['MEMORY_GRAPH_LOADED_TOOL_REGISTRY']);
}

/** After these tool calls, the OpenAI tool schema / MCP proxy list must be rebuilt (cannot reuse in-request cache). */
function shouldInvalidateToolRegistryCache(string $toolName, array $toolResult): bool {
    if (isset($toolResult['error'])) {
        return false;
    }
    if (!empty($toolResult['__mcp_registry_changed'])) {
        return true;
    }
    $n = normalizeToolName($toolName);
    return in_array($n, [
        'create_or_update_tool',
        'delete_tool',
        'edit_tool_file',
        'edit_tool_registry_entry',
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

function queueGraphRefresh(array &$status, string $requestId): void {
    $status['graphRefreshToken'] = uniqid('graph_', true);
    $status['graphRefreshNeeded'] = true;
    writeStatus($requestId, $status);
}

function memory_graph_env_truthy(string $key): bool {
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null) {
        return false;
    }
    $s = strtolower(trim((string) $v));
    return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
}

function memory_graph_mcp_servers_registry_mtime(): int {
    $p = mcp_registry_path();
    return is_file($p) ? (int) filemtime($p) : 0;
}

function memory_graph_mcp_snapshot_max_age_seconds(): int {
    $raw = $_ENV['MEMORYGRAPH_MCP_SNAPSHOT_MAX_AGE'] ?? getenv('MEMORYGRAPH_MCP_SNAPSHOT_MAX_AGE');
    if ($raw !== false && $raw !== null && (string) $raw !== '') {
        return max(0, (int) $raw);
    }
    return 86400;
}

function memory_graph_load_mcp_expanded_snapshot(): ?array {
    $p = mcp_expanded_tools_snapshot_path();
    if (!is_file($p)) {
        return null;
    }
    $raw = @file_get_contents($p);
    $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($data) || !array_key_exists('tools', $data) || !is_array($data['tools'])) {
        return null;
    }
    if ((int) ($data['registry_mtime'] ?? -1) !== memory_graph_mcp_servers_registry_mtime()) {
        return null;
    }
    $maxAge = memory_graph_mcp_snapshot_max_age_seconds();
    if ($maxAge > 0 && (time() - (int) ($data['saved_at'] ?? 0)) > $maxAge) {
        return null;
    }
    return $data['tools'];
}

function memory_graph_save_mcp_expanded_snapshot(array $mcpToolsMap): void {
    $payload = [
        'saved_at' => time(),
        'registry_mtime' => memory_graph_mcp_servers_registry_mtime(),
        'tools' => $mcpToolsMap,
    ];
    @file_put_contents(mcp_expanded_tools_snapshot_path(), json_encode($payload, JSON_UNESCAPED_SLASHES));
}

function memory_graph_should_skip_mcp_server_for_expand(array $server): bool {
    if (memory_graph_env_truthy('MEMORYGRAPH_CHAT_SKIP_STDIO_MCP')) {
        return mcp_effective_transport($server) === 'stdio';
    }
    return false;
}

function memory_graph_build_mcp_tool_map_for_registry(): array {
    $mcpMap = [];
    foreach (list_active_mcp_servers_meta() as $server) {
        if (memory_graph_should_skip_mcp_server_for_expand($server)) {
            continue;
        }
        $remoteTools = mcp_list_server_tools($server);
        if (!empty($remoteTools['error']) || empty($remoteTools['tools']) || !is_array($remoteTools['tools'])) {
            continue;
        }
        foreach ($remoteTools['tools'] as $remoteTool) {
            if (!is_array($remoteTool) || empty($remoteTool['name'])) {
                continue;
            }
            $exposedName = mcp_exposed_tool_name((string) ($server['name'] ?? ''), (string) $remoteTool['name']);
            $mcpMap[$exposedName] = [
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
    return $mcpMap;
}

function memory_rules_prompt_cache_path(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'prompt-memory-rules.cache.json';
}

function memory_rules_prompt_cache_signature(): string {
    $parts = [];
    $statePath = memory_dir_path() . DIRECTORY_SEPARATOR . '_memory_state.json';
    $parts[] = 'ms:' . (is_file($statePath) ? filemtime($statePath) : 0);
    $rulesDir = rules_dir_path();
    foreach (glob($rulesDir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $f) {
        $parts[] = 'r:' . strtolower(basename($f)) . ':' . filemtime($f) . ':' . filesize($f);
    }
    $state = load_memory_state();
    $memDir = memory_dir_path();
    foreach (glob($memDir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $f) {
        $bn = basename($f);
        $active = array_key_exists($bn, $state) ? !empty($state[$bn]['active']) : true;
        $hid = memory_meta_hidden($bn, $state);
        $parts[] = 'm:' . strtolower($bn) . ':' . ($active ? '1' : '0') . ':' . ($hid ? '1' : '0') . ':' . filemtime($f) . ':' . filesize($f);
    }
    sort($parts);
    return hash('sha256', implode("\0", $parts));
}

function loadToolRegistry(): array {
    if (isset($GLOBALS['MEMORY_GRAPH_LOADED_TOOL_REGISTRY']) && is_array($GLOBALS['MEMORY_GRAPH_LOADED_TOOL_REGISTRY'])) {
        return $GLOBALS['MEMORY_GRAPH_LOADED_TOOL_REGISTRY'];
    }
    $data = read_tool_registry_data();
    $tools = [];
    foreach (get_builtin_tools() as $tool) {
        $tools[$tool['name']] = $tool;
    }
    if (!is_array($data) || !isset($data['tools']) || !is_array($data['tools'])) {
        $GLOBALS['MEMORY_GRAPH_LOADED_TOOL_REGISTRY'] = $tools;
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
    if (!memory_graph_env_truthy('MEMORYGRAPH_CHAT_NO_MCP_EXPAND')) {
        $fromSnap = memory_graph_load_mcp_expanded_snapshot();
        if ($fromSnap !== null) {
            foreach ($fromSnap as $name => $entry) {
                if (!is_string($name) || $name === '' || !is_array($entry) || !isset($entry['name'])) {
                    continue;
                }
                if (isset($entry['parameters'])) {
                    $entry['parameters'] = normalize_tool_parameters($entry['parameters']);
                }
                $tools[$name] = $entry;
            }
        } else {
            $mcpMap = memory_graph_build_mcp_tool_map_for_registry();
            memory_graph_save_mcp_expanded_snapshot($mcpMap);
            foreach ($mcpMap as $name => $entry) {
                $tools[$name] = $entry;
            }
        }
    }
    $GLOBALS['MEMORY_GRAPH_LOADED_TOOL_REGISTRY'] = $tools;
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
    $activeSubAgentIds = [];
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
    $subAgentNameArg = '';
    if (in_array($normalizedFunctionName, ['run_sub_agent_chat', 'start_sub_agent_chat', 'create_sub_agent_file', 'update_sub_agent_file', 'delete_sub_agent_file', 'read_sub_agent_file'], true)) {
        $subAgentNameArg = memory_graph_sub_agent_slug_from_tool_arguments($arguments);
        if ($subAgentNameArg === '') {
            $subAgentNameArg = (string) ($arguments['name'] ?? '');
        }
    } elseif (in_array($normalizedFunctionName, ['wait_for_sub_agent_chat', 'get_sub_agent_chat_result'], true) && !empty($arguments['taskId'])) {
        $task = get_sub_agent_task((string) $arguments['taskId']);
        if (is_array($task) && is_array($task['payload'] ?? null) && isset($task['payload']['name'])) {
            $subAgentNameArg = (string) $task['payload']['name'];
        }
    }
    if ($subAgentNameArg !== '') {
        $fn = normalize_sub_agent_filename($subAgentNameArg);
        if ($fn !== '') {
            $nid = sub_agent_node_id($fn);
            $activeSubAgentIds[] = $nid;
            $executionDetails[$nid] = [
                'toolName' => $normalizedFunctionName,
                'arguments' => $arguments,
            ];
        }
    }
    $subAgentToolNames = ['list_sub_agent_files', 'read_sub_agent_file', 'create_sub_agent_file', 'update_sub_agent_file', 'delete_sub_agent_file', 'run_sub_agent_chat', 'start_sub_agent_chat', 'wait_for_sub_agent_chat', 'get_sub_agent_chat_result'];
    if (in_array($normalizedFunctionName, $subAgentToolNames, true)) {
        $executionDetails['sub_agents'] = [
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
        'activeSubAgentIds' => $activeSubAgentIds,
        'executionDetails' => $executionDetails,
    ];
}

/**
 * Shrink MCP tool schemas/descriptions for the provider when env limits are set (reduces tokens + latency).
 */
function memory_graph_truncate_json_schema_for_llm($node, int $maxDepth, int $depth = 0) {
    if ($maxDepth <= 0 || $depth >= $maxDepth) {
        return ['type' => 'object', 'properties' => new stdClass()];
    }
    if ($node === null) {
        return ['type' => 'object', 'properties' => new stdClass()];
    }
    if (is_object($node)) {
        $node = (array) $node;
    }
    if (!is_array($node)) {
        return $node;
    }
    unset($node['examples'], $node['example'], $node['default']);
    foreach (['properties', 'patternProperties', 'definitions', '$defs'] as $pk) {
        if (!isset($node[$pk]) || !is_array($node[$pk])) {
            continue;
        }
        foreach ($node[$pk] as $k => $sub) {
            $node[$pk][$k] = memory_graph_truncate_json_schema_for_llm($sub, $maxDepth, $depth + 1);
        }
    }
    if (isset($node['items'])) {
        $node['items'] = memory_graph_truncate_json_schema_for_llm($node['items'], $maxDepth, $depth + 1);
    }
    if (isset($node['additionalProperties']) && is_array($node['additionalProperties'])) {
        $node['additionalProperties'] = memory_graph_truncate_json_schema_for_llm($node['additionalProperties'], $maxDepth, $depth + 1);
    }
    foreach (['oneOf', 'anyOf', 'allOf'] as $comb) {
        if (!isset($node[$comb]) || !is_array($node[$comb])) {
            continue;
        }
        foreach ($node[$comb] as $i => $sub) {
            $node[$comb][$i] = memory_graph_truncate_json_schema_for_llm($sub, $maxDepth, $depth + 1);
        }
    }
    return $node;
}

function memory_graph_apply_openai_tool_trim_for_llm(array $tool): array {
    if (empty($tool['mcp'])) {
        return $tool;
    }
    $maxDesc = function_exists('memory_graph_env_int') ? memory_graph_env_int('MEMORYGRAPH_OPENAI_TOOL_DESC_MAX', 0) : 0;
    $maxDepth = function_exists('memory_graph_env_int') ? memory_graph_env_int('MEMORYGRAPH_OPENAI_TOOL_SCHEMA_MAX_DEPTH', 0) : 0;
    if ($maxDesc > 0 && isset($tool['description']) && is_string($tool['description']) && strlen($tool['description']) > $maxDesc) {
        $tool['description'] = substr($tool['description'], 0, max(0, $maxDesc - 3)) . '...';
    }
    if ($maxDepth > 0 && isset($tool['parameters'])) {
        $tool['parameters'] = memory_graph_truncate_json_schema_for_llm($tool['parameters'], $maxDepth, 0);
    }
    return $tool;
}

function buildOpenAiTools(array $tools): array {
    $out = [];
    foreach ($tools as $tool) {
        if (empty($tool['active'])) {
            continue;
        }
        $tool = memory_graph_apply_openai_tool_trim_for_llm($tool);
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

/** @param list<array{callId: string, functionName: string, arguments: array, normalizedName: string}> $prepared */
function chat_openai_tool_calls_all_mcp_parallel_eligible(array $prepared, array $activeTools): bool {
    if (count($prepared) < 2 || mcp_proxy_base_url() === null) {
        return false;
    }
    foreach ($prepared as $row) {
        $n = $row['normalizedName'];
        if (!isset($activeTools[$n]) || empty($activeTools[$n]['active']) || empty($activeTools[$n]['mcp'])) {
            return false;
        }
    }
    return true;
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
        'sub_agent_chat' => 'run_sub_agent_chat',
        'chat_sub_agent' => 'run_sub_agent_chat',
        'spawn_sub_agent' => 'run_sub_agent_chat',
    ];
    return $aliases[$normalized] ?? $aliases[$name] ?? $normalized;
}

function normalizeToolArguments(string $toolName, array $args): array {
    if ($toolName === 'get_temperature' && !isset($args['city']) && isset($args['location'])) {
        $args['city'] = $args['location'];
    }
    return $args;
}

/**
 * Some models emit pseudo-XML like <function><parameter name="name">...</parameter></function>
 * instead of native tools or JSON. Map a small subset to real builtins so the run still works.
 */
function memory_graph_parse_pseudo_xml_tool_call(string $trimmed): ?array {
    if (stripos($trimmed, '<function') === false) {
        return null;
    }
    if (!preg_match('/<function\b[^>]*>([\s\S]*?)<\/function>/i', $trimmed, $fm)) {
        return null;
    }
    $inner = $fm[1];
    $params = [];
    if (preg_match_all('/<parameter\s+name\s*=\s*["\']([^"\']+)["\']\s*>([\s\S]*?)<\/parameter>/i', $inner, $pm, PREG_SET_ORDER)) {
        foreach ($pm as $row) {
            $key = strtolower(trim($row[1]));
            $val = trim($row[2]);
            $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $params[$key] = $val;
        }
    }
    if ($params === []) {
        return null;
    }
    if (!empty($params['tool']) && is_string($params['tool'])) {
        $name = normalizeToolName(trim($params['tool']));
        unset($params['tool']);
        $args = $params;
        if (isset($args['arguments']) && is_string($args['arguments'])) {
            $decodedArgs = json_decode($args['arguments'], true);
            if (is_array($decodedArgs)) {
                $args = $decodedArgs;
            } else {
                unset($args['arguments']);
            }
        }
        return [
            'name' => $name,
            'arguments' => is_array($args) ? normalizeToolArguments($name, $args) : normalizeToolArguments($name, []),
        ];
    }
    $action = strtolower((string) ($params['action'] ?? ''));
    if ($action === 'chat' && !empty($params['name'])) {
        $slug = preg_replace('/\.md$/i', '', trim((string) $params['name']));
        $prompt = (string) ($params['message'] ?? $params['prompt'] ?? $params['content'] ?? '');
        if ($slug !== '' && $prompt !== '') {
            $tn = 'run_sub_agent_chat';
            return [
                'name' => $tn,
                'arguments' => normalizeToolArguments($tn, ['name' => $slug, 'prompt' => $prompt]),
            ];
        }
    }
    if (!empty($params['content']) || !empty($params['config'])) {
        $rawName = trim((string) ($params['name'] ?? ''));
        if ($rawName !== '') {
            $slug = preg_replace('/\.md$/i', '', $rawName);
            $args = ['name' => $slug];
            if (!empty($params['content'])) {
                $args['content'] = (string) $params['content'];
            }
            if (!empty($params['config'])) {
                $cfg = $params['config'];
                if (is_string($cfg)) {
                    $cfgDecoded = json_decode($cfg, true);
                    $args['config'] = is_array($cfgDecoded) ? $cfgDecoded : ['raw' => $cfg];
                }
            }
            $tn = 'create_sub_agent_file';
            return [
                'name' => $tn,
                'arguments' => normalizeToolArguments($tn, $args),
            ];
        }
    }
    return null;
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
                return memory_graph_parse_pseudo_xml_tool_call($trimmed);
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
                return memory_graph_parse_pseudo_xml_tool_call($trimmed);
            }
        }
    }
    $decoded = json_decode($jsonStr, true);
    if (!is_array($decoded)) {
        return memory_graph_parse_pseudo_xml_tool_call($trimmed);
    }
    $name = $decoded['tool'] ?? $decoded['name'] ?? null;
    $arguments = $decoded['arguments'] ?? $decoded['parameters'] ?? [];
    if (!is_string($name) || !is_array($arguments)) {
        return memory_graph_parse_pseudo_xml_tool_call($trimmed);
    }
    return [
        'name' => normalizeToolName($name),
        'arguments' => normalizeToolArguments(normalizeToolName($name), $arguments),
    ];
}

/** PHP fatal / parse messages often arrive HTML-wrapped; extract plain text for the model. */
function mg_extract_php_tool_error(string $raw): ?string {
    if ($raw === '') {
        return null;
    }
    $plain = preg_replace('/\s+/', ' ', strip_tags($raw));
    $plain = trim((string) $plain);
    if ($plain === '') {
        return null;
    }
    if (preg_match('/\bFatal error:\s*(.+)$/i', $plain, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\bParse error:\s*(.+)$/i', $plain, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/Cannot redeclare\s+\S+\s*\([^)]*\).*/i', $plain, $m)) {
        return trim($m[0]);
    }
    if (stripos($plain, 'fatal error') !== false) {
        return $plain;
    }
    return null;
}

/**
 * Valid PHP function name for a tool file (hyphens from filename become underscores).
 */
function mg_tool_php_callable_name(string $safeFileBase): string {
    return str_replace('-', '_', $safeFileBase);
}

function executePhpTool(string $toolName, array $arguments): array {
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $toolName);
    $toolPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . $safeName . '.php';
    if ($safeName === '' || !file_exists($toolPath)) {
        return ['error' => 'Tool file not found'];
    }
    $pathKey = realpath($toolPath);
    if (!is_string($pathKey) || $pathKey === '') {
        $pathKey = $toolPath;
    }
    $callableName = mg_tool_php_callable_name($safeName);

    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    try {
        $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] = $arguments;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        if (!isset($GLOBALS['__mg_tool_file_loaded'])) {
            $GLOBALS['__mg_tool_file_loaded'] = [];
        }
        $loadedMap = &$GLOBALS['__mg_tool_file_loaded'];

        ob_start();
        $firstLoad = empty($loadedMap[$pathKey]);
        if ($firstLoad) {
            require_once $toolPath;
            $loadedMap[$pathKey] = true;
        } elseif (!function_exists($callableName)) {
            // Procedural tools (echo json) must run on every invocation; functions were loaded on first require_once.
            include $toolPath;
        }
        $rawOutput = trim((string) ob_get_clean());

        // Function-only tools: first load defines the function but may produce no output.
        if (trim($rawOutput) === '' && function_exists($callableName)) {
            ob_start();
            try {
                $fnResult = call_user_func($callableName, $arguments);
            } catch (Throwable $toolFnEx) {
                ob_end_clean();
                throw $toolFnEx;
            }
            $extraOb = trim((string) ob_get_clean());
            if ($extraOb !== '') {
                $rawOutput = $extraOb;
            } elseif (is_array($fnResult)) {
                unset($GLOBALS['MEMORY_GRAPH_TOOL_INPUT']);
                if ($previousMethod === null) {
                    unset($_SERVER['REQUEST_METHOD']);
                } else {
                    $_SERVER['REQUEST_METHOD'] = $previousMethod;
                }
                return $fnResult;
            } elseif (is_string($fnResult) && $fnResult !== '') {
                $rawOutput = $fnResult;
            } elseif ($fnResult !== null) {
                $rawOutput = json_encode($fnResult, JSON_UNESCAPED_UNICODE) ?: '';
            }
        }

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
        $extracted = mg_extract_php_tool_error($rawOutput);
        if ($extracted !== null) {
            return [
                'error' => 'Tool PHP error: ' . $extracted,
                'raw_output' => $rawOutput,
                'file' => $safeName . '.php',
            ];
        }
        if (preg_match('/^(Fatal error|Parse error|Warning|Notice|Deprecated):\s*(.+)$/im', $rawOutput, $m)) {
            return [
                'error' => 'Tool output PHP error: ' . trim($m[2]),
                'raw_output' => $rawOutput,
                'file' => $safeName . '.php',
            ];
        }
        if (stripos($rawOutput, 'Fatal error') !== false || stripos($rawOutput, 'Parse error') !== false) {
            return [
                'error' => 'Tool produced PHP error. Use edit_tool_file to fix the code, then call the tool again.',
                'raw_output' => $rawOutput,
                'file' => $safeName . '.php',
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
    global $providers;
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
        $all = list_memory_files_meta(false);
        $includeHidden = !empty($arguments['include_hidden']);
        $memories = array_values(array_filter($all, function ($m) use ($includeHidden) {
            if (!empty($m['hidden'])) {
                return $includeHidden;
            }
            return !empty($m['active']);
        }));
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
        return ['rules' => array_values(list_rules_files_meta(false))];
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
    if ($toolName === 'list_web_apps') {
        return ['apps' => list_web_apps_meta()];
    }
    if ($toolName === 'read_web_app') {
        $r = get_web_app((string) ($arguments['name'] ?? ''));
        if (isset($r['error'])) {
            return $r;
        }
        $content = (string) ($r['content'] ?? '');
        $max = 200000;
        if (strlen($content) > $max) {
            $r['content'] = substr($content, 0, $max) . "\n\n…[truncated " . (strlen($content) - $max) . " chars]";
            $r['truncated'] = true;
        }
        return $r;
    }
    if ($toolName === 'create_web_app') {
        return create_web_app(
            (string) ($arguments['name'] ?? ''),
            (string) ($arguments['title'] ?? ''),
            (string) ($arguments['html'] ?? '')
        );
    }
    if ($toolName === 'update_web_app') {
        return update_web_app(
            (string) ($arguments['name'] ?? ''),
            array_key_exists('title', $arguments) ? (string) $arguments['title'] : null,
            array_key_exists('html', $arguments) ? (string) $arguments['html'] : null
        );
    }
    if ($toolName === 'delete_web_app') {
        return delete_web_app((string) ($arguments['name'] ?? ''));
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
        $sess = isset($arguments['session_id']) ? trim((string) $arguments['session_id']) : '';
        if ($sess === '' && !empty($arguments['current_session_only'])) {
            $sess = (string) ($GLOBALS['MEMORY_GRAPH_CHAT_SESSION_ID'] ?? '');
        }
        return list_chat_history($limit, $offset, $sess !== '' ? $sess : null);
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
    if ($toolName === 'list_sub_agent_files') {
        return ['subAgents' => list_sub_agent_files_meta(false)];
    }
    if ($toolName === 'read_sub_agent_file') {
        $name = (string) ($arguments['name'] ?? '');
        $meta = get_sub_agent_meta($name);
        if ($meta === null) return ['error' => 'Sub-agent not found'];
        return $meta;
    }
    if ($toolName === 'create_sub_agent_file') {
        $name = (string) ($arguments['name'] ?? '');
        $content = (string) ($arguments['content'] ?? '');
        if ($content === '' && isset($arguments['config']) && is_array($arguments['config'])) {
            $content = sub_agent_markdown_from_config($arguments['config']);
        }
        return create_sub_agent_file($name, $content);
    }
    if ($toolName === 'update_sub_agent_file') {
        $name = (string) ($arguments['name'] ?? '');
        $content = (string) ($arguments['content'] ?? '');
        if ($content === '' && isset($arguments['config']) && is_array($arguments['config'])) {
            $content = sub_agent_markdown_from_config($arguments['config']);
        }
        return update_sub_agent_file($name, $content);
    }
    if ($toolName === 'delete_sub_agent_file') {
        return delete_sub_agent_file_by_name((string) ($arguments['name'] ?? ''));
    }
    if ($toolName === 'run_sub_agent_chat') {
        return memory_graph_execute_sub_agent_completion($providers, $arguments);
    }
    if ($toolName === 'start_sub_agent_chat') {
        $taskId = 'subtask_' . time() . '_' . mt_rand(1000, 99999);
        $cs = isset($arguments['chatSessionId']) ? trim((string) $arguments['chatSessionId']) : '';
        $payload = [
            'name' => (string) ($arguments['name'] ?? ''),
            'prompt' => (string) ($arguments['prompt'] ?? ''),
            'messages' => isset($arguments['messages']) && is_array($arguments['messages']) ? $arguments['messages'] : [],
        ];
        if ($cs !== '') {
            $payload['chatSessionId'] = $cs;
        }
        create_sub_agent_task($taskId, $payload);
        $spawn = memory_graph_sub_agent_try_spawn_async_worker($taskId);
        $out = [
            'ok' => true,
            'taskId' => $taskId,
            'status' => 'queued',
            'asyncSpawn' => $spawn['mode'],
        ];
        if (($spawn['note'] ?? '') !== '') {
            $out['asyncHint'] = $spawn['note'];
        }

        return $out;
    }
    if ($toolName === 'get_sub_agent_chat_result') {
        $taskId = (string) ($arguments['taskId'] ?? '');
        if ($taskId === '') return ['error' => 'taskId is required'];
        $task = get_sub_agent_task($taskId);
        if ($task === null) return ['error' => 'Task not found'];
        if ((string) ($task['status'] ?? '') === 'queued') {
            memory_graph_sub_agent_try_spawn_async_worker($taskId);
            $task = get_sub_agent_task($taskId) ?? $task;
        }
        if ((string) ($task['status'] ?? '') === 'queued') {
            $createdAt = strtotime((string) ($task['createdAt'] ?? '')) ?: 0;
            if ($createdAt > 0 && (time() - $createdAt) >= 4) {
                $payload = try_claim_sub_agent_task_for_execution($taskId);
                if ($payload !== null) {
                    $result = memory_graph_execute_sub_agent_completion($providers, $payload);
                    if (isset($result['error'])) {
                        $em = $result['error'];
                        if (!is_string($em)) {
                            $em = json_encode($em, JSON_UNESCAPED_UNICODE);
                        }
                        update_sub_agent_task($taskId, ['status' => 'failed', 'error' => $em, 'result' => null]);
                    } else {
                        update_sub_agent_task($taskId, ['status' => 'done', 'result' => $result, 'error' => null]);
                    }
                    $task = get_sub_agent_task($taskId) ?? $task;
                }
            }
        }
        if ((string) ($task['status'] ?? '') === 'done' && is_array($task['result'] ?? null)) {
            $r = $task['result'];
            $resp = (string) ($r['response'] ?? '');
            if ($resp !== '') {
                $task['assistantMessageExcerpt'] = function_exists('mb_substr')
                    ? mb_substr($resp, 0, 12000, 'UTF-8')
                    : substr($resp, 0, 12000);
            }
        }

        return $task;
    }
    if ($toolName === 'wait_for_sub_agent_chat') {
        $taskId = (string) ($arguments['taskId'] ?? '');
        if ($taskId === '') return ['error' => 'taskId is required'];
        $task = get_sub_agent_task($taskId);
        if ($task === null) return ['error' => 'Task not found'];
        $status = (string) ($task['status'] ?? 'queued');
        if ($status === 'done' || $status === 'failed') {
            return $task;
        }
        $timeoutSec = isset($arguments['timeoutSeconds']) ? (int) $arguments['timeoutSeconds'] : 600;

        return memory_graph_poll_sub_agent_task_until_done($taskId, $timeoutSec, $providers);
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
    if ($toolName === 'remove_model_from_provider') {
        $providerKey = (string) ($arguments['providerKey'] ?? $arguments['provider'] ?? '');
        $modelId = (string) ($arguments['modelId'] ?? $arguments['model'] ?? '');
        if ($providerKey === '' || $modelId === '') {
            return ['error' => 'providerKey and modelId are required'];
        }
        return remove_model_from_provider($providerKey, $modelId);
    }
    return ['error' => 'Unknown built-in tool: ' . $toolName . '. Use list_available_tools to see valid tool names.'];
}

function executeToolCall(string $toolName, array $arguments, array $activeTools, ?array $mcpResultOverride = null): array {
    try {
        $result = executeToolCallInner($toolName, $arguments, $activeTools, $mcpResultOverride);
        if (is_array($result) && empty($result['error'])) {
            $n = normalizeToolName($toolName);
            if ($n === 'create_web_app' && !empty($result['ok'])) {
                $GLOBALS['MEMORY_GRAPH_WEB_APPS_LIST_DIRTY'] = true;
            } elseif ($n === 'update_web_app' && !empty($result['ok'])) {
                $GLOBALS['MEMORY_GRAPH_WEB_APPS_LIST_DIRTY'] = true;
            } elseif ($n === 'delete_web_app' && !empty($result['ok'])) {
                $GLOBALS['MEMORY_GRAPH_WEB_APPS_LIST_DIRTY'] = true;
            }
        }
        if (is_array($result) && !empty($result['display_web_app']) && empty($result['error'])) {
            $GLOBALS['MEMORY_GRAPH_WEB_APP_OPEN'] = $result;
        }
        return $result;
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

function executeToolCallInner(string $toolName, array $arguments, array $activeTools, ?array $mcpResultOverride = null): array {
    $normalizedName = normalizeToolName($toolName);
    if (in_array($normalizedName, ['run_sub_agent_chat', 'start_sub_agent_chat'], true)) {
        $sn = memory_graph_sub_agent_slug_from_tool_arguments($arguments);
        if ($sn === '') {
            $sn = (string) ($arguments['name'] ?? $arguments['subAgent'] ?? '');
        }
        if ($sn !== '') {
            memory_graph_reinforce_sub_agent_status_pulse($sn, $arguments);
        }
    }
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
        if ($mcpResultOverride !== null) {
            $result = $mcpResultOverride;
        } else {
            $result = mcp_call_server_tool($server, (string) ($activeTools[$normalizedName]['mcpToolName'] ?? $normalizedName), $arguments);
        }
        $result['__mcp_server_name'] = $server['name'] ?? '';
        $result['__mcp_node_id'] = $server['nodeId'] ?? '';
        return $result;
    }
    if ($normalizedName === 'read_memory_file') {
        $memory = get_memory_meta((string) ($arguments['name'] ?? ''));
        if ($memory === null) {
            return ['error' => 'Memory file not found'];
        }
        if (empty($memory['active']) && empty($memory['hidden'])) {
            return ['error' => 'That memory file has been disabled for me, please enable it if you want me to use that memory.', '__disabled_memory' => true];
        }
        return [
            'name' => $memory['name'],
            'title' => $memory['title'],
            'active' => $memory['active'],
            'hidden' => !empty($memory['hidden']),
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

/**
 * Merge all rules/*.md and active memory/*.md into one system-prompt block for the model.
 */
function buildMemoryAndRulesSystemPromptSection(int $maxTotalChars = 120000): string {
    $cacheSig = memory_rules_prompt_cache_signature() . "\0max:" . $maxTotalChars;
    $cachePath = memory_rules_prompt_cache_path();
    if (is_file($cachePath)) {
        $raw = @file_get_contents($cachePath);
        $cached = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        if (is_array($cached) && ($cached['sig'] ?? '') === $cacheSig && isset($cached['text']) && is_string($cached['text'])) {
            return $cached['text'];
        }
    }
    $sections = [];
    $rulesParts = [];
    foreach (list_rules_files_meta(true) as $r) {
        $name = (string) ($r['name'] ?? '');
        $content = trim((string) ($r['content'] ?? ''));
        if ($name === '' || $content === '') {
            continue;
        }
        $rulesParts[] = '### Rules file: ' . $name . "\n\n" . $content;
    }
    if ($rulesParts !== []) {
        $sections[] = "## Merged rules (all files under rules/)\n\n" . implode("\n\n---\n\n", $rulesParts);
    }
    $memParts = [];
    $memState = load_memory_state();
    foreach (list_memory_files_meta(false) as $m) {
        $name = (string) ($m['name'] ?? '');
        if ($name === '' || !memory_should_merge_into_prompt($name, $memState)) {
            continue;
        }
        $path = memory_dir_path() . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            continue;
        }
        $content = trim((string) file_get_contents($path));
        if ($content === '') {
            continue;
        }
        $memParts[] = '### Memory file: ' . $name . "\n\n" . $content;
    }
    if ($memParts !== []) {
        $sections[] = "## Merged memory (active, non-hidden files under memory/)\n\n" . implode("\n\n---\n\n", $memParts);
    }
    $out = trim(implode("\n\n", $sections));
    if ($out === '') {
        return '';
    }
    if (strlen($out) > $maxTotalChars) {
        $out = substr($out, 0, $maxTotalChars - 40) . "\n\n[Memory/rules section truncated to max length]";
    }
    @file_put_contents(
        $cachePath,
        json_encode(['sig' => $cacheSig, 'text' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    return $out;
}

/**
 * Build a compact catalog of discovery-style tools (list_*, get_*, read_*) so the model
 * can call them immediately without waiting for list_available_tools first.
 * Matches: names starting with list_/get_/read_, or containing _list_/ _get_ (e.g. list_memory_files).
 */
function buildListGetReadToolsQuickReference(array $activeTools): string {
    $listNames = [];
    $getNames = [];
    $readNames = [];
    $readWebAppActive = false;
    foreach ($activeTools as $tool) {
        if (empty($tool['active']) || !is_array($tool)) {
            continue;
        }
        $name = (string) ($tool['name'] ?? '');
        if ($name === '') {
            continue;
        }
        if ($name === 'read_web_app') {
            $readWebAppActive = true;
            continue;
        }
        if ($name === 'agent_cron_list') {
            $listNames[$name] = true;
            continue;
        }
        if (preg_match('#^list_|_list_#', $name) === 1) {
            $listNames[$name] = true;
        } elseif (preg_match('#^get_|_get_#', $name) === 1) {
            $getNames[$name] = true;
        } elseif (preg_match('#^read_#', $name) === 1) {
            $readNames[$name] = true;
        }
    }
    $list = array_keys($listNames);
    $get = array_keys($getNames);
    $read = array_keys($readNames);
    sort($list);
    sort($get);
    sort($read);
    if ($list === [] && $get === [] && $read === []) {
        return '';
    }
    $lines = [
        'QUICK REFERENCE — List / get / read tools (when the user needs inventory, metadata, or file/API content, call the matching tool in the SAME turn as your reasoning; you do not need to ask permission):',
    ];
    if ($list !== []) {
        $lines[] = '- List / enumerate: ' . implode(', ', $list);
    }
    if ($get !== []) {
        $lines[] = '- Get / fetch (by id, key, or query where applicable): ' . implode(', ', $get);
    }
    if ($read !== []) {
        $lines[] = '- Read / load by name: ' . implode(', ', $read);
    }
    if ($readWebAppActive) {
        $lines[] = '- read_web_app: ONLY local dashboard mini-apps (apps/<slug>/index.html). Never substitute for web search—use brave_search for the public web.';
    }
    $subRun = [];
    foreach ($activeTools as $tool) {
        if (empty($tool['active']) || !is_array($tool)) {
            continue;
        }
        $n = (string) ($tool['name'] ?? '');
        if (in_array($n, ['run_sub_agent_chat', 'start_sub_agent_chat', 'get_sub_agent_chat_result', 'wait_for_sub_agent_chat', 'create_sub_agent_file', 'update_sub_agent_file', 'delete_sub_agent_file'], true)) {
            $subRun[$n] = true;
        }
    }
    if ($subRun !== []) {
        ksort($subRun);
        $lines[] = '- Sub-agents (Markdown configs in sub-agents/*.md — never memory/ for agent configs): ' . implode(', ', array_keys($subRun)) . '. Chat a sub-agent with run_sub_agent_chat using JSON {"tool":"run_sub_agent_chat","arguments":{"name":"<slug>","prompt":"..."}} or native function calling.';
    }
    return implode("\n", $lines) . "\n";
}

function buildToolUsageInstruction(array $activeTools): string {
    $activeList = array_filter($activeTools, function ($tool) {
        return !empty($tool['active']);
    });
    $toolNames = array_values(array_map(function ($tool) {
        return (string) ($tool['name'] ?? '');
    }, $activeList));
    sort($toolNames);
    $toolList = implode(', ', array_slice($toolNames, 0, 80));

    $mcpToolCount = 0;
    foreach ($activeList as $tool) {
        if (!is_array($tool)) {
            continue;
        }
        $n = (string) ($tool['name'] ?? '');
        if ($n === '') {
            continue;
        }
        if (!empty($tool['mcp']) || strpos($n, 'mcp__') === 0) {
            $mcpToolCount++;
        }
    }
    $mcpAutonomyLine = $mcpToolCount > 0
        ? "There are {$mcpToolCount} MCP-connected tool(s) in your active tool list (names usually start with mcp__).\n"
        : '';

    $listGetReadRef = buildListGetReadToolsQuickReference($activeList);

    return trim(
        "You are MemoryGraph's server-side agent: real PHP tools, MCP, memory, rules, research files, jobs/cron, and dashboard web apps under apps/. Operate like a senior engineer—discover (list/read), act with tools, verify outputs, then answer. Ground claims in tool results and merged prompt content; name memory/research files when you rely on them.\n" .
        "You have access to tools, including tools for reading/writing memory when they are active.\n" .
        "MEMORYGRAPH — CUSTOM PHP TOOLS ARE REAL: This app runs on the user's server. create_or_update_tool and edit_tool_file register real files under tools/. You CAN and MUST use them when the user asks for a new tool or capability and nothing in list_available_tools already fits. It is incorrect to say you \"cannot create a custom PHP tool\", \"don't have access to the filesystem\", or to answer only with markdown/HTML snippets (e.g. iframe examples) instead of calling create_or_update_tool. After a quick list_available_tools (+ MCP discovery if relevant), implement the tool: php_code must use \$GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] and echo json_encode([...]) like get_temperature.php. For \"show website\" / iframe tools, put the iframe HTML (or url + suggested iframe attrs) inside the JSON payload (e.g. html, url) so callers can render it — still create the tool, do not refuse.\n" .
        "If the model/provider supports native function calling, use it.\n" .
        "If native function calling is unavailable or not used, you must call a tool by replying with ONLY valid JSON in this exact shape and nothing else:\n" .
        "{\"tool\":\"tool_name\",\"arguments\":{\"arg\":\"value\"}}\n" .
        "Do not wrap that JSON in markdown fences.\n" .
        "CRITICAL — MCP autonomy: You must proactively use MCP tools whenever they can help complete the task. Never wait for the user to say \"use MCP\", \"call the MCP\", or similar. If list_available_tools shows mcp__* tools, or if configured MCP servers could provide data/actions the user needs, call those tools as part of your normal workflow. Treat MCP-exposed tools like any other first-class tool. If you are unsure what an MCP server offers, call list_mcp_servers then list_mcp_server_tools for active servers, then invoke the matching tool by name.\n" .
        $mcpAutonomyLine .
        "CRITICAL — Sub-agents: They are first-class tools. Sub-agent definitions live in sub-agents/<slug>.md (YAML front matter + body), NOT under memory/. To list or edit configs use list_sub_agent_files, read_sub_agent_file, create_sub_agent_file, update_sub_agent_file, delete_sub_agent_file. To run a turn through the same engine as Jarvis (tools, memory, rules, MCP when configured) call run_sub_agent_chat with arguments name (slug without .md) and prompt (or messages). PARALLEL ORCHESTRATION: When the user bundles independent heavy work (e.g. web search + tool creation, research + coding), split it: keep one branch yourself and delegate the other to a sub-agent with start_sub_agent_chat (one self-contained prompt listing deliverables). In the same multi-tool turn or the next model round, continue your own tools (search, create_or_update_tool, etc.) without blocking on the sub-agent. Poll get_sub_agent_chat_result with taskId until status is done or failed; merge result.response (or assistantMessageExcerpt) into your final answer. Use wait_for_sub_agent_chat only when you must block until the sub-agent finishes (optional timeoutSeconds). Prefer start_sub_agent_chat + overlap over run_sub_agent_chat when both sides can progress at once. Never tell the user you \"have no sub-agent tool\", invent paths like memory/jarvis-spawn/, or emit fake markup like <function> XML—use native function calls or ONLY this JSON shape: {\"tool\":\"run_sub_agent_chat\",\"arguments\":{\"name\":\"slug\",\"prompt\":\"...\"}}.\n" .
        "To discover what tools are currently available, call list_available_tools.\n" .
        ($listGetReadRef !== '' ? $listGetReadRef : '') .
        "CRITICAL — Before create_or_update_tool (or writing a new PHP tool): You MUST first exhaust existing capabilities. (1) Call list_available_tools and read every active tool name and description — custom tools, builtins, and MCP-proxied tools (names often look like mcp__ServerSlug__tool_slug) are all listed there. (2) Call list_mcp_servers, then for each relevant active server call list_mcp_server_tools to see remote MCP tools and parameters. Only if nothing fits should you create a new PHP tool. Prefer calling an existing tool or an MCP-exposed tool over building new code.\n" .
        "RESEARCH: Use list_research_files / read_research_file / add_research_file / update_research_file when the task needs durable notes, literature, or scraped findings. Cite the research filename when you use it. Prefer updating research over only chatting ephemeral conclusions.\n" .
        "To work with memory, use list_memory_files and read_memory_file. Merged memory in this prompt excludes **hidden** files (including rolling chat transcripts). Each browser chat session appends turns to memory/_chat_session_<id>.md (hidden, not on the graph). To load that archive use read_memory_file with the exact filename, or list_memory_files with include_hidden true. If the user asks for facts already in merged memory above, answer from that text first.\n" .
        "CRITICAL — MCP setup in MemoryGraph: When the user asks to add, connect, configure, or set up an MCP server in this app, you MUST register it with create_mcp_server (new) or configure_mcp_server / update_mcp_server (existing). For HTTP remote MCP (URLs like https://.../mcp): use transport \"streamablehttp\", url set to that endpoint, optional headers for auth (set_mcp_server_header). Do NOT use create_instruction_file, update_instruction_file, add_memory_file, or similar to document MCP setup as a substitute — those do not register servers. Do NOT stop after writing markdown or example VS Code/Cursor .mcp.json unless the user explicitly asked only for external editor config. After saving the server, call list_mcp_server_tools (and list_mcp_servers) to confirm; then summarize what you registered for the user.\n" .
        "To configure MCP servers, use the MCP config tools such as create_mcp_server, configure_mcp_server, set_mcp_server_header, or set_mcp_server_env_var when available.\n" .
        "Active rules (*.md in rules/) and active non-hidden memory (*.md in memory/) are merged into the system prompt above (when non-empty). For prior turns without bloating context: read_memory_file on the session transcript (see above), or list_chat_history / get_chat_history (JSON store), or list_memory_files with include_hidden.\n" .
        "If the user explicitly provides local credentials, private keys, API keys, env vars, headers, or similar config values for a tool or MCP server, you may use them to configure the local app and MCP servers. Do not refuse solely because the value looks secret.\n" .
        "CRITICAL - Tool creation: When you use create_or_update_tool, you MUST immediately call the newly created tool to test it. If it fails (error in result), use edit_tool_file to fix the PHP code and call the tool again. Repeat until the tool succeeds. Never respond to the user or report success until you have tested the tool and it works. This applies no matter what - always test, always fix until success.\n" .
        "TOOL ERRORS: When a tool returns result.error, fix the real cause before your final answer. (1) Bug in custom PHP under tools/ (file/line or stack) → edit_tool_file and retry until it works. (2) Wrong arguments or unknown name → list_* / read_* to discover valid inputs, then retry. (3) Missing API key or upstream HTTP (Brave, Gemini, MCP remote, etc.) → fix .env or parameters, or tell the user exactly what is missing—do not loop edit_tool_file for third-party outages. (4) Wrong tool schema → edit_tool_registry_entry then retry.\n" .
        "CRITICAL — Tabular output: If display_table is active, use it for any row/column data you want the user to see clearly (SQL/ETL, research comparisons, release lists, benchmarks—not only databases). Pass headers and rows (string cells). Do not use markdown pipe tables for that data; call display_table first, then short prose.\n" .
        "CRITICAL — Charts: If render_chart is active, use it for bar/line/pie (etc.) visuals: pass chart_config as a QuickChart/Chart.js config object, or chart_url as an https image URL. Prefer render_chart over embedding huge QuickChart URLs in markdown.\n" .
        "INTERACTIVE WEB APPS: Dashboard mini-apps live under apps/<slug>/index.html. Use list_web_apps, read_web_app, create_web_app, update_web_app, delete_web_app. To show an app fullscreen for the user, call display_web_app with the app slug (name). Public web search is brave_search—not read_web_app.\n" .
        "CRITICAL — create_web_app / update_web_app: You MUST actually invoke the tool with the full html string. Never tell the user an app slug exists or that you \"opened\" it until the tool result returns {\"ok\":true,...}. If the result has \"error\", read it, fix (e.g. new slug if already exists, non-empty html), and call the tool again. After a successful create, call display_web_app with that slug so the user sees it. Prefer passing a complete <!DOCTYPE html> document starting at the first character (or after optional BOM/comments); if you send only a body fragment, omit any fake <html> substring at the start of scripts.\n" .
        "JOBS & AUTOMATION: Use job markdown tools and agent_cron / cron-related tools when the user wants repeatable work or schedules; persist intent in jobs/ or cron configuration instead of only describing steps in chat.\n" .
        ($toolList !== '' ? "Currently active tools include: " . $toolList . "\n" : '') .
        "When you are not calling a tool, answer normally.\n" .
        "Do not end a turn with internal planning only (e.g. lines about what you \"should\" say or do, or restating the user's intent). Either call the next tool, emit the required JSON tool call, or write the direct user-facing answer.\n" .
        "CRITICAL - Do not stop prematurely: Use as many tool calls as needed to complete the task. Never respond with 'no tool calls', 'I have no tools to use', or similar - keep using tools until the task is complete, then give your final answer."
    );
}

/**
 * Format etl_payroll_tool rows as markdown for the model (avoids huge JSON + truncation).
 */
function formatEtlPayrollToolResultForModel(array $toolResult): string {
    if (isset($toolResult['error'])) {
        return (string) json_encode($toolResult, JSON_UNESCAPED_UNICODE);
    }
    $rows = $toolResult['result'] ?? null;
    if (!is_array($rows)) {
        return (string) json_encode($toolResult, JSON_UNESCAPED_UNICODE);
    }
    $count = isset($toolResult['count']) ? (int) $toolResult['count'] : count($rows);
    $esc = static function (string $s): string {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = str_replace(['|', "\0"], ['\\|', ''], $s);
        if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > 100) {
            $s = mb_substr($s, 0, 97, 'UTF-8') . '...';
        } elseif (strlen($s) > 120) {
            $s = substr($s, 0, 117) . '...';
        }
        return trim($s);
    };

    $lines = [
        '## etl_Payroll (SQL result)',
        '',
        '**Row count:** ' . $count,
    ];
    if ($count === 0) {
        $lines[] = '';
        $lines[] = '_Empty result set._';
        $lines[] = '';
        $lines[] = 'When summarizing: use **normal spaces only** in markdown (no narrow/thin Unicode spaces).';
        return implode("\n", $lines);
    }

    $allKeys = [];
    foreach ($rows as $r) {
        if (is_array($r)) {
            foreach (array_keys($r) as $k) {
                if (!in_array($k, $allKeys, true)) {
                    $allKeys[] = $k;
                }
            }
        }
    }
    $maxCols = 14;
    $maxRows = 25;
    $displayKeys = array_slice($allKeys, 0, $maxCols);
    $moreCols = count($allKeys) - count($displayKeys);

    $lines[] = '**Columns (' . count($allKeys) . '):** ' . implode(', ', $allKeys);
    $lines[] = '';
    $lines[] = '### Preview table';
    $lines[] = '';
    $lines[] = '| ' . implode(' | ', array_map($esc, $displayKeys)) . ' |';
    $lines[] = '|' . str_repeat(' --- |', count($displayKeys));

    $slice = array_slice($rows, 0, $maxRows);
    foreach ($slice as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cells = [];
        foreach ($displayKeys as $k) {
            $v = $row[$k] ?? '';
            if ($v === null) {
                $v = '';
            }
            if (!is_scalar($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $cells[] = $esc((string) $v);
        }
        $lines[] = '| ' . implode(' | ', $cells) . ' |';
    }
    if ($moreCols > 0) {
        $lines[] = '';
        $lines[] = '_(' . $moreCols . ' more columns omitted from table; full names listed above.)_';
    }
    if ($count > $maxRows) {
        $lines[] = '';
        $lines[] = '_Showing first ' . $maxRows . ' of ' . $count . ' rows._';
    }
    $lines[] = '';
    $lines[] = '**MANDATORY — Reply to the user:** Call **display_table** with:';
    $lines[] = '- `headers`: ' . json_encode($allKeys, JSON_UNESCAPED_UNICODE) . ' (or a subset if you only show some columns)';
    $lines[] = '- `rows`: each row from the query as an array of string cells (match header order; use full result set from your tool memory for all rows, not only the preview above)';
    $lines[] = '- optional `caption` (e.g. query summary)';
    $lines[] = 'Do **not** answer with a markdown pipe table. After display_table, you may add short bullets (mask SSNs/sensitive IDs in prose).';
    return implode("\n", $lines);
}

/** Normalize model quirks (narrow spaces etc.) in assistant text for cleaner UI/JSON. */
function normalizeAssistantFormatting(string $content): string {
    if ($content === '') {
        return $content;
    }
    $repl = [
        "\xE2\x80\xAF" => ' ', // U+202F narrow no-break space
        "\xC2\xA0" => ' ',     // U+00A0 nbsp
        "\xE2\x80\x89" => ' ', // U+2009 thin space
        "\xE2\x80\x8B" => '',  // U+200B zero-width space
    ];
    return str_replace(array_keys($repl), array_values($repl), $content);
}

function memory_graph_json_encode_tool_safe($data): string {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    if (!is_array($data) && !is_object($data)) {
        $data = ['value' => $data];
    }
    $j = json_encode($data, $flags);

    return is_string($j) && $j !== '' ? $j : '{"error":"json_encode_failed"}';
}

/**
 * Flatten Brave Search JSON (shape varies by tier/type): web, news, videos, discussions, etc.
 *
 * @return list<array{title:string,url:string,desc:string}>
 */
function memory_graph_brave_collect_result_items(array $data): array {
    $out = [];
    $lists = [];
    foreach (['web', 'news', 'videos', 'discussions', 'faq'] as $key) {
        if (isset($data[$key]['results']) && is_array($data[$key]['results'])) {
            $lists[] = $data[$key]['results'];
        }
    }
    foreach ($lists as $list) {
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = (string) ($item['title'] ?? $item['name'] ?? '');
            $url = (string) ($item['url'] ?? $item['link'] ?? '');
            $desc = (string) ($item['description'] ?? $item['snippet'] ?? '');
            if (isset($item['meta_url']) && is_array($item['meta_url']) && $desc === '') {
                $desc = (string) ($item['meta_url']['snippet'] ?? '');
            }
            if ($title === '' && $url === '' && $desc === '') {
                continue;
            }
            if ($title === '') {
                $title = '(no title)';
            }
            $out[] = ['title' => $title, 'url' => $url, 'desc' => $desc];
        }
    }

    return $out;
}

/**
 * Brave web search returns a large nested JSON blob; sending the raw JSON to the model often exceeds
 * tool-message truncation (1800 chars) mid-string, which breaks the next LLM call. Compress to titles/URLs/snippets.
 */
function memory_graph_format_brave_search_for_model(array $data): string {
    if (isset($data['error'])) {
        return memory_graph_json_encode_tool_safe($data);
    }
    $lines = [];
    $q = '';
    if (isset($data['query']) && is_array($data['query'])) {
        $q = (string) ($data['query']['original'] ?? $data['query']['altered'] ?? $data['query']['spellchecked'] ?? '');
    }
    if ($q !== '') {
        $lines[] = 'Query: ' . $q;
    }
    $flat = memory_graph_brave_collect_result_items($data);
    $maxItems = 30;
    $maxDesc = 450;
    $n = 0;
    foreach ($flat as $item) {
        if ($n >= $maxItems) {
            break;
        }
        $title = $item['title'];
        $url = $item['url'];
        $desc = $item['desc'];
        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($desc, 'UTF-8') > $maxDesc) {
            $desc = mb_substr($desc, 0, $maxDesc, 'UTF-8') . '…';
        } elseif (strlen($desc) > $maxDesc) {
            $desc = substr($desc, 0, $maxDesc) . '…';
        }
        $lines[] = ($n + 1) . '. ' . $title . "\n   " . $url . "\n   " . $desc;
        $n++;
    }
    if ($n === 0) {
        $fallback = memory_graph_json_encode_tool_safe($data);
        $lim = 14000;

        return strlen($fallback) > $lim ? substr($fallback, 0, $lim) . "\n…[truncated]" : $fallback;
    }
    $lines[] = 'Answer the user using these results; include relevant URLs.';

    $out = implode("\n\n", $lines);
    $lim = 20000;
    if (strlen($out) > $lim) {
        $out = substr($out, 0, $lim) . "\n…[truncated]";
    }

    return $out;
}

/**
 * When the upstream model returns an empty final message after tools, still show tool output in the UI.
 */
function memory_graph_forced_reply_from_tool_messages(array $conversation, string $requestId): string {
    $blocks = [];
    foreach ($conversation as $m) {
        if (!is_array($m) || ($m['role'] ?? '') !== 'tool') {
            continue;
        }
        $name = normalizeToolName((string) ($m['name'] ?? ''));
        $c = isset($m['content']) && is_string($m['content']) ? trim($m['content']) : '';
        if ($c === '') {
            continue;
        }
        $cap = 16000;
        if (strlen($c) > $cap) {
            $c = substr($c, 0, $cap) . "\n…[truncated]";
        }
        $label = $name !== '' ? $name : 'tool';
        $blocks[] = '**' . $label . "**\n\n" . $c;
    }
    if ($blocks === []) {
        return '';
    }

    return "The model did not return a final assistant message. Here is the tool output from this request:\n\n"
        . implode("\n\n---\n\n", $blocks)
        . "\n\n_(Request ID: " . $requestId . ")_";
}

/** Append non-empty tool summaries when the model returns no final prose (UI + digest). */
function memory_graph_append_tool_fallback_summary(string &$bucket, string $chunk): void {
    $chunk = trim($chunk);
    if ($chunk === '') {
        return;
    }
    if ($bucket === '') {
        $bucket = $chunk;
    } else {
        $bucket .= "\n\n— Tool result —\n" . $chunk;
    }
    if (strlen($bucket) > 14000) {
        $bucket = substr($bucket, 0, 14000) . "\n…[truncated]";
    }
}

/**
 * Safe string for lastToolResultText fallback (avoids Array to string when result is rows).
 */
function lastToolResultDisplayText(string $toolName, array $toolResult): string {
    $toolName = normalizeToolName($toolName);
    if (isset($toolResult['text']) && is_string($toolResult['text'])) {
        return $toolResult['text'];
    }
    if (isset($toolResult['error'])) {
        $e = $toolResult['error'];
        return is_scalar($e) ? (string) $e : (string) json_encode($e, JSON_UNESCAPED_UNICODE);
    }
    if ($toolName === 'brave_search') {
        $flat = memory_graph_brave_collect_result_items($toolResult);
        $cnt = count($flat);
        $t0 = $cnt > 0 ? (string) ($flat[0]['title'] ?? '') : '';
        if ($t0 !== '' && function_exists('mb_substr') && mb_strlen($t0, 'UTF-8') > 100) {
            $t0 = mb_substr($t0, 0, 97, 'UTF-8') . '…';
        } elseif (strlen($t0) > 100) {
            $t0 = substr($t0, 0, 97) . '…';
        }

        return $cnt > 0
            ? 'Brave search: ' . $cnt . ' hit(s)' . ($t0 !== '' ? ' — e.g. ' . $t0 : '')
            : 'Brave search completed (no indexed hits in web/news/etc. — see formatted tool body or API JSON).';
    }
    if (($toolName === 'execute_job_file' || $toolName === 'read_job_file') && !isset($toolResult['error'])) {
        $n = isset($toolResult['name']) ? (string) $toolResult['name'] : '';
        $clen = isset($toolResult['content']) && is_string($toolResult['content']) ? strlen($toolResult['content']) : 0;
        if ($n !== '') {
            $verb = $toolName === 'execute_job_file' ? 'Loaded job for execution' : 'Read job file';
            return $verb . ': **' . $n . '**' . ($clen > 0 ? ' (' . $clen . ' characters).' : '.');
        }
    }
    // Cron/UI fallback: successful research writes often carry huge `content`; json_encode can fail on bad UTF-8 and return ''.
    $researchWriteTools = ['add_research_file', 'create_research_file', 'update_research_file'];
    if (in_array($toolName, $researchWriteTools, true)) {
        $n = isset($toolResult['name']) ? (string) $toolResult['name'] : '';
        if ($n !== '') {
            return 'Saved research file: ' . $n;
        }
    }
    if ($toolName === 'delete_research_file' && isset($toolResult['deleted']) && is_string($toolResult['deleted']) && $toolResult['deleted'] !== '') {
        return 'Deleted research file: ' . $toolResult['deleted'];
    }
    if ($toolName === 'display_web_app' && !empty($toolResult['display_web_app']) && empty($toolResult['error'])) {
        $t = isset($toolResult['title']) ? (string) $toolResult['title'] : '';
        $n = isset($toolResult['name']) ? (string) $toolResult['name'] : '';
        return 'Opened web app **' . ($t !== '' ? $t : $n) . '** in the maximized viewer.';
    }
    if ($toolName === 'create_web_app' && !empty($toolResult['ok']) && empty($toolResult['error'])) {
        $n = isset($toolResult['name']) ? (string) $toolResult['name'] : '';
        if ($n !== '') {
            return 'Created web app **' . $n . '** (saved as apps/' . $n . '/index.html). It should appear in the Apps list now.';
        }
    }
    if ($toolName === 'update_web_app' && !empty($toolResult['ok']) && empty($toolResult['error'])) {
        $n = isset($toolResult['name']) ? (string) $toolResult['name'] : '';
        if ($n !== '') {
            return 'Updated web app **' . $n . '**. Apps list refreshed on the client after chat completes.';
        }
    }
    if ($toolName === 'delete_web_app' && !empty($toolResult['ok']) && empty($toolResult['error'])) {
        $d = isset($toolResult['deleted']) ? (string) $toolResult['deleted'] : '';
        if ($d !== '') {
            return 'Deleted web app **' . $d . '** from apps/.';
        }
    }
    if (isset($toolResult['response']) && is_string($toolResult['response']) && trim($toolResult['response']) !== '') {
        return trim($toolResult['response']);
    }
    $r = $toolResult['result'] ?? null;
    if (is_array($r)) {
        if ($toolName === 'etl_payroll_tool') {
            return formatEtlPayrollToolResultForModel($toolResult);
        }
        return (string) json_encode([
            'row_count' => isset($toolResult['count']) ? (int) $toolResult['count'] : count($r),
            'preview' => array_slice($r, 0, 2),
        ], JSON_UNESCAPED_UNICODE);
    }
    if (is_scalar($r)) {
        return (string) $r;
    }
    $summary = $toolResult;
    if (isset($summary['content']) && is_string($summary['content']) && strlen($summary['content']) > 800) {
        $summary['content'] = '[omitted ' . strlen($toolResult['content']) . ' characters]';
    }
    $enc = json_encode($summary, JSON_UNESCAPED_UNICODE);
    if (!is_string($enc) || $enc === '' || $enc === '[]') {
        return 'Tool `' . $toolName . '` finished (result could not be summarized as JSON; the model still receives the full tool payload in the conversation).';
    }
    $max = 4500;
    if (strlen($enc) > $max) {
        return substr($enc, 0, $max) . "\n…[truncated]";
    }
    return $enc;
}

/**
 * Guidance after tool errors: avoid telling the model to edit PHP for env/API failures.
 */
function memory_graph_tool_error_followup_directive(string $toolName, array $toolResult): string {
    if (isset($toolResult['file']) && is_string($toolResult['file']) && $toolResult['file'] !== '' && isset($toolResult['line'])) {
        return ' Fix the PHP in tools/' . $toolResult['file'] . ' at line ' . (int) $toolResult['line'] . ' via edit_tool_file, then retry until the call succeeds.';
    }
    $tn = normalizeToolName($toolName);
    $envSensitive = ['brave_search', 'brave_image_search', 'get_gemini_response'];
    if (in_array($tn, $envSensitive, true)) {
        return ' Likely missing/invalid API key, rate limit, or request parameters—check .env and arguments; retry with corrections or tell the user what blocks progress. Use edit_tool_file only if the PHP wrapper is clearly wrong.';
    }
    if ($tn === 'call_mcp_tool' || str_starts_with($tn, 'mcp_')) {
        return ' Verify MCP server config (e.g. list_mcp_servers), tool name, and JSON arguments; retry after fixes. edit_tool_file only if a local PHP bridge is broken.';
    }
    return ' Follow TOOL ERRORS in the system prompt: bugs in tools/*.php → edit_tool_file or edit_tool_registry_entry; wrong names/args → list_* / read_* then retry; external API or env → configure or explain to the user.';
}

/** Build the user/tool message content for a tool result; when result has 'error', append fix-and-retry directive. */
function formatToolResultForModel(string $toolName, array $toolResult, bool $inlineFormat = false): string {
    $fixDirective = isset($toolResult['error']) ? memory_graph_tool_error_followup_directive($toolName, $toolResult) : '';
    if ($toolName === 'etl_payroll_tool' && !isset($toolResult['error'])) {
        $s = formatEtlPayrollToolResultForModel($toolResult);
        if ($inlineFormat) {
            $s .= "\n\nContinue and answer the original user request.";
        }
        return $s;
    }
    if (normalizeToolName($toolName) === 'brave_search') {
        $body = memory_graph_format_brave_search_for_model($toolResult);
        if ($inlineFormat) {
            if (isset($toolResult['error'])) {
                return 'Tool "brave_search" returned: ' . $body . '.' . $fixDirective;
            }

            return "Tool \"brave_search\" returned (formatted results):\n" . $body . "\n\nYou MUST now write a clear answer for the user using these results (with URLs). Do not call brave_search again unless the query must change.";
        }

        return $body;
    }
    $json = memory_graph_json_encode_tool_safe($toolResult);
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
        return 'Tool error — resolve before final answer.' . $fixDirective . ' Payload: ' . $json;
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

/** Truncate conversation to avoid context length limits. Keeps system + first user + last N messages; caps content to prevent unbounded growth. */
function truncateConversationForContext(array $conversation): array {
    $maxMessages = 32;
    $maxContentChars = 4000;
    $maxToolContentChars = 1800;

    if (count($conversation) <= $maxMessages) {
        $out = $conversation;
    } else {
        $system = [];
        $firstUser = [];
        $rest = $conversation;
        if (isset($rest[0]) && is_array($rest[0]) && ($rest[0]['role'] ?? '') === 'system') {
            $system = [array_shift($rest)];
        }
        if (!empty($rest) && is_array($rest[0]) && ($rest[0]['role'] ?? '') === 'user') {
            $firstUser = [array_shift($rest)];
        }
        $keepCount = $maxMessages - count($system) - count($firstUser);
        $out = array_merge($system, $firstUser, array_slice($rest, -max(1, $keepCount)));
    }
    $result = [];
    foreach ($out as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $content = $msg['content'] ?? '';
        if (!is_string($content)) {
            $result[] = $msg;
            continue;
        }
        $role = $msg['role'] ?? 'user';
        $cap = ($role === 'tool') ? $maxToolContentChars : $maxContentChars;
        if ($role === 'system') {
            $cap = 250000;
        }
        if ($role === 'tool' && (($msg['name'] ?? '') === 'etl_payroll_tool')) {
            $cap = min(56000, max($cap, 32000));
        }
        if ($role === 'tool' && normalizeToolName((string) ($msg['name'] ?? '')) === 'brave_search') {
            $cap = min(56000, max($cap, 24000));
        }
        if (strlen($content) > $cap) {
            $msg = array_merge($msg, ['content' => substr($content, 0, $cap) . "\n\n[truncated]"]);
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

/**
 * Extract visible assistant text from OpenAI-style chat message (handles string content and part arrays).
 * Never prefers provider reasoning/thinking fields over user-facing content — those are not shown as the reply.
 */
function openai_extract_assistant_message_text(?array $message): string {
    if (!is_array($message)) {
        return '';
    }
    if (!empty($message['refusal']) && is_string($message['refusal'])) {
        return trim((string) $message['refusal']);
    }
    $rawContent = $message['content'] ?? null;
    $fromContent = '';
    if (is_string($rawContent)) {
        $fromContent = trim($rawContent);
    } elseif (is_array($rawContent)) {
        $assistantContent = '';
        foreach ($rawContent as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = (string) ($part['type'] ?? '');
            if ($type === 'reasoning' || $type === 'thinking' || $type === 'redacted_reasoning') {
                continue;
            }
            if (isset($part['text']) && (is_string($part['text']) || is_numeric($part['text']))) {
                $assistantContent .= (string) $part['text'];
                continue;
            }
            if (isset($part['text']) && is_array($part['text']) && isset($part['text']['value']) && is_string($part['text']['value'])) {
                $assistantContent .= $part['text']['value'];
                continue;
            }
            if (isset($part['content']) && is_string($part['content'])) {
                $assistantContent .= $part['content'];
                continue;
            }
            if (($type === 'text' || $type === 'output_text' || $type === 'input_text') && isset($part['text'])) {
                $assistantContent .= is_scalar($part['text']) ? (string) $part['text'] : '';
            }
        }
        $fromContent = trim($assistantContent);
    }
    if ($fromContent !== '') {
        return $fromContent;
    }
    if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
        return '';
    }
    if (isset($message['output_text']) && is_string($message['output_text']) && trim($message['output_text']) !== '') {
        return trim($message['output_text']);
    }
    if ($rawContent === null || $rawContent === false) {
        return '';
    }
    if (!is_string($rawContent) && !is_array($rawContent)) {
        $s = trim((string) $rawContent);
        return $s !== '' ? $s : '';
    }
    return '';
}

function requestOpenAiCompatible(array $provider, string $model, array $conversation, float $temperature, array $tools, string $providerKey = ''): array {
    $payload = [
        'model' => $model,
        'messages' => $conversation,
        'temperature' => $temperature,
    ];
    if (!empty($tools)) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = 'auto';
    }
    if ($providerKey === 'alibaba' && $model !== '' && preg_match('/^glm/i', $model) === 1) {
        $payload['enable_thinking'] = true;
    }

    $endpoint = (string) ($provider['endpoint'] ?? '');
    $apiKey = (string) ($provider['apiKey'] ?? '');
    if ($providerKey === 'alibaba') {
        $apiKey = memory_graph_alibaba_sanitize_api_key($apiKey);
    }

    $result = memory_graph_openai_compatible_post($endpoint, $apiKey, $payload, $providerKey);

    if ($providerKey === 'alibaba' && isset($result['error'])) {
        $raw = isset($result['raw']) ? (string) $result['raw'] : '';
        $hc = (int) ($result['httpCode'] ?? 0);
        if (memory_graph_alibaba_should_retry_alternate_region($hc, $raw)) {
            $alt = memory_graph_alibaba_toggle_endpoint($endpoint);
            if ($alt !== $endpoint) {
                $result = memory_graph_openai_compatible_post($alt, $apiKey, $payload, $providerKey);
            }
        }
    }

    return $result;
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
        CURLOPT_TIMEOUT => 600,
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

/**
 * When the LLM upstream returns 4xx/5xx, echo JSON cron/UI can parse instead of raw HTML or opaque blobs.
 */
function mg_emit_upstream_llm_error_body(int $httpCode, string $rawBody): void {
    $trim = ltrim($rawBody);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $j = json_decode($rawBody, true);
        if (is_array($j) && isset($j['error']) && isset($j['response']) && is_string($j['response'])) {
            $prev = is_string($j['error'])
                ? $j['error']
                : (is_scalar($j['error']) ? (string) $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE));
            $preview = mb_substr(preg_replace('/\s+/', ' ', strip_tags($j['response'])), 0, 500);
            $hint = 'AI provider request failed (HTTP ' . $httpCode . '). ';
            if (stripos($j['response'], 'www.google.com') !== false || stripos($preview, 'Error 404') !== false) {
                $hint .= 'Upstream body looks like Google\'s HTML 404 — wrong Gemini URL/API version, model id, or API key. MemoryGraph chat uses generativelanguage.googleapis.com/v1beta/models. ';
            }
            echo json_encode([
                'error' => $hint . $prev,
                'provider_http_code' => $httpCode,
                'upstream_preview' => $preview,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    if ($rawBody !== '' && (stripos($rawBody, '<html') !== false || stripos($rawBody, '<!DOCTYPE') !== false)) {
        $preview = mb_substr(preg_replace('/\s+/', ' ', strip_tags($rawBody)), 0, 500);
        echo json_encode([
            'error' => 'AI provider returned HTML (HTTP ' . $httpCode . ') instead of JSON — usually a wrong endpoint or model. Check provider URL, model id, and API key.',
            'provider_http_code' => $httpCode,
            'upstream_preview' => $preview,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo $rawBody;
}

if (!$memoryGraphChatLibraryOnly) {
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if (!empty($input['__memoryGraphSubAgentWorker'])) {
    $ws = memory_graph_sub_agent_worker_secret();
    if ($ws === '' || !hash_equals($ws, (string) ($input['__workerSecret'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing MEMORYGRAPH_SUB_AGENT_ASYNC_SECRET for sub-agent worker']);
        exit;
    }
    $taskId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($input['taskId'] ?? ''));
    if ($taskId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'taskId required']);
        exit;
    }
    $GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID'] = 'subagent_worker_' . $taskId;
    $GLOBALS['MEMORY_GRAPH_CHAT_SESSION_ID'] = '';
    $payload = try_claim_sub_agent_task_for_execution($taskId);
    if ($payload === null) {
        $t = get_sub_agent_task($taskId);
        echo json_encode([
            'ok' => true,
            'skipped' => true,
            'status' => is_array($t) ? (string) ($t['status'] ?? '') : '',
        ]);
        exit;
    }
    $result = memory_graph_execute_sub_agent_completion($providers, $payload);
    if (isset($result['error'])) {
        $em = $result['error'];
        if (!is_string($em)) {
            $em = json_encode($em, JSON_UNESCAPED_UNICODE);
        }
        update_sub_agent_task($taskId, ['status' => 'failed', 'error' => $em, 'result' => null]);
    } else {
        update_sub_agent_task($taskId, ['status' => 'done', 'result' => $result, 'error' => null]);
    }
    echo json_encode(['ok' => true, 'taskId' => $taskId]);
    exit;
}

$GLOBALS['MEMORY_GRAPH_WEB_APP_OPEN'] = null;
$GLOBALS['MEMORY_GRAPH_CRON_PENDING_PATHS'] = [];
$GLOBALS['MEMORY_GRAPH_CRON_NODE_ID'] = '';
$skipCronPendingDelivery = !empty($input['skipCronPendingDelivery']);
if ($skipCronPendingDelivery) {
    $cj = isset($input['cronJobId']) ? preg_replace('/[^a-f0-9]/i', '', (string) $input['cronJobId']) : '';
    if ($cj !== '') {
        $GLOBALS['MEMORY_GRAPH_CRON_NODE_ID'] = mg_cron_job_node_id($cj);
    }
}
$chatSessionIdInput = isset($input['chatSessionId']) ? trim((string) $input['chatSessionId']) : '';
$GLOBALS['MEMORY_GRAPH_CHAT_SESSION_ID'] = $chatSessionIdInput;
$messages = $input['messages'] ?? [];
$providerKey = $input['provider'] ?? 'mercury';
$model = isset($input['model']) ? (string) $input['model'] : null;
$systemPrompt = isset($input['systemPrompt']) ? (string) $input['systemPrompt'] : '';
$temperature = isset($input['temperature']) ? (float) $input['temperature'] : 0.7;
$requestId = sanitizeRequestId(isset($input['requestId']) ? (string) $input['requestId'] : null);
$GLOBALS['MEMORY_GRAPH_CHAT_REQUEST_ID'] = $requestId;
$GLOBALS['MEMORYGRAPH_PARENT_STATUS_REQUEST_ID'] = '';
$GLOBALS['MEMORYGRAPH_PARENT_SUB_AGENT_NODE_ID'] = '';
$parentStatusRid = isset($input['parentStatusRequestId']) ? trim((string) $input['parentStatusRequestId']) : '';
$parentStatusRid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $parentStatusRid);
if ($parentStatusRid !== '' && strpos($parentStatusRid, 'subagent_worker_') !== 0) {
    $GLOBALS['MEMORYGRAPH_PARENT_STATUS_REQUEST_ID'] = $parentStatusRid;
    $GLOBALS['MEMORYGRAPH_PARENT_SUB_AGENT_NODE_ID'] = preg_replace(
        '/[^a-zA-Z0-9_\-]/',
        '',
        (string) ($input['parentSubAgentNodeId'] ?? '')
    );
}

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
    'activeSubAgentIds' => [],
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
    'lastActiveSubAgentIds' => [],
    'lastExecutionDetailsByNode' => [],
    'lastEventExpiresAtMs' => 0,
    'graphRefreshToken' => '',
];
if (!empty($GLOBALS['MEMORY_GRAPH_CRON_NODE_ID'])) {
    $nid = (string) $GLOBALS['MEMORY_GRAPH_CRON_NODE_ID'];
    $status['checkingJobs'] = true;
    $status['activeJobIds'] = [$nid];
    $status['lastCheckingJobs'] = true;
    $status['lastActiveJobIds'] = [$nid];
    $status['executionDetailsByNode']['jobs'] = true;
    $status['lastExecutionDetailsByNode']['jobs'] = true;
}
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
if (!empty($input['subAgentProviderRuntime']) && is_array($input['subAgentProviderRuntime'])) {
    $ov = $input['subAgentProviderRuntime'];
    foreach (['endpoint', 'endpointBase', 'apiKey', 'type', 'defaultModel'] as $rk) {
        if (isset($ov[$rk]) && (string) $ov[$rk] !== '') {
            $provider[$rk] = $ov[$rk];
        }
    }
}
if ($providerKey === 'alibaba') {
    $provider = memory_graph_alibaba_provider_runtime($provider);
}
if (($provider['apiKey'] ?? '') === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Missing API key for provider "' . $providerKey . '". Set DASHSCOPE_API_KEY or ALIBABA_API_KEY in .env.']);
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
$pmKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $providerKey) . ':' . trim((string) $modelId);
$agentCfgForPrompt = get_agent_provider_config();
$sifm = isset($agentCfgForPrompt['systemInstructionFilesByModel']) && is_array($agentCfgForPrompt['systemInstructionFilesByModel'])
    ? $agentCfgForPrompt['systemInstructionFilesByModel']
    : [];
$instrFileMapped = isset($sifm[$pmKey]) && is_string($sifm[$pmKey]) ? trim($sifm[$pmKey]) : '';
if ($instrFileMapped !== '') {
    $im = get_instruction_meta($instrFileMapped);
    if ($im !== null && isset($im['content']) && trim((string) $im['content']) !== '') {
        $systemPrompt = trim((string) $im['content']);
    }
} elseif (trim($systemPrompt) === '') {
    $spm = isset($agentCfgForPrompt['systemPromptsByModel']) && is_array($agentCfgForPrompt['systemPromptsByModel'])
        ? $agentCfgForPrompt['systemPromptsByModel']
        : [];
    if (isset($spm[$pmKey]) && is_string($spm[$pmKey]) && trim($spm[$pmKey]) !== '') {
        $systemPrompt = trim($spm[$pmKey]);
    }
}
$activeTools = loadToolRegistry();
$openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
$memoryRulesBlock = buildMemoryAndRulesSystemPromptSection();
$sessionCtx = $chatSessionIdInput !== ''
    ? 'Current browser chat session id (for list_chat_history): ' . json_encode($chatSessionIdInput, JSON_UNESCAPED_UNICODE) . ' Pass it as session_id, or set current_session_only to true.'
    : '';
$cronPendingBlock = '';
if (!$skipCronPendingDelivery) {
    $pack = mg_cron_pending_build_for_chat();
    if ($pack['block'] !== '') {
        $GLOBALS['MEMORY_GRAPH_CRON_PENDING_PATHS'] = $pack['paths'];
        $cronPendingBlock = $pack['block'];
    }
}
$subAgentIdentityPrefix = '';
if (!empty($input['subAgentIdentity']) && is_string($input['subAgentIdentity'])) {
    $subAgentIdentityPrefix = trim($input['subAgentIdentity']);
    if ($subAgentIdentityPrefix !== '') {
        $subAgentIdentityPrefix .= "\n\n";
    }
}
$effectiveSystemPrompt = trim(
    $subAgentIdentityPrefix
    . ($systemPrompt !== '' ? $systemPrompt . "\n\n" : '')
    . ($cronPendingBlock !== '' ? $cronPendingBlock . "\n\n" : '')
    . ($memoryRulesBlock !== '' ? $memoryRulesBlock . "\n\n" : '')
    . ($sessionCtx !== '' ? $sessionCtx . "\n\n" : '')
    . buildToolUsageInstruction($activeTools)
);
$conversation = normalizeConversation($messages, $effectiveSystemPrompt, $provider['type']);
$finalContent = '';
$lastToolResultText = '';
$loopCount = 0;
$jobsToRun = [];

$apiRetryCount = 0;
$apiErrorRetryMax = 3;

while (true) {
    $loopCount++;
    if ($loopCount > 500) {
        $finalContent = trim($finalContent) !== '' ? $finalContent : 'Stopped after too many tool iterations.';
        break;
    }

    $conversationToSend = sanitizeConversationForApi(truncateConversationForContext($conversation));
    $result = $provider['type'] === 'gemini'
        ? requestGemini($provider, $modelId, $conversationToSend, $temperature, $effectiveSystemPrompt)
        : requestOpenAiCompatible($provider, $modelId, $conversationToSend, $temperature, $openAiTools, $providerKey);

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
            mg_emit_upstream_llm_error_body((int) ($result['httpCode'] ?? 502), (string) $result['raw']);
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
            if (isset($data['response']) && is_string($data['response']) && trim($data['response']) !== '') {
                $assistantContent = trim($data['response']);
                $toolCalls = [];
            } else {
                clearStatusFlags($status);
                writeStatus($requestId, $status);
                http_response_code(502);
                echo json_encode(['error' => 'Invalid provider response']);
                exit;
            }
        } else {
            $assistantContent = openai_extract_assistant_message_text($message);
            $toolCalls = $message['tool_calls'] ?? [];
        }

        if (!empty($toolCalls) && is_array($toolCalls)) {
            $conversation[] = [
                'role' => 'assistant',
                'content' => $assistantContent,
                'tool_calls' => $toolCalls,
            ];
            $prepared = [];
            foreach ($toolCalls as $toolCall) {
                $callId = $toolCall['id'] ?? uniqid('tool_', true);
                $functionName = $toolCall['function']['name'] ?? '';
                $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);
                $arguments = is_array($arguments) ? $arguments : [];
                $normalizedFunctionName = normalizeToolName($functionName);
                $prepared[] = [
                    'callId' => $callId,
                    'functionName' => $functionName,
                    'arguments' => $arguments,
                    'normalizedName' => $normalizedFunctionName,
                ];
            }

            $parallelMcpResults = null;
            if (chat_openai_tool_calls_all_mcp_parallel_eligible($prepared, $activeTools)) {
                $jobs = [];
                foreach ($prepared as $row) {
                    $n = $row['normalizedName'];
                    $server = get_mcp_server_meta((string) ($activeTools[$n]['mcpServerName'] ?? ''));
                    if ($server === null || empty($server['active'])) {
                        $jobs = [];
                        break;
                    }
                    $jobs[] = [
                        'server' => $server,
                        'toolName' => (string) ($activeTools[$n]['mcpToolName'] ?? $n),
                        'arguments' => $row['arguments'],
                    ];
                }
                if (count($jobs) === count($prepared)) {
                    $tryParallel = mcp_proxy_parallel_call_tools($jobs);
                    if (is_array($tryParallel) && count($tryParallel) === count($prepared)) {
                        $parallelMcpResults = $tryParallel;
                    }
                }
            }

            foreach ($prepared as $idx => $row) {
                $callId = $row['callId'];
                $functionName = $row['functionName'];
                $arguments = $row['arguments'];
                $normalizedFunctionName = $row['normalizedName'];
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
                    $executionState['activeSubAgentIds'],
                    $executionState['executionDetails']
                );
                $mcpOverride = null;
                if ($parallelMcpResults !== null && isset($parallelMcpResults[$idx])) {
                    $mcpOverride = $parallelMcpResults[$idx];
                }
                try {
                    $toolResult = executeToolCall($functionName, $arguments, $activeTools, $mcpOverride);
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
                $t = lastToolResultDisplayText($normalizedFunctionName, $toolResult);
                memory_graph_append_tool_fallback_summary($lastToolResultText, $t);
                $toolResultArr = is_array($toolResult) ? $toolResult : ['result' => $toolResult];
                $conversation[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'name' => normalizeToolName($functionName),
                    'content' => formatToolResultForModel($normalizedFunctionName, $toolResultArr, false),
                ];
                if (shouldInvalidateToolRegistryCache($normalizedFunctionName, $toolResult)) {
                    clearMemoryGraphToolRegistryCache();
                    $activeTools = loadToolRegistry();
                    $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
                }
                if ($normalizedFunctionName === 'set_provider_model' && is_array($toolResult) && !empty($toolResult['ok'])) {
                    $cfg = get_current_provider_model();
                    $providerKey = $cfg['provider'];
                    $model = $cfg['model'];
                    if (isset($providers[$providerKey])) {
                        $provider = $providers[$providerKey];
                        if ($providerKey === 'alibaba') {
                            $provider = memory_graph_alibaba_provider_runtime($provider);
                        }
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
                $executionState['activeSubAgentIds'],
                $executionState['executionDetails']
            );
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
            $t = lastToolResultDisplayText($normalizedInlineName, $toolResult);
            memory_graph_append_tool_fallback_summary($lastToolResultText, $t);
            $conversation[] = ['role' => 'assistant', 'content' => $assistantContent];
            $toolResultArr = is_array($toolResult) ? $toolResult : ['result' => $toolResult];
            $conversation[] = [
                'role' => 'user',
                'content' => formatToolResultForModel($inlineToolCall['name'], $toolResultArr, true),
            ];
            if (shouldInvalidateToolRegistryCache($inlineToolCall['name'], $toolResult)) {
                clearMemoryGraphToolRegistryCache();
                $activeTools = loadToolRegistry();
                $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
            }
            if ($inlineToolCall['name'] === 'set_provider_model' && is_array($toolResult) && !empty($toolResult['ok'])) {
                $cfg = get_current_provider_model();
                $providerKey = $cfg['provider'];
                $model = $cfg['model'];
                if (isset($providers[$providerKey])) {
                    $provider = $providers[$providerKey];
                    if ($providerKey === 'alibaba') {
                        $provider = memory_graph_alibaba_provider_runtime($provider);
                    }
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
            $executionState['activeSubAgentIds'],
            $executionState['executionDetails']
        );
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
        $t = lastToolResultDisplayText($normalizedInlineName, $toolResult);
        memory_graph_append_tool_fallback_summary($lastToolResultText, $t);
        $conversation[] = ['role' => 'assistant', 'content' => $assistantContent];
        $toolResultArr = is_array($toolResult) ? $toolResult : ['result' => $toolResult];
        $conversation[] = [
            'role' => 'user',
            'content' => formatToolResultForModel($inlineToolCall['name'], $toolResultArr, true),
        ];
        if (shouldInvalidateToolRegistryCache($inlineToolCall['name'], $toolResult)) {
            clearMemoryGraphToolRegistryCache();
            $activeTools = loadToolRegistry();
            $openAiTools = $provider['type'] === 'openai' ? buildOpenAiTools($activeTools) : [];
        }
        if ($inlineToolCall['name'] === 'set_provider_model' && is_array($toolResult) && !empty($toolResult['ok'])) {
            $cfg = get_current_provider_model();
            $providerKey = $cfg['provider'];
            $model = $cfg['model'];
            if (isset($providers[$providerKey])) {
                $provider = $providers[$providerKey];
                if ($providerKey === 'alibaba') {
                    $provider = memory_graph_alibaba_provider_runtime($provider);
                }
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

if (trim($finalContent) === '' && $lastToolResultText !== '') {
    $finalContent = $lastToolResultText;
}

$synthesizedFromTools = false;
if (trim($finalContent) === '') {
    $forced = memory_graph_forced_reply_from_tool_messages($conversation, $requestId);
    if ($forced !== '') {
        $finalContent = $forced;
        $synthesizedFromTools = true;
    }
}

$memoryGraphMeta = [];
if ($synthesizedFromTools) {
    $memoryGraphMeta['synthesized_from_tools'] = true;
}
if (trim($finalContent) === '') {
    $memoryGraphMeta['empty_assistant'] = true;
    $memoryGraphMeta['hint'] = 'The model returned no visible assistant text. Check provider, model ID, API key, and logs. If the job only used tools, ensure research/memory tools are enabled. Request ID: ' . $requestId;
    $finalContent = $memoryGraphMeta['hint'];
}

clearStatusFlags($status);
clearCurrentExecutionStatus($status, $requestId);
writeStatus($requestId, $status);

$finalContent = normalizeAssistantFormatting((string) $finalContent);
if (trim($finalContent) === '') {
    $finalContent = 'No assistant text was produced after tools and fallbacks. Request ID: ' . $requestId;
    $memoryGraphMeta['empty_assistant'] = true;
    $memoryGraphMeta['hint'] = $finalContent;
}

$memoryGraphMeta['assistant_body'] = (string) $finalContent;

$wroteChatTranscriptMemory = false;
if ($initialUserContent !== '' && trim($finalContent) !== '' && empty($memoryGraphMeta['empty_assistant']) && empty($input['skipChatHistoryAppend'])) {
    append_chat_exchange($requestId, $initialUserContent, $finalContent, $chatSessionIdInput);
    $trMeta = append_session_chat_transcript($chatSessionIdInput, $initialUserContent, $finalContent, $requestId);
    if (!empty($trMeta['name'])) {
        $memoryGraphMeta['chat_transcript_memory'] = (string) $trMeta['name'];
        $wroteChatTranscriptMemory = true;
    }
}

$response = [
    'choices' => [
        [
            'message' => [
                'role' => 'assistant',
                'content' => (string) $finalContent,
            ],
        ],
    ],
    'request_id' => $requestId,
];
if (!empty($memoryGraphMeta)) {
    $response['memory_graph'] = $memoryGraphMeta;
}
if (!empty($jobsToRun)) {
    $response['jobToRun'] = $jobsToRun;
}
$waOpen = $GLOBALS['MEMORY_GRAPH_WEB_APP_OPEN'] ?? null;
if (is_array($waOpen) && !empty($waOpen['display_web_app']) && empty($waOpen['error'])) {
    $response['web_app'] = [
        'name' => (string) ($waOpen['name'] ?? ''),
        'title' => (string) ($waOpen['title'] ?? ''),
        'url' => (string) ($waOpen['url'] ?? ''),
    ];
}
if (!empty($status['graphRefreshNeeded']) || $wroteChatTranscriptMemory) {
    $response['graphRefreshNeeded'] = true;
}
if (!empty($GLOBALS['MEMORY_GRAPH_WEB_APPS_LIST_DIRTY'])) {
    $response['reloadWebAppsList'] = true;
}
if (!empty($GLOBALS['MEMORY_GRAPH_CRON_PENDING_PATHS']) && !$skipCronPendingDelivery) {
    mg_cron_pending_delete_paths($GLOBALS['MEMORY_GRAPH_CRON_PENDING_PATHS']);
    $GLOBALS['MEMORY_GRAPH_CRON_PENDING_PATHS'] = [];
}
$jsonFlags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$jsonOut = json_encode($response, $jsonFlags);
if ($jsonOut === false) {
    $response['choices'][0]['message']['content'] = '[Server: could not encode response as JSON — possible invalid UTF-8 in assistant text. Request ID: ' . $requestId . ']';
    $jsonOut = json_encode($response, $jsonFlags);
}
echo ($jsonOut !== false) ? $jsonOut : '{"error":"json_encode failed"}';
} // end if (!$memoryGraphChatLibraryOnly) main chat HTTP handler
