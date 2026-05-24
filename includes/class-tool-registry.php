<?php
/**
 * Tool & resource registry.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class ToolRegistry {

    private static ?ToolRegistry $instance = null;

    /** @var array<string, Tools\AbstractTool> */
    private array $tools = [];

    public static function instance(): ToolRegistry {
        if ( ! self::$instance ) {
            self::$instance = new self();
            self::$instance->bootstrap();
        }
        return self::$instance;
    }

    private function bootstrap(): void {
        $enabled = Plugin::get_settings()['enabled_tools'] ?? [];

        $candidates = [
            // Read
            new Tools\SiteInfoTool(),
            new Tools\SiteHealthTool(),
            new Tools\PostsListTool(),
            new Tools\PostsGetTool(),
            new Tools\PostsSearchTool(),
            new Tools\MediaListTool(),
            new Tools\CommentsListTool(),
            new Tools\TermsListTool(),
            new Tools\SettingsGetTool(),
            // Write
            new Tools\PostsCreateTool(),
            new Tools\PostsUpdateTool(),
            new Tools\PostsDeleteTool(),
            new Tools\MediaUploadTool(),
            new Tools\MediaDeleteTool(),
            new Tools\CommentsModerateTool(),
            new Tools\TermsCreateTool(),
            // Admin
            new Tools\UsersListTool(),
            new Tools\UsersCreateTool(),
            new Tools\UsersUpdateTool(),
            new Tools\PluginsListTool(),
            new Tools\PluginsToggleTool(),
            new Tools\ThemesListTool(),
            new Tools\ThemesActivateTool(),
            new Tools\SettingsUpdateTool(),
        ];

        // Allow other plugins / themes to register tools.
        $candidates = apply_filters( 'cmcp_register_tools', $candidates );

        foreach ( $candidates as $tool ) {
            if ( ! $tool instanceof Tools\AbstractTool ) {
                continue;
            }
            if ( ! in_array( $tool->name(), (array) $enabled, true ) ) {
                continue;
            }
            $this->tools[ $tool->name() ] = $tool;
        }
    }

    public function get( string $name ): ?Tools\AbstractTool {
        return $this->tools[ $name ] ?? null;
    }

    public function list_for_client(): array {
        $out = [];
        foreach ( $this->tools as $tool ) {
            $out[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->input_schema(),
            ];
        }
        return $out;
    }

    /**
     * Resources: simple site-level resources by URI.
     * Default set is empty — register via the 'cmcp_resources' filter.
     */
    public function list_resources(): array {
        return (array) apply_filters( 'cmcp_resources', [] );
    }

    public function read_resource( string $uri ): array {
        $handlers = (array) apply_filters( 'cmcp_resource_handlers', [] );
        if ( isset( $handlers[ $uri ] ) && is_callable( $handlers[ $uri ] ) ) {
            $payload = call_user_func( $handlers[ $uri ] );
            return [
                'contents' => [
                    [
                        'uri'      => $uri,
                        'mimeType' => 'text/plain',
                        'text'     => is_string( $payload ) ? $payload : wp_json_encode( $payload ),
                    ],
                ],
            ];
        }
        throw new \InvalidArgumentException( esc_html( "Unknown resource: {$uri}" ) );
    }
}
