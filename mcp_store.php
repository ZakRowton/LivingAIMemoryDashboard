<?php

function mcp_expanded_tools_snapshot_path(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'mcp-expanded-tools.json';
}

function mcp_notify_registry_changed(): void {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'mcp_client.php';
    if (function_exists('mcp_invalidate_tools_list_disk_cache')) {
        mcp_invalidate_tools_list_disk_cache();
    }
    if (function_exists('mcp_proxy_post_invalidate')) {
        mcp_proxy_post_invalidate(null);
    }
    @unlink(mcp_expanded_tools_snapshot_path());
}

function mcp_registry_path(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'mcp_servers.json';
}

function sanitize_mcp_server_slug(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    $name = trim((string) $name, '_-');
    return strtolower((string) $name);
}

function mcp_server_node_id(string $name): string {
    return 'mcp_server_' . sanitize_mcp_server_slug($name);
}

function normalize_mcp_transport($transport): string {
    $transport = strtolower(trim((string) $transport));
    if ($transport === '') {
        return 'stdio';
    }
    return $transport;
}

function normalize_string_list($value): array {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        }
    }
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $item) {
        if (is_scalar($item) || $item === null) {
            $out[] = (string) $item;
        }
    }
    return array_values($out);
}

function normalize_string_map($value): array {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        }
    }
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $key => $item) {
        if (!is_string($key) || trim($key) === '') {
            continue;
        }
        if (is_scalar($item) || $item === null) {
            $out[$key] = (string) $item;
        }
    }
    return $out;
}

function normalize_mcp_server_record(array $server): array {
    $name = trim((string) ($server['name'] ?? $server['title'] ?? ''));
    if ($name === '') {
        return [];
    }
    $slug = sanitize_mcp_server_slug($name);
    if ($slug === '') {
        return [];
    }
    $command = trim((string) ($server['command'] ?? ''));
    $url = trim((string) ($server['url'] ?? ''));
    $transport = normalize_mcp_transport($server['transport'] ?? '');
    if (($transport === '' || $transport === 'stdio') && $url !== '' && $command === '') {
        $transport = 'streamablehttp';
    }
    return [
        'name' => $name,
        'slug' => $slug,
        'title' => trim((string) ($server['title'] ?? '')) !== '' ? trim((string) ($server['title'] ?? '')) : $name,
        'description' => (string) ($server['description'] ?? ''),
        'nodeId' => mcp_server_node_id($name),
        'active' => array_key_exists('active', $server) ? !empty($server['active']) : true,
        'transport' => $transport !== '' ? $transport : 'stdio',
        'command' => $command,
        'args' => normalize_string_list($server['args'] ?? []),
        'env' => normalize_string_map($server['env'] ?? []),
        'cwd' => trim((string) ($server['cwd'] ?? '')),
        'url' => $url,
        'headers' => normalize_string_map($server['headers'] ?? []),
    ];
}

function read_mcp_registry_data(): array {
    $path = mcp_registry_path();
    $data = file_exists($path) ? json_decode((string) file_get_contents($path), true) : ['servers' => []];
    if (!is_array($data) || !isset($data['servers']) || !is_array($data['servers'])) {
        return ['servers' => []];
    }
    $servers = [];
    foreach ($data['servers'] as $server) {
        if (!is_array($server)) {
            continue;
        }
        $normalized = normalize_mcp_server_record($server);
        if ($normalized === []) {
            continue;
        }
        $servers[] = $normalized;
    }
    return ['servers' => $servers];
}

function save_mcp_registry_data(array $data): void {
    $servers = [];
    if (isset($data['servers']) && is_array($data['servers'])) {
        foreach ($data['servers'] as $server) {
            if (!is_array($server)) {
                continue;
            }
            $normalized = normalize_mcp_server_record($server);
            if ($normalized === []) {
                continue;
            }
            $servers[] = $normalized;
        }
    }
    file_put_contents(mcp_registry_path(), json_encode(['servers' => array_values($servers)], JSON_PRETTY_PRINT));
}

function list_mcp_servers_meta(): array {
    $data = read_mcp_registry_data();
    return array_values($data['servers'] ?? []);
}

function get_mcp_server_meta(string $name): ?array {
    $slug = sanitize_mcp_server_slug($name);
    if ($slug === '') {
        return null;
    }
    foreach (list_mcp_servers_meta() as $server) {
        if (($server['slug'] ?? '') === $slug || sanitize_mcp_server_slug((string) ($server['name'] ?? '')) === $slug) {
            return $server;
        }
    }
    return null;
}

function upsert_mcp_server_artifact(array $serverData, string $originalName = ''): array {
    $normalized = normalize_mcp_server_record($serverData);
    if ($normalized === []) {
        return ['error' => 'Invalid MCP server name'];
    }
    if ($normalized['transport'] === 'stdio' && $normalized['command'] === '') {
        return ['error' => 'A stdio MCP server requires a command'];
    }
    if ($normalized['transport'] === 'streamablehttp' && trim($normalized['url']) === '') {
        return ['error' => 'A streamablehttp MCP server requires a non-empty url (the remote MCP HTTP endpoint)'];
    }

    $originalSlug = sanitize_mcp_server_slug($originalName !== '' ? $originalName : $normalized['name']);
    $data = read_mcp_registry_data();
    $updated = false;

    foreach ($data['servers'] as $index => $server) {
        $serverSlug = sanitize_mcp_server_slug((string) ($server['name'] ?? ''));
        if ($serverSlug === $normalized['slug'] && $serverSlug !== $originalSlug) {
            return ['error' => 'An MCP server with that name already exists'];
        }
        if ($serverSlug === $originalSlug) {
            $data['servers'][$index] = $normalized;
            $updated = true;
        }
    }

    if (!$updated) {
        $data['servers'][] = $normalized;
    }

    save_mcp_registry_data($data);
    mcp_notify_registry_changed();
    $normalized['success'] = true;
    $normalized['__mcp_registry_changed'] = true;
    return $normalized;
}

function configure_mcp_server_artifact(string $name, array $changes): array {
    $server = get_mcp_server_meta($name);
    if ($server === null) {
        return ['error' => 'MCP server not found'];
    }
    $merged = $server;
    foreach (['name', 'description', 'transport', 'command', 'cwd', 'url'] as $field) {
        if (array_key_exists($field, $changes)) {
            $merged[$field] = $changes[$field];
        }
    }
    foreach (['active', 'args', 'env', 'headers'] as $field) {
        if (array_key_exists($field, $changes)) {
            $merged[$field] = $changes[$field];
        }
    }
    return upsert_mcp_server_artifact($merged, (string) ($server['name'] ?? $name));
}

function set_mcp_server_env_var_artifact(string $name, string $key, string $value): array {
    $server = get_mcp_server_meta($name);
    if ($server === null) {
        return ['error' => 'MCP server not found'];
    }
    $env = isset($server['env']) && is_array($server['env']) ? $server['env'] : [];
    $cleanKey = trim($key);
    if ($cleanKey === '') {
        return ['error' => 'Invalid MCP env key'];
    }
    $env[$cleanKey] = $value;
    return configure_mcp_server_artifact((string) $server['name'], ['env' => $env]);
}

function remove_mcp_server_env_var_artifact(string $name, string $key): array {
    $server = get_mcp_server_meta($name);
    if ($server === null) {
        return ['error' => 'MCP server not found'];
    }
    $env = isset($server['env']) && is_array($server['env']) ? $server['env'] : [];
    $cleanKey = trim($key);
    if ($cleanKey === '') {
        return ['error' => 'Invalid MCP env key'];
    }
    unset($env[$cleanKey]);
    return configure_mcp_server_artifact((string) $server['name'], ['env' => $env]);
}

function set_mcp_server_header_artifact(string $name, string $key, string $value): array {
    $server = get_mcp_server_meta($name);
    if ($server === null) {
        return ['error' => 'MCP server not found'];
    }
    $headers = isset($server['headers']) && is_array($server['headers']) ? $server['headers'] : [];
    $cleanKey = trim($key);
    if ($cleanKey === '') {
        return ['error' => 'Invalid MCP header key'];
    }
    $headers[$cleanKey] = $value;
    return configure_mcp_server_artifact((string) $server['name'], ['headers' => $headers]);
}

function remove_mcp_server_header_artifact(string $name, string $key): array {
    $server = get_mcp_server_meta($name);
    if ($server === null) {
        return ['error' => 'MCP server not found'];
    }
    $headers = isset($server['headers']) && is_array($server['headers']) ? $server['headers'] : [];
    $cleanKey = trim($key);
    if ($cleanKey === '') {
        return ['error' => 'Invalid MCP header key'];
    }
    unset($headers[$cleanKey]);
    return configure_mcp_server_artifact((string) $server['name'], ['headers' => $headers]);
}

function delete_mcp_server_artifact(string $name): array {
    $slug = sanitize_mcp_server_slug($name);
    if ($slug === '') {
        return ['error' => 'Invalid MCP server name'];
    }
    $data = read_mcp_registry_data();
    $deleted = null;
    $data['servers'] = array_values(array_filter($data['servers'], function ($server) use ($slug, &$deleted) {
        $serverSlug = sanitize_mcp_server_slug((string) ($server['name'] ?? ''));
        if ($serverSlug === $slug) {
            $deleted = $server;
            return false;
        }
        return true;
    }));
    if ($deleted === null) {
        return ['error' => 'MCP server not found'];
    }
    save_mcp_registry_data($data);
    mcp_notify_registry_changed();
    return [
        'success' => true,
        'name' => $deleted['name'],
        'slug' => $deleted['slug'],
        'nodeId' => $deleted['nodeId'],
        '__mcp_registry_changed' => true,
    ];
}

function set_mcp_server_active_artifact(string $name, bool $active): array {
    $slug = sanitize_mcp_server_slug($name);
    if ($slug === '') {
        return ['error' => 'Invalid MCP server name'];
    }
    $data = read_mcp_registry_data();
    $updated = null;
    foreach ($data['servers'] as &$server) {
        $serverSlug = sanitize_mcp_server_slug((string) ($server['name'] ?? ''));
        if ($serverSlug !== $slug) {
            continue;
        }
        $server['active'] = $active;
        $updated = normalize_mcp_server_record($server);
        break;
    }
    unset($server);
    if ($updated === null) {
        return ['error' => 'MCP server not found'];
    }
    save_mcp_registry_data($data);
    mcp_notify_registry_changed();
    $updated['success'] = true;
    $updated['__mcp_registry_changed'] = true;
    return $updated;
}

function set_all_mcp_servers_active_artifact(bool $active): array {
    $data = read_mcp_registry_data();
    foreach ($data['servers'] as &$server) {
        $server['active'] = $active;
    }
    unset($server);
    save_mcp_registry_data($data);
    mcp_notify_registry_changed();
    return [
        'success' => true,
        'active' => $active,
        'servers' => list_mcp_servers_meta(),
        '__mcp_registry_changed' => true,
    ];
}

function list_active_mcp_servers_meta(): array {
    return array_values(array_filter(list_mcp_servers_meta(), function ($server) {
        return !empty($server['active']);
    }));
}

function mcp_exposed_tool_name(string $serverName, string $toolName): string {
    return 'mcp__' . sanitize_mcp_server_slug($serverName) . '__' . sanitize_mcp_server_slug($toolName);
}
