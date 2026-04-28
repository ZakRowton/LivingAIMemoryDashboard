<?php
/**
 * Featherless.ai helpers: user-facing HTTP errors, tokenize, concurrency snapshot.
 * @see https://api.featherless.ai — /v1/tokenize, /account/concurrency
 */

/**
 * Map Featherless HTTP status + optional JSON body to a clear user message.
 */
function memory_graph_featherless_user_error_message(int $httpCode, string $rawBody): string {
    $rawBody = trim($rawBody);
    $detail = '';
    if ($rawBody !== '' && ($rawBody[0] === '{' || $rawBody[0] === '[')) {
        $j = json_decode($rawBody, true);
        if (is_array($j)) {
            if (isset($j['error']) && is_array($j['error']) && isset($j['error']['message']) && is_string($j['error']['message'])) {
                $detail = trim($j['error']['message']);
            } elseif (isset($j['error']) && is_string($j['error'])) {
                $detail = trim($j['error']);
            } elseif (isset($j['message']) && is_string($j['message'])) {
                $detail = trim($j['message']);
            }
        }
    }
    $tail = $detail !== '' ? ' — ' . $detail : '';

    switch ($httpCode) {
        case 400:
            return 'Featherless (400): This model is cold or not ready for inference yet. Load/warm the model on the Featherless platform (small models may take a few minutes; large ones up to about an hour).' . $tail;
        case 401:
            return 'Featherless (401): Your API key was not recognized. Check FEATHERLESS_API_KEY in .env or the Featherless key saved in agent config.' . $tail;
        case 403:
            return 'Featherless (403): Access denied — the model may be gated; open its page on Featherless and accept the license (“Unlock model”) if required.' . $tail;
        case 429:
            return 'Featherless (429): Rate or concurrency limit hit — too many parallel or rapid requests for your plan. Wait and retry, or reduce concurrent chats.' . $tail;
        case 500:
            return 'Featherless (500): Internal server error on their side. If it persists, try again later or contact Featherless support.' . $tail;
        case 503:
            return 'Featherless (503): Insufficient GPU capacity right now (“no valid executor”). Retry the same request; if it still fails after several tries, the model tier may be temporarily saturated.' . $tail;
        default:
            if ($httpCode >= 400 && $httpCode < 500) {
                return 'Featherless (' . $httpCode . '): Client error.' . $tail;
            }
            if ($httpCode >= 500) {
                return 'Featherless (' . $httpCode . '): Server error.' . $tail;
            }

            return 'Featherless: Request failed (HTTP ' . $httpCode . ').' . $tail;
    }
}

/**
 * @return array{ok?:true,tokens?:int,error?:string,httpCode?:int,raw?:string}|array{ok?:false,error:string,httpCode?:int}
 */
function memory_graph_featherless_tokenize(string $apiKey, string $model, string $text): array {
    $apiKey = trim($apiKey);
    $model = trim($model);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'Missing Featherless API key'];
    }
    if ($model === '') {
        return ['ok' => false, 'error' => 'Missing model id'];
    }
    $body = json_encode(['model' => $model, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'error' => 'Failed to encode tokenize JSON'];
    }
    $ch = curl_init('https://api.featherless.ai/v1/tokenize');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($cerr) {
        return ['ok' => false, 'error' => 'Network error calling Featherless tokenize: ' . $cerr, 'httpCode' => 502];
    }
    $raw = (string) $response;
    if ($httpCode >= 400) {
        return [
            'ok' => false,
            'error' => memory_graph_featherless_user_error_message($httpCode, $raw),
            'httpCode' => $httpCode,
            'raw' => strlen($raw) > 8000 ? substr($raw, 0, 8000) : $raw,
        ];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['tokens'])) {
        return ['ok' => false, 'error' => 'Unexpected tokenize response', 'httpCode' => 502, 'raw' => strlen($raw) > 4000 ? substr($raw, 0, 4000) : $raw];
    }
    $tokens = $decoded['tokens'];

    return ['ok' => true, 'tokens' => is_array($tokens) ? count($tokens) : 0];
}

/**
 * @return array{ok?:true,limit:?int,used_cost:int,request_count:int,requests:array}|array{ok?:false,error:string,httpCode?:int}
 */
function memory_graph_featherless_concurrency_snapshot(string $apiKey): array {
    $apiKey = trim($apiKey);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'Missing Featherless API key'];
    }
    $ch = curl_init('https://api.featherless.ai/account/concurrency');
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($cerr) {
        return ['ok' => false, 'error' => 'Network error calling Featherless concurrency: ' . $cerr, 'httpCode' => 502];
    }
    $raw = (string) $response;
    if ($httpCode >= 400) {
        return [
            'ok' => false,
            'error' => memory_graph_featherless_user_error_message($httpCode, $raw),
            'httpCode' => $httpCode,
        ];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Unexpected concurrency response', 'httpCode' => 502];
    }
    $limit = $decoded['limit'] ?? null;
    if ($limit !== null) {
        $limit = (int) $limit;
    }
    $used = isset($decoded['used_cost']) ? (int) $decoded['used_cost'] : 0;
    $rc = isset($decoded['request_count']) ? (int) $decoded['request_count'] : 0;
    $reqs = isset($decoded['requests']) && is_array($decoded['requests']) ? $decoded['requests'] : [];

    return [
        'ok' => true,
        'limit' => $limit,
        'used_cost' => $used,
        'request_count' => $rc,
        'requests' => $reqs,
    ];
}

/**
 * Last user message content as plain text (for embeddings input).
 */
function memory_graph_messages_last_user_plaintext_for_embeddings(array $messages): string {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $m = $messages[$i];
        if (!is_array($m) || ($m['role'] ?? '') !== 'user') {
            continue;
        }
        $c = $m['content'] ?? '';
        if (is_string($c)) {
            $t = trim($c);
            if ($t !== '') {
                return $t;
            }
        }
        if (is_array($c)) {
            $parts = [];
            foreach ($c as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $parts[] = trim((string) $part['text']);
                }
            }
            $joined = trim(implode("\n", array_filter($parts, static function ($x) {
                return $x !== '';
            })));
            if ($joined !== '') {
                return $joined;
            }
        }
    }

    return '';
}

/**
 * POST OpenAI-compatible embeddings to Featherless.
 *
 * @param string|array<int, string> $input
 * @return array{data: array, httpCode: int}|array{error: string, httpCode: int, raw?: string}
 */
function memory_graph_featherless_embeddings_request(string $apiKey, string $model, $input): array {
    $apiKey = trim($apiKey);
    $model = trim($model);
    if ($apiKey === '') {
        return ['error' => 'Missing Featherless API key', 'httpCode' => 500];
    }
    if ($model === '') {
        return ['error' => 'Missing embedding model id', 'httpCode' => 400];
    }
    $body = json_encode(['model' => $model, 'input' => $input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['error' => 'Failed to encode embeddings JSON', 'httpCode' => 500];
    }
    $ch = curl_init('https://api.featherless.ai/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($cerr) {
        return ['error' => 'Network error calling Featherless embeddings: ' . $cerr, 'httpCode' => 502];
    }
    $raw = (string) $response;
    if ($httpCode >= 400) {
        return [
            'error' => memory_graph_featherless_user_error_message($httpCode, $raw),
            'httpCode' => $httpCode,
            'raw' => strlen($raw) > 12000 ? substr($raw, 0, 12000) : $raw,
        ];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid JSON from Featherless embeddings', 'httpCode' => 502, 'raw' => strlen($raw) > 4000 ? substr($raw, 0, 4000) : $raw];
    }

    return ['data' => $decoded, 'httpCode' => 200];
}

/** User-readable summary of an OpenAI-style embeddings JSON body. */
function memory_graph_featherless_format_embedding_assistant_text(array $decoded): string {
    $lines = [];
    $lines[] = '**Featherless embeddings** (`POST /v1/embeddings`)';
    $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
    if ($data === []) {
        $lines[] = '_No embedding vectors returned._';

        return implode("\n", $lines);
    }
    foreach ($data as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $emb = isset($row['embedding']) && is_array($row['embedding']) ? $row['embedding'] : [];
        $dim = count($emb);
        $idx = isset($row['index']) ? (int) $row['index'] : $i;
        $preview = array_slice($emb, 0, 8);
        $pv = [];
        foreach ($preview as $v) {
            $pv[] = is_float($v) ? (string) $v : (string) $v;
        }
        $tail = $dim > 8 ? ' …' : '';
        $lines[] = '- **#' . $idx . '** · dimension **' . $dim . '** · first values: `' . implode(', ', $pv) . '`' . $tail;
    }
    $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : [];
    if ($usage !== []) {
        $pt = $usage['prompt_tokens'] ?? null;
        $tt = $usage['total_tokens'] ?? null;
        $bits = [];
        if ($pt !== null) {
            $bits[] = 'prompt_tokens: ' . $pt;
        }
        if ($tt !== null) {
            $bits[] = 'total_tokens: ' . $tt;
        }
        if ($bits !== []) {
            $lines[] = '';
            $lines[] = '**Usage:** ' . implode(' · ', $bits);
        }
    }
    $mid = isset($decoded['model']) && is_string($decoded['model']) ? trim($decoded['model']) : '';
    if ($mid !== '') {
        $lines[] = '';
        $lines[] = '_Model:_ `' . $mid . '`';
    }

    return implode("\n", $lines);
}
