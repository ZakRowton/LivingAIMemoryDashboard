<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-MemoryGraph-Cron-Secret');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron_invoker.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function mg_cron_api_secret_ok(): bool {
    memory_graph_load_env();
    $secret = trim((string) memory_graph_env('MEMORYGRAPH_CRON_SECRET', ''));
    $provided = trim((string) ($_SERVER['HTTP_X_MEMORYGRAPH_CRON_SECRET'] ?? ''));
    if ($provided === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $raw = file_get_contents('php://input');
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($body) && isset($body['secret'])) {
            $provided = trim((string) $body['secret']);
        }
    }
    if ($secret !== '' && $provided !== '' && hash_equals($secret, $provided)) {
        return true;
    }
    return false;
}

function mg_cron_api_tick_allowed(): bool {
    if (mg_cron_api_secret_ok()) {
        return true;
    }
    memory_graph_load_env();
    $req = strtolower(trim((string) memory_graph_env('MEMORYGRAPH_CRON_REQUIRE_SECRET', '')));
    if ($req === '1' || $req === 'true' || $req === 'yes' || $req === 'on') {
        return false;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return $ip === '127.0.0.1' || $ip === '::1';
}

/** Scheme + host + port for this request (for same-origin UI checks). */
function mg_cron_api_expected_origin(): ?string {
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || $host === '*') {
        return null;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function mg_cron_api_origin_matches(string $expected, string $candidate): bool {
    $candidate = trim($candidate);
    if ($candidate === '') {
        return false;
    }
    $pe = parse_url($expected);
    $pc = parse_url($candidate);
    if (!is_array($pe) || !is_array($pc) || empty($pe['host']) || empty($pc['host'])) {
        return false;
    }
    if (strtolower((string) $pe['host']) !== strtolower((string) $pc['host'])) {
        return false;
    }
    $se = isset($pe['scheme']) ? strtolower((string) $pe['scheme']) : '';
    $sc = isset($pc['scheme']) ? strtolower((string) $pc['scheme']) : '';
    if ($se !== '' && $sc !== '' && $se !== $sc) {
        return false;
    }
    $defPe = $se === 'https' ? 443 : 80;
    $portE = isset($pe['port']) ? (int) $pe['port'] : $defPe;
    $defPc = $sc === 'https' ? 443 : 80;
    $portC = isset($pc['port']) ? (int) $pc['port'] : $defPc;
    return $portE === $portC;
}

/** True when the browser sent Origin or Referer matching this site's host (Run now / toggle / delete from the UI). */
function mg_cron_api_same_origin_browser(): bool {
    $exp = mg_cron_api_expected_origin();
    if ($exp === null) {
        return false;
    }
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && mg_cron_api_origin_matches($exp, $origin)) {
        return true;
    }
    $ref = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    return $ref !== '' && mg_cron_api_origin_matches($exp, $ref);
}

/**
 * UI management: run now, enable/disable, remove. Allows secret, localhost tick rules, or same-origin browser.
 * (Scheduled tick stays on mg_cron_api_tick_allowed only.)
 */
function mg_cron_api_manage_allowed(): bool {
    if (mg_cron_api_secret_ok()) {
        return true;
    }
    if (mg_cron_api_tick_allowed()) {
        return true;
    }
    return mg_cron_api_same_origin_browser();
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

if ($action === 'list' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(['ok' => true, 'jobs' => mg_cron_list_jobs()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_active' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(['ok' => true, 'runs' => mg_cron_list_active_runs()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'run_result' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $rid = trim((string) ($_GET['request_id'] ?? $_GET['requestId'] ?? ''));
    if ($rid === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'request_id required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = mg_cron_run_result_read($rid);
    if ($data === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Result not found or expired.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'result' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = isset($input['action']) ? (string) $input['action'] : $action;

if ($action === 'tick') {
    if (!mg_cron_api_tick_allowed()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cron tick forbidden. Use localhost, set MEMORYGRAPH_CRON_SECRET and pass it as X-MemoryGraph-Cron-Secret or JSON secret, or set MEMORYGRAPH_CRON_REQUIRE_SECRET=0 for localhost-only default.']);
        exit;
    }
    $out = mg_cron_run_tick();
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'run') {
    if (!mg_cron_api_manage_allowed()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden (use this app from the same host, localhost, or set MEMORYGRAPH_CRON_SECRET).']);
        exit;
    }
    $id = trim((string) ($input['job_id'] ?? $input['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'job_id required']);
        exit;
    }
    echo json_encode(mg_cron_run_job_now($id), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'remove_job') {
    if (!mg_cron_api_manage_allowed()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden (use this app from the same host, localhost, or set MEMORYGRAPH_CRON_SECRET).']);
        exit;
    }
    $id = trim((string) ($input['job_id'] ?? $input['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'job_id required']);
        exit;
    }
    $out = mg_cron_remove_job($id);
    if (empty($out['ok'])) {
        http_response_code(404);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'set_enabled') {
    if (!mg_cron_api_manage_allowed()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden (use this app from the same host, localhost, or set MEMORYGRAPH_CRON_SECRET).']);
        exit;
    }
    $id = trim((string) ($input['job_id'] ?? $input['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'job_id required']);
        exit;
    }
    $enabled = !empty($input['enabled']);
    $out = mg_cron_set_enabled($id, $enabled);
    if (empty($out['ok'])) {
        http_response_code(404);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action. Use tick, run, remove_job, set_enabled, GET list, GET list_active, or GET run_result']);
