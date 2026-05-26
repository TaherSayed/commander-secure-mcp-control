<?php
/**
 * comments.moderate â€” approve / unapprove / spam / trash a comment.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class CommentsModerateTool extends AbstractTool {

    public function name(): string { return 'comments_moderate'; }

    public function description(): string {
        return 'Change moderation status of a comment: approve, hold (unapprove), spam, trash, or untrash.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'id'     => [ 'type' => 'integer', 'minimum' => 1 ],
                'action' => [ 'type' => 'string', 'enum' => [ 'approve', 'hold', 'spam', 'trash', 'untrash' ] ],
            ],
            'required'             => [ 'id', 'action' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_WRITE; }
    public function required_capability(): string { return 'moderate_comments'; }

    public function execute( array $args ): array {
        $id = (int) $args['id'];
        if ( ! get_comment( $id ) ) {
            throw new \InvalidArgumentException( 'Comment not found.' );
        }
        $ok = match ( $args['action'] ) {
            'approve'   => wp_set_comment_status( $id, 'approve' ),
            'hold'      => wp_set_comment_status( $id, 'hold' ),
            'spam'      => wp_spam_comment( $id ),
            'trash'     => wp_trash_comment( $id ),
            'untrash'   => wp_untrash_comment( $id ),
            default     => false,
        };
        if ( ! $ok ) {
            throw new \RuntimeException( 'Action failed.' );
        }
        return $this->json( [ 'id' => $id, 'status' => wp_get_comment_status( $id ) ] );
    }
}
