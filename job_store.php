<?php

function jobs_dir_path(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'jobs';
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

function normalize_job_filename(string $name): string {
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

function job_node_id(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $base));
    $slug = trim((string) $slug, '_');
    return 'job_file_' . ($slug !== '' ? $slug : 'job');
}

/**
 * @return array{runAssignee: string, subAgent: ?string, subAgentNodeId: ?string, body: string}
 */
function job_store_parse_file_header(string $raw): array {
    $out = [
        'runAssignee' => 'main',
        'subAgent' => null,
        'subAgentNodeId' => null,
        'body' => $raw,
    ];
    if (!str_starts_with(ltrim($raw, "\0\x0B \t\n\r"), '---')) {
        return $out;
    }
    $lines = preg_split("/\R/u", (string) $raw);
    if (count($lines) < 2) {
        return $out;
    }
    if (rtrim($lines[0] ?? '', "\r") !== '---') {
        return $out;
    }
    $meta = [];
    $i = 1;
    for ($n = count($lines); $i < $n; $i++) {
        $L = (string) $lines[$i];
        $Lr = rtrim($L, "\r");
        if ($Lr === '---') {
            break;
        }
        if (preg_match('/^([a-zA-Z0-9_]+)\s*:\s*(.*)$/', $Lr, $m)) {
            $k = trim($m[1] ?? '', " \t");
            $v = (string) ($m[2] ?? '');
            $v = trim($v, " \t\"'"); // "value" and 'value'
            if ($k === '') {
                continue;
            }
            $meta[$k] = $v;
        }
    }
    if ($i >= count($lines) || (string) rtrim((string) ($lines[$i] ?? ''), "\r") !== '---') {
        return $out;
    }
    $out['body'] = ltrim(implode("\n", array_slice($lines, $i + 1)), "\n");
    if ($out['body'] === null) {
        $out['body'] = '';
    }
    $assignee = strtolower((string) ($meta['assignee'] ?? 'main'));
    if (in_array($assignee, ['sub_agent', 'subagent', 'sub', 'sub-agent'], true)) {
        $out['runAssignee'] = 'sub_agent';
    } else {
        $out['runAssignee'] = 'main';
    }
    $subName = (string) ($meta['subAgent'] ?? $meta['sub_agent'] ?? $meta['subAgentName'] ?? '');
    $subName = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $subName);
    if ($subName !== '') {
        $subName = (string) pathinfo(basename($subName), PATHINFO_FILENAME);
    } else {
        $subName = null;
    }
    $fromNode = trim((string) ($meta['subAgentNodeId'] ?? $meta['sub_agent_node_id'] ?? ''), " \t\"'"); // e.g. sub_agent_file_*
    if ($out['runAssignee'] === 'sub_agent' && is_string($subName) && $subName !== '') {
        $out['subAgent'] = $subName;
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/i', '_', $subName)) ?: '';
        $slug = trim($slug, '_');
        $out['subAgentNodeId'] = 'sub_agent_file_' . ($slug !== '' ? $slug : 'agent');
    } elseif ($out['runAssignee'] === 'sub_agent' && is_string($fromNode) && str_starts_with($fromNode, 'sub_agent_file_')) {
        $out['subAgentNodeId'] = $fromNode;
    }

    return $out;
}

/**
 * @param array{name?: string, title?: string, nodeId?: string, content?: string} $job
 * @return array
 */
function job_store_enrich_job_array(array $job): array {
    $raw = (string) ($job['content'] ?? '');
    $ph = job_store_parse_file_header($raw);
    $job['body'] = $ph['body'];
    $job['runAssignee'] = $ph['runAssignee'];
    $job['subAgent'] = $ph['subAgent'];
    $job['subAgentNodeId'] = $ph['subAgentNodeId'];

    return $job;
}

function list_job_files_meta(): array {
    $dir = jobs_dir_path();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [];
    $result = [];
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $row = [
            'name' => $filename,
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'nodeId' => job_node_id($filename),
            'content' => (string) file_get_contents($filePath),
        ];
        $result[] = job_store_enrich_job_array($row);
    }
    usort($result, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $result;
}

function get_job_meta(string $name): ?array {
    $filename = normalize_job_filename($name);
    if ($filename === '') {
        return null;
    }
    foreach (list_job_files_meta() as $job) {
        if (strcasecmp($job['name'], $filename) === 0) {
            return $job;
        }
    }
    return null;
}

function write_job_file(string $name, string $content): array {
    $filename = normalize_job_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid job file name'];
    }
    $path = jobs_dir_path() . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $content);
    $g = get_job_meta($filename);
    if ($g !== null) {
        return $g;
    }
    $row = [
        'name' => $filename,
        'title' => pathinfo($filename, PATHINFO_FILENAME),
        'nodeId' => job_node_id($filename),
        'content' => $content,
    ];

    return job_store_enrich_job_array($row);
}

function create_job_file(string $name, string $content): array {
    $filename = normalize_job_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid job file name'];
    }
    $path = jobs_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($path)) {
        return ['error' => 'Job file already exists'];
    }
    return write_job_file($filename, $content);
}

function update_job_file(string $name, string $content): array {
    $filename = normalize_job_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid job file name'];
    }
    $path = jobs_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Job file not found'];
    }
    return write_job_file($filename, $content);
}

function delete_job_file_by_name(string $name): array {
    $filename = normalize_job_filename($name);
    if ($filename === '') {
        return ['error' => 'Invalid job file name'];
    }
    $path = jobs_dir_path() . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return ['error' => 'Job file not found'];
    }
    unlink($path);
    return [
        'deleted' => true,
        'name' => $filename,
        'nodeId' => job_node_id($filename),
    ];
}
