<?php
/**
 * Serve apps/<slug>/index.html (read-only, safe slug).
 */
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app_store.php';

$app = isset($_GET['app']) ? (string) $_GET['app'] : '';
$slug = web_app_normalize_slug($app);
if (!web_app_slug_valid($slug) || !web_app_exists($slug)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not found</title></head><body><p>App not found.</p></body></html>';
    exit;
}
readfile(web_app_index_path($slug));
