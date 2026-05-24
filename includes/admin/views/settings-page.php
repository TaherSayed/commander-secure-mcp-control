<?php
/**
 * Settings page view.
 *
 * @var array $settings
 *
 * @package WPCommander
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-template locals, scoped to include().

$tool_groups = [
    __( 'Read', 'commander-secure-mcp-control' ) => [
        'site.info'        => 'site.info — public site info',
        'site.health'      => 'site.health — technical health snapshot (admin)',
        'posts.list'       => 'posts.list — list posts / pages',
        'posts.get'        => 'posts.get — single post',
        'posts.search'     => 'posts.search — search content',
        'media.list'       => 'media.list — list attachments',
        'comments.list'    => 'comments.list — list comments',
        'terms.list'       => 'terms.list — list taxonomy terms',
        'settings.get'     => 'settings.get — read site options',
    ],
    __( 'Write', 'commander-secure-mcp-control' ) => [
        'posts.create'      => 'posts.create — create post/page',
        'posts.update'      => 'posts.update — edit post/page',
        'posts.delete'      => 'posts.delete — trash post (permanent requires danger mode)',
        'media.upload'      => 'media.upload — upload image from URL (SSRF-guarded)',
        'media.delete'      => 'media.delete — delete attachment',
        'comments.moderate' => 'comments.moderate — approve/spam/trash comments',
        'terms.create'      => 'terms.create — add category / tag',
    ],
    __( 'Admin', 'commander-secure-mcp-control' ) => [
        'users.list'        => 'users.list — list users (PII!)',
        'users.create'      => 'users.create — create user',
        'users.update'      => 'users.update — update user role/email/name',
        'plugins.list'      => 'plugins.list — list installed plugins',
        'plugins.toggle'    => 'plugins.toggle — activate / deactivate plugin',
        'themes.list'       => 'themes.list — list installed themes',
        'themes.activate'   => 'themes.activate — switch active theme',
        'settings.update'   => 'settings.update — update whitelisted options',
    ],
];
?>
<div class="wrap">
    <h1 style="display:flex;align-items:center;gap:10px">
        <span class="dashicons dashicons-shield-alt" style="color:#2271b1;font-size:30px;width:30px;height:30px"></span>
        <?php esc_html_e( 'Commander — Secure MCP Control', 'commander-secure-mcp-control' ); ?>
    </h1>
    <p class="description" style="margin-top:-4px"><?php esc_html_e( 'Powered by Taher Sayed · HBS IT GmbH', 'commander-secure-mcp-control' ); ?> · v<?php echo esc_html( CMCP_VERSION ); ?></p>

    <div class="notice notice-info">
        <p>
            <strong><?php esc_html_e( 'Endpoint:', 'commander-secure-mcp-control' ); ?></strong>
            <code><?php echo esc_html( rest_url( CMCP_REST_NAMESPACE . '/rpc' ) ); ?></code>
            &nbsp;|&nbsp;
            <strong><?php esc_html_e( 'Discovery:', 'commander-secure-mcp-control' ); ?></strong>
            <code><?php echo esc_html( rest_url( CMCP_REST_NAMESPACE . '/discovery' ) ); ?></code>
        </p>
        <p><?php esc_html_e( 'Protocol version:', 'commander-secure-mcp-control' ); ?> <code><?php echo esc_html( CMCP_PROTOCOL_VERSION ); ?></code></p>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( 'cmcp_settings_group' ); ?>

        <h2 class="title"><?php esc_html_e( 'Transport security', 'commander-secure-mcp-control' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Require HTTPS', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[require_https]" value="1" <?php checked( ! empty( $settings['require_https'] ) ); ?> />
                        <?php esc_html_e( 'Reject non-HTTPS requests (recommended; localhost is always allowed).', 'commander-secure-mcp-control' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Allowed origins', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <textarea name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[allowed_origins]" rows="4" cols="60" placeholder="https://claude.ai&#10;https://app.example.com"><?php echo esc_textarea( implode( "\n", (array) ( $settings['allowed_origins'] ?? [] ) ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One origin per line. Same-site is always allowed. Empty Origin headers (server-to-server clients) are accepted.', 'commander-secure-mcp-control' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Rate limit', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <input type="number" min="0" max="6000" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[rate_limit_per_min]" value="<?php echo esc_attr( (int) $settings['rate_limit_per_min'] ); ?>" />
                    <?php esc_html_e( 'requests / minute / token (0 = unlimited)', 'commander-secure-mcp-control' ); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Max request size', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <input type="number" min="4096" max="<?php echo esc_attr( 8 * 1024 * 1024 ); ?>" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[max_request_bytes]" value="<?php echo esc_attr( (int) $settings['max_request_bytes'] ); ?>" />
                    <?php esc_html_e( 'bytes (raise this if uploading large media)', 'commander-secure-mcp-control' ); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Trust reverse proxy', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[trust_proxy]" value="1" <?php checked( ! empty( $settings['trust_proxy'] ) ); ?> />
                        <?php esc_html_e( 'Honor X-Forwarded-Proto, X-Forwarded-For and CF-Connecting-IP headers.', 'commander-secure-mcp-control' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Only enable this when WordPress sits behind a trusted reverse proxy (Cloudflare, nginx, load balancer). With this off, the plugin uses REMOTE_ADDR — safer when traffic reaches WP directly. With it on, the real client IP can be used for brute-force lockouts even behind a proxy.', 'commander-secure-mcp-control' ); ?></p>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e( 'OAuth', 'commander-secure-mcp-control' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr style="background:#fff7e6">
                <th scope="row" style="color:#b26a00"><?php esc_html_e( 'Dynamic Client Registration', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[allow_dcr]" value="1" <?php checked( ! empty( $settings['allow_dcr'] ) ); ?> />
                        <strong style="color:#b26a00"><?php esc_html_e( 'Allow anonymous OAuth client registration (RFC 7591).', 'commander-secure-mcp-control' ); ?></strong>
                    </label>
                    <p class="description"><?php esc_html_e( 'Required for one-click connect from clients like Claude Desktop. Leave OFF unless you need it: when on, anyone on the internet can register an OAuth client. Tokens are still only issued after an admin approves the consent screen on this site.', 'commander-secure-mcp-control' ); ?></p>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e( 'Audit, alerts & destructive operations', 'commander-secure-mcp-control' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Audit log', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[enable_audit_log]" value="1" <?php checked( ! empty( $settings['enable_audit_log'] ) ); ?> />
                        <?php esc_html_e( 'Enable audit logging of every MCP call.', 'commander-secure-mcp-control' ); ?>
                    </label>
                    <br>
                    <label>
                        <?php esc_html_e( 'Keep logs for', 'commander-secure-mcp-control' ); ?>
                        <input type="number" min="1" max="3650" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[log_retention_days]" value="<?php echo esc_attr( (int) $settings['log_retention_days'] ); ?>" />
                        <?php esc_html_e( 'days.', 'commander-secure-mcp-control' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Block scanner user-agents', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[block_bad_uas]" value="1" <?php checked( ! empty( $settings['block_bad_uas'] ) ); ?> />
                        <?php esc_html_e( 'Reject sqlmap, nikto, wpscan, nmap and other known scanner UAs.', 'commander-secure-mcp-control' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Email alerts', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[notify_admin_email]" value="1" <?php checked( ! empty( $settings['notify_admin_email'] ) ); ?> />
                        <?php esc_html_e( 'Email site admin when an IP is locked out for brute force.', 'commander-secure-mcp-control' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Rate-limited to one email per IP per hour. Sent to:', 'commander-secure-mcp-control' ); ?> <code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Token rotation warning', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <?php esc_html_e( 'Warn on dashboard if a personal token is older than', 'commander-secure-mcp-control' ); ?>
                    <input type="number" min="7" max="730" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[rotation_warn_days]" value="<?php echo esc_attr( (int) ( $settings['rotation_warn_days'] ?? 90 ) ); ?>" /> <?php esc_html_e( 'days.', 'commander-secure-mcp-control' ); ?>
                </td>
            </tr>
            <tr style="background:#fff7e6">
                <th scope="row" style="color:#b26a00"><?php esc_html_e( 'Allow destructive ops', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[allow_destructive]" value="1" <?php checked( ! empty( $settings['allow_destructive'] ) ); ?> />
                        <strong style="color:#b26a00"><?php esc_html_e( 'I understand — permit permanent deletes and sensitive option writes (siteurl, home, admin_email, default_role, permalink_structure, …).', 'commander-secure-mcp-control' ); ?></strong>
                    </label>
                    <p class="description"><?php esc_html_e( 'Keep this off unless you specifically need it. The plugin still requires admin scope + WP capability for those calls — this is an extra interlock.', 'commander-secure-mcp-control' ); ?></p>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e( 'Enabled tools', 'commander-secure-mcp-control' ); ?></h2>
        <table class="form-table" role="presentation">
            <?php foreach ( $tool_groups as $group => $tools ) : ?>
            <tr>
                <th scope="row"><?php echo esc_html( $group ); ?></th>
                <td>
                    <?php foreach ( $tools as $slug => $desc ) : ?>
                        <label style="display:block">
                            <input type="checkbox"
                                   name="<?php echo esc_attr( CMCP\Plugin::OPT_SETTINGS ); ?>[enabled_tools][]"
                                   value="<?php echo esc_attr( $slug ); ?>"
                                   <?php checked( in_array( $slug, (array) ( $settings['enabled_tools'] ?? [] ), true ) ); ?> />
                            <code><?php echo esc_html( $slug ); ?></code> — <?php echo esc_html( substr( $desc, strpos( $desc, '—' ) + 1 ) ); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
