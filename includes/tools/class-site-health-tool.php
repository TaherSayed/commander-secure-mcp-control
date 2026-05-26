<?php
/**
 * site.health â€” quick technical health snapshot.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class SiteHealthTool extends AbstractTool {

    public function name(): string { return 'site_health'; }

    public function description(): string {
        return 'Return a technical health snapshot: WP/PHP/MySQL versions, debug flags, multisite status, theme, active plugin count, disk free, memory limit, available updates.';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'manage_options'; }

    public function execute( array $args ): array {
        global $wpdb;
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option( 'active_plugins', [] );
        $core_upd  = function_exists( 'get_core_updates' ) ? get_core_updates() : [];
        $core_avail = false;
        if ( is_array( $core_upd ) && ! empty( $core_upd ) ) {
            foreach ( $core_upd as $u ) {
                if ( isset( $u->response ) && $u->response === 'upgrade' ) { $core_avail = true; break; }
            }
        }
        $upd_plugins = get_site_transient( 'update_plugins' );
        $upd_themes  = get_site_transient( 'update_themes' );

        $data = [
            'wp_version'      => get_bloginfo( 'version' ),
            'php_version'     => PHP_VERSION,
            'mysql_version'   => $wpdb ? $wpdb->db_version() : null,
            'is_multisite'    => is_multisite(),
            'debug'           => defined( 'WP_DEBUG' )      && WP_DEBUG,
            'debug_log'       => defined( 'WP_DEBUG_LOG' )  && WP_DEBUG_LOG,
            'script_debug'    => defined( 'SCRIPT_DEBUG' )  && SCRIPT_DEBUG,
            'memory_limit'    => ini_get( 'memory_limit' ),
            'max_upload'      => size_format( wp_max_upload_size() ),
            'disk_free_bytes' => @disk_free_space( ABSPATH ),
            'active_theme'    => wp_get_theme()->get( 'Name' ),
            'plugins_active'  => count( $active ),
            'plugins_total'   => count( get_plugins() ),
            'updates' => [
                'core'    => $core_avail,
                'plugins' => $upd_plugins && ! empty( $upd_plugins->response ) ? count( (array) $upd_plugins->response ) : 0,
                'themes'  => $upd_themes  && ! empty( $upd_themes->response )  ? count( (array) $upd_themes->response )  : 0,
            ],
        ];
        return $this->json( $data );
    }
}
