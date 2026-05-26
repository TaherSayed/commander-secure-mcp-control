<?php
/**
 * posts.get â€” fetch one post by ID. Only published posts are returned to non-privileged users.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class PostsGetTool extends AbstractTool {

    public function name(): string { return 'posts_get'; }

    public function description(): string {
        return 'Fetch a single post by ID. Returns full content (cleaned of shortcodes). Only published posts are accessible unless caller has edit_posts capability.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'id' => [ 'type' => 'integer', 'minimum' => 1 ],
            ],
            'required'             => [ 'id' ],
            'additionalProperties' => false,
        ];
    }

    public function execute( array $args ): array {
        $post = get_post( (int) $args['id'] );
        if ( ! $post ) {
            throw new \InvalidArgumentException( 'Post not found.' );
        }

        if ( $post->post_status !== 'publish' && ! current_user_can( 'edit_post', $post->ID ) ) {
            throw new \RuntimeException( 'Post not accessible.' );
        }

        return $this->json( [
            'id'         => (int) $post->ID,
            'type'       => $post->post_type,
            'status'     => $post->post_status,
            'title'      => get_the_title( $post ),
            'content'    => wp_strip_all_tags( strip_shortcodes( $post->post_content ) ),
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying WordPress core's documented `the_content` filter; not a custom hook.
            'html'       => apply_filters( 'the_content', $post->post_content ),
            'excerpt'    => wp_strip_all_tags( get_the_excerpt( $post ) ),
            'date'       => mysql2date( 'c', $post->post_date_gmt, false ),
            'modified'   => mysql2date( 'c', $post->post_modified_gmt, false ),
            'link'       => get_permalink( $post ),
            'author'     => get_the_author_meta( 'display_name', $post->post_author ),
            'categories' => wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] ),
            'tags'       => wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] ),
        ] );
    }
}
