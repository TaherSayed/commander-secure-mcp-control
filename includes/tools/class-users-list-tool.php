<?php
/**
 * users.list â€” list WordPress users (admin scope).
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class UsersListTool extends AbstractTool {

    public function name(): string { return 'users_list'; }

    public function description(): string {
        return 'List users (admin scope). Returns id, login, display name, email, roles, registered date. Sensitive data â€” handle with care.';
    }

    public function input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'role'     => [ 'type' => 'string', 'maxLength' => 60 ],
                'search'   => [ 'type' => 'string', 'maxLength' => 120 ],
                'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                'page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_ADMIN; }
    public function required_capability(): string { return 'list_users'; }

    public function execute( array $args ): array {
        $per_page = (int) ( $args['per_page'] ?? 25 );
        $page     = (int) ( $args['page']     ?? 1 );

        $q = new \WP_User_Query( [
            'role'   => isset( $args['role'] ) ? sanitize_key( (string) $args['role'] ) : '',
            'search' => isset( $args['search'] ) ? '*' . sanitize_text_field( (string) $args['search'] ) . '*' : '',
            'number' => $per_page,
            'offset' => ( $page - 1 ) * $per_page,
        ] );

        $items = [];
        foreach ( $q->get_results() as $u ) {
            $items[] = [
                'id'           => (int) $u->ID,
                'login'        => $u->user_login,
                'display_name' => $u->display_name,
                'email'        => $u->user_email,
                'roles'        => array_values( (array) $u->roles ),
                'registered'   => mysql2date( 'c', $u->user_registered, false ),
            ];
        }
        return $this->json( [
            'total' => (int) $q->get_total(),
            'items' => $items,
        ] );
    }
}
