<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
if (function_exists('memory_graph_load_env')) {
    memory_graph_load_env();
}

function tool_dir_path(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'tools';
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

function tool_registry_path(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'tool_calls.json';
}

function sanitize_tool_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    $name = trim((string) $name, '_-');
    return strtolower((string) $name);
}

/**
 * Resolve CLI php.exe for `php -l` (Apache/mod_php often has no PATH + empty PHP_BINARY).
 */
function memory_graph_resolve_php_cli_binary(): ?string {
    if (function_exists('memory_graph_env')) {
        $env = trim((string) memory_graph_env('MEMORYGRAPH_PHP_CLI', ''));
        if ($env !== '' && is_file($env)) {
            return $env;
        }
    } else {
        $env = trim((string) (getenv('MEMORYGRAPH_PHP_CLI') ?: ''));
        if ($env !== '' && is_file($env)) {
            return $env;
        }
    }
    $candidates = [];
    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $base = basename(PHP_BINARY);
        $bl = strtolower($base);
        // mod_php / Apache: PHP_BINARY is often httpd.exe — never use it for `php -l`.
        $isApacheBinary = (strpos($bl, 'httpd') !== false || strpos($bl, 'apache') !== false);
        $looksLikePhpCli = (bool) preg_match('/^php\\d*(\\.exe)?$/', $bl)
            || strpos($bl, 'php-') === 0;
        if (!$isApacheBinary && $looksLikePhpCli) {
            $candidates[] = PHP_BINARY;
        }
        $dir = dirname(PHP_BINARY);
        if (stripos($base, 'php-cgi') !== false || stripos($base, 'php-fpm') !== false) {
            $candidates[] = $dir . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
        }
        // XAMPP: apache\bin\httpd.exe → ..\..\php\php.exe
        if (PHP_VERSION_ID >= 70000) {
            $xamppPhp = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
            if ($xamppPhp !== '' && @is_file($xamppPhp)) {
                $candidates[] = $xamppPhp;
            }
        }
    }
    if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
        $candidates[] = rtrim(PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = 'C:\\xampp\\php\\php.exe';
        $candidates[] = 'C:\\wamp64\\bin\\php\\php8.2.0\\php.exe';
    }
    $candidates[] = 'php';
    foreach ($candidates as $c) {
        if ($c === 'php') {
            return 'php';
        }
        if ($c !== '' && @is_file($c)) {
            return $c;
        }
    }
    return null;
}

/**
 * Strip markdown fences / BOM and ensure <?php so tokenizer and php -l match a real tool file.
 */
function sanitize_tool_php_code_from_llm(string $code): string {
    $code = (string) $code;
    if (strncmp($code, "\xEF\xBB\xBF", 3) === 0) {
        $code = substr($code, 3);
    }
    $code = trim($code);
    if (preg_match('/^```[a-zA-Z0-9_-]*\s*\R/', $code)) {
        $code = preg_replace('/^```[a-zA-Z0-9_-]*\s*\R/', '', $code, 1);
        $code = preg_replace('/\R```\s*$/', '', $code, 1);
    }
    $code = trim($code);
    if ($code !== '' && !preg_match('/^<\?php\b/i', $code) && !preg_match('/^<\?=\s*/', $code) && !preg_match('/^<\?\s+\S/', $code)) {
        $code = "<?php\n" . $code;
    }
    return $code;
}

function memory_graph_exec_disabled(): bool {
    $df = ini_get('disable_functions');
    if (!is_string($df) || $df === '') {
        return false;
    }
    foreach (array_map('trim', explode(',', $df)) as $fn) {
        if (strtolower($fn) === 'exec') {
            return true;
        }
    }
    return false;
}

/**
 * Parse-check PHP source without CLI (PHP 8+). Catches many syntax errors; not a full duplicate of `php -l`.
 */
function validate_tool_php_token_parse(string $phpCode): ?string {
    if (!defined('TOKEN_PARSE')) {
        return null;
    }
    $prev = @ini_set('display_errors', '0');
    try {
        token_get_all($phpCode, TOKEN_PARSE);
    } catch (ParseError $e) {
        if ($prev !== false) {
            @ini_set('display_errors', $prev);
        }
        return 'PHP parse error: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')';
    } catch (Throwable $e) {
        if ($prev !== false) {
            @ini_set('display_errors', $prev);
        }
        return 'PHP validation error: ' . $e->getMessage();
    }
    if ($prev !== false) {
        @ini_set('display_errors', $prev);
    }
    return null;
}

/**
 * Run php -l on tool source before writing. Catches duplicate functions, parse errors, etc.
 *
 * @return string|null Error message, or null if OK
 */
function validate_tool_php_before_save(string $phpCode): ?string {
    $phpCode = (string) $phpCode;
    if (trim($phpCode) === '') {
        return 'php_code is empty';
    }
    $tmp = @tempnam(sys_get_temp_dir(), 'mgt');
    if ($tmp === false) {
        return 'Could not create temp file for PHP validation';
    }
    $path = $tmp . '.php';
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return 'Could not prepare temp file for PHP validation';
    }
    if (@file_put_contents($path, $phpCode) === false) {
        @unlink($path);
        return 'Could not write temp file for PHP validation';
    }

    $cliFail = null;
    if (!memory_graph_exec_disabled() && function_exists('exec')) {
        $bin = memory_graph_resolve_php_cli_binary();
        if ($bin !== null) {
            $cmd = escapeshellarg($bin) . ' -l ' . escapeshellarg($path) . ' 2>&1';
            $out = [];
            $code = 0;
            @exec($cmd, $out, $code);
            if ($code === 0) {
                @unlink($path);
                return null;
            }
            $cliFail = trim(implode("\n", $out));
            if ($cliFail === '' && $bin === 'php') {
                $cliFail = 'php CLI missing from PATH. Set MEMORYGRAPH_PHP_CLI in .env to your php.exe (e.g. C:\\xampp\\php\\php.exe).';
            }
        }
    }

    @unlink($path);

    // PHP 8+: tokenizer proves syntax; ignore php -l failure when PATH has no `php` (typical XAMPP/Apache).
    if (defined('TOKEN_PARSE')) {
        $tok = validate_tool_php_token_parse($phpCode);
        if ($tok !== null) {
            return $tok;
        }
        return null;
    }

    if ($cliFail !== null && $cliFail !== '') {
        return 'PHP syntax check failed: ' . $cliFail;
    }
    return null;
}

function normalize_tool_parameters($parameters): array {
    if (!is_array($parameters)) {
        return [
            'type' => 'object',
            'properties' => new stdClass(),
        ];
    }
    $normalized = $parameters;
    $normalized['type'] = isset($normalized['type']) && is_string($normalized['type']) && $normalized['type'] !== ''
        ? $normalized['type']
        : 'object';
    if (!isset($normalized['properties']) || (!is_array($normalized['properties']) && !is_object($normalized['properties']))) {
        $normalized['properties'] = new stdClass();
    } elseif (is_array($normalized['properties']) && $normalized['properties'] === []) {
        $normalized['properties'] = new stdClass();
    }
    if (isset($normalized['required']) && !is_array($normalized['required'])) {
        unset($normalized['required']);
    }
    return $normalized;
}

function get_builtin_tools(): array {
    return [[
        'name' => 'list_available_tools',
        'description' => 'List all available tools, their metadata, current active state, and raw tool_calls.json contents.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists tool metadata, code, and raw tool_calls.json.",
    ], [
        'name' => 'list_tools',
        'description' => 'Alias for listing all available tools and the raw tool_calls.json contents.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in alias\n// Same behavior as list_available_tools.",
    ], [
        'name' => 'get_tools',
        'description' => 'Alias for listing all available tools and the raw tool_calls.json contents.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in alias\n// Same behavior as list_available_tools.",
    ], [
        'name' => 'list_memory_files',
        'description' => 'List markdown memory files: by default only active, non-hidden files (normal memories). Hidden archives include per-session chat transcripts memory/_chat_session_*.md — set include_hidden true to list those too.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'include_hidden' => [
                    'type' => 'boolean',
                    'description' => 'If true, include hidden transcript archives (_chat_*) and other hidden memories in the list.',
                ],
            ],
        ],
        'code' => "// Built-in tool\n// Lists markdown memory files; optional include_hidden for transcript archives.",
    ], [
        'name' => 'read_memory_file',
        'description' => 'Read a memory markdown file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The memory file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Reads a markdown memory file by name.",
    ], [
        'name' => 'add_memory_file',
        'description' => 'Create or overwrite a markdown memory file.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The memory file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Creates or overwrites a markdown memory file.",
    ], [
        'name' => 'create_memory_file',
        'description' => 'Create a new markdown memory file. Returns an error if the file already exists.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The memory file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Creates a new markdown memory file only if it does not already exist.",
    ], [
        'name' => 'update_memory_file',
        'description' => 'Modify an existing markdown memory file. Returns an error if the file does not exist.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The memory file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Updates an existing markdown memory file.",
    ], [
        'name' => 'delete_memory_file',
        'description' => 'Delete a markdown memory file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The memory file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a markdown memory file by name.",
    ], [
        'name' => 'list_instruction_files',
        'description' => 'List all markdown instruction files available in the instructions folder.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists all markdown instruction files.",
    ], [
        'name' => 'read_instruction_file',
        'description' => 'Read an instruction markdown file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The instruction file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Reads a markdown instruction file by name.",
    ], [
        'name' => 'create_instruction_file',
        'description' => 'Create a new markdown instruction file in the instructions folder (agent behavior / prompt snippets only). Returns an error if the file already exists. Do NOT use this to "set up MCP" or register remote servers — use create_mcp_server, configure_mcp_server, set_mcp_server_header, etc. Instructions files are NOT MCP configuration.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The instruction file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Creates a new markdown instruction file.",
    ], [
        'name' => 'update_instruction_file',
        'description' => 'Modify an existing markdown instruction file in the instructions folder (agent text only). Do NOT use this instead of MCP tools when the user wants MemoryGraph to connect to an MCP server.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The instruction file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Updates an existing markdown instruction file.",
    ], [
        'name' => 'delete_instruction_file',
        'description' => 'Delete a markdown instruction file from the instructions folder by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The instruction file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a markdown instruction file by name.",
    ], [
        'name' => 'list_job_files',
        'description' => 'List all markdown job files available in the jobs folder.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists all markdown job files.",
    ], [
        'name' => 'read_job_file',
        'description' => 'Read a job markdown file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The job file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Reads a markdown job file by name.",
    ], [
        'name' => 'create_job_file',
        'description' => 'Create a new markdown job file in the jobs folder. Returns an error if the file already exists.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The job file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown task list to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Creates a new markdown job file.",
    ], [
        'name' => 'update_job_file',
        'description' => 'Modify an existing markdown job file in the jobs folder.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The job file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown task list to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Updates an existing markdown job file.",
    ], [
        'name' => 'delete_job_file',
        'description' => 'Delete a markdown job file from the jobs folder by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The job file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a markdown job file by name.",
    ], [
        'name' => 'execute_job_file',
        'description' => 'Load a job markdown file and return its full contents so the AI can execute the listed tasks.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The job file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Loads a markdown job file for execution.",
    ], [
        'name' => 'list_web_apps',
        'description' => 'List HTML/JS mini-apps in the apps/ folder (name, title, updated, size). Use with create_web_app, display_web_app, etc.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists apps under apps/<slug>/index.html.",
    ], [
        'name' => 'read_web_app',
        'description' => 'Read one web app by slug/name: title and full index.html source (for editing).',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'App folder slug (e.g. demo-counter)',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Returns app HTML source.",
    ], [
        'name' => 'create_web_app',
        'description' => 'Create a new mini-app: apps/<slug>/index.html. Pass html as a full document or a body fragment (wrapped automatically). Slug is derived from name (letters, numbers, hyphens). Three.js/WebGL: prefer UMD three@0.128.0 only — script src https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js then examples/js/controls/OrbitControls.js or PointerLockControls.js (same version). Do NOT use three@0.159+ with /examples/js/ (that folder was removed — 404, black screen). ES modules: importmap + three@0.128.0/build/three.module.js + examples/jsm/.... Always renderer.setPixelRatio(Math.min(devicePixelRatio,2)), append renderer.domElement, window resize → setSize(innerWidth,innerHeight) + camera.aspect. Host injects iframe resize hooks; wrong CDN paths still fail.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Folder slug or human name (normalized to slug)',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Short display title for the UI',
                ],
                'html' => [
                    'type' => 'string',
                    'description' => 'HTML document or fragment (JS/CSS inline or in same file)',
                ],
            ],
            'required' => ['name', 'html'],
        ],
        'code' => "// Built-in tool\n// Writes apps/<slug>/index.html.",
    ], [
        'name' => 'update_web_app',
        'description' => 'Update an existing app: replace index.html and/or title. At least one of html or title must be non-empty.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'App slug'],
                'title' => ['type' => 'string', 'description' => 'Optional new title'],
                'html' => ['type' => 'string', 'description' => 'Optional new HTML body or document'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Patches apps/<slug>/.",
    ], [
        'name' => 'delete_web_app',
        'description' => 'Delete a mini-app folder from apps/ by slug.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'App slug to remove'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Removes apps/<slug>/ recursively.",
    ], [
        'name' => 'list_research_files',
        'description' => 'List all markdown research files available in the research folder.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists all markdown research files.",
    ], [
        'name' => 'read_research_file',
        'description' => 'Read a research markdown file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The research file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Reads a markdown research file by name.",
    ], [
        'name' => 'add_research_file',
        'description' => 'Create or overwrite a markdown research file.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The research file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Creates or overwrites a markdown research file.",
    ], [
        'name' => 'create_research_file',
        'description' => 'Create a new markdown research file only if it does not already exist.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The research file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Creates a new markdown research file.",
    ], [
        'name' => 'update_research_file',
        'description' => 'Modify an existing markdown research file.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The research file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Updates an existing markdown research file.",
    ], [
        'name' => 'delete_research_file',
        'description' => 'Delete a markdown research file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The research file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a markdown research file by name.",
    ], [
        'name' => 'list_rules_files',
        'description' => 'List all markdown rules files available in the rules folder.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists all markdown rules files.",
    ], [
        'name' => 'read_rules_file',
        'description' => 'Read a rules markdown file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The rules file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Reads a markdown rules file by name.",
    ], [
        'name' => 'add_rules_file',
        'description' => 'Create or overwrite a markdown rules file.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The rules file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Creates or overwrites a markdown rules file.",
    ], [
        'name' => 'create_rules_file',
        'description' => 'Create a new markdown rules file only if it does not already exist.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The rules file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Creates a new markdown rules file.",
    ], [
        'name' => 'update_rules_file',
        'description' => 'Modify an existing markdown rules file.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The rules file name, with or without .md',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The markdown content to save',
                ],
            ],
            'required' => ['name', 'content'],
        ],
        'code' => "// Built-in tool\n// Updates an existing markdown rules file.",
    ], [
        'name' => 'delete_rules_file',
        'description' => 'Delete a markdown rules file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The rules file name, with or without .md',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a markdown rules file by name.",
    ], [
        'name' => 'list_mcp_servers',
        'description' => 'List all configured MCP servers, including active state, transport, and node ids. Use proactively when planning work: discover which MCP backends exist so you can call their tools without the user asking.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists configured MCP servers.",
    ], [
        'name' => 'read_mcp_server',
        'description' => 'Read a configured MCP server definition by name. Use when you need transport/command details before invoking that server\'s tools.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The MCP server name.',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Reads one configured MCP server definition.",
    ], [
        'name' => 'list_mcp_server_tools',
        'description' => 'Connect to a configured MCP server and list the tools it currently exposes. Use proactively on active servers when a task might be solved by an MCP tool; then call those tools by name (often exposed as mcp__* in list_available_tools) without waiting for the user to mention MCP.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The MCP server name.',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Connects to an MCP server and lists its tools.",
    ], [
        'name' => 'create_mcp_server',
        'description' => 'Register a NEW MCP server in MemoryGraph (writes mcp_servers.json). This is the correct tool when the user asks to add, connect, or set up an MCP server in THIS app. For remote HTTP/SSE MCP endpoints: set transport to "streamablehttp", set url to the full MCP base URL (e.g. https://example.com/mcp), optional headers object for auth; omit command or use empty string. For local stdio servers: transport "stdio", command (required), optional args/env/cwd. After creating, call list_mcp_server_tools to verify connectivity. Do NOT write VS Code/Cursor .mcp.json or instruction markdown as a substitute.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Unique MCP server name.'],
                'description' => ['type' => 'string', 'description' => 'Optional description for the MCP server.'],
                'transport' => ['type' => 'string', 'description' => 'stdio (local process) or streamablehttp (remote URL). Use streamablehttp for https://... MCP endpoints.'],
                'command' => ['type' => 'string', 'description' => 'Required for stdio only: executable (e.g. npx). Leave empty for streamablehttp.'],
                'args' => ['type' => 'array', 'description' => 'Command arguments for stdio MCP servers.', 'items' => ['type' => 'string']],
                'env' => ['type' => 'object', 'description' => 'Environment variables for the MCP server process.'],
                'cwd' => ['type' => 'string', 'description' => 'Working directory for the MCP server process.'],
                'url' => ['type' => 'string', 'description' => 'Required for streamablehttp: full MCP HTTP endpoint URL.'],
                'headers' => ['type' => 'object', 'description' => 'Optional HTTP headers for streamablehttp (e.g. Authorization).'],
                'active' => ['type' => 'boolean', 'description' => 'Whether the MCP server should be enabled. Defaults to true.'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Creates a new MCP server definition.",
    ], [
        'name' => 'update_mcp_server',
        'description' => 'Update an existing MCP server definition by name. You can rename it by providing original_name and a new name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'original_name' => ['type' => 'string', 'description' => 'Current MCP server name to update.'],
                'name' => ['type' => 'string', 'description' => 'New or existing MCP server name.'],
                'description' => ['type' => 'string', 'description' => 'Updated description.'],
                'transport' => ['type' => 'string', 'description' => 'Updated transport type.'],
                'command' => ['type' => 'string', 'description' => 'Updated executable command for stdio servers.'],
                'args' => ['type' => 'array', 'description' => 'Updated command arguments.', 'items' => ['type' => 'string']],
                'env' => ['type' => 'object', 'description' => 'Updated environment variables.'],
                'cwd' => ['type' => 'string', 'description' => 'Updated working directory.'],
                'url' => ['type' => 'string', 'description' => 'Updated URL.'],
                'headers' => ['type' => 'object', 'description' => 'Updated headers.'],
                'active' => ['type' => 'boolean', 'description' => 'Updated active state.'],
            ],
            'required' => ['original_name'],
        ],
        'code' => "// Built-in tool\n// Updates an existing MCP server definition.",
    ], [
        'name' => 'configure_mcp_server',
        'description' => 'Partially update an existing MemoryGraph MCP server by name (url, transport streamablehttp, headers, command, active, etc.). Use when the user pastes an MCP URL or auth header for a server that already exists. Not for instruction files or external editor JSON.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Existing MCP server name to configure.'],
                'description' => ['type' => 'string', 'description' => 'Updated description.'],
                'transport' => ['type' => 'string', 'description' => 'Updated transport type.'],
                'command' => ['type' => 'string', 'description' => 'Updated executable command.'],
                'args' => ['type' => 'array', 'description' => 'Updated command arguments.', 'items' => ['type' => 'string']],
                'env' => ['type' => 'object', 'description' => 'Complete replacement env map for the server process.'],
                'cwd' => ['type' => 'string', 'description' => 'Updated working directory.'],
                'url' => ['type' => 'string', 'description' => 'Updated URL.'],
                'headers' => ['type' => 'object', 'description' => 'Complete replacement headers map.'],
                'active' => ['type' => 'boolean', 'description' => 'Updated enabled/disabled state.'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Partially configures an existing MCP server.",
    ], [
        'name' => 'set_mcp_server_env_var',
        'description' => 'Set or overwrite a single MCP server environment variable, such as AGENT_PRIVATE_KEY or API_KEY.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Existing MCP server name.'],
                'key' => ['type' => 'string', 'description' => 'Environment variable name to set.'],
                'value' => ['type' => 'string', 'description' => 'Environment variable value to save.'],
            ],
            'required' => ['name', 'key', 'value'],
        ],
        'code' => "// Built-in tool\n// Sets one MCP env var.",
    ], [
        'name' => 'remove_mcp_server_env_var',
        'description' => 'Remove a single environment variable from an MCP server config.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Existing MCP server name.'],
                'key' => ['type' => 'string', 'description' => 'Environment variable name to remove.'],
            ],
            'required' => ['name', 'key'],
        ],
        'code' => "// Built-in tool\n// Removes one MCP env var.",
    ], [
        'name' => 'set_mcp_server_header',
        'description' => 'Set or overwrite a single MCP server header value, such as Authorization.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Existing MCP server name.'],
                'key' => ['type' => 'string', 'description' => 'Header name to set.'],
                'value' => ['type' => 'string', 'description' => 'Header value to save.'],
            ],
            'required' => ['name', 'key', 'value'],
        ],
        'code' => "// Built-in tool\n// Sets one MCP header.",
    ], [
        'name' => 'remove_mcp_server_header',
        'description' => 'Remove a single header from an MCP server config.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Existing MCP server name.'],
                'key' => ['type' => 'string', 'description' => 'Header name to remove.'],
            ],
            'required' => ['name', 'key'],
        ],
        'code' => "// Built-in tool\n// Removes one MCP header.",
    ], [
        'name' => 'set_mcp_server_active',
        'description' => 'Enable or disable a configured MCP server by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The MCP server name.'],
                'active' => ['type' => 'boolean', 'description' => 'True to enable, false to disable.'],
            ],
            'required' => ['name', 'active'],
        ],
        'code' => "// Built-in tool\n// Enables or disables a configured MCP server.",
    ], [
        'name' => 'delete_mcp_server',
        'description' => 'Delete a configured MCP server definition by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The MCP server name to delete.'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a configured MCP server definition.",
    ], [
        'name' => 'create_or_update_tool',
        'description' => 'PRIMARY way to add new capabilities in MemoryGraph: writes tools/<name>.php and updates tool_calls.json on the user\'s server. You are expected to use this when the user asks for a new tool — do not claim you cannot create PHP tools here. First call list_available_tools (and list_mcp_servers + list_mcp_server_tools if MCP might cover it). Only skip creation if an existing or MCP tool already does the job. After saving, you MUST immediately call the new tool to test it; on failure use edit_tool_file and retry until success. Never tell the user the tool exists until a test call succeeds.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Tool name to create or update. This becomes tools/<name>.php and the tool_calls.json entry name.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Human-readable description of what the tool does.',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'JSON Schema object describing the tool arguments.',
                ],
                'php_code' => [
                    'type' => 'string',
                    'description' => 'Complete PHP source for tools/<name>.php. The server strips leading/trailing ``` fences and prepends <?php if missing (so syntax check passes). REQUIRED PATTERN (same as get_temperature.php): read $args = $GLOBALS[\'MEMORY_GRAPH_TOOL_INPUT\'] ?? []; then echo json_encode([...]); If you use a function named like the tool, wrap it in if (!function_exists(\'tool_name\')) { ... }. After saving, call the tool to verify.',
                ],
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Whether the tool should be active after saving. Defaults to true.',
                ],
            ],
            'required' => ['name', 'description', 'parameters', 'php_code'],
        ],
        'code' => "// Built-in tool\n// Creates or updates a PHP tool file and registry entry.",
    ], [
        'name' => 'edit_tool_file',
        'description' => 'Edit an existing custom PHP tool file in the tools folder by replacing its full PHP source.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Existing custom tool name.',
                ],
                'php_code' => [
                    'type' => 'string',
                    'description' => 'Full replacement PHP source. Use procedural MEMORY_GRAPH_TOOL_INPUT + echo json_encode, or function wrapped in if (!function_exists(...)). See create_or_update_tool.',
                ],
            ],
            'required' => ['name', 'php_code'],
        ],
        'code' => "// Built-in tool\n// Replaces a custom tool PHP file.",
    ], [
        'name' => 'edit_tool_registry_entry',
        'description' => 'Edit a custom tool entry in tool_calls.json, including description, parameters, and active state.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Existing custom tool name.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Updated human-readable description.',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Updated JSON Schema object for the tool arguments.',
                ],
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Updated active state for the tool.',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Updates a custom tool entry in tool_calls.json.",
    ], [
        'name' => 'delete_tool',
        'description' => 'Delete a custom PHP tool file from the tools folder and remove its entry from tool_calls.json.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Custom tool name to delete.',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a custom tool PHP file and its registry entry.",
    ], [
        'name' => 'get_current_provider_model',
        'description' => 'Get the currently selected AI provider and model. Returns provider key and model id used for chat.',
        'active' => true,
        'builtin' => true,
        'parameters' => ['type' => 'object', 'properties' => new stdClass()],
        'code' => "// Built-in tool\n// Returns current provider and model.",
    ], [
        'name' => 'set_provider_model',
        'description' => 'Change the selected AI provider and/or model. Persists so the UI and future requests use this provider and model.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'provider' => ['type' => 'string', 'description' => 'Provider key (e.g. mercury, gemini, or a custom key).'],
                'model' => ['type' => 'string', 'description' => 'Model id for that provider (e.g. mercury-2, gemini-2.5-flash).'],
            ],
            'required' => ['provider'],
        ],
        'code' => "// Built-in tool\n// Sets current provider and model.",
    ], [
        'name' => 'list_providers_models',
        'description' => 'List all configured AI providers and their available models (built-in and custom). Use this to see which provider/model to set.',
        'active' => true,
        'builtin' => true,
        'parameters' => ['type' => 'object', 'properties' => new stdClass()],
        'code' => "// Built-in tool\n// Lists providers and models.",
    ], [
        'name' => 'list_providers_available',
        'description' => 'List all available AI providers (keys and display names). Use this to see which providers are configured before listing models or setting provider.',
        'active' => true,
        'builtin' => true,
        'parameters' => ['type' => 'object', 'properties' => new stdClass()],
        'code' => "// Built-in tool\n// Lists available providers.",
    ], [
        'name' => 'list_models_for_provider',
        'description' => 'List all model ids available for a given provider. Pass the provider key (e.g. mercury, gemini). Use after list_providers_available to choose a model.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'providerKey' => ['type' => 'string', 'description' => 'Provider key (e.g. mercury, gemini, featherless, alibaba).'],
            ],
            'required' => ['providerKey'],
        ],
        'code' => "// Built-in tool\n// Lists models for a provider.",
    ], [
        'name' => 'list_chat_history',
        'description' => 'List recent chat exchanges (past conversations). Returns id, requestId, sessionId, ts, and short previews. Omit session_id to list all tabs; pass session_id from the system prompt (or current_session_only: true) to list only the current browser chat session.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max number of exchanges to return (default 20, max 100).'],
                'offset' => ['type' => 'integer', 'description' => 'Skip this many from the start (for pagination).'],
                'session_id' => ['type' => 'string', 'description' => 'If set, only exchanges from this browser tab session (see system prompt).'],
                'current_session_only' => ['type' => 'boolean', 'description' => 'If true, same as passing the current tab session id (no need to copy the id).'],
            ],
        ],
        'code' => "// Built-in tool\n// Lists recent chat history.",
    ], [
        'name' => 'get_chat_history',
        'description' => 'Get full content of a past chat exchange by id or requestId. Use after list_chat_history when you need the full user/assistant content of a previous conversation.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Exchange id or requestId from list_chat_history.'],
                'requestId' => ['type' => 'string', 'description' => 'Alternative: request_id of the chat to retrieve.'],
            ],
        ],
        'code' => "// Built-in tool\n// Retrieves one chat exchange.",
    ], [
        'name' => 'list_sub_agent_files',
        'description' => 'List all sub-agent markdown configuration files from sub-agents/.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists sub-agent config markdown files.",
    ], [
        'name' => 'read_sub_agent_file',
        'description' => 'Read one sub-agent markdown configuration file (provider/model/system prompt/API settings).',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Sub-agent file name, with or without .md'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Reads a sub-agent config file.",
    ], [
        'name' => 'create_sub_agent_file',
        'description' => 'Create a new sub-agent config markdown file. Provide either content (markdown) or config object.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'config' => ['type' => 'object'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Creates a sub-agent config file.",
    ], [
        'name' => 'update_sub_agent_file',
        'description' => 'Update an existing sub-agent config markdown file. Provide content or config.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'config' => ['type' => 'object'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Updates a sub-agent config file.",
    ], [
        'name' => 'delete_sub_agent_file',
        'description' => 'Delete a sub-agent markdown configuration file by name.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a sub-agent config file.",
    ], [
        'name' => 'run_sub_agent_chat',
        'description' => 'Run a sub-agent through the same MemoryGraph chat engine as Jarvis: active PHP tools, merged memory & rules, instructions, research, MCP, jobs, and web apps (requires MEMORYGRAPH_PUBLIC_BASE_URL and a registered provider key in the sub-agent config). `name` must be the exact sub-agents/*.md filename stem (call list_sub_agent_files if unsure). Optional dashboard_url / link in the sub-agent markdown for UI. Returns response text plus usage when available.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'prompt' => ['type' => 'string'],
                'messages' => ['type' => 'array', 'items' => ['type' => 'object']],
                'chatSessionId' => ['type' => 'string', 'description' => 'Optional: browser chat session id for list_chat_history alignment.'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Runs one sub-agent chat completion.",
    ], [
        'name' => 'start_sub_agent_chat',
        'description' => 'Queue a sub-agent chat task and return taskId + asyncSpawn (cli|http|none|skip|already). `name` must match a real file in sub-agents/*.md (stem without .md from list_sub_agent_files)—not a role label. Background execution uses MEMORYGRAPH_SUB_AGENT_ASYNC_SECRET; on Windows/single-thread hosts set MEMORYGRAPH_PHP_CLI so a detached PHP process can run the worker. When combining with other tools in one turn, prefer emitting this tool_call first so the worker starts immediately, then call list_* / brave_search / etc. in the same assistant message. Use get_sub_agent_chat_result to poll.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'prompt' => ['type' => 'string'],
                'messages' => ['type' => 'array', 'items' => ['type' => 'object']],
                'chatSessionId' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Creates a queued sub-agent task.",
    ], [
        'name' => 'get_sub_agent_chat_result',
        'description' => 'Read sub-agent task status/result by taskId. When status is done, assistantMessageExcerpt summarizes result.response for quick merging into Jarvis\'s answer.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string'],
            ],
            'required' => ['taskId'],
        ],
        'code' => "// Built-in tool\n// Reads queued sub-agent task result.",
    ], [
        'name' => 'wait_for_sub_agent_chat',
        'description' => 'Block until a queued/running sub-agent task completes (polls if another worker is executing). Optional timeoutSeconds (default 600). Prefer get_sub_agent_chat_result when Jarvis should keep working in parallel.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string'],
                'timeoutSeconds' => ['type' => 'integer', 'description' => 'Max wait (default 600, max 900).'],
            ],
            'required' => ['taskId'],
        ],
        'code' => "// Built-in tool\n// Resolves a sub-agent task to completion.",
    ], [
        'name' => 'add_provider',
        'description' => 'Add a new AI provider. Provide key, display name, endpoint (or endpointBase for Gemini-type), type (openai or gemini), defaultModel, and envVar (the .env variable name for the API key).',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Unique provider key (alphanumeric, underscores/dashes).'],
                'name' => ['type' => 'string', 'description' => 'Display name for the provider.'],
                'endpoint' => ['type' => 'string', 'description' => 'Chat completions URL for OpenAI-compatible APIs.'],
                'endpointBase' => ['type' => 'string', 'description' => 'Base URL for Gemini-type APIs (e.g. https://generativelanguage.googleapis.com/v1beta/models).'],
                'type' => ['type' => 'string', 'description' => 'openai or gemini.'],
                'defaultModel' => ['type' => 'string', 'description' => 'Default model id for this provider.'],
                'envVar' => ['type' => 'string', 'description' => '.env variable name for API key (e.g. MY_API_KEY).'],
            ],
            'required' => ['key'],
        ],
        'code' => "// Built-in tool\n// Adds a new provider.",
    ], [
        'name' => 'add_model_to_provider',
        'description' => 'Add a model id to a provider\'s model list so it appears in the UI and can be selected. If that id was previously hidden with remove_model_from_provider (built-in exclusion), this clears the exclusion and adds it to the custom list.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'providerKey' => ['type' => 'string', 'description' => 'Provider key (e.g. mercury, gemini).'],
                'modelId' => ['type' => 'string', 'description' => 'Model id to add (e.g. gemini-2.0, mercury-3).'],
            ],
            'required' => ['providerKey', 'modelId'],
        ],
        'code' => "// Built-in tool\n// Adds a model to a provider.",
    ], [
        'name' => 'remove_model_from_provider',
        'description' => 'Remove a model id from the provider selector. If it was added with add_model_to_provider, it is deleted from that list. If it is (or remains) a built-in default, it is hidden via config (excludedBuiltinModels) so it no longer appears—use add_model_to_provider with the same id to show it again. Cannot remove the last remaining model for a provider.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'providerKey' => ['type' => 'string', 'description' => 'Provider key (e.g. alibaba, gemini).'],
                'modelId' => ['type' => 'string', 'description' => 'Model id to remove from the selector (custom entry and/or hidden built-in).'],
            ],
            'required' => ['providerKey', 'modelId'],
        ],
        'code' => "// Built-in tool\n// Removes a custom-added model from a provider.",
    ]];
}

function builtin_tool_names(): array {
    return array_values(array_map(function ($tool) {
        return (string) ($tool['name'] ?? '');
    }, get_builtin_tools()));
}

function is_builtin_tool_name(string $name): bool {
    return in_array($name, builtin_tool_names(), true);
}

function read_tool_registry_data(): array {
    $path = tool_registry_path();
    $data = file_exists($path) ? json_decode((string) file_get_contents($path), true) : ['tools' => []];
    if (!is_array($data) || !isset($data['tools']) || !is_array($data['tools'])) {
        return ['tools' => []];
    }
    return $data;
}

function save_tool_registry_data(array $data): void {
    if (!isset($data['tools']) || !is_array($data['tools'])) {
        $data['tools'] = [];
    }
    file_put_contents(tool_registry_path(), json_encode($data, JSON_PRETTY_PRINT));
}

function tool_file_path(string $name): string {
    return tool_dir_path() . DIRECTORY_SEPARATOR . $name . '.php';
}

function read_tool_file_content(string $name): ?string {
    $safeName = sanitize_tool_name($name);
    if ($safeName === '') {
        return null;
    }
    $path = tool_file_path($safeName);
    if (!file_exists($path)) {
        return null;
    }
    return (string) file_get_contents($path);
}

function upsert_tool_registry_entry(string $name, array $entry): array {
    $safeName = sanitize_tool_name($name);
    if ($safeName === '') {
        return ['error' => 'Invalid tool name'];
    }
    if (is_builtin_tool_name($safeName)) {
        return ['error' => 'Built-in tools cannot be modified'];
    }
    $data = read_tool_registry_data();
    $normalizedEntry = [
        'name' => $safeName,
        'description' => isset($entry['description']) ? (string) $entry['description'] : '',
        'active' => array_key_exists('active', $entry) ? !empty($entry['active']) : true,
        'parameters' => normalize_tool_parameters($entry['parameters'] ?? null),
    ];

    $updated = false;
    foreach ($data['tools'] as &$tool) {
        if (($tool['name'] ?? '') === $safeName) {
            $tool = array_merge($tool, $normalizedEntry);
            $updated = true;
            break;
        }
    }
    unset($tool);
    if (!$updated) {
        $data['tools'][] = $normalizedEntry;
    }

    save_tool_registry_data($data);
    return $normalizedEntry;
}

function create_or_update_tool_artifact(string $name, string $description, $parameters, string $phpCode, bool $active = true): array {
    $safeName = sanitize_tool_name($name);
    if ($safeName === '') {
        return ['error' => 'Invalid tool name'];
    }
    if (is_builtin_tool_name($safeName)) {
        return ['error' => 'Built-in tools cannot be modified'];
    }
    $phpCode = sanitize_tool_php_code_from_llm($phpCode);
    $syntaxErr = validate_tool_php_before_save($phpCode);
    if ($syntaxErr !== null) {
        return ['error' => $syntaxErr];
    }
    file_put_contents(tool_file_path($safeName), $phpCode);
    $entry = upsert_tool_registry_entry($safeName, [
        'description' => $description,
        'parameters' => $parameters,
        'active' => $active,
    ]);
    if (isset($entry['error'])) {
        return $entry;
    }
    return [
        'success' => true,
        'name' => $safeName,
        'description' => $entry['description'],
        'active' => $entry['active'],
        'parameters' => $entry['parameters'],
        'php_code' => $phpCode,
        '__tool_registry_changed' => true,
    ];
}

function edit_tool_file_artifact(string $name, string $phpCode): array {
    $safeName = sanitize_tool_name($name);
    if ($safeName === '') {
        return ['error' => 'Invalid tool name'];
    }
    if (is_builtin_tool_name($safeName)) {
        return ['error' => 'Built-in tools cannot be modified'];
    }
    $registry = read_tool_registry_data();
    $existsInRegistry = false;
    foreach ($registry['tools'] as $tool) {
        if (($tool['name'] ?? '') === $safeName) {
            $existsInRegistry = true;
            break;
        }
    }
    if (!$existsInRegistry) {
        return ['error' => 'Tool not found in tool_calls.json'];
    }
    $phpCode = sanitize_tool_php_code_from_llm($phpCode);
    $syntaxErr = validate_tool_php_before_save($phpCode);
    if ($syntaxErr !== null) {
        return ['error' => $syntaxErr];
    }
    file_put_contents(tool_file_path($safeName), $phpCode);
    return [
        'success' => true,
        'name' => $safeName,
        'php_code' => $phpCode,
        '__tool_registry_changed' => true,
    ];
}

function edit_tool_registry_entry_artifact(string $name, array $changes): array {
    $safeName = sanitize_tool_name($name);
    if ($safeName === '') {
        return ['error' => 'Invalid tool name'];
    }
    if (is_builtin_tool_name($safeName)) {
        return ['error' => 'Built-in tools cannot be modified'];
    }
    $data = read_tool_registry_data();
    $updatedTool = null;
    foreach ($data['tools'] as &$tool) {
        if (($tool['name'] ?? '') !== $safeName) {
            continue;
        }
        if (array_key_exists('description', $changes)) {
            $tool['description'] = (string) $changes['description'];
        }
        if (array_key_exists('active', $changes)) {
            $tool['active'] = !empty($changes['active']);
        }
        if (array_key_exists('parameters', $changes)) {
            $tool['parameters'] = normalize_tool_parameters($changes['parameters']);
        } elseif (!isset($tool['parameters'])) {
            $tool['parameters'] = normalize_tool_parameters(null);
        }
        $updatedTool = $tool;
        break;
    }
    unset($tool);
    if ($updatedTool === null) {
        return ['error' => 'Tool not found in tool_calls.json'];
    }
    save_tool_registry_data($data);
    $updatedTool['parameters'] = normalize_tool_parameters($updatedTool['parameters'] ?? null);
    $updatedTool['__tool_registry_changed'] = true;
    return $updatedTool;
}

function delete_tool_artifact(string $name): array {
    $safeName = sanitize_tool_name($name);
    if ($safeName === '') {
        return ['error' => 'Invalid tool name'];
    }
    if (is_builtin_tool_name($safeName)) {
        return ['error' => 'Built-in tools cannot be deleted'];
    }
    $path = tool_file_path($safeName);
    $fileDeleted = false;
    if (file_exists($path)) {
        unlink($path);
        $fileDeleted = true;
    }

    $data = read_tool_registry_data();
    $beforeCount = count($data['tools']);
    $data['tools'] = array_values(array_filter($data['tools'], function ($tool) use ($safeName) {
        return ($tool['name'] ?? '') !== $safeName;
    }));
    $registryDeleted = count($data['tools']) !== $beforeCount;
    save_tool_registry_data($data);

    if (!$fileDeleted && !$registryDeleted) {
        return ['error' => 'Tool not found'];
    }

    return [
        'success' => true,
        'name' => $safeName,
        'file_deleted' => $fileDeleted,
        'registry_deleted' => $registryDeleted,
        '__tool_registry_changed' => true,
    ];
}
