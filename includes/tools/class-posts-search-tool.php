<?php
/**
 * posts.search â€” full-text search across published content.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class PostsSearchTool extends AbstractTool {

    public function name(): string { return 'posts_search'; }

    public function description(): string {
        return 'Full-text search across published posts and pages. Returns the top matches with id, title, excerpt and link.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'query'    => [ 'type' => 'string', 'maxLength' => 200 ],
                'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 25 ],
            ],
            'required'             => [ 'query' ],
            'additionalProperties' => false,
        ];
    }

    public function execute( array $args ): array {
        $term = trim( (string) $args['query'] );
        if ( $term === '' ) {
            throw new \InvalidArgumentException( 'query must not be empty.' );
        }

        $q = new \WP_Query( [
            's'                   => $term,
            'post_status'         => 'publish',
            'post_type'           => [ 'post', 'page' ],
            'posts_per_page'      => (int) ( $args['per_page'] ?? 10 ),
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
        ] );

        $items = [];
        foreach ( $q->posts as $p ) {
            $items[] = [
                'id'      => (int) $p->ID,
                'type'    => $p->post_type,
                'title'   => get_the_title( $p ),
                'excerpt' => wp_strip_all_tags( get_the_excerpt( $p ) ),
                'date'    => mysql2date( 'c', $p->post_date_gmt, false ),
                'link'    => get_permalink( $p ),
            ];
        }

        return $this->json( [
            'query' => $term,
            'total' => (int) $q->found_posts,
            'items' => $items,
        ] );
    }
}
