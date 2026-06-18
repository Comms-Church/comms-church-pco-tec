<?php
/**
 * CCTEC_Admin
 *
 * Registers the Settings → PCO Events Sync admin page with five sections:
 *   1. API Credentials
 *   2. Sync Settings (frequency, conflict strategy, lookback, delete stale)
 *   3. Registrations (optional signup link pull)
 *   4. Cache
 *   5. Sync Log + manual trigger
 *
 * Also hooks into the TEC event edit screen to show a read-only meta box
 * with the PCO source data, and a "manually edited" checkbox for manual_wins
 * conflict resolution.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Admin {

    const PAGE_SLUG    = 'cctec-settings';
    const OPTION_GROUP = 'cctec_settings_group';

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'add_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_init',    [ $this, 'handle_actions' ] );
        add_action( 'admin_notices', [ $this, 'show_notices' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // TEC event meta box
        add_action( 'tribe_events_single_meta_details_section_start', [ $this, 'maybe_render_pco_meta_box' ] );
        add_action( 'save_post_tribe_events', [ $this, 'save_manually_edited_flag' ], 10, 2 );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function add_menu(): void {
        add_options_page(
            __( 'PCO Events Sync', 'comms-church-pco-tec' ),
            __( 'PCO Events Sync', 'comms-church-pco-tec' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // ── Settings registration ─────────────────────────────────────────────────

    public function register_settings(): void {
        // ── API credentials ──────────────────────────────────────────────
        register_setting( self::OPTION_GROUP, 'cctec_app_id',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::OPTION_GROUP, 'cctec_secret',  [ 'sanitize_callback' => 'sanitize_text_field' ] );

        // ── Sync settings ────────────────────────────────────────────────
        register_setting( self::OPTION_GROUP, 'cctec_sync_frequency',   [ 'sanitize_callback' => [ $this, 'sanitize_frequency' ] ] );
        register_setting( self::OPTION_GROUP, 'cctec_conflict_strategy',[ 'sanitize_callback' => [ $this, 'sanitize_strategy' ] ] );
        register_setting( self::OPTION_GROUP, 'cctec_delete_removed',   [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::OPTION_GROUP, 'cctec_sync_lookback_days', [
            'sanitize_callback' => function( $v ) { return max( 0, intval( $v ) ); },
        ] );

        // ── Sync source ──────────────────────────────────────────────────
        register_setting( self::OPTION_GROUP, 'cctec_sync_source', [ 'sanitize_callback' => [ $this, 'sanitize_sync_source' ] ] );

        // ── Registrations ────────────────────────────────────────────────
        register_setting( self::OPTION_GROUP, 'cctec_pull_registrations', [ 'sanitize_callback' => 'sanitize_text_field' ] );

        // ── Cache ────────────────────────────────────────────────────────
        register_setting( self::OPTION_GROUP, 'cctec_cache_ttl', [
            'sanitize_callback' => function( $v ) { return max( 60, intval( $v ) ); },
        ] );

        // ── Display / branding ───────────────────────────────────────────
        register_setting( self::OPTION_GROUP, 'cctec_brand_color', [
            'sanitize_callback' => 'sanitize_hex_color',
        ] );
        register_setting( self::OPTION_GROUP, 'cctec_sideload_images', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
    }

    public function sanitize_sync_source( $v ): string {
        return in_array( $v, [ 'registrations_only', 'calendar_all', 'both' ], true ) ? $v : 'registrations_only';
    }

    public function sanitize_frequency( $v ): string {
        return in_array( $v, [ 'hourly', 'twicedaily', 'daily', 'cctec_every_6_hours' ], true ) ? $v : 'hourly';
    }

    public function sanitize_strategy( $v ): string {
        return in_array( $v, [ 'pco_wins', 'manual_wins' ], true ) ? $v : 'pco_wins';
    }

    // ── Action handler (manual sync / clear cache / clear log) ────────────────

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Register custom cron interval
        add_filter( 'cron_schedules', function( $schedules ) {
            $schedules['cctec_every_6_hours'] = [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display'  => __( 'Every 6 hours', 'comms-church-pco-tec' ),
            ];
            return $schedules;
        } );

        if ( empty( $_POST['cctec_action'] ) ) return;
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cctec_action' ) ) return;

        $action = sanitize_text_field( $_POST['cctec_action'] );

        if ( $action === 'sync_now' ) {
            $sync  = new CCTEC_Sync();
            $stats = $sync->run( isset( $_POST['cctec_force'] ) );
            $msg   = sprintf(
                __( 'Sync complete — %d created, %d updated, %d deleted, %d errors.', 'comms-church-pco-tec' ),
                $stats['created'], $stats['updated'], $stats['deleted'], $stats['errors']
            );
            set_transient( 'cctec_admin_notice', [ 'type' => $stats['errors'] ? 'warning' : 'success', 'msg' => $msg ], 60 );
        }

        if ( $action === 'clear_cache' ) {
            CCTEC_Cache::flush_all();
            set_transient( 'cctec_admin_notice', [ 'type' => 'success', 'msg' => __( 'Cache cleared.', 'comms-church-pco-tec' ) ], 60 );
        }

        if ( $action === 'clear_log' ) {
            CCTEC_Sync::clear_log();
            set_transient( 'cctec_admin_notice', [ 'type' => 'success', 'msg' => __( 'Sync log cleared.', 'comms-church-pco-tec' ) ], 60 );
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    // ── Admin notices ─────────────────────────────────────────────────────────

    public function show_notices(): void {
        $notice = get_transient( 'cctec_admin_notice' );
        if ( ! $notice ) return;
        delete_transient( 'cctec_admin_notice' );
        $class = $notice['type'] === 'success' ? 'notice-success' : 'notice-warning';
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) return;
        wp_enqueue_style( 'cctec-admin', CCTEC_PLUGIN_URL . 'assets/admin.css', [], CCTEC_VERSION );
        wp_enqueue_script( 'cctec-admin', CCTEC_PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], CCTEC_VERSION, true );
    }

    // ── Settings page render ──────────────────────────────────────────────────

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $api          = new CCTEC_API();
        $last_sync    = get_option( 'cctec_last_sync', __( 'Never', 'comms-church-pco-tec' ) );
        $next_ts      = wp_next_scheduled( 'cctec_scheduled_sync' );
        $next_sync    = $next_ts ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_ts ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'Not scheduled', 'comms-church-pco-tec' );
        $log_entries  = array_reverse( CCTEC_Sync::get_log() );
        ?>
        <div class="wrap cctec-wrap">
            <h1><?php esc_html_e( 'PCO Events → The Events Calendar', 'comms-church-pco-tec' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Syncs Planning Center Events into The Events Calendar. Events are created/updated automatically; registration links are optionally attached when a matching PCO Signup is found.', 'comms-church-pco-tec' ); ?></p>

            <?php /* ── Status bar ── */ ?>
            <div class="cctec-status-bar">
                <span><?php echo esc_html__( 'Last sync:', 'comms-church-pco-tec' ) . ' ' . esc_html( $last_sync ); ?></span>
                <span><?php echo esc_html__( 'Next sync:', 'comms-church-pco-tec' ) . ' ' . esc_html( $next_sync ); ?></span>
                <span class="cctec-api-status <?php echo $api->is_configured() ? 'ok' : 'err'; ?>">
                    <?php echo $api->is_configured()
                        ? esc_html__( 'API configured', 'comms-church-pco-tec' )
                        : esc_html__( 'API not configured', 'comms-church-pco-tec' ); ?>
                </span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); ?>

                <?php /* ── 1. API Credentials ── */ ?>
                <div class="cctec-card">
                    <h2><?php esc_html_e( 'API Credentials', 'comms-church-pco-tec' ); ?></h2>
                    <p><?php printf(
                        /* translators: %s: PCO API link */
                        esc_html__( 'Create a Personal Access Token at %s. Both the Registrations and Calendar APIs use the same App ID / Secret.', 'comms-church-pco-tec' ),
                        '<a href="https://api.planningcenteronline.com/oauth/applications" target="_blank" rel="noopener">api.planningcenteronline.com</a>'
                    ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><label for="cctec_app_id"><?php esc_html_e( 'Application ID', 'comms-church-pco-tec' ); ?></label></th>
                            <td><input type="text" id="cctec_app_id" name="cctec_app_id" value="<?php echo esc_attr( get_option( 'cctec_app_id', '' ) ); ?>" class="regular-text" autocomplete="off"></td>
                        </tr>
                        <tr>
                            <th><label for="cctec_secret"><?php esc_html_e( 'Secret', 'comms-church-pco-tec' ); ?></label></th>
                            <td><input type="password" id="cctec_secret" name="cctec_secret" value="<?php echo esc_attr( get_option( 'cctec_secret', '' ) ); ?>" class="regular-text" autocomplete="off"></td>
                        </tr>
                    </table>
                </div>

                <?php /* ── 2. Sync Settings ── */ ?>
                <div class="cctec-card">
                    <h2><?php esc_html_e( 'Sync Settings', 'comms-church-pco-tec' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="cctec_sync_frequency"><?php esc_html_e( 'Sync Frequency', 'comms-church-pco-tec' ); ?></label></th>
                            <td>
                                <select id="cctec_sync_frequency" name="cctec_sync_frequency">
                                    <?php foreach ( [
                                        'hourly'             => __( 'Hourly', 'comms-church-pco-tec' ),
                                        'cctec_every_6_hours'=> __( 'Every 6 hours', 'comms-church-pco-tec' ),
                                        'twicedaily'         => __( 'Twice daily', 'comms-church-pco-tec' ),
                                        'daily'              => __( 'Daily', 'comms-church-pco-tec' ),
                                    ] as $val => $lbl ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( get_option( 'cctec_sync_frequency', 'hourly' ), $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'How often WP-Cron checks PCO for changes. Hourly is fine for most churches.', 'comms-church-pco-tec' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cctec_conflict_strategy"><?php esc_html_e( 'Conflict Strategy', 'comms-church-pco-tec' ); ?></label></th>
                            <td>
                                <select id="cctec_conflict_strategy" name="cctec_conflict_strategy">
                                    <option value="pco_wins"    <?php selected( get_option( 'cctec_conflict_strategy', 'pco_wins' ), 'pco_wins' ); ?>><?php esc_html_e( 'PCO wins — always overwrite local edits', 'comms-church-pco-tec' ); ?></option>
                                    <option value="manual_wins" <?php selected( get_option( 'cctec_conflict_strategy', 'pco_wins' ), 'manual_wins' ); ?>><?php esc_html_e( 'Manual wins — skip events that were edited in WP', 'comms-church-pco-tec' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'When PCO changes an event that was also edited in WordPress, which version wins?', 'comms-church-pco-tec' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cctec_delete_removed"><?php esc_html_e( 'Delete Removed Events', 'comms-church-pco-tec' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="cctec_delete_removed" name="cctec_delete_removed" value="1" <?php checked( get_option( 'cctec_delete_removed', '1' ), '1' ); ?>>
                                    <?php esc_html_e( 'Trash TEC events when they are removed from PCO', 'comms-church-pco-tec' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cctec_sync_lookback_days"><?php esc_html_e( 'Past Days to Sync', 'comms-church-pco-tec' ); ?></label></th>
                            <td>
                                <input type="number" id="cctec_sync_lookback_days" name="cctec_sync_lookback_days" value="<?php echo esc_attr( get_option( 'cctec_sync_lookback_days', 0 ) ); ?>" min="0" max="365" style="width:80px"> <?php esc_html_e( 'days', 'comms-church-pco-tec' ); ?>
                                <p class="description"><?php esc_html_e( '0 = future events only. Set higher to also pull past events (useful for on-demand archives).', 'comms-church-pco-tec' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php /* ── 3. Sync Source ── */ ?>
                <div class="cctec-card">
                    <h2><?php esc_html_e( 'What to Sync', 'comms-church-pco-tec' ); ?></h2>
                    <p><?php esc_html_e( 'Choose which Planning Center data drives the sync. Most churches should use Registrations Only.', 'comms-church-pco-tec' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><label for="cctec_sync_source"><?php esc_html_e( 'Sync Source', 'comms-church-pco-tec' ); ?></label></th>
                            <td>
                                <?php $source = get_option( 'cctec_sync_source', 'registrations_only' ); ?>
                                <label style="display:block;margin-bottom:8px">
                                    <input type="radio" name="cctec_sync_source" value="registrations_only" <?php checked( $source, 'registrations_only' ); ?>>
                                    <strong><?php esc_html_e( 'Registrations Only', 'comms-church-pco-tec' ); ?></strong>
                                    — <?php esc_html_e( 'Only sync events that have a linked PCO Signup. Registration URLs and ticket prices are always attached. Recommended for most churches.', 'comms-church-pco-tec' ); ?>
                                </label>
                                <label style="display:block;margin-bottom:8px">
                                    <input type="radio" name="cctec_sync_source" value="calendar_all" <?php checked( $source, 'calendar_all' ); ?>>
                                    <strong><?php esc_html_e( 'All Calendar Events', 'comms-church-pco-tec' ); ?></strong>
                                    — <?php esc_html_e( 'Sync every future event from PCO Calendar, including recurring series. Registration data is attached where a matching Signup name is found.', 'comms-church-pco-tec' ); ?>
                                </label>
                                <label style="display:block">
                                    <input type="radio" name="cctec_sync_source" value="both" <?php checked( $source, 'both' ); ?>>
                                    <strong><?php esc_html_e( 'Both', 'comms-church-pco-tec' ); ?></strong>
                                    — <?php esc_html_e( 'All calendar events synced, and registration data is always attempted for every event regardless of name match.', 'comms-church-pco-tec' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php /* ── 4. Display & Branding ── */ ?>
                <div class="cctec-card">
                    <h2><?php esc_html_e( 'Display &amp; Branding', 'comms-church-pco-tec' ); ?></h2>
                    <p><?php esc_html_e( 'Controls how events look when displayed via the [pco_register] and [pco_event_card] shortcodes.', 'comms-church-pco-tec' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><label for="cctec_brand_color"><?php esc_html_e( 'Brand Color', 'comms-church-pco-tec' ); ?></label></th>
                            <td>
                                <input type="color" id="cctec_brand_color" name="cctec_brand_color"
                                       value="<?php echo esc_attr( get_option( 'cctec_brand_color', '#1a4a8a' ) ); ?>">
                                <input type="text"  id="cctec_brand_color_hex" style="width:90px;margin-left:8px"
                                       value="<?php echo esc_attr( get_option( 'cctec_brand_color', '#1a4a8a' ) ); ?>"
                                       pattern="^#[0-9a-fA-F]{6}$" placeholder="#1a4a8a">
                                <p class="description"><?php esc_html_e( 'Used for Register Now buttons and card accents. Can be overridden per-shortcode with the color="" attribute.', 'comms-church-pco-tec' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Event Images', 'comms-church-pco-tec' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cctec_sideload_images" value="1"
                                           <?php checked( get_option( 'cctec_sideload_images', '1' ), '1' ); ?>>
                                    <?php esc_html_e( 'Pull event images from PCO and save as WordPress featured images', 'comms-church-pco-tec' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'Images are only downloaded when a new or changed image URL is detected — not on every sync. Old images are cleaned from the media library automatically.', 'comms-church-pco-tec' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php /* ── 5. Cache ── */ ?>
                <div class="cctec-card">
                    <h2><?php esc_html_e( 'Cache', 'comms-church-pco-tec' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="cctec_cache_ttl"><?php esc_html_e( 'API Cache TTL', 'comms-church-pco-tec' ); ?></label></th>
                            <td>
                                <input type="number" id="cctec_cache_ttl" name="cctec_cache_ttl" value="<?php echo esc_attr( get_option( 'cctec_cache_ttl', 300 ) ); ?>" min="60" style="width:80px"> <?php esc_html_e( 'seconds', 'comms-church-pco-tec' ); ?>
                                <p class="description"><?php esc_html_e( 'How long raw PCO API responses are cached between sync passes. Does not affect how often events are written to TEC.', 'comms-church-pco-tec' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( __( 'Save Settings', 'comms-church-pco-tec' ) ); ?>
            </form>

            <?php /* ── 5. Manual Sync Controls ── */ ?>
            <div class="cctec-card">
                <h2><?php esc_html_e( 'Manual Sync', 'comms-church-pco-tec' ); ?></h2>
                <form method="post" style="display:inline-block;margin-right:12px">
                    <?php wp_nonce_field( 'cctec_action' ); ?>
                    <input type="hidden" name="cctec_action" value="sync_now">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Sync Now', 'comms-church-pco-tec' ); ?></button>
                </form>
                <form method="post" style="display:inline-block;margin-right:12px">
                    <?php wp_nonce_field( 'cctec_action' ); ?>
                    <input type="hidden" name="cctec_action" value="sync_now">
                    <input type="hidden" name="cctec_force"  value="1">
                    <button type="submit" class="button"><?php esc_html_e( 'Force Full Re-sync', 'comms-church-pco-tec' ); ?></button>
                </form>
                <form method="post" style="display:inline-block;margin-right:12px">
                    <?php wp_nonce_field( 'cctec_action' ); ?>
                    <input type="hidden" name="cctec_action" value="clear_cache">
                    <button type="submit" class="button"><?php esc_html_e( 'Clear API Cache', 'comms-church-pco-tec' ); ?></button>
                </form>
            </div>

            <?php /* ── 6. Sync Log ── */ ?>
            <div class="cctec-card">
                <h2>
                    <?php esc_html_e( 'Sync Log', 'comms-church-pco-tec' ); ?>
                    <form method="post" style="display:inline;margin-left:12px">
                        <?php wp_nonce_field( 'cctec_action' ); ?>
                        <input type="hidden" name="cctec_action" value="clear_log">
                        <button type="submit" class="button button-small"><?php esc_html_e( 'Clear Log', 'comms-church-pco-tec' ); ?></button>
                    </form>
                </h2>
                <div class="cctec-log" id="cctec-log">
                    <?php if ( empty( $log_entries ) ) : ?>
                        <p class="description"><?php esc_html_e( 'No sync log entries yet. Run a sync to populate this log.', 'comms-church-pco-tec' ); ?></p>
                    <?php else : ?>
                        <table class="widefat striped cctec-log-table">
                            <thead><tr>
                                <th><?php esc_html_e( 'Time', 'comms-church-pco-tec' ); ?></th>
                                <th><?php esc_html_e( 'Level', 'comms-church-pco-tec' ); ?></th>
                                <th><?php esc_html_e( 'Message', 'comms-church-pco-tec' ); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ( $log_entries as $entry ) : ?>
                                <tr class="cctec-log-<?php echo esc_attr( $entry['level'] ?? 'info' ); ?>">
                                    <td><?php echo esc_html( $entry['ts'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( strtoupper( $entry['level'] ?? 'info' ) ); ?></td>
                                    <td><?php echo esc_html( $entry['msg'] ?? '' ); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- .cctec-wrap -->
        <?php
    }

    // ── TEC event meta box ────────────────────────────────────────────────────

    /**
     * Render a read-only PCO source info box on TEC event edit screens.
     * Called from tribe_events_single_meta_details_section_start (TEC action).
     */
    public function maybe_render_pco_meta_box(): void {
        global $post;
        if ( ! $post || $post->post_type !== 'tribe_events' ) return;

        $instance_id = get_post_meta( $post->ID, '_pco_instance_id', true );
        if ( ! $instance_id ) return;

        $event_id   = get_post_meta( $post->ID, '_pco_event_id',    true );
        $updated_at = get_post_meta( $post->ID, '_pco_updated_at',  true );
        $synced     = get_post_meta( $post->ID, '_pco_synced',      true );
        $reg_url    = get_post_meta( $post->ID, '_pco_registration_url', true );
        $min_price  = get_post_meta( $post->ID, '_pco_min_price',   true );
        $strategy   = get_option( 'cctec_conflict_strategy', 'pco_wins' );
        $manually   = get_post_meta( $post->ID, '_pco_manually_edited', true );

        echo '<div class="cctec-event-meta-box">';
        echo '<h4>' . esc_html__( 'Planning Center Source', 'comms-church-pco-tec' ) . '</h4>';
        echo '<p><strong>' . esc_html__( 'PCO Event ID:', 'comms-church-pco-tec' ) . '</strong> ' . esc_html( $event_id ) . '</p>';
        echo '<p><strong>' . esc_html__( 'PCO Instance ID:', 'comms-church-pco-tec' ) . '</strong> ' . esc_html( $instance_id ) . '</p>';
        echo '<p><strong>' . esc_html__( 'PCO Updated At:', 'comms-church-pco-tec' ) . '</strong> ' . esc_html( $updated_at ) . '</p>';
        if ( $synced ) {
            echo '<p><strong>' . esc_html__( 'Last Synced:', 'comms-church-pco-tec' ) . '</strong> ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $synced ) ) . '</p>';
        }
        if ( $reg_url ) {
            echo '<p><strong>' . esc_html__( 'Registration URL:', 'comms-church-pco-tec' ) . '</strong> <a href="' . esc_url( $reg_url ) . '" target="_blank">' . esc_html( $reg_url ) . '</a></p>';
        }
        if ( $min_price !== '' && $min_price !== null ) {
            echo '<p><strong>' . esc_html__( 'Starting Price:', 'comms-church-pco-tec' ) . '</strong> ' . esc_html( '$' . number_format( (float) $min_price, 2 ) ) . '</p>';
        }

        // Conflict flag
        if ( $strategy === 'manual_wins' ) {
            $nonce = wp_create_nonce( 'cctec_manually_edited_' . $post->ID );
            echo '<p><label>';
            echo '<input type="checkbox" name="cctec_manually_edited" value="1" ' . checked( $manually, '1', false ) . '> ';
            echo esc_html__( 'This event was manually edited — skip during future syncs (Manual wins mode)', 'comms-church-pco-tec' );
            echo '</label>';
            echo '<input type="hidden" name="cctec_manually_edited_nonce" value="' . esc_attr( $nonce ) . '">';
            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * Save the "manually edited" flag when a TEC event is saved.
     */
    public function save_manually_edited_flag( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['cctec_manually_edited_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['cctec_manually_edited_nonce'], 'cctec_manually_edited_' . $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        update_post_meta( $post_id, '_pco_manually_edited', isset( $_POST['cctec_manually_edited'] ) ? '1' : '0' );
    }
}
