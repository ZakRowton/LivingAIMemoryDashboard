<?php
/**
 * Persistent chat session archives under sessions/*.json for AI-managed history + graph links.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'memory_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'instruction_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'research_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'rules_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'job_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mcp_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sub_agent_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tool_store.php';

function session_dir_path(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

function normalize_session_filename(string $name): string {
    $name = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name));
    $name = basename($name);
    if ($name === '') {
        return '';
    }
    if (strtolower(substr($name, -5)) !== '.json') {
        $name .= '.json';
    }
    return $name;
}

function session_node_id(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $base));
    $slug = trim((string) $slug, '_');
    return 'session_file_' . ($slug !== '' ? $slug : 'session');
}

/**
 * @param array<string, mixed> $refs
 * @return array<int, array{to:string}>
 */
function session_resolve_reference_targets(array $refs): array {
    $out = [];
    $add = static function (string $to) use (&$out): void {
        $to = trim($to);
        if ($to !== '') {
            $out[] = ['to' => $to];
        }
    };

    $tools = isset($refs['tools']) && is_array($refs['tools']) ? $refs['tools'] : [];
    foreach ($tools as $t) {
        $t = is_string($t) ? trim($t) : '';
        if ($t === '') {
            continue;
        }
        $add('tool_' . sanitize_tool_name($t));
    }
    $mem = isset($refs['memory_files']) && is_array($refs['memory_files']) ? $refs['memory_files'] : [];
    foreach ($mem as $f) {
        $f = is_string($f) ? normalize_memory_filename($f) : '';
        if ($f === '') {
            continue;
        }
        $add(memory_node_id($f));
    }
    $ins = isset($refs['instruction_files']) && is_array($refs['instruction_files']) ? $refs['instruction_files'] : [];
    foreach ($ins as $f) {
        $f = is_string($f) ? normalize_instruction_filename($f) : '';
        if ($f === '') {
            continue;
        }
        $add(instruction_node_id($f));
    }
    $res = isset($refs['research_files']) && is_array($refs['research_files']) ? $refs['research_files'] : [];
    foreach ($res as $f) {
        $f = is_string($f) ? trim($f) : '';
        if ($f === '') {
            continue;
        }
        $add(research_node_id($f));
    }
    $rules = isset($refs['rules_files']) && is_array($refs['rules_files']) ? $refs['rules_files'] : [];
    foreach ($rules as $f) {
        $f = is_string($f) ? trim($f) : '';
        if ($f === '') {
            continue;
        }
        $add(rules_node_id($f));
    }
    $mcps = isset($refs['mcp_servers']) && is_array($refs['mcp_servers']) ? $refs['mcp_servers'] : [];
    foreach ($mcps as $n) {
        $n = is_string($n) ? trim($n) : '';
        if ($n === '') {
            continue;
        }
        $add(mcp_server_node_id($n));
    }
    $jobs = isset($refs['job_files']) && is_array($refs['job_files']) ? $refs['job_files'] : [];
    foreach ($jobs as $f) {
        $f = is_string($f) ? trim($f) : '';
        if ($f === '') {
            continue;
        }
        $add(job_node_id($f));
    }
    $subs = isset($refs['sub_agent_files']) && is_array($refs['sub_agent_files']) ? $refs['sub_agent_files'] : [];
    foreach ($subs as $f) {
        $f = is_string($f) ? normalize_sub_agent_filename($f) : '';
        if ($f === '') {
            continue;
        }
        $add(sub_agent_node_id($f));
    }

    $seen = [];
    $uniq = [];
    foreach ($out as $row) {
        $t = $row['to'];
        if (isset($seen[$t])) {
            continue;
        }
        $seen[$t] = true;
        $uniq[] = $row;
    }

    return $uniq;
}

/**
 * Default empty session document.
 *
 * @return array<string, mixed>
 */
function session_default_document(string $title = ''): array {
    $now = gmdate('c');
    return [
        'meta' => [
            'title' => $title !== '' ? $title : 'Untitled session',
            'tags' => [],
            'summary' => '',
            'references' => [
                'tools' => [],
                'memory_files' => [],
                'instruction_files' => [],
                'research_files' => [],
                'rules_files' => [],
                'mcp_servers' => [],
                'job_files' => [],
                'sub_agent_files' => [],
            ],
            'createdAt' => $now,
            'updatedAt' => $now,
        ],
        'turns' => [],
    ];
}

/**
 * @param array<string, mixed> $data
 */
function session_normalize_document(array $data): array {
    $def = session_default_document();
    if (!isset($data['meta']) || !is_array($data['meta'])) {
        $data['meta'] = $def['meta'];
    }
    $m = &$data['meta'];
    foreach (['title', 'summary'] as $k) {
        if (!isset($m[$k])) {
            $m[$k] = $def['meta'][$k];
        }
        $m[$k] = is_string($m[$k]) ? $m[$k] : (string) $m[$k];
    }
    if (!isset($m['tags']) || !is_array($m['tags'])) {
        $m['tags'] = [];
    }
    $m['tags'] = array_values(array_filter(array_map(static function ($t) {
        return is_string($t) ? strtolower(trim($t)) : '';
    }, $m['tags'])));
    if (!isset($m['references']) || !is_array($m['references'])) {
        $m['references'] = $def['meta']['references'];
    }
    $ref = &$m['references'];
    foreach (['tools', 'memory_files', 'instruction_files', 'research_files', 'rules_files', 'mcp_servers', 'job_files', 'sub_agent_files'] as $rk) {
        if (!isset($ref[$rk]) || !is_array($ref[$rk])) {
            $ref[$rk] = [];
        }
        $ref[$rk] = array_values(array_filter(array_map(static function ($x) {
            return is_string($x) ? trim($x) : '';
        }, $ref[$rk])));
    }
    foreach (['createdAt', 'updatedAt'] as $tk) {
        if (empty($m[$tk]) || !is_string($m[$tk])) {
            $m[$tk] = gmdate('c');
        }
    }
    if (!isset($data['turns']) || !is_array($data['turns'])) {
        $data['turns'] = [];
    }

    return $data;
}

function memorygraph_session_json_encode(array $data): string {
    $data = session_normalize_document($data);
    $data['meta']['updatedAt'] = gmdate('c');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : '{}';
}

/**
 * @return array{name:string, title:string, nodeId:string, tags:array, summary:string, updatedAt:string, linkTargets:string[], hidden?:bool}|null
 */
function get_session_meta(string $name): ?array {
    $filename = normalize_session_filename($name);
    if ($filename === '') {
        return null;
    }
    $path = session_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return null;
    }
    $raw = (string) file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $data = session_normalize_document($data);
    $refs = isset($data['meta']['references']) && is_array($data['meta']['references']) ? $data['meta']['references'] : [];
    $resolved = session_resolve_reference_targets($refs);
    $linkTargets = array_values(array_map(static function ($r) {
        return $r['to'];
    }, $resolved));

    return [
        'name' => $filename,
        'title' => (string) ($data['meta']['title'] ?? pathinfo($filename, PATHINFO_FILENAME)),
        'nodeId' => session_node_id($filename),
        'tags' => isset($data['meta']['tags']) && is_array($data['meta']['tags']) ? array_values($data['meta']['tags']) : [],
        'summary' => (string) ($data['meta']['summary'] ?? ''),
        'updatedAt' => (string) ($data['meta']['updatedAt'] ?? ''),
        'linkTargets' => $linkTargets,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function read_session_document(string $name): ?array {
    $filename = normalize_session_filename($name);
    if ($filename === '') {
        return null;
    }
    $path = session_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return null;
    }
    $raw = (string) file_get_contents($path);
    $data = json_decode($raw, true);

    return is_array($data) ? session_normalize_document($data) : null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function list_session_files_meta(bool $includeContent = false): array {
    $dir = session_dir_path();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $result = [];
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $raw = (string) file_get_contents($filePath);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }
        $decoded = session_normalize_document($decoded);
        $refs = isset($decoded['meta']['references']) && is_array($decoded['meta']['references']) ? $decoded['meta']['references'] : [];
        $resolved = session_resolve_reference_targets($refs);
        $linkTargets = array_values(array_map(static function ($r) {
            return $r['to'];
        }, $resolved));
        $meta = [
            'name' => $filename,
            'title' => (string) ($decoded['meta']['title'] ?? pathinfo($filename, PATHINFO_FILENAME)),
            'nodeId' => session_node_id($filename),
            'tags' => isset($decoded['meta']['tags']) && is_array($decoded['meta']['tags']) ? array_values($decoded['meta']['tags']) : [],
            'summary' => (string) ($decoded['meta']['summary'] ?? ''),
            'updatedAt' => (string) ($decoded['meta']['updatedAt'] ?? ''),
            'linkTargets' => $linkTargets,
        ];
        if ($includeContent) {
            $meta['content'] = $decoded;
        }
        $result[] = $meta;
    }
    usort($result, static function ($a, $b) {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $result;
}

/**
 * @param array<string, mixed>|null $initial
 * @return array<string, mixed>
 */
function create_session_file(string $name, ?array $initial = null): array {
    $filename = normalize_session_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid session file name'];
    }
    $path = session_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($path)) {
        return ['error' => 'Session file already exists', 'name' => $filename];
    }
    $title = '';
    if ($initial !== null && isset($initial['meta']) && is_array($initial['meta']) && isset($initial['meta']['title'])) {
        $title = (string) $initial['meta']['title'];
    }
    $doc = session_default_document($title);
    if ($initial !== null && is_array($initial)) {
        if (isset($initial['meta']) && is_array($initial['meta'])) {
            $doc['meta'] = array_replace_recursive($doc['meta'], $initial['meta']);
        }
        if (isset($initial['turns']) && is_array($initial['turns'])) {
            $doc['turns'] = $initial['turns'];
        }
    }
    $doc = session_normalize_document($doc);
    if (file_put_contents($path, memorygraph_session_json_encode($doc)) === false) {
        return ['error' => 'Failed to write session file'];
    }
    $meta = get_session_meta($filename);
    if ($meta !== null) {
        $meta['ok'] = true;
        return $meta;
    }

    return ['ok' => true, 'name' => $filename, 'nodeId' => session_node_id($filename)];
}

/**
 * @return array<string, mixed>
 */
function write_session_file(string $name, array $content): array {
    $filename = normalize_session_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid session file name'];
    }
    $path = session_dir_path() . DIRECTORY_SEPARATOR . $filename;
    $content = session_normalize_document($content);
    if (file_put_contents($path, memorygraph_session_json_encode($content)) === false) {
        return ['error' => 'Failed to write session file'];
    }

    return get_session_meta($filename) ?? ['ok' => true, 'name' => $filename];
}

/**
 * Append turns and optionally patch meta (tags, summary, references merged).
 *
 * @param array<int, array<string, mixed>> $turnsToAppend
 * @param array<string, mixed> $metaPatch
 * @return array<string, mixed>
 */
function append_session_turns(string $name, array $turnsToAppend, array $metaPatch = []): array {
    $filename = normalize_session_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid session file name'];
    }
    $path = session_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return ['error' => 'Session file not found'];
    }
    $raw = (string) file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['error' => 'Invalid session JSON'];
    }
    $data = session_normalize_document($data);
    foreach ($turnsToAppend as $t) {
        if (!is_array($t)) {
            continue;
        }
        $role = isset($t['role']) ? (string) $t['role'] : '';
        if ($role !== 'user' && $role !== 'assistant' && $role !== 'system') {
            continue;
        }
        $content = $t['content'] ?? '';
        if (!is_string($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        $data['turns'][] = ['role' => $role, 'content' => $content];
    }
    if ($metaPatch !== []) {
        if (isset($metaPatch['title'])) {
            $data['meta']['title'] = (string) $metaPatch['title'];
        }
        if (isset($metaPatch['summary'])) {
            $data['meta']['summary'] = (string) $metaPatch['summary'];
        }
        if (isset($metaPatch['tags']) && is_array($metaPatch['tags'])) {
            $merge = array_merge($data['meta']['tags'] ?? [], $metaPatch['tags']);
            $data['meta']['tags'] = array_values(array_unique(array_filter(array_map(static function ($x) {
                return is_string($x) ? strtolower(trim($x)) : '';
            }, $merge))));
        }
        if (isset($metaPatch['references']) && is_array($metaPatch['references'])) {
            $base = $data['meta']['references'];
            foreach ($metaPatch['references'] as $k => $vals) {
                if (!is_array($vals)) {
                    continue;
                }
                if (!isset($base[$k]) || !is_array($base[$k])) {
                    $base[$k] = [];
                }
                $base[$k] = array_values(array_unique(array_merge($base[$k], $vals)));
            }
            $data['meta']['references'] = $base;
        }
    }
    if (file_put_contents($path, memorygraph_session_json_encode($data)) === false) {
        return ['error' => 'Failed to save session file'];
    }

    return [
        'ok' => true,
        'name' => $filename,
        'nodeId' => session_node_id($filename),
        'turnCount' => count($data['turns']),
        'meta' => $data['meta'],
    ];
}

/**
 * Replace meta or full turns (optional). Merges references/tags when merging arrays.
 *
 * @param array<string, mixed> $metaPatch
 * @param array<int, array<string, mixed>>|null $replaceTurns if set, replaces turns entirely
 * @return array<string, mixed>
 */
function patch_session_document(string $name, array $metaPatch = [], ?array $replaceTurns = null): array {
    $filename = normalize_session_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid session file name'];
    }
    $path = session_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return ['error' => 'Session file not found'];
    }
    $raw = (string) file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['error' => 'Invalid session JSON'];
    }
    $data = session_normalize_document($data);
    foreach ($metaPatch as $k => $v) {
        if ($k === 'references' && is_array($v)) {
            $base = $data['meta']['references'];
            foreach ($v as $rk => $vals) {
                if (!is_array($vals)) {
                    continue;
                }
                if (!isset($base[$rk]) || !is_array($base[$rk])) {
                    $base[$rk] = [];
                }
                $base[$rk] = array_values(array_unique(array_merge($base[$rk], array_map('strval', $vals))));
            }
            $data['meta']['references'] = $base;
        } elseif ($k === 'tags' && is_array($v)) {
            $merge = array_merge($data['meta']['tags'] ?? [], $v);
            $data['meta']['tags'] = array_values(array_unique(array_filter(array_map(static function ($x) {
                return is_string($x) ? strtolower(trim($x)) : '';
            }, $merge))));
        } elseif ($k === 'title' || $k === 'summary') {
            $data['meta'][$k] = is_string($v) ? $v : (string) $v;
        }
    }
    if ($replaceTurns !== null) {
        $data['turns'] = array_values($replaceTurns);
    }
    if (file_put_contents($path, memorygraph_session_json_encode($data)) === false) {
        return ['error' => 'Failed to save session file'];
    }

    return [
        'ok' => true,
        'name' => $filename,
        'nodeId' => session_node_id($filename),
        'meta' => $data['meta'],
    ];
}

function delete_session_file_by_name(string $name): array {
    $filename = normalize_session_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid session file name'];
    }
    $path = session_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return ['error' => 'Session file not found'];
    }
    if (!@unlink($path)) {
        return ['error' => 'Failed to delete session file'];
    }

    return ['deleted' => true, 'name' => $filename];
}

/**
 * Search sessions by tags (any match) and/or text query (summary + serialized turns).
 *
 * @param array<int, string> $tagsAny
 * @return array<int, array<string, mixed>>
 */
function search_session_files(array $tagsAny = [], string $query = '', int $limit = 25): array {
    $limit = max(1, min(100, $limit));
    $tagsAny = array_values(array_filter(array_map(static function ($t) {
        return is_string($t) ? strtolower(trim($t)) : '';
    }, $tagsAny)));
    $query = strtolower(trim($query));
    $all = list_session_files_meta(true);
    $scored = [];
    foreach ($all as $row) {
        $full = $row['content'] ?? null;
        unset($row['content']);
        if (!is_array($full)) {
            continue;
        }
        $meta = isset($full['meta']) && is_array($full['meta']) ? $full['meta'] : [];
        $fileTags = isset($meta['tags']) && is_array($meta['tags']) ? $meta['tags'] : [];
        $summary = strtolower((string) ($meta['summary'] ?? ''));
        $title = strtolower((string) ($meta['title'] ?? ''));
        $blob = $summary . "\n" . $title . "\n";
        $blob .= json_encode($full['turns'] ?? [], JSON_UNESCAPED_UNICODE);
        $blob = strtolower($blob);

        $score = 0;
        if ($tagsAny !== []) {
            $hit = false;
            foreach ($tagsAny as $tg) {
                if ($tg !== '' && in_array($tg, $fileTags, true)) {
                    $hit = true;
                    $score += 10;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
        }
        if ($query !== '') {
            if (strpos($blob, $query) === false && strpos($summary, $query) === false) {
                if ($tagsAny === []) {
                    continue;
                }
            } else {
                $score += 5;
            }
        } elseif ($tagsAny === [] && $query === '') {
            $score = 1;
        }
        $scored[] = ['score' => $score, 'row' => $row];
    }
    usort($scored, static function ($a, $b) {
        return ($b['score'] <=> $a['score']) ?: strcasecmp((string) ($a['row']['name'] ?? ''), (string) ($b['row']['name'] ?? ''));
    });
    $out = [];
    foreach ($scored as $i => $item) {
        if ($i >= $limit) {
            break;
        }
        $out[] = $item['row'];
    }

    return $out;
}
