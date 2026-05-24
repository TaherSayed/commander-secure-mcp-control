<?php
/**
 * users.create — create a new WordPress user.
 *
 * A random password is generated server-side; clients cannot supply one.
 * The admin can choose to email the new user via send_email=true.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class UsersCreateTool extends AbstractTool {

    public function name(): string { return 'users.create'; }

    public function description(): string {
        return 'Create a new WordPress user with a server-generated password. Role must be one of the registered editable roles. Clients cannot set passwords directly.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'username'     => [ 'type' => 'string', 'maxLength' => 60 ],
                'email'        => [ 'type' => 'string', 'maxLength' => 200 ],
                'display_name' => [ 'type' => 'string', 'maxLength' => 250 ],
                'role'         => [ 'type' => 'string', 'maxLength' => 60 ],
                'send_email'   => [ 'type' => 'boolean' ],
            ],
            'required'             => [ 'username', 'email' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'create_users'; }

    public function execute( array $args ): array {
        $login = sanitize_user( (string) $args['username'], true );
        $email = sanitize_email( (string) $args['email'] );
        if ( ! $login || ! is_email( $email ) ) {
            throw new \InvalidArgumentException( 'Invalid username or email.' );
        }
        if ( username_exists( $login ) || email_exists( $email ) ) {
            throw new \RuntimeException( 'Username or email already in use.' );
        }

        $password = wp_generate_password( 24, true, true );
        $user_id  = wp_insert_user( [
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => sanitize_text_field( (string) ( $args['display_name'] ?? $login ) ),
            'role'         => $this->safe_role( (string) ( $args['role'] ?? get_option( 'default_role', 'subscriber' ) ) ),
        ] );
        if ( is_wp_error( $user_id ) ) {
            throw new \RuntimeException( esc_html( $user_id->get_error_message() ) );
        }

        if ( ! empty( $args['send_email'] ) ) {
            wp_send_new_user_notifications( $user_id, 'user' );
        }

        return $this->json( [
            'id'              => (int) $user_id,
            'login'           => $login,
            'one_time_password' => $password, // Shown ONCE — log it offline.
            'note'            => 'Store the password securely. It will not be available again.',
        ] );
    }

    private function safe_role( string $role ): string {
        $role = sanitize_key( $role );
        $editable = get_editable_roles();
        // Don't let an admin-scope token create more administrators unless current user can.
        if ( $role === 'administrator' && ! current_user_can( 'create_users' ) ) {
            throw new \RuntimeException( 'Cannot create administrator accounts.' );
        }
        if ( ! isset( $editable[ $role ] ) ) {
            $role = get_option( 'default_role', 'subscriber' );
        }
        return $role;
    }
}
