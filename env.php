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

        $rawFile = @file_get_contents($envPath);
        if ($rawFile === false) {
            $loaded = true;
            return;
        }
        if (strncmp($rawFile, "\xEF\xBB\xBF", 3) === 0) {
            $rawFile = substr($rawFile, 3);
        }
        $lines = preg_split('/\r\n|\r|\n/', $rawFile);
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

            $key = trim($parts[0], " \t\n\r\0\x0B\"'");
            $value = trim($parts[1], " \t\n\r\0\x0B");
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
    /**
     * Resolve env after .env is loaded. Prefer any non-empty value: empty getenv() / $_SERVER from the
     * OS or Apache must not hide a real key set in .env (PHP returns "" not false for empty vars).
     */
    function memory_graph_env(string $key, ?string $default = null): ?string {
        memory_graph_load_env();

        $candidates = [];
        if (array_key_exists($key, $_ENV)) {
            $candidates[] = (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            $candidates[] = (string) $_SERVER[$key];
        }
        $g = getenv($key);
        if ($g !== false) {
            $candidates[] = (string) $g;
        }
        foreach ($candidates as $v) {
            if ($v !== '') {
                return $v;
            }
        }
        return $default;
    }
}
