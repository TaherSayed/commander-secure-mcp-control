<?php
/**
 * Setup wizard view.
 *
 * @var string $just_token  Set ONCE after wizard finish, otherwise empty.
 * @var string $home_host   Pre-detected current site host.
 *
 * @package WPCommander
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-template locals, scoped to include().
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only routing read after admin_post redirect.
$done = isset( $_GET['done'] );
$mcp_endpoint = rest_url( CMCP_REST_NAMESPACE . '/rpc' );
?>
<div class="wrap cmcp-wrap">
    <div class="cmcp-hero">
        <div class="cmcp-logo">
            <svg width="40" height="40" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="3" y="3" width="34" height="34" rx="8" fill="#0a3d62"/>
                <path d="M20 9 L30 14 V22 C30 26.5 25.5 30 20 31 C14.5 30 10 26.5 10 22 V14 Z" fill="#fff"/>
                <path d="M16 20 L19 23 L25 17" stroke="#0a3d62" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div>
            <h1>Commander <span class="cmcp-version">v<?php echo esc_html( CMCP_VERSION ); ?></span></h1>
            <p class="cmcp-subtitle">Secure MCP Control · <?php echo esc_html( $home_host ); ?></p>
        </div>
    </div>

<?php if ( $done ) : ?>
    <div class="cmcp-card cmcp-success">
        <h2>✓ Setup complete</h2>

        <?php if ( $just_token ) : ?>
            <p><strong>Your first token (shown ONCE — copy it now):</strong></p>
            <div class="cmcp-token-box">
                <code id="cmcp-token"><?php echo esc_html( $just_token ); ?></code>
                <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('cmcp-token').textContent);this.textContent='✓ Copied';">Copy</button>
            </div>
            <p class="description">If you lose it, just issue a new one in <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-tokens' ) ); ?>">Tokens</a>. The plaintext is never stored — only its SHA-256 hash.</p>
        <?php endif; ?>

        <h3>Now connect Claude</h3>
        <p>Pick the path that matches your setup:</p>

        <div class="cmcp-grid">
            <div class="cmcp-mini-card">
                <h4>🌐 claude.ai web</h4>
                <p>Settings → Connectors → <em>+ Add custom connector</em></p>
                <p>Server URL:</p>
                <code class="cmcp-inline-code"><?php echo esc_html( $mcp_endpoint ); ?></code>
                <p class="description">OAuth 2.1 + Dynamic Client Registration is built in — no token needed; Claude registers itself and you approve via consent screen.</p>
            </div>
            <div class="cmcp-mini-card">
                <h4>🖥️ Claude Desktop / Code</h4>
                <p>Add to <code>claude_desktop_config.json</code>:</p>
<pre class="cmcp-pre"><?php echo esc_html( "{
  \"mcpServers\": {
    \"wp-commander\": {
      \"type\": \"http\",
      \"url\": \"" . $mcp_endpoint . "\",
      \"headers\": {
        \"Authorization\": \"Bearer YOUR_TOKEN_HERE\"
      }
    }
  }
}" ); ?></pre>
            </div>
        </div>

        <p style="margin-top:24px">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp' ) ); ?>" class="button button-primary">Go to dashboard</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-tokens' ) ); ?>" class="button">Manage tokens</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-oauth' ) ); ?>" class="button">OAuth clients</a>
        </p>
    </div>
<?php else : ?>
    <div class="cmcp-card">
        <h2>Welcome — let's get you connected in 30 seconds</h2>
        <p class="lead">This wizard will set up safe defaults for <code><?php echo esc_html( $home_host ); ?></code>. Everything is opt-in — uncheck anything you don't want.</p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="cmcp_wizard_finish" />
            <?php wp_nonce_field( 'cmcp_wizard_finish' ); ?>

            <div class="cmcp-step">
                <h3>1. Create a dedicated bot user</h3>
                <label class="cmcp-check">
                    <input type="checkbox" name="create_user" value="1" checked>
                    <span><strong>Create <code>wp-commander-bot</code></strong> (administrator role)</span>
                </label>
                <p class="description">Tokens run as this user. The bot password is randomly generated and never used — only API tokens authenticate. Cleaner audit trail and clear "this action was done via Commander" attribution.</p>
            </div>

            <div class="cmcp-step">
                <h3>2. Issue your first token</h3>
                <label class="cmcp-check">
                    <input type="checkbox" name="issue_token" value="1" checked>
                    <span><strong>Issue token now</strong> (shown once on the next screen)</span>
                </label>
                <p style="margin-left:28px;margin-top:8px"><strong>Scopes:</strong></p>
                <div style="margin-left:28px">
                    <label class="cmcp-check"><input type="checkbox" name="scopes[]" value="read" checked> <code>read</code> — list, search, read content</label>
                    <label class="cmcp-check"><input type="checkbox" name="scopes[]" value="write" checked> <code>write</code> — create/edit posts, upload media, moderate comments</label>
                    <label class="cmcp-check"><input type="checkbox" name="scopes[]" value="admin"> <code>admin</code> — users, plugins, themes, settings <span style="color:#b26a00">(careful)</span></label>
                </div>
            </div>

            <div class="cmcp-step">
                <h3>3. Allow this site's origin</h3>
                <label class="cmcp-check">
                    <input type="checkbox" name="add_origin" value="1" checked>
                    <span>Add <code><?php echo esc_html( ( wp_parse_url( home_url(), PHP_URL_SCHEME ) ) . '://' . $home_host ); ?></code> to allowed origins</span>
                </label>
                <p class="description">Required for OAuth redirects from <em>this</em> WordPress site. Claude.ai's own origin is automatically accepted when its tokens are used.</p>
            </div>

            <div class="cmcp-actions">
                <button type="submit" class="button button-primary button-hero">Finish setup →</button>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmcp_wizard_dismiss' ), 'cmcp_wizard_dismiss' ) ); ?>" class="button">Skip — I'll configure manually</a>
            </div>
        </form>
    </div>

    <div class="cmcp-card cmcp-info">
        <h3>What Commander does</h3>
        <ul class="cmcp-features">
            <li>🔌 <strong>JSON-RPC 2.0 / Streamable HTTP</strong> — MCP 2025-06-18 spec</li>
            <li>🔐 <strong>OAuth 2.1 + PKCE + Dynamic Client Registration</strong> — claude.ai web works out of the box</li>
            <li>🪙 <strong>Personal access tokens</strong> — for Claude Desktop / CLI / curl</li>
            <li>🛡️ <strong>Brute-force protection</strong> — progressive lockout after failed auth</li>
            <li>📊 <strong>Audit log & live stats</strong> — every call recorded with token id, IP, tool, status</li>
            <li>🚫 <strong>Bad-UA blocking</strong> — refuses common scanners (sqlmap, nikto, wpscan…)</li>
            <li>🔧 <strong>24 tools</strong> — posts, media, comments, users, plugins, themes, settings, site health</li>
            <li>🗑️ <strong>Destructive ops interlock</strong> — permanent deletes require explicit admin opt-in</li>
        </ul>
    </div>
<?php endif; ?>

    <p class="cmcp-credit">Commander · Secure MCP Control · <a href="https://hbs-it-gmbh.de" target="_blank" rel="noopener">HBS IT GmbH</a> · Built by Taher Sayed</p>
</div>
