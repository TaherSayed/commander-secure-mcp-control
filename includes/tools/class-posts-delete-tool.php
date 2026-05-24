<?php
/**
 * posts.delete — trash a post, or (with danger mode on) permanently delete it.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

use CMCP\Plugin;

defined( 'ABSPATH' ) || exit;

final class PostsDeleteTool extends AbstractTool {

    public function name(): string { return 'posts.delete'; }

    public function description(): string {
        return 'Move a post to trash. Set permanent=true to delete forever (requires "Allow destructive operations" to be enabled in plugin settings).';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'id'        => [ 'type' => 'integer', 'minimum' => 1 ],
                'permanent' => [ 'type' => 'boolean' ],
            ],
            'required'             => [ 'id' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_WRITE; }
    public function required_capability(): string { return 'delete_posts'; }

    public function execute( array $args ): array {
        $id = (int) $args['id'];
        if ( ! current_user_can( 'delete_post', $id ) ) {
            throw new \RuntimeException( 'Not allowed to delete this post.' );
        }

        $permanent = ! empty( $args['permanent'] );
        if ( $permanent && empty( Plugin::get_settings()['allow_destructive'] ) ) {
            throw new \RuntimeException( esc_html( 'Permanent delete is disabled. Enable "Allow destructive operations" in Commander settings.' ) );
        }

        $res = $permanent ? wp_delete_post( $id, true ) : wp_trash_post( $id );
        if ( ! $res ) {
            throw new \RuntimeException( 'Delete failed.' );
        }

        return $this->json( [ 'id' => $id, 'permanent' => $permanent, 'deleted' => true ] );
    }
}
