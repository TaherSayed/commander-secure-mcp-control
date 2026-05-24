<?php
/**
 * OAuth Clients admin page.
 *
 * @var array  $clients
 * @var array  $active_tokens
 * @var string $notice
 *
 * @package WPCommander
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-template locals, scoped to include().

$auth_url   = rest_url( CMCP_REST_NAMESPACE . '/oauth/authorize' );
$token_url  = rest_url( CMCP_REST_NAMESPACE . '/oauth/token' );
$reg_url    = rest_url( CMCP_REST_NAMESPACE . '/oauth/register' );
$as_meta    = home_url( '/.well-known/oauth-authorization-server' );
$pr_meta    = home_url( '/.well-known/oauth-protected-resource' );
$mcp_url    = rest_url( CMCP_REST_NAMESPACE . '/rpc' );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Commander — OAuth Clients', 'mcp-for-claude' ); ?></h1>
    <p class="description"><?php esc_html_e( 'OAuth 2.1 apps (claude.ai etc.) that have registered with this server via Dynamic Client Registration.', 'mcp-for-claude' ); ?></p>

    <?php if ( $notice === 'token_revoked' ) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'OAuth token revoked.', 'mcp-for-claude' ); ?></p></div>
    <?php endif; ?>

    <div class="notice notice-info">
        <p style="margin-bottom:4px"><strong><?php esc_html_e( 'For claude.ai web (Settings → Connectors → + Add custom connector):', 'mcp-for-claude' ); ?></strong></p>
        <p style="margin-top:0">
            <?php esc_html_e( 'Custom MCP server URL:', 'mcp-for-claude' ); ?>
            <code style="font-size:13px"><?php echo esc_html( $mcp_url ); ?></code>
        </p>
        <p style="color:#646970;font-size:12px;margin-top:0">
            <?php esc_html_e( 'Claude will auto-discover OAuth via /.well-known and register itself. After the consent screen, an entry will appear below.', 'mcp-for-claude' ); ?>
        </p>
    </div>

    <h2><?php esc_html_e( 'Endpoint reference', 'mcp-for-claude' ); ?></h2>
    <table class="widefat" style="max-width:900px">
        <tbody>
            <tr><td style="width:240px"><strong>MCP endpoint</strong></td><td><code><?php echo esc_html( $mcp_url ); ?></code></td></tr>
            <tr><td><strong>Authorization endpoint</strong></td><td><code><?php echo esc_html( $auth_url ); ?></code></td></tr>
            <tr><td><strong>Token endpoint</strong></td><td><code><?php echo esc_html( $token_url ); ?></code></td></tr>
            <tr><td><strong>Registration endpoint</strong></td><td><code><?php echo esc_html( $reg_url ); ?></code></td></tr>
            <tr><td><strong>Authorization Server Metadata</strong></td><td><code><?php echo esc_html( $as_meta ); ?></code></td></tr>
            <tr><td><strong>Protected Resource Metadata</strong></td><td><code><?php echo esc_html( $pr_meta ); ?></code></td></tr>
        </tbody>
    </table>

    <h2 style="margin-top:30px"><?php esc_html_e( 'Registered clients', 'mcp-for-claude' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Client ID', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Redirect URIs', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Auth method', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Active tokens', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Registered', 'mcp-for-claude' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $clients ) ) : ?>
            <tr><td colspan="7"><?php esc_html_e( 'No OAuth clients registered yet. Add a custom connector in claude.ai pointing at the MCP endpoint above — the first authorization will register the client here.', 'mcp-for-claude' ); ?></td></tr>
        <?php else : foreach ( $clients as $c ) :
            $redir = json_decode( (string) $c['redirect_uris'], true );
            $redir = is_array( $redir ) ? $redir : [];
            ?>
            <tr>
                <td><?php echo esc_html( $c['name'] ); ?></td>
                <td><code style="font-size:11px"><?php echo esc_html( $c['client_id'] ); ?></code></td>
                <td><?php foreach ( $redir as $u ) : ?><div style="font-size:11px"><code><?php echo esc_html( (string) $u ); ?></code></div><?php endforeach; ?></td>
                <td><?php echo esc_html( $c['token_endpoint_auth_method'] ); ?></td>
                <td><?php echo (int) ( $c['active_tokens'] ?? 0 ); ?></td>
                <td><?php echo esc_html( $c['created_at'] ); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke ALL active tokens for this client?', 'mcp-for-claude' ) ); ?>');">
                        <input type="hidden" name="action" value="cmcp_oauth_revoke_tokens" />
                        <input type="hidden" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>" />
                        <?php wp_nonce_field( 'cmcp_oauth_revoke_tokens' ); ?>
                        <button class="button" type="submit"><?php esc_html_e( 'Revoke tokens', 'mcp-for-claude' ); ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this client and revoke its tokens? It will need to re-authorize.', 'mcp-for-claude' ) ); ?>');">
                        <input type="hidden" name="action" value="cmcp_oauth_delete_client" />
                        <input type="hidden" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>" />
                        <?php wp_nonce_field( 'cmcp_oauth_delete_client' ); ?>
                        <button class="button button-link-delete" type="submit"><?php esc_html_e( 'Delete client', 'mcp-for-claude' ); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h2 style="margin-top:30px"><?php esc_html_e( 'Active OAuth sessions', 'mcp-for-claude' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Individual access tokens issued to clients via the OAuth flow. Use "Revoke" to kill a single session without deleting the parent client.', 'mcp-for-claude' ); ?></p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Client', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'WP user', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Scopes', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Issued', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Last used', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Access expires', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Refresh expires', 'mcp-for-claude' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $active_tokens ) ) : ?>
            <tr><td colspan="8"><?php esc_html_e( 'No active OAuth sessions right now.', 'mcp-for-claude' ); ?></td></tr>
        <?php else : foreach ( $active_tokens as $t ) :
            $user = (int) $t['user_id'] ? get_user_by( 'id', (int) $t['user_id'] ) : null;
        ?>
            <tr>
                <td>
                    <strong><?php echo esc_html( (string) ( $t['client_name'] ?? '?' ) ); ?></strong><br>
                    <code style="font-size:10px;color:#646970"><?php echo esc_html( $t['client_id'] ); ?></code>
                </td>
                <td><?php echo $user ? esc_html( $user->user_login ) . ' <span style="color:#646970">#' . (int) $t['user_id'] . '</span>' : '<span style="color:#a7aaad">—</span>'; ?></td>
                <td>
                    <?php foreach ( array_filter( array_map( 'trim', explode( ',', (string) $t['scopes'] ) ) ) as $sc ) : ?>
                        <span class="cmcp-badge <?php echo esc_attr( $sc ); ?>"><?php echo esc_html( $sc ); ?></span>
                    <?php endforeach; ?>
                </td>
                <td><code style="font-size:11px"><?php echo esc_html( $t['created_at'] ); ?></code></td>
                <td><?php echo $t['last_used_at'] ? '<code style="font-size:11px">' . esc_html( $t['last_used_at'] ) . '</code>' : '<span style="color:#a7aaad">—</span>'; ?></td>
                <td><code style="font-size:11px"><?php echo esc_html( $t['access_expires_at'] ); ?></code></td>
                <td><code style="font-size:11px"><?php echo esc_html( $t['refresh_expires_at'] ?: '—' ); ?></code></td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this OAuth session?', 'mcp-for-claude' ) ); ?>');">
                        <input type="hidden" name="action" value="cmcp_oauth_revoke_one" />
                        <input type="hidden" name="oauth_token_id" value="<?php echo (int) $t['id']; ?>" />
                        <?php wp_nonce_field( 'cmcp_oauth_revoke_one' ); ?>
                        <button class="button button-small" type="submit"><?php esc_html_e( 'Revoke', 'mcp-for-claude' ); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
