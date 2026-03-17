<?php

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
        'description' => 'List all markdown memory files available, including active status and node ids.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists all markdown memory files and active state.",
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
        'description' => 'Create a new markdown instruction file in the instructions folder. Returns an error if the file already exists.',
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
        'description' => 'Modify an existing markdown instruction file in the instructions folder.',
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
        'name' => 'create_category_node',
        'description' => 'Create a new child category node under the Agent in the graph. The node appears in real-time. Use this when the user asks you to create a category (e.g. "database", "api", "cache") to organize or represent a concept, data store, or subsystem. Each category is a direct child of the Agent node.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Unique category name (e.g. database, api, cache). Used as the node identifier.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional display label. Defaults to name if empty.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional description of what this category represents.',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Creates a child category node under the Agent in the graph.",
    ], [
        'name' => 'list_category_nodes',
        'description' => 'List all category nodes that are direct children of the Agent in the graph.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists all category nodes.",
    ], [
        'name' => 'delete_category_node',
        'description' => 'Delete a category node by name. Removes it from the graph.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The category name to delete.',
                ],
            ],
            'required' => ['name'],
        ],
        'code' => "// Built-in tool\n// Deletes a category node by name.",
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
        'description' => 'List all configured MCP servers, including active state, transport, and node ids.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => new stdClass(),
        ],
        'code' => "// Built-in tool\n// Lists configured MCP servers.",
    ], [
        'name' => 'read_mcp_server',
        'description' => 'Read a configured MCP server definition by name.',
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
        'description' => 'Connect to a configured MCP server and list the tools it currently exposes.',
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
        'description' => 'Create a new MCP server definition. For stdio servers, provide command and optional args/env/cwd.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Unique MCP server name.'],
                'description' => ['type' => 'string', 'description' => 'Optional description for the MCP server.'],
                'transport' => ['type' => 'string', 'description' => 'Transport type. Defaults to stdio.'],
                'command' => ['type' => 'string', 'description' => 'Executable command for stdio MCP servers.'],
                'args' => ['type' => 'array', 'description' => 'Command arguments for stdio MCP servers.', 'items' => ['type' => 'string']],
                'env' => ['type' => 'object', 'description' => 'Environment variables for the MCP server process.'],
                'cwd' => ['type' => 'string', 'description' => 'Working directory for the MCP server process.'],
                'url' => ['type' => 'string', 'description' => 'Optional server URL for non-stdio transports.'],
                'headers' => ['type' => 'object', 'description' => 'Optional headers for non-stdio transports.'],
                'active' => ['type' => 'boolean', 'description' => 'Whether the MCP server should be enabled. Defaults to true.'],
            ],
            'required' => ['name', 'command'],
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
        'description' => 'Partially update an existing MCP server config by name. Use this to set command, args, cwd, env, headers, url, description, transport, or active without resending the full server definition.',
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
        'description' => 'Create a PHP tool file in the tools folder and create or update its entry in tool_calls.json. After creating, you MUST immediately call the new tool to test it. If it fails, use edit_tool_file to fix the code and test again. Never respond to the user until the tool works successfully.',
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
                    'description' => 'Complete PHP source code for the tool file.',
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
                    'description' => 'Complete replacement PHP source code for the tool file.',
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
        'description' => 'List recent chat exchanges (past conversations). Returns id, requestId, ts, and short previews. Use when you need to refer to earlier context that may have been truncated.',
        'active' => true,
        'builtin' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max number of exchanges to return (default 20, max 100).'],
                'offset' => ['type' => 'integer', 'description' => 'Skip this many from the start (for pagination).'],
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
        'description' => 'Add a model id to a provider\'s model list so it appears in the UI and can be selected. Use for both built-in and custom providers.',
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
