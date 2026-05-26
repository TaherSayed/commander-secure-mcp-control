<?php
/**
 * posts.create â€” create a post or page (any registered post type).
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class PostsCreateTool extends AbstractTool {

    public function name(): string { return 'posts_create'; }

    public function description(): string {
        return 'Create a new post (or page / custom post type). Returns the new ID and edit/view URLs. HTML in content is allowed but sanitised through wp_kses_post.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'post_type' => [ 'type' => 'string' ],
                'title'     => [ 'type' => 'string', 'maxLength' => 250 ],
                'content'   => [ 'type' => 'string' ],
                'excerpt'   => [ 'type' => 'string' ],
                'status'    => [ 'type' => 'string', 'enum' => [ 'draft', 'pending', 'publish', 'private', 'future' ] ],
                'date'      => [ 'type' => 'string', 'description' => 'GMT datetime, ISO-8601' ],
                'slug'      => [ 'type' => 'string' ],
                'author'    => [ 'type' => 'integer', 'minimum' => 1 ],
                'categories'=> [ 'type' => 'array' ],
                'tags'      => [ 'type' => 'array' ],
                'meta'      => [ 'type' => 'object' ],
            ],
            'required'             => [ 'title' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_WRITE; }
    public function required_capability(): string { return 'edit_posts'; }

    public function execute( array $args ): array {
        $post_type = (string) ( $args['post_type'] ?? 'post' );
        $types     = get_post_types( [ 'show_ui' => true ], 'names' );
        if ( ! in_array( $post_type, $types, true ) ) {
            throw new \InvalidArgumentException( esc_html( "Unknown or non-editable post_type: {$post_type}" ) );
        }

        $pto = get_post_type_object( $post_type );
        if ( $pto && ! current_user_can( $pto->cap->edit_posts ) ) {
            throw new \RuntimeException( esc_html( "Insufficient capability for post type '{$post_type}'." ) );
        }

        $data = [
            'post_type'    => $post_type,
            'post_status'  => $args['status']  ?? 'draft',
            'post_title'   => sanitize_text_field( (string) $args['title'] ),
            'post_content' => wp_kses_post( (string) ( $args['content'] ?? '' ) ),
            'post_excerpt' => sanitize_textarea_field( (string) ( $args['excerpt'] ?? '' ) ),
            'meta_input'   => is_array( $args['meta'] ?? null ) ? $this->safe_meta( $args['meta'] ) : [],
        ];

        if ( ! empty( $args['slug'] ) ) {
            $data['post_name'] = sanitize_title( (string) $args['slug'] );
        }
        if ( ! empty( $args['author'] ) ) {
            $data['post_author'] = (int) $args['author'];
        }
        if ( ! empty( $args['date'] ) ) {
            $ts = strtotime( (string) $args['date'] );
            if ( $ts ) {
                $data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
                $data['post_date']     = get_date_from_gmt( $data['post_date_gmt'] );
            }
        }

        $id = wp_insert_post( $data, true );
        if ( is_wp_error( $id ) ) {
            throw new \RuntimeException( esc_html( $id->get_error_message() ) );
        }

        if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
            wp_set_post_categories( $id, array_map( 'intval', $args['categories'] ) );
        }
        if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
            wp_set_post_tags( $id, array_map( 'sanitize_text_field', $args['tags'] ) );
        }

        return $this->json( [
            'id'        => (int) $id,
            'status'    => get_post_status( $id ),
            'link'      => get_permalink( $id ),
            'edit_link' => get_edit_post_link( $id, 'raw' ),
        ] );
    }

    /** Drop protected/underscored meta keys clients shouldn't touch. */
    private function safe_meta( array $meta ): array {
        $out = [];
        foreach ( $meta as $k => $v ) {
            $k = (string) $k;
            if ( $k === '' || str_starts_with( $k, '_' ) ) {
                continue;
            }
            $out[ $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
        }
        return $out;
    }
}
