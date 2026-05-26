<?php
/**
 * themes.activate â€” switch the active theme.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class ThemesActivateTool extends AbstractTool {

    public function name(): string { return 'themes_activate'; }

    public function description(): string {
        return 'Activate an installed theme by its stylesheet slug. Theme must already be installed.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [ 'stylesheet' => [ 'type' => 'string', 'maxLength' => 120 ] ],
            'required'             => [ 'stylesheet' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'switch_themes'; }

    public function execute( array $args ): array {
        $slug  = sanitize_key( (string) $args['stylesheet'] );
        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() ) {
            throw new \InvalidArgumentException( 'Theme not installed.' );
        }
        if ( $theme->errors() ) {
            throw new \RuntimeException( 'Theme has errors and cannot be activated.' );
        }
        switch_theme( $slug );
        return $this->json( [ 'stylesheet' => $slug, 'active' => wp_get_theme()->get_stylesheet() ] );
    }
}
