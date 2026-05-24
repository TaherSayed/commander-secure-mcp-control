<?php
/**
 * WordPress admin UI.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class Admin {

    private static ?Admin $instance = null;

    public static function instance(): Admin {
        return self::$instance ??= new self();
    }

    public function init(): void {
        add_action( 'admin_menu',  [ $this, 'menu' ] );
        add_action( 'admin_init',  [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_dashboard_setup',    [ $this, 'register_dashboard_widget' ] );
        add_action( 'admin_post_cmcp_create_token', [ $this, 'handle_create_token' ] );
        add_action( 'admin_post_cmcp_revoke_token', [ $this, 'handle_revoke_token' ] );
        add_action( 'admin_post_cmcp_delete_token', [ $this, 'handle_delete_token' ] );
        add_action( 'admin_post_cmcp_rotate_token', [ $this, 'handle_rotate_token' ] );
        add_action( 'wp_ajax_cmcp_test_token',       [ $this, 'ajax_test_token' ] );
        add_action( 'admin_post_cmcp_oauth_delete_client',  [ $this, 'handle_oauth_delete_client' ] );
        add_action( 'admin_post_cmcp_oauth_revoke_tokens',  [ $this, 'handle_oauth_revoke_tokens' ] );
        // Wizard hooks.
        Wizard::init();
    }

    public function enqueue_assets( string $hook ): void {
        // Only on our pages.
        if ( ! str_contains( (string) $hook, 'cmcp' ) && $hook !== 'index.php' ) {
            return;
        }
        wp_enqueue_style(
            'cmcp-admin',
            CMCP_URL . 'assets/css/admin.css',
            [],
            CMCP_VERSION
        );
    }

    public function menu(): void {
        add_menu_page(
            __( 'Commander', 'commander-secure-mcp-control' ),
            __( 'Commander', 'commander-secure-mcp-control' ),
            'manage_options',
            'cmcp',
            [ $this, 'render_dashboard' ],
            'dashicons-shield-alt',
            81
        );
        add_submenu_page( 'cmcp', __( 'Dashboard',     'commander-secure-mcp-control' ), __( 'Dashboard',     'commander-secure-mcp-control' ), 'manage_options', 'cmcp',           [ $this, 'render_dashboard' ] );
        add_submenu_page( 'cmcp', __( 'Settings',      'commander-secure-mcp-control' ), __( 'Settings',      'commander-secure-mcp-control' ), 'manage_options', 'cmcp-settings',  [ $this, 'render_settings' ] );
        add_submenu_page( 'cmcp', __( 'Tokens',        'commander-secure-mcp-control' ), __( 'Tokens',        'commander-secure-mcp-control' ), 'manage_options', 'cmcp-tokens',    [ $this, 'render_tokens' ] );
        add_submenu_page( 'cmcp', __( 'OAuth Clients', 'commander-secure-mcp-control' ), __( 'OAuth Clients', 'commander-secure-mcp-control' ), 'manage_options', 'cmcp-oauth',     [ $this, 'render_oauth' ] );
        add_submenu_page( 'cmcp', __( 'Audit Log',     'commander-secure-mcp-control' ), __( 'Audit Log',     'commander-secure-mcp-control' ), 'manage_options', 'cmcp-log',       [ $this, 'render_log' ] );
        // Hidden setup wizard (no parent slug match → not shown in menu).
        add_submenu_page( null, __( 'Setup Wizard', 'commander-secure-mcp-control' ), __( 'Setup Wizard', 'commander-secure-mcp-control' ), 'manage_options', 'cmcp-wizard', [ Wizard::class, 'render' ] );

        // Custom footer credit on our pages only.
        add_filter( 'admin_footer_text',  [ $this, 'footer_credit' ] );
        add_filter( 'update_footer',      [ $this, 'footer_version' ], 11 );
    }

    /* ----------------------- Dashboard ----------------------- */

    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $stats = $this->compute_stats();
        include CMCP_DIR . 'includes/admin/views/dashboard-page.php';
    }

    /** Compute the dashboard KPIs. Cheap aggregate queries. */
    private function compute_stats(): array {
        global $wpdb;
        $audit  = $wpdb->prefix . Plugin::TABLE_AUDIT;
        $tokens = $wpdb->prefix . Plugin::TABLE_TOKENS;
        $oc     = $wpdb->prefix . Plugin::TABLE_OAUTH_CLIENTS;

        // 7-day daily breakdown.
        $daily = [];
        for ( $i = 6; $i >= 0; $i-- ) {
            $d = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    SUM(success = 1) AS ok,
                    SUM(success = 0) AS fail,
                    COUNT(*) AS total
                 FROM {$audit} WHERE DATE(ts) = %s", $d
            ), ARRAY_A );
            $daily[] = [
                'date'  => $d,
                'ok'    => (int) ( $row['ok']    ?? 0 ),
                'fail'  => (int) ( $row['fail']  ?? 0 ),
                'total' => (int) ( $row['total'] ?? 0 ),
            ];
        }

        $top_tools = $wpdb->get_results(
            "SELECT tool, COUNT(*) AS n FROM {$audit}
             WHERE tool IS NOT NULL AND tool <> '' AND ts > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY tool ORDER BY n DESC LIMIT 8", ARRAY_A
        );

        $rotation_days  = max( 1, (int) ( Plugin::get_settings()['rotation_warn_days'] ?? 90 ) );
        $old_tokens     = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tokens} WHERE revoked_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $rotation_days
        ) );

        // Count active lockouts by enumerating known IPs in audit (best-effort).
        $locked_ips = 0;
        $recent_ips = $wpdb->get_col( "SELECT DISTINCT ip FROM {$audit} WHERE ts > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 500" );
        foreach ( (array) $recent_ips as $ip ) {
            if ( BruteForce::check_lockout( (string) $ip ) ) {
                $locked_ips++;
            }
        }

        return [
            'calls_today'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE DATE(ts) = CURDATE()" ),
            'ok_24h'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE ts > DATE_SUB(NOW(), INTERVAL 1 DAY) AND success = 1" ),
            'fail_24h'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE ts > DATE_SUB(NOW(), INTERVAL 1 DAY) AND success = 0" ),
            'active_tokens' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tokens} WHERE revoked_at IS NULL" ),
            'oauth_clients' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$oc}" ),
            'locked_ips'    => $locked_ips,
            'daily'         => $daily,
            'top_tools'     => $top_tools ?: [],
            'old_tokens'    => $old_tokens,
        ];
    }

    /* ----------------------- WP Dashboard widget ----------------------- */

    public function register_dashboard_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_add_dashboard_widget( 'cmcp_dashboard_widget', '🛡️ Commander — MCP activity', [ $this, 'render_dashboard_widget' ] );
    }

    public function render_dashboard_widget(): void {
        $stats = $this->compute_stats();
        $url   = admin_url( 'admin.php?page=cmcp' );
        echo '<div class="cmcp-stats" style="margin:8px 0 16px;grid-template-columns:repeat(3,1fr);gap:8px">';
        echo '<div class="cmcp-stat" style="padding:10px 14px"><span class="num" style="font-size:22px">' . (int) $stats['calls_today']  . '</span><span class="lbl">Today</span></div>';
        echo '<div class="cmcp-stat success" style="padding:10px 14px"><span class="num" style="font-size:22px">' . (int) $stats['ok_24h']  . '</span><span class="lbl">OK 24h</span></div>';
        $cls = $stats['fail_24h'] ? 'danger' : '';
        echo '<div class="cmcp-stat ' . esc_attr( $cls ) . '" style="padding:10px 14px"><span class="num" style="font-size:22px">' . (int) $stats['fail_24h']  . '</span><span class="lbl">Failed</span></div>';
        echo '</div>';
        echo '<p style="text-align:right;margin:0"><a href="' . esc_url( $url ) . '">Open dashboard →</a></p>';
    }

    public function footer_credit( $text ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && str_starts_with( (string) $screen->id, 'toplevel_page_cmcp' ) === false && str_contains( (string) $screen->id, 'cmcp' ) === false ) {
            return $text;
        }
        return '<span style="color:#646970">Commander · Secure MCP Control · <strong>powered by Taher Sayed</strong> — HBS IT GmbH · <a href="https://github.com/TaherSayed/commander-secure-mcp-control" target="_blank" rel="noopener">GitHub</a></span>';
    }

    public function footer_version( $text ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && str_contains( (string) $screen->id, 'cmcp' ) ) {
            return 'v' . CMCP_VERSION;
        }
        return $text;
    }

    public function register_settings(): void {
        register_setting(
            'cmcp_settings_group',
            Plugin::OPT_SETTINGS,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
                'default'           => Plugin::default_settings(),
            ]
        );
    }

    public function sanitize_settings( $value ): array {
        $clean = Plugin::default_settings();
        if ( ! is_array( $value ) ) {
            return $clean;
        }
        $clean['require_https']      = ! empty( $value['require_https'] );
        $clean['enable_audit_log']   = ! empty( $value['enable_audit_log'] );
        $clean['allow_destructive']  = ! empty( $value['allow_destructive'] );
        $clean['notify_admin_email'] = ! empty( $value['notify_admin_email'] );
        $clean['block_bad_uas']      = ! empty( $value['block_bad_uas'] );
        $clean['trust_proxy']        = ! empty( $value['trust_proxy'] );
        $clean['allow_dcr']          = ! empty( $value['allow_dcr'] );
        $clean['rotation_warn_days'] = max( 7, min( 730, (int) ( $value['rotation_warn_days'] ?? 90 ) ) );
        $clean['rate_limit_per_min'] = max( 0, min( 6000, (int) ( $value['rate_limit_per_min'] ?? 60 ) ) );
        $clean['max_request_bytes']  = max( 4096, min( 8 * 1024 * 1024, (int) ( $value['max_request_bytes'] ?? 262144 ) ) );
        $clean['log_retention_days'] = max( 1, min( 3650, (int) ( $value['log_retention_days'] ?? 30 ) ) );

        $origins = (array) ( $value['allowed_origins'] ?? [] );
        if ( is_string( $value['allowed_origins'] ?? null ) ) {
            $origins = preg_split( '/[\r\n,]+/', (string) $value['allowed_origins'] ) ?: [];
        }
        $origins = array_filter( array_map( static function ( $o ) {
            $o = trim( (string) $o );
            return preg_match( '#^https?://[^\s/]+$#i', $o ) ? $o : null;
        }, $origins ) );
        $clean['allowed_origins'] = array_values( $origins );

        $enabled = (array) ( $value['enabled_tools'] ?? [] );
        $enabled = array_values( array_filter( array_map( 'sanitize_text_field', $enabled ) ) );
        $clean['enabled_tools'] = $enabled;

        return $clean;
    }

    /* ----------------- Views ----------------- */

    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = Plugin::get_settings();
        include CMCP_DIR . 'includes/admin/views/settings-page.php';
    }

    public function render_tokens(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $tokens = Auth::list_tokens();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only routing read after admin_post redirect; capability check above.
        $just_token = isset( $_GET['new_token'] ) ? sanitize_text_field( wp_unslash( $_GET['new_token'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice flag after admin_post redirect.
        $notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
        $test_nonce = wp_create_nonce( 'cmcp_test_token' );
        $ajax_url   = admin_url( 'admin-ajax.php' );
        include CMCP_DIR . 'includes/admin/views/tokens-page.php';
    }

    public function render_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $rows = Logger::recent( 200 );
        include CMCP_DIR . 'includes/admin/views/log-page.php';
    }

    public function render_oauth(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb;
        $clients = $wpdb->get_results(
            "SELECT c.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}" . Plugin::TABLE_OAUTH_TOKENS . " t
                  WHERE t.client_id = c.client_id AND t.revoked_at IS NULL AND t.access_expires_at > NOW()) AS active_tokens
             FROM {$wpdb->prefix}" . Plugin::TABLE_OAUTH_CLIENTS . " c
             ORDER BY c.id DESC",
            ARRAY_A
        );
        include CMCP_DIR . 'includes/admin/views/oauth-page.php';
    }

    /* ----------------- Actions ----------------- */

    public function handle_create_token(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'cmcp_create_token' );

        // Nonce already verified by check_admin_referer() above; the
        // following $_POST reads sanitize each value inline (sanitize_key
        // for scope strings, absint for ints, sanitize_text_field elsewhere).

        $raw_scopes = isset( $_POST['scopes'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['scopes'] ) ) : [];
        $scopes     = array_intersect( Auth::SCOPES, $raw_scopes );

        $expires_in = isset( $_POST['expires_days'] )
            ? max( 0, absint( wp_unslash( $_POST['expires_days'] ) ) ) * DAY_IN_SECONDS
            : 0;

        $ip_list = [];
        if ( isset( $_POST['ip_allowlist'] ) ) {
            $raw_ips = sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ) );
            $ip_list = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw_ips ) ) );
        }

        $result = Auth::issue_token( [
            'label'        => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : 'MCP token',
            'scopes'       => array_values( $scopes ) ?: [ Auth::SCOPE_READ ],
            'user_id'      => isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0,
            'ip_allowlist' => $ip_list,
            'expires_in'   => $expires_in,
        ] );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'cmcp-tokens', 'new_token' => rawurlencode( $result['token'] ) ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function handle_revoke_token(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'cmcp_revoke_token' );
        $id = isset( $_POST['token_id'] ) ? absint( wp_unslash( $_POST['token_id'] ) ) : 0;
        if ( $id ) {
            Auth::revoke_token( $id );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=cmcp-tokens&notice=revoked' ) );
        exit;
    }

    public function handle_delete_token(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'cmcp_delete_token' );
        $id = isset( $_POST['token_id'] ) ? absint( wp_unslash( $_POST['token_id'] ) ) : 0;
        if ( $id ) {
            Auth::delete_token( $id );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=cmcp-tokens&notice=deleted' ) );
        exit;
    }

    public function handle_rotate_token(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'cmcp_rotate_token' );
        $id  = isset( $_POST['token_id'] ) ? absint( wp_unslash( $_POST['token_id'] ) ) : 0;
        $new = $id ? Auth::rotate_token( $id ) : null;
        $args = [ 'page' => 'cmcp-tokens' ];
        if ( $new && ! empty( $new['token'] ) ) {
            $args['new_token'] = rawurlencode( $new['token'] );
            $args['notice']    = 'rotated';
        } else {
            $args['notice'] = 'rotate_failed';
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * AJAX: live "Test connection" — POSTs `initialize` to /rpc with the bearer
     * the admin just clicked Test on. Returns { ok, status, latency_ms, message }.
     */
    public function ajax_test_token(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }
        check_ajax_referer( 'cmcp_test_token', '_nonce' );
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        if ( $token === '' || strlen( $token ) < 16 || strlen( $token ) > 256 ) {
            wp_send_json_error( [ 'message' => __( 'Missing or malformed token.', 'commander-secure-mcp-control' ) ] );
        }

        $url   = rest_url( CMCP_REST_NAMESPACE . '/rpc' );
        $start = microtime( true );
        $resp  = wp_remote_post( $url, [
            'timeout'   => 8,
            'headers'   => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'      => wp_json_encode( [
                'jsonrpc' => '2.0',
                'id'      => 'cmcp-test',
                'method'  => 'initialize',
                'params'  => [
                    'protocolVersion' => CMCP_PROTOCOL_VERSION,
                    'clientInfo'      => [ 'name' => 'cmcp-self-test', 'version' => CMCP_VERSION ],
                    'capabilities'    => (object) [],
                ],
            ] ),
            'sslverify' => true,
        ] );
        $latency = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [
                'message'    => $resp->get_error_message(),
                'latency_ms' => $latency,
            ] );
        }
        $status = (int) wp_remote_retrieve_response_code( $resp );
        $body   = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

        $ok = ( $status === 200 && isset( $body['result']['protocolVersion'] ) );
        $message = $ok
            ? sprintf(
                /* translators: 1: MCP protocol version returned by the server, 2: round-trip latency in ms */
                __( 'OK — MCP %1$s, %2$d ms', 'commander-secure-mcp-control' ),
                (string) $body['result']['protocolVersion'],
                $latency
            )
            : sprintf(
                /* translators: 1: HTTP status code, 2: error message from server */
                __( 'Failed — HTTP %1$d, %2$s', 'commander-secure-mcp-control' ),
                $status,
                (string) ( $body['error']['message'] ?? $body['message'] ?? 'no body' )
            );

        wp_send_json_success( [
            'ok'         => $ok,
            'status'     => $status,
            'latency_ms' => $latency,
            'message'    => $message,
        ] );
    }

    public function handle_oauth_delete_client(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'cmcp_oauth_delete_client' );
        $client_id = sanitize_text_field( wp_unslash( (string) ( $_POST['client_id'] ?? '' ) ) );
        if ( $client_id ) {
            global $wpdb;
            // Revoke tokens first, then delete client.
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
            $wpdb->update(
                $wpdb->prefix . Plugin::TABLE_OAUTH_TOKENS,
                [ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
                [ 'client_id' => $client_id ],
                [ '%s' ], [ '%s' ]
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
            $wpdb->delete( $wpdb->prefix . Plugin::TABLE_OAUTH_CLIENTS, [ 'client_id' => $client_id ], [ '%s' ] );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        }
        wp_safe_redirect( admin_url( 'admin.php?page=cmcp-oauth' ) );
        exit;
    }

    public function handle_oauth_revoke_tokens(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'cmcp_oauth_revoke_tokens' );
        $client_id = sanitize_text_field( wp_unslash( (string) ( $_POST['client_id'] ?? '' ) ) );
        if ( $client_id ) {
            global $wpdb;
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
            $wpdb->update(
                $wpdb->prefix . Plugin::TABLE_OAUTH_TOKENS,
                [ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
                [ 'client_id' => $client_id, 'revoked_at' => null ],
                [ '%s' ], [ '%s', '%s' ]
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        }
        wp_safe_redirect( admin_url( 'admin.php?page=cmcp-oauth' ) );
        exit;
    }
}
