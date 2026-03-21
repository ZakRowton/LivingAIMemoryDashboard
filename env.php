<?php

if (!function_exists('memory_graph_load_env')) {
    function memory_graph_load_env(?string $path = null): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $envPath = $path ?: (__DIR__ . DIRECTORY_SEPARATOR . '.env');
        if (!file_exists($envPath)) {
            $loaded = true;
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            $loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === '') {
                continue;
            }

            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $loaded = true;
    }
}

if (!function_exists('memory_graph_env_int')) {
    function memory_graph_env_int(string $key, int $default = 0): int {
        memory_graph_load_env();
        $v = memory_graph_env($key, '');
        if ($v === null || $v === '') {
            return $default;
        }
        return (int) $v;
    }
}

if (!function_exists('memory_graph_env')) {
    function memory_graph_env(string $key, ?string $default = null): ?string {
        memory_graph_load_env();

        $value = getenv($key);
        if ($value !== false) {
            return (string) $value;
        }
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        return $default;
    }
}
