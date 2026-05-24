<?php
/**
 * site.info — return basic public site info.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class SiteInfoTool extends AbstractTool {

    public function name(): string { return 'site.info'; }

    public function description(): string {
        return 'Return basic information about this WordPress site: title, tagline, URL, language, and timezone.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function execute( array $args ): array {
        $info = [
            'name'        => get_bloginfo( 'name' ),
            'description' => get_bloginfo( 'description' ),
            'url'         => home_url( '/' ),
            'admin_email' => '', // intentionally omitted
            'language'    => get_locale(),
            'timezone'    => wp_timezone_string(),
            'wp_version'  => get_bloginfo( 'version' ),
        ];
        return $this->json( $info );
    }
}
