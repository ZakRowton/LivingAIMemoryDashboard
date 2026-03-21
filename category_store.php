<?php

function category_registry_path(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'categories.json';
}

function category_node_id(string $name): string {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name)));
    $slug = trim((string) $slug, '_');
    return 'category_' . ($slug !== '' ? $slug : 'category');
}

function normalize_category_name(string $name): string {
    $name = trim($name);
    return $name === '' ? '' : $name;
}

function list_category_nodes_meta(): array {
    $path = category_registry_path();
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['categories']) || !is_array($data['categories'])) {
        return [];
    }
    $result = [];
    foreach ($data['categories'] as $cat) {
        if (!is_array($cat) || empty($cat['name'])) {
            continue;
        }
        $result[] = [
            'name' => (string) $cat['name'],
            'title' => trim((string) ($cat['title'] ?? $cat['name'])) !== '' ? trim((string) ($cat['title'] ?? $cat['name'])) : (string) $cat['name'],
            'nodeId' => category_node_id((string) $cat['name']),
            'description' => (string) ($cat['description'] ?? ''),
        ];
    }
    usort($result, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $result;
}

function get_category_meta(string $name): ?array {
    $name = normalize_category_name($name);
    if ($name === '') {
        return null;
    }
    foreach (list_category_nodes_meta() as $cat) {
        if (strcasecmp($cat['name'], $name) === 0) {
            return $cat;
        }
    }
    return null;
}

function create_category_node(string $name, string $title = '', string $description = ''): array {
    $name = normalize_category_name($name);
    if ($name === '') {
        return ['error' => 'Category name is required'];
    }
    if (get_category_meta($name) !== null) {
        return ['error' => 'Category already exists'];
    }
    $title = trim($title) !== '' ? trim($title) : $name;
    $path = category_registry_path();
    $data = file_exists($path) ? json_decode((string) file_get_contents($path), true) : ['categories' => []];
    $data = is_array($data) ? $data : ['categories' => []];
    if (!isset($data['categories']) || !is_array($data['categories'])) {
        $data['categories'] = [];
    }
    $data['categories'][] = [
        'name' => $name,
        'title' => $title,
        'description' => trim($description),
    ];
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if ($json === false) {
        return ['error' => 'Failed to encode categories data'];
    }
    if (@file_put_contents($path, $json) === false) {
        return ['error' => 'Failed to write categories file. Check runtime/ directory permissions.'];
    }
    return get_category_meta($name) ?? [
        'name' => $name,
        'title' => $title,
        'nodeId' => category_node_id($name),
        'description' => trim($description),
    ];
}

function delete_category_node_by_name(string $name): array {
    $name = normalize_category_name($name);
    if ($name === '') {
        return ['error' => 'Category name is required'];
    }
    $path = category_registry_path();
    if (!file_exists($path)) {
        return ['error' => 'Category not found'];
    }
    $data = json_decode((string) file_get_contents($path), true);
    $data = is_array($data) ? $data : ['categories' => []];
    if (!isset($data['categories']) || !is_array($data['categories'])) {
        return ['error' => 'Category not found'];
    }
    $before = count($data['categories']);
    $data['categories'] = array_values(array_filter($data['categories'], function ($c) use ($name) {
        return !isset($c['name']) || strcasecmp((string) $c['name'], $name) !== 0;
    }));
    if (count($data['categories']) === $before) {
        return ['error' => 'Category not found'];
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    return ['deleted' => $name];
}
