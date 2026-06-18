<?php
/**
 * CCTEC_Sync
 *
 * The sync engine. Pulls event instances from PCO Calendar v2, maps them to
 * The Events Calendar post meta, and creates / updates / deletes TEC events
 * to keep WordPress in sync with Planning Center.
 *
 * Meta keys written to TEC event posts
 * ─────────────────────────────────────
 *  _pco_event_id         string   PCO calendar event ID
 *  _pco_instance_id      string   PCO event_instance ID
 *  _pco_updated_at       string   ISO8601 — PCO's updated_at for change detection
 *  _pco_registration_id  string   Matched PCO signup ID (if pull_registrations on)
 *  _pco_registration_url string   Direct PCO registration URL
 *  _pco_min_price        string   Lowest price from selection_types
 *  _pco_max_price        string   Highest price from selection_types
 *  _pco_ticket_types     string   JSON-encoded array of { name, price, capacity }
 *  _pco_synced           int      Unix timestamp of last successful sync for this post
 *
 * Standard TEC meta written
 * ─────────────────────────
 *  _EventStartDate  Y-m-d H:i:s
 *  _EventEndDate    Y-m-d H:i:s
 *  _EventAllDay     yes|''
 *  _EventURL        string   PCO public event URL
 *  _EventVenueID    int      Attached TEC Venue post ID (created if needed)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Sync {

    private CCTEC_API $api;

    // Sync log stored as a WP option (last 200 lines, JSON).
    const LOG_OPTION    = 'cctec_sync_log';
    const LOG_MAX_LINES = 200;

    // TEC post type
    const TEC_CPT = 'tribe_events';

    public function __construct() {
        $this->api = new CCTEC_API();

        // Register the WP-Cron action
        add_action( 'cctec_scheduled_sync', [ $this, 'run' ] );

        // Re-schedule if frequency option changes
        add_action( 'update_option_cctec_sync_frequency', [ $this, 'reschedule_cron' ], 10, 2 );
    }

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Run a full sync. Safe to call manually from admin or WP-Cron.
     *
     * @param bool $force  If true, ignore _pco_updated_at change detection and
     *                     overwrite all fields (useful after a settings change).
     * @return array  [ 'created'=>int, 'updated'=>int, 'deleted'=>int, 'errors'=>int ]
     */
    public function run( bool $force = false ): array {
        if ( ! $this->api->is_configured() ) {
            $this->log( 'error', 'Sync skipped — API credentials not configured.' );
            return [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 1 ];
        }

        $this->log( 'info', 'Sync started.' );
        $stats = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0 ];

        // ── 1. Pull all upcoming event instances from PCO ──────────────────
        $instances = CCTEC_Cache::remember( 'all_instances', function() {
            return $this->api->get_all_event_instances();
        }, 60 ); // short cache so manual sync always feels fresh

        if ( is_wp_error( $instances ) ) {
            $this->log( 'error', 'Failed to fetch event instances: ' . $instances->get_error_message() );
            return array_merge( $stats, [ 'errors' => 1 ] );
        }

        // ── 2. Optionally build a signup name → signup map ─────────────────
        $signup_map = [];
        if ( get_option( 'cctec_pull_registrations', '0' ) === '1' ) {
            $signup_map = $this->api->get_signups_by_name();
        }

        // ── 3. Upsert each instance into TEC ──────────────────────────────
        $synced_instance_ids = [];

        foreach ( $instances as $instance ) {
            $result = $this->upsert_instance( $instance, $signup_map, $force );
            if ( is_wp_error( $result ) ) {
                $stats['errors']++;
                $this->log( 'error', $result->get_error_message() );
            } elseif ( $result === 'created' ) {
                $stats['created']++;
            } elseif ( $result === 'updated' ) {
                $stats['updated']++;
            }
            // 'noop' = unchanged, skip counting
            $synced_instance_ids[] = $instance['id'] ?? '';
        }

        // ── 4. Delete TEC posts that no longer exist in PCO ───────────────
        if ( get_option( 'cctec_delete_removed', '1' ) === '1' ) {
            $stats['deleted'] = $this->delete_stale( $synced_instance_ids );
        }

        // ── 5. Flush page cache after sync ─────────────────────────────────
        CCTEC_Cache::flush_all();
        update_option( 'cctec_last_sync', current_time( 'mysql' ) );

        $this->log( 'info', sprintf(
            'Sync complete — created %d, updated %d, deleted %d, errors %d.',
            $stats['created'], $stats['updated'], $stats['deleted'], $stats['errors']
        ) );

        return $stats;
    }

    // ── Instance upsert ───────────────────────────────────────────────────────

    /**
     * Create or update a single TEC event post from a PCO event_instance.
     *
     * @param array  $instance    PCO event_instance resource (may have _included key)
     * @param array  $signup_map  name→signup index (may be empty)
     * @param bool   $force
     * @return string|WP_Error  'created' | 'updated' | 'noop' | WP_Error
     */
    private function upsert_instance( array $instance, array $signup_map, bool $force ) {
        $attrs       = $instance['attributes']  ?? [];
        $included    = $instance['_included']   ?? [];
        $instance_id = $instance['id']          ?? '';

        // ── Resolve the parent Event resource ──────────────────────────────
        $event_id  = $instance['relationships']['event']['data']['id'] ?? '';
        $event_res = $included['Event'][ $event_id ] ?? null;

        // Fall back to fetching the parent event if not included
        if ( ! $event_res && $event_id ) {
            $body      = $this->api->get_event( $event_id );
            $event_res = is_wp_error( $body ) ? null : ( $body['data'] ?? null );
        }

        $event_attrs = $event_res['attributes'] ?? [];

        // ── Build the field map ────────────────────────────────────────────
        $name        = sanitize_text_field( $event_attrs['name']        ?? $attrs['name']    ?? '' );
        $description = wp_kses_post(         $event_attrs['description'] ?? '' );
        $starts_at   = $attrs['starts_at']   ?? $attrs['all_day_start'] ?? '';
        $ends_at     = $attrs['ends_at']     ?? $attrs['all_day_end']   ?? '';
        $all_day     = (bool) ( $attrs['all_day'] ?? false );
        $pco_url     = $event_attrs['registration_url'] ?? $event_attrs['public_url'] ?? '';
        $updated_at  = $attrs['updated_at']  ?? '';

        if ( ! $name || ! $starts_at ) {
            return new WP_Error( 'cctec_missing_fields',
                "Instance {$instance_id} skipped — missing name or start date." );
        }

        // ── Dates ──────────────────────────────────────────────────────────
        $start_date = $this->iso_to_tec( $starts_at );
        $end_date   = $ends_at ? $this->iso_to_tec( $ends_at ) : $start_date;

        // ── Venue ──────────────────────────────────────────────────────────
        $venue_id = 0;
        $loc_id   = $instance['relationships']['location']['data']['id']
                 ?? $event_res['relationships']['location']['data']['id']
                 ?? '';
        if ( $loc_id && isset( $included['Location'][ $loc_id ] ) ) {
            $venue_id = $this->get_or_create_venue( $included['Location'][ $loc_id ] );
        }

        // ── Registration (optional) ────────────────────────────────────────
        $signup_data = [];
        if ( $signup_map ) {
            $lookup = strtolower( trim( $name ) );
            if ( isset( $signup_map[ $lookup ] ) ) {
                $signup_data = $this->build_signup_data( $signup_map[ $lookup ] );
            }
        }

        // ── Find existing TEC post ─────────────────────────────────────────
        $existing_id = $this->find_tec_post( $instance_id );

        // ── Change detection ───────────────────────────────────────────────
        if ( $existing_id && ! $force ) {
            $stored_updated = get_post_meta( $existing_id, '_pco_updated_at', true );
            if ( $stored_updated && $stored_updated === $updated_at ) {
                return 'noop';
            }

            // Conflict: post was manually edited
            $strategy = get_option( 'cctec_conflict_strategy', 'pco_wins' );
            if ( $strategy === 'manual_wins' ) {
                $manually_edited = get_post_meta( $existing_id, '_pco_manually_edited', true );
                if ( $manually_edited ) {
                    $this->log( 'info', "Post {$existing_id} skipped (manual_wins, post was edited manually)." );
                    return 'noop';
                }
            }
        }

        // ── Build TEC event args ───────────────────────────────────────────
        $event_args = [
            'post_title'   => $name,
            'post_content' => $description,
            'post_status'  => 'publish',
            'post_type'    => self::TEC_CPT,
            'EventStartDate' => $start_date,
            'EventEndDate'   => $end_date,
            'EventAllDay'    => $all_day ? 'yes' : '',
            'EventURL'       => esc_url_raw( $pco_url ),
        ];

        if ( $venue_id ) {
            $event_args['EventVenueID'] = $venue_id;
        }

        // ── Create or update via TEC API ───────────────────────────────────
        if ( $existing_id ) {
            $event_args['ID'] = $existing_id;
            $post_id = tribe_update_event( $existing_id, $event_args );
            $action  = 'updated';
        } else {
            $post_id = tribe_create_event( $event_args );
            $action  = 'created';
        }

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return new WP_Error( 'cctec_tec_write_fail',
                "Failed to {$action} TEC event for PCO instance {$instance_id}." );
        }

        // ── Write PCO meta ─────────────────────────────────────────────────
        update_post_meta( $post_id, '_pco_event_id',    $event_id );
        update_post_meta( $post_id, '_pco_instance_id', $instance_id );
        update_post_meta( $post_id, '_pco_updated_at',  $updated_at );
        update_post_meta( $post_id, '_pco_synced',      time() );

        if ( $signup_data ) {
            update_post_meta( $post_id, '_pco_registration_id',  $signup_data['id'] );
            update_post_meta( $post_id, '_pco_registration_url', $signup_data['url'] );
            update_post_meta( $post_id, '_pco_min_price',        $signup_data['min_price'] );
            update_post_meta( $post_id, '_pco_max_price',        $signup_data['max_price'] );
            update_post_meta( $post_id, '_pco_ticket_types',     wp_json_encode( $signup_data['ticket_types'] ) );
        }

        $this->log( 'info', "{$action} TEC post {$post_id} ← PCO instance {$instance_id} ({$name})." );
        return $action;
    }

    // ── Venue helper ──────────────────────────────────────────────────────────

    /**
     * Find an existing TEC Venue for a PCO Location, or create one.
     *
     * Venues are matched by the PCO Location ID stored in meta key _pco_location_id.
     *
     * @param array $location_resource  PCO Location resource object.
     * @return int  Venue post ID, or 0 on failure.
     */
    private function get_or_create_venue( array $location_resource ): int {
        $attrs   = $location_resource['attributes'] ?? [];
        $loc_id  = $location_resource['id']         ?? '';
        $name    = sanitize_text_field( $attrs['name']        ?? '' );
        $address = sanitize_text_field( $attrs['address']     ?? '' );
        $city    = sanitize_text_field( $attrs['city']        ?? '' );
        $state   = sanitize_text_field( $attrs['state']       ?? '' );
        $zip     = sanitize_text_field( $attrs['zip']         ?? '' );
        $country = sanitize_text_field( $attrs['country']     ?? '' );

        if ( ! $name ) return 0;

        // Look for existing venue by PCO location ID
        $existing = get_posts( [
            'post_type'   => 'tribe_venue',
            'meta_key'    => '_pco_location_id',
            'meta_value'  => $loc_id,
            'numberposts' => 1,
            'fields'      => 'ids',
        ] );

        if ( $existing ) return $existing[0];

        // Create a new TEC venue
        $venue_id = tribe_create_venue( [
            'Venue'   => $name,
            'Address' => $address,
            'City'    => $city,
            'State'   => $state,
            'Zip'     => $zip,
            'Country' => $country,
        ] );

        if ( $venue_id && ! is_wp_error( $venue_id ) ) {
            update_post_meta( $venue_id, '_pco_location_id', $loc_id );
            $this->log( 'info', "Created TEC venue {$venue_id} for PCO location {$name}." );
            return $venue_id;
        }

        return 0;
    }

    // ── Registration data builder ─────────────────────────────────────────────

    /**
     * Pull signup + ticket-type data and return a flat array for meta storage.
     *
     * @param array $signup  PCO signup resource
     * @return array
     */
    private function build_signup_data( array $signup ): array {
        $attrs  = $signup['attributes'] ?? [];
        $sig_id = (int) ( $signup['id'] ?? 0 );
        $url    = $attrs['registration_url'] ?? $attrs['public_url'] ?? '';

        $ticket_types = [];
        $min_price    = null;
        $max_price    = null;

        $types_body = $this->api->get_selection_types( $sig_id );
        if ( ! is_wp_error( $types_body ) ) {
            foreach ( $types_body['data'] ?? [] as $type ) {
                $ta  = $type['attributes'] ?? [];
                $amt = isset( $ta['amount_cents'] ) ? $ta['amount_cents'] / 100 : null;

                $ticket_types[] = [
                    'name'     => sanitize_text_field( $ta['name'] ?? '' ),
                    'price'    => $amt,
                    'capacity' => $ta['maximum_selection_count'] ?? null,
                ];

                if ( $amt !== null ) {
                    $min_price = $min_price === null ? $amt : min( $min_price, $amt );
                    $max_price = $max_price === null ? $amt : max( $max_price, $amt );
                }
            }
        }

        return [
            'id'           => $sig_id,
            'url'          => esc_url_raw( $url ),
            'min_price'    => $min_price,
            'max_price'    => $max_price,
            'ticket_types' => $ticket_types,
        ];
    }

    // ── Stale post cleanup ────────────────────────────────────────────────────

    /**
     * Delete TEC posts whose _pco_instance_id is no longer in $live_ids.
     * Only deletes posts that were created by this plugin (have _pco_instance_id meta).
     *
     * @param array $live_ids  Instance IDs returned by the current sync.
     * @return int  Count of deleted posts.
     */
    private function delete_stale( array $live_ids ): int {
        $all_synced = get_posts( [
            'post_type'   => self::TEC_CPT,
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [ [
                'key'     => '_pco_instance_id',
                'compare' => 'EXISTS',
            ] ],
        ] );

        $deleted = 0;
        foreach ( $all_synced as $post_id ) {
            $stored_id = get_post_meta( $post_id, '_pco_instance_id', true );
            if ( $stored_id && ! in_array( $stored_id, $live_ids, true ) ) {
                wp_trash_post( $post_id );
                $this->log( 'info', "Trashed TEC post {$post_id} (PCO instance {$stored_id} no longer in feed)." );
                $deleted++;
            }
        }
        return $deleted;
    }

    // ── TEC post lookup ───────────────────────────────────────────────────────

    /**
     * Find the WP post ID for a TEC event by its PCO instance ID.
     *
     * @param string $instance_id
     * @return int  0 if not found.
     */
    private function find_tec_post( string $instance_id ): int {
        if ( ! $instance_id ) return 0;

        $posts = get_posts( [
            'post_type'   => self::TEC_CPT,
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [ [
                'key'   => '_pco_instance_id',
                'value' => $instance_id,
            ] ],
        ] );

        return $posts ? (int) $posts[0] : 0;
    }

    // ── Date formatting ───────────────────────────────────────────────────────

    /**
     * Convert a PCO ISO8601 datetime string to TEC's expected Y-m-d H:i:s format.
     * PCO uses UTC; TEC stores in local time (WP timezone setting).
     *
     * @param string $iso
     * @return string  Y-m-d H:i:s in site timezone.
     */
    private function iso_to_tec( string $iso ): string {
        if ( ! $iso ) return '';
        try {
            $dt = new DateTimeImmutable( $iso, new DateTimeZone( 'UTC' ) );
            $dt = $dt->setTimezone( wp_timezone() );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    // ── Cron rescheduler ──────────────────────────────────────────────────────

    /**
     * When the frequency option changes, reschedule the cron event.
     *
     * @param mixed $old_value
     * @param mixed $new_value
     */
    public function reschedule_cron( $old_value, $new_value ): void {
        $ts = wp_next_scheduled( 'cctec_scheduled_sync' );
        if ( $ts ) wp_unschedule_event( $ts, 'cctec_scheduled_sync' );

        if ( $new_value ) {
            wp_schedule_event( time() + 60, $new_value, 'cctec_scheduled_sync' );
        }
    }

    // ── Sync log ──────────────────────────────────────────────────────────────

    /**
     * Append a line to the rolling sync log stored in wp_options.
     *
     * @param string $level  'info' | 'error'
     * @param string $msg
     */
    public function log( string $level, string $msg ): void {
        $log   = json_decode( (string) get_option( self::LOG_OPTION, '[]' ), true ) ?: [];
        $log[] = [
            'ts'    => current_time( 'mysql' ),
            'level' => $level,
            'msg'   => $msg,
        ];

        // Keep log bounded
        if ( count( $log ) > self::LOG_MAX_LINES ) {
            $log = array_slice( $log, -self::LOG_MAX_LINES );
        }

        update_option( self::LOG_OPTION, wp_json_encode( $log ), false );
    }

    /**
     * Retrieve the sync log as an array (newest last).
     *
     * @return array
     */
    public static function get_log(): array {
        return json_decode( (string) get_option( self::LOG_OPTION, '[]' ), true ) ?: [];
    }

    /**
     * Clear the sync log.
     */
    public static function clear_log(): void {
        update_option( self::LOG_OPTION, '[]', false );
    }
}
