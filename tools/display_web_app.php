<?php
/**
 * Opens a saved HTML/JS app in the dashboard fullscreen viewer (client reads display_web_app flag).
 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app_store.php';

$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];
$name = trim((string) ($arguments['name'] ?? $arguments['app'] ?? ''));
$slug = web_app_normalize_slug($name);

if (!web_app_slug_valid($slug) || !web_app_exists($slug)) {
    echo json_encode(['error' => 'Web app not found', 'name' => $slug]);
    return;
}

$meta = web_app_read_meta($slug);
$title = (string) ($meta['title'] ?? $slug);
$url = 'api/serve_app.php?app=' . rawurlencode($slug);

echo json_encode([
    'display_web_app' => true,
    'name' => $slug,
    'title' => $title,
    'url' => $url,
    'message' => 'Opening **' . $title . '** in the maximized app viewer for the user.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
