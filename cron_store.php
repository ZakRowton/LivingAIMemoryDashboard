<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'cron_schedule.php';

function mg_cron_runtime_dir(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cron';
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

function mg_cron_jobs_path(): string {
    return mg_cron_runtime_dir() . DIRECTORY_SEPARATOR . 'jobs.json';
}

function mg_cron_new_job_id(): string {
    return bin2hex(random_bytes(16));
}

/** Stable graph node id: child under the Jobs hub (3D + panels). */
function mg_cron_job_node_id(string $jobId): string {
    $jobId = preg_replace('/[^a-f0-9]/i', '', $jobId);
    return 'job_cron_' . ($jobId !== '' ? strtolower($jobId) : 'unknown');
}

function mg_cron_load_raw(): array {
    $path = mg_cron_jobs_path();
    if (!is_file($path)) {
        return ['version' => 1, 'jobs' => []];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return ['version' => 1, 'jobs' => []];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['jobs']) || !is_array($decoded['jobs'])) {
        return ['version' => 1, 'jobs' => []];
    }
    $decoded['version'] = 1;
    $decoded['jobs'] = array_values($decoded['jobs']);
    return $decoded;
}

function mg_cron_save_raw(array $data): bool {
    $path = mg_cron_jobs_path();
    $data['version'] = 1;
    $data['jobs'] = array_values($data['jobs'] ?? []);
    return @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
}

/**
 * @param callable(array):array $mutator receives full document, returns new document
 */
function mg_cron_with_lock(callable $mutator): array {
    $path = mg_cron_jobs_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return ['ok' => false, 'error' => 'Cannot open cron jobs file'];
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return ['ok' => false, 'error' => 'Cannot lock cron jobs file'];
        }
        $size = filesize($path);
        $raw = $size > 0 ? stream_get_contents($fh) : '';
        $data = ['version' => 1, 'jobs' => []];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['jobs']) && is_array($decoded['jobs'])) {
                $data = $decoded;
            }
        }
        $data['version'] = 1;
        $data['jobs'] = array_values($data['jobs']);
        $out = $mutator($data);
        if (!is_array($out) || !isset($out['jobs'])) {
            flock($fh, LOCK_UN);
            return ['ok' => false, 'error' => 'Invalid cron store mutation'];
        }
        $out['version'] = 1;
        $out['jobs'] = array_values($out['jobs']);
        ftruncate($fh, 0);
        rewind($fh);
        $written = fwrite($fh, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fh);
        flock($fh, LOCK_UN);
        return ['ok' => $written !== false, 'data' => $out, 'error' => $written === false ? 'Write failed' : null];
    } finally {
        fclose($fh);
    }
}

function mg_cron_sanitize_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[\r\n\x00]/', '', $name);
    if (strlen($name) > 160) {
        $name = substr($name, 0, 160);
    }
    return $name;
}

function mg_cron_job_is_due(array $job, int $nowTs): bool {
    if (array_key_exists('enabled', $job) && $job['enabled'] === false) {
        return false;
    }
    $sched = isset($job['schedule']) && is_array($job['schedule']) ? $job['schedule'] : [];
    $kind = isset($sched['kind']) ? (string) $sched['kind'] : '';
    $rt = isset($job['runtime']) && is_array($job['runtime']) ? $job['runtime'] : [];

    if ($kind === 'at') {
        $atTs = isset($sched['atTs']) ? (int) $sched['atTs'] : 0;
        if ($atTs <= 0) {
            return false;
        }
        if (!empty($rt['atFired'])) {
            return false;
        }
        return $nowTs >= $atTs;
    }

    if ($kind === 'every') {
        $ms = isset($sched['everyMs']) ? (int) $sched['everyMs'] : 0;
        if ($ms < 5000) {
            return false;
        }
        $last = isset($rt['lastFiredAt']) ? (int) $rt['lastFiredAt'] : 0;
        $created = isset($job['createdAt']) ? (int) $job['createdAt'] : $nowTs;
        $anchor = $last > 0 ? $last : $created;
        return ($nowTs - $anchor) * 1000 >= $ms;
    }

    if ($kind === 'cron') {
        $expr = isset($sched['cron']) ? trim((string) $sched['cron']) : '';
        if ($expr === '' || !mg_cron_expression_valid($expr)) {
            return false;
        }
        $tz = mg_cron_parse_timezone(isset($sched['timezone']) ? (string) $sched['timezone'] : '');
        if ($tz === null) {
            return false;
        }
        $local = (new DateTimeImmutable('@' . $nowTs))->setTimezone($tz);
        $key = mg_cron_minute_key($local);
        if (isset($rt['lastCronMinuteKey']) && (string) $rt['lastCronMinuteKey'] === $key) {
            return false;
        }
        return mg_cron_matches_local_time($local, $expr);
    }

    return false;
}

function mg_cron_apply_after_fire(array &$job, int $nowTs): void {
    $sched = isset($job['schedule']) && is_array($job['schedule']) ? $job['schedule'] : [];
    $kind = isset($sched['kind']) ? (string) $sched['kind'] : '';
    if (!isset($job['runtime']) || !is_array($job['runtime'])) {
        $job['runtime'] = [];
    }
    $rt = &$job['runtime'];

    if ($kind === 'at') {
        $rt['atFired'] = true;
        $rt['lastFiredAt'] = $nowTs;
        if (!empty($sched['deleteAfterRun'])) {
            $job['_remove'] = true;
        } else {
            // One-shot: disable after a successful run (not recurring).
            $job['enabled'] = false;
        }
        return;
    }
    if ($kind === 'every') {
        $rt['lastFiredAt'] = $nowTs;
        return;
    }
    if ($kind === 'cron') {
        $tz = mg_cron_parse_timezone(isset($sched['timezone']) ? (string) $sched['timezone'] : '');
        if ($tz !== null) {
            $local = (new DateTimeImmutable('@' . $nowTs))->setTimezone($tz);
            $rt['lastCronMinuteKey'] = mg_cron_minute_key($local);
        }
        $rt['lastFiredAt'] = $nowTs;
    }
}

function mg_cron_push_run_log(array &$job, bool $ok, string $summary): void {
    if (!isset($job['runtime']) || !is_array($job['runtime'])) {
        $job['runtime'] = [];
    }
    $runs = isset($job['runtime']['lastRuns']) && is_array($job['runtime']['lastRuns']) ? $job['runtime']['lastRuns'] : [];
    $runs[] = [
        'at' => gmdate('c'),
        'ok' => $ok,
        'summary' => mb_substr($summary, 0, 800),
    ];
    if (count($runs) > 8) {
        $runs = array_slice($runs, -8);
    }
    $job['runtime']['lastRuns'] = $runs;
    $job['runtime']['fireCount'] = (int) ($job['runtime']['fireCount'] ?? 0) + 1;
    if (!$ok) {
        $job['runtime']['lastError'] = mb_substr($summary, 0, 500);
    } else {
        $job['runtime']['lastError'] = null;
    }
}

/**
 * Add job. schedule: kind at|every|cron + fields.
 */
function mg_cron_add_job(array $def): array {
    $name = mg_cron_sanitize_name((string) ($def['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'name is required'];
    }
    $message = isset($def['message']) ? trim((string) $def['message']) : '';
    if ($message === '') {
        return ['ok' => false, 'error' => 'message is required (agent prompt for this run)'];
    }
    if (strlen($message) > 32000) {
        return ['ok' => false, 'error' => 'message too long (max 32000 chars)'];
    }
    $kind = strtolower(trim((string) ($def['schedule_kind'] ?? $def['kind'] ?? '')));
    if (!in_array($kind, ['at', 'every', 'cron'], true)) {
        return ['ok' => false, 'error' => 'schedule_kind must be at, every, or cron'];
    }

    $schedule = ['kind' => $kind];
    $now = time();

    if ($kind === 'at') {
        $atStr = trim((string) ($def['at'] ?? ''));
        $atTs = mg_cron_parse_at_iso($atStr);
        if ($atTs === null) {
            return ['ok' => false, 'error' => 'Invalid at timestamp (use ISO 8601, e.g. 2026-03-21T15:30:00Z)'];
        }
        $schedule['at'] = $atStr;
        $schedule['atTs'] = $atTs;
        $schedule['deleteAfterRun'] = array_key_exists('delete_after_run', $def)
            ? (bool) $def['delete_after_run']
            : false;
    } elseif ($kind === 'every') {
        $everyMs = isset($def['every_ms']) ? (int) $def['every_ms'] : (isset($def['everyMs']) ? (int) $def['everyMs'] : 0);
        if ($everyMs < 5000 || $everyMs > 86400000 * 365) {
            return ['ok' => false, 'error' => 'every_ms must be between 5000 and 31536000000'];
        }
        $schedule['everyMs'] = $everyMs;
    } else {
        $cron = trim((string) ($def['cron'] ?? $def['cron_expression'] ?? ''));
        if ($cron === '' || !mg_cron_expression_valid($cron)) {
            return ['ok' => false, 'error' => 'cron must be a 5-field expression (minute hour dom month dow)'];
        }
        $schedule['cron'] = preg_replace('/\s+/', ' ', $cron);
        $tzName = trim((string) ($def['timezone'] ?? $def['tz'] ?? ''));
        if ($tzName === '') {
            $tzName = date_default_timezone_get() ?: 'UTC';
        }
        if (mg_cron_parse_timezone($tzName) === null) {
            return ['ok' => false, 'error' => 'Invalid timezone (IANA name, e.g. America/Chicago)'];
        }
        $schedule['timezone'] = $tzName;
    }

    $provider = isset($def['provider']) ? trim((string) $def['provider']) : '';
    $model = isset($def['model']) ? trim((string) $def['model']) : '';
    $temp = isset($def['temperature']) ? (float) $def['temperature'] : null;

    $job = [
        'id' => mg_cron_new_job_id(),
        'name' => $name,
        'enabled' => array_key_exists('enabled', $def) ? (bool) $def['enabled'] : true,
        'createdAt' => $now,
        'updatedAt' => $now,
        'schedule' => $schedule,
        'payload' => [
            'kind' => 'agentTurn',
            'message' => $message,
        ],
        'model' => [
            'provider' => $provider,
            'model' => $model,
            'temperature' => $temp,
        ],
        'runtime' => [
            'lastFiredAt' => null,
            'lastError' => null,
            'fireCount' => 0,
            'lastRuns' => [],
        ],
    ];

    $res = mg_cron_with_lock(function (array $doc) use ($job) {
        $doc['jobs'][] = $job;
        return $doc;
    });

    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => $res['error'] ?? 'save failed'];
    }
    return ['ok' => true, 'job' => $job];
}

function mg_cron_remove_job(string $id): array {
    $id = trim($id);
    if ($id === '' || strlen($id) > 64) {
        return ['ok' => false, 'error' => 'invalid job id'];
    }
    $before = 0;
    $res = mg_cron_with_lock(function (array $doc) use ($id, &$before) {
        $before = count($doc['jobs']);
        $doc['jobs'] = array_values(array_filter($doc['jobs'], function ($j) use ($id) {
            return !is_array($j) || (($j['id'] ?? '') !== $id);
        }));
        return $doc;
    });
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => $res['error'] ?? 'save failed'];
    }
    $after = count($res['data']['jobs'] ?? []);
    if ($after === $before) {
        return ['ok' => false, 'error' => 'job not found'];
    }
    return ['ok' => true, 'removed' => $id];
}

function mg_cron_list_jobs(): array {
    $doc = mg_cron_load_raw();
    $out = [];
    foreach ($doc['jobs'] as $j) {
        if (!is_array($j)) {
            continue;
        }
        $jid = (string) ($j['id'] ?? '');
        $enabledFlag = !array_key_exists('enabled', $j) || $j['enabled'] !== false;
        $out[] = [
            'id' => $jid,
            'nodeId' => mg_cron_job_node_id($jid),
            'name' => $j['name'] ?? '',
            'title' => $j['name'] ?? '',
            'active' => $enabledFlag,
            'enabled' => $enabledFlag,
            'schedule' => $j['schedule'] ?? [],
            'createdAt' => $j['createdAt'] ?? null,
            'updatedAt' => $j['updatedAt'] ?? null,
            'runtime' => $j['runtime'] ?? [],
            'messagePreview' => mb_substr((string) (($j['payload']['message'] ?? '')), 0, 200),
        ];
    }
    return $out;
}

function mg_cron_find_job_by_id(string $id): ?array {
    $id = trim($id);
    foreach (mg_cron_load_raw()['jobs'] as $j) {
        if (is_array($j) && ($j['id'] ?? '') === $id) {
            return $j;
        }
    }
    return null;
}

function mg_cron_set_enabled(string $id, bool $enabled): array {
    $id = trim($id);
    if ($id === '') {
        return ['ok' => false, 'error' => 'invalid job id'];
    }
    $found = false;
    $res = mg_cron_with_lock(function (array $doc) use ($id, $enabled, &$found) {
        foreach ($doc['jobs'] as &$j) {
            if (!is_array($j) || ($j['id'] ?? '') !== $id) {
                continue;
            }
            $j['enabled'] = $enabled;
            $j['updatedAt'] = time();
            $found = true;
            break;
        }
        unset($j);
        return $doc;
    });
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => $res['error'] ?? 'save failed'];
    }
    if (!$found) {
        return ['ok' => false, 'error' => 'job not found'];
    }
    return ['ok' => true];
}
