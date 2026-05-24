<?php
/**
 * WP-CLI commands.
 *
 *   wp cmcp token issue --label="ci" --scopes=read,write --user-id=2
 *   wp cmcp token list
 *   wp cmcp token revoke 5
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

final class CLI {

    /**
     * Manage MCP tokens.
     *
     * ## SUBCOMMANDS
     *   issue|list|revoke
     */
    public function token( $args, $assoc ): void {
        $sub = $args[0] ?? '';
        switch ( $sub ) {
            case 'issue':
                $res = Auth::issue_token( [
                    'label'      => (string) ( $assoc['label']   ?? 'cli' ),
                    'scopes'     => explode( ',', (string) ( $assoc['scopes'] ?? 'read' ) ),
                    'user_id'    => (int) ( $assoc['user-id']    ?? 0 ),
                    'expires_in' => (int) ( $assoc['expires-days'] ?? 0 ) * DAY_IN_SECONDS,
                ] );
                \WP_CLI::success( "Token created (id={$res['row_id']}): {$res['token']}" );
                \WP_CLI::warning( 'Copy now — this will not be shown again.' );
                break;

            case 'list':
                $rows = Auth::list_tokens();
                if ( ! $rows ) {
                    \WP_CLI::log( 'No tokens.' );
                    return;
                }
                \WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'label', 'prefix', 'scopes', 'user_id', 'expires_at', 'last_used_at', 'revoked_at' ] );
                break;

            case 'revoke':
                $id = (int) ( $args[1] ?? 0 );
                if ( ! $id ) {
                    \WP_CLI::error( 'Token id required.' );
                }
                Auth::revoke_token( $id );
                \WP_CLI::success( "Revoked #$id" );
                break;

            default:
                \WP_CLI::error( "Unknown subcommand: $sub" );
        }
    }
}
