# Research: Using Cursor CLI as an MCP

**Source:** Brave Search result "MCP | Cursor Docs" (https://cursor.com/docs/cli/mcp)

## Overview
The Cursor CLI includes built‑in support for the **Model Context Protocol (MCP)**, allowing you to treat Cursor as an MCP server client. This enables you to:
- Register remote MCP servers (HTTP endpoints) from the command line.
- List, enable, and disable configured MCP servers.
- Invoke MCP‑exposed tools (e.g., GitHub, NEUS) directly via `cursor mcp run`.

## Key Commands
| Command | Description |
|---|---|
| `cursor mcp add <name> --url <url> [--header <key:value>...]` | Register a new MCP server. |
| `cursor mcp list` | Show all configured MCP servers, their URLs, and active status. |
| `cursor mcp enable <name>` / `cursor mcp disable <name>` | Toggle a server’s active flag. |
| `cursor mcp run <server> <tool> [--args ...]` | Execute a specific MCP tool (e.g., `github.create_pull_request`). |
| `cursor mcp config <name> --set <key>=<value>` | Update server configuration (headers, env vars, etc.). |

## Example Workflow
1. **Add a server**
   ```bash
   cursor mcp add mygithub --url https://api.github.com --header Authorization="token $GITHUB_TOKEN"
   ```
2. **List servers**
   ```bash
   cursor mcp list
   ```
3. **Run a tool** – create a pull request:
   ```bash
   cursor mcp run mygithub github.create_pull_request \
       --owner myorg \
       --repo myrepo \
       --title "Add new feature" \
       --head feature-branch \
       --base main \
       --body "This PR adds X, Y, Z."
   ```
4. **Disable a server** when not needed:
   ```bash
   cursor mcp disable mygithub
   ```

## Benefits of Using Cursor CLI as MCP
- **Unified interface** – manage multiple MCP services without leaving the terminal.
- **Automation** – combine Cursor commands with scripts for CI/CD pipelines.
- **Fine‑grained control** – set per‑server headers, environment variables, and toggle availability.
- **Extensibility** – any MCP‑compatible service (GitHub, NEUS, custom APIs) can be accessed.

## Additional Notes
- The CLI stores server definitions in a local config file (`~/.cursor/mcp.json`).
- When a server is disabled, its tools are ignored by `cursor mcp run`.
- Errors from remote MCP calls are surfaced as CLI error messages, making debugging straightforward.

---

## Executing Agent Tasks on Open Cursor Projects

The Cursor CLI can also act as a **local MCP server** (transport `stdio`). When you run `cursor mcp start` (or the built‑in `cursor mcp run` with a local server), it exposes a set of **agent‑focused tools** that let you:
- **Run code generation agents** (e.g., `cursor.agent.run`, `cursor.agent.create`).
- **Interact with the current workspace** – list files, read file contents, apply patches, and commit changes.
- **Invoke the built‑in Copilot‑style assistant** to refactor, test, or document code.

Typical workflow for a local project:
1. **Start the local MCP server** (automatically started when you use `cursor mcp run`).
2. **List available tools**:
   ```bash
   cursor mcp list-tools
   ```
   You’ll see entries such as `cursor.agent.run`, `cursor.file.read`, `cursor.file.write`, etc.
3. **Run an agent task** – for example, ask the assistant to add a unit test to a file:
   ```bash
   cursor mcp run local cursor.agent.run \
       --task "Add a unit test for function calculatePayroll in src/payroll.js" \
       --file src/payroll.js
   ```
   The agent will generate the test code, write it to a new file, and optionally commit it.
4. **Control the codebase remotely** – you can also expose the local MCP server over HTTP (`streamablehttp`) and let other services (CI, remote bots) invoke the same `cursor.agent.*` tools, giving you **remote agent codebase control**.

### Key Points
- **Local MCP server** enables direct manipulation of the current Cursor project (read/write files, run agents, execute tests).
- **Remote control** is possible by exposing the local server via an HTTP tunnel (e.g., using `ngrok`), then registering it as an MCP server on another machine.
- The **agent tools** are part of the Cursor MCP spec and are documented in the Cursor CLI help (`cursor mcp help`).
- You can combine these with other MCP services (GitHub, NEUS) to build end‑to‑end automation pipelines that edit code, push to GitHub, and trigger CI.

---

*This expanded summary now includes how the Cursor CLI can execute agent tasks on open projects and be used for remote agent codebase control.*
