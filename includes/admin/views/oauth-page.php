<?php
/**
 * OAuth Clients admin page.
 *
 * @var array $clients
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
    <h1><?php esc_html_e( 'Commander — OAuth Clients', 'commander-secure-mcp-control' ); ?></h1>
    <p class="description"><?php esc_html_e( 'OAuth 2.1 apps (claude.ai etc.) that have registered with this server via Dynamic Client Registration.', 'commander-secure-mcp-control' ); ?></p>

    <div class="notice notice-info">
        <p style="margin-bottom:4px"><strong><?php esc_html_e( 'For claude.ai web (Settings → Connectors → + Add custom connector):', 'commander-secure-mcp-control' ); ?></strong></p>
        <p style="margin-top:0">
            <?php esc_html_e( 'Custom MCP server URL:', 'commander-secure-mcp-control' ); ?>
            <code style="font-size:13px"><?php echo esc_html( $mcp_url ); ?></code>
        </p>
        <p style="color:#646970;font-size:12px;margin-top:0">
            <?php esc_html_e( 'Claude will auto-discover OAuth via /.well-known and register itself. After the consent screen, an entry will appear below.', 'commander-secure-mcp-control' ); ?>
        </p>
    </div>

    <h2><?php esc_html_e( 'Endpoint reference', 'commander-secure-mcp-control' ); ?></h2>
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

    <h2 style="margin-top:30px"><?php esc_html_e( 'Registered clients', 'commander-secure-mcp-control' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Client ID', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Redirect URIs', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Auth method', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Active tokens', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Registered', 'commander-secure-mcp-control' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $clients ) ) : ?>
            <tr><td colspan="7"><?php esc_html_e( 'No OAuth clients registered yet. Add a custom connector in claude.ai pointing at the MCP endpoint above — the first authorization will register the client here.', 'commander-secure-mcp-control' ); ?></td></tr>
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
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Revoke ALL active tokens for this client?');">
                        <input type="hidden" name="action" value="cmcp_oauth_revoke_tokens" />
                        <input type="hidden" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>" />
                        <?php wp_nonce_field( 'cmcp_oauth_revoke_tokens' ); ?>
                        <button class="button" type="submit"><?php esc_html_e( 'Revoke tokens', 'commander-secure-mcp-control' ); ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Delete this client and revoke its tokens? It will need to re-authorize.');">
                        <input type="hidden" name="action" value="cmcp_oauth_delete_client" />
                        <input type="hidden" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>" />
                        <?php wp_nonce_field( 'cmcp_oauth_delete_client' ); ?>
                        <button class="button button-link-delete" type="submit"><?php esc_html_e( 'Delete client', 'commander-secure-mcp-control' ); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
