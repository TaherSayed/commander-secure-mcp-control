<?php
/**
 * media.upload — sideload an attachment from a URL into the media library.
 *
 * Only HTTP/HTTPS URLs are allowed; private/loopback IPs are blocked
 * (SSRF protection). Mime types are restricted to those WordPress
 * already considers safe (get_allowed_mime_types).
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class MediaUploadTool extends AbstractTool {

    public function name(): string { return 'media.upload'; }

    public function description(): string {
        return 'Download an image or file from a public URL and add it to the media library. Returns the new attachment ID and URL. Private/internal hosts are blocked.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'url'         => [ 'type' => 'string', 'maxLength' => 2048 ],
                'title'       => [ 'type' => 'string', 'maxLength' => 200 ],
                'alt'         => [ 'type' => 'string', 'maxLength' => 200 ],
                'description' => [ 'type' => 'string', 'maxLength' => 1000 ],
                'parent_id'   => [ 'type' => 'integer', 'minimum' => 0 ],
            ],
            'required'             => [ 'url' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_WRITE; }
    public function required_capability(): string { return 'upload_files'; }

    public function execute( array $args ): array {
        $url = esc_url_raw( (string) $args['url'] );
        if ( ! $url ) {
            throw new \InvalidArgumentException( 'Invalid URL.' );
        }
        $this->guard_ssrf( $url );

        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Use media_handle_sideload-style flow to get attachment ID back.
        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            throw new \RuntimeException( esc_html( 'Download failed: ' . $tmp->get_error_message() ) );
        }

        $filename = basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'upload' );
        $file = [ 'name' => sanitize_file_name( $filename ), 'tmp_name' => $tmp ];

        // Mime check via WordPress' own allowlist.
        $check = wp_check_filetype_and_ext( $tmp, $file['name'] );
        if ( empty( $check['type'] ) ) {
            wp_delete_file( $tmp );
            throw new \RuntimeException( 'File type not allowed.' );
        }

        $att_id = media_handle_sideload( $file, (int) ( $args['parent_id'] ?? 0 ), (string) ( $args['title'] ?? '' ) );
        if ( is_wp_error( $att_id ) ) {
            wp_delete_file( $tmp );
            throw new \RuntimeException( esc_html( $att_id->get_error_message() ) );
        }

        if ( ! empty( $args['alt'] ) ) {
            update_post_meta( $att_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
        }
        if ( ! empty( $args['description'] ) ) {
            wp_update_post( [ 'ID' => $att_id, 'post_content' => sanitize_textarea_field( (string) $args['description'] ) ] );
        }

        return $this->json( [
            'id'        => (int) $att_id,
            'url'       => wp_get_attachment_url( $att_id ),
            'thumbnail' => wp_get_attachment_image_url( $att_id, 'thumbnail' ),
            'mime'      => get_post_mime_type( $att_id ),
        ] );
    }

    /** Block SSRF: only http/https, public hosts only. */
    private function guard_ssrf( string $url ): void {
        $p = wp_parse_url( $url );
        if ( ! $p || empty( $p['scheme'] ) || ! in_array( strtolower( $p['scheme'] ), [ 'http', 'https' ], true ) ) {
            throw new \InvalidArgumentException( 'Only http(s) URLs are accepted.' );
        }
        $host = $p['host'] ?? '';
        if ( $host === '' ) {
            throw new \InvalidArgumentException( 'URL host required.' );
        }
        // Resolve and check IPs.
        $ips = gethostbynamel( $host );
        if ( ! $ips ) {
            // IPv6 fallback or unresolvable — be conservative.
            throw new \InvalidArgumentException( 'Host does not resolve.' );
        }
        foreach ( $ips as $ip ) {
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                throw new \InvalidArgumentException( 'Host resolves to a private or reserved IP — refusing for SSRF protection.' );
            }
        }
    }
}
