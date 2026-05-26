<?php
/**
 * themes.list â€” list installed themes.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class ThemesListTool extends AbstractTool {

    public function name(): string { return 'themes_list'; }

    public function description(): string {
        return 'List installed themes with name, version, parent (if child theme), and active flag.';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'switch_themes'; }

    public function execute( array $args ): array {
        $current = wp_get_theme()->get_stylesheet();
        $items = [];
        foreach ( wp_get_themes() as $slug => $theme ) {
            $items[] = [
                'stylesheet' => $slug,
                'name'       => $theme->get( 'Name' ),
                'version'    => $theme->get( 'Version' ),
                'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                'active'     => $slug === $current,
            ];
        }
        return $this->json( [ 'items' => $items, 'active' => $current ] );
    }
}
