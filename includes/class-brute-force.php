<?php
/**
 * Brute-force protection.
 *
 * Tracks failed Authorization-header attempts per IP using transients.
 * Progressive lockout: 5 fails → 5 min, 10 fails → 1 h, 20 fails → 24 h.
 * Successful auth clears the counter for that IP.
 *
 * @package WPCommander
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class BruteForce {

    /** Threshold => lockout duration in seconds. */
    public const LOCKOUT_TIERS = [
        5  => 300,     // 5 min
        10 => 3600,    // 1 h
        20 => 86400,   // 24 h
    ];

    private static function key( string $ip ): string {
        return 'cmcp_bf_' . md5( $ip );
    }

    private static function lock_key( string $ip ): string {
        return 'cmcp_lock_' . md5( $ip );
    }

    /**
     * Check whether the given IP is currently locked out.
     * Returns null if OK, or WP_Error if locked.
     */
    public static function check_lockout( string $ip ): ?\WP_Error {
        if ( $ip === '' ) {
            return null;
        }
        $lock = get_transient( self::lock_key( $ip ) );
        if ( $lock ) {
            $until = (int) $lock - time();
            $resp  = new \WP_Error(
                'cmcp_locked_out',
                sprintf(
                    /* translators: %d: seconds until lockout expires */
                    __( 'Too many failed authentication attempts. Try again in %d seconds.', 'commander-secure-mcp-control' ),
                    max( 1, $until )
                ),
                [ 'status' => 429, 'retry_after' => max( 1, $until ) ]
            );
            return $resp;
        }
        return null;
    }

    /** Record a failed auth attempt; may set a lockout. */
    public static function record_failure( string $ip ): void {
        if ( $ip === '' ) {
            return;
        }
        $key   = self::key( $ip );
        $count = (int) get_transient( $key );
        $count++;
        set_transient( $key, $count, DAY_IN_SECONDS );

        // Find the highest tier crossed.
        $duration = 0;
        foreach ( self::LOCKOUT_TIERS as $threshold => $secs ) {
            if ( $count >= $threshold ) {
                $duration = $secs;
            }
        }
        if ( $duration > 0 ) {
            set_transient( self::lock_key( $ip ), time() + $duration, $duration );
            Logger::log( [
                'method'      => '(brute-force-lockout)',
                'ip'          => $ip,
                'success'     => 0,
                'status_code' => 429,
                'note'        => "IP locked for {$duration}s after {$count} failures",
            ] );

            // Email the admin (rate-limited).
            self::maybe_notify_admin( $ip, $count, $duration );
        }
    }

    /** Successful auth clears the counter (but not an active lockout). */
    public static function record_success( string $ip ): void {
        if ( $ip === '' ) {
            return;
        }
        delete_transient( self::key( $ip ) );
    }

    /** Get current failure count for an IP. */
    public static function failure_count( string $ip ): int {
        return $ip === '' ? 0 : (int) get_transient( self::key( $ip ) );
    }

    /** Manually clear a lockout (admin action). */
    public static function clear( string $ip ): void {
        delete_transient( self::key( $ip ) );
        delete_transient( self::lock_key( $ip ) );
    }

    private static function maybe_notify_admin( string $ip, int $count, int $duration ): void {
        $settings = Plugin::get_settings();
        if ( empty( $settings['notify_admin_email'] ) ) {
            return;
        }
        // Rate-limit: one email per IP per hour.
        $sent_key = 'cmcp_bf_sent_' . md5( $ip );
        if ( get_transient( $sent_key ) ) {
            return;
        }
        set_transient( $sent_key, 1, HOUR_IN_SECONDS );

        $site  = wp_parse_url( home_url(), PHP_URL_HOST );
        $admin = get_option( 'admin_email' );
        if ( ! is_email( $admin ) ) {
            return;
        }
        wp_mail(
            $admin,
            sprintf(
                /* translators: %s: site host */
                __( '[Commander] Brute-force lockout on %s', 'commander-secure-mcp-control' ),
                $site
            ),
            sprintf(
                /* translators: 1: IP address, 2: site host, 3: failure count, 4: lockout seconds, 5: UTC timestamp, 6: audit-log URL */
                __( "Commander locked out IP %1\$s on %2\$s after %3\$d failed authentication attempts.\n\nLockout duration: %4\$d seconds.\nTime: %5\$s UTC\n\nReview the audit log: %6\$s\n\n— Commander", 'commander-secure-mcp-control' ),
                $ip,
                $site,
                $count,
                $duration,
                gmdate( 'Y-m-d H:i:s' ),
                admin_url( 'admin.php?page=cmcp-log' )
            )
        );
    }
}
