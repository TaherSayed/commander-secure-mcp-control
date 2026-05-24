<?php
/**
 * Main plugin class.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class Plugin {

    /** Option key holding global plugin settings. */
    public const OPT_SETTINGS = 'cmcp_settings';

    /** Custom DB table (audit log). Suffix appended to $wpdb->prefix. */
    public const TABLE_AUDIT  = 'cmcp_audit_log';

    /** Custom DB table (tokens). */
    public const TABLE_TOKENS = 'cmcp_tokens';

    /** OAuth: registered client apps (claude.ai etc). */
    public const TABLE_OAUTH_CLIENTS = 'cmcp_oauth_clients';

    /** OAuth: short-lived authorization codes (PKCE). */
    public const TABLE_OAUTH_CODES   = 'cmcp_oauth_codes';

    /** OAuth: access + refresh tokens issued via the flow. */
    public const TABLE_OAUTH_TOKENS  = 'cmcp_oauth_tokens';

    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void {

        // Self-heal: if our tables/options are missing or the stored version
        // is older than CMCP_VERSION, re-run dbDelta. Fixes installs where
        // register_activation_hook didn't fire — e.g. the "Replace current
        // with uploaded" upgrade path, manual SVN updates, or a site that
        // accidentally ran uninstall.php and then reinstalled.
        self::maybe_upgrade();

        // Register REST routes (the MCP endpoint).
        add_action( 'rest_api_init', [ Server::class, 'register_routes' ] );

        // Discovery endpoints (.well-known) — useful for clients.
        add_action( 'rest_api_init', [ Server::class, 'register_discovery' ] );

        // OAuth 2.1 endpoints (authorize, token, register, revoke).
        add_action( 'rest_api_init', [ OAuth::class, 'register_routes' ] );

        // /.well-known/oauth-authorization-server and /.well-known/oauth-protected-resource
        // need to live at the domain root, NOT under /wp-json. Intercept parse_request.
        add_action( 'parse_request', [ OAuth::class, 'serve_well_known' ], 0 );

        // Touch the Notifier class so its add_action() runs on every request —
        // otherwise the WP-Cron-fired `cmcp_send_webhook` event has no handler.
        class_exists( Notifier::class );

        // (GitHub auto-updater removed for WP.org compliance — updates come
        // from the WordPress.org plugin directory.)

        // Admin UI.
        if ( is_admin() ) {
            Admin::instance()->init();
        }

        // CLI commands (only if WP-CLI available).
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'cmcp', CLI::class );
        }
    }

    /**
     * Default settings used on activation and as a fallback.
     */
    public static function default_settings(): array {
        return [
            'require_https'        => true,
            'allowed_origins'      => [],  // empty = same-host only
            'rate_limit_per_min'   => 60,
            'max_request_bytes'    => 1048576, // 1 MiB (media uploads)
            'log_retention_days'   => 30,
            'enable_audit_log'     => true,
            'allow_destructive'    => false, // permanent deletes & high-risk option writes
            // v1.2.0
            'notify_admin_email'   => true,   // brute-force / suspicious activity emails
            'rotation_warn_days'   => 90,     // warn about PATs older than this
            'block_bad_uas'        => true,   // block known scanner user-agents
            // v1.3.0 — security hardening
            'trust_proxy'          => false,  // honor X-Forwarded-Proto / X-Forwarded-For / CF-Connecting-IP
            'allow_dcr'            => false,  // RFC 7591 dynamic client registration (off by default — public clients can flood the table)
            // v1.5.0 — outbound webhooks
            'webhook_url'          => '',     // POST {event,timestamp,site,data} on lockout / new OAuth client / destructive op
            'webhook_secret'       => '',     // optional HMAC-SHA256 secret; sent as X-Commander-Signature: sha256=...
            'enabled_tools'        => [
                // read
                'site.info', 'site.health', 'posts.list', 'posts.get', 'posts.search',
                'media.list', 'comments.list', 'terms.list', 'settings.get',
                // write
                'posts.create', 'posts.update', 'posts.delete',
                'media.upload', 'media.delete',
                'comments.moderate', 'terms.create',
                // admin
                'users.list', 'users.create', 'users.update',
                'plugins.list', 'plugins.toggle',
                'themes.list', 'themes.activate',
                'settings.update',
            ],
        ];
    }

    public static function get_settings(): array {
        $saved = get_option( self::OPT_SETTINGS, [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], self::default_settings() );
    }

    /* ---------------- Activation / Deactivation ---------------- */

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $tokens_table = $wpdb->prefix . self::TABLE_TOKENS;
        $audit_table  = $wpdb->prefix . self::TABLE_AUDIT;
        $oc_table     = $wpdb->prefix . self::TABLE_OAUTH_CLIENTS;
        $ocode_table  = $wpdb->prefix . self::TABLE_OAUTH_CODES;
        $ot_table     = $wpdb->prefix . self::TABLE_OAUTH_TOKENS;

        dbDelta( "CREATE TABLE {$tokens_table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label         VARCHAR(120)    NOT NULL,
            token_hash    CHAR(64)        NOT NULL,
            prefix        VARCHAR(12)     NOT NULL,
            scopes        VARCHAR(255)    NOT NULL DEFAULT 'read',
            user_id       BIGINT UNSIGNED NULL,
            ip_allowlist  TEXT            NULL,
            expires_at    DATETIME        NULL,
            last_used_at  DATETIME        NULL,
            created_at    DATETIME        NOT NULL,
            revoked_at    DATETIME        NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY prefix (prefix)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$audit_table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts          DATETIME        NOT NULL,
            token_id    BIGINT UNSIGNED NULL,
            ip          VARCHAR(45)     NULL,
            method      VARCHAR(120)    NULL,
            tool        VARCHAR(120)    NULL,
            success     TINYINT(1)      NOT NULL DEFAULT 0,
            status_code SMALLINT        NOT NULL DEFAULT 0,
            note        TEXT            NULL,
            PRIMARY KEY (id),
            KEY ts (ts),
            KEY token_id (token_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$oc_table} (
            id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id                  VARCHAR(64)     NOT NULL,
            client_secret_hash         CHAR(64)        NULL,
            name                       VARCHAR(200)    NOT NULL,
            redirect_uris              TEXT            NOT NULL,
            grant_types                VARCHAR(255)    NOT NULL DEFAULT 'authorization_code,refresh_token',
            token_endpoint_auth_method VARCHAR(40)     NOT NULL DEFAULT 'none',
            is_dcr                     TINYINT(1)      NOT NULL DEFAULT 1,
            created_at                 DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$ocode_table} (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code_hash             CHAR(64)        NOT NULL,
            client_id             VARCHAR(64)     NOT NULL,
            user_id               BIGINT UNSIGNED NOT NULL,
            scopes                VARCHAR(255)    NOT NULL,
            redirect_uri          TEXT            NOT NULL,
            code_challenge        VARCHAR(128)    NOT NULL,
            code_challenge_method VARCHAR(10)     NOT NULL,
            resource              TEXT            NULL,
            expires_at            DATETIME        NOT NULL,
            used_at               DATETIME        NULL,
            created_at            DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code_hash (code_hash)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$ot_table} (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            access_hash        CHAR(64)        NOT NULL,
            refresh_hash       CHAR(64)        NULL,
            client_id          VARCHAR(64)     NOT NULL,
            user_id            BIGINT UNSIGNED NOT NULL,
            scopes             VARCHAR(255)    NOT NULL,
            resource           TEXT            NULL,
            access_expires_at  DATETIME        NOT NULL,
            refresh_expires_at DATETIME        NULL,
            last_used_at       DATETIME        NULL,
            created_at         DATETIME        NOT NULL,
            revoked_at         DATETIME        NULL,
            PRIMARY KEY (id),
            UNIQUE KEY access_hash (access_hash),
            KEY refresh_hash (refresh_hash)
        ) {$charset};" );

        if ( ! get_option( self::OPT_SETTINGS ) ) {
            // First-time activation: seed with this site's URL as an allowed origin
            // and trigger the welcome wizard so the admin sees a one-screen setup.
            $defaults = self::default_settings();
            $home_url = home_url();
            $scheme   = wp_parse_url( $home_url, PHP_URL_SCHEME );
            $host     = wp_parse_url( $home_url, PHP_URL_HOST );
            if ( $host ) {
                $defaults['allowed_origins'] = [ $scheme . '://' . $host ];
            }
            add_option( self::OPT_SETTINGS, $defaults );
            set_transient( 'cmcp_show_wizard', 1, DAY_IN_SECONDS );
        }

        // Schedule daily cleanup.
        if ( ! wp_next_scheduled( 'cmcp_daily_cleanup' ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'cmcp_daily_cleanup' );
        }

        // Flush rewrites so /.well-known/ endpoints work.
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'cmcp_daily_cleanup' );
    }

    /** Option key storing the last version that ran the activation routine. */
    public const OPT_VERSION = 'cmcp_version';

    /**
     * Run activation routine (dbDelta etc.) if the installed code version
     * is newer than the last one that successfully activated, OR if our
     * primary token table is missing for any reason.
     */
    public static function maybe_upgrade(): void {
        $stored = (string) get_option( self::OPT_VERSION, '0.0.0' );
        $needs  = version_compare( $stored, CMCP_VERSION, '<' );

        if ( ! $needs ) {
            // Even with matching version, double-check the primary table exists.
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_TOKENS;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- One-shot self-heal check.
            $exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists === $table ) {
                return;
            }
        }

        // Run activation routine. dbDelta is idempotent for matching schemas.
        self::activate();
        update_option( self::OPT_VERSION, CMCP_VERSION, true );
    }
}

// Daily cleanup of old audit rows + expired OAuth artifacts.
add_action( 'cmcp_daily_cleanup', function () {
    global $wpdb;
    $settings = Plugin::get_settings();
    $days     = max( 1, (int) ( $settings['log_retention_days'] ?? 30 ) );

    $audit  = $wpdb->prefix . Plugin::TABLE_AUDIT;
    $codes  = $wpdb->prefix . Plugin::TABLE_OAUTH_CODES;
    $tokens = $wpdb->prefix . Plugin::TABLE_OAUTH_TOKENS;

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$audit} WHERE ts < DATE_SUB(NOW(), INTERVAL %d DAY)", $days ) );
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    // Expired or used auth codes older than 1 day — gone.
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
    $wpdb->query( "DELETE FROM {$codes}  WHERE expires_at < NOW() - INTERVAL 1 DAY OR used_at IS NOT NULL AND used_at < NOW() - INTERVAL 1 DAY" );
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    // Revoked tokens older than 30 days — gone.
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
    $wpdb->query( "DELETE FROM {$tokens} WHERE revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 30 DAY" );
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    // phpcs:enable
} );
