<?php
/**
 * UI designs: four files per design (html, css, js, md) under designs/<slug>.<ext>
 */

function designs_root_path(): string {
    $d = __DIR__ . DIRECTORY_SEPARATOR . 'designs';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

function design_normalize_slug(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/\.(html|css|js|md)$/i', '', $s);
    $s = preg_replace('/[^a-z0-9_-]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

function design_slug_valid(string $slug): bool {
    return $slug !== '' && (bool) preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug);
}

function design_path(string $slug, string $ext): string {
    return designs_root_path() . DIRECTORY_SEPARATOR . $slug . '.' . $ext;
}

function design_node_id(string $slug): string {
    $slug = design_normalize_slug($slug);
    $safe = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $safe = trim($safe, '_');

    return 'design_file_' . ($safe !== '' ? $safe : 'design');
}

/**
 * @return array<int, string> Slugs that have all four files
 */
function design_list_slugs(): array {
    $dir = designs_root_path();
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $p) {
        $stem = pathinfo($p, PATHINFO_FILENAME);
        if (is_string($stem) && $stem !== '' && design_exists($stem)) {
            $out[] = $stem;
        }
    }
    usort($out, 'strcasecmp');

    return $out;
}

function design_exists(string $slug): bool {
    $slug = design_normalize_slug($slug);
    if (!design_slug_valid($slug)) {
        return false;
    }
    foreach (['html', 'css', 'js', 'md'] as $ext) {
        if (!is_file(design_path($slug, $ext))) {
            return false;
        }
    }

    return true;
}

/**
 * @return array<string, mixed>
 */
function design_get_meta(string $slug): ?array {
    if (!design_exists($slug)) {
        return null;
    }
    $slug = design_normalize_slug($slug);

    return [
        'name' => $slug,
        'nodeId' => design_node_id($slug),
        'updated' => (int) max(
            @filemtime(design_path($slug, 'html')) ?: 0,
            @filemtime(design_path($slug, 'css')) ?: 0,
            @filemtime(design_path($slug, 'js')) ?: 0,
            @filemtime(design_path($slug, 'md')) ?: 0
        ),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function list_designs_meta(): array {
    $out = [];
    foreach (design_list_slugs() as $s) {
        $m = design_get_meta($s);
        if ($m !== null) {
            $out[] = $m;
        }
    }

    return $out;
}

/**
 * @return array{html: string, css: string, js: string, md: string}
 */
function design_read_all(string $slug): ?array {
    if (!design_exists($slug)) {
        return null;
    }
    $slug = design_normalize_slug($slug);
    $read = static function (string $ext) use ($slug): string {
        $p = design_path($slug, $ext);
        if (!is_file($p)) {
            return '';
        }
        $raw = (string) file_get_contents($p);

        return $raw;
    };

    return [
        'html' => $read('html'),
        'css' => $read('css'),
        'js' => $read('js'),
        'md' => $read('md'),
    ];
}

/**
 * @return array<string, string>|array{error:string}
 */
function design_read_part(string $slug, string $part): array {
    $p = strtolower(trim($part));
    if (!in_array($p, ['html', 'css', 'js', 'md'], true)) {
        return ['error' => 'part must be html, css, js, or md'];
    }
    if (!design_exists($slug)) {
        return ['error' => 'Design not found'];
    }
    $slug = design_normalize_slug($slug);
    if (!is_file(design_path($slug, $p))) {
        return ['error' => 'Missing ' . $p . ' file'];
    }

    return [
        'name' => $slug,
        'part' => $p,
        'content' => (string) file_get_contents(design_path($slug, $p)),
    ];
}

/**
 * @return array<string, mixed>|array{error:string}
 */
function design_write_part(string $slug, string $part, string $content): array {
    $p = strtolower(trim($part));
    if (!in_array($p, ['html', 'css', 'js', 'md'], true)) {
        return ['error' => 'part must be html, css, js, or md'];
    }
    $slug = design_normalize_slug($slug);
    if (!design_slug_valid($slug)) {
        return ['error' => 'Invalid design name'];
    }
    if (!design_exists($slug)) {
        return ['error' => 'Design not found'];
    }
    if (file_put_contents(design_path($slug, $p), $content) === false) {
        return ['error' => 'Write failed'];
    }
    $meta = design_get_meta($slug);
    if ($meta !== null) {
        $meta['ok'] = true;

        return $meta;
    }

    return ['ok' => true, 'name' => $slug, 'nodeId' => design_node_id($slug), 'part' => $p];
}

const DEFAULT_DESIGN_HTML = "<div class=\"design-root\">\n  <h1>Design</h1>\n  <p>Preview this design in the viewer.</p>\n</div>\n";
const DEFAULT_DESIGN_CSS = "/* design */\nhtml, body { margin: 0; }\n.design-root {\n  font-family: system-ui, sans-serif;\n  padding: 1.25rem;\n  min-height: 100vh;\n  box-sizing: border-box;\n}\n";
const DEFAULT_DESIGN_JS = "// design script\n(function () {\n  console.log('Design loaded');\n})();\n";
const DEFAULT_DESIGN_MD = "# Design card\n\n## Description\n\nShort description of the layout and purpose.\n\n## Colors\n\n- **Primary:** #1e3a5f\n- **Accent:** #7cb8ff\n\n## Theme\n\nLight / dark, spacing, typography.\n\n## Controls\n\nButtons, inputs, navigation patterns.\n";

/**
 * @return array<string, mixed>
 */
function design_create(string $slug, ?string $html = null, ?string $css = null, ?string $js = null, ?string $md = null): array {
    $slug = design_normalize_slug($slug);
    if (!design_slug_valid($slug)) {
        return ['error' => 'Invalid design name: use letters, numbers, - and _'];
    }
    if (design_exists($slug)) {
        return ['error' => 'Design already exists', 'name' => $slug];
    }
    if (file_put_contents(design_path($slug, 'html'), $html !== null ? (string) $html : DEFAULT_DESIGN_HTML) === false) {
        return ['error' => 'Failed to create html'];
    }
    if (file_put_contents(design_path($slug, 'css'), $css !== null ? (string) $css : DEFAULT_DESIGN_CSS) === false) {
        @unlink(design_path($slug, 'html'));

        return ['error' => 'Failed to create css'];
    }
    if (file_put_contents(design_path($slug, 'js'), $js !== null ? (string) $js : DEFAULT_DESIGN_JS) === false) {
        @unlink(design_path($slug, 'html'));
        @unlink(design_path($slug, 'css'));

        return ['error' => 'Failed to create js'];
    }
    if (file_put_contents(design_path($slug, 'md'), $md !== null ? (string) $md : DEFAULT_DESIGN_MD) === false) {
        @unlink(design_path($slug, 'html'));
        @unlink(design_path($slug, 'css'));
        @unlink(design_path($slug, 'js'));

        return ['error' => 'Failed to create md'];
    }
    $meta = design_get_meta($slug);
    if ($meta !== null) {
        $meta['ok'] = true;

        return $meta;
    }

    return ['ok' => true, 'name' => $slug, 'nodeId' => design_node_id($slug)];
}

/**
 * @return array<string, mixed>
 */
function design_delete(string $slug): array {
    if (!design_exists($slug)) {
        return ['error' => 'Design not found'];
    }
    $slug = design_normalize_slug($slug);
    $ok = true;
    foreach (['html', 'css', 'js', 'md'] as $ext) {
        $p = design_path($slug, $ext);
        if (is_file($p) && !@unlink($p)) {
            $ok = false;
        }
    }
    if (!$ok) {
        return ['error' => 'Some files could not be deleted'];
    }

    return ['deleted' => true, 'name' => $slug];
}

/**
 * Build a single HTML document for iframe preview. The .html file is treated as **body inner HTML** unless it is a full document.
 */
function design_build_preview_html(string $slug): ?string {
    $all = design_read_all($slug);
    if ($all === null) {
        return null;
    }
    $rawHtml = (string) ($all['html'] ?? '');
    $css = (string) ($all['css'] ?? '');
    $js = (string) ($all['js'] ?? '');
    $rawHtml = trim($rawHtml);
    if (stripos($rawHtml, '<!DOCTYPE') === 0 || (stripos($rawHtml, '<html') === 0)) {
        if (preg_match('/<head[^>]*>([\s\S]*?)<\/head>/i', $rawHtml, $hm)) {
            if (stripos($hm[1], 'mg-design-injected') === false) {
                $inj = "\n<meta charset=\"utf-8\"><style id=\"mg-design-injected\">\n" . $css . "\n</style>\n";
                $rawHtml = preg_replace('/<head[^>]*>/i', '$0' . $inj, $rawHtml, 1);
            }
        }
        if (preg_match('/<body[^>]*>([\s\S]*?)<\/body>/i', $rawHtml)) {
            if (stripos($rawHtml, 'mg-design-js') === false && $js !== '') {
                $rawHtml = preg_replace('/<\/body>/i', '<script id="mg-design-js">' . $js . '</script></body>', $rawHtml, 1);
            }
        }

        return $rawHtml;
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Design: ' . htmlspecialchars($slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</title><style id=\"mg-design-injected\">" . $css . "\n</style></head><body>\n" . $rawHtml . "\n" . ($js !== '' ? '<script id="mg-design-js">' . $js . "</script>\n" : '') . "</body></html>\n";
}

/**
 * @return string Public URL path for design preview (relative to site root)
 */
function design_preview_url_path(string $slug): string {
    $slug = design_normalize_slug($slug);

    return 'api/serve_design.php?name=' . rawurlencode($slug);
}
