# Commander — Secure MCP Control

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)
[![Requires PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)](composer.json)
[![Requires WP](https://img.shields.io/badge/WordPress-6.2%2B-21759B.svg)](readme.txt)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18-7C3AED.svg)](https://modelcontextprotocol.io)

> Powered by **Taher Sayed** · HBS IT GmbH

A WordPress plugin that turns your site into a **secure Model Context Protocol (MCP) server** so Claude (and any MCP-compatible client) can read, write, moderate and administrate it — with every call scoped, capability-checked, rate-limited and audit-logged.

Implements **MCP 2025-06-18** over Streamable HTTP / JSON-RPC 2.0.

![Commander banner](assets-wporg/banner-772x250.png)

---

## Why Commander?

Most WordPress AI plugins either expose an admin session to a third party or skip auth entirely. Commander is built around hard interlocks:

- **No anonymous access.** Every request needs a bearer token.
- **Tokens hashed at rest** (SHA-256). The plaintext is shown to the admin once, never recoverable.
- **Triple gate per call.** MCP scope (`read` / `write` / `admin`) + WordPress capability (`current_user_can`) + optional "danger mode" interlock for destructive ops.
- **Audit log** for every successful and failed call (token id, IP, method, tool, status, note).
- **Transport hardening.** HTTPS enforced, Origin checked, request size capped, rate-limited, constant-time token comparison.
- **SSRF guard** on `media.upload` (rejects private / loopback / reserved IPs).
- **Self-protection.** The plugin will refuse to deactivate itself via `plugins.toggle`.

---

## Install / upgrade

**From WordPress.org** (recommended once approved):
Search for "Commander — Secure MCP Control" under **Plugins → Add New**, install, activate.

**From this repo** (development install):

```bash
cd wp-content/plugins/
git clone https://github.com/TaherSayed/commander-secure-mcp-control.git
```

Then activate **Commander — Secure MCP Control** under **Plugins**. The 30-second setup wizard runs on first activation.

**From a release zip:**
Download the latest `commander-secure-mcp-control-x.y.z.zip` from [Releases](https://github.com/TaherSayed/commander-secure-mcp-control/releases) and upload via **Plugins → Add New → Upload Plugin**.

After install:
1. Go to **Commander → Tokens** and issue a token (bind it to a least-privileged WP user).
2. Tune **Commander → Settings** — allowed origins, rate limit, destructive ops, trust-proxy, OAuth options, enabled tools.

**Requires:** PHP 8.0+ and WordPress 6.2+.

---

## Endpoints

| Purpose      | URL                                                  |
|--------------|------------------------------------------------------|
| MCP (RPC)    | `https://your-site/wp-json/claude-mcp/v1/rpc`        |
| Discovery    | `https://your-site/wp-json/claude-mcp/v1/discovery`  |

Auth: `Authorization: Bearer cmcp_xxxxx…`

---

## Tools — what Claude can do

### Read (`read` scope)
| Tool | What |
|------|------|
| `site.info` | Site name, URL, language, timezone |
| `site.health` | WP/PHP/MySQL versions, debug flags, available updates, plugins/themes count, disk free |
| `posts.list` | List posts / pages / CPTs |
| `posts.get` | Fetch single post (full content + meta) |
| `posts.search` | Full-text search |
| `media.list` | List attachments |
| `comments.list` | List comments by status |
| `terms.list` | List terms in any taxonomy |
| `settings.get` | Read whitelisted site options |

### Write (`write` scope)
| Tool | What |
|------|------|
| `posts.create` | Create post / page / CPT, with categories, tags, meta |
| `posts.update` | Edit any of the above |
| `posts.delete` | Trash — permanent only with danger mode |
| `media.upload` | Sideload image/file from a public URL (SSRF-guarded) |
| `media.delete` | Delete attachment |
| `comments.moderate` | Approve / hold / spam / trash / untrash |
| `terms.create` | Add a category, tag, or custom term |

### Admin (`admin` scope)
| Tool | What |
|------|------|
| `users.list` / `users.create` / `users.update` | User management (passwords NOT settable via MCP) |
| `plugins.list` / `plugins.toggle` | List, activate, deactivate plugins (cannot deactivate Commander itself) |
| `themes.list` / `themes.activate` | List and switch themes |
| `settings.update` | Update whitelisted options (sensitive keys require danger mode) |

---

## Quick test (curl)

```bash
TOKEN="cmcp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
HOST="https://your-site.tld"

# initialize
curl -sS -X POST "$HOST/wp-json/claude-mcp/v1/rpc" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","clientInfo":{"name":"curl","version":"1"},"capabilities":{}}}' | jq

# list tools
curl -sS -X POST "$HOST/wp-json/claude-mcp/v1/rpc" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | jq

# create a draft post
curl -sS -X POST "$HOST/wp-json/claude-mcp/v1/rpc" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"posts.create","arguments":{"title":"Hello from Claude","content":"<p>It works.</p>","status":"draft"}}}' | jq
```

---

## Recommended token layout for client sites

For each WordPress site managed by HBS IT GmbH, create at least:

| Purpose | Scopes | Bound user | Notes |
|---------|--------|------------|-------|
| Daily reading / monitoring | `read` | low-privilege subscriber | Use for dashboards and reports |
| Content workflows | `read, write` | editor | Post creation, media uploads, comment moderation |
| Maintenance & admin | `read, write, admin` | administrator | Plugin/theme/user management. Set short expiry. |

Add IP allowlist on each token if you call from a fixed location.

---

## Security checklist

- [ ] HTTPS on, **Require HTTPS** enabled
- [ ] Tokens bound to least-privileged WP users
- [ ] Rate limit configured
- [ ] Allowed origins set to your actual clients
- [ ] Audit log enabled — checked regularly
- [ ] **Danger mode OFF** unless you explicitly need permanent deletes / siteurl edits
- [ ] WP core, PHP, this plugin kept up to date

### Threats handled
- DNS rebinding (Origin allowlist)
- Token theft / replay (hash at rest, expiry, revocation, IP allowlist)
- Timing attacks (constant-time compare, dummy compare on miss)
- Abuse / runaway loops (per-token rate limit, body size cap)
- Privilege escalation (WP capability final check)
- SSRF on media URL fetches (no private / loopback / reserved IPs)
- Self-lockout (refuses to deactivate Commander itself)
- Log poisoning (control-char strip, length cap, no token plaintext)

### Threats NOT handled
- Compromised WP admin credentials
- A malicious tool added via the `cmcp_register_tools` filter — only install code you trust
- Host-level attacks

---

## Adding custom tools

```php
add_filter( 'cmcp_register_tools', function( array $tools ) {
    $tools[] = new class extends \CMCP\Tools\AbstractTool {
        public function name(): string        { return 'orders.recent'; }
        public function description(): string { return 'Recent WooCommerce orders.'; }
        public function input_schema(): array {
            return [
                'type' => 'object',
                'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ] ],
                'additionalProperties' => false,
            ];
        }
        public function required_scope(): string      { return \CMCP\Auth::SCOPE_READ; }
        public function required_capability(): string { return 'manage_woocommerce'; }
        public function execute( array $args ): array {
            // your query …
            return $this->json( [ 'todo' => true ] );
        }
    };
    return $tools;
} );
```

Enable the new tool name in **Commander → Settings → Enabled tools**.

---

## WP-CLI

```bash
wp cmcp token issue --label="ci" --scopes=read,write --user-id=2 --expires-days=90
wp cmcp token list
wp cmcp token revoke 5
```

---

## License

GPL-2.0-or-later · © Taher Sayed · HBS IT GmbH
