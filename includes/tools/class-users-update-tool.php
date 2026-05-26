<?php
/**
 * users.update â€” update non-credential user fields. Passwords are not settable here.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class UsersUpdateTool extends AbstractTool {

    public function name(): string { return 'users_update'; }

    public function description(): string {
        return 'Update display name, email, or role of a user. Passwords cannot be set via MCP â€” the user must reset via the normal flow.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'id'           => [ 'type' => 'integer', 'minimum' => 1 ],
                'email'        => [ 'type' => 'string', 'maxLength' => 200 ],
                'display_name' => [ 'type' => 'string', 'maxLength' => 250 ],
                'role'         => [ 'type' => 'string', 'maxLength' => 60 ],
            ],
            'required'             => [ 'id' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'edit_users'; }

    public function execute( array $args ): array {
        $id = (int) $args['id'];
        $user = get_userdata( $id );
        if ( ! $user ) {
            throw new \InvalidArgumentException( 'User not found.' );
        }

        // Never let a non-super-admin edit a super admin on multisite.
        if ( is_multisite() && is_super_admin( $id ) && ! is_super_admin() ) {
            throw new \RuntimeException( 'Cannot edit a super admin.' );
        }

        $data = [ 'ID' => $id ];
        if ( isset( $args['email'] ) ) {
            $email = sanitize_email( (string) $args['email'] );
            if ( ! is_email( $email ) ) {
                throw new \InvalidArgumentException( 'Invalid email.' );
            }
            $data['user_email'] = $email;
        }
        if ( isset( $args['display_name'] ) ) {
            $data['display_name'] = sanitize_text_field( (string) $args['display_name'] );
        }
        if ( isset( $args['role'] ) ) {
            $role = sanitize_key( (string) $args['role'] );
            $editable = get_editable_roles();
            if ( ! isset( $editable[ $role ] ) ) {
                throw new \InvalidArgumentException( 'Unknown or non-editable role.' );
            }
            $data['role'] = $role;
        }

        $res = wp_update_user( $data );
        if ( is_wp_error( $res ) ) {
            throw new \RuntimeException( esc_html( $res->get_error_message() ) );
        }
        return $this->json( [ 'id' => $id, 'updated' => true ] );
    }
}
