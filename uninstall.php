<?php
/**
 * Uninstall — drop tables, options, transients, scheduled events, and
 * the bot user the setup wizard may have created. Runs only when the
 * site admin actually deletes the plugin.
 *
 * @package ClaudeMCPSecure
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall-script locals; not real globals.

global $wpdb;

/* --------------------------- Tables --------------------------- */

$tables = [
    $wpdb->prefix . 'cmcp_tokens',
    $wpdb->prefix . 'cmcp_audit_log',
    $wpdb->prefix . 'cmcp_oauth_clients',
    $wpdb->prefix . 'cmcp_oauth_codes',
    $wpdb->prefix . 'cmcp_oauth_tokens',
];
foreach ( $tables as $t ) {
    // Table names cannot be parameterised; the prefix is trusted WP config.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall must drop its own tables; required by WP.org clean-uninstall policy.
    $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $t ) . '`' );
}

/* --------------------------- Options --------------------------- */

delete_option( 'cmcp_settings' );
delete_option( 'cmcp_version' );

/* --------------------------- Transients --------------------------- *
 * Brute-force counters, lockouts, rate-limit buckets, wizard state, etc.
 * Wildcards aren't supported by delete_transient(), so query the options
 * table directly and remove every cmcp_* transient.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_cmcp\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_cmcp\\_%'"
);
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
// Site transients (multisite).
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_site\\_transient\\_cmcp\\_%'
        OR option_name LIKE '\\_site\\_transient\\_timeout\\_cmcp\\_%'"
);
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:enable

/* --------------------------- Bot user --------------------------- *
 * The setup wizard can create a dedicated "wp-commander-bot" administrator
 * to own MCP tokens. Remove it on uninstall and reassign any content it
 * still owns to the user running the uninstall.
 */
if ( function_exists( 'wp_delete_user' ) ) {
    $cmcp_bot = get_user_by( 'login', 'wp-commander-bot' );
    if ( $cmcp_bot ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( (int) $cmcp_bot->ID, get_current_user_id() ?: null );
    }
}

/* --------------------------- Cron + rewrite --------------------------- */

wp_clear_scheduled_hook( 'cmcp_daily_cleanup' );

// Flush rewrites so /.well-known/* hooks are removed cleanly.
if ( function_exists( 'flush_rewrite_rules' ) ) {
    flush_rewrite_rules( false );
}
