<?php
/**
 * Serve apps/<slug>/index.html (read-only, safe slug).
 * Opened inside #web-app-modal-frame: sandbox allows scripts, same-origin, pointer-lock, etc., so WebGL + PointerLockControls work.
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
$raw = @file_get_contents(web_app_index_path($slug));
if (!is_string($raw)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body><p>Could not read app.</p></body></html>';
    exit;
}
echo web_app_ensure_viewport_hooks($raw);
