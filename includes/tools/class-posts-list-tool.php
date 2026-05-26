<?php
/**
 * posts.list â€” list published posts (or another post type) with pagination.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class PostsListTool extends AbstractTool {

    public function name(): string { return 'posts_list'; }

    public function description(): string {
        return 'List published posts. Returns id, title, excerpt, date, link, and author. Supports paging and post type.';
    }

    public function input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_type' => [
                    'type'        => 'string',
                    'enum'        => $this->public_post_types(),
                    'description' => 'Post type slug. Default: post.',
                ],
                'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ],
                'page'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ],
                'orderby'   => [ 'type' => 'string', 'enum' => [ 'date', 'modified', 'title' ] ],
                'order'     => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ] ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function public_post_types(): array {
        $types = get_post_types( [ 'public' => true ], 'names' );
        return array_values( $types );
    }

    public function execute( array $args ): array {
        $post_type = $args['post_type'] ?? 'post';
        if ( ! in_array( $post_type, $this->public_post_types(), true ) ) {
            $post_type = 'post';
        }

        $q = new \WP_Query( [
            'post_type'           => $post_type,
            'post_status'         => 'publish',
            'posts_per_page'      => (int) ( $args['per_page'] ?? 10 ),
            'paged'               => (int) ( $args['page']     ?? 1 ),
            'orderby'             => $args['orderby'] ?? 'date',
            'order'               => strtoupper( (string) ( $args['order'] ?? 'desc' ) ),
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
        ] );

        $items = [];
        foreach ( $q->posts as $p ) {
            $items[] = [
                'id'      => (int) $p->ID,
                'title'   => get_the_title( $p ),
                'excerpt' => wp_strip_all_tags( get_the_excerpt( $p ) ),
                'date'    => mysql2date( 'c', $p->post_date_gmt, false ),
                'link'    => get_permalink( $p ),
                'author'  => get_the_author_meta( 'display_name', $p->post_author ),
            ];
        }

        return $this->json( [
            'total'       => (int) $q->found_posts,
            'total_pages' => (int) $q->max_num_pages,
            'items'       => $items,
        ] );
    }
}
