<?php
/**
 * Per-token rate limiter using WP transients.
 *
 * A simple fixed-window-per-minute counter. For higher precision use Redis or
 * a dedicated store; this is enough to stop runaway loops and casual abuse.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

    /**
     * Per-token rate limit. Returns [ ok, remaining, reset_in_seconds ].
     */
    public static function check( int $token_id, int $limit_per_min ): array {
        if ( $limit_per_min <= 0 ) {
            return [ true, PHP_INT_MAX, 0 ];
        }

        $minute = (int) floor( time() / 60 );
        $key    = "cmcp_rl_{$token_id}_{$minute}";
        $count  = (int) get_transient( $key );
        $count++;
        set_transient( $key, $count, 75 ); // a bit over a minute, then expire

        $remaining = max( 0, $limit_per_min - $count );
        $reset_in  = 60 - ( time() % 60 );

        return [ $count <= $limit_per_min, $remaining, $reset_in ];
    }

    /**
     * Per-IP rate limit for unauthenticated endpoints (oauth/register,
     * oauth/authorize POST, /.well-known/*). Bucket is keyed by IP + scope
     * so different endpoints don't share a counter.
     *
     * @return bool true if request is allowed, false if it should be denied.
     */
    public static function by_ip( string $scope, int $limit_per_min ): bool {
        if ( $limit_per_min <= 0 ) {
            return true;
        }
        $ip = Security::client_ip();
        if ( $ip === '' ) {
            return true; // can't enforce, fail open
        }
        $minute = (int) floor( time() / 60 );
        $key    = 'cmcp_rli_' . substr( hash( 'sha256', $scope . '|' . $ip ), 0, 24 ) . "_{$minute}";
        $count  = (int) get_transient( $key );
        $count++;
        set_transient( $key, $count, 75 );
        return $count <= $limit_per_min;
    }
}
