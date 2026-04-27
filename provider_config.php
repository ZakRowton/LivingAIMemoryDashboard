<?php
/**
 * Agent provider/model configuration: current selection, custom providers, custom models.
 * Persisted in config/agent_config.json so the AI can change provider/model and add providers/models.
 */

if (!function_exists('agent_provider_config_path')) {
    function agent_provider_config_path(): string {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'config';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'agent_config.json';
    }
}

/** Built-in provider keys and their UI display names / default models (for list). */
function get_builtin_provider_ui(): array {
    $openRouterModel = trim((string) ($_ENV['OPENROUTER_MODEL'] ?? getenv('OPENROUTER_MODEL') ?: 'google/gemma-4-31b-it:free'));
    if ($openRouterModel === '') {
        $openRouterModel = 'google/gemma-4-31b-it:free';
    }
    $nvidiaNimModel = trim((string) ($_ENV['NVIDIA_NIM_MODEL'] ?? getenv('NVIDIA_NIM_MODEL') ?: 'deepseek-ai/deepseek-v4-flash'));
    if ($nvidiaNimModel === '') {
        $nvidiaNimModel = 'deepseek-ai/deepseek-v4-flash';
    }
    return [
        'mercury'   => ['name' => 'Mercury (Inception Labs)', 'models' => ['mercury-2']],
        'featherless' => ['name' => 'Featherless', 'models' => ['glm47-flash']],
        'alibaba'   => ['name' => 'Alibaba Cloud', 'models' => ['qwen-plus', 'glm-5']],
        'gemini'    => ['name' => 'Gemini (Google)', 'models' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0', 'gemini-3-flash-preview', 'gemini-3-pro-preview', 'gemini-3-flash', 'gemini-3-pro', 'gemini-3.1-flash-preview', 'gemini-3.1-pro-preview']],
        'openrouter' => ['name' => 'OpenRouter', 'models' => [$openRouterModel]],
        'nvidia_nim' => ['name' => 'NVIDIA NIM', 'models' => [
            $nvidiaNimModel,
            'deepseek-ai/deepseek-v4-pro',
            'nvidia/nemotron-voicechat',
            'z-ai/glm-4.7',
            'minimaxai/minimax-m2.7',
            'mistralai/devstral-2-123b-instruct-2512',
        ]],
    ];
}

function get_agent_provider_config(): array {
    $path = agent_provider_config_path();
    $default = [
        'currentProvider' => 'mercury',
        'currentModel'    => 'mercury-2',
        'customProviders' => [],
        'customModels'    => [],
        'excludedBuiltinModels' => [],
        'systemPromptsByModel' => [],
        'systemInstructionFilesByModel' => [],
        'providerApiKeys' => [],
    ];
    if (!file_exists($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return $default;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $default;
    }
    return array_merge($default, $decoded);
}

function save_agent_provider_config(array $config): bool {
    $path = agent_provider_config_path();
    $config['customProviders'] = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];
    $config['customModels']    = isset($config['customModels']) && is_array($config['customModels']) ? $config['customModels'] : [];
    $config['excludedBuiltinModels'] = isset($config['excludedBuiltinModels']) && is_array($config['excludedBuiltinModels']) ? $config['excludedBuiltinModels'] : [];
    if (!isset($config['systemPromptsByModel']) || !is_array($config['systemPromptsByModel'])) {
        $config['systemPromptsByModel'] = [];
    }
    $pkRaw = isset($config['providerApiKeys']) && is_array($config['providerApiKeys']) ? $config['providerApiKeys'] : [];
    $pkClean = [];
    foreach ($pkRaw as $k => $v) {
        $kk = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $k);
        if ($kk === '' || !is_string($v)) {
            continue;
        }
        $vv = trim($v);
        if ($vv !== '') {
            $pkClean[$kk] = $vv;
        }
    }
    $config['providerApiKeys'] = $pkClean;
    $sifRaw = isset($config['systemInstructionFilesByModel']) && is_array($config['systemInstructionFilesByModel']) ? $config['systemInstructionFilesByModel'] : [];
    $sifClean = [];
    foreach ($sifRaw as $k => $v) {
        if (!is_string($k) || !is_string($v)) {
            continue;
        }
        $kk = trim($k);
        $colonPos = strpos($kk, ':');
        if ($colonPos === false || $colonPos < 1) {
            continue;
        }
        $pk = substr($kk, 0, $colonPos);
        $mk = substr($kk, $colonPos + 1);
        if ($mk === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $pk) !== 1) {
            continue;
        }
        $vv = basename(trim(str_replace(['\\', '/'], '/', $v)));
        if ($vv === '') {
            continue;
        }
        if (strtolower(substr($vv, -3)) !== '.md') {
            $vv .= '.md';
        }
        $sifClean[$kk] = $vv;
    }
    $config['systemInstructionFilesByModel'] = $sifClean;
    return @file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

/** Map key: "provider:modelId" */
function get_system_prompts_by_model(): array {
    $c = get_agent_provider_config();
    $m = $c['systemPromptsByModel'] ?? [];
    return is_array($m) ? $m : [];
}

function set_system_prompt_for_provider_model(string $provider, string $model, string $text): array {
    $provider = preg_replace('/[^a-zA-Z0-9_\-]/', '', $provider);
    $model = trim($model);
    if ($provider === '' || $model === '') {
        return ['error' => 'provider and model are required'];
    }
    $key = $provider . ':' . $model;
    $config = get_agent_provider_config();
    if (!isset($config['systemPromptsByModel']) || !is_array($config['systemPromptsByModel'])) {
        $config['systemPromptsByModel'] = [];
    }
    $config['systemPromptsByModel'][$key] = $text;
    if (!save_agent_provider_config($config)) {
        return ['error' => 'Failed to save system prompt'];
    }
    return ['ok' => true, 'key' => $key];
}

/** Map key "provider:modelId" -> instruction filename under instructions/ */
function get_system_instruction_files_by_model(): array {
    $c = get_agent_provider_config();
    $m = $c['systemInstructionFilesByModel'] ?? [];
    return is_array($m) ? $m : [];
}

/**
 * Attach an instruction file as the per-provider/model system prompt source (replaces textarea for that pair).
 * Pass empty instructionFilename to clear.
 */
function set_system_instruction_file_for_provider_model(string $provider, string $model, string $instructionFilename): array {
    $provider = preg_replace('/[^a-zA-Z0-9_\-]/', '', $provider);
    $model = trim($model);
    if ($provider === '' || $model === '') {
        return ['error' => 'provider and model are required'];
    }
    $key = $provider . ':' . $model;
    $fn = trim($instructionFilename);
    if ($fn !== '') {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'instruction_store.php';
        $fn = normalize_instruction_filename($fn);
        if (get_instruction_meta($fn) === null) {
            return ['error' => 'Instruction file not found'];
        }
    } else {
        $fn = '';
    }
    $config = get_agent_provider_config();
    if (!isset($config['systemInstructionFilesByModel']) || !is_array($config['systemInstructionFilesByModel'])) {
        $config['systemInstructionFilesByModel'] = [];
    }
    if ($fn === '') {
        unset($config['systemInstructionFilesByModel'][$key]);
    } else {
        $config['systemInstructionFilesByModel'][$key] = $fn;
    }
    if (!save_agent_provider_config($config)) {
        return ['error' => 'Failed to save instruction file mapping'];
    }
    return ['ok' => true, 'key' => $key, 'instructionFile' => $fn];
}

/**
 * Merge built-in + custom provider defaults + customModels, then apply excludedBuiltinModels for that key.
 */
function get_merged_models_for_provider(string $providerKey, array $config): array {
    $providerKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $providerKey);
    $builtin = get_builtin_provider_ui();
    $models = [];
    if (isset($builtin[$providerKey]['models']) && is_array($builtin[$providerKey]['models'])) {
        $models = $builtin[$providerKey]['models'];
    }
    $custom = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];
    if (isset($custom[$providerKey]) && is_array($custom[$providerKey])) {
        $def = $custom[$providerKey];
        $models = [isset($def['defaultModel']) ? (string) $def['defaultModel'] : 'default'];
    }
    $customModels = isset($config['customModels'][$providerKey]) && is_array($config['customModels'][$providerKey])
        ? $config['customModels'][$providerKey]
        : [];
    $models = array_values(array_unique(array_merge($models, $customModels)));
    $excluded = isset($config['excludedBuiltinModels'][$providerKey]) && is_array($config['excludedBuiltinModels'][$providerKey])
        ? $config['excludedBuiltinModels'][$providerKey]
        : [];
    if ($excluded !== []) {
        $models = array_values(array_filter($models, function ($m) use ($excluded) {
            return !in_array($m, $excluded, true);
        }));
    }
    return $models;
}

function get_current_provider_model(): array {
    $config = get_agent_provider_config();
    return [
        'provider' => (string) ($config['currentProvider'] ?? 'mercury'),
        'model'    => (string) ($config['currentModel'] ?? 'mercury-2'),
    ];
}

function set_current_provider_model(string $provider, string $model): array {
    $config = get_agent_provider_config();
    $config['currentProvider'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $provider) ?: $config['currentProvider'];
    $config['currentModel']    = $model !== '' ? $model : $config['currentModel'];
    if (!save_agent_provider_config($config)) {
        return ['error' => 'Failed to save provider config'];
    }
    return ['ok' => true, 'provider' => $config['currentProvider'], 'model' => $config['currentModel']];
}

function get_provider_api_key_override(string $providerKey): string {
    $providerKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $providerKey);
    if ($providerKey === '') {
        return '';
    }
    $config = get_agent_provider_config();
    $map = isset($config['providerApiKeys']) && is_array($config['providerApiKeys']) ? $config['providerApiKeys'] : [];
    if (!isset($map[$providerKey]) || !is_string($map[$providerKey])) {
        return '';
    }
    return trim($map[$providerKey]);
}

/**
 * Persist API key override for a built-in or custom provider (stored in agent_config.json).
 * Empty apiKey removes the override so .env is used again.
 */
function set_provider_api_key(string $providerKey, string $apiKey): array {
    $providerKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $providerKey);
    if ($providerKey === '') {
        return ['error' => 'provider is required'];
    }
    $builtin = get_builtin_provider_ui();
    $config = get_agent_provider_config();
    $custom = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];
    if (!isset($builtin[$providerKey]) && !isset($custom[$providerKey])) {
        return ['error' => 'Unknown provider'];
    }
    $apiKey = trim($apiKey);
    if (!isset($config['providerApiKeys']) || !is_array($config['providerApiKeys'])) {
        $config['providerApiKeys'] = [];
    }
    if ($apiKey === '') {
        unset($config['providerApiKeys'][$providerKey]);
    } else {
        $config['providerApiKeys'][$providerKey] = $apiKey;
    }
    if (!save_agent_provider_config($config)) {
        return ['error' => 'Failed to save'];
    }
    return [
        'ok' => true,
        'provider' => $providerKey,
        'apiKeyStatus' => get_provider_api_key_ui_row($providerKey),
    ];
}

function provider_env_key_has_value(string $envKey): bool {
    if ($envKey === '' || !function_exists('memory_graph_env')) {
        return false;
    }
    $v = memory_graph_env($envKey, '');
    return $v !== null && trim((string) $v) !== '';
}

/** For UI: whether a key is configured (override or .env) and which source wins for requests. */
function get_provider_api_key_ui_row(string $providerKey): array {
    $providerKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $providerKey);
    if ($providerKey === '') {
        return ['configured' => false, 'source' => 'none'];
    }
    if (get_provider_api_key_override($providerKey) !== '') {
        return ['configured' => true, 'source' => 'override'];
    }
    $hasEnv = false;
    if ($providerKey === 'alibaba') {
        foreach (['DASHSCOPE_API_KEY', 'ALIBABA_API_KEY', 'ALIBABA_CLOUD_API_KEY', 'ALIYUN_API_KEY'] as $ek) {
            if (provider_env_key_has_value($ek)) {
                $hasEnv = true;
                break;
            }
        }
    } else {
        $config = get_agent_provider_config();
        $custom = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];
        if (isset($custom[$providerKey]) && is_array($custom[$providerKey])) {
            $ev = trim((string) ($custom[$providerKey]['envVar'] ?? ''));
            if ($ev === '') {
                $ev = strtoupper($providerKey) . '_API_KEY';
            }
            $hasEnv = provider_env_key_has_value($ev);
        } else {
            $map = [
                'mercury' => 'MERCURY_API_KEY',
                'featherless' => 'FEATHERLESS_API_KEY',
                'gemini' => 'GEMINI_API_KEY',
                'openrouter' => 'OPENROUTER_API_KEY',
                'nvidia_nim' => 'NVIDIA_NIM_API_KEY',
            ];
            $ek = $map[$providerKey] ?? '';
            if ($ek !== '') {
                $hasEnv = provider_env_key_has_value($ek);
            }
        }
    }
    return [
        'configured' => $hasEnv,
        'source' => $hasEnv ? 'env' : 'none',
    ];
}

/** Returns providers list for UI: { currentProvider, currentModel, providers: { key: { name, models } } } */
function get_providers_for_ui(): array {
    $config = get_agent_provider_config();
    $builtin = get_builtin_provider_ui();
    $custom  = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];

    $providers = $builtin;
    foreach ($custom as $key => $def) {
        $name = isset($def['name']) ? (string) $def['name'] : $key;
        $providers[$key] = ['name' => $name, 'models' => []];
    }
    foreach (array_keys($providers) as $key) {
        $providers[$key]['models'] = get_merged_models_for_provider($key, $config);
    }

    $apiKeyStatus = [];
    foreach (array_keys($providers) as $pkey) {
        $apiKeyStatus[$pkey] = get_provider_api_key_ui_row($pkey);
    }

    $sifm = get_system_instruction_files_by_model();
    // json_encode encodes [] as a JSON array; the UI expects a string-keyed object so empty maps are {}.
    if ($sifm === []) {
        $sifm = new \stdClass();
    }

    return [
        'currentProvider' => (string) ($config['currentProvider'] ?? 'mercury'),
        'currentModel'    => (string) ($config['currentModel'] ?? 'mercury-2'),
        'providers'       => $providers,
        'systemPromptsByModel' => get_system_prompts_by_model(),
        'systemInstructionFilesByModel' => $sifm,
        'providerApiKeyStatus' => $apiKeyStatus,
    ];
}

/** List providers and models for AI (same shape + custom provider definitions for reference). */
function list_providers_models_for_tool(): array {
    $ui = get_providers_for_ui();
    unset($ui['providerApiKeyStatus']);
    $config = get_agent_provider_config();
    $ui['customProviderDefinitions'] = isset($config['customProviders']) ? $config['customProviders'] : [];
    $ui['excludedBuiltinModels'] = isset($config['excludedBuiltinModels']) && is_array($config['excludedBuiltinModels'])
        ? $config['excludedBuiltinModels']
        : [];
    return $ui;
}

/** List available provider keys and display names only. */
function list_providers_available(): array {
    $ui = get_providers_for_ui();
    $providers = isset($ui['providers']) && is_array($ui['providers']) ? $ui['providers'] : [];
    $list = [];
    foreach ($providers as $key => $def) {
        $list[] = [
            'key'  => $key,
            'name' => isset($def['name']) ? (string) $def['name'] : $key,
        ];
    }
    return ['providers' => array_values($list)];
}

/** List model ids for a given provider. Returns error if provider not found. */
function list_models_for_provider(string $providerKey): array {
    $providerKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $providerKey);
    $ui = get_providers_for_ui();
    $providers = isset($ui['providers']) && is_array($ui['providers']) ? $ui['providers'] : [];
    if (!isset($providers[$providerKey])) {
        return ['error' => 'Provider not found', 'providerKey' => $providerKey];
    }
    $models = isset($providers[$providerKey]['models']) && is_array($providers[$providerKey]['models'])
        ? $providers[$providerKey]['models']
        : [];
    return [
        'providerKey' => $providerKey,
        'providerName' => isset($providers[$providerKey]['name']) ? (string) $providers[$providerKey]['name'] : $providerKey,
        'models' => array_values($models),
    ];
}

/** Add a new custom provider. key: id; name: display; endpoint or endpointBase; type: openai|gemini; defaultModel; envVar: .env key for API key. */
function add_custom_provider(string $key, string $name, string $endpointOrBase, string $type, string $defaultModel, string $envVar): array {
    $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);
    if ($key === '') {
        return ['error' => 'Provider key must be alphanumeric with optional underscores/dashes'];
    }
    $builtin = get_builtin_provider_ui();
    if (isset($builtin[$key])) {
        return ['error' => 'Cannot override built-in provider ' . $key];
    }
    $config = get_agent_provider_config();
    $custom = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];
    $entry = [
        'name'         => $name,
        'type'         => $type === 'gemini' ? 'gemini' : 'openai',
        'defaultModel' => $defaultModel,
        'envVar'       => $envVar !== '' ? $envVar : strtoupper($key) . '_API_KEY',
    ];
    if ($entry['type'] === 'gemini') {
        $entry['endpointBase'] = $endpointOrBase !== '' ? $endpointOrBase : 'https://generativelanguage.googleapis.com/v1beta/models';
    } else {
        $entry['endpoint'] = $endpointOrBase !== '' ? $endpointOrBase : '';
    }
    $custom[$key] = $entry;
    $config['customProviders'] = $custom;
    if (!save_agent_provider_config($config)) {
        return ['error' => 'Failed to save config'];
    }
    return ['ok' => true, 'provider' => $key, 'name' => $name];
}

/** Add a model id to a provider's model list (built-in or custom). */
function add_model_to_provider(string $providerKey, string $modelId): array {
    $providerKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $providerKey);
    $modelId = trim($modelId);
    if ($providerKey === '' || $modelId === '') {
        return ['error' => 'Provider key and model id are required'];
    }
    $config = get_agent_provider_config();
    $customModels = isset($config['customModels']) && is_array($config['customModels']) ? $config['customModels'] : [];
    if (!isset($customModels[$providerKey])) {
        $customModels[$providerKey] = [];
    }
    if (!in_array($modelId, $customModels[$providerKey], true)) {
        $customModels[$providerKey][] = $modelId;
    }
    $config['customModels'] = $customModels;
    $excluded = isset($config['excludedBuiltinModels']) && is_array($config['excludedBuiltinModels']) ? $config['excludedBuiltinModels'] : [];
    if (isset($excluded[$providerKey]) && is_array($excluded[$providerKey])) {
        $excluded[$providerKey] = array_values(array_filter($excluded[$providerKey], function ($m) use ($modelId) {
            return $m !== $modelId;
        }));
        if ($excluded[$providerKey] === []) {
            unset($excluded[$providerKey]);
        }
        $config['excludedBuiltinModels'] = $excluded;
    }
    if (!save_agent_provider_config($config)) {
        return ['error' => 'Failed to save config'];
    }
    return ['ok' => true, 'provider' => $providerKey, 'model' => $modelId];
}

/**
 * Remove a model from the selector: drops it from customModels if present; otherwise hides a built-in id via excludedBuiltinModels.
 */
function remove_model_from_provider(string $providerKey, string $modelId): array {
    $providerKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $providerKey);
    $modelId = trim($modelId);
    if ($providerKey === '' || $modelId === '') {
        return ['error' => 'Provider key and model id are required'];
    }
    $config = get_agent_provider_config();
    $before = get_merged_models_for_provider($providerKey, $config);
    if (!in_array($modelId, $before, true)) {
        return ['error' => 'That model id is not in the effective list for this provider (check spelling and provider key).'];
    }
    if (count($before) <= 1) {
        return ['error' => 'Cannot remove the last remaining model for this provider. Add another model first.'];
    }

    $customModels = isset($config['customModels']) && is_array($config['customModels']) ? $config['customModels'] : [];
    $removedFromCustom = false;
    if (isset($customModels[$providerKey]) && is_array($customModels[$providerKey])) {
        $idx = array_search($modelId, $customModels[$providerKey], true);
        if ($idx !== false) {
            array_splice($customModels[$providerKey], (int) $idx, 1);
            $removedFromCustom = true;
            if ($customModels[$providerKey] === []) {
                unset($customModels[$providerKey]);
            }
            $config['customModels'] = $customModels;
        }
    }

    $hiddenBuiltin = false;
    if (in_array($modelId, get_merged_models_for_provider($providerKey, $config), true)) {
        $excluded = isset($config['excludedBuiltinModels']) && is_array($config['excludedBuiltinModels']) ? $config['excludedBuiltinModels'] : [];
        if (!isset($excluded[$providerKey]) || !is_array($excluded[$providerKey])) {
            $excluded[$providerKey] = [];
        }
        if (!in_array($modelId, $excluded[$providerKey], true)) {
            $excluded[$providerKey][] = $modelId;
        }
        $excluded[$providerKey] = array_values(array_unique($excluded[$providerKey]));
        $config['excludedBuiltinModels'] = $excluded;
        $hiddenBuiltin = true;
    }

    $after = get_merged_models_for_provider($providerKey, $config);
    if ($after === []) {
        return ['error' => 'Cannot remove the last remaining model for this provider.'];
    }

    if (($config['currentProvider'] ?? '') === $providerKey && ($config['currentModel'] ?? '') === $modelId) {
        $config['currentModel'] = (string) ($after[0] ?? 'mercury-2');
    }

    if (!save_agent_provider_config($config)) {
        return ['error' => 'Failed to save config'];
    }
    return [
        'ok' => true,
        'provider' => $providerKey,
        'model' => $modelId,
        'removed' => true,
        'removed_from_custom_list' => $removedFromCustom,
        'hidden_builtin' => $hiddenBuiltin,
    ];
}

/** Return custom provider definitions for chat.php to merge (with apiKey resolved via env). */
function get_custom_provider_definitions_for_chat(): array {
    $config = get_agent_provider_config();
    $custom = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];
    $out = [];
    foreach ($custom as $key => $def) {
        $envVar = isset($def['envVar']) ? (string) $def['envVar'] : (strtoupper($key) . '_API_KEY');
        $apiKey = function_exists('memory_graph_env') ? (memory_graph_env($envVar, '') ?? '') : (getenv($envVar) ?: '');
        $ov = get_provider_api_key_override($key);
        if ($ov !== '') {
            $apiKey = $ov;
        }
        $entry = [
            'type'         => isset($def['type']) && $def['type'] === 'gemini' ? 'gemini' : 'openai',
            'defaultModel' => isset($def['defaultModel']) ? (string) $def['defaultModel'] : 'default',
            'apiKey'       => (string) $apiKey,
        ];
        if ($entry['type'] === 'gemini') {
            $entry['endpointBase'] = isset($def['endpointBase']) ? (string) $def['endpointBase'] : 'https://generativelanguage.googleapis.com/v1beta/models';
        } else {
            $entry['endpoint'] = isset($def['endpoint']) ? (string) $def['endpoint'] : '';
        }
        $out[$key] = $entry;
    }
    return $out;
}
