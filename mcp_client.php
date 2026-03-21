<?php

if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'env.php')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
}

function mcp_process_env(array $server): ?array {
    $customEnv = isset($server['env']) && is_array($server['env']) ? $server['env'] : [];
    if ($customEnv === []) {
        return null;
    }
    $baseEnv = [];
    foreach ($_ENV as $key => $value) {
        if (is_string($key) && (is_scalar($value) || $value === null)) {
            $baseEnv[$key] = (string) $value;
        }
    }
    foreach ($customEnv as $key => $value) {
        $baseEnv[$key] = (string) $value;
    }
    return $baseEnv;
}

function mcp_process_command(array $server): array {
    $command = trim((string) ($server['command'] ?? ''));
    if ($command === '') {
        return [];
    }
    $parts = [$command];
    foreach (($server['args'] ?? []) as $arg) {
        $parts[] = (string) $arg;
    }
    return $parts;
}

function mcp_write_message($stream, array $message): void {
    $payload = json_encode($message);
    $frame = 'Content-Length: ' . strlen((string) $payload) . "\r\n\r\n" . $payload;
    fwrite($stream, $frame);
    fflush($stream);
}

function mcp_merge_http_headers(array $baseHeaders, array $extraHeaders): array {
    $normalized = [];
    foreach ([$baseHeaders, $extraHeaders] as $headerSet) {
        foreach ($headerSet as $key => $value) {
            if (is_int($key) && is_string($value) && strpos($value, ':') !== false) {
                $parts = explode(':', $value, 2);
                $normalized[strtolower(trim($parts[0]))] = trim($parts[1]);
            } elseif (is_string($key)) {
                $normalized[strtolower(trim($key))] = (string) $value;
            }
        }
    }
    $out = [];
    foreach ($normalized as $key => $value) {
        $out[] = $key . ': ' . $value;
    }
    return $out;
}

function mcp_http_config_headers(array $server): array {
    $headers = isset($server['headers']) && is_array($server['headers']) ? $server['headers'] : [];
    $env = isset($server['env']) && is_array($server['env']) ? $server['env'] : [];

    foreach ($env as $key => $value) {
        if (!is_string($key) || trim($key) === '') {
            continue;
        }
        $normalizedKey = trim($key);
        $normalizedHyphenKey = str_replace('_', '-', $normalizedKey);
        if (!array_key_exists($normalizedKey, $headers)) {
            $headers[$normalizedKey] = (string) $value;
        }
        if ($normalizedHyphenKey !== $normalizedKey && !array_key_exists($normalizedHyphenKey, $headers)) {
            $headers[$normalizedHyphenKey] = (string) $value;
        }
    }

    return $headers;
}

function mcp_effective_transport(array $server): string {
    $transport = strtolower(trim((string) ($server['transport'] ?? 'stdio')));
    $url = trim((string) ($server['url'] ?? ''));
    $command = trim((string) ($server['command'] ?? ''));
    if (($transport === '' || $transport === 'stdio') && $url !== '' && $command === '') {
        return 'streamablehttp';
    }
    return $transport !== '' ? $transport : 'stdio';
}

function mcp_parse_sse_payloads(string $body): array {
    $events = preg_split("/\r?\n\r?\n/", $body);
    $messages = [];
    foreach ($events as $event) {
        $event = trim($event);
        if ($event === '') {
            continue;
        }
        $dataLines = [];
        foreach (preg_split("/\r?\n/", $event) as $line) {
            if (strpos($line, 'data:') === 0) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if (!$dataLines) {
            continue;
        }
        $decoded = json_decode(implode("\n", $dataLines), true);
        if (is_array($decoded)) {
            $messages[] = $decoded;
        }
    }
    return $messages;
}

function mcp_http_request(array $server, array $message, ?string $sessionId = null, float $timeoutSeconds = 20.0): array {
    $url = trim((string) ($server['url'] ?? ''));
    if ($url === '') {
        return ['error' => 'MCP server URL is required for HTTP transport'];
    }

    $responseHeaders = [];
    $payload = json_encode($message);
    $headers = mcp_merge_http_headers([
        'Accept' => 'application/json, text/event-stream',
        'Content-Type' => 'application/json',
    ], mcp_http_config_headers($server));
    if ($sessionId) {
        $headers[] = 'mcp-session-id: ' . $sessionId;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$responseHeaders) {
            $trimmed = trim($headerLine);
            if ($trimmed !== '' && strpos($trimmed, ':') !== false) {
                $parts = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($headerLine);
        },
        CURLOPT_TIMEOUT => max(1, (int) ceil($timeoutSeconds)),
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'HTTP transport error', 'details' => $err];
    }

    $contentType = strtolower((string) ($responseHeaders['content-type'] ?? 'application/json'));
    $decoded = null;
    if (strpos($contentType, 'text/event-stream') !== false) {
        $messages = mcp_parse_sse_payloads((string) $body);
        foreach ($messages as $candidate) {
            if (isset($candidate['id']) && isset($message['id']) && (string) $candidate['id'] === (string) $message['id']) {
                $decoded = $candidate;
                break;
            }
        }
        if ($decoded === null && $messages) {
            $decoded = $messages[count($messages) - 1];
        }
    } else {
        $decoded = json_decode((string) $body, true);
    }

    if ($httpCode >= 400) {
        return [
            'error' => 'MCP HTTP request failed',
            'details' => [
                'status' => $httpCode,
                'body' => $body,
                'response' => $decoded,
            ],
        ];
    }

    if ($httpCode === 202 && (string) $body === '') {
        return [
            'result' => [],
            'sessionId' => $responseHeaders['mcp-session-id'] ?? $sessionId,
        ];
    }

    if (!is_array($decoded)) {
        return [
            'error' => 'Invalid MCP HTTP response',
            'details' => [
                'status' => $httpCode,
                'body' => $body,
                'contentType' => $contentType,
            ],
        ];
    }

    if (isset($decoded['error'])) {
        return [
            'error' => $decoded['error'],
            'sessionId' => $responseHeaders['mcp-session-id'] ?? $sessionId,
        ];
    }

    return [
        'result' => $decoded['result'] ?? [],
        'sessionId' => $responseHeaders['mcp-session-id'] ?? $sessionId,
        'raw' => $decoded,
    ];
}

function mcp_wait_for_stream($stream, float $deadline): bool {
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) {
        return false;
    }
    $seconds = (int) floor($remaining);
    $microseconds = (int) (($remaining - $seconds) * 1000000);
    $read = [$stream];
    $write = null;
    $except = null;
    return stream_select($read, $write, $except, $seconds, $microseconds) > 0;
}

function mcp_read_line($stream, float $deadline): ?string {
    if (!mcp_wait_for_stream($stream, $deadline)) {
        return null;
    }
    $line = fgets($stream);
    return $line === false ? null : $line;
}

function mcp_read_message($stream, float $timeoutSeconds = 20.0): ?array {
    $deadline = microtime(true) + $timeoutSeconds;
    $headers = [];
    while (true) {
        $line = mcp_read_line($stream, $deadline);
        if ($line === null) {
            return null;
        }
        $trimmed = trim($line);
        if ($trimmed === '') {
            break;
        }
        $parts = explode(':', $trimmed, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    $contentLength = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
    if ($contentLength <= 0) {
        return null;
    }
    $body = '';
    while (strlen($body) < $contentLength) {
        if (!mcp_wait_for_stream($stream, $deadline)) {
            return null;
        }
        $chunk = fread($stream, $contentLength - strlen($body));
        if ($chunk === false || $chunk === '') {
            continue;
        }
        $body .= $chunk;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function mcp_request_response($stdin, $stdout, string $method, $params, int $id, float $timeoutSeconds = 20.0): array {
    mcp_write_message($stdin, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ]);
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $message = mcp_read_message($stdout, max(0.1, $deadline - microtime(true)));
        if (!is_array($message)) {
            break;
        }
        if (isset($message['id']) && (int) $message['id'] === $id) {
            if (isset($message['error'])) {
                return ['error' => $message['error']];
            }
            return ['result' => $message['result'] ?? []];
        }
    }
    return ['error' => 'Timed out waiting for MCP response'];
}

function mcp_notify($stdin, string $method, $params): void {
    mcp_write_message($stdin, [
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
    ]);
}

function with_mcp_streamable_http_session(array $server, callable $callback): array {
    $protocolVersion = '2025-03-26';
    $initialize = mcp_http_request($server, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => $protocolVersion,
            'capabilities' => new stdClass(),
            'clientInfo' => [
                'name' => 'MemoryGraph',
                'version' => '1.0.0',
            ],
        ],
    ], null, 20.0);
    if (isset($initialize['error'])) {
        return ['error' => 'MCP initialize failed', 'details' => $initialize['details'] ?? $initialize['error']];
    }

    $sessionId = (string) ($initialize['sessionId'] ?? '');
    $notify = mcp_http_request($server, [
        'jsonrpc' => '2.0',
        'method' => 'notifications/initialized',
        'params' => new stdClass(),
    ], $sessionId !== '' ? $sessionId : null, 20.0);
    if (isset($notify['sessionId']) && $notify['sessionId'] !== '') {
        $sessionId = (string) $notify['sessionId'];
    }

    $result = $callback($sessionId);
    if (!is_array($result)) {
        return ['error' => 'Invalid MCP callback result'];
    }
    return $result;
}

function with_mcp_stdio_session(array $server, callable $callback): array {
    if (mcp_effective_transport($server) !== 'stdio') {
        return ['error' => 'Only stdio MCP servers are supported right now'];
    }
    $command = mcp_process_command($server);
    if ($command === []) {
        return ['error' => 'MCP server command is required'];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $cwd = trim((string) ($server['cwd'] ?? ''));
    $cwd = $cwd !== '' && is_dir($cwd) ? $cwd : null;
    $options = [];
    if (DIRECTORY_SEPARATOR === '\\') {
        $options['bypass_shell'] = true;
    }
    $process = @proc_open($command, $descriptors, $pipes, $cwd, mcp_process_env($server), $options);
    if (!is_resource($process) && DIRECTORY_SEPARATOR === '\\') {
        $escapedParts = array_map('escapeshellarg', $command);
        $commandString = 'cmd.exe /c ' . implode(' ', $escapedParts);
        $process = @proc_open($commandString, $descriptors, $pipes, $cwd, mcp_process_env($server));
    }
    if (!is_resource($process)) {
        return ['error' => 'Failed to start MCP server process'];
    }

    try {
        stream_set_blocking($pipes[0], true);
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], false);

        $initialize = mcp_request_response($pipes[0], $pipes[1], 'initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new stdClass(),
            'clientInfo' => [
                'name' => 'MemoryGraph',
                'version' => '1.0.0',
            ],
        ], 1, 20.0);
        if (isset($initialize['error'])) {
            $stderr = stream_get_contents($pipes[2]);
            return [
                'error' => 'MCP initialize failed',
                'details' => $initialize['error'],
                'stderr' => $stderr !== false ? trim($stderr) : '',
            ];
        }

        mcp_notify($pipes[0], 'notifications/initialized', new stdClass());
        $result = $callback($pipes[0], $pipes[1]);
        if (!is_array($result)) {
            $result = ['error' => 'Invalid MCP callback result'];
        }
        $stderr = stream_get_contents($pipes[2]);
        if ($stderr !== false && trim($stderr) !== '') {
            $result['stderr'] = trim($stderr);
        }
        return $result;
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
}

/**
 * Seconds to cache MCP tools/list per server (disk). 0 = always fetch live.
 * Env: MEMORYGRAPH_MCP_TOOLS_CACHE_TTL (e.g. 600). Default 600.
 */
function mcp_tools_list_cache_ttl_seconds(): int {
    $raw = $_ENV['MEMORYGRAPH_MCP_TOOLS_CACHE_TTL'] ?? getenv('MEMORYGRAPH_MCP_TOOLS_CACHE_TTL');
    if ($raw !== false && $raw !== null && (string) $raw !== '') {
        return max(0, (int) $raw);
    }
    return 86400;
}

function mcp_tools_list_cache_dir(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'mcp-tools-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function mcp_tools_list_cache_key(array $server): string {
    $sig = [
        'n' => (string) ($server['name'] ?? ''),
        't' => mcp_effective_transport($server),
        'u' => (string) ($server['url'] ?? ''),
        'c' => (string) ($server['command'] ?? ''),
        'a' => $server['args'] ?? [],
        'h' => isset($server['headers']) && is_array($server['headers']) ? $server['headers'] : [],
    ];
    return hash('sha256', json_encode($sig, JSON_UNESCAPED_SLASHES));
}

function mcp_tools_list_cache_path(string $key): string {
    return mcp_tools_list_cache_dir() . DIRECTORY_SEPARATOR . $key . '.json';
}

/** Clear all on-disk MCP tools/list caches (e.g. after server config change). */
function mcp_invalidate_tools_list_disk_cache(): void {
    $dir = mcp_tools_list_cache_dir();
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
        @unlink($f);
    }
}

function mcp_list_server_tools_uncached(array $server): array {
    $viaProxy = mcp_try_proxy_list_tools($server);
    if ($viaProxy !== null) {
        return $viaProxy;
    }
    $transport = mcp_effective_transport($server);
    if ($transport === 'streamablehttp' || $transport === 'streamable_http' || $transport === 'http') {
        return with_mcp_streamable_http_session($server, function ($sessionId) use ($server) {
            $response = mcp_http_request($server, [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
                'params' => new stdClass(),
            ], $sessionId !== '' ? $sessionId : null, 20.0);
            if (isset($response['error'])) {
                return ['error' => 'Failed to list MCP tools', 'details' => $response['details'] ?? $response['error']];
            }
            $result = $response['result'] ?? [];
            return [
                'tools' => isset($result['tools']) && is_array($result['tools']) ? array_values($result['tools']) : [],
            ];
        });
    }
    return with_mcp_stdio_session($server, function ($stdin, $stdout) {
        $response = mcp_request_response($stdin, $stdout, 'tools/list', new stdClass(), 2, 20.0);
        if (isset($response['error'])) {
            return ['error' => 'Failed to list MCP tools', 'details' => $response['error']];
        }
        $result = $response['result'] ?? [];
        return [
            'tools' => isset($result['tools']) && is_array($result['tools']) ? array_values($result['tools']) : [],
        ];
    });
}

/**
 * List tools from an MCP server. Uses a short-lived disk cache to avoid blocking every chat request on network/subprocess I/O.
 */
function mcp_list_server_tools(array $server): array {
    $ttl = mcp_tools_list_cache_ttl_seconds();
    if ($ttl > 0) {
        $key = mcp_tools_list_cache_key($server);
        $path = mcp_tools_list_cache_path($key);
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $entry = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
            if (is_array($entry) && isset($entry['saved_at'], $entry['payload']) && is_array($entry['payload'])) {
                if ((time() - (int) $entry['saved_at']) < $ttl) {
                    return $entry['payload'];
                }
            }
        }
    }
    $fresh = mcp_list_server_tools_uncached($server);
    if ($ttl > 0 && empty($fresh['error']) && isset($fresh['tools']) && is_array($fresh['tools'])) {
        $key = mcp_tools_list_cache_key($server);
        @file_put_contents(
            mcp_tools_list_cache_path($key),
            json_encode(['saved_at' => time(), 'payload' => $fresh], JSON_UNESCAPED_SLASHES)
        );
    }
    return $fresh;
}

function mcp_call_server_tool(array $server, string $toolName, array $arguments): array {
    $viaProxy = mcp_try_proxy_call_tool($server, $toolName, $arguments);
    if ($viaProxy !== null) {
        return $viaProxy;
    }
    $transport = mcp_effective_transport($server);
    if ($transport === 'streamablehttp' || $transport === 'streamable_http' || $transport === 'http') {
        return with_mcp_streamable_http_session($server, function ($sessionId) use ($server, $toolName, $arguments) {
            $response = mcp_http_request($server, [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => $toolName,
                    'arguments' => (object) $arguments,
                ],
            ], $sessionId !== '' ? $sessionId : null, 45.0);
            if (isset($response['error'])) {
                return ['error' => 'Failed to call MCP tool', 'details' => $response['details'] ?? $response['error']];
            }
            return is_array($response['result'] ?? null) ? $response['result'] : ['result' => $response['result'] ?? null];
        });
    }
    return with_mcp_stdio_session($server, function ($stdin, $stdout) use ($toolName, $arguments) {
        $response = mcp_request_response($stdin, $stdout, 'tools/call', [
            'name' => $toolName,
            'arguments' => (object) $arguments,
        ], 3, 45.0);
        if (isset($response['error'])) {
            return ['error' => 'Failed to call MCP tool', 'details' => $response['error']];
        }
        return is_array($response['result'] ?? null) ? $response['result'] : ['result' => $response['result'] ?? null];
    });
}

// --- Optional localhost MCP sidecar (persistent sessions) ---

function mcp_proxy_base_url(): ?string {
    static $done = false;
    static $out = null;
    if ($done) {
        return $out;
    }
    $done = true;
    $u = function_exists('memory_graph_env')
        ? (string) memory_graph_env('MEMORYGRAPH_MCP_PROXY_URL', '')
        : (string) (getenv('MEMORYGRAPH_MCP_PROXY_URL') ?: '');
    $u = trim($u);
    if ($u === '') {
        return null;
    }
    $out = rtrim($u, '/');
    return $out;
}

function mcp_proxy_auth_header_lines(): array {
    $tok = function_exists('memory_graph_env')
        ? trim((string) memory_graph_env('MEMORYGRAPH_MCP_PROXY_SECRET', ''))
        : trim((string) (getenv('MEMORYGRAPH_MCP_PROXY_SECRET') ?: ''));
    if ($tok === '') {
        return [];
    }
    return ['X-MemoryGraph-Mcp-Proxy: ' . $tok];
}

/**
 * POST JSON to MCP sidecar. Returns decoded array or null on hard failure.
 *
 * @return ?array
 */
function mcp_proxy_http_post(string $path, array $body, float $timeoutSeconds = 60.0) {
    $base = mcp_proxy_base_url();
    if ($base === null) {
        return null;
    }
    $url = $base . $path;
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return null;
    }
    $headers = array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        mcp_proxy_auth_header_lines()
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(1, (int) ceil($timeoutSeconds)),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        if (function_exists('memory_graph_env') && memory_graph_env('MEMORYGRAPH_MCP_DEBUG', '') === '1') {
            error_log('[mcp_proxy] POST ' . $path . ' failed: ' . $err);
        }
        return null;
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    if ($code >= 400 && function_exists('memory_graph_env') && memory_graph_env('MEMORYGRAPH_MCP_DEBUG', '') === '1') {
        error_log('[mcp_proxy] POST ' . $path . ' HTTP ' . $code . ' body=' . substr((string) $raw, 0, 500));
    }
    return $decoded;
}

/** @return ?array Success payload or null to use in-process MCP */
function mcp_try_proxy_list_tools(array $server): ?array {
    if (mcp_proxy_base_url() === null) {
        return null;
    }
    $key = mcp_tools_list_cache_key($server);
    $resp = mcp_proxy_http_post('/v1/list-tools', [
        'serverKey' => $key,
        'server' => $server,
    ], 45.0);
    if (!is_array($resp) || empty($resp['ok']) || !isset($resp['tools']) || !is_array($resp['tools'])) {
        return null;
    }
    return [
        'tools' => array_values($resp['tools']),
    ];
}

/** @return ?array Tool result array or null to use in-process MCP */
function mcp_try_proxy_call_tool(array $server, string $toolName, array $arguments): ?array {
    if (mcp_proxy_base_url() === null) {
        return null;
    }
    $key = mcp_tools_list_cache_key($server);
    $resp = mcp_proxy_http_post('/v1/call', [
        'serverKey' => $key,
        'server' => $server,
        'toolName' => $toolName,
        'arguments' => $arguments,
    ], 120.0);
    if (!is_array($resp) || empty($resp['ok']) || !array_key_exists('result', $resp)) {
        return null;
    }
    $r = $resp['result'];
    return is_array($r) ? $r : ['result' => $r];
}

/**
 * Run multiple MCP tool calls through the sidecar in parallel (curl_multi).
 * Preserves job order. Returns null if proxy off, single job, or any sub-request fails.
 *
 * @param list<array{server: array, toolName: string, arguments: array}> $jobs
 * @return ?list<array>
 */
function mcp_proxy_parallel_call_tools(array $jobs): ?array {
    $base = mcp_proxy_base_url();
    if ($base === null || count($jobs) < 2) {
        return null;
    }
    $n = count($jobs);
    $mh = curl_multi_init();
    if ($mh === false) {
        return null;
    }
    $handles = [];
    $auth = mcp_proxy_auth_header_lines();
    for ($i = 0; $i < $n; $i++) {
        $job = $jobs[$i];
        $server = $job['server'];
        if (!is_array($server)) {
            foreach ($handles as $ch) {
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
            return null;
        }
        $key = mcp_tools_list_cache_key($server);
        $payload = json_encode([
            'serverKey' => $key,
            'server' => $server,
            'toolName' => (string) ($job['toolName'] ?? ''),
            'arguments' => isset($job['arguments']) && is_array($job['arguments']) ? $job['arguments'] : [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            foreach ($handles as $ch) {
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
            return null;
        }
        $ch = curl_init($base . '/v1/call');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $auth),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $running = null;
    do {
        $mrc = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $mrc === CURLM_OK);

    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $ch = $handles[$i];
        $raw = curl_multi_getcontent($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if ($httpCode >= 400 || !is_array($decoded) || empty($decoded['ok']) || !array_key_exists('result', $decoded)) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (!isset($handles[$j])) {
                    continue;
                }
                $h = $handles[$j];
                curl_multi_remove_handle($mh, $h);
                curl_close($h);
            }
            curl_multi_close($mh);
            return null;
        }
        $r = $decoded['result'];
        $out[$i] = is_array($r) ? $r : ['result' => $r];
    }
    curl_multi_close($mh);
    return $out;
}

/** Notify sidecar to drop sessions (best-effort). */
function mcp_proxy_post_invalidate(?string $serverKey = null): void {
    if (mcp_proxy_base_url() === null) {
        return;
    }
    $body = $serverKey !== null && $serverKey !== '' ? ['serverKey' => $serverKey] : [];
    mcp_proxy_http_post('/invalidate', $body, 5.0);
}
