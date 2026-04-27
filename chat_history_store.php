<?php
/**
 * Chat history store: persist completed exchanges so context can be truncated
 * and the AI can look up past chats via tools when needed.
 */

function chat_history_path(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'chat-history';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'exchanges.json';
}

function chat_history_max_exchanges(): int {
    return 500;
}

function chat_history_max_content_chars(): int {
    return 50000;
}

function read_chat_history_data(): array {
    $path = chat_history_path();
    if (!file_exists($path)) {
        return ['exchanges' => []];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return ['exchanges' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['exchanges']) || !is_array($data['exchanges'])) {
        return ['exchanges' => []];
    }
    return $data;
}

function write_chat_history_data(array $data): bool {
    $path = chat_history_path();
    $data['exchanges'] = array_values($data['exchanges'] ?? []);
    return @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

/**
 * Append a completed exchange. Trims to max exchanges.
 */
function append_chat_exchange(string $requestId, string $userContent, string $assistantContent, string $sessionId = ''): array {
    $data = read_chat_history_data();
    $exchanges = $data['exchanges'];
    $id = $requestId ?: ('hist_' . (string) (microtime(true) * 1000) . '_' . bin2hex(random_bytes(4)));

    $userContent = strlen($userContent) > chat_history_max_content_chars()
        ? substr($userContent, 0, chat_history_max_content_chars()) . "\n\n[truncated]"
        : $userContent;
    $assistantContent = strlen($assistantContent) > chat_history_max_content_chars()
        ? substr($assistantContent, 0, chat_history_max_content_chars()) . "\n\n[truncated]"
        : $assistantContent;

    $exchanges[] = [
        'id'        => $id,
        'requestId' => $requestId,
        'sessionId' => trim($sessionId),
        'ts'        => (int) (microtime(true) * 1000),
        'user'      => $userContent,
        'assistant' => $assistantContent,
    ];

    $max = chat_history_max_exchanges();
    if (count($exchanges) > $max) {
        $exchanges = array_slice($exchanges, -$max);
    }
    $data['exchanges'] = $exchanges;
    write_chat_history_data($data);
    return ['id' => $id];
}

/**
 * List recent exchanges (previews only). limit/offset for pagination.
 * When $sessionId is non-null and non-empty, only exchanges with that sessionId are returned.
 */
function list_chat_history(int $limit = 20, int $offset = 0, ?string $sessionId = null): array {
    $data = read_chat_history_data();
    $exchanges = array_reverse($data['exchanges'] ?? []);
    if ($sessionId !== null && $sessionId !== '') {
        $exchanges = array_values(array_filter($exchanges, function ($e) use ($sessionId) {
            return (string) ($e['sessionId'] ?? '') === $sessionId;
        }));
    }
    $total = count($exchanges);
    $slice = array_slice($exchanges, $offset, $limit);
    $previewLen = 200;
    $list = [];
    foreach ($slice as $e) {
        $list[] = [
            'id'         => $e['id'] ?? '',
            'requestId'  => $e['requestId'] ?? '',
            'sessionId'  => $e['sessionId'] ?? '',
            'ts'         => $e['ts'] ?? 0,
            'userPreview'    => isset($e['user']) ? (strlen($e['user']) > $previewLen ? substr($e['user'], 0, $previewLen) . '…' : $e['user']) : '',
            'assistantPreview' => isset($e['assistant']) ? (strlen($e['assistant']) > $previewLen ? substr($e['assistant'], 0, $previewLen) . '…' : $e['assistant']) : '',
        ];
    }
    return [
        'exchanges' => $list,
        'total'     => $total,
        'limit'     => $limit,
        'offset'    => $offset,
    ];
}

/**
 * Get one exchange by id or requestId. Returns full user/assistant content.
 */
function get_chat_history(string $id): ?array {
    $data = read_chat_history_data();
    $id = trim($id);
    foreach (array_reverse($data['exchanges'] ?? []) as $e) {
        if (($e['id'] ?? '') === $id || ($e['requestId'] ?? '') === $id) {
            return [
                'id'        => $e['id'] ?? '',
                'requestId' => $e['requestId'] ?? '',
                'sessionId' => $e['sessionId'] ?? '',
                'ts'        => $e['ts'] ?? 0,
                'user'      => $e['user'] ?? '',
                'assistant' => $e['assistant'] ?? '',
            ];
        }
    }
    return null;
}

/**
 * Distinct browser chat sessions from persisted exchanges (newest activity first).
 */
function list_chat_sessions(int $limit = 100): array {
    $data = read_chat_history_data();
    $agg = [];
    foreach ($data['exchanges'] ?? [] as $e) {
        $sid = trim((string) ($e['sessionId'] ?? ''));
        if ($sid === '') {
            $sid = '';
        }
        $ts = (int) ($e['ts'] ?? 0);
        if (!isset($agg[$sid])) {
            $agg[$sid] = [
                'sessionId' => $sid,
                'exchangeCount' => 0,
                'lastTs' => 0,
                'firstTs' => PHP_INT_MAX,
                'lastUserPreview' => '',
            ];
        }
        $agg[$sid]['exchangeCount']++;
        if ($ts >= $agg[$sid]['lastTs']) {
            $agg[$sid]['lastTs'] = $ts;
            $u = (string) ($e['user'] ?? '');
            $agg[$sid]['lastUserPreview'] = strlen($u) > 120 ? substr($u, 0, 117) . '…' : $u;
        }
        if ($ts < $agg[$sid]['firstTs']) {
            $agg[$sid]['firstTs'] = $ts;
        }
    }
    $rows = array_values($agg);
    usort($rows, function ($a, $b) {
        return (int) ($b['lastTs'] ?? 0) <=> (int) ($a['lastTs'] ?? 0);
    });
    foreach ($rows as &$r) {
        if (isset($r['firstTs']) && $r['firstTs'] === PHP_INT_MAX) {
            $r['firstTs'] = 0;
        }
    }
    unset($r);
    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }
    return $rows;
}

/**
 * Simple-chat turns (oldest first) rebuilt from server history for one session.
 *
 * @return list<array{role: string, content: string}>
 */
function list_chat_history_turns_for_session(string $sessionId, int $maxExchanges = 200): array {
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return [];
    }
    $data = read_chat_history_data();
    $hits = [];
    foreach ($data['exchanges'] ?? [] as $e) {
        if (trim((string) ($e['sessionId'] ?? '')) !== $sessionId) {
            continue;
        }
        $hits[] = $e;
    }
    usort($hits, function ($a, $b) {
        return (int) ($a['ts'] ?? 0) <=> (int) ($b['ts'] ?? 0);
    });
    if (count($hits) > $maxExchanges) {
        $hits = array_slice($hits, -$maxExchanges);
    }
    $turns = [];
    foreach ($hits as $e) {
        $turns[] = ['role' => 'user', 'content' => (string) ($e['user'] ?? '')];
        $turns[] = ['role' => 'assistant', 'content' => (string) ($e['assistant'] ?? '')];
    }
    return $turns;
}

/**
 * Remove all exchanges for a session. Returns number removed.
 * Empty session id removes legacy rows (no sessionId stored).
 */
function delete_chat_history_for_session(string $sessionId): int {
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return delete_chat_history_unassigned();
    }
    $data = read_chat_history_data();
    $exchanges = $data['exchanges'] ?? [];
    $before = count($exchanges);
    $data['exchanges'] = array_values(array_filter($exchanges, function ($e) use ($sessionId) {
        return trim((string) ($e['sessionId'] ?? '')) !== $sessionId;
    }));
    write_chat_history_data($data);
    return $before - count($data['exchanges']);
}

/**
 * Remove one exchange by id or requestId.
 */
function delete_chat_history_entry(string $id): bool {
    $id = trim($id);
    if ($id === '') {
        return false;
    }
    $data = read_chat_history_data();
    $exchanges = $data['exchanges'] ?? [];
    $found = false;
    $data['exchanges'] = array_values(array_filter($exchanges, function ($e) use ($id, &$found) {
        $match = (($e['id'] ?? '') === $id || ($e['requestId'] ?? '') === $id);
        if ($match) {
            $found = true;
        }
        return !$match;
    }));
    if ($found) {
        write_chat_history_data($data);
    }
    return $found;
}

/**
 * Remove exchanges with empty session id (legacy rows).
 */
function delete_chat_history_unassigned(): int {
    $data = read_chat_history_data();
    $exchanges = $data['exchanges'] ?? [];
    $before = count($exchanges);
    $data['exchanges'] = array_values(array_filter($exchanges, function ($e) {
        return trim((string) ($e['sessionId'] ?? '')) !== '';
    }));
    write_chat_history_data($data);
    return $before - count($data['exchanges']);
}
