<?php
/**
 * Activation wizard. Shown once after the plugin is first activated.
 * One screen, opt-in actions: create bot user, issue first token, confirm origins.
 *
 * @package WPCommander
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class Wizard {

    public static function init(): void {
        add_action( 'admin_notices',                           [ self::class, 'maybe_show_notice' ] );
        add_action( 'admin_post_cmcp_wizard_finish',           [ self::class, 'handle_finish' ] );
        add_action( 'admin_post_cmcp_wizard_dismiss',          [ self::class, 'handle_dismiss' ] );
    }

    /** Returns true if the wizard should be shown right now. */
    public static function should_show(): bool {
        return (bool) get_transient( 'cmcp_show_wizard' );
    }

    /** A small one-line notice on every admin page, until dismissed. */
    public static function maybe_show_notice(): void {
        if ( ! self::should_show() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Don't double up on the wizard page itself.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only page routing check; no state mutation.
        if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'cmcp-wizard' ) {
            return;
        }
        $url     = admin_url( 'admin.php?page=cmcp-wizard' );
        $dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=cmcp_wizard_dismiss' ), 'cmcp_wizard_dismiss' );
        ?>
        <div class="notice notice-info" style="border-left-color:#2271b1">
            <p style="font-size:14px">
                🛡️ <strong>Commander</strong> is installed. Run the
                <a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="margin-left:6px"><?php esc_html_e( '30-second setup', 'commander-secure-mcp-control' ); ?></a>
                — auto-creates a dedicated bot user and your first token, or
                <a href="<?php echo esc_url( $dismiss ); ?>" style="margin-left:4px"><?php esc_html_e( 'skip', 'commander-secure-mcp-control' ); ?></a>.
                <span style="color:#646970;font-size:12px;float:right">powered by Taher Sayed · HBS IT GmbH</span>
            </p>
        </div>
        <?php
    }

    /** Render the wizard page. */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $just_token = get_transient( 'cmcp_wizard_token' );
        if ( $just_token ) {
            delete_transient( 'cmcp_wizard_token' );
        }
        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        include CMCP_DIR . 'includes/admin/views/wizard-page.php';
    }

    /** Run the wizard actions and redirect back with token to display once. */
    public static function handle_finish(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'cmcp_wizard_finish' );

        // Nonce verified by check_admin_referer() above.
        $create_user = ! empty( $_POST['create_user'] );
        $issue_token = ! empty( $_POST['issue_token'] );
        $add_origin  = ! empty( $_POST['add_origin'] );
        $raw_scopes  = isset( $_POST['scopes'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['scopes'] ) ) : [ 'read' ];
        $scopes      = array_intersect( Auth::SCOPES, $raw_scopes );

        $user_id = 0;
        if ( $create_user ) {
            $user_id = self::create_bot_user();
        }

        if ( $add_origin ) {
            $settings = Plugin::get_settings();
            $home     = home_url();
            $scheme   = wp_parse_url( $home, PHP_URL_SCHEME );
            $host     = wp_parse_url( $home, PHP_URL_HOST );
            $origin   = $scheme . '://' . $host;
            if ( ! in_array( $origin, (array) ( $settings['allowed_origins'] ?? [] ), true ) ) {
                $settings['allowed_origins'][] = $origin;
                update_option( Plugin::OPT_SETTINGS, $settings );
            }
        }

        if ( $issue_token ) {
            $res = Auth::issue_token( [
                'label'      => 'Setup wizard (' . gmdate( 'Y-m-d' ) . ')',
                'scopes'     => array_values( $scopes ) ?: [ 'read' ],
                'user_id'    => $user_id,
                'expires_in' => 0,
            ] );
            set_transient( 'cmcp_wizard_token', $res['token'], 5 * MINUTE_IN_SECONDS );
        }

        delete_transient( 'cmcp_show_wizard' );
        wp_safe_redirect( admin_url( 'admin.php?page=cmcp-wizard&done=1' ) );
        exit;
    }

    public static function handle_dismiss(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'cmcp_wizard_dismiss' );
        delete_transient( 'cmcp_show_wizard' );
        wp_safe_redirect( admin_url( 'admin.php?page=cmcp' ) );
        exit;
    }

    /**
     * Create the `wp-commander-bot` user if it doesn't exist.
     * Uses an administrator role so the bot can do everything — but tokens
     * issued to it still go through MCP scopes AND the WP capability checks.
     */
    private static function create_bot_user(): int {
        $login = 'wp-commander-bot';
        $u     = get_user_by( 'login', $login );
        if ( $u ) {
            return (int) $u->ID;
        }
        $host  = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'example.com';
        $email = 'bot+' . wp_generate_password( 8, false, false ) . '@' . $host;

        // If somehow taken, retry with new suffix.
        while ( email_exists( $email ) ) {
            $email = 'bot+' . wp_generate_password( 8, false, false ) . '@' . $host;
        }
        $pass = wp_generate_password( 32, true, true ); // never used; tokens auth
        $id = wp_insert_user( [
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $pass,
            'display_name' => 'Commander Bot',
            'role'         => 'administrator',
            'description'  => 'Service account for Commander MCP. Created by the setup wizard. Do not log in as this user — all access is via API tokens.',
        ] );
        if ( is_wp_error( $id ) ) {
            return 0;
        }
        // Tag the user so the admin knows it's managed.
        update_user_meta( $id, '_cmcp_bot', 1 );
        return (int) $id;
    }
}
