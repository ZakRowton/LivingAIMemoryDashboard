<?php

function memory_dir_path(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'memory';
}

function memory_state_path(): string {
    return memory_dir_path() . DIRECTORY_SEPARATOR . '_memory_state.json';
}

function memory_node_id(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $base));
    $slug = trim($slug, '_');
    return 'memory_file_' . ($slug !== '' ? $slug : 'memory');
}

function normalize_memory_filename(string $name): string {
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

function load_memory_state(): array {
    $path = memory_state_path();
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function save_memory_state(array $state): void {
    file_put_contents(memory_state_path(), json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * @param bool $includeContent If false, skips reading file bodies (fast for list_memory_files tool / graph list).
 */
function list_memory_files_meta(bool $includeContent = false): array {
    $dir = memory_dir_path();
    if (!is_dir($dir)) {
        return [];
    }
    $state = load_memory_state();
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [];
    $result = [];
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $row = [
            'name' => $filename,
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'active' => array_key_exists($filename, $state) ? !empty($state[$filename]['active']) : true,
            'nodeId' => memory_node_id($filename),
        ];
        if ($includeContent) {
            $row['content'] = (string) file_get_contents($filePath);
        }
        $result[] = $row;
    }
    usort($result, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $result;
}

function get_memory_meta(string $name): ?array {
    $filename = normalize_memory_filename($name);
    if ($filename === '') {
        return null;
    }
    $path = memory_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return null;
    }
    $state = load_memory_state();
    return [
        'name' => $filename,
        'title' => pathinfo($filename, PATHINFO_FILENAME),
        'active' => array_key_exists($filename, $state) ? !empty($state[$filename]['active']) : true,
        'nodeId' => memory_node_id($filename),
        'content' => (string) file_get_contents($path),
    ];
}

function write_memory_file(string $name, string $content): array {
    $filename = normalize_memory_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid memory file name'];
    }
    $path = memory_dir_path() . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $content);
    $state = load_memory_state();
    if (!isset($state[$filename])) {
        $state[$filename] = ['active' => true];
        save_memory_state($state);
    }
    return get_memory_meta($filename) ?? ['name' => $filename, 'title' => pathinfo($filename, PATHINFO_FILENAME), 'active' => true, 'nodeId' => memory_node_id($filename), 'content' => $content];
}

function create_memory_file(string $name, string $content): array {
    $filename = normalize_memory_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid memory file name'];
    }
    $path = memory_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($path)) {
        return ['error' => 'Memory file already exists'];
    }
    return write_memory_file($filename, $content);
}

function update_memory_file(string $name, string $content): array {
    $filename = normalize_memory_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid memory file name'];
    }
    $path = memory_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Memory file not found'];
    }
    return write_memory_file($filename, $content);
}

function delete_memory_file_by_name(string $name): array {
    $filename = normalize_memory_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid memory file name'];
    }
    $path = memory_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Memory file not found'];
    }
    unlink($path);
    $state = load_memory_state();
    unset($state[$filename]);
    save_memory_state($state);
    return ['deleted' => true, 'name' => $filename, 'nodeId' => memory_node_id($filename)];
}

function set_memory_active_state(string $name, bool $active): array {
    $filename = normalize_memory_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid memory file name'];
    }
    $path = memory_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Memory file not found'];
    }
    $state = load_memory_state();
    $state[$filename] = ['active' => $active];
    save_memory_state($state);
    $meta = get_memory_meta($filename);
    return $meta ?? ['name' => $filename, 'title' => pathinfo($filename, PATHINFO_FILENAME), 'active' => $active, 'nodeId' => memory_node_id($filename)];
}
