<?php
/**
 * Audit log view.
 *
 * @var array $rows
 * @var int   $total
 * @var int   $page
 * @var int   $per
 * @var int   $pages
 *
 * @package WPCommander
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-template locals, scoped to include().
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filter form values are display-only routing reads, capability check above.

$f = [
    'q'      => isset( $_GET['q'] )      ? sanitize_text_field( wp_unslash( $_GET['q'] ) )      : '',
    'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) )        : '',
    'method' => isset( $_GET['method'] ) ? sanitize_text_field( wp_unslash( $_GET['method'] ) ) : '',
    'tool'   => isset( $_GET['tool'] )   ? sanitize_text_field( wp_unslash( $_GET['tool'] ) )   : '',
    'ip'     => isset( $_GET['ip'] )     ? sanitize_text_field( wp_unslash( $_GET['ip'] ) )     : '',
    'from'   => isset( $_GET['from'] )   ? sanitize_text_field( wp_unslash( $_GET['from'] ) )   : '',
    'to'     => isset( $_GET['to'] )     ? sanitize_text_field( wp_unslash( $_GET['to'] ) )     : '',
];

$has_filter = (bool) array_filter( $f );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Commander — Audit Log', 'mcp-for-claude' ); ?></h1>
    <p class="description">
        <?php
        printf(
            /* translators: 1: showing count, 2: total filtered count, 3: page n, 4: of pages */
            esc_html__( 'Showing %1$d of %2$d entries · page %3$d of %4$d', 'mcp-for-claude' ),
            (int) count( $rows ),
            (int) $total,
            (int) $page,
            (int) $pages
        );
        ?>
    </p>

    <form method="get" action="" style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin:14px 0">
        <input type="hidden" name="page" value="cmcp-log" />
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end">
            <label>
                <span style="display:block;font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:0.3px"><?php esc_html_e( 'Search', 'mcp-for-claude' ); ?></span>
                <input type="search" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="<?php esc_attr_e( 'method · tool · note · ip', 'mcp-for-claude' ); ?>" style="width:100%" />
            </label>
            <label>
                <span style="display:block;font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:0.3px"><?php esc_html_e( 'Status', 'mcp-for-claude' ); ?></span>
                <select name="status">
                    <option value=""    <?php selected( $f['status'], '' ); ?>><?php esc_html_e( 'any', 'mcp-for-claude' ); ?></option>
                    <option value="ok"  <?php selected( $f['status'], 'ok' ); ?>><?php esc_html_e( 'success only', 'mcp-for-claude' ); ?></option>
                    <option value="fail"<?php selected( $f['status'], 'fail' ); ?>><?php esc_html_e( 'failure only', 'mcp-for-claude' ); ?></option>
                </select>
            </label>
            <label>
                <span style="display:block;font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:0.3px"><?php esc_html_e( 'Method', 'mcp-for-claude' ); ?></span>
                <input type="text" name="method" value="<?php echo esc_attr( $f['method'] ); ?>" placeholder="oauth/token" style="width:100%" />
            </label>
            <label>
                <span style="display:block;font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:0.3px"><?php esc_html_e( 'Tool', 'mcp-for-claude' ); ?></span>
                <input type="text" name="tool" value="<?php echo esc_attr( $f['tool'] ); ?>" placeholder="posts.list" style="width:100%" />
            </label>
            <label>
                <span style="display:block;font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:0.3px"><?php esc_html_e( 'IP', 'mcp-for-claude' ); ?></span>
                <input type="text" name="ip" value="<?php echo esc_attr( $f['ip'] ); ?>" placeholder="203.0.113.5" style="width:100%" />
            </label>
            <label>
                <span style="display:block;font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:0.3px"><?php esc_html_e( 'From', 'mcp-for-claude' ); ?></span>
                <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" style="width:100%" />
            </label>
            <label>
                <span style="display:block;font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:0.3px"><?php esc_html_e( 'To', 'mcp-for-claude' ); ?></span>
                <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" style="width:100%" />
            </label>
            <div>
                <button type="submit" class="button button-primary" style="width:100%"><?php esc_html_e( 'Filter', 'mcp-for-claude' ); ?></button>
            </div>
        </div>
        <?php if ( $has_filter ) : ?>
            <p style="margin:8px 0 0;font-size:12px"><a href="<?php echo esc_url( admin_url( 'admin.php?page=cmcp-log' ) ); ?>"><?php esc_html_e( '← clear filters', 'mcp-for-claude' ); ?></a></p>
        <?php endif; ?>
    </form>

    <div style="margin:10px 0;display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div style="font-size:12px;color:#646970">
            <?php esc_html_e( 'Rows older than retention setting are auto-pruned daily.', 'mcp-for-claude' ); ?>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
            <input type="hidden" name="action" value="cmcp_audit_export" />
            <?php wp_nonce_field( 'cmcp_audit_export' ); ?>
            <button type="submit" class="button">⬇ <?php esc_html_e( 'Export CSV (last 50 000 rows)', 'mcp-for-claude' ); ?></button>
        </form>
    </div>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Time (UTC)', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Token', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'IP', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Method', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Tool', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Status', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'OK', 'mcp-for-claude' ); ?></th>
                <th><?php esc_html_e( 'Note', 'mcp-for-claude' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $rows ) ) : ?>
            <tr><td colspan="8"><?php $has_filter ? esc_html_e( 'No matches for those filters.', 'mcp-for-claude' ) : esc_html_e( 'No log entries yet.', 'mcp-for-claude' ); ?></td></tr>
        <?php else : foreach ( $rows as $r ) : ?>
            <tr>
                <td><code style="font-size:11px"><?php echo esc_html( $r['ts'] ); ?></code></td>
                <td><?php echo $r['token_id'] ? '#' . (int) $r['token_id'] : '<span style="color:#a7aaad">—</span>'; ?></td>
                <td><code style="font-size:11px"><?php echo esc_html( $r['ip'] ); ?></code></td>
                <td><?php echo esc_html( $r['method'] ); ?></td>
                <td><?php echo $r['tool'] ? '<code>' . esc_html( $r['tool'] ) . '</code>' : '<span style="color:#a7aaad">—</span>'; ?></td>
                <td><?php echo esc_html( $r['status_code'] ); ?></td>
                <td><?php echo $r['success'] ? '<span style="color:#080">✓</span>' : '<span style="color:#c00">✗</span>'; ?></td>
                <td style="color:#646970"><?php echo esc_html( $r['note'] ?: '' ); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ( $pages > 1 ) : ?>
        <div class="tablenav" style="margin-top:14px">
            <div class="tablenav-pages">
                <?php
                $base = add_query_arg( array_filter( $f ), admin_url( 'admin.php?page=cmcp-log' ) );
                echo wp_kses_post( paginate_links( [
                    'base'      => add_query_arg( 'p', '%#%', $base ),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $pages,
                    'prev_text' => '←',
                    'next_text' => '→',
                ] ) );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
