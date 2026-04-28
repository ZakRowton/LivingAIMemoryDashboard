<?php
/**
 * Opens a composed design in the same fullscreen modal as display_web_app.
 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'design_store.php';

$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];
$name = trim((string) ($arguments['name'] ?? $arguments['design'] ?? ''));
$slug = design_normalize_slug($name);

if (!design_slug_valid($slug) || !design_exists($slug)) {
    echo json_encode(['error' => 'Design not found', 'name' => $slug]);
    return;
}

$meta = design_get_meta($slug);
$title = 'Design: ' . $slug;
$url = design_preview_url_path($slug);

echo json_encode([
    'display_web_app' => true,
    'name' => $slug,
    'title' => $title,
    'url' => $url,
    'message' => 'Opening design preview **' . $slug . '** in the app viewer.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
