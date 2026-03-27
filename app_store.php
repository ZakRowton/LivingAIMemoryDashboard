<?php
/**
 * HTML/JS mini-apps under apps/<slug>/index.html (+ meta.json).
 */

function web_apps_root(): string {
    $d = __DIR__ . DIRECTORY_SEPARATOR . 'apps';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    return $d;
}

function web_app_normalize_slug(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9_-]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

function web_app_slug_valid(string $slug): bool {
    return $slug !== '' && (bool) preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug);
}

function web_app_dir(string $slug): string {
    return web_apps_root() . DIRECTORY_SEPARATOR . $slug;
}

function web_app_meta_path(string $slug): string {
    return web_app_dir($slug) . DIRECTORY_SEPARATOR . 'meta.json';
}

function web_app_index_path(string $slug): string {
    return web_app_dir($slug) . DIRECTORY_SEPARATOR . 'index.html';
}

function web_app_rrmdir(string $dir): bool {
    if (!is_dir($dir)) {
        return true;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($p)) {
            web_app_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    return @rmdir($dir);
}

function web_app_read_meta(string $slug): array {
    $p = web_app_meta_path($slug);
    if (!is_file($p)) {
        return ['title' => $slug, 'updated' => 0];
    }
    $raw = @file_get_contents($p);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        return ['title' => $slug, 'updated' => 0];
    }
    $j['title'] = isset($j['title']) && is_string($j['title']) ? $j['title'] : $slug;
    $j['updated'] = isset($j['updated']) ? (int) $j['updated'] : 0;
    return $j;
}

function web_app_write_meta(string $slug, string $title): void {
    $dir = web_app_dir($slug);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $payload = [
        'title' => $title !== '' ? $title : $slug,
        'updated' => time(),
    ];
    @file_put_contents(web_app_meta_path($slug), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/** Wrap fragment in a minimal document if needed. */
function web_app_wrap_html(string $html): string {
    $html = (string) $html;
    if (stripos($html, '<html') !== false) {
        return $html;
    }
    return '<!DOCTYPE html>' . "\n"
        . '<html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>App</title></head><body>' . $html . '</body></html>';
}

function web_app_exists(string $slug): bool {
    return web_app_slug_valid($slug) && is_file(web_app_index_path($slug));
}

/** @return list<array{name:string,title:string,updated:int,size:int}> */
function list_web_apps_meta(): array {
    $root = web_apps_root();
    $out = [];
    if (!is_dir($root)) {
        return $out;
    }
    foreach (glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (!web_app_slug_valid($slug)) {
            continue;
        }
        $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($idx)) {
            continue;
        }
        $meta = web_app_read_meta($slug);
        $out[] = [
            'name' => $slug,
            'title' => (string) ($meta['title'] ?? $slug),
            'updated' => (int) ($meta['updated'] ?? 0),
            'size' => (int) @filesize($idx),
        ];
    }
    usort($out, function ($a, $b) {
        return strcasecmp((string) $a['title'], (string) $b['title']);
    });
    return $out;
}

/** @return array{name:string,title:string,content:string,updated:int}|array{error:string} */
function get_web_app(string $name): array {
    $slug = web_app_normalize_slug($name);
    if (!web_app_slug_valid($slug) || !web_app_exists($slug)) {
        return ['error' => 'Web app not found'];
    }
    $meta = web_app_read_meta($slug);
    $raw = @file_get_contents(web_app_index_path($slug));
    $content = is_string($raw) ? $raw : '';
    return [
        'name' => $slug,
        'title' => (string) ($meta['title'] ?? $slug),
        'content' => $content,
        'updated' => (int) ($meta['updated'] ?? 0),
    ];
}

/** @return array{ok:bool,name:string,title:string}|array{error:string} */
function create_web_app(string $name, string $title, string $html): array {
    $slug = web_app_normalize_slug($name);
    if (!web_app_slug_valid($slug)) {
        return ['error' => 'Invalid app name; use letters, numbers, hyphens (e.g. my-counter).'];
    }
    if (web_app_exists($slug)) {
        return ['error' => 'Web app already exists: ' . $slug];
    }
    $wrapped = web_app_wrap_html($html);
    $dir = web_app_dir($slug);
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['error' => 'Could not create app directory'];
    }
    if (@file_put_contents(web_app_index_path($slug), $wrapped) === false) {
        web_app_rrmdir($dir);
        return ['error' => 'Could not write index.html'];
    }
    web_app_write_meta($slug, $title !== '' ? $title : $slug);
    return ['ok' => true, 'name' => $slug, 'title' => $title !== '' ? $title : $slug];
}

/** @return array{ok:bool,name:string}|array{error:string} */
function update_web_app(string $name, ?string $title, ?string $html): array {
    $slug = web_app_normalize_slug($name);
    if (!web_app_exists($slug)) {
        return ['error' => 'Web app not found'];
    }
    $changed = false;
    if ($html !== null && $html !== '') {
        $wrapped = web_app_wrap_html($html);
        if (@file_put_contents(web_app_index_path($slug), $wrapped) === false) {
            return ['error' => 'Could not write index.html'];
        }
        $changed = true;
    }
    if ($title !== null && $title !== '') {
        web_app_write_meta($slug, $title);
        $changed = true;
    } elseif ($changed) {
        web_app_write_meta($slug, (string) (web_app_read_meta($slug)['title'] ?? $slug));
    }
    if (!$changed) {
        return ['error' => 'Nothing to update: pass non-empty html and/or title'];
    }
    return ['ok' => true, 'name' => $slug];
}

/** @return array{ok:bool,deleted:string}|array{error:string} */
function delete_web_app(string $name): array {
    $slug = web_app_normalize_slug($name);
    if (!web_app_exists($slug)) {
        return ['error' => 'Web app not found'];
    }
    if (!web_app_rrmdir(web_app_dir($slug))) {
        return ['error' => 'Could not delete app folder'];
    }
    return ['ok' => true, 'deleted' => $slug];
}
