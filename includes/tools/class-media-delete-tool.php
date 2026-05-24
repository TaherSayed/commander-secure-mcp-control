<?php
/**
 * media.delete — delete an attachment.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class MediaDeleteTool extends AbstractTool {

    public function name(): string { return 'media.delete'; }

    public function description(): string {
        return 'Permanently delete an attachment from the media library along with its files.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'             => [ 'id' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_WRITE; }
    public function required_capability(): string { return 'upload_files'; }

    public function execute( array $args ): array {
        $id = (int) $args['id'];
        if ( get_post_type( $id ) !== 'attachment' ) {
            throw new \InvalidArgumentException( 'Not an attachment.' );
        }
        if ( ! current_user_can( 'delete_post', $id ) ) {
            throw new \RuntimeException( 'Not allowed to delete this attachment.' );
        }
        $res = wp_delete_attachment( $id, true );
        if ( ! $res ) {
            throw new \RuntimeException( 'Delete failed.' );
        }
        return $this->json( [ 'id' => $id, 'deleted' => true ] );
    }
}
