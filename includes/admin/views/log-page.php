<?php
/**
 * Audit log view.
 *
 * @var array $rows
 *
 * @package ClaudeMCPSecure
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-template locals, scoped to include().
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Commander — Audit Log', 'commander-secure-mcp-control' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Showing up to 200 most recent entries.', 'commander-secure-mcp-control' ); ?></p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Time (UTC)', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Token', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'IP', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Method', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Tool', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Status', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'OK', 'commander-secure-mcp-control' ); ?></th>
                <th><?php esc_html_e( 'Note', 'commander-secure-mcp-control' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $rows ) ) : ?>
            <tr><td colspan="8"><?php esc_html_e( 'No log entries yet.', 'commander-secure-mcp-control' ); ?></td></tr>
        <?php else : foreach ( $rows as $r ) : ?>
            <tr>
                <td><?php echo esc_html( $r['ts'] ); ?></td>
                <td><?php echo $r['token_id'] ? '#' . (int) $r['token_id'] : '—'; ?></td>
                <td><?php echo esc_html( $r['ip'] ); ?></td>
                <td><?php echo esc_html( $r['method'] ); ?></td>
                <td><?php echo esc_html( $r['tool'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $r['status_code'] ); ?></td>
                <td><?php echo $r['success'] ? '<span style="color:#080">✓</span>' : '<span style="color:#c00">✗</span>'; ?></td>
                <td><?php echo esc_html( $r['note'] ?: '' ); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
