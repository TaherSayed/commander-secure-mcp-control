<?php
/**
 * plugins.toggle â€” activate or deactivate a plugin.
 *
 * Protects Commander itself: refuses to deactivate its own plugin file.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class PluginsToggleTool extends AbstractTool {

    public function name(): string { return 'plugins_toggle'; }

    public function description(): string {
        return 'Activate or deactivate a plugin by its plugin file (e.g. "akismet/akismet.php"). Refuses to deactivate Commander itself.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'file'   => [ 'type' => 'string', 'maxLength' => 250 ],
                'action' => [ 'type' => 'string', 'enum' => [ 'activate', 'deactivate' ] ],
            ],
            'required'             => [ 'file', 'action' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'activate_plugins'; }

    public function execute( array $args ): array {
        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Reject path traversal & non-listed plugins.
        $file = (string) $args['file'];
        if ( str_contains( $file, '..' ) || str_starts_with( $file, '/' ) ) {
            throw new \InvalidArgumentException( 'Bad plugin file path.' );
        }
        $all = get_plugins();
        if ( ! isset( $all[ $file ] ) ) {
            throw new \InvalidArgumentException( 'Plugin not installed.' );
        }

        // Refuse to deactivate ourselves.
        if ( $args['action'] === 'deactivate' && $file === plugin_basename( CMCP_FILE ) ) {
            throw new \RuntimeException( 'Refusing to deactivate Commander.' );
        }

        if ( $args['action'] === 'activate' ) {
            $r = activate_plugin( $file );
            if ( is_wp_error( $r ) ) {
                throw new \RuntimeException( esc_html( $r->get_error_message() ) );
            }
        } else {
            deactivate_plugins( $file );
        }
        return $this->json( [ 'file' => $file, 'action' => $args['action'], 'is_active' => is_plugin_active( $file ) ] );
    }
}
