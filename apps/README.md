# Web apps (`apps/`)

Each app is a folder: `apps/<slug>/index.html` plus optional `meta.json` (`title`, `updated`).

- **Slug**: lowercase letters, numbers, hyphens (e.g. `demo-counter`).
- The AI can manage apps with **list_web_apps**, **read_web_app**, **create_web_app**, **update_web_app**, **delete_web_app**, and show them with **display_web_app** (fullscreen modal in the dashboard).
- Apps are served at `api/serve_app.php?app=<slug>` (same origin).

Bundled sample: **demo-counter**.
