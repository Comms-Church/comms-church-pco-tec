<?php
/**
 * CCTEC_Updater
 *
 * Checks GitHub Releases for newer versions of this plugin and surfaces them
 * in the standard WordPress Plugins / Updates screens.
 * Direct fork of Comms_Church_PCO_Updater — only the repo slug and class name differ.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Updater {

    const GITHUB_REPO = 'Comms-Church/comms-church-pco-tec';
    const CACHE_KEY   = 'cctec_github_release_cache';
    const CACHE_TTL   = DAY_IN_SECONDS;

    private string $plugin_file;
    private string $plugin_slug;
    private string $version;

    public function __construct( string $plugin_file, string $version ) {
        $this->plugin_file = plugin_basename( $plugin_file );
        $this->plugin_slug = dirname( $this->plugin_file );
        $this->version     = $version;

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',   [ $this, 'plugins_api_handler' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
        add_filter( 'plugin_row_meta', [ $this, 'add_check_now_link' ], 10, 2 );
        add_action( 'admin_init',    [ $this, 'maybe_manual_check' ] );
    }

    // ── Core update check ─────────────────────────────────────────────────────

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->get_latest_release();
        if ( ! $release || empty( $release['tag_name'] ) ) return $transient;

        $remote = $this->normalize_version( $release['tag_name'] );

        if ( version_compare( $remote, $this->version, '>' ) ) {
            $zip = $this->get_zip_asset_url( $release );
            if ( ! $zip ) return $transient;

            $item               = new stdClass();
            $item->slug         = $this->plugin_slug;
            $item->plugin       = $this->plugin_file;
            $item->new_version  = $remote;
            $item->url          = 'https://github.com/' . self::GITHUB_REPO;
            $item->package      = $zip;
            $item->tested       = get_bloginfo( 'version' );
            $item->icons        = [];
            $item->banners      = [];

            $transient->response[ $this->plugin_file ] = $item;
        } else {
            unset( $transient->response[ $this->plugin_file ] );
        }

        return $transient;
    }

    // ── Plugin info popup ─────────────────────────────────────────────────────

    public function plugins_api_handler( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) return $result;

        $release = $this->get_latest_release();
        if ( ! $release ) return $result;

        $info               = new stdClass();
        $info->name         = 'Comms.Church — PCO Events → The Events Calendar';
        $info->slug         = $this->plugin_slug;
        $info->version      = $this->normalize_version( $release['tag_name'] );
        $info->author       = '<a href="https://comms.church">Comms.Church</a>';
        $info->homepage     = 'https://github.com/' . self::GITHUB_REPO;
        $info->sections     = [
            'description' => wpautop( $release['body'] ?? 'See GitHub releases for changelog.' ),
            'changelog'   => wpautop( $release['body'] ?? '' ),
        ];
        $info->download_link = $this->get_zip_asset_url( $release );

        return $info;
    }

    // ── Fix unzip folder name ─────────────────────────────────────────────────

    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        global $wp_filesystem;

        if ( ! is_object( $upgrader ) || empty( $hook_extra['plugin'] ) ) return $source;
        if ( $hook_extra['plugin'] !== $this->plugin_file ) return $source;

        $desired = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

        if ( trailingslashit( $source ) === $desired ) return $source;
        if ( $wp_filesystem->move( $source, $desired ) ) return $desired;

        return $source;
    }

    // ── Manual "Check for updates" link ───────────────────────────────────────

    public function add_check_now_link( $links, $plugin_file ): array {
        if ( $plugin_file !== $this->plugin_file ) return $links;

        $url = wp_nonce_url(
            add_query_arg( [ 'cctec_check_update' => 1 ], admin_url( 'plugins.php' ) ),
            'cctec_check_update'
        );
        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'comms-church-pco-tec' ) . '</a>';
        return $links;
    }

    public function maybe_manual_check(): void {
        if ( empty( $_GET['cctec_check_update'] ) ) return;
        if ( ! current_user_can( 'update_plugins' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cctec_check_update' ) ) return;

        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );
        wp_safe_redirect( admin_url( 'plugins.php?cctec_checked=1' ) );
        exit;
    }

    // ── GitHub API ────────────────────────────────────────────────────────────

    private function get_latest_release(): array|false {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) return $cached;

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
            [
                'headers' => [ 'Accept' => 'application/vnd.github+json' ],
                'timeout' => 10,
            ]
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) return false;

        set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
        return $data;
    }

    private function get_zip_asset_url( array $release ): string|false {
        foreach ( $release['assets'] ?? [] as $asset ) {
            if ( str_ends_with( $asset['name'] ?? '', '.zip' ) ) {
                return $asset['browser_download_url'];
            }
        }
        return false;
    }

    private function normalize_version( string $tag ): string {
        return ltrim( $tag, 'vV' );
    }
}
