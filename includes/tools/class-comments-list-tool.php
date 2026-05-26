<?php
/**
 * comments.list â€” list comments by status.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class CommentsListTool extends AbstractTool {

    public function name(): string { return 'comments_list'; }

    public function description(): string {
        return 'List comments filtered by status (approve, hold, spam, trash). Returns author name (PII), email is omitted unless caller has moderate_comments.';
    }

    public function input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'status'    => [ 'type' => 'string', 'enum' => [ 'approve', 'hold', 'spam', 'trash', 'all' ] ],
                'post_id'   => [ 'type' => 'integer', 'minimum' => 0 ],
                'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                'page'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_READ; }
    public function required_capability(): string { return 'edit_posts'; }

    public function execute( array $args ): array {
        $per_page = (int) ( $args['per_page'] ?? 25 );
        $page     = (int) ( $args['page']     ?? 1 );

        $q = new \WP_Comment_Query( [
            'status'  => $args['status'] ?? 'all',
            'post_id' => (int) ( $args['post_id'] ?? 0 ),
            'number'  => $per_page,
            'offset'  => ( $page - 1 ) * $per_page,
        ] );

        $show_email = current_user_can( 'moderate_comments' );
        $items = [];
        foreach ( $q->get_comments() as $c ) {
            $items[] = [
                'id'       => (int) $c->comment_ID,
                'post_id'  => (int) $c->comment_post_ID,
                'author'   => $c->comment_author,
                'email'    => $show_email ? $c->comment_author_email : null,
                'date'     => mysql2date( 'c', $c->comment_date_gmt, false ),
                'content'  => wp_strip_all_tags( $c->comment_content ),
                'status'   => wp_get_comment_status( $c->comment_ID ),
                'link'     => get_comment_link( $c ),
            ];
        }
        return $this->json( [ 'items' => $items ] );
    }
}
