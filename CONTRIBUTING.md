# Contributing to Commander — Secure MCP Control

Thanks for your interest. This document covers how to file issues, propose changes, and report security problems.

## Reporting security issues

**Do not open a public GitHub issue for security problems.** Instead email security@hbs-it-gmbh.de with:

- A description of the issue and its impact.
- Steps to reproduce (or a proof-of-concept).
- The plugin version, WordPress version, and PHP version.

You will get an acknowledgement within 72 hours. Fixes are coordinated and disclosed responsibly.

## Filing bug reports

Open a GitHub issue with:

- **Environment:** WordPress version, PHP version, plugin version, hosting / proxy setup (Cloudflare, nginx, Apache, etc.).
- **Steps to reproduce.**
- **What you expected** vs **what happened**.
- Relevant entries from **WP Commander → Audit Log** (sanitise tokens and IPs first).
- Browser console / `error_log` excerpts if applicable.

## Proposing changes

1. Open an issue first to discuss large changes — saves both sides time.
2. Fork the repo, create a feature branch.
3. Follow the existing code style (WordPress Coding Standards, prefixed `cmcp_*` for option / transient / hook names, classes in `CMCP\` namespace).
4. Add or update tests where it makes sense.
5. Open a PR against `main`.

## Code style

- PHP 8.0+, strict-ish: type-hint params and returns, `final class` by default.
- All user input through `sanitize_*` / `wp_unslash` / `esc_*` on output.
- Every `$wpdb->prepare()`-able query *must* use placeholders. Table-name interpolation is annotated `phpcs:disable WordPress.DB.PreparedSQL.NotPrepared` with rationale.
- Every privileged endpoint goes through three gates: MCP scope → WP capability → destructive-ops interlock (where applicable).
- New tools extend `\CMCP\Tools\AbstractTool` and are registered via the `cmcp_register_tools` filter (see README).

## Releasing

1. Bump `Version:` in the main plugin header and `CMCP_VERSION` constant.
2. Bump `Stable tag:` in `readme.txt` and add a `== Changelog ==` entry.
3. Regenerate `languages/commander-secure-mcp-control.pot` (`wp i18n make-pot . languages/commander-secure-mcp-control.pot`).
4. Tag the release: `git tag v1.x.y && git push --tags`.
5. Upload to WordPress.org SVN.

## License

By contributing you agree your contribution is licensed under GPLv2-or-later (same as the project).
