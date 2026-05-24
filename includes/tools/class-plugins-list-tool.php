<?php
/**
 * plugins.list — list installed plugins with active state and update availability.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class PluginsListTool extends AbstractTool {

    public function name(): string { return 'plugins.list'; }

    public function description(): string {
        return 'List installed plugins. Returns plugin file, name, version, active state, network active state and whether an update is available.';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'activate_plugins'; }

    public function execute( array $args ): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all      = get_plugins();
        $active   = (array) get_option( 'active_plugins', [] );
        $updates  = get_site_transient( 'update_plugins' );
        $upd_map  = $updates && ! empty( $updates->response ) ? array_keys( (array) $updates->response ) : [];

        $items = [];
        foreach ( $all as $file => $data ) {
            $items[] = [
                'file'           => $file,
                'name'           => $data['Name'] ?? '',
                'version'        => $data['Version'] ?? '',
                'author'         => wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ),
                'description'    => wp_strip_all_tags( (string) ( $data['Description'] ?? '' ) ),
                'is_active'      => in_array( $file, $active, true ),
                'network_active' => is_multisite() ? is_plugin_active_for_network( $file ) : false,
                'update_available' => in_array( $file, $upd_map, true ),
            ];
        }
        return $this->json( [ 'items' => $items ] );
    }
}
