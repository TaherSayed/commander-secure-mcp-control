<?php
/**
 * settings.get — read whitelisted WordPress options.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class SettingsGetTool extends AbstractTool {

    /** Options that can be read by MCP clients. Editable also live in SettingsUpdateTool. */
    public const READABLE = [
        'blogname', 'blogdescription', 'admin_email', 'timezone_string',
        'date_format', 'time_format', 'start_of_week',
        'default_category', 'default_comment_status', 'default_ping_status',
        'posts_per_page', 'posts_per_rss', 'show_on_front', 'page_on_front', 'page_for_posts',
        'permalink_structure', 'category_base', 'tag_base',
        'WPLANG', 'siteurl', 'home', 'comment_registration', 'users_can_register',
    ];

    public function name(): string { return 'settings.get'; }

    public function description(): string {
        return 'Read whitelisted WordPress site settings (general, reading, discussion, permalinks). Pass specific keys or omit to get all readable settings.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [ 'keys' => [ 'type' => 'array' ] ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string { return \CMCP\Auth::SCOPE_READ; }
    public function required_capability(): string { return 'manage_options'; }

    public function execute( array $args ): array {
        $keys = $args['keys'] ?? self::READABLE;
        if ( ! is_array( $keys ) || empty( $keys ) ) {
            $keys = self::READABLE;
        }
        $out = [];
        foreach ( $keys as $k ) {
            $k = sanitize_key( (string) $k );
            if ( ! in_array( $k, self::READABLE, true ) ) continue;
            $out[ $k ] = get_option( $k );
        }
        return $this->json( $out );
    }
}
