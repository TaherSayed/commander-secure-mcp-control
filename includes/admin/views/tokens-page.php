<?php
/**
 * Tokens admin page.
 *
 * @var array  $tokens
 * @var string $just_token  Plaintext token to display ONCE after creation/rotation.
 * @var string $notice      Notice key for the admin redirect banner.
 * @var string $test_nonce  Nonce for the Test-connection AJAX call.
 * @var string $ajax_url    admin-ajax.php URL.
 *
 * @package WPCommander
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-template locals, scoped to include().

$status_labels = [
    'active'  => __( 'Active',  'mcp-for-claude' ),
    'idle'    => __( 'Idle',    'mcp-for-claude' ),
    'stale'   => __( 'Stale',   'mcp-for-claude' ),
    'expired' => __( 'Expired', 'mcp-for-claude' ),
    'revoked' => __( 'Revoked', 'mcp-for-claude' ),
];
$status_hints = [
    'active'  => __( 'Used in the last 30 days.',         'mcp-for-claude' ),
    'idle'    => __( 'Never used yet.',                   'mcp-for-claude' ),
    'stale'   => __( 'No activity in over 30 days.',      'mcp-for-claude' ),
    'expired' => __( 'Past its expiry date.',             'mcp-for-claude' ),
    'revoked' => __( 'Revoked — cannot authenticate.',    'mcp-for-claude' ),
];

$bot_user = get_user_by( 'login', 'wp-commander-bot' );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Commander — Tokens', 'mcp-for-claude' ); ?></h1>

    <?php if ( $bot_user ) : ?>
        <div class="notice notice-info inline" style="margin:10px 0;padding:10px 14px">
            <p style="margin:0">
                🤖 <strong><?php esc_html_e( 'Service account:', 'mcp-for-claude' ); ?></strong>
                <code><?php echo esc_html( $bot_user->user_login ); ?></code>
                <span style="color:#646970"><?php echo esc_html( ' · #' . (int) $bot_user->ID . ' · ' . implode( ', ', (array) $bot_user->roles ) ); ?></span>
                · <a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $bot_user->ID ) ); ?>"><?php esc_html_e( 'Edit user', 'mcp-for-claude' ); ?></a>
            </p>
            <p class="description" style="margin:4px 0 0">
                <?php esc_html_e( 'Auto-created by the setup wizard. Bind your read-only / monitoring tokens to this account so they execute with administrator capabilities without exposing a real human user.', 'mcp-for-claude' ); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( $notice === 'revoked' ) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Token revoked.', 'mcp-for-claude' ); ?></p></div>
    <?php elseif ( $notice === 'deleted' ) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Token permanently deleted.', 'mcp-for-claude' ); ?></p></div>
    <?php elseif ( $notice === 'rotated' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Token rotated — the old one is revoked and a new one was issued below.', 'mcp-for-claude' ); ?></p></div>
    <?php elseif ( $notice === 'rotate_failed' ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not rotate the token.', 'mcp-for-claude' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $just_token ) : ?>
        <div class="notice notice-success">
            <p>
                <strong><?php esc_html_e( 'Token created. Copy it now — it will not be shown again:', 'mcp-for-claude' ); ?></strong>
            </p>
            <div class="cmcp-token-box">
                <code id="cmcp-new-token"><?php echo esc_html( $just_token ); ?></code>
                <button type="button" class="button cmcp-copy" data-target="#cmcp-new-token"><?php esc_html_e( 'Copy', 'mcp-for-claude' ); ?></button>
                <button type="button" class="button cmcp-test-new" data-token="<?php echo esc_attr( $just_token ); ?>"><?php esc_html_e( 'Test now', 'mcp-for-claude' ); ?></button>
            </div>
            <p class="cmcp-test-result" style="margin:0;color:#646970;font-size:12px"></p>

            <?php
            $rpc_url     = rest_url( CMCP_REST_NAMESPACE . '/rpc' );
            $snippet_id  = 'cmcp-new-snip';
            include CMCP_DIR . 'includes/admin/views/partials/token-snippets.php';
            ?>
        </div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Issue new token', 'mcp-for-claude' ); ?></h2>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="cmcp_create_token" />
        <?php wp_nonce_field( 'cmcp_create_token' ); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="label"><?php esc_html_e( 'Label', 'mcp-for-claude' ); ?></label></th>
                <td><input id="label" name="label" type="text" class="regular-text" required maxlength="120" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Scopes', 'mcp-for-claude' ); ?></th>
                <td>
                    <label><input type="checkbox" name="scopes[]" value="read" checked /> read</label>
                    <label><input type="checkbox" name="scopes[]" value="write" /> write</label>
                    <label><input type="checkbox" name="scopes[]" value="admin" /> admin</label>
                </td>
            </tr>
            <tr>
                <th><label for="user_id"><?php esc_html_e( 'Run as WP user', 'mcp-for-claude' ); ?> <span style="color:#b32d2e">*</span></label></th>
                <td>
                    <select id="cmcp-user-select" style="min-width:280px">
                        <?php foreach ( (array) $suggested_users as $u ) :
                            $u_id  = (int) $u->ID;
                            $roles = get_userdata( $u_id )->roles ?? [];
                            $tag   = '';
                            if ( in_array( 'administrator', $roles, true ) ) {
                                $tag = ' — administrator';
                            } elseif ( in_array( 'editor', $roles, true ) ) {
                                $tag = ' — editor';
                            } elseif ( in_array( 'author', $roles, true ) ) {
                                $tag = ' — author';
                            }
                        ?>
                            <option value="<?php echo (int) $u_id; ?>" <?php selected( $u_id, $current_admin_id ); ?>>
                                #<?php echo (int) $u_id; ?> — <?php echo esc_html( $u->user_login ); ?><?php echo esc_html( $tag ); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="-1"><?php esc_html_e( 'Other — enter ID manually…', 'mcp-for-claude' ); ?></option>
                        <option value="0" style="color:#b32d2e"><?php esc_html_e( '0 — anonymous (only public/read tools)', 'mcp-for-claude' ); ?></option>
                    </select>
                    <input id="user_id" name="user_id" type="number" min="0" value="<?php echo (int) $current_admin_id; ?>" style="width:90px;margin-left:8px" />
                    <p class="description"><?php esc_html_e( "Token executes with this user's WordPress capabilities. Admin-scope tools (plugins / users / settings) ALL require the bound user to actually have those WP capabilities — so pick an Administrator unless you specifically want a limited token.", 'mcp-for-claude' ); ?></p>
                    <p id="cmcp-user-warning" class="notice notice-warning inline" style="display:none;padding:8px 12px;margin:6px 0 0;border-left-width:4px">
                        ⚠ <strong><?php esc_html_e( 'This token will have no WordPress user.', 'mcp-for-claude' ); ?></strong>
                        <?php esc_html_e( 'Every capability-gated tool will fail. Only use this for testing the auth handshake itself.', 'mcp-for-claude' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="expires_days"><?php esc_html_e( 'Expires in (days)', 'mcp-for-claude' ); ?></label></th>
                <td><input id="expires_days" name="expires_days" type="number" min="0" value="0" /> <span class="description"><?php esc_html_e( '0 = never', 'mcp-for-claude' ); ?></span></td>
            </tr>
            <tr>
                <th><label for="ip_allowlist"><?php esc_html_e( 'IP allowlist', 'mcp-for-claude' ); ?></label></th>
                <td><textarea id="ip_allowlist" name="ip_allowlist" rows="3" cols="40" placeholder="203.0.113.5&#10;198.51.100.0"></textarea>
                <p class="description"><?php esc_html_e( 'One per line. Leave empty to allow any source IP.', 'mcp-for-claude' ); ?></p></td>
            </tr>
        </table>
        <?php submit_button( __( 'Issue token', 'mcp-for-claude' ) ); ?>
    </form>

    <h2><?php esc_html_e( 'Existing tokens', 'mcp-for-claude' ); ?></h2>
    <table class="widefat striped cmcp-tokens-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Label',     'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Status',    'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Prefix',    'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Scopes',    'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'User',      'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Last used', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Last IP',   'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( '7-day calls', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Expires',   'mcp-for-claude' ); ?></th>
                <th style="width:280px"><?php esc_html_e( 'Actions', 'mcp-for-claude' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $tokens ) ) : ?>
            <tr><td colspan="10"><?php esc_html_e( 'No tokens yet.', 'mcp-for-claude' ); ?></td></tr>
        <?php else : foreach ( $tokens as $t ) :
            $status = \CMCP\Auth::token_status( $t );
            $is_active_status = ! in_array( $status, [ 'revoked', 'expired' ], true );
            $user_disp = (int) $t['user_id']
                ? esc_html( get_user_by( 'id', (int) $t['user_id'] )->user_login ?? '#' . (int) $t['user_id'] )
                : '<span style="color:#a7aaad">—</span>';
        ?>
            <tr data-token-id="<?php echo (int) $t['id']; ?>" data-prefix="<?php echo esc_attr( $t['prefix'] ); ?>">
                <td><strong><?php echo esc_html( $t['label'] ); ?></strong><br><span style="color:#a7aaad;font-size:11px">#<?php echo (int) $t['id']; ?> · <?php echo esc_html( $t['created_at'] ); ?></span></td>
                <td>
                    <span class="cmcp-status cmcp-status-<?php echo esc_attr( $status ); ?>" title="<?php echo esc_attr( $status_hints[ $status ] ?? '' ); ?>">
                        <span class="cmcp-status-dot"></span><?php echo esc_html( $status_labels[ $status ] ?? $status ); ?>
                    </span>
                </td>
                <td><code><?php echo esc_html( $t['prefix'] ); ?>…</code></td>
                <td>
                    <?php foreach ( array_filter( array_map( 'trim', explode( ',', (string) $t['scopes'] ) ) ) as $sc ) : ?>
                        <span class="cmcp-badge <?php echo esc_attr( $sc ); ?>"><?php echo esc_html( $sc ); ?></span>
                    <?php endforeach; ?>
                </td>
                <td><?php echo wp_kses_post( $user_disp ); ?></td>
                <td><?php echo $t['last_used_at'] ? esc_html( $t['last_used_at'] ) : '<span style="color:#a7aaad">—</span>'; ?></td>
                <td><?php echo ! empty( $t['last_ip'] ) ? '<code style="font-size:11px">' . esc_html( (string) $t['last_ip'] ) . '</code>' : '<span style="color:#a7aaad">—</span>'; ?></td>
                <td>
                    <?php
                    $series = \CMCP\Auth::daily_calls( (int) $t['id'] );
                    $total  = (int) ( $t['calls_7d'] ?? array_sum( array_column( $series, 'n' ) ) );
                    $max    = max( 1, max( array_column( $series, 'n' ) ) );
                    $w = 84; $h = 22; $pad = 1; $step = ( $w - $pad * 2 ) / 6;
                    $pts = [];
                    foreach ( $series as $i => $d ) {
                        $x = $pad + $i * $step;
                        $y = $h - $pad - ( (int) $d['n'] / $max ) * ( $h - $pad * 2 );
                        $pts[] = sprintf( '%.1f,%.1f', $x, $y );
                    }
                    $title = '';
                    foreach ( $series as $d ) {
                        $title .= $d['date'] . ': ' . (int) $d['n'] . "\n";
                    }
                    ?>
                    <span class="cmcp-sparkline-wrap" title="<?php echo esc_attr( trim( $title ) ); ?>">
                        <svg class="cmcp-sparkline" width="<?php echo (int) $w; ?>" height="<?php echo (int) $h; ?>" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <polyline points="<?php echo esc_attr( implode( ' ', $pts ) ); ?>" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
                            <?php foreach ( $series as $i => $d ) : if ( (int) $d['n'] === 0 ) continue; ?>
                                <circle cx="<?php echo (float) ( $pad + $i * $step ); ?>" cy="<?php echo (float) ( $h - $pad - ( (int) $d['n'] / $max ) * ( $h - $pad * 2 ) ); ?>" r="1.5" fill="currentColor" />
                            <?php endforeach; ?>
                        </svg>
                        <span class="cmcp-sparkline-total"><?php echo (int) $total; ?></span>
                    </span>
                </td>
                <td><?php echo $t['expires_at']  ? esc_html( $t['expires_at'] )  : '<span style="color:#a7aaad">' . esc_html__( 'never', 'mcp-for-claude' ) . '</span>'; ?></td>
                <td class="cmcp-row-actions">
                    <?php if ( $is_active_status ) : ?>
                        <button type="button" class="button button-small cmcp-test-row" data-token-id="<?php echo (int) $t['id']; ?>" disabled title="<?php esc_attr_e( 'Live testing of existing tokens is disabled (the plaintext is not stored on the server). Issue a new token or rotate to test.', 'mcp-for-claude' ); ?>"><?php esc_html_e( 'Test', 'mcp-for-claude' ); ?></button>
                    <?php endif; ?>

                    <?php if ( $is_active_status ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Rotate this token? The old one will be revoked and a new plaintext will be shown.', 'mcp-for-claude' ) ); ?>');">
                        <input type="hidden" name="action"   value="cmcp_rotate_token" />
                        <input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>" />
                        <?php wp_nonce_field( 'cmcp_rotate_token' ); ?>
                        <button class="button button-small" type="submit"><?php esc_html_e( 'Rotate', 'mcp-for-claude' ); ?></button>
                    </form>
                    <?php endif; ?>

                    <?php if ( $is_active_status ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this token? It can no longer authenticate, but the audit row stays.', 'mcp-for-claude' ) ); ?>');">
                        <input type="hidden" name="action"   value="cmcp_revoke_token" />
                        <input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>" />
                        <?php wp_nonce_field( 'cmcp_revoke_token' ); ?>
                        <button class="button button-small" type="submit"><?php esc_html_e( 'Revoke', 'mcp-for-claude' ); ?></button>
                    </form>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this token row? This action cannot be undone.', 'mcp-for-claude' ) ); ?>');">
                        <input type="hidden" name="action"   value="cmcp_delete_token" />
                        <input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>" />
                        <?php wp_nonce_field( 'cmcp_delete_token' ); ?>
                        <button class="button button-small button-link-delete" type="submit"><?php esc_html_e( 'Delete', 'mcp-for-claude' ); ?></button>
                    </form>
                </td>
            </tr>
            <tr class="cmcp-snippets-row">
                <td colspan="10" style="padding:0">
                    <?php
                    $rpc_url    = rest_url( CMCP_REST_NAMESPACE . '/rpc' );
                    $token_view = '<YOUR_TOKEN>'; // never stored plaintext server-side
                    $snippet_id = 'cmcp-snip-' . (int) $t['id'];
                    include CMCP_DIR . 'includes/admin/views/partials/token-snippets.php';
                    ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
( function () {
    var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
    var nonce   = <?php echo wp_json_encode( $test_nonce ); ?>;

    // Generic copy-to-clipboard for any [data-target] button.
    document.querySelectorAll( '.cmcp-copy' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            var sel = btn.getAttribute( 'data-target' );
            var el  = sel ? document.querySelector( sel ) : null;
            var text = el ? el.textContent : '';
            if ( ! text ) { return; }
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText( text ).then( function () {
                    var original = btn.textContent;
                    btn.textContent = '✓ <?php echo esc_js( __( 'Copied', 'mcp-for-claude' ) ); ?>';
                    setTimeout( function () { btn.textContent = original; }, 1500 );
                } );
            } else {
                // Fallback: select text.
                var range = document.createRange();
                range.selectNode( el );
                window.getSelection().removeAllRanges();
                window.getSelection().addRange( range );
            }
        } );
    } );

    // "Bind to WP user" picker — sync dropdown with the number input,
    // and show a big warning if user_id ends up as 0.
    var sel  = document.getElementById( 'cmcp-user-select' );
    var inp  = document.getElementById( 'user_id' );
    var warn = document.getElementById( 'cmcp-user-warning' );
    function syncWarn() {
        if ( ! warn ) { return; }
        warn.style.display = ( parseInt( inp.value, 10 ) === 0 ) ? 'block' : 'none';
    }
    if ( sel && inp ) {
        // Hide manual input by default; show only when "Other" is picked.
        inp.style.display = 'none';
        sel.addEventListener( 'change', function () {
            var v = sel.value;
            if ( v === '-1' ) {
                inp.style.display = '';
                inp.value = '';
                inp.focus();
            } else {
                inp.style.display = 'none';
                inp.value = v;
            }
            syncWarn();
        } );
        inp.addEventListener( 'input', syncWarn );
        syncWarn();
    }

    // Tab switching for snippet panels.
    document.querySelectorAll( '.cmcp-tabs' ).forEach( function ( bar ) {
        var panels = bar.parentElement.querySelectorAll( '.cmcp-tab-panel' );
        bar.querySelectorAll( '.cmcp-tab' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                bar.querySelectorAll( '.cmcp-tab' ).forEach( function ( b ) { b.classList.remove( 'active' ); } );
                btn.classList.add( 'active' );
                panels.forEach( function ( p ) {
                    p.classList.toggle( 'active', p.getAttribute( 'data-tab' ) === btn.getAttribute( 'data-tab' ) );
                } );
            } );
        } );
    } );

    // Test-connection on a freshly-shown plaintext token.
    document.querySelectorAll( '.cmcp-test-new' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            var token  = btn.getAttribute( 'data-token' );
            var result = btn.closest( '.notice' ).querySelector( '.cmcp-test-result' );
            if ( ! token || ! result ) { return; }
            result.textContent = '<?php echo esc_js( __( 'Testing…', 'mcp-for-claude' ) ); ?>';
            result.style.color = '#646970';
            var data = new URLSearchParams();
            data.set( 'action',  'cmcp_test_token' );
            data.set( '_nonce',  nonce );
            data.set( 'token',   token );
            fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( resp ) {
                    var d  = ( resp && resp.data ) || {};
                    var ok = resp && resp.success && d.ok;
                    result.textContent = d.message || ( ok ? 'OK' : 'Failed' );
                    result.style.color = ok ? '#0a6041' : '#9b1c1c';
                } )
                .catch( function ( err ) {
                    result.textContent = 'Error: ' + err;
                    result.style.color = '#9b1c1c';
                } );
        } );
    } );
} )();
</script>
