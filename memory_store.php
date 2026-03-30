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

/**
 * Hidden memories: excluded from 3D graph, default memory list, and system-prompt merge.
 * Still readable via read_memory_file (for transcript archives).
 */
function memory_meta_hidden(string $filename, array $state): bool {
    if (array_key_exists('hidden', $state[$filename] ?? [])) {
        return !empty($state[$filename]['hidden']);
    }
    $base = pathinfo($filename, PATHINFO_FILENAME);
    return is_string($base) && preg_match('/^_chat_/i', $base) === 1;
}

/** Include file body in merged system prompt (rules + memory section). */
function memory_should_merge_into_prompt(string $filename, array $state): bool {
    $active = array_key_exists($filename, $state) ? !empty($state[$filename]['active']) : true;
    if (!$active) {
        return false;
    }
    return !memory_meta_hidden($filename, $state);
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
        $hidden = memory_meta_hidden($filename, $state);
        $row = [
            'name' => $filename,
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'active' => array_key_exists($filename, $state) ? !empty($state[$filename]['active']) : true,
            'hidden' => $hidden,
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
        $state[$filename] = ['active' => true, 'hidden' => false];
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
    $prev = isset($state[$filename]) && is_array($state[$filename]) ? $state[$filename] : [];
    $state[$filename] = array_merge($prev, ['active' => $active]);
    save_memory_state($state);
    $meta = get_memory_meta($filename);
    return $meta ?? ['name' => $filename, 'title' => pathinfo($filename, PATHINFO_FILENAME), 'active' => $active, 'nodeId' => memory_node_id($filename)];
}

function set_memory_hidden_state(string $name, bool $hidden): array {
    $filename = normalize_memory_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid memory file name'];
    }
    $path = memory_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Memory file not found'];
    }
    $state = load_memory_state();
    $prev = isset($state[$filename]) && is_array($state[$filename]) ? $state[$filename] : [];
    $state[$filename] = array_merge($prev, ['hidden' => $hidden]);
    save_memory_state($state);
    return get_memory_meta($filename) ?? ['error' => 'Failed to read memory'];
}

const MEMORY_GRAPH_CHAT_TRANSCRIPT_MAX_BYTES = 480000;

/**
 * Append a user/assistant turn to a per-session markdown file under memory/.
 * Files are named _chat_session_<slug>.md, marked hidden (not merged into prompt, not on graph).
 */
function append_session_chat_transcript(string $sessionId, string $userContent, string $assistantContent, string $requestId): array {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($sessionId)));
    if ($slug === '') {
        $slug = 'default';
    }
    $slug = substr($slug, 0, 56);
    $filename = '_chat_session_' . $slug . '.md';
    $dir = memory_dir_path();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = $dir . DIRECTORY_SEPARATOR . $filename;

    $userContent = str_replace(["\r\n", "\r"], "\n", $userContent);
    $assistantContent = str_replace(["\r\n", "\r"], "\n", $assistantContent);
    $ts = gmdate('c');
    $block = "\n\n---\n**request_id:** `" . str_replace('`', '', $requestId) . "`  \n**time (UTC):** {$ts}\n\n### User\n\n" . $userContent
        . "\n\n### Assistant\n\n" . $assistantContent . "\n";

    $state = load_memory_state();
    $prev = isset($state[$filename]) && is_array($state[$filename]) ? $state[$filename] : [];
    $state[$filename] = array_merge($prev, [
        'active' => false,
        'hidden' => true,
    ]);
    save_memory_state($state);

    $header = "# Chat transcript\n\nSession slug: `{$slug}`  \nThis file is **hidden**: not merged into the default system prompt and not shown on the memory graph. Use `read_memory_file` with name `{$filename}` when prior context is needed.\n";

    if (!is_file($path)) {
        file_put_contents($path, $header . $block);
    } else {
        $existing = (string) file_get_contents($path);
        $existing .= $block;
        $max = MEMORY_GRAPH_CHAT_TRANSCRIPT_MAX_BYTES;
        if (strlen($existing) > $max) {
            $tail = (int) ($max * 0.88);
            $chunk = substr($existing, -$tail);
            $cut = strpos($chunk, "\n---\n");
            if ($cut !== false && $cut < 400) {
                $chunk = substr($chunk, $cut + 1);
            }
            $existing = "# Chat transcript\n\n*(Older turns removed to cap file size.)*\n\n" . ltrim($chunk);
        }
        file_put_contents($path, $existing);
    }

    return ['name' => $filename, 'nodeId' => memory_node_id($filename), 'hidden' => true];
}
