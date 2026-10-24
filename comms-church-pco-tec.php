<?php
/**
 * Plugin Name: Comms.Church — PCO Events → The Events Calendar
 * Plugin URI:  https://comms.church
 * Description: Syncs Planning Center Events (and optional Registrations signup links) into The Events Calendar. Automatic scheduled sync + manual trigger. API credentials stored securely server-side.
 * Version:     1.4.0
 * Author:      Comms.Church
 * Author URI:  https://comms.church
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: comms-church-pco-tec
 * Requires Plugins: the-events-calendar
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CCTEC_VERSION',     '1.4.0' );
define( 'CCTEC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CCTEC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CCTEC_PLUGIN_FILE', __FILE__ );

add_action( 'plugins_loaded', [ 'CCTEC', 'boot' ], 5 );

class CCTEC {

    public static function boot(): void {
        if ( ! class_exists( 'Tribe__Events__Main' ) ) {
            add_action( 'admin_notices', [ __CLASS__, 'notice_missing_tec' ] );
            return;
        }

        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-api.php';
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-cache.php';
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-images.php';
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-sync.php';
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-shortcodes.php';
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-admin.php';

        if ( is_admin() ) {
            require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-updater.php';
        }

        new CCTEC_Admin();
        new CCTEC_Sync();
        new CCTEC_Shortcodes();

        if ( is_admin() ) {
            new CCTEC_Updater( CCTEC_PLUGIN_FILE, CCTEC_VERSION );
        }
    }

    // ── Activation / Deactivation ─────────────────────────────────────────────

    public static function activate(): void {
        // Register custom cron interval before scheduling.
        if ( ! wp_get_schedule( 'cctec_every_6_hours' ) ) {
            add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );
        }

        $freq = get_option( 'cctec_sync_frequency', 'hourly' );
        if ( ! wp_next_scheduled( 'cctec_scheduled_sync' ) ) {
            wp_schedule_event( time(), $freq, 'cctec_scheduled_sync' );
        }

        // Seed defaults — add_option() is a no-op if the key already exists,
        // so this is safe to call on upgrades as well as fresh installs.
        $defaults = [
            'cctec_conflict_strategy'  => 'pco_wins',
            'cctec_sync_lookback_days' => 0,
            'cctec_sync_source'        => 'registrations_only',
            'cctec_pull_registrations' => '0',
            'cctec_delete_removed'     => '1',
            'cctec_cache_ttl'          => 300,
            'cctec_brand_color'        => '#1a4a8a',
            'cctec_sideload_images'    => '1',
            'cctec_register_label'     => 'Register Now',
            'cctec_sync_frequency'     => 'hourly',
        ];
        foreach ( $defaults as $key => $value ) {
            add_option( $key, $value );
        }
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled( 'cctec_scheduled_sync' );
        if ( $ts ) wp_unschedule_event( $ts, 'cctec_scheduled_sync' );
    }

    public static function add_cron_interval( array $schedules ): array {
        $schedules['cctec_every_6_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __( 'Every 6 hours', 'comms-church-pco-tec' ),
        ];
        return $schedules;
    }

    public static function notice_missing_tec(): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'Comms.Church PCO → Events Calendar requires The Events Calendar plugin to be installed and active.', 'comms-church-pco-tec' )
            . '</p></div>';
    }
}

// Register the custom cron interval on every load (not just activation).
add_filter( 'cron_schedules', [ 'CCTEC', 'add_cron_interval' ] );

register_activation_hook(   __FILE__, [ 'CCTEC', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CCTEC', 'deactivate' ] );
