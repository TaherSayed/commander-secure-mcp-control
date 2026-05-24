<?php
/**
 * Audit logger - persists per-request info to the audit table.
 *
 * @package ClaudeMCPSecure
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class Logger {

    public static function log( array $row ): void {
        $settings = Plugin::get_settings();
        if ( empty( $settings['enable_audit_log'] ) ) {
            return;
        }

        global $wpdb;
        $defaults = [
            'ts'          => gmdate( 'Y-m-d H:i:s' ),
            'token_id'    => null,
            'ip'          => Security::client_ip(),
            'method'      => null,
            'tool'        => null,
            'success'     => 0,
            'status_code' => 0,
            'note'        => null,
        ];
        $row = array_intersect_key( array_merge( $defaults, $row ), $defaults );

        // Truncate to schema limits and strip control chars from note.
        if ( ! empty( $row['note'] ) ) {
            $row['note'] = mb_substr( preg_replace( '/[\x00-\x1F\x7F]/u', ' ', (string) $row['note'] ), 0, 2000 );
        }
        if ( ! empty( $row['method'] ) ) {
            $row['method'] = substr( (string) $row['method'], 0, 120 );
        }
        if ( ! empty( $row['tool'] ) ) {
            $row['tool'] = substr( (string) $row['tool'], 0, 120 );
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; table names cannot be prepared, caching not applicable.
        $wpdb->insert(
            $wpdb->prefix . Plugin::TABLE_AUDIT,
            $row,
            [ '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Recent rows for the admin UI. Capped at $limit.
     */
    public static function recent( int $limit = 100 ): array {
        global $wpdb;
        $limit = max( 1, min( 500, $limit ) );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Plugin::TABLE_AUDIT . " ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }
}
