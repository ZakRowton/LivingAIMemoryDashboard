<?php

function sub_agents_dir_path(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'sub-agents';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function sub_agent_runtime_dir_path(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'sub-agents';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function normalize_sub_agent_filename(string $name): string {
    $name = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name));
    $name = basename($name);
    if ($name === '') {
        return '';
    }
    if (strtolower(substr($name, -3)) !== '.md') {
        $name .= '.md';
    }
    return $name;
}

function sub_agent_node_id(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $base));
    $slug = trim($slug, '_');
    return 'sub_agent_file_' . ($slug !== '' ? $slug : 'agent');
}

function sub_agent_parse_markdown_config(string $markdown): array {
    $config = [
        'system_prompt' => '',
        'model' => '',
        'provider' => '',
        'api_key' => '',
        'endpoint' => '',
        'chat_type' => '',
        'temperature' => 0.7,
        'dashboard_url' => '',
    ];

    $raw = str_replace("\r\n", "\n", (string) $markdown);
    $trimmed = trim($raw);

    if (preg_match('/^\s*---\n([\s\S]*?)\n---\n?([\s\S]*)$/', $trimmed, $m) === 1) {
        $front = (string) ($m[1] ?? '');
        $body = (string) ($m[2] ?? '');
        foreach (preg_split('/\n/', $front) as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = explode(':', $line, 2);
            $key = strtolower(trim($k));
            $val = trim((string) $v, " \t\n\r\0\x0B\"'");
            if ($key === '') continue;
            if ($key === 'system_prompt' || $key === 'systemprompt') $config['system_prompt'] = $val;
            if ($key === 'model') $config['model'] = $val;
            if ($key === 'provider') $config['provider'] = $val;
            if ($key === 'api_key' || $key === 'apikey') $config['api_key'] = $val;
            if ($key === 'endpoint') $config['endpoint'] = $val;
            if ($key === 'chat_type' || $key === 'chattype') $config['chat_type'] = $val;
            if ($key === 'temperature') $config['temperature'] = is_numeric($val) ? (float) $val : 0.7;
            if ($key === 'dashboard_url' || $key === 'dashboard_link' || $key === 'app_link' || $key === 'link') {
                $config['dashboard_url'] = $val;
            }
        }
        if ($config['system_prompt'] === '' && trim($body) !== '') {
            $config['system_prompt'] = trim($body);
        }
        return $config;
    }

    foreach (preg_split('/\n/', $raw) as $line) {
        if (strpos($line, ':') === false) continue;
        [$k, $v] = explode(':', $line, 2);
        $key = strtolower(trim($k));
        $val = trim((string) $v, " \t\n\r\0\x0B\"'");
        if ($key === '') continue;
        if ($key === 'system_prompt' || $key === 'systemprompt') $config['system_prompt'] = $val;
        if ($key === 'model') $config['model'] = $val;
        if ($key === 'provider') $config['provider'] = $val;
        if ($key === 'api_key' || $key === 'apikey') $config['api_key'] = $val;
        if ($key === 'endpoint') $config['endpoint'] = $val;
        if ($key === 'chat_type' || $key === 'chattype') $config['chat_type'] = $val;
        if ($key === 'temperature') $config['temperature'] = is_numeric($val) ? (float) $val : 0.7;
        if ($key === 'dashboard_url' || $key === 'dashboard_link' || $key === 'app_link' || $key === 'link') {
            $config['dashboard_url'] = $val;
        }
    }
    if ($config['chat_type'] === '') $config['chat_type'] = 'openai';
    return $config;
}

function sub_agent_markdown_from_config(array $config): string {
    $temperature = isset($config['temperature']) && is_numeric($config['temperature']) ? (float) $config['temperature'] : 0.7;
    $systemPrompt = (string) ($config['system_prompt'] ?? '');
    $lines = [
        '---',
        'provider: ' . (string) ($config['provider'] ?? ''),
        'model: ' . (string) ($config['model'] ?? ''),
        'api_key: ' . (string) ($config['api_key'] ?? ''),
        'endpoint: ' . (string) ($config['endpoint'] ?? ''),
        'chat_type: ' . (string) ($config['chat_type'] ?? 'openai'),
        'temperature: ' . rtrim(rtrim(number_format($temperature, 4, '.', ''), '0'), '.'),
        'dashboard_url: ' . (string) ($config['dashboard_url'] ?? ''),
        'system_prompt: ' . str_replace("\n", ' ', $systemPrompt),
        '---',
        '',
    ];
    if (trim($systemPrompt) !== '') {
        $lines[] = trim($systemPrompt);
        $lines[] = '';
    }
    return implode("\n", $lines);
}

function list_sub_agent_files_meta(bool $includeContent = false): array {
    $dir = sub_agents_dir_path();
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [];
    $rows = [];
    foreach ($files as $path) {
        $filename = basename($path);
        $content = (string) file_get_contents($path);
        $parsed = sub_agent_parse_markdown_config($content);
        $rows[] = [
            'name' => $filename,
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'nodeId' => sub_agent_node_id($filename),
            'provider' => (string) ($parsed['provider'] ?? ''),
            'model' => (string) ($parsed['model'] ?? ''),
            'chat_type' => (string) ($parsed['chat_type'] ?? ''),
            'temperature' => isset($parsed['temperature']) ? (float) $parsed['temperature'] : 0.7,
            'dashboard_url' => (string) ($parsed['dashboard_url'] ?? ''),
            'content' => $includeContent ? $content : null,
        ];
    }
    usort($rows, function ($a, $b) {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
    if (!$includeContent) {
        foreach ($rows as &$r) unset($r['content']);
        unset($r);
    }
    return $rows;
}

function get_sub_agent_meta(string $name): ?array {
    $filename = normalize_sub_agent_filename($name);
    if ($filename === '') return null;
    $path = sub_agents_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) return null;
    $content = (string) file_get_contents($path);
    $parsed = sub_agent_parse_markdown_config($content);
    return [
        'name' => $filename,
        'title' => pathinfo($filename, PATHINFO_FILENAME),
        'nodeId' => sub_agent_node_id($filename),
        'config' => $parsed,
        'content' => $content,
    ];
}

function write_sub_agent_file(string $name, string $content): array {
    $filename = normalize_sub_agent_filename($name);
    if ($filename === '') return ['error' => 'Invalid sub-agent file name'];
    file_put_contents(sub_agents_dir_path() . DIRECTORY_SEPARATOR . $filename, $content);
    return get_sub_agent_meta($filename) ?? ['error' => 'Failed to read saved sub-agent'];
}

function create_sub_agent_file(string $name, string $content): array {
    $filename = normalize_sub_agent_filename($name);
    if ($filename === '') return ['error' => 'Invalid sub-agent file name'];
    $path = sub_agents_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) return ['error' => 'Sub-agent file already exists'];
    return write_sub_agent_file($filename, $content);
}

function update_sub_agent_file(string $name, string $content): array {
    $filename = normalize_sub_agent_filename($name);
    if ($filename === '') return ['error' => 'Invalid sub-agent file name'];
    $path = sub_agents_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) return ['error' => 'Sub-agent file not found'];
    return write_sub_agent_file($filename, $content);
}

function delete_sub_agent_file_by_name(string $name): array {
    $filename = normalize_sub_agent_filename($name);
    if ($filename === '') return ['error' => 'Invalid sub-agent file name'];
    $path = sub_agents_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) return ['error' => 'Sub-agent file not found'];
    @unlink($path);
    return ['deleted' => true, 'name' => $filename, 'nodeId' => sub_agent_node_id($filename)];
}

function sub_agent_task_path(string $taskId): string {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $taskId);
    return sub_agent_runtime_dir_path() . DIRECTORY_SEPARATOR . $safe . '.json';
}

function create_sub_agent_task(string $taskId, array $payload): array {
    $path = sub_agent_task_path($taskId);
    $doc = [
        'taskId' => $taskId,
        'status' => 'queued',
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'payload' => $payload,
        'result' => null,
        'error' => null,
    ];
    file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $doc;
}

function get_sub_agent_task(string $taskId): ?array {
    $path = sub_agent_task_path($taskId);
    if (!is_file($path)) return null;
    $raw = (string) file_get_contents($path);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function update_sub_agent_task(string $taskId, array $updates): array {
    $task = get_sub_agent_task($taskId);
    if (!is_array($task)) {
        $task = [
            'taskId' => $taskId,
            'status' => 'queued',
            'createdAt' => date('c'),
        ];
    }
    foreach ($updates as $k => $v) $task[$k] = $v;
    $task['updatedAt'] = date('c');
    file_put_contents(sub_agent_task_path($taskId), json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $task;
}
