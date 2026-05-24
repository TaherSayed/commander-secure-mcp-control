<?php
/**
 * Plugin Name:       Commander — Secure MCP Control
 * Plugin URI:        https://github.com/TaherSayed/commander-secure-mcp-control
 * Description:       Give Claude and other MCP-compatible AI clients full, secure, audited control of your WordPress site. JSON-RPC 2.0 / Streamable HTTP, OAuth 2.1, brute-force protection, activation wizard, stats dashboard, audit log. Powered by Taher Sayed · HBS IT GmbH.
 * Version:           1.5.2
 * Requires at least: 6.2
 * Tested up to:      7.0
 * Requires PHP:      8.0
 * Author:            Taher Sayed · HBS IT GmbH
 * Author URI:        https://hbs-it-gmbh.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/TaherSayed/commander-secure-mcp-control
 * Text Domain:       commander-secure-mcp-control
 *
 * @package WPCommander
 */

// Block direct access.
defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'CMCP_VERSION',        '1.5.2' );
define( 'CMCP_FILE',           __FILE__ );
define( 'CMCP_DIR',            plugin_dir_path( __FILE__ ) );
define( 'CMCP_URL',            plugin_dir_url( __FILE__ ) );
define( 'CMCP_BASENAME',       plugin_basename( __FILE__ ) );
define( 'CMCP_REST_NAMESPACE', 'claude-mcp/v1' );
define( 'CMCP_PROTOCOL_VERSION', '2025-06-18' );

// Minimum PHP guard (defensive; header also enforces).
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'Claude MCP Secure requires PHP 8.0 or higher.', 'commander-secure-mcp-control' )
            . '</p></div>';
    } );
    return;
}

// Autoload (PSR-4-lite, our own simple loader — no Composer required).
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'CMCP\\' ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( 'CMCP\\' ) );
    $relative = str_replace( '\\', '/', $relative );
    // Convert CamelCase to class-kebab-case.php (WP convention).
    $parts    = explode( '/', $relative );
    $filename = array_pop( $parts );
    $filename = 'class-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $filename ) ) . '.php';
    $subdir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
    $path     = CMCP_DIR . 'includes/' . $subdir . $filename;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );

// Activation / deactivation.
register_activation_hook( __FILE__, [ 'CMCP\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CMCP\\Plugin', 'deactivate' ] );

// Bootstrap.
add_action( 'plugins_loaded', function () {
    CMCP\Plugin::instance()->init();
} );
