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
 */
function list_chat_history(int $limit = 20, int $offset = 0): array {
    $data = read_chat_history_data();
    $exchanges = array_reverse($data['exchanges'] ?? []);
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
