# Commander — Secure MCP Control · Setup Guide

A step-by-step walkthrough from upload to first authenticated MCP call. Plugin slug on WordPress.org: **`mcp-for-claude`**. Display name: **Commander — Secure MCP Control**. License: GPLv2-or-later.

> Domain references in screenshots are **redacted** — the plugin auto-detects whatever site it's installed on via `home_url()` / `rest_url()`. There is nothing hardcoded to a specific site.

---

## Requirements

- WordPress **6.2+**
- PHP **8.0+**
- HTTPS site (recommended) — the plugin's default settings reject non-HTTPS MCP calls

---

## 1 · Install the plugin

Two paths depending on whether you're using the WordPress.org listing or a release zip from GitHub.

### From WordPress.org (recommended once approved)

1. **Plugins → Add New**
2. Search for **`mcp-for-claude`** or **`Commander Secure MCP`**
3. Click **Install Now** → **Activate**

### From a release zip

1. Download the latest `mcp-for-claude-X.Y.Z.zip` from [Releases](https://github.com/TaherSayed/commander-secure-mcp-control/releases)
2. **Plugins → Add New → Upload Plugin**
3. **Choose File** → pick the zip → **Install Now**
4. After "Plugin successfully installed", click **Activate Plugin**

![Upload plugin screen](screenshots/01-upload-plugin.png)

*Step 1 — Plugins → Add New → Upload Plugin · Pick the zip · Install Now*

![Install success](screenshots/02-install-success.png)

*Step 2 — Plugin successfully installed · Click Activate Plugin*

---

## 2 · Activation notice → 30-second setup wizard

Right after activation, a blue banner appears on the **Plugins** page:

> 🛡 **Commander** is installed. Run the **30-second setup** — auto-creates a dedicated bot user and your first token, or **skip**.

![Activation banner](screenshots/03-activation-banner.png)

Click the **30-second setup** button to open the wizard. (You can skip and configure everything manually under **Commander** in the admin menu, but the wizard is the path of least resistance.)

---

## 3 · The wizard — three opt-in steps

The wizard is fully opt-in. Every checkbox is on by default with sensible safe values; uncheck anything you don't want.

![Wizard step 1 & 2](screenshots/04-wizard-top.png)

### 3.1 · Create a dedicated bot user

`wp-commander-bot` is created with the **administrator** role. The bot's password is randomly generated server-side and immediately discarded — only API tokens authenticate. This gives you:

- Cleaner audit trail (every entry attributable to "this token, this scope")
- Clear "this action was done via Commander" attribution in WP
- No need to expose a real human user's credentials to API clients

### 3.2 · Issue your first token

Token plaintext is shown **once** on the next screen, hashed before storage. Default scopes: `read` + `write`. `admin` is unchecked by default — tick it deliberately if you need user / plugin / theme / settings management.

### 3.3 · Allow this site's origin

Adds your site's URL to the allowed-origin list for OAuth redirects from your own WordPress. (claude.ai's origin is automatically accepted when its tokens are presented.)

![Wizard step 3 & Finish](screenshots/05-wizard-bottom.png)

Click **Finish setup →**.

---

## 4 · Setup complete — copy your token

The next screen shows your token plaintext **exactly once**. Copy it before navigating away. If you lose it, just issue a new one in **Commander → Tokens** — the plaintext is never stored on the server, only its SHA-256 hash.

![Setup complete screen with token](screenshots/06-setup-complete.png)

Below the token, the **Now connect Claude** section offers two routes:

- **claude.ai web** — adds the MCP server URL to Claude's connector list; OAuth runs automatically (no token needed; Claude self-registers via Dynamic Client Registration).
- **Claude Desktop / Code** — copy the JSON snippet into `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows), substituting your token.

---

## 5 · Dashboard

After the wizard you land on the **Commander** dashboard. Live KPIs at the top, 7-day call volume chart, top tools, plus a Quick Connect block with the three URLs (RPC, Discovery, OAuth metadata) clients need.

![Dashboard](screenshots/07-dashboard.png)

---

## 6 · Tokens page

The tokens page is where you issue, test, rotate, revoke, and delete Personal Access Tokens (PATs).

![Tokens page](screenshots/08-tokens-page.png)

### Issue new token form

| Field | Notes |
|---|---|
| **Label** | Free text — e.g. "claude-desktop laptop", "ci-runner", "monitoring-readonly" |
| **Scopes** | `read` / `write` / `admin` — tick the minimum you need |
| **Run as WP user** | Dropdown of Administrators / Editors / Authors. Pre-selected to the current admin. Admin-scope tools (plugins, users, settings) require the bound user to actually have those WP capabilities — pick an Administrator unless you specifically want a limited token |
| **Expires in (days)** | `0` = never. Recommended for human-attached tokens: 30–90. For CI: 7–30 with rotation |
| **IP allowlist** | One IP per line. Token denied from any other source IP. Leave empty for "any source" |

Bot user banner at the top reminds you that `wp-commander-bot` exists as a binding target if you want admin capability without a human user.

### Existing tokens table

| Column | Notes |
|---|---|
| **Label** | Plus id + created timestamp |
| **Status** | Active (used in last 30d) / Idle (never used) / Stale (>30d no activity) / Expired / Revoked |
| **Prefix** | First 12 chars of plaintext + ellipsis (used for lookup; never reveals plaintext) |
| **Scopes** | Colored badges |
| **User** | WP user the token executes as |
| **Last used / Last IP** | Most recent successful auth |
| **7-day calls** | Inline SVG sparkline + total |
| **Expires** | "never" or absolute date |
| **Actions** | **Test** (freshly-issued only — pings /rpc, reports OK + latency) · **Rotate** (atomic reissue with same params) · **Revoke** (kill, keep audit row) · **Delete** (wipe row entirely) |

### Connect snippets

Under each token row, a collapsible **🔌 Connect snippets** block has tabs for:

- **curl** — bash one-liner
- **Claude Desktop** — JSON config with the right file path
- **Python** — `requests`-based snippet
- **Node** — `fetch`-based snippet (Node 18+)

Plaintext is auto-substituted only for the just-issued / rotated token (where the server still has it in memory). Existing rows use a `<YOUR_TOKEN>` placeholder.

---

## 7 · Settings

![Settings page](screenshots/09-settings.png)

### Transport security

- **Require HTTPS** — reject non-HTTPS MCP calls (localhost is always allowed)
- **Allowed origins** — one URL per line. Empty = same-site only
- **Rate limit** — N requests / minute / token. `0` = unlimited
- **Max request size** — bytes. Raise for large `media.upload` payloads
- **Trust reverse proxy** — when **on**, the plugin reads `CF-Connecting-IP` / `X-Forwarded-For` / `X-Real-IP` to get the real client IP. **Turn this on only when WordPress sits behind a trusted proxy** (Cloudflare, nginx, load balancer). Otherwise leave off — the plugin uses `REMOTE_ADDR` and an attacker can't spoof their way out of brute-force lockouts

### Notifications (webhook)

- **Webhook URL** — POST destination for security events. Receives JSON `{ event, timestamp, site, data }` on:
  - `brute_force.lockout` (IP locked after N failed auths)
  - `oauth.client_registered` (new RFC 7591 DCR client appeared)
- **Webhook secret** — optional shared secret. When set, requests include `X-Commander-Signature: sha256=<HMAC>` so the receiver can verify the call really came from this site
- **Send test ping** — fires a `test.ping` synchronously so you can verify the endpoint works

### OAuth

- **Allow anonymous OAuth client registration (RFC 7591)** — required for one-click connect from clients like Claude Desktop. **Off by default**: when on, anyone on the internet can register an OAuth client. Tokens are still only issued after an admin approves the consent screen. Rate-limited to 5 registrations/minute/IP

### Audit, alerts & destructive operations

- **Audit log** + retention days (default 30)
- **Block scanner user-agents** — refuse sqlmap, nikto, wpscan, etc. by UA string
- **Email alerts** — site admin gets an email on brute-force lockouts (rate-limited to 1/IP/hour)
- **Token rotation warning** — dashboard banner for tokens older than N days
- **Allow destructive ops** — gate for permanent deletes + sensitive option writes (`siteurl`, `home`, `admin_email`, `default_role`, `permalink_structure`)

### Enabled tools

Per-tool on/off toggles grouped by scope (read / write / admin).

---

## 8 · OAuth Clients

![OAuth Clients page](screenshots/10-oauth-clients.png)

Lists OAuth 2.1 apps that have registered with this server via Dynamic Client Registration. Shows the endpoint reference table at the top (MCP RPC URL, Authorization, Token, Registration, AS metadata, PR metadata) for clients that need them.

Two tables:

- **Registered clients** — one row per OAuth client app, with name, client_id, redirect URIs, auth method, active token count, registered date, and **Revoke tokens** / **Delete client** actions
- **Active OAuth sessions** — one row per individual access token currently valid, with client, bound WP user, scopes, issued / last-used / access-expires / refresh-expires timestamps, and a per-row **Revoke** action

---

## 9 · Audit Log

![Audit Log page](screenshots/11-audit-log.png)

Every successful and failed call lands here.

Filter form across the top:

- **Search** — free text across method/tool/note/ip
- **Status** — any / success only / failure only
- **Method** / **Tool** / **IP** — exact / contains filters
- **From** / **To** — date range
- **Filter** button applies; **clear filters** link resets

Right-aligned **Export CSV** button downloads the last 50 000 audit rows as `commander-audit-YYYYMMDD-HHMMSS.csv`.

Rows are paginated at 50 per page.

---

## 10 · Connect your AI client

### Path A — claude.ai web (one-click OAuth)

1. In **Commander → Settings → OAuth**, enable Dynamic Client Registration
2. In claude.ai: **Settings → Connectors → + Add custom connector**
3. Paste your MCP RPC URL: `https://your-site/wp-json/claude-mcp/v1/rpc`
4. Claude auto-discovers OAuth via `/.well-known/oauth-authorization-server`
5. You see the **Authorize access** consent screen on your WordPress site — click **Approve**
6. Claude can now call the 24 built-in tools on your WP

### Path B — Claude Desktop / Code / curl / Python / Node (bearer token)

1. Issue a PAT in **Commander → Tokens** (see §6)
2. Copy the plaintext from the green "Token created" notice
3. Add to your client config — the **Connect snippets** block under the token row has ready-to-paste examples for each client

For Claude Desktop / Code:

```json
{
  "mcpServers": {
    "wp-commander": {
      "type": "http",
      "url": "https://your-site/wp-json/claude-mcp/v1/rpc",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN_HERE"
      }
    }
  }
}
```

For curl:

```bash
curl -sS -X POST "https://your-site/wp-json/claude-mcp/v1/rpc" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq
```

---

## 11 · The triple gate (why your token may fail)

Every MCP call passes through three independent gates:

| Gate | What it checks | Fail message |
|---|---|---|
| **1 — MCP scope** | Does the token include the scope the tool requires (`read` / `write` / `admin`)? | `Tool 'site_health' requires scope 'admin'.` |
| **2 — WP capability** | Does the WP user the token is bound to have the underlying WordPress capability (`manage_options`, `edit_posts`, `activate_plugins`, `list_users`, etc.)? | `Tool 'plugins_list' requires WordPress capability 'activate_plugins'.` |
| **3 — Destructive ops interlock** | If the tool would do a permanent delete or write a sensitive option, is "Allow destructive ops" enabled in Settings? | `Destructive operations are disabled in plugin settings.` |

If any gate denies, the call fails with the exact reason in the response body. The most common surprise: token has `admin` scope but is bound to a non-administrator WP user → gate 2 denies. Fix: re-issue the token bound to an Administrator (or the `wp-commander-bot` account).

---

## 12 · Uninstall / cleanup

- **Deactivate** — pauses the plugin; data preserved
- **Delete** (from Plugins page) — runs `uninstall.php` which drops the `wp_cmcp_*` tables (tokens, audit log, OAuth clients / codes / tokens), removes the `cmcp_settings` option, all `cmcp_*` transients, the daily cleanup cron, and the `wp-commander-bot` user (reassigning any content it owns to the deleting admin)

⚠ Deleting the plugin via the WP admin **does drop the database**. To keep token + audit data, **deactivate** and remove the folder manually via FTP/SSH instead.

---

## Support

- 🐛 [GitHub issues](https://github.com/TaherSayed/commander-secure-mcp-control/issues)
- 🔒 Security: see [SECURITY.md](../SECURITY.md) — coordinated disclosure
- 💬 Built by **Taher Sayed · [HBS IT GmbH](https://hbs-it-gmbh.de)**
