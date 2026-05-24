<?php
/**
 * Dashboard view — the new landing page for Commander.
 *
 * @var array $stats
 *
 * @package WPCommander
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-template locals, scoped to include().

$home_host    = wp_parse_url( home_url(), PHP_URL_HOST );
$mcp_endpoint = rest_url( CMCP_REST_NAMESPACE . '/rpc' );
$settings     = CMCP\Plugin::get_settings();
$dest_ok      = ! empty( $settings['require_https'] ) && ! empty( $settings['enable_audit_log'] );
?>
<div class="wrap cmcp-wrap">
    <div class="cmcp-hero">
        <div class="cmcp-logo">
            <svg width="40" height="40" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="3" y="3" width="34" height="34" rx="8" fill="#fff" opacity="0.15"/>
                <path d="M20 9 L30 14 V22 C30 26.5 25.5 30 20 31 C14.5 30 10 26.5 10 22 V14 Z" fill="#fff"/>
                <path d="M16 20 L19 23 L25 17" stroke="#0a3d62" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div style="flex:1">
            <h1>Commander <span class="cmcp-version">v<?php echo esc_html( CMCP_VERSION ); ?></span></h1>
            <p class="cmcp-subtitle">Dashboard · <?php echo esc_html( $home_host ); ?> · MCP <?php echo esc_html( CMCP_PROTOCOL_VERSION ); ?></p>
        </div>
        <div style="text-align:right">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-settings' ) ); ?>" class="button" style="background:rgba(255,255,255,0.15);border-color:rgba(255,255,255,0.3);color:#fff">⚙ Settings</a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="cmcp-stats">
        <div class="cmcp-stat">
            <span class="num"><?php echo (int) $stats['calls_today']; ?></span>
            <span class="lbl">Calls today</span>
        </div>
        <div class="cmcp-stat success">
            <span class="num"><?php echo (int) $stats['ok_24h']; ?></span>
            <span class="lbl">OK · 24h</span>
        </div>
        <div class="cmcp-stat <?php echo $stats['fail_24h'] > 0 ? 'danger' : ''; ?>">
            <span class="num"><?php echo (int) $stats['fail_24h']; ?></span>
            <span class="lbl">Failed · 24h</span>
        </div>
        <div class="cmcp-stat">
            <span class="num"><?php echo (int) $stats['active_tokens']; ?></span>
            <span class="lbl">Active PATs</span>
        </div>
        <div class="cmcp-stat">
            <span class="num"><?php echo (int) $stats['oauth_clients']; ?></span>
            <span class="lbl">OAuth clients</span>
        </div>
        <div class="cmcp-stat <?php echo $stats['locked_ips'] > 0 ? 'warning' : ''; ?>">
            <span class="num"><?php echo (int) $stats['locked_ips']; ?></span>
            <span class="lbl">Locked IPs</span>
        </div>
    </div>

    <div class="cmcp-grid">
        <!-- Volume chart -->
        <div class="cmcp-card" style="margin:0">
            <h2 style="font-size:14px;text-transform:uppercase;letter-spacing:0.5px;color:var(--cmcp-muted)">7-day volume</h2>
            <div class="cmcp-bars" title="Hover bars for counts">
                <?php
                $max = max( 1, max( array_column( $stats['daily'], 'total' ) ) );
                foreach ( $stats['daily'] as $d ) :
                    $ok_h   = $max ? max( 1, (int) ( $d['ok']   * 64 / $max ) ) : 1;
                    $fail_h = $max ? max( 0, (int) ( $d['fail'] * 64 / $max ) ) : 0;
                ?>
                    <div title="<?php echo esc_attr( $d['date'] . ': ' . $d['ok'] . ' ok / ' . $d['fail'] . ' fail' ); ?>" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;gap:1px">
                        <?php if ( $fail_h ) : ?><div class="bar fail" style="height:<?php echo (int) $fail_h; ?>px"></div><?php endif; ?>
                        <div class="bar" style="height:<?php echo (int) $ok_h; ?>px"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p style="font-size:11px;color:var(--cmcp-muted);margin:6px 0 0;display:flex;justify-content:space-between">
                <?php foreach ( $stats['daily'] as $d ) : ?>
                    <span><?php echo esc_html( substr( $d['date'], 5 ) ); ?></span>
                <?php endforeach; ?>
            </p>
        </div>

        <!-- Top tools -->
        <div class="cmcp-card" style="margin:0">
            <h2 style="font-size:14px;text-transform:uppercase;letter-spacing:0.5px;color:var(--cmcp-muted)">Top tools · last 7 days</h2>
            <?php if ( empty( $stats['top_tools'] ) ) : ?>
                <p style="color:var(--cmcp-muted);font-style:italic">No tool calls yet.</p>
            <?php else : ?>
                <table class="widefat striped cmcp-table-tools" style="border:0">
                    <?php foreach ( $stats['top_tools'] as $t ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $t['tool'] ); ?></code></td>
                            <td style="text-align:right;width:60px"><strong><?php echo (int) $t['n']; ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Endpoint & quick connect -->
    <div class="cmcp-card">
        <h2 style="font-size:14px;text-transform:uppercase;letter-spacing:0.5px;color:var(--cmcp-muted)">Quick connect</h2>
        <table style="width:100%">
            <tr>
                <td style="width:200px;padding:6px 0"><strong>MCP endpoint</strong></td>
                <td><code class="cmcp-inline-code" style="margin:0"><?php echo esc_html( $mcp_endpoint ); ?></code></td>
            </tr>
            <tr>
                <td style="padding:6px 0"><strong>Discovery</strong></td>
                <td><code class="cmcp-inline-code" style="margin:0"><?php echo esc_html( rest_url( CMCP_REST_NAMESPACE . '/discovery' ) ); ?></code></td>
            </tr>
            <tr>
                <td style="padding:6px 0"><strong>OAuth metadata</strong></td>
                <td><code class="cmcp-inline-code" style="margin:0"><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></code></td>
            </tr>
        </table>

        <p style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-tokens' ) ); ?>" class="button button-primary">🪙 Manage tokens</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-oauth' ) ); ?>" class="button">🔐 OAuth clients</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-log' ) ); ?>" class="button">📋 Audit log</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-settings' ) ); ?>" class="button">⚙ Settings</a>
        </p>
    </div>

    <!-- Security status -->
    <div class="cmcp-card">
        <h2 style="font-size:14px;text-transform:uppercase;letter-spacing:0.5px;color:var(--cmcp-muted)">Security status</h2>
        <ul style="list-style:none;padding:0;margin:0">
            <li style="padding:6px 0;border-bottom:1px solid #f0f0f1"><?php cmcp_status_row( ! empty( $settings['require_https'] ), __( 'HTTPS required', 'commander-secure-mcp-control' ), __( 'Reject non-HTTPS requests', 'commander-secure-mcp-control' ) ); ?></li>
            <li style="padding:6px 0;border-bottom:1px solid #f0f0f1"><?php cmcp_status_row( ! empty( $settings['enable_audit_log'] ), __( 'Audit log enabled', 'commander-secure-mcp-control' ), __( 'Every call recorded', 'commander-secure-mcp-control' ) ); ?></li>
            <li style="padding:6px 0;border-bottom:1px solid #f0f0f1"><?php cmcp_status_row( ! empty( $settings['block_bad_uas'] ), __( 'Block bad user-agents', 'commander-secure-mcp-control' ), __( 'sqlmap, nikto, wpscan, etc.', 'commander-secure-mcp-control' ) ); ?></li>
            <li style="padding:6px 0;border-bottom:1px solid #f0f0f1"><?php cmcp_status_row( ! empty( $settings['notify_admin_email'] ), __( 'Email alerts', 'commander-secure-mcp-control' ), __( 'Lockout & suspicious activity', 'commander-secure-mcp-control' ) ); ?></li>
            <li style="padding:6px 0;border-bottom:1px solid #f0f0f1"><?php cmcp_status_row( ! empty( $settings['allowed_origins'] ), __( 'Allowed origins set', 'commander-secure-mcp-control' ), __( 'DNS rebinding protection', 'commander-secure-mcp-control' ) ); ?></li>
            <li style="padding:6px 0"><?php cmcp_status_row( empty( $settings['allow_destructive'] ), __( 'Destructive ops OFF', 'commander-secure-mcp-control' ), __( 'Permanent deletes locked', 'commander-secure-mcp-control' ), true ); ?></li>
        </ul>
        <?php if ( ! empty( $stats['old_tokens'] ) ) : ?>
            <p style="margin-top:14px;padding:10px 14px;background:#fff7e6;border-left:3px solid var(--cmcp-warning);border-radius:4px">
                ⚠️ <?php echo (int) $stats['old_tokens']; ?> token(s) older than <?php echo (int) ( $settings['rotation_warn_days'] ?? 90 ); ?> days — consider rotating in <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-tokens' ) ); ?>">Tokens</a>.
            </p>
        <?php endif; ?>
    </div>

    <p class="cmcp-credit">
        Commander · Secure MCP Control ·
        <a href="https://github.com/TaherSayed/commander-secure-mcp-control" target="_blank" rel="noopener">GitHub</a> ·
        <a href="https://github.com/TaherSayed/commander-secure-mcp-control/issues" target="_blank" rel="noopener"><?php esc_html_e( 'Report an issue', 'commander-secure-mcp-control' ); ?></a> ·
        <a href="https://hbs-it-gmbh.de" target="_blank" rel="noopener">HBS IT GmbH</a> ·
        <?php esc_html_e( 'Built by Taher Sayed', 'commander-secure-mcp-control' ); ?>
    </p>
</div>
<?php
if ( ! function_exists( 'cmcp_status_row' ) ) {
    function cmcp_status_row( bool $ok, string $label, string $desc, bool $invert_color = false ): void {
        $good  = $invert_color ? ! $ok : $ok;
        $color = $good ? '#0a6041' : '#9b1c1c';
        $icon  = $good ? '✓' : '✗';
        echo '<span style="color:' . esc_attr( $color ) . ';font-weight:700;margin-right:8px">' . esc_html( $icon ) . '</span>';
        echo '<strong>' . esc_html( $label ) . '</strong> — <span style="color:var(--cmcp-muted)">' . esc_html( $desc ) . '</span>';
    }
}
