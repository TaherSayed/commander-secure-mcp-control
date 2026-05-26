<?php
/**
 * posts.update â€” update an existing post / page.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class PostsUpdateTool extends AbstractTool {

    public function name(): string { return 'posts_update'; }

    public function description(): string {
        return 'Update an existing post or page. Only supplied fields are changed. Caller must have edit_post capability for the target.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'id'         => [ 'type' => 'integer', 'minimum' => 1 ],
                'title'      => [ 'type' => 'string', 'maxLength' => 250 ],
                'content'    => [ 'type' => 'string' ],
                'excerpt'    => [ 'type' => 'string' ],
                'status'     => [ 'type' => 'string', 'enum' => [ 'draft', 'pending', 'publish', 'private', 'future' ] ],
                'slug'       => [ 'type' => 'string' ],
                'categories' => [ 'type' => 'array' ],
                'tags'       => [ 'type' => 'array' ],
                'meta'       => [ 'type' => 'object' ],
            ],
            'required'             => [ 'id' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_WRITE; }
    public function required_capability(): string { return 'edit_posts'; }

    public function execute( array $args ): array {
        $id   = (int) $args['id'];
        $post = get_post( $id );
        if ( ! $post ) {
            throw new \InvalidArgumentException( 'Post not found.' );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            throw new \RuntimeException( 'Not allowed to edit this post.' );
        }

        $data = [ 'ID' => $id ];
        if ( array_key_exists( 'title', $args ) )   { $data['post_title']   = sanitize_text_field( (string) $args['title'] ); }
        if ( array_key_exists( 'content', $args ) ) { $data['post_content'] = wp_kses_post( (string) $args['content'] ); }
        if ( array_key_exists( 'excerpt', $args ) ) { $data['post_excerpt'] = sanitize_textarea_field( (string) $args['excerpt'] ); }
        if ( array_key_exists( 'status', $args ) )  { $data['post_status']  = (string) $args['status']; }
        if ( ! empty( $args['slug'] ) )             { $data['post_name']    = sanitize_title( (string) $args['slug'] ); }

        $res = wp_update_post( $data, true );
        if ( is_wp_error( $res ) ) {
            throw new \RuntimeException( esc_html( $res->get_error_message() ) );
        }

        if ( isset( $args['categories'] ) && is_array( $args['categories'] ) ) {
            wp_set_post_categories( $id, array_map( 'intval', $args['categories'] ) );
        }
        if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
            wp_set_post_tags( $id, array_map( 'sanitize_text_field', $args['tags'] ) );
        }
        if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
            foreach ( $args['meta'] as $k => $v ) {
                $k = (string) $k;
                if ( $k === '' || str_starts_with( $k, '_' ) ) continue;
                update_post_meta( $id, $k, is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
            }
        }

        return $this->json( [
            'id'     => $id,
            'status' => get_post_status( $id ),
            'link'   => get_permalink( $id ),
        ] );
    }
}
