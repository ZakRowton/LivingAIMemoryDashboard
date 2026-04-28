<?php
/**
 * Composed design preview (HTML + CSS + JS from designs/<slug>.*)
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'design_store.php';

$name = isset($_GET['name']) ? trim((string) $_GET['name']) : '';
$slug = design_normalize_slug($name);
if (!design_slug_valid($slug) || !design_exists($slug)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Design not found</title></head><body><p>Design not found.</p></body></html>';
    exit;
}
$html = design_build_preview_html($slug);
if ($html === null || $html === '') {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><p>Could not build preview.</p></body></html>';
    exit;
}
echo $html;
