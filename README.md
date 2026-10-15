# WordPress MCP Adapter: two editions

This repository ships the [official WordPress **MCP Adapter**](https://github.com/WordPress/mcp-adapter) in **two ready-to-install editions**. Both turn your WordPress site into a [Model Context Protocol (MCP)](https://modelcontextprotocol.io) server that AI agents (such as Claude Desktop) can connect to. They differ only in **how much the connected agent is allowed to do**.

| Edition | Download | What an agent can do | Can it edit site code? |
|---------|----------|----------------------|------------------------|
| **Restricted (Safe)** | `mcp-adapter.zip` | Manage content, media, users, menus, comments, plugins, themes, settings, SEO, and more (whatever your site exposes) | **No** |
| **Full (Site Abilities)** | `mcp-adapter-full.zip` | Everything the Restricted edition does, **plus** theme control and full `wp-content` file read, write, edit, and delete | **Yes** |

Install **one** edition, not both. The Full edition is a superset of the Restricted one, and both register the same default MCP server.

---

## What each edition can do (in detail)

The MCP Adapter exposes the WordPress **abilities** that are registered on your site. The exact set depends on your install and your plugins. On a managed WordPress site (for example a Hostinger-hosted site, where a rich set of WordPress abilities is registered), an agent connected through **either edition** can do the following.

### Available through both editions

- **Content:** create, read, update, delete posts, pages, and any custom post type; list post types; search with pagination.
- **Taxonomies:** categories and tags create, read, update, delete.
- **Media:** list, search, get, update, delete; fetch a file blob; sideload from a URL.
- **Users:** search, get, create, update, delete; current user.
- **Menus:** menus and menu items create, read, update, delete; assign menus to theme locations.
- **Template parts (block themes):** list, get, update, delete; assign navigation.
- **Comments:** search, create, update, approve or unapprove, spam or unspam, delete.
- **Plugins and themes:** list, install from wp.org, activate or deactivate, update, delete.
- **Settings:** general site settings; Hostinger plugin settings (maintenance mode, HTTPS, XML-RPC, and more); LiteSpeed cache settings, flush, presets.
- **SEO:** read and update metadata per post; bulk update up to 50 at once; site-wide audit (works with Yoast, Rank Math, AIOSEO, or a Hostinger fallback).
- **Revisions:** list, get, restore, delete.
- **Diagnostics:** site info; environment info (PHP, database, WordPress version).

### Restricted (Safe) edition stops there

`mcp-adapter.zip` is the unmodified official **MCP Adapter 0.7.0**. It cannot read, write, edit, or delete any file under `wp-content`, and it cannot change plugin or theme source code. Use this edition when you want AI access to your site **without** handing over the ability to change code.

### Full (Site Abilities) edition adds

`mcp-adapter-full.zip` is the same adapter **merged into a single plugin** with a bundled *Site Abilities* component. On top of everything above, it adds the abilities below. **All of them require the `manage_options` capability.**

- **Themes**
  - `site/themes-list` (read): list installed themes with slug, name, version, parent, active flag, and block-theme flag.
  - `site/theme-activate` (write): activate an installed theme by its stylesheet slug.
- **`wp-content` file management**, scoped to four roots: `themes`, `plugins`, `mu-plugins`, `uploads`.
  - `site/files-list` (read): browse files and directories, with a recursion depth from 1 to 6.
  - `site/file-read` (read): read a file up to 5 MB; returns a `sha1`; binary files come back base64-encoded.
  - `site/file-write` (write): create or overwrite a file, that is, **edit live code**. PHP is syntax-checked with `php -l` and the write is rejected on a syntax error; an optional `expected_sha1` refuses the write if the file changed since it was read; a `.bak` copy of the previous version is kept by default.
  - `site/file-delete` (destructive): delete a single file (directories are refused).

This is the capability that lets an agent **edit plugin and theme code directly** on the site.

#### Built-in guardrails (Full edition)

- **Capability gate:** every file and theme ability requires `manage_options`.
- **Scoped roots:** only `wp-content/{themes,plugins,mu-plugins,uploads}`. Resolved paths are re-checked to stay inside the root.
- **No path traversal:** `../` and null-byte paths are rejected.
- **Self-protection:** the abilities refuse to write or delete the Full plugin's own directory (and a separately installed `mcp-adapter` folder), so an agent cannot break the tool serving its request.
- **Safe writes:** PHP syntax check, optimistic `sha1` locking, and automatic `.bak` backups.
- **Size limit:** reads are capped at 5 MB.

> **Security note.** The Full edition lets an authenticated `manage_options` user, and therefore any MCP agent acting on their behalf, change live PHP on your site. That is intentional and powerful. Only install it on sites you fully own and trust, behind proper authentication. If you are not sure, use the Restricted edition.

---

## How it works

The MCP Adapter is a **bridge**, not an AI feature in itself. It connects two things:

1. **The WordPress Abilities API** (built into WordPress 6.9+). An *ability* is a single named capability, such as "read a post" or "write a file", registered by WordPress core or by a plugin, with its own input schema and its own permission check.
2. **The MCP protocol.** The adapter takes the abilities registered on your site, exposes the **public** ones to MCP clients as tools, and handles protocol negotiation, authentication, and permission enforcement.

```text
AI agent (MCP client, e.g. Claude Desktop)
      |  HTTP (MCP), authenticates as a WordPress user with an Application Password
      v
MCP Adapter  ->  default MCP server at  /wp-json/mcp/mcp-adapter-default-server
      |
      |- discover-abilities   -> list the public abilities
      |- get-ability-info     -> inspect one ability's schema
      |- execute-ability      -> run an ability by name, with parameters
                                   |
                                   v
                        WordPress Abilities API
                 (each ability runs its own permission check first)
```

Every ability is **opt-in**: it is reachable over MCP only if it is marked public, and the ability's own permission check still runs against the authenticated user before anything executes. The Restricted edition is "safe" precisely because it ships **no** file abilities. The Full edition is powerful because it bundles abilities that read and write code.

---

## Step-by-step: connect Claude Desktop to your site

This walks through the Full edition. The steps are identical for the Restricted edition; you just upload the other zip in step 1.

### Step 1: Install the plugin

1. Download the zip for the edition you want from the [**Releases**](https://github.com/ghayasleo/wp-mcp-adapter/releases) page (`mcp-adapter-full.zip` or `mcp-adapter.zip`).
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Choose the zip, click **Install Now**, then **Activate**.
4. Make sure you are on **WordPress 6.9 or newer** (the Abilities API ships with core from 6.9) and **PHP 7.4 or newer**.

### Step 2: Create an Application Password

The MCP client authenticates as a WordPress user. Use an Application Password, not your login password.

1. In WordPress admin, go to **Users > Profile** (or **Users > All Users** and edit the user you want the agent to act as; it needs `manage_options` for the Full edition's abilities).
2. Scroll to **Application Passwords**.
3. Type a name (for example `Claude Desktop`) and click **Add New Application Password**.
4. Copy the generated password. It is six groups of four characters, like `xxxx xxxx xxxx xxxx xxxx xxxx`. WordPress shows it only once. The spaces are part of it and can be kept.

### Step 3: Open the Claude Desktop config

1. Open **Claude Desktop**.
2. Go to **Settings > Developer**.
3. Click **Edit Config**. This opens `claude_desktop_config.json` in your editor.

### Step 4: Add the server and fill in your details

Paste the block below into the `mcpServers` object. If the file is empty, use the whole structure shown here.

```json
{
  "mcpServers": {
    "example-wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "admin",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Update four things:

- **Server name:** `example-wordpress` is just a label. Rename it to anything you like.
- **`WP_API_URL`:** replace `example.com` with your site domain. Keep the `/wp-json/mcp/mcp-adapter-default-server` path, which is the default endpoint this plugin registers.
- **`WP_API_USERNAME`:** the WordPress username the Application Password belongs to.
- **`WP_API_PASSWORD`:** the Application Password you copied in step 2.

This uses the [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) bridge, which Claude Desktop runs with `npx`, so you need **Node.js** installed. The bridge connects Claude Desktop to the MCP endpoint your plugin exposes.

### Step 5: Restart and verify

1. Save the config file.
2. Quit Claude Desktop completely and reopen it.
3. The server appears in Claude Desktop's tools. Ask the agent something simple first, for example "list my site's installed themes" (Full edition) or "list my recent posts", to confirm the connection.

If the server does not appear, recheck the JSON for a missing comma or brace, confirm the URL resolves in a browser, and confirm the username and Application Password match.

---

## Switching editions

To switch editions, deactivate and delete the one you have, then upload and activate the other. Do not run both at the same time, because both register the same default MCP server id and REST route.

---

## Credits and license

The MCP Adapter is developed by the [WordPress.org contributors](https://github.com/WordPress/mcp-adapter) as part of the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative, and is licensed **GPL-2.0-or-later**. The bundled Site Abilities component in the Full edition is distributed under the same license.
