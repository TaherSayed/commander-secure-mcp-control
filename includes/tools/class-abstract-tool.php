<?php
/**
 * Base class for MCP tools.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP\Tools;

use CMCP\Auth;

defined( 'ABSPATH' ) || exit;

abstract class AbstractTool {

    /** Unique tool name, e.g. "posts_search". Stable across versions. */
    abstract public function name(): string;

    /** Human-readable description for the LLM. */
    abstract public function description(): string;

    /** JSON Schema for the arguments object. */
    abstract public function input_schema(): array;

    /** Execute and return an MCP tools/call result. */
    abstract public function execute( array $args ): array;

    /** Minimum auth scope. */
    public function required_scope(): string {
        return Auth::SCOPE_READ;
    }

    /** WordPress capability required, or '' if no extra check needed. */
    public function required_capability(): string {
        return '';
    }

    /**
     * Validate arguments against the JSON Schema. Lightweight — only enforces
     * `required`, `type`, and `enum`. For deeper validation override this.
     */
    public function validate_arguments( array $args ): void {
        $schema = $this->input_schema();
        $required = (array) ( $schema['required'] ?? [] );
        foreach ( $required as $key ) {
            if ( ! array_key_exists( $key, $args ) ) {
                throw new \InvalidArgumentException( esc_html( "Missing required argument: {$key}" ) );
            }
        }
        $props = (array) ( $schema['properties'] ?? [] );
        foreach ( $args as $k => $v ) {
            if ( ! isset( $props[ $k ] ) ) {
                continue;
            }
            $spec = $props[ $k ];
            $type = $spec['type'] ?? null;
            if ( $type && ! self::type_matches( $type, $v ) ) {
                throw new \InvalidArgumentException( esc_html( "Argument '{$k}' must be of type {$type}." ) );
            }
            if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) && ! in_array( $v, $spec['enum'], true ) ) {
                throw new \InvalidArgumentException( esc_html( "Argument '{$k}' has invalid value." ) );
            }
            if ( isset( $spec['maxLength'] ) && is_string( $v ) && mb_strlen( $v ) > (int) $spec['maxLength'] ) {
                throw new \InvalidArgumentException( esc_html( "Argument '{$k}' exceeds max length." ) );
            }
            if ( isset( $spec['minimum'] ) && is_numeric( $v ) && $v < (float) $spec['minimum'] ) {
                throw new \InvalidArgumentException( esc_html( "Argument '{$k}' below minimum." ) );
            }
            if ( isset( $spec['maximum'] ) && is_numeric( $v ) && $v > (float) $spec['maximum'] ) {
                throw new \InvalidArgumentException( esc_html( "Argument '{$k}' above maximum." ) );
            }
        }
    }

    private static function type_matches( string $type, $value ): bool {
        return match ( $type ) {
            'string'   => is_string( $value ),
            'integer'  => is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ),
            'number'   => is_numeric( $value ),
            'boolean'  => is_bool( $value ),
            'array'    => is_array( $value ),
            'object'   => is_array( $value ),
            default    => true,
        };
    }

    /**
     * Helper: wrap a string as the standard MCP text content.
     */
    protected function text( string $text ): array {
        return [ 'content' => [ [ 'type' => 'text', 'text' => $text ] ], 'isError' => false ];
    }

    /**
     * Helper: wrap structured data as JSON text content (clients can parse).
     */
    protected function json( $data ): array {
        return [
            'content' => [ [
                'type' => 'text',
                'text' => wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
            ] ],
            'isError' => false,
        ];
    }
}
