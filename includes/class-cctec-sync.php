<?php
/**
 * CCTEC_Sync
 *
 * The sync engine. Supports three modes controlled by the cctec_sync_source option:
 *
 *   registrations_only  (default) — Only events that have a linked PCO Signup are
 *                                   synced. Ideal for most churches who only want
 *                                   public-facing, registerable events on their site.
 *
 *   calendar_all        — Every future event instance in PCO Calendar is synced,
 *                         with optional registration data attached where a matching
 *                         Signup is found.
 *
 *   both                — Same as calendar_all, but registration data is always
 *                         attempted for every event.
 *
 * Recurring event support: PCO returns one event_instance per occurrence, all
 * sharing the same parent event_id. The sync engine groups instances by event_id
 * and stores a _pco_event_id meta key. TEC events are linked via a shared
 * _pco_series_id term so they appear as a series in the admin, and the first
 * instance carries _pco_is_series_parent = 1 for reference.
 *
 * Meta keys written to TEC event posts
 * ─────────────────────────────────────
 *  _pco_event_id         string   PCO calendar event ID (shared by all instances in a series)
 *  _pco_instance_id      string   PCO event_instance ID (unique per occurrence)
 *  _pco_updated_at       string   ISO8601 — PCO's updated_at for change detection
 *  _pco_registration_id  string   Matched PCO signup ID (if applicable)
 *  _pco_registration_url string   Direct PCO registration URL
 *  _pco_min_price        string   Lowest price from selection_types
 *  _pco_max_price        string   Highest price from selection_types
 *  _pco_ticket_types     string   JSON-encoded array of { name, price, capacity }
 *  _pco_synced           int      Unix timestamp of last successful sync for this post
 *  _pco_is_recurring     int      1 if this event has multiple instances (series)
 *  _pco_series_index     int      0-based position within the series
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Sync {

    private CCTEC_API $api;

    const LOG_OPTION    = 'cctec_sync_log';
    const LOG_MAX_LINES = 200;
    const TEC_CPT       = 'tribe_events';

    // Taxonomy used to group recurring event posts into a series.
    const SERIES_TAXONOMY = 'cctec_series';

    public function __construct() {
        $this->api = new CCTEC_API();

        add_action( 'cctec_scheduled_sync', [ $this, 'run' ] );
        add_action( 'update_option_cctec_sync_frequency', [ $this, 'reschedule_cron' ], 10, 2 );

        // Register a hidden taxonomy used to group recurring instances.
        add_action( 'init', [ $this, 'register_series_taxonomy' ] );
    }

    // ── Series taxonomy ───────────────────────────────────────────────────────

    public function register_series_taxonomy(): void {
        register_taxonomy( self::SERIES_TAXONOMY, self::TEC_CPT, [
            'label'             => __( 'PCO Series', 'comms-church-pco-tec' ),
            'public'            => false,
            'show_ui'           => false,
            'show_admin_column' => false,
            'hierarchical'      => false,
            'rewrite'           => false,
        ] );
    }

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Run a full sync.
     *
     * @param bool $force  Ignore change detection; overwrite all fields.
     * @return array  [ 'created'=>int, 'updated'=>int, 'deleted'=>int, 'errors'=>int ]
     */
    public function run( bool $force = false ): array {
        if ( ! $this->api->is_configured() ) {
            $this->log( 'error', 'Sync skipped — API credentials not configured.' );
            return [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 1 ];
        }

        $source = get_option( 'cctec_sync_source', 'registrations_only' );
        $this->log( 'info', "Sync started (mode: {$source})." );
        $stats = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0 ];

        if ( $source === 'registrations_only' ) {
            // ── Registrations-only mode ──────────────────────────────────────
            // Pull signups that have a linked PCO Calendar event. Each signup
            // maps to one TEC post. No recurring series grouping needed here
            // since signups represent a single registerable event.
            $signups = CCTEC_Cache::remember( 'all_registration_instances', function() {
                return $this->api->get_all_registration_instances();
            }, 60 );

            if ( is_wp_error( $signups ) ) {
                $this->log( 'error', 'Failed to fetch registrations: ' . $signups->get_error_message() );
                return array_merge( $stats, [ 'errors' => 1 ] );
            }

            $synced_ids = [];
            foreach ( $signups as $signup ) {
                $result = $this->upsert_from_signup( $signup, $force );
                if ( is_wp_error( $result ) ) {
                    $stats['errors']++;
                    $this->log( 'error', $result->get_error_message() );
                } elseif ( $result === 'created' ) {
                    $stats['created']++;
                } elseif ( $result === 'updated' ) {
                    $stats['updated']++;
                }
                $synced_ids[] = 'signup_' . ( $signup['id'] ?? '' );
            }

            if ( get_option( 'cctec_delete_removed', '1' ) === '1' ) {
                $stats['deleted'] = $this->delete_stale( $synced_ids, 'signup' );
            }

        } else {
            // ── Calendar / Both mode ─────────────────────────────────────────
            // Pull all future instances. Group by parent event_id to detect
            // recurring series before upserting.
            $instances = CCTEC_Cache::remember( 'all_instances', function() {
                return $this->api->get_all_event_instances();
            }, 60 );

            if ( is_wp_error( $instances ) ) {
                $this->log( 'error', 'Failed to fetch event instances: ' . $instances->get_error_message() );
                return array_merge( $stats, [ 'errors' => 1 ] );
            }

            // Group instances by parent event_id to identify recurring series.
            $by_event = [];
            foreach ( $instances as $instance ) {
                $event_id = $instance['relationships']['event']['data']['id'] ?? 'unknown';
                $by_event[ $event_id ][] = $instance;
            }

            // Build signup map for registration attachment (both modes).
            $signup_map = [];
            if ( $source === 'both' || get_option( 'cctec_pull_registrations', '0' ) === '1' ) {
                $signup_map = $this->api->get_signups_by_name();
            }

            $synced_instance_ids = [];

            foreach ( $by_event as $event_id => $event_instances ) {
                $is_recurring = count( $event_instances ) > 1;

                // Sort instances chronologically so series index is stable.
                usort( $event_instances, function( $a, $b ) {
                    return strcmp(
                        $a['attributes']['starts_at'] ?? '',
                        $b['attributes']['starts_at'] ?? ''
                    );
                } );

                foreach ( $event_instances as $index => $instance ) {
                    $result = $this->upsert_instance( $instance, $signup_map, $force, $is_recurring, $index );
                    if ( is_wp_error( $result ) ) {
                        $stats['errors']++;
                        $this->log( 'error', $result->get_error_message() );
                    } elseif ( $result === 'created' ) {
                        $stats['created']++;
                    } elseif ( $result === 'updated' ) {
                        $stats['updated']++;
                    }
                    $synced_instance_ids[] = $instance['id'] ?? '';
                }
            }

            if ( get_option( 'cctec_delete_removed', '1' ) === '1' ) {
                $stats['deleted'] = $this->delete_stale( $synced_instance_ids, 'instance' );
            }
        }

        CCTEC_Cache::flush_all();
        update_option( 'cctec_last_sync', current_time( 'mysql' ) );

        $this->log( 'info', sprintf(
            'Sync complete — created %d, updated %d, deleted %d, errors %d.',
            $stats['created'], $stats['updated'], $stats['deleted'], $stats['errors']
        ) );

        return $stats;
    }

    // ── Registrations-only upsert ─────────────────────────────────────────────

    /**
     * Create or update a TEC event from a PCO Signup (registrations_only mode).
     * Dates and title come from the linked PCO Calendar event.
     *
     * @param array $signup  PCO signup resource with _calendar_event attached.
     * @param bool  $force
     * @return string|WP_Error  'created' | 'updated' | 'noop' | WP_Error
     */
    private function upsert_from_signup( array $signup, bool $force ) {
        $signup_id   = $signup['id']          ?? '';
        $attrs       = $signup['attributes']  ?? [];
        $cal_event   = $signup['_calendar_event'] ?? null;
        $cal_attrs   = $cal_event['attributes'] ?? [];

        $name        = sanitize_text_field( $cal_attrs['name'] ?? $attrs['name'] ?? '' );
        $description = wp_kses_post( $cal_attrs['description'] ?? '' );
        $starts_at   = $cal_attrs['starts_at'] ?? '';
        $ends_at     = $cal_attrs['ends_at']   ?? '';
        $pco_url     = $attrs['registration_url'] ?? $attrs['public_url'] ?? $cal_attrs['public_url'] ?? '';
        $updated_at  = $attrs['updated_at'] ?? '';

        if ( ! $name ) {
            return new WP_Error( 'cctec_missing_fields', "Signup {$signup_id} skipped — no event name." );
        }

        $start_date = $starts_at ? $this->iso_to_tec( $starts_at ) : '';
        $end_date   = $ends_at   ? $this->iso_to_tec( $ends_at )   : $start_date;

        // Find existing TEC post by signup ID.
        $existing_id = $this->find_tec_post_by_meta( '_pco_registration_id', $signup_id );

        if ( $existing_id && ! $force ) {
            $stored_updated = get_post_meta( $existing_id, '_pco_updated_at', true );
            if ( $stored_updated && $stored_updated === $updated_at ) {
                return 'noop';
            }
        }

        $event_args = array_merge( [
            'post_title'   => $name,
            'post_content' => $description,
            'post_status'  => 'publish',
            'post_type'    => self::TEC_CPT,
            'EventURL'     => esc_url_raw( $pco_url ),
        ], $this->date_args( $start_date, $end_date ?: $start_date ) );

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
                "Failed to {$action} TEC event for PCO signup {$signup_id}." );
        }

        // Write signup registration meta.
        $signup_data = $this->build_signup_data( $signup );
        update_post_meta( $post_id, '_pco_registration_id',  $signup_id );
        update_post_meta( $post_id, '_pco_registration_url', $signup_data['url'] );
        update_post_meta( $post_id, '_pco_min_price',        $signup_data['min_price'] );
        update_post_meta( $post_id, '_pco_max_price',        $signup_data['max_price'] );
        update_post_meta( $post_id, '_pco_ticket_types',     wp_json_encode( $signup_data['ticket_types'] ) );
        update_post_meta( $post_id, '_pco_updated_at',       $updated_at );
        update_post_meta( $post_id, '_pco_synced',           time() );

        // Store a special meta key so delete_stale can find signup-mode posts.
        update_post_meta( $post_id, '_pco_signup_id', 'signup_' . $signup_id );

        $this->log( 'info', "{$action} TEC post {$post_id} ← PCO signup {$signup_id} ({$name})." );
        return $action;
    }

    // ── Calendar instance upsert ──────────────────────────────────────────────

    /**
     * Create or update a single TEC event post from a PCO event_instance.
     *
     * @param array  $instance      PCO event_instance resource (with _included key)
     * @param array  $signup_map    name→signup index (may be empty)
     * @param bool   $force
     * @param bool   $is_recurring  True if this event_id has multiple future instances
     * @param int    $series_index  0-based position within the series
     * @return string|WP_Error  'created' | 'updated' | 'noop' | WP_Error
     */
    private function upsert_instance( array $instance, array $signup_map, bool $force, bool $is_recurring = false, int $series_index = 0 ) {
        $attrs       = $instance['attributes']  ?? [];
        $included    = $instance['_included']   ?? [];
        $instance_id = $instance['id']          ?? '';

        // ── Resolve the parent Event resource ──────────────────────────────
        $event_id  = $instance['relationships']['event']['data']['id'] ?? '';
        $event_res = $included['Event'][ $event_id ] ?? null;

        if ( ! $event_res && $event_id ) {
            $body      = $this->api->get_event( $event_id );
            $event_res = is_wp_error( $body ) ? null : ( $body['data'] ?? null );
        }

        $event_attrs = $event_res['attributes'] ?? [];

        // ── Build field map ────────────────────────────────────────────────
        $name        = sanitize_text_field( $event_attrs['name']        ?? $attrs['name']    ?? '' );
        $description = wp_kses_post(        $event_attrs['description'] ?? '' );
        $starts_at   = $attrs['starts_at']  ?? $attrs['all_day_start']  ?? '';
        $ends_at     = $attrs['ends_at']    ?? $attrs['all_day_end']    ?? '';
        $all_day     = (bool) ( $attrs['all_day'] ?? false );
        $pco_url     = $event_attrs['registration_url'] ?? $event_attrs['public_url'] ?? '';
        $updated_at  = $attrs['updated_at'] ?? '';

        if ( ! $name || ! $starts_at ) {
            return new WP_Error( 'cctec_missing_fields',
                "Instance {$instance_id} skipped — missing name or start date." );
        }

        // ── For recurring series, append occurrence number to title ────────
        $post_title = $name;
        if ( $is_recurring ) {
            $post_title = $name; // title stays clean; series grouping is via taxonomy
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
        $event_args = array_merge( [
            'post_title'   => $post_title,
            'post_content' => $description,
            'post_status'  => 'publish',
            'post_type'    => self::TEC_CPT,
            'EventAllDay'  => $all_day ? 'yes' : '',
            'EventURL'     => esc_url_raw( $pco_url ),
        ], $this->date_args( $start_date, $end_date ) );

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
        update_post_meta( $post_id, '_pco_is_recurring', $is_recurring ? 1 : 0 );
        update_post_meta( $post_id, '_pco_series_index', $series_index );

        // ── Tag recurring instances with a shared series term ──────────────
        if ( $is_recurring && $event_id ) {
            $term_slug = 'pco-series-' . $event_id;
            $term      = get_term_by( 'slug', $term_slug, self::SERIES_TAXONOMY );
            if ( ! $term ) {
                $inserted = wp_insert_term( $name . ' (Series)', self::SERIES_TAXONOMY, [ 'slug' => $term_slug ] );
                $term_id  = is_wp_error( $inserted ) ? 0 : $inserted['term_id'];
            } else {
                $term_id = $term->term_id;
            }
            if ( $term_id ) {
                wp_set_object_terms( $post_id, $term_id, self::SERIES_TAXONOMY );
            }
        }

        if ( $signup_data ) {
            update_post_meta( $post_id, '_pco_registration_id',  $signup_data['id'] );
            update_post_meta( $post_id, '_pco_registration_url', $signup_data['url'] );
            update_post_meta( $post_id, '_pco_min_price',        $signup_data['min_price'] );
            update_post_meta( $post_id, '_pco_max_price',        $signup_data['max_price'] );
            update_post_meta( $post_id, '_pco_ticket_types',     wp_json_encode( $signup_data['ticket_types'] ) );
        }

        $series_note = $is_recurring ? " [series #{$series_index}]" : '';
        $this->log( 'info', "{$action} TEC post {$post_id} ← PCO instance {$instance_id} ({$name}){$series_note}." );
        return $action;
    }

    // ── Venue helper ──────────────────────────────────────────────────────────

    private function get_or_create_venue( array $location_resource ): int {
        $attrs   = $location_resource['attributes'] ?? [];
        $loc_id  = $location_resource['id']         ?? '';
        $name    = sanitize_text_field( $attrs['name']    ?? '' );
        $address = sanitize_text_field( $attrs['address'] ?? '' );
        $city    = sanitize_text_field( $attrs['city']    ?? '' );
        $state   = sanitize_text_field( $attrs['state']   ?? '' );
        $zip     = sanitize_text_field( $attrs['zip']     ?? '' );
        $country = sanitize_text_field( $attrs['country'] ?? '' );

        if ( ! $name ) return 0;

        $existing = get_posts( [
            'post_type'   => 'tribe_venue',
            'meta_key'    => '_pco_location_id',
            'meta_value'  => $loc_id,
            'numberposts' => 1,
            'fields'      => 'ids',
        ] );

        if ( $existing ) return $existing[0];

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
     * Delete TEC posts whose sync ID is no longer in $live_ids.
     *
     * @param array  $live_ids  Instance or signup IDs from the current sync.
     * @param string $mode      'instance' or 'signup' — determines which meta key to check.
     * @return int  Count of deleted posts.
     */
    private function delete_stale( array $live_ids, string $mode ): int {
        $meta_key = $mode === 'signup' ? '_pco_signup_id' : '_pco_instance_id';

        $all_synced = get_posts( [
            'post_type'   => self::TEC_CPT,
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [ [
                'key'     => $meta_key,
                'compare' => 'EXISTS',
            ] ],
        ] );

        $deleted = 0;
        foreach ( $all_synced as $post_id ) {
            $stored_id = get_post_meta( $post_id, $meta_key, true );
            if ( $stored_id && ! in_array( $stored_id, $live_ids, true ) ) {
                wp_trash_post( $post_id );
                $this->log( 'info', "Trashed TEC post {$post_id} (PCO {$mode} {$stored_id} no longer in feed)." );
                $deleted++;
            }
        }
        return $deleted;
    }

    // ── TEC post lookup ───────────────────────────────────────────────────────

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

    private function find_tec_post_by_meta( string $meta_key, string $value ): int {
        $posts = get_posts( [
            'post_type'   => self::TEC_CPT,
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [ [
                'key'   => $meta_key,
                'value' => $value,
            ] ],
        ] );
        return $posts ? (int) $posts[0] : 0;
    }

    // ── Date formatting ───────────────────────────────────────────────────────

    /**
     * Build the full set of date/time keys that tribe_create_event() /
     * tribe_update_event() need to correctly store both date AND time.
     *
     * Passing only EventStartDate causes TEC to ignore the time component
     * and default everything to 00:00 (midnight = "today"). We must also
     * pass the split Hour/Minute fields so TEC writes them to post meta.
     *
     * @param string $start  Y-m-d H:i:s in site timezone
     * @param string $end    Y-m-d H:i:s in site timezone
     * @return array
     */
    private function date_args( string $start, string $end ): array {
        if ( ! $start ) return [];
        $end = $end ?: $start;

        // Parse with site timezone so strtotime doesn't silently shift hours.
        try {
            $tz   = wp_timezone();
            $s_dt = new DateTimeImmutable( $start, $tz );
            $e_dt = new DateTimeImmutable( $end,   $tz );
        } catch ( Exception $e ) {
            return [];
        }

        return [
            'EventStartDate'   => $s_dt->format( 'Y-m-d' ),
            'EventStartHour'   => $s_dt->format( 'G' ),   // 0-23, no leading zero
            'EventStartMinute' => $s_dt->format( 'i' ),   // 00-59
            'EventStartMeridian' => $s_dt->format( 'a' ), // am/pm
            'EventEndDate'     => $e_dt->format( 'Y-m-d' ),
            'EventEndHour'     => $e_dt->format( 'G' ),
            'EventEndMinute'   => $e_dt->format( 'i' ),
            'EventEndMeridian' => $e_dt->format( 'a' ),
        ];
    }

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

    public function reschedule_cron( $old_value, $new_value ): void {
        $ts = wp_next_scheduled( 'cctec_scheduled_sync' );
        if ( $ts ) wp_unschedule_event( $ts, 'cctec_scheduled_sync' );
        if ( $new_value ) {
            wp_schedule_event( time() + 60, $new_value, 'cctec_scheduled_sync' );
        }
    }

    // ── Sync log ──────────────────────────────────────────────────────────────

    public function log( string $level, string $msg ): void {
        $log   = json_decode( (string) get_option( self::LOG_OPTION, '[]' ), true ) ?: [];
        $log[] = [
            'ts'    => current_time( 'mysql' ),
            'level' => $level,
            'msg'   => $msg,
        ];
        if ( count( $log ) > self::LOG_MAX_LINES ) {
            $log = array_slice( $log, -self::LOG_MAX_LINES );
        }
        update_option( self::LOG_OPTION, wp_json_encode( $log ), false );
    }

    public static function get_log(): array {
        return json_decode( (string) get_option( self::LOG_OPTION, '[]' ), true ) ?: [];
    }

    public static function clear_log(): void {
        update_option( self::LOG_OPTION, '[]', false );
    }
}
