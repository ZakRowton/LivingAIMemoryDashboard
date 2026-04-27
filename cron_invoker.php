<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cron_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cron_pending.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'provider_config.php';

/**
 * POST JSON to URL; prefers cURL, falls back to file_get_contents stream context.
 *
 * @return array{ok:bool,raw:string,httpCode:int,error:string}
 */
function mg_cron_http_post_json(string $url, string $jsonBody): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'raw' => '', 'httpCode' => 0, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 580,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = (string) curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'raw' => '', 'httpCode' => $code, 'error' => $cerr !== '' ? $cerr : 'curl_exec failed'];
        }
        return ['ok' => true, 'raw' => (string) $raw, 'httpCode' => $code, 'error' => ''];
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json; charset=utf-8\r\n",
            'content' => $jsonBody,
            'timeout' => 580,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (function_exists('http_get_last_response_headers')) {
        $tmp = http_get_last_response_headers();
        $headerLines = is_array($tmp) ? $tmp : [];
    } else {
        $hdrVar = 'http' . '_response_header';
        $headerLines = (isset($$hdrVar) && is_array($$hdrVar)) ? $$hdrVar : [];
    }
    foreach ($headerLines as $h) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) {
            $code = (int) $m[1];
            break;
        }
    }
    if ($raw === false) {
        return ['ok' => false, 'raw' => '', 'httpCode' => $code, 'error' => 'HTTP POST failed (no cURL)'];
    }
    return ['ok' => true, 'raw' => (string) $raw, 'httpCode' => $code, 'error' => ''];
}

function mg_cron_public_base_url(): string {
    memory_graph_load_env();
    $u = memory_graph_env('MEMORYGRAPH_PUBLIC_BASE_URL', '');
    if (is_string($u) && trim($u) !== '') {
        return rtrim(trim($u), '/');
    }
    return 'http://127.0.0.1/MemoryGraph';
}

function mg_cron_resolve_model_for_job(array $job): array {
    $defaults = get_current_provider_model();
    $m = isset($job['model']) && is_array($job['model']) ? $job['model'] : [];
    $provider = trim((string) ($m['provider'] ?? ''));
    $model = trim((string) ($m['model'] ?? ''));
    if ($provider === '') {
        $provider = $defaults['provider'];
    }
    if ($model === '') {
        $model = $defaults['model'];
    }
    $temp = isset($m['temperature']) && $m['temperature'] !== null && $m['temperature'] !== ''
        ? (float) $m['temperature']
        : 0.7;
    return ['provider' => $provider, 'model' => $model, 'temperature' => $temp];
}

function mg_cron_system_prompt_for(string $provider, string $model): string {
    $key = $provider . ':' . $model;
    $cfg = get_agent_provider_config();
    $ifm = isset($cfg['systemInstructionFilesByModel']) && is_array($cfg['systemInstructionFilesByModel']) ? $cfg['systemInstructionFilesByModel'] : [];
    if (isset($ifm[$key]) && is_string($ifm[$key]) && trim($ifm[$key]) !== '') {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'instruction_store.php';
        $meta = get_instruction_meta(trim($ifm[$key]));
        if ($meta !== null && isset($meta['content']) && trim((string) $meta['content']) !== '') {
            return trim((string) $meta['content']);
        }
    }
    $map = get_system_prompts_by_model();
    return isset($map[$key]) ? (string) $map[$key] : '';
}

function mg_cron_active_run_dir(): string {
    $d = mg_cron_runtime_dir() . DIRECTORY_SEPARATOR . 'active';
    if (!is_dir($d)) {
        @mkdir($d, 0777, true);
    }
    return $d;
}

function mg_cron_active_run_path(string $requestId): string {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $requestId);
    if ($safe === '') {
        $safe = 'unknown';
    }
    return mg_cron_active_run_dir() . DIRECTORY_SEPARATOR . $safe . '.json';
}

/** Advertise an in-flight cron invoke so the browser Jobs panel can poll and show progress. */
function mg_cron_active_run_register(string $requestId, array $job): void {
    $jid = (string) ($job['id'] ?? '');
    $payload = [
        'requestId' => $requestId,
        'jobId' => $jid,
        'jobName' => (string) ($job['name'] ?? 'Scheduled job'),
        'nodeId' => mg_cron_job_node_id($jid),
        'startedAt' => time(),
    ];
    @file_put_contents(mg_cron_active_run_path($requestId), json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function mg_cron_active_run_unregister(string $requestId): void {
    $p = mg_cron_active_run_path($requestId);
    if (is_file($p)) {
        @unlink($p);
    }
}

/**
 * @return list<array{requestId:string,jobId:string,jobName:string,nodeId:string,startedAt:int}>
 */
function mg_cron_list_active_runs(): array {
    $files = glob(mg_cron_active_run_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $now = time();
    $out = [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j) || empty($j['requestId'])) {
            @unlink($f);
            continue;
        }
        $st = isset($j['startedAt']) ? (int) $j['startedAt'] : 0;
        if ($st > 0 && ($now - $st) > 7200) {
            @unlink($f);
            continue;
        }
        $out[] = $j;
    }
    return $out;
}

function mg_cron_run_result_dir(): string {
    $d = mg_cron_runtime_dir() . DIRECTORY_SEPARATOR . 'results';
    if (!is_dir($d)) {
        @mkdir($d, 0777, true);
    }
    return $d;
}

function mg_cron_run_result_path(string $requestId): string {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $requestId);
    if ($safe === '') {
        $safe = 'unknown';
    }
    return mg_cron_run_result_dir() . DIRECTORY_SEPARATOR . $safe . '.json';
}

/**
 * Persist last assistant output for a cron invoke (Jobs panel "View response").
 *
 * @param array{ok?:bool,error?:string,assistantContent?:string,summary?:string,jobName?:string,cronPrompt?:string,finishedAt?:int} $payload
 */
function mg_cron_run_result_save(string $requestId, array $payload): void {
    $payload['requestId'] = $requestId;
    $payload['finishedAt'] = isset($payload['finishedAt']) ? (int) $payload['finishedAt'] : time();
    $c = (string) ($payload['assistantContent'] ?? '');
    if (strlen($c) > 400000) {
        $c = substr($c, 0, 400000) . "\n\n[truncated]\n";
    }
    $payload['assistantContent'] = $c;
    @file_put_contents(mg_cron_run_result_path($requestId), json_encode($payload, JSON_UNESCAPED_UNICODE));
    mg_cron_run_result_prune_old();
}

/**
 * Extract visible assistant string from chat.php JSON (string, array parts, null).
 */
function mg_cron_extract_assistant_content_from_chat_response(array $decoded): string {
    $msg = $decoded['choices'][0]['message'] ?? null;
    if (is_array($msg)) {
        foreach (['reasoning', 'reasoning_content'] as $rk) {
            if (!empty($msg[$rk]) && is_string($msg[$rk]) && trim($msg[$rk]) !== '') {
                return trim($msg[$rk]);
            }
        }
        $raw = $msg['content'] ?? null;
        if (is_string($raw)) {
            return trim($raw);
        }
        if (is_array($raw)) {
            $s = '';
            foreach ($raw as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $s .= $part['text'];
                } elseif (isset($part['text']) && is_array($part['text']) && isset($part['text']['value']) && is_string($part['text']['value'])) {
                    $s .= $part['text']['value'];
                } elseif (isset($part['content']) && is_string($part['content'])) {
                    $s .= $part['content'];
                }
            }
            return trim($s);
        }
        if ($raw !== null && $raw !== false) {
            return trim((string) $raw);
        }
    }
    $c0 = $decoded['choices'][0] ?? null;
    if (is_array($c0)) {
        foreach (['text', 'message_text'] as $k) {
            if (!empty($c0[$k]) && is_string($c0[$k]) && trim($c0[$k]) !== '') {
                return trim($c0[$k]);
            }
        }
    }
    // Tool-style JSON echoed alone (e.g. get_gemini_response success) or odd provider shapes.
    if (isset($decoded['response']) && is_string($decoded['response']) && trim($decoded['response']) !== '') {
        return trim($decoded['response']);
    }
    return '';
}

/**
 * JSON is an error object, not an OpenAI-style completion (no assistant message to show).
 */
function mg_cron_chat_response_is_error_payload(array $d): bool {
    $choices = $d['choices'] ?? null;
    if (is_array($choices) && isset($choices[0]) && is_array($choices[0])) {
        if (isset($choices[0]['message']) && is_array($choices[0]['message'])) {
            return false;
        }
        foreach (['text', 'message_text'] as $k) {
            if (isset($choices[0][$k]) && is_string($choices[0][$k]) && trim($choices[0][$k]) !== '') {
                return false;
            }
        }
    }
    if (array_key_exists('error', $d) && $d['error'] !== null && $d['error'] !== '' && $d['error'] !== []) {
        return true;
    }
    if (isset($d['errors']) && $d['errors'] !== null && $d['errors'] !== '' && $d['errors'] !== []) {
        return true;
    }
    return false;
}

/**
 * @param array<string,mixed> $d
 */
function mg_cron_format_chat_error_payload_for_ui(array $d, int $httpCode, string $cronRequestId): string {
    $err = $d['error'] ?? null;
    if (is_array($err)) {
        $err = isset($err['message']) ? (string) $err['message'] : json_encode($err, JSON_UNESCAPED_UNICODE);
    } elseif ($err !== null && !is_string($err)) {
        $err = is_scalar($err) ? (string) $err : json_encode($err, JSON_UNESCAPED_UNICODE);
    } else {
        $err = (string) $err;
    }
    $lines = [];
    $lines[] = 'The chat API returned an error payload instead of a model completion (HTTP ' . $httpCode . '). This is not an "empty model reply".';
    if ($err !== '') {
        $lines[] = 'Detail: ' . $err;
    }
    if (isset($d['upstream_preview']) && is_string($d['upstream_preview']) && trim($d['upstream_preview']) !== '') {
        $lines[] = 'Upstream preview: ' . trim($d['upstream_preview']);
    } elseif (isset($d['response']) && is_string($d['response']) && $d['response'] !== '') {
        $plain = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($d['response'])), 0, 600));
        if ($plain !== '') {
            $lines[] = 'Upstream body (stripped): ' . $plain;
        }
        if (stripos($d['response'], 'www.google.com') !== false || stripos($plain, 'Error 404') !== false) {
            $lines[] = 'That HTML is almost always a bad Gemini URL, API version, model id, or key. If you use the get_gemini_response tool, it must call the same API family as the dashboard provider. Fix GEMINI_API_KEY and the model in Settings.';
        }
    }
    $lines[] = 'Cron invoke id: ' . $cronRequestId . '. POST target: ' . mg_cron_public_base_url() . '/api/chat.php — set MEMORYGRAPH_PUBLIC_BASE_URL in .env if this URL is wrong.';
    return implode("\n\n", $lines);
}

/**
 * Build a one-line summary when chat.php queued dashboard job(s) but returned no prose.
 *
 * @param mixed $jobToRun
 */
function mg_cron_summarize_job_to_run($jobToRun): string {
    if ($jobToRun === null || $jobToRun === []) {
        return '';
    }
    $list = [];
    if (is_array($jobToRun) && isset($jobToRun['name']) && is_string($jobToRun['name']) && $jobToRun['name'] !== '') {
        $list = [$jobToRun];
    } elseif (is_array($jobToRun)) {
        $list = array_values($jobToRun);
    }
    $lines = [];
    foreach ($list as $j) {
        if (is_array($j) && !empty($j['name'])) {
            $lines[] = 'Queued dashboard job **' . (string) $j['name'] . '** — open the Jobs panel to run it (multi-step markdown job).';
        }
    }
    return implode("\n", $lines);
}

function mg_cron_run_result_prune_old(): void {
    $files = glob(mg_cron_run_result_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $cut = time() - 86400 * 5;
    foreach ($files as $f) {
        if (@filemtime($f) !== false && @filemtime($f) < $cut) {
            @unlink($f);
        }
    }
}

/** @return ?array<string,mixed> */
function mg_cron_run_result_read(string $requestId): ?array {
    $requestId = trim($requestId);
    if ($requestId === '' || strlen($requestId) > 200) {
        return null;
    }
    $p = mg_cron_run_result_path($requestId);
    if (!is_file($p)) {
        return null;
    }
    $raw = @file_get_contents($p);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) ? $j : null;
}

/**
 * Run one scheduled agent turn via HTTP POST to api/chat.php (same as the dashboard).
 *
 * @return array{ok:bool,summary?:string,fullContent?:string,error?:string,httpCode?:int,requestId?:string}
 */
function mg_cron_invoke_agent_job(array $job): array {
    $requestId = 'cron_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) ($job['id'] ?? 'x')) . '_' . time();
    $base = mg_cron_public_base_url();
    $url = $base . '/api/chat.php';
    $resolved = mg_cron_resolve_model_for_job($job);
    $payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : [];
    $message = trim((string) ($payload['message'] ?? ''));
    if ($message === '') {
        return ['ok' => false, 'error' => 'empty job message', 'requestId' => $requestId];
    }
    $name = (string) ($job['name'] ?? 'job');
    $userLine = '[cron:' . $name . '] ' . $message;

    $jidRaw = preg_replace('/[^a-f0-9]/i', '', (string) ($job['id'] ?? ''));
    $body = [
        'requestId' => $requestId,
        'skipCronPendingDelivery' => true,
        'cronJobId' => $jidRaw,
        'provider' => $resolved['provider'],
        'model' => $resolved['model'],
        'systemPrompt' => mg_cron_system_prompt_for($resolved['provider'], $resolved['model']),
        'temperature' => $resolved['temperature'],
        'messages' => [['role' => 'user', 'content' => $userLine]],
    ];

    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'failed to encode request', 'requestId' => $requestId];
    }

    $resultForUi = [
        'ok' => false,
        'error' => '',
        'assistantContent' => '',
        'summary' => '',
        'jobName' => $name,
        'cronPrompt' => $userLine,
    ];

    mg_cron_active_run_register($requestId, $job);
    try {
        $http = mg_cron_http_post_json($url, $json);
        if (!$http['ok']) {
            $resultForUi['error'] = $http['error'] !== '' ? $http['error'] : 'HTTP request failed';
            $hc = (int) ($http['httpCode'] ?? 0);
            $resultForUi['assistantContent'] = 'Cron could not reach chat API' . ($hc > 0 ? ' (HTTP ' . $hc . ')' : '') . ': ' . $resultForUi['error'];
            $resultForUi['summary'] = mb_substr($resultForUi['assistantContent'], 0, 2000);
            return ['ok' => false, 'error' => $resultForUi['error'], 'httpCode' => $http['httpCode'], 'requestId' => $requestId];
        }
        $raw = $http['raw'];
        $code = $http['httpCode'];

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $resultForUi['error'] = 'invalid JSON from chat';
            $resultForUi['assistantContent'] = 'invalid JSON from chat (HTTP ' . $code . '). First 400 chars of body: ' . mb_substr((string) $raw, 0, 400);
            $resultForUi['summary'] = mb_substr($resultForUi['assistantContent'], 0, 2000);
            return ['ok' => false, 'error' => $resultForUi['error'], 'httpCode' => $code, 'summary' => $resultForUi['summary'], 'requestId' => $requestId];
        }
        if ($code >= 400) {
            $err = $decoded['error'] ?? $raw;
            if (is_array($err)) {
                if (isset($err['message']) && is_string($err['message'])) {
                    $err = $err['message'];
                } else {
                    $err = json_encode($err, JSON_UNESCAPED_UNICODE);
                }
            }
            $resultForUi['error'] = (string) $err;
            $resultForUi['assistantContent'] = 'HTTP ' . $code . ': ' . $resultForUi['error'];
            if (isset($decoded['upstream_preview']) && is_string($decoded['upstream_preview']) && $decoded['upstream_preview'] !== '') {
                $resultForUi['assistantContent'] .= "\n\n" . trim($decoded['upstream_preview']);
            } elseif (isset($decoded['response']) && is_string($decoded['response'])) {
                $plain = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($decoded['response'])), 0, 500));
                if ($plain !== '') {
                    $resultForUi['assistantContent'] .= "\n\nUpstream (stripped): " . $plain;
                }
            }
            $resultForUi['summary'] = mb_substr($resultForUi['assistantContent'], 0, 2000);
            return ['ok' => false, 'error' => $resultForUi['error'], 'httpCode' => $code, 'requestId' => $requestId, 'summary' => $resultForUi['summary']];
        }
        if (mg_cron_chat_response_is_error_payload($decoded)) {
            $msg = mg_cron_format_chat_error_payload_for_ui($decoded, $code, $requestId);
            $resultForUi['ok'] = false;
            $resultForUi['error'] = 'Chat API returned an error JSON body (not a model completion). Fix provider key/model/URL or MEMORYGRAPH_PUBLIC_BASE_URL.';
            $resultForUi['assistantContent'] = $msg;
            $resultForUi['summary'] = mb_substr($msg, 0, 2000);
            return ['ok' => false, 'error' => $resultForUi['error'], 'httpCode' => $code, 'requestId' => $requestId, 'summary' => $resultForUi['summary']];
        }
        $mg = isset($decoded['memory_graph']) && is_array($decoded['memory_graph']) ? $decoded['memory_graph'] : [];
        $content = mg_cron_extract_assistant_content_from_chat_response($decoded);
        if ($content === '' && !empty($mg['hint']) && is_string($mg['hint'])) {
            $content = trim($mg['hint']);
        }
        $jobSummary = mg_cron_summarize_job_to_run($decoded['jobToRun'] ?? null);
        if ($content === '' && $jobSummary !== '') {
            $content = $jobSummary;
        } elseif ($content !== '' && $jobSummary !== '' && stripos($content, 'Queued dashboard job') === false) {
            $content .= "\n\n" . $jobSummary;
        }

        $content = trim($content);
        if ($content === '') {
            $apiRid = isset($decoded['request_id']) ? (string) $decoded['request_id'] : '';
            $diag = 'Empty assistant response from chat API (could not read message content from JSON). '
                . 'Check provider, tools, and logs; the scheduled run was not marked successful. '
                . 'Cron invoke id: ' . $requestId
                . ($apiRid !== '' ? ' · chat request_id: ' . $apiRid : '')
                . "\n\nIf the model only queued a dashboard job, ensure api/chat.php is updated (jobToRun + non-empty content fallbacks). "
                . 'Raw response length: ' . strlen((string) $raw) . ' bytes.';
            $snippet = preg_replace('/\s+/', ' ', strip_tags(substr((string) $raw, 0, 600)));
            if ($snippet !== '') {
                $diag .= "\nSnippet: " . $snippet;
            }
            $resultForUi['ok'] = false;
            $resultForUi['error'] = 'Empty assistant response from chat API (model returned no visible text). Check provider, tools, and logs; the job was not marked successful.';
            $resultForUi['assistantContent'] = $diag;
            $resultForUi['summary'] = mb_substr($diag, 0, 2000);
            return ['ok' => false, 'error' => $resultForUi['error'], 'httpCode' => $code, 'requestId' => $requestId, 'summary' => $resultForUi['summary']];
        }

        // Model-only failure flag: still show hint text in UI; cron run counts as completed if we have usable text (tool digest, job queue, etc.).
        if (!empty($mg['empty_assistant'])) {
            $resultForUi['modelEmptyAssistant'] = true;
        }
        $summary = mb_substr($content, 0, 2000);
        $resultForUi['ok'] = true;
        $resultForUi['assistantContent'] = $content;
        $resultForUi['summary'] = $summary;
        $resultForUi['error'] = '';

        return ['ok' => true, 'summary' => $summary, 'fullContent' => $content, 'requestId' => $requestId];
    } finally {
        $resultForUi['finishedAt'] = time();
        mg_cron_run_result_save($requestId, $resultForUi);
        mg_cron_active_run_unregister($requestId);
    }
}

/**
 * Process due jobs one at a time (reloads store between runs). Uses non-blocking flock so overlapping ticks skip.
 *
 * @return array{ok:bool,ran:array,skipped?:bool,message?:string,error?:string}
 */
function mg_cron_run_tick(): array {
    $lockPath = mg_cron_runtime_dir() . DIRECTORY_SEPARATOR . 'tick.lock';
    $fh = @fopen($lockPath, 'c+');
    if ($fh === false) {
        return ['ok' => false, 'error' => 'tick lock open failed', 'ran' => []];
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return ['ok' => true, 'skipped' => true, 'message' => 'Another cron tick is running', 'ran' => []];
    }
    try {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
        $ran = [];
        $processed = [];

        while (true) {
            $now = time();
            $doc = mg_cron_load_raw();
            $next = null;
            foreach ($doc['jobs'] as $j) {
                if (!is_array($j)) {
                    continue;
                }
                $id = (string) ($j['id'] ?? '');
                if ($id === '' || isset($processed[$id])) {
                    continue;
                }
                if (mg_cron_job_is_due($j, $now)) {
                    $next = $j;
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $id = (string) ($next['id'] ?? '');
            $processed[$id] = true;

            $invoke = mg_cron_invoke_agent_job($next);
            $ok = !empty($invoke['ok']);
            $summary = $ok
                ? (string) ($invoke['summary'] ?? 'ok')
                : (string) ($invoke['error'] ?? 'failed');

            $jobNameForPending = (string) ($next['name'] ?? 'job');
            $bodyForMd = $ok
                ? (string) ($invoke['fullContent'] ?? $invoke['summary'] ?? '')
                : (string) ($invoke['error'] ?? 'failed');
            if (!$ok && !empty($invoke['summary'])) {
                $bodyForMd .= "\n\n" . (string) $invoke['summary'];
            }
            mg_cron_save_pending_output($id, $jobNameForPending, $ok, $bodyForMd, (string) ($invoke['requestId'] ?? ''));

            $upd = mg_cron_with_lock(function (array $d) use ($id, $now, $ok, $summary) {
                $out = [];
                foreach ($d['jobs'] as $job) {
                    if (!is_array($job) || ($job['id'] ?? '') !== $id) {
                        $out[] = $job;
                        continue;
                    }
                    // Only advance schedule / one-shot remove after a successful invoke.
                    // Otherwise failed runs would delete "at" jobs or burn a cron minute for nothing.
                    if ($ok) {
                        mg_cron_apply_after_fire($job, $now);
                    }
                    mg_cron_push_run_log($job, $ok, $summary);
                    if (empty($job['_remove'])) {
                        $out[] = $job;
                    }
                }
                $d['jobs'] = array_values($out);
                return $d;
            });

            if (empty($upd['ok'])) {
                $ran[] = ['id' => $id, 'ok' => false, 'summary' => 'Failed to persist cron state: ' . ($upd['error'] ?? '')];
                break;
            }

            $ran[] = [
                'id' => $id,
                'ok' => $ok,
                'summary' => mb_substr($summary, 0, 500),
                'requestId' => $invoke['requestId'] ?? null,
            ];
        }

        return ['ok' => true, 'ran' => $ran, 'count' => count($ran)];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function mg_cron_run_job_now(string $id): array {
    $id = trim($id);
    $job = mg_cron_find_job_by_id($id);
    if ($job === null) {
        return ['ok' => false, 'error' => 'job not found'];
    }
    $now = time();
    $invoke = mg_cron_invoke_agent_job($job);
    $ok = !empty($invoke['ok']);
    $summary = $ok ? (string) ($invoke['summary'] ?? 'ok') : (string) ($invoke['error'] ?? 'failed');

    $jobNameForPending = (string) ($job['name'] ?? 'job');
    $bodyForMd = $ok
        ? (string) ($invoke['fullContent'] ?? $invoke['summary'] ?? '')
        : (string) ($invoke['error'] ?? 'failed');
    if (!$ok && !empty($invoke['summary'])) {
        $bodyForMd .= "\n\n" . (string) $invoke['summary'];
    }
    mg_cron_save_pending_output($id, $jobNameForPending, $ok, $bodyForMd, (string) ($invoke['requestId'] ?? ''));

    $upd = mg_cron_with_lock(function (array $d) use ($id, $now, $ok, $summary) {
        $out = [];
        foreach ($d['jobs'] as $job) {
            if (!is_array($job) || ($job['id'] ?? '') !== $id) {
                $out[] = $job;
                continue;
            }
            mg_cron_push_run_log($job, $ok, $summary);
            $job['updatedAt'] = $now;
            $out[] = $job;
        }
        $d['jobs'] = array_values($out);
        return $d;
    });

    if (empty($upd['ok'])) {
        return ['ok' => false, 'error' => $upd['error'] ?? 'persist failed'];
    }
    return ['ok' => true, 'ran' => ['id' => $id, 'ok' => $ok, 'summary' => mb_substr($summary, 0, 500)]];
}
