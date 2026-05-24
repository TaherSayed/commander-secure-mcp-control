<?php
/**
 * terms.create — create a new term in a taxonomy.
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class TermsCreateTool extends AbstractTool {

    public function name(): string { return 'terms.create'; }

    public function description(): string {
        return 'Create a new term (category, tag, etc.) in the given taxonomy.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'taxonomy'    => [ 'type' => 'string' ],
                'name'        => [ 'type' => 'string', 'maxLength' => 200 ],
                'slug'        => [ 'type' => 'string', 'maxLength' => 200 ],
                'description' => [ 'type' => 'string' ],
                'parent'      => [ 'type' => 'integer', 'minimum' => 0 ],
            ],
            'required'             => [ 'taxonomy', 'name' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string      { return \CMCP\Auth::SCOPE_WRITE; }
    public function required_capability(): string { return 'manage_categories'; }

    public function execute( array $args ): array {
        $tax = (string) $args['taxonomy'];
        if ( ! taxonomy_exists( $tax ) ) {
            throw new \InvalidArgumentException( esc_html( "Unknown taxonomy: {$tax}" ) );
        }
        $r = wp_insert_term(
            sanitize_text_field( (string) $args['name'] ),
            $tax,
            [
                'slug'        => isset( $args['slug'] )        ? sanitize_title( (string) $args['slug'] ) : '',
                'description' => isset( $args['description'] ) ? sanitize_textarea_field( (string) $args['description'] ) : '',
                'parent'      => (int) ( $args['parent'] ?? 0 ),
            ]
        );
        if ( is_wp_error( $r ) ) {
            throw new \RuntimeException( esc_html( $r->get_error_message() ) );
        }
        return $this->json( [ 'id' => (int) $r['term_id'], 'taxonomy' => $tax ] );
    }
}
