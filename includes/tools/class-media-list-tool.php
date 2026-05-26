<?php
/**
 * media.list â€” list attachments.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class MediaListTool extends AbstractTool {

    public function name(): string { return 'media_list'; }

    public function description(): string {
        return 'List media library attachments with id, title, mime type, URL and thumbnail.';
    }

    public function input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                'page'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ],
                'mime_type' => [ 'type' => 'string', 'maxLength' => 60 ],
                'search'    => [ 'type' => 'string', 'maxLength' => 120 ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_READ; }
    public function required_capability(): string { return 'upload_files'; }

    public function execute( array $args ): array {
        $q = new \WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => (int) ( $args['per_page'] ?? 25 ),
            'paged'          => (int) ( $args['page']     ?? 1 ),
            'post_mime_type' => isset( $args['mime_type'] ) ? sanitize_mime_type( (string) $args['mime_type'] ) : '',
            's'              => isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '',
        ] );

        $items = [];
        foreach ( $q->posts as $att ) {
            $items[] = [
                'id'        => (int) $att->ID,
                'title'     => get_the_title( $att ),
                'mime'      => get_post_mime_type( $att ),
                'url'       => wp_get_attachment_url( $att->ID ),
                'thumbnail' => wp_get_attachment_image_url( $att->ID, 'thumbnail' ),
                'date'      => mysql2date( 'c', $att->post_date_gmt, false ),
                'alt'       => (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
            ];
        }
        return $this->json( [
            'total'       => (int) $q->found_posts,
            'total_pages' => (int) $q->max_num_pages,
            'items'       => $items,
        ] );
    }
}
