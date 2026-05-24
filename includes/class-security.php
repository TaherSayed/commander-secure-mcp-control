<?php
/**
 * Transport-level security: origin check, HTTPS, security headers.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class Security {

    /**
     * Validate the request before any MCP processing.
     *
     * Returns null on success or a WP_Error suitable for REST responses.
     */
    public static function validate_request( \WP_REST_Request $request ): ?\WP_Error {
        $settings = Plugin::get_settings();

        // 0. Block obvious scanner user-agents.
        if ( ! empty( $settings['block_bad_uas'] ) ) {
            $ua = (string) $request->get_header( 'user_agent' );
            if ( $ua && self::is_bad_user_agent( $ua ) ) {
                return new \WP_Error( 'cmcp_blocked_ua', 'Blocked.', [ 'status' => 403 ] );
            }
        }

        // 1. HTTPS — required outside of localhost (DNS rebinding mitigations rely on TLS).
        if ( ! empty( $settings['require_https'] ) && ! self::is_https() && ! self::is_local_host() ) {
            return new \WP_Error( 'cmcp_https_required', 'HTTPS is required.', [ 'status' => 426 ] );
        }

        // 2. Origin header — DNS rebinding protection (per MCP spec).
        $origin_err = self::validate_origin( $request, $settings );
        if ( $origin_err ) {
            return $origin_err;
        }

        // 3. Method.
        if ( strtoupper( $request->get_method() ) !== 'POST' ) {
            // GET is reserved by spec for the optional SSE stream — we don't support it here.
            return new \WP_Error( 'cmcp_method', 'Only POST is supported.', [ 'status' => 405 ] );
        }

        // 4. Content-Type.
        $ct    = $request->get_content_type();
        $ctype = is_array( $ct ) ? (string) ( $ct['value'] ?? '' ) : '';
        if ( stripos( $ctype, 'application/json' ) === false ) {
            return new \WP_Error( 'cmcp_content_type', 'Content-Type must be application/json.', [ 'status' => 415 ] );
        }

        // 5. Request size.
        $max  = (int) ( $settings['max_request_bytes'] ?? 262144 );
        $body = $request->get_body();
        if ( strlen( $body ) > $max ) {
            return new \WP_Error( 'cmcp_too_large', 'Request body too large.', [ 'status' => 413 ] );
        }

        // 6. Required Accept header per spec — must include application/json.
        $accept = (string) $request->get_header( 'accept' );
        if ( $accept !== '' && stripos( $accept, 'application/json' ) === false && stripos( $accept, '*/*' ) === false ) {
            return new \WP_Error( 'cmcp_accept', 'Accept header must include application/json.', [ 'status' => 406 ] );
        }

        return null;
    }

    /** Common scanner / vulnerability-probe user-agents. */
    private static function is_bad_user_agent( string $ua ): bool {
        $bad = [
            'sqlmap', 'nikto', 'nmap', 'masscan', 'wpscan', 'metasploit',
            'havij', 'fimap', 'whatweb', 'zgrab', 'gobuster', 'dirbuster',
            'feroxbuster', 'ffuf', 'wfuzz', 'arachni', 'commix',
        ];
        $lc = strtolower( $ua );
        foreach ( $bad as $needle ) {
            if ( str_contains( $lc, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    private static function validate_origin( \WP_REST_Request $request, array $settings ): ?\WP_Error {
        $origin = (string) $request->get_header( 'origin' );

        // Programmatic clients (curl, server-to-server) often omit Origin — that's fine.
        if ( $origin === '' ) {
            return null;
        }

        $allowed = array_filter( array_map( 'trim', (array) ( $settings['allowed_origins'] ?? [] ) ) );

        // Always allow same site as a sane default.
        $home = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $home ) {
            $allowed[] = ( self::is_https() ? 'https://' : 'http://' ) . $home;
        }

        $origin_norm = rtrim( $origin, '/' );
        foreach ( $allowed as $a ) {
            if ( strcasecmp( rtrim( $a, '/' ), $origin_norm ) === 0 ) {
                return null;
            }
        }

        return new \WP_Error( 'cmcp_origin', 'Origin not allowed.', [ 'status' => 403 ] );
    }

    public static function is_https(): bool {
        if ( is_ssl() ) {
            return true;
        }
        // Behind reverse proxy — only honor X-Forwarded-Proto when admin has opted in.
        // Without this opt-in, an attacker who can set the header bypasses the HTTPS gate.
        if ( self::trust_proxy() ) {
            $proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
                ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
                : '';
            if ( $proto === 'https' ) {
                return true;
            }
        }
        return false;
    }

    public static function is_local_host(): bool {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
        $host = preg_replace( '/:\d+$/', '', $host );
        return in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true );
    }

    /**
     * Real client IP. Behind Cloudflare / load balancer, admin must enable
     * "trust_proxy" so we read CF-Connecting-IP / X-Forwarded-For; otherwise
     * REMOTE_ADDR would always be the proxy and the first attacker would lock
     * out everyone via the brute-force gate.
     */
    public static function client_ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        if ( self::trust_proxy() ) {
            // Cloudflare first — single value, no chain to parse.
            if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
                $candidate = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                    $ip = $candidate;
                }
            } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                // First entry in the chain is the original client.
                $parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
                $candidate = trim( (string) ( $parts[0] ?? '' ) );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                    $ip = $candidate;
                }
            } elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
                $candidate = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ) );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                    $ip = $candidate;
                }
            }
        }

        return (string) apply_filters( 'cmcp_client_ip', $ip );
    }

    private static function trust_proxy(): bool {
        $settings = Plugin::get_settings();
        return ! empty( $settings['trust_proxy'] );
    }

    /**
     * Add security headers to a REST response.
     */
    public static function harden_response( \WP_REST_Response $response ): \WP_REST_Response {
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        $response->header( 'Referrer-Policy', 'no-referrer' );
        $response->header( 'Cache-Control', 'no-store' );
        // Tell intermediaries this is API content, not for caching.
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }
}
