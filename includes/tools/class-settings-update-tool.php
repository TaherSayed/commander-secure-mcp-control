<?php
/**
 * settings.update â€” update whitelisted WordPress options.
 *
 * "Dangerous" options (siteurl, home, admin_email, users_can_register, default_role,
 * permalink_structure) additionally require Allow destructive operations = on.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

use CMCP\Plugin;

defined( 'ABSPATH' ) || exit;

final class SettingsUpdateTool extends AbstractTool {

    /** Safe-to-change options. */
    public const SAFE = [
        'blogname', 'blogdescription', 'timezone_string',
        'date_format', 'time_format', 'start_of_week',
        'default_category', 'default_comment_status', 'default_ping_status',
        'posts_per_page', 'posts_per_rss', 'show_on_front', 'page_on_front', 'page_for_posts',
        'WPLANG', 'comment_registration',
    ];

    /** Sensitive options â€” only when "Allow destructive" is enabled. */
    public const SENSITIVE = [
        'admin_email', 'siteurl', 'home', 'users_can_register', 'default_role', 'permalink_structure',
        'category_base', 'tag_base',
    ];

    public function name(): string { return 'settings_update'; }

    public function description(): string {
        return 'Update one or more whitelisted WordPress options. Sensitive options (URLs, admin email, permalinks) only work with destructive operations enabled.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [ 'options' => [ 'type' => 'object' ] ],
            'required'             => [ 'options' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'manage_options'; }

    public function execute( array $args ): array {
        $options = (array) $args['options'];
        if ( empty( $options ) ) {
            throw new \InvalidArgumentException( 'No options supplied.' );
        }
        $danger_ok = ! empty( Plugin::get_settings()['allow_destructive'] );

        $changed = [];
        $skipped = [];
        foreach ( $options as $k => $v ) {
            $k = sanitize_key( (string) $k );
            if ( in_array( $k, self::SAFE, true ) ) {
                update_option( $k, $this->cast_for_key( $k, $v ) );
                $changed[ $k ] = get_option( $k );
            } elseif ( in_array( $k, self::SENSITIVE, true ) ) {
                if ( ! $danger_ok ) {
                    $skipped[ $k ] = 'destructive_disabled';
                    continue;
                }
                update_option( $k, $this->cast_for_key( $k, $v ) );
                $changed[ $k ] = get_option( $k );
            } else {
                $skipped[ $k ] = 'not_whitelisted';
            }
        }
        return $this->json( [ 'changed' => $changed, 'skipped' => $skipped ] );
    }

    private function cast_for_key( string $key, $value ) {
        return match ( $key ) {
            'posts_per_page', 'posts_per_rss', 'start_of_week',
            'default_category', 'page_on_front', 'page_for_posts',
            'users_can_register', 'comment_registration'
                => (int) $value,
            'admin_email'
                => sanitize_email( (string) $value ),
            'siteurl', 'home'
                => esc_url_raw( (string) $value ),
            default
                => sanitize_text_field( (string) $value ),
        };
    }
}
