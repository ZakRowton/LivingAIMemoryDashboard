<?php

function rules_dir_path(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'rules';
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

function normalize_rules_filename(string $name): string {
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

function rules_node_id(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $base));
    $slug = trim((string) $slug, '_');
    return 'rules_file_' . ($slug !== '' ? $slug : 'rules');
}

function list_rules_files_meta(): array {
    $dir = rules_dir_path();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [];
    $result = [];
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $result[] = [
            'name' => $filename,
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'nodeId' => rules_node_id($filename),
            'content' => (string) file_get_contents($filePath),
        ];
    }
    usort($result, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $result;
}

function get_rules_meta(string $name): ?array {
    $filename = normalize_rules_filename($name);
    if ($filename === '') {
        return null;
    }
    foreach (list_rules_files_meta() as $rules) {
        if (strcasecmp($rules['name'], $filename) === 0) {
            return $rules;
        }
    }
    return null;
}

function write_rules_file(string $name, string $content): array {
    $filename = normalize_rules_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid rules file name'];
    }
    $path = rules_dir_path() . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $content);
    return get_rules_meta($filename) ?? [
        'name' => $filename,
        'title' => pathinfo($filename, PATHINFO_FILENAME),
        'nodeId' => rules_node_id($filename),
        'content' => $content,
    ];
}

function create_rules_file(string $name, string $content): array {
    $filename = normalize_rules_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid rules file name'];
    }
    $path = rules_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($path)) {
        return ['error' => 'Rules file already exists'];
    }
    return write_rules_file($filename, $content);
}

function update_rules_file(string $name, string $content): array {
    $filename = normalize_rules_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid rules file name'];
    }
    $path = rules_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Rules file not found'];
    }
    return write_rules_file($filename, $content);
}

function delete_rules_file_by_name(string $name): array {
    $filename = normalize_rules_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid rules file name'];
    }
    $path = rules_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Rules file not found'];
    }
    unlink($path);
    return ['deleted' => $filename];
}
