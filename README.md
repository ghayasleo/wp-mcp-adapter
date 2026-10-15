# WordPress MCP Adapter — two editions

This repository ships the [official WordPress **MCP Adapter**](https://github.com/WordPress/mcp-adapter) in **two ready-to-install editions**. Both turn your WordPress site into a [Model Context Protocol (MCP)](https://modelcontextprotocol.io) server that AI agents can connect to. They differ only in **how much the connected agent is allowed to do**.

| Edition | Download | What an agent can do | Can it edit site code? |
|---|---|---|---|
| **Restricted (Safe)** | `mcp-adapter.zip` | Discover and run whatever abilities the site exposes as public | **No** |
| **Full (Site Abilities)** | `mcp-adapter-full.zip` | Everything the restricted edition does, **plus** theme control and full `wp-content` file read/write/edit/delete | **Yes** |

> Install **one** edition, not both — the Full edition is a superset of the Restricted one and both register the same default MCP server.

---

## How it works

The MCP Adapter is a **bridge**, not an AI feature in itself. It connects two things:

1. **The WordPress Abilities API** (built into WordPress 6.9+). An *ability* is a single, named capability — "read a post", "get site info", "write a file" — registered by WordPress core or by a plugin, with its own input schema and its own permission check.
2. **The MCP protocol.** The adapter takes the abilities that are registered on your site, exposes the **public** ones to MCP clients as tools/resources/prompts, and handles protocol negotiation, authentication and permission enforcement.

```
AI agent (MCP client)
      │  HTTP (MCP)   authenticates as a WordPress user
      ▼
MCP Adapter  ──►  default MCP server at  /wp-json/mcp/mcp-adapter-default-server
      │
      ├─ discover-abilities   → list the public abilities
      ├─ get-ability-info     → inspect one ability's schema
      └─ execute-ability      → run an ability by name, with parameters
                                   │
                                   ▼
                        WordPress Abilities API
                 (each ability runs its own permission check first)
```

Every ability is **opt-in**: it is only reachable over MCP if it is marked public (`meta.public` / `meta.mcp.public`), and the ability's own `permission_callback` still runs against the authenticated user before anything executes. The adapter adds no new capability on its own — the Restricted edition is "safe" precisely because it ships **no** site-management or file abilities. The Full edition is "powerful" because it bundles abilities that do.

**Connecting:** point any MCP-compatible client at the default endpoint (`/wp-json/mcp/mcp-adapter-default-server`) and authenticate as a WordPress user. See the adapter's [getting-started guide](https://github.com/WordPress/mcp-adapter/tree/trunk/docs/getting-started) for transport and authentication details. Requires **WordPress 6.9+** and **PHP 7.4+**.

---

## Restricted (Safe) edition — `mcp-adapter.zip`

The unmodified official **MCP Adapter 0.7.0**. It provides the protocol layer and three built-in meta-tools:

- **`mcp-adapter/discover-abilities`** — list the public abilities available on the site.
- **`mcp-adapter/get-ability-info`** — return the schema and metadata for a named ability.
- **`mcp-adapter/execute-ability`** — run any **public** ability by name with the supplied parameters.

**What it can do:** exactly what the public abilities on your site allow — nothing more. On a stock install that is read-oriented and limited; it grows only if you (or another plugin) register and publish more abilities.

**What it cannot do:** it ships no file, theme, or site-management abilities. It **cannot read, write, or edit any file under `wp-content`, and cannot modify plugin or theme code.** This is the edition to use when you want AI access to your site without handing over the ability to change code.

---

## Full (Site Abilities) edition — `mcp-adapter-full.zip`

The same adapter, **merged into a single plugin** with a bundled *Site Abilities* component (`mcp-site-abilities.php`). It does everything the Restricted edition does, and adds the abilities below. **All of them require the `manage_options` capability.**

### Core info (marked public by this edition)
- `core/get-site-info`, `core/get-user-info`, `core/get-environment-info` — WordPress core read abilities, exposed over MCP.

### Theme management
| Ability | Type | What it does |
|---|---|---|
| `site/themes-list` | read | List installed themes (slug, name, version, parent, active flag, block-theme flag, directory). |
| `site/theme-activate` | write | Activate an installed theme by its stylesheet slug. |

### `wp-content` file management
Scoped to four roots under `wp-content`: **`themes`, `plugins`, `mu-plugins`, `uploads`.**

| Ability | Type | What it does |
|---|---|---|
| `site/files-list` | read | List files/directories under a root, with a configurable recursion depth (1–6). |
| `site/file-read` | read | Read a file (≤ 5 MB). Returns content + a `sha1`; binary files come back base64-encoded. |
| `site/file-write` | **write / destructive** | Create or overwrite a file. PHP is syntax-checked with `php -l` before the write and **rejected on a syntax error**; an optional `expected_sha1` refuses the write if the file changed since it was read; a `.bak` of the previous version is kept by default. |
| `site/file-delete` | **destructive** | Delete a single file (directories are refused). |

**So, in practice:** an agent can list and read plugin/theme files, edit live PHP, create new files, and delete files — i.e. **modify code anywhere under `wp-content`.**

### Built-in guardrails
Even in the Full edition, the file abilities are constrained:
- **Capability gate** — every ability requires `manage_options`.
- **Scoped roots** — only `wp-content/{themes,plugins,mu-plugins,uploads}`; nothing elsewhere on the server. Resolved paths are re-checked to stay inside the root.
- **No path traversal** — `../` and null-byte paths are rejected.
- **Self-protection** — the abilities refuse to write or delete the Full plugin's own directory (and a separately installed `mcp-adapter` folder), so an agent cannot break the tool serving its request.
- **Safe writes** — PHP syntax check, optimistic `sha1` locking, and automatic `.bak` backups.
- **Size limit** — reads are capped at 5 MB.

### ⚠ Security
The Full edition lets an authenticated `manage_options` user (and therefore any MCP agent acting on their behalf) **change live PHP on your site.** That is intentional and powerful. Only install it on sites you fully own and trust, behind proper authentication. If you are not sure, use the **Restricted** edition.

---

## Installing

1. Download the zip for the edition you want from the [**Releases**](https://github.com/ghayasleo/wp-mcp-adapter/releases) page.
2. In WordPress admin: **Plugins → Add New → Upload Plugin**, choose the zip, **Install Now**, then **Activate**.
3. Make sure you are on **WordPress 6.9+** (the Abilities API ships with core from 6.9).
4. Point your MCP client at `/wp-json/mcp/mcp-adapter-default-server` and authenticate.

To switch editions, deactivate and delete the one you have before installing the other.

---

## Credits & license

The MCP Adapter is developed by the [WordPress.org contributors](https://github.com/WordPress/mcp-adapter) as part of the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative, and is licensed **GPL-2.0-or-later**. The bundled Site Abilities component in the Full edition is distributed under the same license.
