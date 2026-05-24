<?php
/**
 * GitHub release auto-updater.
 *
 * Polls https://api.github.com/repos/<repo>/releases/latest at most once per
 * 12 hours (cached in a site transient). When a newer release tag than the
 * installed CMCP_VERSION is found, exposes it to WordPress's update_plugins
 * transient so the standard "Update available" banner + one-click update
 * flow takes over.
 *
 * Handles the GitHub zipball folder-name quirk by renaming the extracted
 * source folder back to the plugin slug during upgrade.
 *
 * Once the plugin is published on WordPress.org, this checker yields to
 * .org's own update channel automatically — .org's filter runs after ours
 * and overrides any update info it sees.
 *
 * @package WPCommander
 */

namespace CMCP;

defined( 'ABSPATH' ) || exit;

final class GithubUpdater {

    /** GitHub repo slug — owner/name. */
    public const REPO = 'TaherSayed/commander-secure-mcp-control';

    /** Transient cache key for the latest-release payload. */
    public const TRANSIENT_KEY = 'cmcp_gh_release_v1';

    /** Cache TTL for a successful API response. */
    public const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS;

    /** Shorter cache TTL on failures so we don't hammer the API. */
    public const FAILURE_TTL = HOUR_IN_SECONDS;

    public static function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ self::class, 'inject_update' ] );
        add_filter( 'plugins_api',                           [ self::class, 'plugins_api' ], 10, 3 );
        add_filter( 'upgrader_source_selection',             [ self::class, 'rename_source' ], 10, 4 );
        add_filter( 'plugin_row_meta',                       [ self::class, 'plugin_row_meta' ], 10, 2 );
    }

    /**
     * Inject our update record into WP's update_plugins transient.
     *
     * @param mixed $transient The WP update_plugins transient.
     * @return mixed
     */
    public static function inject_update( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            return $transient;
        }
        $release = self::get_latest_release();
        if ( ! $release ) {
            return $transient;
        }
        $latest = self::tag_to_version( (string) ( $release['tag_name'] ?? '' ) );
        if ( $latest === '' || version_compare( $latest, CMCP_VERSION, '<=' ) ) {
            return $transient;
        }
        $download = self::find_zip_asset( $release );
        if ( ! $download ) {
            $download = (string) ( $release['zipball_url'] ?? '' );
        }
        if ( ! $download ) {
            return $transient;
        }

        $plugin_file = CMCP_BASENAME;
        $slug        = dirname( $plugin_file );

        $obj = (object) [
            'id'            => self::REPO,
            'slug'          => $slug,
            'plugin'        => $plugin_file,
            'new_version'   => $latest,
            'url'           => 'https://github.com/' . self::REPO,
            'package'       => $download,
            'tested'        => '7.0',
            'requires'      => '6.2',
            'requires_php'  => '8.0',
            'icons'         => [],
            'banners'       => [],
            'compatibility' => new \stdClass(),
        ];

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }
        $transient->response[ $plugin_file ] = $obj;

        return $transient;
    }

    /**
     * Provide "View details" content for the upgrade modal.
     */
    public static function plugins_api( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== dirname( CMCP_BASENAME ) ) {
            return $result;
        }
        $release = self::get_latest_release();
        if ( ! $release ) {
            return $result;
        }
        $body = (string) ( $release['body'] ?? '' );

        $info = (object) [
            'name'          => 'Commander — Secure MCP Control',
            'slug'          => dirname( CMCP_BASENAME ),
            'version'       => self::tag_to_version( (string) ( $release['tag_name'] ?? '' ) ),
            'author'        => '<a href="https://hbs-it-gmbh.de">Taher Sayed · HBS IT GmbH</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => '6.2',
            'tested'        => '7.0',
            'requires_php'  => '8.0',
            'last_updated'  => (string) ( $release['published_at'] ?? '' ),
            'download_link' => self::find_zip_asset( $release ) ?: (string) ( $release['zipball_url'] ?? '' ),
            'sections'      => [
                'description' => '<p>' . esc_html__( 'Secure MCP server for WordPress: bearer-token + OAuth 2.1 with PKCE, scope + capability dual-gate, audit log, brute-force lockout, 25 built-in tools.', 'commander-secure-mcp-control' ) . '</p><p><a href="https://github.com/' . self::REPO . '" target="_blank" rel="noopener">' . esc_html__( 'Full README on GitHub', 'commander-secure-mcp-control' ) . '</a></p>',
                'changelog'   => self::release_body_to_html( $body ),
            ],
            'banners'       => [],
            'icons'         => [],
        ];
        return $info;
    }

    /**
     * GitHub zipballs extract to `repo-tag-sha/`; WP expects the original
     * slug folder. Rename the extracted source so install_plugin doesn't
     * orphan the old folder.
     */
    public static function rename_source( $source, $remote_source, $upgrader, $hook_extra = null ) {
        if ( ! is_object( $upgrader ) ) {
            return $source;
        }
        // Identify our plugin from hook_extra (preferred) or the skin.
        $plugin = '';
        if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) ) {
            $plugin = (string) $hook_extra['plugin'];
        } elseif ( isset( $upgrader->skin->plugin ) ) {
            $plugin = (string) $upgrader->skin->plugin;
        }
        if ( $plugin !== CMCP_BASENAME ) {
            return $source;
        }

        $slug = dirname( CMCP_BASENAME );
        $new  = trailingslashit( dirname( $source ) ) . $slug;
        if ( untrailingslashit( $source ) === untrailingslashit( $new ) ) {
            return $source;
        }

        global $wp_filesystem;
        if ( $wp_filesystem && $wp_filesystem->move( $source, $new, true ) ) {
            return trailingslashit( $new );
        }
        return $source;
    }

    /**
     * Add a "GitHub" link to the Plugins page row.
     */
    public static function plugin_row_meta( $links, $file ) {
        if ( $file !== CMCP_BASENAME ) {
            return $links;
        }
        $links[] = '<a href="https://github.com/' . esc_attr( self::REPO ) . '" target="_blank" rel="noopener">GitHub</a>';
        $links[] = '<a href="https://github.com/' . esc_attr( self::REPO ) . '/issues" target="_blank" rel="noopener">' . esc_html__( 'Report issue', 'commander-secure-mcp-control' ) . '</a>';
        return $links;
    }

    /* ---------------- Internals ---------------- */

    /**
     * Fetch + cache the latest-release JSON. Returns null on error.
     */
    private static function get_latest_release(): ?array {
        $cached = get_site_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) ) {
            if ( ! empty( $cached['_failed'] ) ) {
                return null;
            }
            return $cached;
        }

        $resp = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            [
                'timeout' => 8,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Commander/' . CMCP_VERSION,
                ],
            ]
        );
        if ( is_wp_error( $resp ) ) {
            set_site_transient( self::TRANSIENT_KEY, [ '_failed' => true ], self::FAILURE_TTL );
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) {
            set_site_transient( self::TRANSIENT_KEY, [ '_failed' => true ], self::FAILURE_TTL );
            return null;
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) ) {
            set_site_transient( self::TRANSIENT_KEY, [ '_failed' => true ], self::FAILURE_TTL );
            return null;
        }
        set_site_transient( self::TRANSIENT_KEY, $body, self::TRANSIENT_TTL );
        return $body;
    }

    /** Tag `v1.5.1` or `1.5.1` → `1.5.1`. */
    private static function tag_to_version( string $tag ): string {
        $tag = trim( $tag );
        if ( $tag !== '' && ( $tag[0] === 'v' || $tag[0] === 'V' ) ) {
            $tag = substr( $tag, 1 );
        }
        return $tag;
    }

    /** Find the first attached `.zip` asset's download URL. */
    private static function find_zip_asset( array $release ): ?string {
        foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
            $name = strtolower( (string) ( $asset['name'] ?? '' ) );
            $url  = (string) ( $asset['browser_download_url'] ?? '' );
            if ( $url !== '' && substr( $name, -4 ) === '.zip' ) {
                return $url;
            }
        }
        return null;
    }

    /**
     * Very lightweight Markdown → HTML for the changelog modal section.
     * Handles ## headings, `-` bullets, blank-line paragraphs, and **bold**.
     */
    private static function release_body_to_html( string $md ): string {
        $lines   = preg_split( '/\r?\n/', $md );
        $out     = '';
        $in_list = false;
        foreach ( (array) $lines as $line ) {
            if ( preg_match( '/^##+\s+(.+)$/', $line, $m ) ) {
                if ( $in_list ) { $out .= '</ul>'; $in_list = false; }
                $out .= '<h4>' . esc_html( $m[1] ) . '</h4>';
            } elseif ( preg_match( '/^[-*]\s+(.+)$/', $line, $m ) ) {
                if ( ! $in_list ) { $out .= '<ul>'; $in_list = true; }
                $out .= '<li>' . self::md_inline( $m[1] ) . '</li>';
            } elseif ( trim( $line ) === '' ) {
                if ( $in_list ) { $out .= '</ul>'; $in_list = false; }
            } else {
                if ( $in_list ) { $out .= '</ul>'; $in_list = false; }
                $out .= '<p>' . self::md_inline( $line ) . '</p>';
            }
        }
        if ( $in_list ) {
            $out .= '</ul>';
        }
        return $out !== '' ? $out : '<p>' . esc_html__( 'See the GitHub release for details.', 'commander-secure-mcp-control' ) . '</p>';
    }

    private static function md_inline( string $s ): string {
        $s = esc_html( $s );
        $s = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s );
        $s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
        return (string) $s;
    }
}
