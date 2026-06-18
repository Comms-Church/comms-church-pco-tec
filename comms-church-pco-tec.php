<?php
/**
 * Plugin Name: Comms.Church — PCO Events → The Events Calendar
 * Plugin URI:  https://comms.church
 * Description: Syncs Planning Center Events (and optional Registrations signup links) into The Events Calendar. Automatic scheduled sync + manual trigger. API credentials stored securely server-side.
 * Version:     1.1.0
 * Author:      Comms.Church
 * Author URI:  https://comms.church
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: comms-church-pco-tec
 * Requires Plugins: the-events-calendar
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CCTEC_VERSION',     '1.1.0' );
define( 'CCTEC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CCTEC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CCTEC_PLUGIN_FILE', __FILE__ );

// ── Dependency check ─────────────────────────────────────────────────────────
// The Events Calendar must be active before we boot. We check for the
// Tribe__Events__API class rather than the plugin file so it works with both
// the free TEC plugin and TEC Pro.
add_action( 'plugins_loaded', array( 'CCTEC', 'boot' ), 5 );

class CCTEC {

    /** Boot: verify TEC is present, then wire everything up. */
    public static function boot() {
        if ( ! class_exists( 'Tribe__Events__Main' ) ) {
            add_action( 'admin_notices', array( __CLASS__, 'notice_missing_tec' ) );
            return;
        }

        // Load includes
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-api.php';
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-cache.php';
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-sync.php';
        require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-admin.php';

        if ( is_admin() ) {
            require_once CCTEC_PLUGIN_DIR . 'includes/class-cctec-updater.php';
        }

        // Initialise
        new CCTEC_Admin();
        new CCTEC_Sync(); // registers cron hooks

        if ( is_admin() ) {
            new CCTEC_Updater( CCTEC_PLUGIN_FILE, CCTEC_VERSION );
        }
    }

    // ── Activation / Deactivation ─────────────────────────────────────────────

    public static function activate() {
        // Schedule the recurring sync if not already scheduled.
        // Frequency is stored in options; default to hourly.
        $freq = get_option( 'cctec_sync_frequency', 'hourly' );
        if ( ! wp_next_scheduled( 'cctec_scheduled_sync' ) ) {
            wp_schedule_event( time(), $freq, 'cctec_scheduled_sync' );
        }

        // Seed option defaults on first activation only.
        if ( false === get_option( 'cctec_conflict_strategy' ) ) {
            update_option( 'cctec_conflict_strategy', 'pco_wins' );
        }
        if ( false === get_option( 'cctec_sync_lookback_days' ) ) {
            update_option( 'cctec_sync_lookback_days', 0 ); // future only
        }
        if ( false === get_option( 'cctec_sync_source' ) ) {
            update_option( 'cctec_sync_source', 'registrations_only' );
        }
        if ( false === get_option( 'cctec_pull_registrations' ) ) {
            update_option( 'cctec_pull_registrations', '0' );
        }
        if ( false === get_option( 'cctec_cache_ttl' ) ) {
            update_option( 'cctec_cache_ttl', 300 );
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'cctec_scheduled_sync' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'cctec_scheduled_sync' );
        }
    }

    // ── Admin notice ──────────────────────────────────────────────────────────

    public static function notice_missing_tec() {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'Comms.Church PCO → Events Calendar requires The Events Calendar plugin to be installed and active.', 'comms-church-pco-tec' )
            . '</p></div>';
    }
}

register_activation_hook(   __FILE__, array( 'CCTEC', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CCTEC', 'deactivate' ) );
