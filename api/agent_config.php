<?php
/**
 * API for agent provider/model config: get current selection and provider list, set selection, add provider/model.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env.php';
memory_graph_load_env();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'provider_config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(get_providers_for_ui());
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = isset($input['action']) ? (string) $input['action'] : '';

switch ($action) {
    case 'set_selection':
        $provider = isset($input['provider']) ? (string) $input['provider'] : '';
        $model    = isset($input['model']) ? (string) $input['model'] : '';
        if ($provider === '') {
            echo json_encode(['error' => 'provider is required']);
            exit;
        }
        echo json_encode(set_current_provider_model($provider, $model));
        break;

    case 'add_provider':
        $key   = isset($input['key']) ? (string) $input['key'] : '';
        $name  = isset($input['name']) ? (string) $input['name'] : $key;
        $endpoint = isset($input['endpoint']) ? (string) $input['endpoint'] : (isset($input['endpointBase']) ? (string) $input['endpointBase'] : '');
        $type  = isset($input['type']) ? (string) $input['type'] : 'openai';
        $defaultModel = isset($input['defaultModel']) ? (string) $input['defaultModel'] : '';
        $envVar = isset($input['envVar']) ? (string) $input['envVar'] : '';
        if ($key === '') {
            echo json_encode(['error' => 'key is required']);
            exit;
        }
        echo json_encode(add_custom_provider($key, $name, $endpoint, $type, $defaultModel, $envVar));
        break;

    case 'add_model':
        $providerKey = isset($input['providerKey']) ? (string) $input['providerKey'] : (isset($input['provider']) ? (string) $input['provider'] : '');
        $modelId    = isset($input['modelId']) ? (string) $input['modelId'] : (isset($input['model']) ? (string) $input['model'] : '');
        if ($providerKey === '' || $modelId === '') {
            echo json_encode(['error' => 'providerKey and modelId are required']);
            exit;
        }
        echo json_encode(add_model_to_provider($providerKey, $modelId));
        break;

    case 'remove_model':
        $providerKey = isset($input['providerKey']) ? (string) $input['providerKey'] : (isset($input['provider']) ? (string) $input['provider'] : '');
        $modelId    = isset($input['modelId']) ? (string) $input['modelId'] : (isset($input['model']) ? (string) $input['model'] : '');
        if ($providerKey === '' || $modelId === '') {
            echo json_encode(['error' => 'providerKey and modelId are required']);
            exit;
        }
        echo json_encode(remove_model_from_provider($providerKey, $modelId));
        break;

    case 'set_system_prompt':
        $provider = isset($input['provider']) ? (string) $input['provider'] : '';
        $model = isset($input['model']) ? (string) $input['model'] : '';
        $prompt = isset($input['systemPrompt']) ? (string) $input['systemPrompt'] : '';
        echo json_encode(set_system_prompt_for_provider_model($provider, $model, $prompt));
        break;

    case 'set_provider_api_key':
        $provider = isset($input['provider']) ? (string) $input['provider'] : '';
        $apiKey = isset($input['apiKey']) ? (string) $input['apiKey'] : '';
        if ($provider === '') {
            echo json_encode(['error' => 'provider is required']);
            exit;
        }
        echo json_encode(set_provider_api_key($provider, $apiKey));
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use set_selection, add_provider, add_model, remove_model, set_system_prompt, or set_provider_api_key']);
}
