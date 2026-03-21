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
    return [
        'mercury'   => ['name' => 'Mercury (Inception Labs)', 'models' => ['mercury-2']],
        'featherless' => ['name' => 'Featherless', 'models' => ['glm47-flash']],
        'alibaba'   => ['name' => 'Alibaba Cloud', 'models' => ['qwen-plus']],
        'gemini'    => ['name' => 'Gemini (Google)', 'models' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0', 'gemini-3-flash-preview', 'gemini-3-pro-preview', 'gemini-3-flash', 'gemini-3-pro', 'gemini-3.1-flash-preview', 'gemini-3.1-pro-preview']],
    ];
}

function get_agent_provider_config(): array {
    $path = agent_provider_config_path();
    $default = [
        'currentProvider' => 'mercury',
        'currentModel'    => 'mercury-2',
        'customProviders' => [],
        'customModels'    => [],
        'systemPromptsByModel' => [],
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
    if (!isset($config['systemPromptsByModel']) || !is_array($config['systemPromptsByModel'])) {
        $config['systemPromptsByModel'] = [];
    }
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

/** Returns providers list for UI: { currentProvider, currentModel, providers: { key: { name, models } } } */
function get_providers_for_ui(): array {
    $config = get_agent_provider_config();
    $builtin = get_builtin_provider_ui();
    $custom  = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];
    $customModels = isset($config['customModels']) && is_array($config['customModels']) ? $config['customModels'] : [];

    $providers = $builtin;
    foreach ($custom as $key => $def) {
        $name = isset($def['name']) ? (string) $def['name'] : $key;
        $models = [isset($def['defaultModel']) ? (string) $def['defaultModel'] : 'default'];
        if (isset($customModels[$key]) && is_array($customModels[$key])) {
            $models = array_values(array_unique(array_merge($models, $customModels[$key])));
        }
        $providers[$key] = ['name' => $name, 'models' => $models];
    }
    foreach ($customModels as $key => $list) {
        if (!isset($providers[$key])) {
            continue;
        }
        if (is_array($list)) {
            $providers[$key]['models'] = array_values(array_unique(array_merge($providers[$key]['models'] ?? [], $list)));
        }
    }

    return [
        'currentProvider' => (string) ($config['currentProvider'] ?? 'mercury'),
        'currentModel'    => (string) ($config['currentModel'] ?? 'mercury-2'),
        'providers'       => $providers,
        'systemPromptsByModel' => get_system_prompts_by_model(),
    ];
}

/** List providers and models for AI (same shape + custom provider definitions for reference). */
function list_providers_models_for_tool(): array {
    $ui = get_providers_for_ui();
    $config = get_agent_provider_config();
    $ui['customProviderDefinitions'] = isset($config['customProviders']) ? $config['customProviders'] : [];
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
    if (!save_agent_provider_config($config)) {
        return ['error' => 'Failed to save config'];
    }
    return ['ok' => true, 'provider' => $providerKey, 'model' => $modelId];
}

/** Return custom provider definitions for chat.php to merge (with apiKey resolved via env). */
function get_custom_provider_definitions_for_chat(): array {
    $config = get_agent_provider_config();
    $custom = isset($config['customProviders']) && is_array($config['customProviders']) ? $config['customProviders'] : [];
    $out = [];
    foreach ($custom as $key => $def) {
        $envVar = isset($def['envVar']) ? (string) $def['envVar'] : (strtoupper($key) . '_API_KEY');
        $apiKey = function_exists('memory_graph_env') ? (memory_graph_env($envVar, '') ?? '') : (getenv($envVar) ?: '');
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
