<?php
/**
 * Bearer-token authentication for the MCP endpoint.
 *
 * Tokens are stored as SHA-256 hashes with a 12-char public prefix used only
 * for lookup/display. Comparison is constant-time. Tokens never appear in the
 * audit log in plaintext.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class Auth {

    public const SCOPE_READ  = 'read';
    public const SCOPE_WRITE = 'write';
    public const SCOPE_ADMIN = 'admin';

    /** All known scopes, in increasing power. */
    public const SCOPES = [ self::SCOPE_READ, self::SCOPE_WRITE, self::SCOPE_ADMIN ];

    /**
     * Issue a brand-new token. Returns the plaintext token (shown ONCE to admin)
     * plus the stored row ID. The plaintext is never persisted.
     *
     * @return array{token:string,row_id:int,prefix:string}
     */
    public static function issue_token( array $args ): array {
        global $wpdb;

        $args = wp_parse_args( $args, [
            'label'        => 'MCP token',
            'scopes'       => [ self::SCOPE_READ ],
            'user_id'      => 0,
            'ip_allowlist' => [],
            'expires_in'   => 0, // seconds; 0 = never
        ] );

        // 32 random bytes -> 64 hex chars. Prefix "cmcp_" is for visual ID.
        $raw    = bin2hex( random_bytes( 32 ) );
        $token  = 'cmcp_' . $raw;
        $prefix = substr( $token, 0, 12 );
        $hash   = hash( 'sha256', $token );

        $scopes = array_values( array_intersect( self::SCOPES, (array) $args['scopes'] ) );
        if ( empty( $scopes ) ) {
            $scopes = [ self::SCOPE_READ ];
        }

        $expires = null;
        if ( ! empty( $args['expires_in'] ) && (int) $args['expires_in'] > 0 ) {
            $expires = gmdate( 'Y-m-d H:i:s', time() + (int) $args['expires_in'] );
        }

        $ip_list = array_filter( array_map( 'trim', (array) $args['ip_allowlist'] ) );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->insert(
            $wpdb->prefix . Plugin::TABLE_TOKENS,
            [
                'label'        => substr( sanitize_text_field( (string) $args['label'] ), 0, 120 ),
                'token_hash'   => $hash,
                'prefix'       => $prefix,
                'scopes'       => implode( ',', $scopes ),
                'user_id'      => (int) $args['user_id'] ?: null,
                'ip_allowlist' => $ip_list ? wp_json_encode( array_values( $ip_list ) ) : null,
                'expires_at'   => $expires,
                'created_at'   => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return [
            'token'  => $token,
            'row_id' => (int) $wpdb->insert_id,
            'prefix' => $prefix,
        ];
    }

    public static function revoke_token( int $row_id ): bool {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables.
        $result = (bool) $wpdb->update(
            $wpdb->prefix . Plugin::TABLE_TOKENS,
            [ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => $row_id ],
            [ '%s' ],
            [ '%d' ]
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $result;
    }

    /**
     * Permanently delete a token row. Unlike revoke, this wipes the entry.
     * Prefer revoke for compliance / audit trail.
     */
    public static function delete_token( int $row_id ): bool {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables.
        $result = (bool) $wpdb->delete(
            $wpdb->prefix . Plugin::TABLE_TOKENS,
            [ 'id' => $row_id ],
            [ '%d' ]
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $result;
    }

    /**
     * Rotate a token in place — issue a new one with the same label, scopes,
     * user binding, IP allowlist and remaining expiry, then revoke the old.
     * Returns the new plaintext token (shown ONCE to admin) and new row id.
     */
    public static function rotate_token( int $old_id ): ?array {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Plugin::TABLE_TOKENS . " WHERE id = %d",
                $old_id
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( ! $row ) {
            return null;
        }

        $expires_in = 0;
        if ( ! empty( $row['expires_at'] ) ) {
            $remaining  = strtotime( $row['expires_at'] . ' UTC' ) - time();
            $expires_in = $remaining > 0 ? $remaining : 0;
        }

        $ip_list = [];
        if ( ! empty( $row['ip_allowlist'] ) ) {
            $decoded = json_decode( (string) $row['ip_allowlist'], true );
            $ip_list = is_array( $decoded ) ? $decoded : [];
        }

        $new = self::issue_token( [
            'label'        => (string) $row['label'] . ' (rotated)',
            'scopes'       => array_filter( array_map( 'trim', explode( ',', (string) $row['scopes'] ) ) ),
            'user_id'      => (int) $row['user_id'],
            'ip_allowlist' => $ip_list,
            'expires_in'   => $expires_in,
        ] );
        self::revoke_token( $old_id );
        return $new;
    }

    public static function list_tokens(): array {
        global $wpdb;
        $audit = $wpdb->prefix . Plugin::TABLE_AUDIT;
        $toks  = $wpdb->prefix . Plugin::TABLE_TOKENS;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables.
        $rows = $wpdb->get_results(
            "SELECT t.id, t.label, t.prefix, t.scopes, t.user_id, t.expires_at, t.last_used_at, t.created_at, t.revoked_at,
                    ( SELECT a.ip FROM {$audit} a WHERE a.token_id = t.id ORDER BY a.id DESC LIMIT 1 ) AS last_ip,
                    ( SELECT COUNT(*) FROM {$audit} a WHERE a.token_id = t.id AND a.ts > DATE_SUB(NOW(), INTERVAL 7 DAY) ) AS calls_7d
             FROM {$toks} t
             ORDER BY t.id DESC",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Derived status for the admin UI.
     * Returns: revoked | expired | idle | stale | active.
     */
    public static function token_status( array $row ): string {
        if ( ! empty( $row['revoked_at'] ) ) {
            return 'revoked';
        }
        if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
            return 'expired';
        }
        if ( empty( $row['last_used_at'] ) ) {
            return 'idle';
        }
        if ( strtotime( $row['last_used_at'] . ' UTC' ) < time() - 30 * DAY_IN_SECONDS ) {
            return 'stale';
        }
        return 'active';
    }

    /**
     * Authenticate the request via Authorization: Bearer.
     *
     * Recognises two kinds of bearer tokens:
     *   - Personal Access Tokens issued via admin UI (`cmcp_<hex>`)
     *   - OAuth-issued access tokens (`cmcp_oat_<hex>`) from the OAuth flow
     *
     * @return array{ok:bool,token_id?:int,scopes?:array,user_id?:int,error?:\WP_Error}
     */
    public static function authenticate( \WP_REST_Request $request ): array {
        $ip = Security::client_ip();

        // Brute-force lockout check FIRST — short-circuit before any DB lookup.
        if ( $lock = BruteForce::check_lockout( $ip ) ) {
            return [ 'ok' => false, 'error' => $lock ];
        }

        $header = (string) $request->get_header( 'authorization' );
        if ( $header === '' ) {
            BruteForce::record_failure( $ip );
            return [ 'ok' => false, 'error' => self::err( 'cmcp_no_auth', 'Missing Authorization header.', 401 ) ];
        }
        if ( ! preg_match( '/^Bearer\s+(\S+)$/i', $header, $m ) ) {
            BruteForce::record_failure( $ip );
            return [ 'ok' => false, 'error' => self::err( 'cmcp_bad_auth', 'Authorization must be Bearer <token>.', 401 ) ];
        }
        $presented = trim( $m[1] );
        if ( strlen( $presented ) < 16 || strlen( $presented ) > 256 ) {
            BruteForce::record_failure( $ip );
            return [ 'ok' => false, 'error' => self::err( 'cmcp_bad_token_format', 'Malformed token.', 401 ) ];
        }

        // Route by prefix.
        $result = str_starts_with( $presented, 'cmcp_oat_' )
            ? OAuth::verify_access_token( $presented )
            : self::authenticate_pat( $presented, $request );

        if ( empty( $result['ok'] ) ) {
            BruteForce::record_failure( $ip );
        } else {
            BruteForce::record_success( $ip );
        }
        return $result;
    }

    /**
     * Authenticate a Personal Access Token (the kind issued in the admin UI).
     */
    private static function authenticate_pat( string $presented, \WP_REST_Request $request ): array {
        $hash = hash( 'sha256', $presented );

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Plugin::TABLE_TOKENS . " WHERE token_hash = %s",
                $hash
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            // Always do a hash_equals against a dummy to keep timing similar.
            hash_equals( str_repeat( 'a', 64 ), $hash );
            return [ 'ok' => false, 'error' => self::err( 'cmcp_invalid_token', 'Invalid token.', 401 ) ];
        }

        // Constant-time confirm (defense in depth — DB compared by index, but be paranoid).
        if ( ! hash_equals( (string) $row['token_hash'], $hash ) ) {
            return [ 'ok' => false, 'error' => self::err( 'cmcp_invalid_token', 'Invalid token.', 401 ) ];
        }

        if ( ! empty( $row['revoked_at'] ) ) {
            return [ 'ok' => false, 'error' => self::err( 'cmcp_revoked', 'Token revoked.', 401 ) ];
        }

        if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
            return [ 'ok' => false, 'error' => self::err( 'cmcp_expired', 'Token expired.', 401 ) ];
        }

        // IP allowlist (optional).
        if ( ! empty( $row['ip_allowlist'] ) ) {
            $list = json_decode( (string) $row['ip_allowlist'], true );
            if ( is_array( $list ) && $list ) {
                $ip = Security::client_ip();
                if ( ! in_array( $ip, $list, true ) ) {
                    return [ 'ok' => false, 'error' => self::err( 'cmcp_ip_denied', 'IP not allowed for this token.', 403 ) ];
                }
            }
        }

        // Set the WP current user (so capability checks work in tools).
        if ( ! empty( $row['user_id'] ) ) {
            wp_set_current_user( (int) $row['user_id'] );
        }

        // Touch last_used_at (fire-and-forget; no error handling needed).
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->update(
            $wpdb->prefix . Plugin::TABLE_TOKENS,
            [ 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => (int) $row['id'] ],
            [ '%s' ],
            [ '%d' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return [
            'ok'       => true,
            'token_id' => (int) $row['id'],
            'scopes'   => array_filter( array_map( 'trim', explode( ',', (string) $row['scopes'] ) ) ),
            'user_id'  => (int) ( $row['user_id'] ?? 0 ),
        ];
    }

    public static function has_scope( array $auth, string $needed ): bool {
        if ( empty( $auth['scopes'] ) ) {
            return false;
        }
        // 'admin' grants everything; 'write' grants 'read'.
        $set = (array) $auth['scopes'];
        if ( in_array( self::SCOPE_ADMIN, $set, true ) ) {
            return true;
        }
        if ( $needed === self::SCOPE_READ ) {
            return in_array( self::SCOPE_READ, $set, true ) || in_array( self::SCOPE_WRITE, $set, true );
        }
        return in_array( $needed, $set, true );
    }

    public static function err( string $code, string $msg, int $status ): \WP_Error {
        return new \WP_Error( $code, $msg, [ 'status' => $status ] );
    }
}
