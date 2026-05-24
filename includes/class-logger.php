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

        // Dedup noisy pre-auth invalid_token errors that follow a successful
        // OAuth refresh. The SDK often retries with a now-revoked old token
        // between refresh + cached use; logging every one floods the audit
        // log without adding signal. We allow the first failure per IP per
        // minute through and drop subsequent identical failures.
        if (
            $row['method'] === '(pre-auth)'
            && $row['status_code'] === 401
            && (string) ( $row['note'] ?? '' ) === 'cmcp_invalid_token'
            && self::recent_oauth_success_from_same_ip( (string) $row['ip'] )
        ) {
            $dedup_key = 'cmcp_dedup_' . md5( (string) $row['ip'] );
            if ( get_transient( $dedup_key ) ) {
                return; // suppress
            }
            set_transient( $dedup_key, 1, MINUTE_IN_SECONDS );
        }

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
     * Was there a successful OAuth refresh from this IP within the last 60s?
     * Used by log() to suppress the cascade of "stale-token retry" 401s.
     */
    private static function recent_oauth_success_from_same_ip( string $ip ): bool {
        if ( $ip === '' ) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . Plugin::TABLE_AUDIT;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables.
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table}
              WHERE ip = %s AND success = 1
                AND method IN ('oauth/token', 'oauth/token/refresh')
                AND ts > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
              LIMIT 1",
            $ip
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $exists === 1;
    }

    /**
     * Recent rows for the admin UI with filter + pagination.
     *
     * @param array{
     *   q?: string,
     *   status?: string,         // 'ok' | 'fail' | ''
     *   method?: string,
     *   tool?: string,
     *   ip?: string,
     *   from?: string,           // 'YYYY-MM-DD'
     *   to?: string,
     *   per_page?: int,
     *   page?: int,
     * } $filters
     * @return array{ items:array, total:int, page:int, per_page:int }
     */
    public static function recent( int $limit_legacy = 100, array $filters = [] ): array {
        global $wpdb;

        $per_page = isset( $filters['per_page'] ) ? max( 1, min( 500, (int) $filters['per_page'] ) ) : $limit_legacy;
        $page     = isset( $filters['page'] )     ? max( 1, (int) $filters['page'] ) : 1;
        $offset   = ( $page - 1 ) * $per_page;

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'success = %d';
            $params[] = $filters['status'] === 'ok' ? 1 : 0;
        }
        if ( ! empty( $filters['method'] ) ) {
            $where[]  = 'method LIKE %s';
            $params[] = '%' . $wpdb->esc_like( (string) $filters['method'] ) . '%';
        }
        if ( ! empty( $filters['tool'] ) ) {
            $where[]  = 'tool LIKE %s';
            $params[] = '%' . $wpdb->esc_like( (string) $filters['tool'] ) . '%';
        }
        if ( ! empty( $filters['ip'] ) ) {
            $where[]  = 'ip = %s';
            $params[] = (string) $filters['ip'];
        }
        if ( ! empty( $filters['from'] ) ) {
            $where[]  = 'ts >= %s';
            $params[] = (string) $filters['from'] . ' 00:00:00';
        }
        if ( ! empty( $filters['to'] ) ) {
            $where[]  = 'ts <= %s';
            $params[] = (string) $filters['to'] . ' 23:59:59';
        }
        if ( ! empty( $filters['q'] ) ) {
            $where[]  = '(method LIKE %s OR tool LIKE %s OR note LIKE %s OR ip LIKE %s)';
            $like     = '%' . $wpdb->esc_like( (string) $filters['q'] ) . '%';
            array_push( $params, $like, $like, $like, $like );
        }

        $where_sql = implode( ' AND ', $where );
        $table     = $wpdb->prefix . Plugin::TABLE_AUDIT;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; WHERE clause built from $wpdb->prepare-able placeholders only.
        $total = (int) $wpdb->get_var(
            $params
                ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params )
                : "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
        );

        $page_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
                ...$page_params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return [
            'items'    => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }
}
