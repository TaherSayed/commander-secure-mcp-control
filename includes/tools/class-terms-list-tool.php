<?php
/**
 * terms.list — list taxonomy terms (categories, tags, or any registered taxonomy).
 *
 * @package WPCommander
 */

namespace CMCP\Tools;

defined( 'ABSPATH' ) || exit;

final class TermsListTool extends AbstractTool {

    public function name(): string { return 'terms.list'; }

    public function description(): string {
        return 'List terms in a taxonomy (category, post_tag, or any custom taxonomy). Returns id, name, slug, parent and count.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'taxonomy' => [ 'type' => 'string' ],
                'search'   => [ 'type' => 'string', 'maxLength' => 120 ],
                'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ],
            ],
            'required'             => [ 'taxonomy' ],
            'additionalProperties' => false,
        ];
    }

    public function required_scope(): string { return \CMCP\Auth::SCOPE_READ; }

    public function execute( array $args ): array {
        $tax = (string) $args['taxonomy'];
        if ( ! taxonomy_exists( $tax ) ) {
            throw new \InvalidArgumentException( esc_html( "Unknown taxonomy: {$tax}" ) );
        }
        $terms = get_terms( [
            'taxonomy'   => $tax,
            'hide_empty' => false,
            'number'     => (int) ( $args['per_page'] ?? 100 ),
            'search'     => isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '',
        ] );
        if ( is_wp_error( $terms ) ) {
            throw new \RuntimeException( esc_html( $terms->get_error_message() ) );
        }
        $items = array_map( static fn( $t ) => [
            'id'     => (int) $t->term_id,
            'name'   => $t->name,
            'slug'   => $t->slug,
            'parent' => (int) $t->parent,
            'count'  => (int) $t->count,
        ], $terms );
        return $this->json( [ 'taxonomy' => $tax, 'items' => $items ] );
    }
}
