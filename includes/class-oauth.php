<?php
/**
 * OAuth 2.1 authorization server for Commander.
 *
 * Implements:
 *   - RFC 6749 Authorization Code grant (only — no implicit, no password)
 *   - RFC 7636 PKCE (S256 required)
 *   - RFC 7591 Dynamic Client Registration
 *   - RFC 7009 Token Revocation
 *   - RFC 8414 Authorization Server Metadata
 *   - RFC 8707 Resource Indicators
 *   - RFC 9728 Protected Resource Metadata
 *
 * The same WordPress site is both the Authorization Server and the Resource
 * Server (the MCP endpoint). Issued access tokens are opaque strings
 * (`cmcp_oat_<hex>`) stored as SHA-256 hashes in the DB; `Auth::authenticate()`
 * recognises them by prefix and dispatches to `verify_access_token()` here.
 *
 * @package WPCommander
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class OAuth {

    /** Lifetime of an authorization code (seconds). */
    public const CODE_TTL    = 300;       // 5 min

    /** Lifetime of an access token (seconds). */
    public const ACCESS_TTL  = 3600;      // 1 h

    /** Lifetime of a refresh token (seconds). */
    public const REFRESH_TTL = 2592000;   // 30 d

    /** Token prefixes. */
    public const PREFIX_ACCESS  = 'cmcp_oat_';
    public const PREFIX_REFRESH = 'cmcp_ort_';

    /** Scopes we recognise. */
    public const SCOPES = [ 'read', 'write', 'admin' ];

    /* ------------------------------------------------------------------ *
     *  Route registration
     * ------------------------------------------------------------------ */

    public static function register_routes(): void {
        register_rest_route( CMCP_REST_NAMESPACE, '/oauth/authorize', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ self::class, 'rest_authorize_get' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'rest_authorize_post' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        register_rest_route( CMCP_REST_NAMESPACE, '/oauth/token', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'rest_token' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( CMCP_REST_NAMESPACE, '/oauth/register', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'rest_register' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( CMCP_REST_NAMESPACE, '/oauth/revoke', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'rest_revoke' ],
            'permission_callback' => '__return_true',
        ] );

        // Discovery metadata under /wp-json/ — convenient mirrors of the
        // .well-known endpoints, in case a client looks here.
        register_rest_route( CMCP_REST_NAMESPACE, '/.well-known/oauth-authorization-server', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'rest_as_metadata' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( CMCP_REST_NAMESPACE, '/.well-known/oauth-protected-resource', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'rest_pr_metadata' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Serve /.well-known/oauth-* at the *domain root* (not under /wp-json/),
     * which is where RFC 8414 / RFC 9728 say discovery lives.
     */
    public static function serve_well_known( \WP $wp ): void {
        // Cheap path test first so we don't run on every front-end request.
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( strpos( $uri, '/.well-known/oauth-' ) === false ) {
            return;
        }
        $path = (string) wp_parse_url( $uri, PHP_URL_PATH );

        if ( $path === '/.well-known/oauth-authorization-server' ) {
            // Per-IP rate limit to slow down enumeration.
            if ( ! RateLimiter::by_ip( 'oauth_wk', 60 ) ) {
                status_header( 429 );
                exit;
            }
            self::output_json( self::authorization_server_metadata() );
        } elseif ( $path === '/.well-known/oauth-protected-resource' ) {
            if ( ! RateLimiter::by_ip( 'oauth_wk', 60 ) ) {
                status_header( 429 );
                exit;
            }
            self::output_json( self::protected_resource_metadata() );
        }
    }

    /* ------------------------------------------------------------------ *
     *  Discovery metadata
     * ------------------------------------------------------------------ */

    public static function rest_as_metadata(): \WP_REST_Response {
        return new \WP_REST_Response( self::authorization_server_metadata() );
    }
    public static function rest_pr_metadata(): \WP_REST_Response {
        return new \WP_REST_Response( self::protected_resource_metadata() );
    }

    /** RFC 8414 Authorization Server Metadata. */
    private static function authorization_server_metadata(): array {
        $base = rest_url( CMCP_REST_NAMESPACE );
        return [
            'issuer'                                => home_url( '/' ),
            'authorization_endpoint'                => $base . '/oauth/authorize',
            'token_endpoint'                        => $base . '/oauth/token',
            'registration_endpoint'                 => $base . '/oauth/register',
            'revocation_endpoint'                   => $base . '/oauth/revoke',
            'response_types_supported'              => [ 'code' ],
            'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
            'code_challenge_methods_supported'      => [ 'S256' ],
            'token_endpoint_auth_methods_supported' => [ 'none', 'client_secret_basic', 'client_secret_post' ],
            'scopes_supported'                      => self::SCOPES,
            'service_documentation'                 => 'https://hbs-it-gmbh.de/wp-commander',
            'ui_locales_supported'                  => [ 'en', 'de' ],
        ];
    }

    /** RFC 9728 Protected Resource Metadata. */
    private static function protected_resource_metadata(): array {
        return [
            'resource'                => rest_url( CMCP_REST_NAMESPACE . '/rpc' ),
            'authorization_servers'   => [ home_url( '/' ) ],
            'scopes_supported'        => self::SCOPES,
            'bearer_methods_supported'=> [ 'header' ],
            'resource_documentation'  => 'https://hbs-it-gmbh.de/wp-commander',
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Dynamic Client Registration (RFC 7591)
     * ------------------------------------------------------------------ */

    public static function rest_register( \WP_REST_Request $request ) {
        // Admin opt-in. DCR is anonymous by design (RFC 7591) so we require
        // the site owner to explicitly turn it on, otherwise an internet
        // attacker can flood the clients table and phish admins via a
        // crafted redirect_uri.
        $settings = Plugin::get_settings();
        if ( empty( $settings['allow_dcr'] ) ) {
            return self::oauth_error(
                'access_denied',
                'Dynamic Client Registration is disabled on this site. Enable it under Commander → Settings → OAuth.',
                403
            );
        }

        // Per-IP rate limit — at most 5 registrations per minute per IP.
        if ( ! RateLimiter::by_ip( 'oauth_register', 5 ) ) {
            return self::oauth_error( 'temporarily_unavailable', 'Too many registrations. Try again later.', 429 );
        }

        $body = json_decode( $request->get_body() ?: '{}', true );
        if ( ! is_array( $body ) ) {
            return self::oauth_error( 'invalid_request', 'Body must be JSON.', 400 );
        }

        $redirect_uris = $body['redirect_uris'] ?? null;
        if ( ! is_array( $redirect_uris ) || empty( $redirect_uris ) ) {
            return self::oauth_error( 'invalid_redirect_uri', 'redirect_uris[] required.', 400 );
        }
        foreach ( $redirect_uris as $uri ) {
            if ( ! self::is_valid_redirect_uri( (string) $uri ) ) {
                return self::oauth_error( 'invalid_redirect_uri', "Invalid redirect_uri: {$uri}", 400 );
            }
        }

        $name        = sanitize_text_field( (string) ( $body['client_name'] ?? 'OAuth Client' ) );
        $auth_method = (string) ( $body['token_endpoint_auth_method'] ?? 'none' );
        if ( ! in_array( $auth_method, [ 'none', 'client_secret_basic', 'client_secret_post' ], true ) ) {
            $auth_method = 'none';
        }

        $client_id     = 'cmcp_cli_' . bin2hex( random_bytes( 16 ) );
        $client_secret = null;
        $secret_hash   = null;
        if ( $auth_method !== 'none' ) {
            $client_secret = bin2hex( random_bytes( 32 ) );
            $secret_hash   = hash( 'sha256', $client_secret );
        }

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->insert(
            $wpdb->prefix . Plugin::TABLE_OAUTH_CLIENTS,
            [
                'client_id'                  => $client_id,
                'client_secret_hash'         => $secret_hash,
                'name'                       => mb_substr( $name, 0, 200 ),
                'redirect_uris'              => wp_json_encode( array_values( $redirect_uris ) ),
                'grant_types'                => 'authorization_code,refresh_token',
                'token_endpoint_auth_method' => $auth_method,
                'is_dcr'                     => 1,
                'created_at'                 => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        Logger::log( [ 'method' => 'oauth/register', 'success' => 1, 'status_code' => 201, 'note' => "client {$client_id} ({$name})" ] );

        // Webhook (no-op if not configured).
        Notifier::notify( Notifier::EVENT_OAUTH_CLIENT, [
            'client_id'                  => $client_id,
            'client_name'                => $name,
            'token_endpoint_auth_method' => $auth_method,
            'redirect_uris'              => array_values( $redirect_uris ),
            'admin_url'                  => admin_url( 'admin.php?page=cmcp-oauth' ),
        ] );

        $resp = [
            'client_id'                  => $client_id,
            'client_id_issued_at'        => time(),
            'redirect_uris'              => array_values( $redirect_uris ),
            'token_endpoint_auth_method' => $auth_method,
            'grant_types'                => [ 'authorization_code', 'refresh_token' ],
            'response_types'             => [ 'code' ],
            'client_name'                => $name,
        ];
        if ( $client_secret ) {
            $resp['client_secret']            = $client_secret;
            $resp['client_secret_expires_at'] = 0; // never
        }
        return new \WP_REST_Response( $resp, 201 );
    }

    /* ------------------------------------------------------------------ *
     *  Authorization endpoint (GET shows consent, POST processes it)
     * ------------------------------------------------------------------ */

    public static function rest_authorize_get( \WP_REST_Request $request ): \WP_REST_Response {
        $params = self::collect_authorize_params( $request );
        $err    = self::validate_authorize_params( $params );

        // Hard errors not safe to redirect (no/invalid redirect_uri) — render HTML.
        if ( $err && in_array( $err[0], [ 'invalid_request', 'invalid_redirect_uri', 'unauthorized_client', 'invalid_scope_pre' ], true ) ) {
            return self::html_response( self::render_error_page( $err[1] ), 400 );
        }
        // Recoverable errors — redirect back to the client.
        if ( $err ) {
            return self::redirect_with_error( $params['redirect_uri'], $err[0], $err[1], $params['state'] );
        }

        // Authenticate via WP browser cookie. REST endpoints normally require a
        // nonce header for cookie auth — we don't, because this URL is hit by
        // direct browser navigation in the OAuth flow.
        self::maybe_login_from_cookie();

        // Force WP login if needed.
        if ( ! is_user_logged_in() ) {
            $self_url = self::current_request_url();
            $login    = wp_login_url( $self_url );
            return self::raw_redirect( $login );
        }

        // Render the consent screen.
        return self::html_response( self::render_consent_page( $params ), 200 );
    }

    public static function rest_authorize_post( \WP_REST_Request $request ): \WP_REST_Response {
        // Per-IP rate limit — 20 consent submissions per minute is plenty for
        // a human, but stops automated abuse.
        if ( ! RateLimiter::by_ip( 'oauth_authorize_post', 20 ) ) {
            return self::html_response( self::render_error_page( 'Too many requests. Please slow down.' ), 429 );
        }

        self::maybe_login_from_cookie();

        if ( ! is_user_logged_in() ) {
            return self::html_response( self::render_error_page( 'You must be logged in.' ), 401 );
        }

        $params = self::collect_authorize_params( $request );
        $err    = self::validate_authorize_params( $params );
        if ( $err && in_array( $err[0], [ 'invalid_request', 'invalid_redirect_uri', 'unauthorized_client', 'invalid_scope_pre' ], true ) ) {
            return self::html_response( self::render_error_page( $err[1] ), 400 );
        }
        if ( $err ) {
            return self::redirect_with_error( $params['redirect_uri'], $err[0], $err[1], $params['state'] );
        }

        // Nonce check on the consent form itself.
        $nonce = (string) $request->get_param( '_cmcp_nonce' );
        if ( ! wp_verify_nonce( $nonce, 'cmcp_oauth_consent' ) ) {
            return self::html_response( self::render_error_page( 'Security check failed. Please retry from the start.' ), 403 );
        }

        // Deny.
        if ( (string) $request->get_param( 'decision' ) !== 'approve' ) {
            return self::redirect_with_error( $params['redirect_uri'], 'access_denied', 'User denied the request.', $params['state'] );
        }

        // Approve — issue a one-time code.
        $code      = bin2hex( random_bytes( 32 ) );
        $code_hash = hash( 'sha256', $code );

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->insert(
            $wpdb->prefix . Plugin::TABLE_OAUTH_CODES,
            [
                'code_hash'             => $code_hash,
                'client_id'             => $params['client_id'],
                'user_id'               => get_current_user_id(),
                'scopes'                => implode( ',', $params['scopes'] ),
                'redirect_uri'          => $params['redirect_uri'],
                'code_challenge'        => $params['code_challenge'],
                'code_challenge_method' => $params['code_challenge_method'],
                'resource'              => $params['resource'] ?: null,
                'expires_at'            => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
                'created_at'            => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        Logger::log( [
            'method'      => 'oauth/authorize',
            'success'     => 1,
            'status_code' => 302,
            'note'        => 'code issued for client ' . $params['client_id'] . ' user ' . get_current_user_id(),
        ] );

        // Redirect back with the code.
        $url = add_query_arg( [ 'code' => $code, 'state' => $params['state'] ], $params['redirect_uri'] );
        return self::raw_redirect( $url );
    }

    /* ------------------------------------------------------------------ *
     *  Token endpoint
     * ------------------------------------------------------------------ */

    public static function rest_token( \WP_REST_Request $request ) {
        $grant = (string) $request->get_param( 'grant_type' );

        if ( $grant === 'authorization_code' ) {
            return self::token_from_code( $request );
        }
        if ( $grant === 'refresh_token' ) {
            return self::token_from_refresh( $request );
        }
        return self::oauth_error( 'unsupported_grant_type', "Unsupported grant_type: {$grant}", 400 );
    }

    private static function token_from_code( \WP_REST_Request $request ) {
        $code          = (string) $request->get_param( 'code' );
        $redirect_uri  = (string) $request->get_param( 'redirect_uri' );
        $code_verifier = (string) $request->get_param( 'code_verifier' );
        $client_id     = (string) $request->get_param( 'client_id' );
        $resource      = $request->get_param( 'resource' );
        $resource      = is_string( $resource ) ? $resource : null;

        if ( $code === '' || $redirect_uri === '' || $code_verifier === '' ) {
            return self::oauth_error( 'invalid_request', 'code, redirect_uri, and code_verifier are required.', 400 );
        }

        // Client auth (if confidential).
        $client = self::authenticate_client( $request, $client_id );
        if ( $client instanceof \WP_REST_Response ) {
            return $client; // error already formatted
        }

        global $wpdb;
        $code_hash = hash( 'sha256', $code );
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . Plugin::TABLE_OAUTH_CODES . " WHERE code_hash = %s", $code_hash ),
            ARRAY_A
        );
        if ( ! $row ) {
            return self::oauth_error( 'invalid_grant', 'Authorization code not recognised.', 400 );
        }
        if ( ! empty( $row['used_at'] ) ) {
            // Replay — also revoke any tokens minted from it (defence in depth).
            self::revoke_tokens_for_user_client( (int) $row['user_id'], (string) $row['client_id'] );
            return self::oauth_error( 'invalid_grant', 'Authorization code already used.', 400 );
        }
        if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
            return self::oauth_error( 'invalid_grant', 'Authorization code expired.', 400 );
        }
        if ( ! hash_equals( (string) $row['redirect_uri'], $redirect_uri ) ) {
            return self::oauth_error( 'invalid_grant', 'redirect_uri mismatch.', 400 );
        }
        if ( ! hash_equals( (string) $row['client_id'], $client['client_id'] ) ) {
            return self::oauth_error( 'invalid_grant', 'client_id mismatch.', 400 );
        }

        // PKCE verification (S256).
        $expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
        if ( ! hash_equals( (string) $row['code_challenge'], $expected ) ) {
            return self::oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
        }

        // Mark code as used.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->update(
            $wpdb->prefix . Plugin::TABLE_OAUTH_CODES,
            [ 'used_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => (int) $row['id'] ],
            [ '%s' ],
            [ '%d' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        // Issue tokens.
        $tokens = self::issue_tokens(
            (string) $row['client_id'],
            (int) $row['user_id'],
            array_filter( array_map( 'trim', explode( ',', (string) $row['scopes'] ) ) ),
            $resource ?: ( $row['resource'] ?: null )
        );

        Logger::log( [
            'method'      => 'oauth/token',
            'success'     => 1,
            'status_code' => 200,
            'note'        => 'access+refresh issued for ' . $row['client_id'] . ' user ' . $row['user_id'],
        ] );

        return new \WP_REST_Response( $tokens, 200 );
    }

    private static function token_from_refresh( \WP_REST_Request $request ) {
        $refresh   = (string) $request->get_param( 'refresh_token' );
        $client_id = (string) $request->get_param( 'client_id' );
        $resource  = $request->get_param( 'resource' );
        $resource  = is_string( $resource ) ? $resource : null;

        if ( $refresh === '' ) {
            return self::oauth_error( 'invalid_request', 'refresh_token required.', 400 );
        }

        $client = self::authenticate_client( $request, $client_id );
        if ( $client instanceof \WP_REST_Response ) {
            return $client;
        }

        global $wpdb;
        $refresh_hash = hash( 'sha256', $refresh );
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . Plugin::TABLE_OAUTH_TOKENS . " WHERE refresh_hash = %s", $refresh_hash ),
            ARRAY_A
        );
        if ( ! $row ) {
            return self::oauth_error( 'invalid_grant', 'refresh_token not recognised.', 400 );
        }
        if ( ! empty( $row['revoked_at'] ) ) {
            return self::oauth_error( 'invalid_grant', 'refresh_token revoked.', 400 );
        }
        if ( $row['refresh_expires_at'] && strtotime( $row['refresh_expires_at'] . ' UTC' ) < time() ) {
            return self::oauth_error( 'invalid_grant', 'refresh_token expired.', 400 );
        }
        if ( ! hash_equals( (string) $row['client_id'], $client['client_id'] ) ) {
            return self::oauth_error( 'invalid_grant', 'client_id mismatch.', 400 );
        }

        // Rotate: revoke the old row, issue a new pair.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->update(
            $wpdb->prefix . Plugin::TABLE_OAUTH_TOKENS,
            [ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => (int) $row['id'] ],
            [ '%s' ],
            [ '%d' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        $tokens = self::issue_tokens(
            (string) $row['client_id'],
            (int) $row['user_id'],
            array_filter( array_map( 'trim', explode( ',', (string) $row['scopes'] ) ) ),
            $resource ?: ( $row['resource'] ?: null )
        );

        Logger::log( [ 'method' => 'oauth/token/refresh', 'success' => 1, 'status_code' => 200, 'note' => 'refresh rotated for client ' . $row['client_id'] ] );

        return new \WP_REST_Response( $tokens, 200 );
    }

    /* ------------------------------------------------------------------ *
     *  Revocation (RFC 7009)
     * ------------------------------------------------------------------ */

    public static function rest_revoke( \WP_REST_Request $request ): \WP_REST_Response {
        $token = (string) $request->get_param( 'token' );
        if ( $token === '' ) {
            // RFC 7009: respond 200 even on invalid token to avoid leakage.
            return new \WP_REST_Response( null, 200 );
        }

        global $wpdb;
        $hash = hash( 'sha256', $token );
        $now  = gmdate( 'Y-m-d H:i:s' );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->update(
            $wpdb->prefix . Plugin::TABLE_OAUTH_TOKENS,
            [ 'revoked_at' => $now ],
            [ 'access_hash' => $hash ],
            [ '%s' ], [ '%s' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->update(
            $wpdb->prefix . Plugin::TABLE_OAUTH_TOKENS,
            [ 'revoked_at' => $now ],
            [ 'refresh_hash' => $hash ],
            [ '%s' ], [ '%s' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        Logger::log( [ 'method' => 'oauth/revoke', 'success' => 1, 'status_code' => 200 ] );

        return new \WP_REST_Response( null, 200 );
    }

    /* ------------------------------------------------------------------ *
     *  Token verification (called by Auth::authenticate)
     * ------------------------------------------------------------------ */

    /**
     * Verify an OAuth access token. Returns the same array shape as
     * Auth::authenticate_pat() so callers can treat both kinds uniformly.
     */
    public static function verify_access_token( string $presented ): array {
        $hash = hash( 'sha256', $presented );

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . Plugin::TABLE_OAUTH_TOKENS . " WHERE access_hash = %s", $hash ),
            ARRAY_A
        );

        if ( ! $row ) {
            hash_equals( str_repeat( 'a', 64 ), $hash );
            return [ 'ok' => false, 'error' => Auth::err( 'cmcp_invalid_token', 'Invalid token.', 401 ) ];
        }
        if ( ! hash_equals( (string) $row['access_hash'], $hash ) ) {
            return [ 'ok' => false, 'error' => Auth::err( 'cmcp_invalid_token', 'Invalid token.', 401 ) ];
        }
        if ( ! empty( $row['revoked_at'] ) ) {
            return [ 'ok' => false, 'error' => Auth::err( 'cmcp_revoked', 'Token revoked.', 401 ) ];
        }
        if ( strtotime( $row['access_expires_at'] . ' UTC' ) < time() ) {
            return [ 'ok' => false, 'error' => Auth::err( 'cmcp_expired', 'Token expired.', 401 ) ];
        }

        wp_set_current_user( (int) $row['user_id'] );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->update(
            $wpdb->prefix . Plugin::TABLE_OAUTH_TOKENS,
            [ 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => (int) $row['id'] ],
            [ '%s' ], [ '%d' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return [
            'ok'       => true,
            'token_id' => (int) $row['id'],
            'scopes'   => array_filter( array_map( 'trim', explode( ',', (string) $row['scopes'] ) ) ),
            'user_id'  => (int) $row['user_id'],
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Internals
     * ------------------------------------------------------------------ */

    /** Issue a new access + refresh pair. */
    private static function issue_tokens( string $client_id, int $user_id, array $scopes, ?string $resource ): array {
        $access  = self::PREFIX_ACCESS  . bin2hex( random_bytes( 32 ) );
        $refresh = self::PREFIX_REFRESH . bin2hex( random_bytes( 32 ) );

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->insert(
            $wpdb->prefix . Plugin::TABLE_OAUTH_TOKENS,
            [
                'access_hash'        => hash( 'sha256', $access ),
                'refresh_hash'       => hash( 'sha256', $refresh ),
                'client_id'          => $client_id,
                'user_id'            => $user_id,
                'scopes'             => implode( ',', $scopes ),
                'resource'           => $resource,
                'access_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TTL ),
                'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TTL ),
                'created_at'         => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        $body = [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => implode( ' ', $scopes ),
        ];
        if ( $resource ) {
            $body['resource'] = $resource;
        }
        return $body;
    }

    /** Revoke all tokens for a (user, client) pair — used on auth-code replay. */
    private static function revoke_tokens_for_user_client( int $user_id, string $client_id ): void {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}" . Plugin::TABLE_OAUTH_TOKENS . " SET revoked_at = %s WHERE user_id = %d AND client_id = %s AND revoked_at IS NULL",
            gmdate( 'Y-m-d H:i:s' ), $user_id, $client_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /** Look up a client by id; returns row or null. */
    public static function find_client( string $client_id ): ?array {
        if ( $client_id === '' ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . Plugin::TABLE_OAUTH_CLIENTS . " WHERE client_id = %s", $client_id
        ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Authenticate the client on the token endpoint.
     * Returns the client row on success, or a WP_REST_Response error on failure.
     */
    private static function authenticate_client( \WP_REST_Request $request, string $body_client_id ) {
        // Try HTTP Basic first.
        $auth_header = (string) $request->get_header( 'authorization' );
        $client_id = $client_secret = '';
        if ( stripos( $auth_header, 'Basic ' ) === 0 ) {
            $decoded = base64_decode( substr( $auth_header, 6 ), true );
            if ( $decoded && strpos( $decoded, ':' ) !== false ) {
                [ $client_id, $client_secret ] = explode( ':', $decoded, 2 );
            }
        }
        // Or form params.
        if ( $client_id === '' ) {
            $client_id     = $body_client_id;
            $client_secret = (string) $request->get_param( 'client_secret' );
        }
        if ( $client_id === '' ) {
            return self::oauth_error( 'invalid_client', 'client_id required.', 401 );
        }
        $client = self::find_client( $client_id );
        if ( ! $client ) {
            return self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
        }
        // Public client — no secret needed.
        if ( empty( $client['client_secret_hash'] ) ) {
            return $client;
        }
        if ( $client_secret === '' || ! hash_equals( (string) $client['client_secret_hash'], hash( 'sha256', $client_secret ) ) ) {
            return self::oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
        }
        return $client;
    }

    /** Pull the standard authorize params out of the request. */
    private static function collect_authorize_params( \WP_REST_Request $request ): array {
        $scope_str = (string) $request->get_param( 'scope' );
        $scopes    = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $scope_str ) ?: [] ) );
        $scopes    = array_values( array_intersect( self::SCOPES, $scopes ) );
        if ( ! $scopes ) {
            $scopes = [ 'read' ];
        }

        return [
            'response_type'         => (string) $request->get_param( 'response_type' ),
            'client_id'             => (string) $request->get_param( 'client_id' ),
            'redirect_uri'          => (string) $request->get_param( 'redirect_uri' ),
            'state'                 => (string) $request->get_param( 'state' ),
            'code_challenge'        => (string) $request->get_param( 'code_challenge' ),
            'code_challenge_method' => (string) ( $request->get_param( 'code_challenge_method' ) ?: 'S256' ),
            'scopes'                => $scopes,
            'resource'              => (string) ( $request->get_param( 'resource' ) ?: '' ),
        ];
    }

    /** Returns [code, message] on error, null on success. */
    private static function validate_authorize_params( array $p ): ?array {
        if ( $p['response_type'] !== 'code' ) {
            return [ 'unsupported_response_type', 'response_type must be "code".' ];
        }
        if ( $p['client_id'] === '' ) {
            return [ 'invalid_request', 'client_id required.' ];
        }
        $client = self::find_client( $p['client_id'] );
        if ( ! $client ) {
            return [ 'unauthorized_client', 'Unknown client.' ];
        }
        $allowed = json_decode( (string) $client['redirect_uris'], true );
        if ( ! is_array( $allowed ) || ! in_array( $p['redirect_uri'], $allowed, true ) ) {
            return [ 'invalid_redirect_uri', 'redirect_uri not registered for this client.' ];
        }
        if ( $p['code_challenge'] === '' || $p['code_challenge_method'] !== 'S256' ) {
            return [ 'invalid_request', 'PKCE S256 code_challenge required.' ];
        }
        if ( strlen( $p['code_challenge'] ) < 43 || strlen( $p['code_challenge'] ) > 128 ) {
            return [ 'invalid_request', 'code_challenge length invalid.' ];
        }
        return null;
    }

    private static function is_valid_redirect_uri( string $uri ): bool {
        $p = wp_parse_url( $uri );
        if ( ! $p || empty( $p['scheme'] ) ) return false;
        // Allow https://… and custom schemes (e.g. desktop apps), reject http:// except localhost.
        if ( $p['scheme'] === 'http' ) {
            $host = $p['host'] ?? '';
            if ( ! in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
                return false;
            }
        }
        // No fragments allowed in OAuth redirect URIs.
        if ( ! empty( $p['fragment'] ) ) return false;
        return true;
    }

    /* ----------------------- Rendering helpers ----------------------- */

    private static function render_consent_page( array $p ): string {
        $client    = self::find_client( $p['client_id'] );
        $name      = $client ? (string) $client['name'] : $p['client_id'];
        $self_url  = self::current_request_url();
        $nonce     = wp_create_nonce( 'cmcp_oauth_consent' );
        $scope_lbl = [
            'read'  => 'Read content, search posts, list media, read settings.',
            'write' => 'Create / edit / trash posts, upload media, moderate comments.',
            'admin' => 'Manage users, plugins, themes, and site settings.',
        ];
        $user = wp_get_current_user();

        ob_start();
        ?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Authorize · Commander</title>
<style>
  body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f1;color:#1d2327;display:flex;align-items:center;justify-content:center;min-height:100vh}
  .card{background:#fff;max-width:480px;width:90%;padding:36px;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  h1{margin:0 0 4px;font-size:22px}
  .sub{color:#646970;font-size:13px;margin-bottom:24px}
  .client{background:#f6f7f7;border:1px solid #dcdcde;padding:14px;border-radius:6px;margin:0 0 20px}
  .client b{display:block;font-size:16px}
  .scopes{margin:0 0 24px;padding:0;list-style:none}
  .scopes li{padding:10px 0;border-top:1px solid #f0f0f1}
  .scopes li:first-child{border-top:0}
  .scope-name{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#f0f6fc;color:#0a4b78;padding:1px 6px;border-radius:3px;font-size:12px}
  .actions{display:flex;gap:10px}
  button{flex:1;padding:11px 16px;border-radius:5px;border:1px solid transparent;font-size:14px;font-weight:600;cursor:pointer}
  .approve{background:#2271b1;color:#fff;border-color:#2271b1}
  .approve:hover{background:#135e96}
  .deny{background:#fff;color:#1d2327;border-color:#c3c4c7}
  .deny:hover{background:#f6f7f7}
  .who{margin-top:16px;font-size:12px;color:#646970;text-align:center}
  .who a{color:#2271b1}
  .footer{margin-top:24px;padding-top:18px;border-top:1px solid #f0f0f1;font-size:12px;color:#8c8f94;text-align:center}
</style></head><body>
<div class="card">
  <h1>Authorize access</h1>
  <p class="sub">An application is requesting permission to act on this WordPress site on your behalf.</p>

  <div class="client">
    <b><?php echo esc_html( $name ); ?></b>
    <code style="font-size:11px;color:#646970"><?php echo esc_html( $p['client_id'] ); ?></code>
  </div>

  <p style="margin:0 0 8px;font-weight:600">It will be able to:</p>
  <ul class="scopes">
    <?php foreach ( $p['scopes'] as $s ) : ?>
      <li><span class="scope-name"><?php echo esc_html( $s ); ?></span> — <?php echo esc_html( $scope_lbl[ $s ] ?? 'Custom scope.' ); ?></li>
    <?php endforeach; ?>
  </ul>

  <form method="post" action="<?php echo esc_attr( $self_url ); ?>">
    <input type="hidden" name="_cmcp_nonce"           value="<?php echo esc_attr( $nonce ); ?>">
    <input type="hidden" name="response_type"         value="code">
    <input type="hidden" name="client_id"             value="<?php echo esc_attr( $p['client_id'] ); ?>">
    <input type="hidden" name="redirect_uri"          value="<?php echo esc_attr( $p['redirect_uri'] ); ?>">
    <input type="hidden" name="state"                 value="<?php echo esc_attr( $p['state'] ); ?>">
    <input type="hidden" name="scope"                 value="<?php echo esc_attr( implode( ' ', $p['scopes'] ) ); ?>">
    <input type="hidden" name="code_challenge"        value="<?php echo esc_attr( $p['code_challenge'] ); ?>">
    <input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $p['code_challenge_method'] ); ?>">
    <?php if ( ! empty( $p['resource'] ) ) : ?>
      <input type="hidden" name="resource" value="<?php echo esc_attr( $p['resource'] ); ?>">
    <?php endif; ?>

    <div class="actions">
      <button class="deny"    name="decision" value="deny"    type="submit">Deny</button>
      <button class="approve" name="decision" value="approve" type="submit">Approve</button>
    </div>
  </form>

  <p class="who">Signed in as <b><?php echo esc_html( $user->user_login ); ?></b> · <a href="<?php echo esc_url( wp_logout_url( $self_url ) ); ?>">Switch user</a></p>

  <div class="footer">Commander · powered by Taher Sayed · HBS IT GmbH</div>
</div>
</body></html>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_error_page( string $msg ): string {
        $msg = esc_html( $msg );
        $html  = '<!doctype html><html><head><meta charset="utf-8"><title>OAuth error</title>';
        $html .= '<style>body{font:15px/1.5 -apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f0f1;margin:0}';
        $html .= '.card{background:#fff;max-width:480px;padding:32px;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.08)}';
        $html .= 'h1{margin-top:0;color:#b32d2e}.f{margin-top:24px;padding-top:16px;border-top:1px solid #f0f0f1;font-size:12px;color:#8c8f94;text-align:center}</style>';
        $html .= '</head><body><div class="card"><h1>OAuth error</h1><p>' . $msg . '</p><div class="f">Commander · HBS IT GmbH</div></div></body></html>';
        return $html;
    }

    /* ----------------------- Response helpers ----------------------- */

    /**
     * Emit a raw HTML response and exit. We bypass WP's REST serializer
     * because it always wp_json_encode()s the response body — even with
     * Content-Type set to text/html — turning our consent / error pages
     * into a quoted, escaped JSON string that browsers can't render.
     */
    private static function html_response( string $html, int $status ): \WP_REST_Response {
        if ( ! headers_sent() ) {
            status_header( $status );
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'Cache-Control: no-store' );
            header( 'X-Content-Type-Options: nosniff' );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted HTML built from esc_*'d parts in render_*_page().
        echo $html;
        exit;
        // @phpstan-ignore-next-line return.unreachable -- Keeps return type compatible for callers.
        return new \WP_REST_Response( null, $status );
    }

    /**
     * Emit a 302 redirect and exit. Same reasoning as html_response —
     * REST's serializer would otherwise emit a quoted JSON body alongside
     * the Location header.
     */
    private static function raw_redirect( string $url ): \WP_REST_Response {
        if ( ! headers_sent() ) {
            status_header( 302 );
            header( 'Location: ' . $url );
            header( 'Cache-Control: no-store' );
        }
        exit;
        // @phpstan-ignore-next-line return.unreachable -- Keeps return type compatible for callers.
        return new \WP_REST_Response( null, 302 );
    }

    private static function redirect_with_error( string $redirect_uri, string $code, string $desc, string $state ): \WP_REST_Response {
        if ( ! $redirect_uri ) {
            return self::html_response( self::render_error_page( "{$code}: {$desc}" ), 400 );
        }
        $url = add_query_arg( array_filter( [
            'error'             => $code,
            'error_description' => $desc,
            'state'             => $state,
        ] ), $redirect_uri );
        return self::raw_redirect( $url );
    }

    private static function oauth_error( string $code, string $description, int $status ): \WP_REST_Response {
        $r = new \WP_REST_Response( [
            'error'             => $code,
            'error_description' => $description,
        ], $status );
        $r->header( 'Cache-Control', 'no-store' );
        $r->header( 'Pragma', 'no-cache' );
        return $r;
    }

    private static function output_json( array $body ): void {
        if ( headers_sent() ) {
            return;
        }
        status_header( 200 );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        header( 'X-Content-Type-Options: nosniff' );
        echo wp_json_encode( $body );
        exit;
    }

    /**
     * Build the current request URL from WP's known home, not from
     * Host / X-Forwarded-Host headers (which an attacker can spoof and use
     * to redirect the consent form to a third-party host).
     */
    /**
     * Set the current WP user from the browser auth cookie when we're in a
     * REST context. WordPress's REST API normally only honors cookie auth if
     * the request also carries an X-WP-Nonce header — which a top-level
     * browser navigation to /wp-json/.../oauth/authorize does NOT. Without
     * this, `is_user_logged_in()` returns false even for a logged-in admin
     * and the OAuth flow bounces back to wp-login.
     *
     * Safe: wp_validate_auth_cookie verifies the HMAC signature on the
     * LOGGED_IN_COOKIE — we're not bypassing auth, just plumbing the user
     * id through when the cookie is genuinely valid.
     */
    private static function maybe_login_from_cookie(): void {
        if ( is_user_logged_in() ) {
            return;
        }
        if ( ! defined( 'LOGGED_IN_COOKIE' ) ) {
            wp_cookie_constants();
        }
        // wp_validate_auth_cookie reads from $_COOKIE when first arg is empty.
        $user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
        if ( $user_id > 0 ) {
            wp_set_current_user( $user_id );
        }
    }

    private static function current_request_url(): string {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path_qs = (string) wp_parse_url( $uri, PHP_URL_PATH );
        $qs      = (string) wp_parse_url( $uri, PHP_URL_QUERY );
        if ( $qs !== '' ) {
            $path_qs .= '?' . $qs;
        }
        // home_url is anchored to siteurl/home (admin-trusted), not the request host.
        return home_url( $path_qs );
    }
}
