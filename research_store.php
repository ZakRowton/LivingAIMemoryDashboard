<?php

function research_dir_path(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'research';
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

function normalize_research_filename(string $name): string {
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

function research_node_id(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $base));
    $slug = trim((string) $slug, '_');
    return 'research_file_' . ($slug !== '' ? $slug : 'research');
}

function list_research_files_meta(): array {
    $dir = research_dir_path();
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
            'nodeId' => research_node_id($filename),
            'content' => (string) file_get_contents($filePath),
        ];
    }
    usort($result, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $result;
}

function get_research_meta(string $name): ?array {
    $filename = normalize_research_filename($name);
    if ($filename === '') {
        return null;
    }
    foreach (list_research_files_meta() as $research) {
        if (strcasecmp($research['name'], $filename) === 0) {
            return $research;
        }
    }
    return null;
}

function write_research_file(string $name, string $content): array {
    $filename = normalize_research_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid research file name'];
    }
    $path = research_dir_path() . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $content);
    return get_research_meta($filename) ?? [
        'name' => $filename,
        'title' => pathinfo($filename, PATHINFO_FILENAME),
        'nodeId' => research_node_id($filename),
        'content' => $content,
    ];
}

function create_research_file(string $name, string $content): array {
    $filename = normalize_research_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid research file name'];
    }
    $path = research_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($path)) {
        return ['error' => 'Research file already exists'];
    }
    return write_research_file($filename, $content);
}

function update_research_file(string $name, string $content): array {
    $filename = normalize_research_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid research file name'];
    }
    $path = research_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Research file not found'];
    }
    return write_research_file($filename, $content);
}

function delete_research_file_by_name(string $name): array {
    $filename = normalize_research_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid research file name'];
    }
    $path = research_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Research file not found'];
    }
    unlink($path);
    return ['deleted' => $filename];
}
