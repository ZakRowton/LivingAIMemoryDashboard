<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'cron_store.php';

function mg_cron_pending_dir(): string {
    $d = mg_cron_runtime_dir() . DIRECTORY_SEPARATOR . 'pending';
    if (!is_dir($d)) {
        @mkdir($d, 0777, true);
    }
    return $d;
}

/**
 * Write one delivery payload after a cron-triggered chat completes.
 * Filename: {jobId}_{Ymd_His}.md under runtime/cron/pending/
 */
function mg_cron_save_pending_output(string $jobId, string $jobName, bool $ok, string $bodyText, string $requestId): string {
    $jobId = preg_replace('/[^a-f0-9]/i', '', $jobId);
    if ($jobId === '') {
        $jobId = 'unknown';
    }
    $jobName = trim(preg_replace('/[\r\n\x00]/', '', $jobName));
    if (strlen($jobName) > 200) {
        $jobName = substr($jobName, 0, 200);
    }
    $dir = mg_cron_pending_dir();
    $fn = $jobId . '_' . gmdate('Ymd_His') . '.md';
    $path = $dir . DIRECTORY_SEPARATOR . $fn;
    $header = "# Cron job output\n\n"
        . '- **jobId:** `' . $jobId . "`\n"
        . '- **jobName:** ' . str_replace(["\r", "\n"], ' ', $jobName) . "\n"
        . '- **ok:** ' . ($ok ? 'true' : 'false') . "\n"
        . '- **finishedAt (UTC):** ' . gmdate('c') . "\n"
        . '- **requestId:** `' . preg_replace('/[^\w\-]/', '_', $requestId) . "`\n\n"
        . "---\n\n";
    $bodyText = (string) $bodyText;
    if (strlen($bodyText) > 120000) {
        $bodyText = substr($bodyText, 0, 120000) . "\n\n[truncated]\n";
    }
    @file_put_contents($path, $header . $bodyText);
    return $path;
}

/**
 * @return array{paths: string[], block: string}
 */
function mg_cron_pending_build_for_chat(): array {
    $dir = mg_cron_pending_dir();
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [];
    $pairs = [];
    foreach ($files as $path) {
        if (!is_string($path) || !is_file($path)) {
            continue;
        }
        $pairs[] = ['path' => $path, 'mtime' => (int) @filemtime($path)];
    }
    usort($pairs, function ($a, $b) {
        return ($a['mtime'] <=> $b['mtime']);
    });
    if ($pairs === []) {
        return ['paths' => [], 'block' => ''];
    }
    $paths = [];
    $chunks = [];
    foreach ($pairs as $row) {
        $path = $row['path'];
        $paths[] = $path;
        $raw = @file_get_contents($path);
        $chunks[] = '#### `pending/' . basename($path) . "`\n\n" . (is_string($raw) ? $raw : '');
    }
    $block = "## Pending scheduled cron output (must deliver to user)\n\n"
        . "One or more files exist under `runtime/cron/pending/` (same folder as the cron job store). "
        . "**Any `.md` file there means a scheduled cron job already finished since the user's last normal chat message** — not a hypothetical future run.\n\n"
        . "In your reply you MUST:\n"
        . "1. Tell the user explicitly that scheduled job(s) ran and report the substantive outcome (summarize or quote the blocks below).\n"
        . "2. Do not imply the user just asked for this run; it was automatic.\n\n"
        . "After this HTTP response completes, the server removes these files automatically. You do not delete them via tools.\n\n"
        . implode("\n\n---\n\n", $chunks);
    return ['paths' => $paths, 'block' => $block];
}

function mg_cron_pending_delete_paths(array $paths): void {
    $base = realpath(mg_cron_pending_dir());
    if ($base === false || $base === '') {
        return;
    }
    $base = rtrim(str_replace('\\', '/', $base), '/');
    foreach ($paths as $p) {
        if (!is_string($p) || $p === '') {
            continue;
        }
        $rp = realpath($p);
        if ($rp === false) {
            continue;
        }
        $norm = str_replace('\\', '/', $rp);
        if (strpos($norm, $base) !== 0) {
            continue;
        }
        if (is_file($rp)) {
            @unlink($rp);
        }
    }
}
