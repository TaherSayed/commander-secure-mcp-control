# Security Policy

## Supported versions

Only the latest minor release receives security fixes. Older releases are not patched.

## Reporting a vulnerability

Email **security@hbs-it-gmbh.de** rather than opening a public issue.

Please include:

1. A description of the vulnerability and its impact (data exposure, privilege escalation, DoS, etc.).
2. Steps to reproduce, ideally with a proof-of-concept.
3. Affected versions you've tested.
4. Your environment (WordPress version, PHP version, hosting / proxy).

You will get an acknowledgement within **72 hours** and a fix-or-mitigation plan within **7 days** for confirmed issues.

## Coordinated disclosure

We follow a standard 90-day coordinated-disclosure timeline. If you intend to publish or present the finding, please coordinate the release date with us so users have time to upgrade.

## What is in scope

- Authentication bypass (PAT or OAuth)
- Authorisation bypass (scope, WP capability, destructive-ops interlock)
- SQL injection, XSS, CSRF, SSRF in MCP tools or admin UI
- Brute-force / rate-limit bypass
- Token leakage (in logs, transients, error pages, etc.)
- OAuth flow flaws (PKCE bypass, redirect-URI manipulation, refresh-token misuse)

## What is **not** in scope

- A compromised WordPress admin account (out of plugin's control)
- Vulnerabilities in custom tools registered through the `cmcp_register_tools` filter (those are third-party code)
- Host-level attacks (kernel, web server, PHP, MySQL)
- WordPress core vulnerabilities (report to core upstream)

## Hall of fame

Contributors who report valid issues will be credited in the release changelog with their consent.
