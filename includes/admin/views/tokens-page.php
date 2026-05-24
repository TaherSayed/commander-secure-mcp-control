<?php
/**
 * Tokens admin page.
 *
 * @var array  $tokens
 * @var string $just_token  Plaintext token to display ONCE after creation.
 *
 * @package ClaudeMCPSecure
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-template locals, scoped to include().
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Commander — Tokens', 'commander-secure-mcp-control' ); ?></h1>

    <?php if ( $just_token ) : ?>
        <div class="notice notice-success">
            <p>
                <strong><?php esc_html_e( 'Token created. Copy it now — it will not be shown again:', 'commander-secure-mcp-control' ); ?></strong>
            </p>
            <p>
                <code style="font-size:14px;background:#f6f7f7;padding:8px;display:inline-block"><?php echo esc_html( $just_token ); ?></code>
            </p>
        </div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Issue new token', 'commander-secure-mcp-control' ); ?></h2>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="cmcp_create_token" />
        <?php wp_nonce_field( 'cmcp_create_token' ); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="label"><?php esc_html_e( 'Label', 'commander-secure-mcp-control' ); ?></label></th>
                <td><input id="label" name="label" type="text" class="regular-text" required maxlength="120" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Scopes', 'commander-secure-mcp-control' ); ?></th>
                <td>
                    <label><input type="checkbox" name="scopes[]" value="read" checked /> read</label>
                    <label><input type="checkbox" name="scopes[]" value="write" /> write</label>
                    <label><input type="checkbox" name="scopes[]" value="admin" /> admin</label>
                </td>
            </tr>
            <tr>
                <th><label for="user_id"><?php esc_html_e( 'Bind to WP user (optional)', 'commander-secure-mcp-control' ); ?></label></th>
                <td>
                    <input id="user_id" name="user_id" type="number" min="0" value="0" />
                    <p class="description"><?php esc_html_e( 'When set, the token executes with that user\'s WordPress capabilities. Use a least-privilege account.', 'commander-secure-mcp-control' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="expires_days"><?php esc_html_e( 'Expires in (days)', 'commander-secure-mcp-control' ); ?></label></th>
                <td><input id="expires_days" name="expires_days" type="number" min="0" value="0" /> <span class="description"><?php esc_html_e( '0 = never', 'commander-secure-mcp-control' ); ?></span></td>
            </tr>
            <tr>
                <th><label for="ip_allowlist"><?php esc_html_e( 'IP allowlist', 'commander-secure-mcp-control' ); ?></label></th>
                <td><textarea id="ip_allowlist" name="ip_allowlist" rows="3" cols="40" placeholder="203.0.113.5&#10;198.51.100.0"></textarea>
                <p class="description"><?php esc_html_e( 'One per line. Leave empty to allow any source IP.', 'commander-secure-mcp-control' ); ?></p></td>
            </tr>
        </table>
        <?php submit_button( __( 'Issue token', 'commander-secure-mcp-control' ) ); ?>
    </form>

    <h2><?php esc_html_e( 'Existing tokens', 'commander-secure-mcp-control' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Label', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Prefix', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Scopes', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'User', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Created', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Last used', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Expires', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Status', 'commander-secure-mcp-control' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $tokens ) ) : ?>
            <tr><td colspan="9"><?php esc_html_e( 'No tokens yet.', 'commander-secure-mcp-control' ); ?></td></tr>
        <?php else : foreach ( $tokens as $t ) : ?>
            <tr>
                <td><?php echo esc_html( $t['label'] ); ?></td>
                <td><code><?php echo esc_html( $t['prefix'] ); ?>…</code></td>
                <td><?php echo esc_html( $t['scopes'] ); ?></td>
                <td><?php echo (int) $t['user_id'] ? esc_html( get_user_by( 'id', (int) $t['user_id'] )->user_login ?? '#' . (int) $t['user_id'] ) : '—'; ?></td>
                <td><?php echo esc_html( $t['created_at'] ); ?></td>
                <td><?php echo esc_html( $t['last_used_at'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $t['expires_at']  ?: '—' ); ?></td>
                <td>
                    <?php if ( $t['revoked_at'] ) : ?>
                        <span style="color:#c00">revoked</span>
                    <?php else : ?>
                        <span style="color:#080">active</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( ! $t['revoked_at'] ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Revoke this token?');">
                        <input type="hidden" name="action" value="cmcp_revoke_token" />
                        <input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>" />
                        <?php wp_nonce_field( 'cmcp_revoke_token' ); ?>
                        <button class="button button-link-delete" type="submit"><?php esc_html_e( 'Revoke', 'commander-secure-mcp-control' ); ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
