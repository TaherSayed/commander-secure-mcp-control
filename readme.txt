=== Commander — Secure MCP Control ===
Contributors: tahersayed, hbsitgmbh
Tags: mcp, claude, ai, oauth, rest-api
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your WordPress site into a secure Model Context Protocol (MCP) server for Claude and any MCP-compatible AI client.

== Description ==

**Commander** gives Claude (and any MCP-compatible AI client) full, secure, audited control of your WordPress site over the official Model Context Protocol — JSON-RPC 2.0 over Streamable HTTP, MCP spec **2025-06-18**.

Most "AI for WordPress" plugins either hand over an admin session to a third party or skip authentication entirely. Commander is built around hard interlocks at every layer.

= Built on hard interlocks =

* **No anonymous access.** Every request needs a bearer token or an OAuth 2.1 access token.
* **Tokens hashed at rest** (SHA-256). The plaintext is shown to the admin once and is never recoverable.
* **Triple gate per call.** MCP scope (`read` / `write` / `admin`) + WordPress capability (`current_user_can`) + an optional "destructive ops" interlock for permanent deletes and sensitive option writes.
* **Audit log** for every successful and failed call: token id, IP, JSON-RPC method, tool, status code, note.
* **Transport hardening.** HTTPS enforced, Origin header allowlisted (DNS rebinding mitigation), request size capped, per-token rate limit, constant-time token comparison.
* **OAuth 2.1 with PKCE (S256 required)**, refresh token rotation, RFC 7009 revocation, RFC 8414 / RFC 9728 discovery, RFC 7591 Dynamic Client Registration **off by default** (admin opt-in).
* **Brute-force protection** with progressive lockout (5 / 10 / 20 fails → 5 min / 1 h / 24 h) and optional email alerts.
* **SSRF guard** on `media.upload` — rejects private, loopback and reserved IPs.
* **Self-protection.** The plugin refuses to deactivate itself via the `plugins.toggle` tool.

= 25 built-in tools, scoped read / write / admin =

**Read scope:** `site.info`, `site.health`, `posts.list`, `posts.get`, `posts.search`, `media.list`, `comments.list`, `terms.list`, `settings.get`

**Write scope:** `posts.create`, `posts.update`, `posts.delete`, `media.upload`, `media.delete`, `comments.moderate`, `terms.create`

**Admin scope:** `users.list`, `users.create`, `users.update`, `plugins.list`, `plugins.toggle`, `themes.list`, `themes.activate`, `settings.update`

You can register your own tools through the `cmcp_register_tools` filter — see the FAQ.

= Two authentication modes =

* **Personal access tokens (PATs)** — issue from the admin UI, bind to a least-privileged WP user, pin to an IP allowlist, set an expiry. Ideal for server-to-server use or your own scripts.
* **OAuth 2.1** — the full authorize / token / refresh / revoke flow with PKCE. Ideal for desktop / browser clients that need a one-click connect. Public clients are supported; confidential clients get a hashed secret.

= Endpoints =

* MCP RPC: `https://your-site/wp-json/claude-mcp/v1/rpc`
* Discovery: `https://your-site/wp-json/claude-mcp/v1/discovery`
* OAuth metadata: `https://your-site/.well-known/oauth-authorization-server`

= Privacy =

Commander does not "phone home". No analytics, no remote logging, no third-party requests beyond what you explicitly ask a tool to do (e.g. `media.upload` fetches the URL you pass it). All data — tokens, audit log, OAuth clients/codes/tokens — lives in your own database.

== Installation ==

1. Upload the `commander-secure-mcp-control/` folder to `/wp-content/plugins/` (or install the zip via **Plugins → Add New → Upload**).
2. Activate **Commander — Secure MCP Control** in **Plugins**.
3. The setup wizard appears on first load — walk through it, or skip and use **Commander** in the admin menu.
4. Go to **Commander → Tokens** and issue a token. Bind it to a least-privileged WP user. Save the plaintext somewhere safe — it is shown once.
5. Tune **Commander → Settings** — allowed origins, rate limit, destructive ops, enabled tools, OAuth options.
6. (Optional) **Commander → OAuth Clients** — enable Dynamic Client Registration if you want clients like Claude Desktop to self-register.

Requirements: PHP 8.0+ and WordPress 6.2+.

== Frequently Asked Questions ==

= Is this safe to run on a production site? =

Yes — that is the entire point of the design. Every request is authenticated, scope-checked, capability-checked, rate-limited and audit-logged. Destructive operations (permanent deletes, edits to `siteurl` / `home` / `admin_email` / `default_role` / `permalink_structure`) require an additional admin opt-in. That said: keep WordPress and PHP up to date, use HTTPS, bind tokens to the lowest-privileged WP user that can do the job, and review the audit log.

= Does it work with Claude Desktop? =

Yes. Enable **Settings → OAuth → Dynamic Client Registration**, then add the server URL in Claude Desktop. PKCE with S256 is required.

= Does it work with curl / Python / Node clients? =

Yes. Issue a personal access token under **Tokens**, then send `Authorization: Bearer <token>` to `/wp-json/claude-mcp/v1/rpc`. See the plugin README on GitHub for a curl quickstart.

= Can I add my own tools? =

Yes. Hook the `cmcp_register_tools` filter and return objects extending `\CMCP\Tools\AbstractTool`. Define `name()`, `description()`, `input_schema()`, `required_scope()`, `required_capability()` and `execute()`. Enable the new tool's name under **Settings → Enabled tools**.

= My site is behind Cloudflare / a load balancer. Brute-force lockout locks out everyone. =

Enable **Settings → Trust reverse proxy**. With this on the plugin reads `CF-Connecting-IP`, `X-Forwarded-For` or `X-Real-IP` to identify the real client. Leave it OFF if WordPress is reachable directly, otherwise an attacker can spoof the header.

= How are tokens stored? =

Tokens are hashed with SHA-256 before insertion. The plaintext is shown to the admin exactly once when the token is created. The same applies to OAuth access and refresh tokens, and to OAuth client secrets.

= What happens on uninstall? =

Uninstall drops every plugin table (tokens, audit log, OAuth clients / codes / tokens), removes the `cmcp_settings` option, all `cmcp_*` transients, the bot user the wizard may have created, and the daily-cleanup cron.

= Is there a CLI? =

Yes, WP-CLI:

`wp cmcp token issue --label="ci" --scopes=read,write --user-id=2 --expires-days=90`

`wp cmcp token list`

`wp cmcp token revoke 5`

= How do I report a security issue? =

Please email security@hbs-it-gmbh.de rather than opening a public issue.

== Screenshots ==

1. Dashboard — KPIs, 7-day call volume, top tools and live security status at a glance.
2. Tokens — issue personal access tokens, bind to a user, scope, IP allowlist, expiry.
3. Settings — transport, audit, alerts, destructive ops interlock, per-tool enable toggles.
4. OAuth Clients — registered apps with active-token counts and one-click revoke.
5. Audit Log — every call, with token id, IP, JSON-RPC method, tool, status, note.

== Changelog ==

= 1.5.4 =
* **Fix:** OAuth consent screen bounced logged-in admins back to wp-login on sites where the REST API is hit by direct browser navigation. WordPress's REST routes only honor cookie auth when the request carries an X-WP-Nonce header — which a top-level navigation does not. `OAuth::rest_authorize_get/post` now manually calls `wp_validate_auth_cookie` to set the current WP user from the browser cookie. Fully verified (HMAC-signed cookie); not an auth bypass.

= 1.5.3 =
* **Token issuance UX fix.** The "Bind to WP user" field defaulted to `0` (anonymous) — admins kept issuing tokens that passed the scope check but failed every WordPress capability check, giving the impression admin tools were broken. Now:
  - Field is a dropdown of suggested users (Administrators / Editors / Authors), with the **current admin pre-selected**.
  - "Other — enter ID manually" option reveals the number input for edge cases.
  - "0 — anonymous" is still available as the last option (in red), with a live warning banner when picked.
  - Label clarified: "Run as WP user" with an explanation that admin-scope tools also require the bound user to have the corresponding WP capability.

= 1.5.2 =
* **Fix:** Critical fatal error on activation in 1.5.1. The new GitHub auto-updater class was named `GitHubUpdater`, but the autoloader's CamelCase→kebab-case regex splits at every lower-to-upper boundary (`tH`, `bU`), so it tried to load `class-git-hub-updater.php` while the actual file is `class-github-updater.php`. Renamed the class to `GithubUpdater` so the filename matches. **Do not run 1.5.1 — install 1.5.2 directly.**

= 1.5.1 =
* **GitHub auto-updater** — once installed, the plugin polls api.github.com/repos/TaherSayed/commander-secure-mcp-control/releases/latest at most every 12 hours and surfaces new versions through WordPress's standard "Update available" banner. One-click update preserves all data. Yields to WordPress.org's update channel automatically once published there.
* "View details" modal shows the GitHub release notes for the new version.
* Plugins-page row links: GitHub repo + Report issue.
* **Fix:** JSON-RPC error messages no longer contain HTML entities (e.g. apostrophes were rendered as `&#039;`). Exception messages are now entity-decoded before being returned in the JSON body.

= 1.5.0 =
* **Quick-connect snippets** per token row — collapsible block with tabs for `curl`, Claude Desktop config JSON, Python (requests), and Node (fetch). For freshly-issued / rotated tokens the plaintext is substituted automatically; for existing rows a `<YOUR_TOKEN>` placeholder is used (plaintext is never stored server-side).
* **7-day sparkline** per token row — inline SVG chart of call activity, alongside the total count.
* **Outbound webhooks** — POSTs a small JSON `{ event, timestamp, site, data }` to a configurable URL on brute-force lockouts and new OAuth client registrations. Optional HMAC-SHA256 shared secret produces an `X-Commander-Signature` header for receiver verification. "Send test ping" button in settings.
* Webhook delivery is async via WP-Cron (falls back to short-timeout sync if cron is disabled).

= 1.4.0 =
* **Token UX overhaul:** new Status column (Active / Idle / Stale / Expired / Revoked) with colored pulsing dot, scope badges, last-IP column, 7-day call counter, and per-row actions.
* **Copy-to-clipboard** button on freshly-issued tokens.
* **Test connection** button — fires a real `initialize` against `/rpc` with the just-shown token and reports OK + MCP protocol version + round-trip latency, or the actual failure reason.
* **Rotate** action — one-click reissue with the same label / scopes / user / IP allowlist / remaining expiry, old token revoked atomically.
* **Permanent Delete** action — wipes the token row entirely (Revoke remains the compliance-friendly default that keeps the audit trail).
* Inline notices for revoke / delete / rotate outcomes.

= 1.3.0 =
* **Security:** Dynamic Client Registration (RFC 7591) is now off by default and requires explicit admin opt-in. When on, registration is rate-limited per IP.
* **Security:** `is_https()` only honors `X-Forwarded-Proto` when the new "Trust reverse proxy" setting is enabled — prevents header-spoof bypass of the HTTPS gate.
* **Security:** `client_ip()` reads `CF-Connecting-IP` / `X-Forwarded-For` / `X-Real-IP` only when "Trust reverse proxy" is on — prevents single-IP-locks-out-everyone DoS behind Cloudflare.
* **Security:** Per-IP rate limit added to `oauth/register`, `oauth/authorize` (POST) and `/.well-known/oauth-*`.
* **Security:** Consent screen form action now uses `home_url()` instead of `Host:` header — fixes a host-header redirection vector.
* **Security:** `serve_well_known` early-returns on non-matching paths instead of parsing every front-end request URL.
* **Fix:** Dashboard view's status-row helper is no longer redeclared on second page load (fatal on revisit).
* **Fix:** Dashboard "destructive ops OFF" color is now correct (operator-precedence bug inverted it before).
* **Fix:** Admin POST handlers now `wp_unslash()` before sanitizing.
* **Uninstall:** Drops all `cmcp_*` transients, deletes the `wp-commander-bot` user if present, flushes rewrite rules.
* **i18n:** Brute-force, server, OAuth and dashboard strings translated. Adds `languages/claude-mcp-secure.pot`.

= 1.2.0 =
* Setup wizard, dashboard with KPIs and 7-day chart, dashboard widget, OAuth 2.1 with PKCE and DCR, brute-force protection with progressive lockout, scanner-UA block list, token rotation warnings, email alerts.

= 1.1.0 =
* OAuth 2.1 endpoints (authorize / token / register / revoke / metadata).

= 1.0.0 =
* Initial release. Personal access tokens, scope + capability gating, audit log, rate limiting, SSRF guard on media.upload, 25 built-in tools, WP-CLI command, custom-tool filter.

== Upgrade Notice ==

= 1.5.4 =
Fix: OAuth consent screen now recognises your existing WordPress login session (was bouncing logged-in admins back to wp-login). Required if you use security plugins that move the login URL.

= 1.5.3 =
Fixes a footgun in the token form — "Bind to WP user" now defaults to the current admin instead of 0. Existing tokens are not affected.

= 1.5.2 =
**Critical fix.** v1.5.1 had a fatal-error bug on activation due to a class-name / autoloader mismatch — do not run 1.5.1; install 1.5.2 directly.

= 1.5.1 =
Adds GitHub auto-updater — every future release will appear as a standard "Update available" notification on your Plugins page. **Install this version once manually (Replace via upload) and every release after 1.5.1 updates automatically.**

= 1.5.0 =
Quick-connect snippets (curl / Claude Desktop / Python / Node) per token, 7-day sparkline, and outbound webhooks on lockouts + new OAuth clients. Pure additive — no settings or schema change.

= 1.4.0 =
Token management overhaul — Copy, Test, Rotate, Delete, live status badges, last-IP and 7-day call counts. Pure additive — no settings change required.

= 1.3.0 =
Important security update. Dynamic Client Registration is now off by default — if you relied on it for Claude Desktop one-click connect, re-enable under Commander → Settings → OAuth. Behind a reverse proxy, also enable "Trust reverse proxy".
