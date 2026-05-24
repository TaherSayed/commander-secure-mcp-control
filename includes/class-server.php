<?php
/**
 * MCP Server — JSON-RPC 2.0 dispatcher for the Streamable HTTP transport.
 *
 * Implements the methods required by MCP 2025-06-18:
 *   - initialize / notifications/initialized
 *   - ping
 *   - tools/list, tools/call
 *   - resources/list, resources/read
 *   - prompts/list (empty here)
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class Server {

    /** JSON-RPC error codes (per spec / JSON-RPC 2.0). */
    public const ERR_PARSE          = -32700;
    public const ERR_INVALID_REQ    = -32600;
    public const ERR_METHOD_MISSING = -32601;
    public const ERR_INVALID_PARAMS = -32602;
    public const ERR_INTERNAL       = -32603;

    public static function register_routes(): void {
        register_rest_route(
            CMCP_REST_NAMESPACE,
            '/rpc',
            [
                'methods'             => [ \WP_REST_Server::CREATABLE ],  // POST
                'callback'            => [ self::class, 'handle' ],
                'permission_callback' => '__return_true', // gate ourselves
            ]
        );
    }

    /**
     * Public discovery so clients can find the MCP endpoint without guessing.
     * Returns: { name, version, endpoint, protocolVersion }
     */
    public static function register_discovery(): void {
        register_rest_route(
            CMCP_REST_NAMESPACE,
            '/discovery',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'permission_callback' => '__return_true',
                'callback'            => function () {
                    $resp = new \WP_REST_Response( [
                        'name'            => 'Commander @ ' . ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: get_bloginfo( 'name' ) ),
                        'vendor'          => 'Taher Sayed · HBS IT GmbH',
                        'version'         => CMCP_VERSION,
                        'protocolVersion' => CMCP_PROTOCOL_VERSION,
                        'endpoint'        => rest_url( CMCP_REST_NAMESPACE . '/rpc' ),
                        'auth'            => [
                            'type'                       => 'bearer',
                            'oauth2'                     => [
                                'authorization_endpoint'  => rest_url( CMCP_REST_NAMESPACE . '/oauth/authorize' ),
                                'token_endpoint'          => rest_url( CMCP_REST_NAMESPACE . '/oauth/token' ),
                                'registration_endpoint'   => rest_url( CMCP_REST_NAMESPACE . '/oauth/register' ),
                                'as_metadata'             => home_url( '/.well-known/oauth-authorization-server' ),
                                'pr_metadata'             => home_url( '/.well-known/oauth-protected-resource' ),
                            ],
                        ],
                    ] );
                    return Security::harden_response( $resp );
                },
            ]
        );
    }

    /**
     * Top-level handler. Runs security → auth → rate limit → dispatch.
     */
    public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
        // Transport-level checks first.
        if ( $err = Security::validate_request( $request ) ) {
            return self::wp_err_response( $err );
        }

        // Authentication.
        $auth = Auth::authenticate( $request );
        if ( empty( $auth['ok'] ) ) {
            $err = $auth['error'] ?? new \WP_Error( 'cmcp_unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
            Logger::log( [
                'method'      => '(pre-auth)',
                'success'     => 0,
                'status_code' => (int) ( $err->get_error_data()['status'] ?? 401 ),
                'note'        => $err->get_error_code(),
            ] );
            $resp = self::wp_err_response( $err );
            // Per MCP spec + RFC 9728: point clients at the protected-resource metadata.
            $resource_meta = home_url( '/.well-known/oauth-protected-resource' );
            $resp->header( 'WWW-Authenticate', 'Bearer realm="MCP", resource_metadata="' . $resource_meta . '"' );
            return $resp;
        }

        // Rate limit.
        $settings = Plugin::get_settings();
        [ $ok, $remaining, $reset ] = RateLimiter::check( (int) $auth['token_id'], (int) $settings['rate_limit_per_min'] );
        if ( ! $ok ) {
            Logger::log( [
                'token_id'    => $auth['token_id'],
                'method'      => '(rate-limit)',
                'success'     => 0,
                'status_code' => 429,
            ] );
            $resp = self::wp_err_response( new \WP_Error( 'cmcp_rate_limited', 'Rate limit exceeded.', [ 'status' => 429 ] ) );
            $resp->header( 'Retry-After', (string) $reset );
            return $resp;
        }

        // Parse JSON-RPC body.
        $body = (string) $request->get_body();
        if ( $body === '' ) {
            return self::jsonrpc_error_response( null, self::ERR_INVALID_REQ, 'Empty request body.', 400 );
        }
        try {
            $data = json_decode( $body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
        } catch ( \JsonException $e ) {
            return self::jsonrpc_error_response( null, self::ERR_PARSE, 'Parse error.', 400 );
        }

        // Batch or single message?
        if ( ! is_array( $data ) || ( isset( $data[0] ) && is_array( $data[0] ) ) ) {
            // Per spec 2025-06-18 batch is removed; reject batches cleanly.
            return self::jsonrpc_error_response( null, self::ERR_INVALID_REQ, 'Batch requests are not supported.', 400 );
        }

        $result = self::dispatch( $data, $auth );

        // Notifications (no id) get a 202 with empty body.
        if ( ! array_key_exists( 'id', $data ) ) {
            $resp = new \WP_REST_Response( null, 202 );
            return Security::harden_response( $resp );
        }

        $resp = new \WP_REST_Response( $result, 200 );
        $resp->header( 'X-RateLimit-Remaining', (string) $remaining );
        $resp->header( 'X-RateLimit-Reset',     (string) $reset );
        return Security::harden_response( $resp );
    }

    /**
     * Dispatch one parsed JSON-RPC message.
     */
    private static function dispatch( array $msg, array $auth ): array {
        $id     = $msg['id']     ?? null;
        $method = $msg['method'] ?? '';
        $params = is_array( $msg['params'] ?? null ) ? $msg['params'] : [];

        if ( ! is_string( $method ) || $method === '' ) {
            return self::rpc_error( $id, self::ERR_INVALID_REQ, 'Missing method.' );
        }

        try {
            switch ( $method ) {
                case 'initialize':
                    return self::rpc_ok( $id, self::handle_initialize( $params ) );

                case 'notifications/initialized':
                case 'notifications/cancelled':
                case 'notifications/progress':
                case 'notifications/roots/list_changed':
                    // Notifications: nothing to return.
                    Logger::log( [
                        'token_id' => $auth['token_id'],
                        'method'   => $method,
                        'success'  => 1,
                        'status_code' => 202,
                    ] );
                    return [];

                case 'ping':
                    return self::rpc_ok( $id, (object) [] );

                case 'tools/list':
                    self::require_scope( $auth, Auth::SCOPE_READ );
                    return self::rpc_ok( $id, [ 'tools' => ToolRegistry::instance()->list_for_client() ] );

                case 'tools/call':
                    self::require_scope( $auth, Auth::SCOPE_READ );
                    return self::rpc_ok( $id, self::handle_tool_call( $params, $auth ) );

                case 'resources/list':
                    self::require_scope( $auth, Auth::SCOPE_READ );
                    return self::rpc_ok( $id, [ 'resources' => ToolRegistry::instance()->list_resources() ] );

                case 'resources/read':
                    self::require_scope( $auth, Auth::SCOPE_READ );
                    return self::rpc_ok( $id, ToolRegistry::instance()->read_resource( (string) ( $params['uri'] ?? '' ) ) );

                case 'prompts/list':
                    return self::rpc_ok( $id, [ 'prompts' => [] ] );

                default:
                    return self::rpc_error(
                        $id,
                        self::ERR_METHOD_MISSING,
                        /* translators: %s: JSON-RPC method name */
                        sprintf( __( 'Method not found: %s', 'commander-secure-mcp-control' ), $method )
                    );
            }
        } catch ( \InvalidArgumentException $e ) {
            return self::rpc_error( $id, self::ERR_INVALID_PARAMS, $e->getMessage() );
        } catch ( \RuntimeException $e ) {
            // Tool reported a controlled error.
            Logger::log( [
                'token_id'    => $auth['token_id'],
                'method'      => $method,
                'success'     => 0,
                'status_code' => 200, // JSON-RPC errors are HTTP 200
                'note'        => 'tool error: ' . $e->getMessage(),
            ] );
            return self::rpc_error( $id, self::ERR_INTERNAL, $e->getMessage() );
        } catch ( \Throwable $e ) {
            // Unexpected — keep the message generic.
            Logger::log( [
                'token_id'    => $auth['token_id'],
                'method'      => $method,
                'success'     => 0,
                'status_code' => 200,
                'note'        => 'exception: ' . get_class( $e ),
            ] );
            return self::rpc_error( $id, self::ERR_INTERNAL, __( 'Internal error.', 'commander-secure-mcp-control' ) );
        }
    }

    private static function handle_initialize( array $params ): array {
        $client_proto = (string) ( $params['protocolVersion'] ?? '' );
        // Accept any client version; respond with ours.
        return [
            'protocolVersion' => CMCP_PROTOCOL_VERSION,
            'serverInfo'      => [
                'name'    => 'Commander @ ' . ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: get_bloginfo( 'name' ) ),
                'version' => CMCP_VERSION,
                'vendor'  => 'Taher Sayed · HBS IT GmbH',
            ],
            'capabilities'    => [
                'tools'     => [ 'listChanged' => false ],
                'resources' => [ 'listChanged' => false, 'subscribe' => false ],
                'prompts'   => [ 'listChanged' => false ],
                'logging'   => (object) [],
            ],
            'instructions' => sprintf(
                'Commander MCP server for %s. Every tool call is gated by MCP scope, a WordPress capability check, and is written to the audit log. Permanent deletes and sensitive option writes require the site admin to enable "destructive operations" in plugin settings.',
                wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'this site'
            ),
        ];
    }

    private static function handle_tool_call( array $params, array $auth ): array {
        $name      = (string) ( $params['name'] ?? '' );
        $arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [];

        if ( $name === '' ) {
            throw new \InvalidArgumentException( esc_html__( 'Tool name required.', 'commander-secure-mcp-control' ) );
        }

        $tool = ToolRegistry::instance()->get( $name );
        if ( ! $tool ) {
            /* translators: %s: tool name */
            throw new \InvalidArgumentException( esc_html( sprintf( __( 'Unknown tool: %s', 'commander-secure-mcp-control' ), $name ) ) );
        }

        // Scope check.
        $needed = $tool->required_scope();
        if ( ! Auth::has_scope( $auth, $needed ) ) {
            /* translators: 1: tool name, 2: required scope */
            throw new \RuntimeException( esc_html( sprintf( __( "Tool '%1\$s' requires scope '%2\$s'.", 'commander-secure-mcp-control' ), $name, $needed ) ) );
        }

        // WordPress capability check.
        $cap = $tool->required_capability();
        if ( $cap && ! current_user_can( $cap ) ) {
            /* translators: 1: tool name, 2: WP capability */
            throw new \RuntimeException( esc_html( sprintf( __( "Tool '%1\$s' requires WordPress capability '%2\$s'.", 'commander-secure-mcp-control' ), $name, $cap ) ) );
        }

        // Validate input shape.
        $tool->validate_arguments( $arguments );

        $result = $tool->execute( $arguments );

        Logger::log( [
            'token_id'    => $auth['token_id'],
            'method'      => 'tools/call',
            'tool'        => $name,
            'success'     => 1,
            'status_code' => 200,
        ] );

        // MCP tools/call result format.
        return [
            'content' => is_array( $result['content'] ?? null )
                ? $result['content']
                : [ [ 'type' => 'text', 'text' => is_string( $result ) ? $result : wp_json_encode( $result ) ] ],
            'isError' => (bool) ( $result['isError'] ?? false ),
        ];
    }

    private static function require_scope( array $auth, string $scope ): void {
        if ( ! Auth::has_scope( $auth, $scope ) ) {
            /* translators: %s: required scope */
            throw new \RuntimeException( esc_html( sprintf( __( "Scope '%s' required.", 'commander-secure-mcp-control' ), $scope ) ) );
        }
    }

    /* ------------------------- helpers ------------------------- */

    private static function rpc_ok( $id, $result ): array {
        return [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ];
    }

    private static function rpc_error( $id, int $code, string $message, $data = null ): array {
        $err = [ 'code' => $code, 'message' => $message ];
        if ( $data !== null ) {
            $err['data'] = $data;
        }
        return [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => $err ];
    }

    private static function wp_err_response( \WP_Error $err ): \WP_REST_Response {
        $status = (int) ( $err->get_error_data()['status'] ?? 400 );
        $body   = [
            'error'   => $err->get_error_code(),
            'message' => $err->get_error_message(),
        ];
        $resp = new \WP_REST_Response( $body, $status );
        return Security::harden_response( $resp );
    }

    private static function jsonrpc_error_response( $id, int $code, string $message, int $http ): \WP_REST_Response {
        $resp = new \WP_REST_Response( self::rpc_error( $id, $code, $message ), $http );
        return Security::harden_response( $resp );
    }
}
