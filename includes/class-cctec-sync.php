<?php
/**
 * CCTEC_Sync
 *
 * The sync engine. Three modes via cctec_sync_source:
 *
 *   registrations_only  Pull signups from the Registrations API. Only events
 *                       with a PCO signup (i.e. publicly registerable) appear
 *                       on the site. Ideal for most churches.
 *
 *   calendar_all        Pull every future event instance from the Calendar API.
 *                       Registration data is attached where a signup name matches.
 *
 *   both                Same as calendar_all; registration data is always
 *                       attempted for every event.
 *
 * Recurring events: PCO returns one event_instance per occurrence sharing the
 * same parent event_id. Instances are grouped, sorted chronologically, then
 * each gets a 0-based _pco_series_index. A hidden cctec_series taxonomy term
 * groups them in the admin.
 *
 * Meta keys written to TEC tribe_events posts
 * ──────────────────────────────────────────────
 *  _pco_event_id          string   PCO Calendar event ID
 *  _pco_instance_id       string   PCO event_instance ID (unique per occurrence)
 *  _pco_registration_id   string   PCO signup ID (registrations mode)
 *  _pco_signup_id         string   "signup_{id}" key for stale-delete lookup
 *  _pco_registration_url  string   Direct PCO registration URL
 *  _pco_min_price         float|null
 *  _pco_max_price         float|null
 *  _pco_ticket_types      string   JSON [{name, price, capacity}]
 *  _pco_updated_at        string   ISO8601 — change-detection sentinel
 *  _pco_synced            int      Unix timestamp of last sync write
 *  _pco_is_recurring      int      1 if part of a series
 *  _pco_series_index      int      0-based position in series
 *  _pco_manually_edited   string   '1' if editor flagged manual_wins protection
 *  _pco_image_url         string   (written by CCTEC_Images)
 *  _pco_image_attachment_id int    (written by CCTEC_Images)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Sync {

    private CCTEC_API $api;

    const LOG_OPTION      = 'cctec_sync_log';
    const LOG_MAX_LINES   = 200;
    const TEC_CPT         = 'tribe_events';
    const SERIES_TAXONOMY = 'cctec_series';

    public function __construct() {
        $this->api = new CCTEC_API();

        add_action( 'cctec_scheduled_sync',          [ $this, 'run' ] );
        add_action( 'update_option_cctec_sync_frequency', [ $this, 'reschedule_cron' ], 10, 2 );
        add_action( 'init',                          [ $this, 'register_series_taxonomy' ] );
    }

    // ── Taxonomy ──────────────────────────────────────────────────────────────

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
     * Run a full sync. Safe to call from WP-Cron or admin.
     *
     * @param bool $force  Skip change detection; rewrite every field.
     * @return array { created, updated, deleted, errors }
     */
    public function run( bool $force = false ): array {
        if ( ! $this->api->is_configured() ) {
            $this->log( 'error', 'Sync skipped — API credentials not configured.' );
            return [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 1 ];
        }

        $source = get_option( 'cctec_sync_source', 'registrations_only' );
        $this->log( 'info', "Sync started (mode: {$source}, force: " . ( $force ? 'yes' : 'no' ) . ').' );
        $stats = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0 ];

        if ( $source === 'registrations_only' ) {
            $this->run_registrations( $stats, $force );
        } else {
            $this->run_calendar( $stats, $force, $source );
        }

        CCTEC_Cache::flush_all();
        update_option( 'cctec_last_sync', current_time( 'mysql' ) );

        $this->log( 'info', sprintf(
            'Sync complete — created %d, updated %d, deleted %d, errors %d.',
            $stats['created'], $stats['updated'], $stats['deleted'], $stats['errors']
        ) );

        return $stats;
    }

    // ── Registrations mode ────────────────────────────────────────────────────

    private function run_registrations( array &$stats, bool $force ): void {
        $signups = CCTEC_Cache::remember( 'all_registration_instances', function() {
            return $this->api->get_all_registration_instances();
        }, 60 );

        if ( is_wp_error( $signups ) ) {
            $this->log( 'error', 'Failed to fetch signups: ' . $signups->get_error_message() );
            $stats['errors']++;
            return;
        }

        $synced_keys = [];
        foreach ( $signups as $signup ) {
            $result = $this->upsert_from_signup( $signup, $force );
            $this->tally( $result, $stats );
            $synced_keys[] = 'signup_' . ( $signup['id'] ?? '' );
        }

        if ( get_option( 'cctec_delete_removed', '1' ) === '1' ) {
            $stats['deleted'] += $this->delete_stale( $synced_keys, '_pco_signup_id' );
        }
    }

    // ── Calendar mode ─────────────────────────────────────────────────────────

    private function run_calendar( array &$stats, bool $force, string $source ): void {
        $instances = CCTEC_Cache::remember( 'all_instances', function() {
            return $this->api->get_all_event_instances();
        }, 60 );

        if ( is_wp_error( $instances ) ) {
            $this->log( 'error', 'Failed to fetch event instances: ' . $instances->get_error_message() );
            $stats['errors']++;
            return;
        }

        // Build signup map once, cached, only when needed.
        $signup_map = [];
        if ( $source === 'both' || get_option( 'cctec_pull_registrations', '0' ) === '1' ) {
            $signup_map = CCTEC_Cache::remember( 'signups_by_name', function() {
                return $this->api->get_signups_by_name();
            }, 300 );
            if ( is_wp_error( $signup_map ) ) $signup_map = [];
        }

        // Group by parent event_id to detect recurring series.
        $by_event = [];
        foreach ( $instances as $instance ) {
            $eid = $instance['relationships']['event']['data']['id'] ?? 'unknown';
            $by_event[ $eid ][] = $instance;
        }

        $synced_ids = [];
        foreach ( $by_event as $event_instances ) {
            $is_recurring = count( $event_instances ) > 1;

            // Sort chronologically so series index is stable across syncs.
            usort( $event_instances, fn( $a, $b ) =>
                strcmp( $a['attributes']['starts_at'] ?? '', $b['attributes']['starts_at'] ?? '' )
            );

            foreach ( $event_instances as $idx => $instance ) {
                $result = $this->upsert_instance( $instance, $signup_map, $force, $is_recurring, $idx );
                $this->tally( $result, $stats );
                $synced_ids[] = $instance['id'] ?? '';
            }
        }

        if ( get_option( 'cctec_delete_removed', '1' ) === '1' ) {
            $stats['deleted'] += $this->delete_stale( $synced_ids, '_pco_instance_id' );
        }
    }

    // ── Registrations upsert ──────────────────────────────────────────────────

    /**
     * Create or update a TEC event from a PCO Signup.
     * Dates and location come from the linked SignupTime and SignupLocation
     * resources attached by get_all_registration_instances().
     *
     * @return 'created'|'updated'|'noop'|WP_Error
     */
    private function upsert_from_signup( array $signup, bool $force ) {
        $signup_id  = (string) ( $signup['id'] ?? '' );
        $attrs      = $signup['attributes'] ?? [];
        $time_attrs = $signup['_next_time']['attributes'] ?? [];
        $loc_attrs  = $signup['_location']['attributes']  ?? [];

        $name        = sanitize_text_field( $attrs['name'] ?? '' );
        $description = wp_kses_post( $attrs['description'] ?? '' );
        $starts_at   = $time_attrs['starts_at'] ?? '';
        $ends_at     = $time_attrs['ends_at']   ?? '';
        $all_day     = (bool) ( $time_attrs['all_day'] ?? false );
        // Correct field per PCO Registrations OpenAPI spec.
        $reg_url     = $attrs['new_registration_url'] ?? $attrs['registration_url'] ?? $attrs['public_url'] ?? '';
        $updated_at  = $attrs['updated_at'] ?? '';

        if ( ! $name ) {
            return new WP_Error( 'cctec_missing_fields', "Signup {$signup_id} skipped — no name." );
        }

        $start_date = $starts_at ? $this->iso_to_local( $starts_at ) : '';
        $end_date   = $ends_at   ? $this->iso_to_local( $ends_at )   : $start_date;

        // Lookup existing post.
        $existing_id = $this->find_post_by_meta( '_pco_registration_id', $signup_id );

        // Change detection — skip early before any extra API calls.
        if ( $existing_id && ! $force ) {
            if ( get_post_meta( $existing_id, '_pco_updated_at', true ) === $updated_at ) {
                return 'noop';
            }
        }

        // Venue — only attempt if we have a location name.
        $venue_id = 0;
        if ( ! empty( $loc_attrs['name'] ) ) {
            $venue_id = $this->get_or_create_venue_from_attrs(
                $signup['_location']['id'] ?? '',
                $loc_attrs
            );
        }

        $event_args = array_merge( [
            'post_title'   => $name,
            'post_content' => $description,
            'post_status'  => 'publish',
            'post_type'    => self::TEC_CPT,
            'EventAllDay'  => $all_day ? 'yes' : '',
            'EventURL'     => esc_url_raw( $reg_url ),
        ], $this->date_args( $start_date, $end_date ) );

        if ( $venue_id ) $event_args['EventVenueID'] = $venue_id;

        [ $post_id, $action ] = $this->write_tec_event( $existing_id, $event_args, $signup_id );
        if ( is_wp_error( $post_id ) ) return $post_id;

        // Fetch ticket / price data only on actual writes (not noops).
        $ticket_data = $this->fetch_ticket_data( (int) $signup_id );

        update_post_meta( $post_id, '_pco_registration_id',  $signup_id );
        update_post_meta( $post_id, '_pco_signup_id',        'signup_' . $signup_id );
        update_post_meta( $post_id, '_pco_registration_url', esc_url_raw( $reg_url ) );
        update_post_meta( $post_id, '_pco_min_price',        $ticket_data['min_price'] );
        update_post_meta( $post_id, '_pco_max_price',        $ticket_data['max_price'] );
        update_post_meta( $post_id, '_pco_ticket_types',     wp_json_encode( $ticket_data['types'] ) );
        update_post_meta( $post_id, '_pco_updated_at',       $updated_at );
        update_post_meta( $post_id, '_pco_synced',           time() );

        $this->maybe_sideload_image( $post_id, $name,
            CCTEC_Images::url_from_signup( $attrs )
        );

        $this->log( 'info', "{$action} post {$post_id} ← signup {$signup_id} ({$name})." );
        return $action;
    }

    // ── Calendar instance upsert ──────────────────────────────────────────────

    /**
     * Create or update a TEC event from a PCO event_instance.
     *
     * @return 'created'|'updated'|'noop'|WP_Error
     */
    private function upsert_instance(
        array $instance,
        array $signup_map,
        bool  $force,
        bool  $is_recurring = false,
        int   $series_index = 0
    ) {
        $attrs       = $instance['attributes'] ?? [];
        $included    = $instance['_included']  ?? [];
        $instance_id = (string) ( $instance['id'] ?? '' );

        // Resolve parent Event resource (from included data or a fallback API call).
        $event_id  = $instance['relationships']['event']['data']['id'] ?? '';
        $event_res = $included['Event'][ $event_id ] ?? null;
        if ( ! $event_res && $event_id ) {
            $body      = $this->api->get_event( $event_id );
            $event_res = is_wp_error( $body ) ? null : ( $body['data'] ?? null );
        }
        $event_attrs = $event_res['attributes'] ?? [];

        $name        = sanitize_text_field( $event_attrs['name'] ?? $attrs['name'] ?? '' );
        $description = wp_kses_post( $event_attrs['description'] ?? '' );
        $starts_at   = $attrs['starts_at']       ?? $attrs['all_day_start'] ?? '';
        $ends_at     = $attrs['ends_at']         ?? $attrs['all_day_end']   ?? '';
        $all_day     = (bool) ( $attrs['all_day'] ?? false );
        $pco_url     = $event_attrs['registration_url'] ?? $event_attrs['public_url'] ?? '';
        $updated_at  = $attrs['updated_at'] ?? '';

        if ( ! $name || ! $starts_at ) {
            return new WP_Error( 'cctec_missing_fields',
                "Instance {$instance_id} skipped — missing name or start date." );
        }

        $start_date = $this->iso_to_local( $starts_at );
        $end_date   = $ends_at ? $this->iso_to_local( $ends_at ) : $start_date;

        // Lookup existing post.
        $existing_id = $this->find_post_by_meta( '_pco_instance_id', $instance_id );

        // Change detection.
        if ( $existing_id && ! $force ) {
            if ( get_post_meta( $existing_id, '_pco_updated_at', true ) === $updated_at ) {
                return 'noop';
            }
            // Conflict strategy.
            if ( get_option( 'cctec_conflict_strategy', 'pco_wins' ) === 'manual_wins' ) {
                if ( get_post_meta( $existing_id, '_pco_manually_edited', true ) ) {
                    $this->log( 'info', "Post {$existing_id} skipped (manual_wins)." );
                    return 'noop';
                }
            }
        }

        // Venue.
        $venue_id = 0;
        $loc_id   = $instance['relationships']['location']['data']['id']
                 ?? $event_res['relationships']['location']['data']['id']
                 ?? '';
        if ( $loc_id && isset( $included['Location'][ $loc_id ] ) ) {
            $venue_id = $this->get_or_create_venue( $included['Location'][ $loc_id ] );
        }

        // Registration match by event name.
        $reg_url   = esc_url_raw( $pco_url );
        $min_price = null;
        $max_price = null;
        $ticket_types = [];
        if ( $signup_map ) {
            $lookup = strtolower( trim( $name ) );
            if ( isset( $signup_map[ $lookup ] ) ) {
                $matched_signup = $signup_map[ $lookup ];
                $sig_reg_url    = $matched_signup['attributes']['new_registration_url']
                               ?? $matched_signup['attributes']['registration_url']
                               ?? $matched_signup['attributes']['public_url']
                               ?? '';
                if ( $sig_reg_url ) $reg_url = esc_url_raw( $sig_reg_url );

                $td = $this->fetch_ticket_data( (int) $matched_signup['id'] );
                $min_price    = $td['min_price'];
                $max_price    = $td['max_price'];
                $ticket_types = $td['types'];
            }
        }

        $event_args = array_merge( [
            'post_title'   => $name,
            'post_content' => $description,
            'post_status'  => 'publish',
            'post_type'    => self::TEC_CPT,
            'EventAllDay'  => $all_day ? 'yes' : '',
            'EventURL'     => $reg_url,
        ], $this->date_args( $start_date, $end_date ) );

        if ( $venue_id ) $event_args['EventVenueID'] = $venue_id;

        [ $post_id, $action ] = $this->write_tec_event( $existing_id, $event_args, $instance_id );
        if ( is_wp_error( $post_id ) ) return $post_id;

        update_post_meta( $post_id, '_pco_event_id',          $event_id );
        update_post_meta( $post_id, '_pco_instance_id',       $instance_id );
        update_post_meta( $post_id, '_pco_updated_at',        $updated_at );
        update_post_meta( $post_id, '_pco_synced',            time() );
        update_post_meta( $post_id, '_pco_is_recurring',      $is_recurring ? 1 : 0 );
        update_post_meta( $post_id, '_pco_series_index',      $series_index );
        update_post_meta( $post_id, '_pco_registration_url',  $reg_url );
        update_post_meta( $post_id, '_pco_min_price',         $min_price );
        update_post_meta( $post_id, '_pco_max_price',         $max_price );
        update_post_meta( $post_id, '_pco_ticket_types',      wp_json_encode( $ticket_types ) );

        // Tag recurring instances with a shared hidden taxonomy term.
        if ( $is_recurring && $event_id ) {
            $this->tag_series( $post_id, $event_id, $name );
        }

        $this->maybe_sideload_image( $post_id, $name,
            CCTEC_Images::url_from_calendar_event( $event_attrs )
        );

        $note = $is_recurring ? " [series #{$series_index}]" : '';
        $this->log( 'info', "{$action} post {$post_id} ← instance {$instance_id} ({$name}){$note}." );
        return $action;
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Call tribe_create_event or tribe_update_event and return [post_id, action].
     * Returns [WP_Error, ''] on failure.
     */
    private function write_tec_event( int $existing_id, array $event_args, string $ref_id ): array {
        if ( $existing_id ) {
            $event_args['ID'] = $existing_id;
            $post_id = tribe_update_event( $existing_id, $event_args );
            $action  = 'updated';
        } else {
            $post_id = tribe_create_event( $event_args );
            $action  = 'created';
        }

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return [ new WP_Error( 'cctec_tec_write_fail',
                "Failed to {$action} TEC event for PCO ref {$ref_id}." ), '' ];
        }

        return [ (int) $post_id, $action ];
    }

    /**
     * Fetch selection_types for a signup and return normalised ticket data.
     * Always returns an array even on API error.
     */
    private function fetch_ticket_data( int $signup_id ): array {
        $types     = [];
        $min_price = null;
        $max_price = null;

        $body = $this->api->get_selection_types( $signup_id );
        if ( ! is_wp_error( $body ) ) {
            foreach ( $body['data'] ?? [] as $type ) {
                $ta  = $type['attributes'] ?? [];
                // Correct field per PCO Registrations OpenAPI spec: price_cents.
                $amt = isset( $ta['price_cents'] ) ? ( (float) $ta['price_cents'] ) / 100 : null;

                $types[] = [
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

        return [ 'types' => $types, 'min_price' => $min_price, 'max_price' => $max_price ];
    }

    /**
     * Find the WP post ID for a TEC event by a meta key/value pair.
     * Returns 0 if not found.
     */
    private function find_post_by_meta( string $meta_key, string $value ): int {
        if ( ! $value ) return 0;
        $posts = get_posts( [
            'post_type'      => self::TEC_CPT,
            'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
            'numberposts'    => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => $meta_key, 'value' => $value ] ],
            'no_found_rows'  => true,
        ] );
        return $posts ? (int) $posts[0] : 0;
    }

    /**
     * Trash TEC posts whose $meta_key value is no longer in $live_ids.
     * Only touches posts that have the meta key (i.e. created by this plugin).
     * Excludes already-trashed posts to avoid double-counting.
     */
    private function delete_stale( array $live_ids, string $meta_key ): int {
        $all = get_posts( [
            'post_type'     => self::TEC_CPT,
            'post_status'   => [ 'publish', 'draft', 'private', 'pending' ],
            'numberposts'   => -1,
            'fields'        => 'ids',
            'no_found_rows' => true,
            'meta_query'    => [ [ 'key' => $meta_key, 'compare' => 'EXISTS' ] ],
        ] );

        $deleted = 0;
        foreach ( $all as $post_id ) {
            $stored = get_post_meta( $post_id, $meta_key, true );
            if ( $stored && ! in_array( $stored, $live_ids, true ) ) {
                wp_trash_post( $post_id );
                $this->log( 'info', "Trashed post {$post_id} ({$meta_key}={$stored} no longer in PCO feed)." );
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Conditionally sideload image; respects the sideload_images setting.
     */
    private function maybe_sideload_image( int $post_id, string $title, string $image_url ): void {
        if ( get_option( 'cctec_sideload_images', '1' ) !== '1' ) return;
        if ( ! $image_url ) return;
        CCTEC_Images::maybe_sideload( $post_id, $image_url, $title );
    }

    /**
     * Tag an event post with a shared hidden series term.
     */
    private function tag_series( int $post_id, string $event_id, string $name ): void {
        $slug = 'pco-series-' . $event_id;
        $term = get_term_by( 'slug', $slug, self::SERIES_TAXONOMY );
        if ( ! $term ) {
            $ins = wp_insert_term( $name . ' (Series)', self::SERIES_TAXONOMY, [ 'slug' => $slug ] );
            $term_id = is_wp_error( $ins ) ? 0 : $ins['term_id'];
        } else {
            $term_id = $term->term_id;
        }
        if ( $term_id ) {
            wp_set_object_terms( $post_id, $term_id, self::SERIES_TAXONOMY );
        }
    }

    // ── Venue ─────────────────────────────────────────────────────────────────

    private function get_or_create_venue( array $location_resource ): int {
        return $this->get_or_create_venue_from_attrs(
            $location_resource['id'] ?? '',
            $location_resource['attributes'] ?? []
        );
    }

    private function get_or_create_venue_from_attrs( string $loc_id, array $attrs ): int {
        $name = sanitize_text_field( $attrs['name'] ?? '' );
        if ( ! $name ) return 0;

        if ( $loc_id ) {
            $existing = get_posts( [
                'post_type'     => 'tribe_venue',
                'numberposts'   => 1,
                'fields'        => 'ids',
                'no_found_rows' => true,
                'meta_query'    => [ [ 'key' => '_pco_location_id', 'value' => $loc_id ] ],
            ] );
            if ( $existing ) return (int) $existing[0];
        }

        $venue_id = tribe_create_venue( [
            'Venue'   => $name,
            'Address' => sanitize_text_field( $attrs['address'] ?? $attrs['street']  ?? '' ),
            'City'    => sanitize_text_field( $attrs['city']    ?? '' ),
            'State'   => sanitize_text_field( $attrs['state']   ?? '' ),
            'Zip'     => sanitize_text_field( $attrs['zip']     ?? '' ),
            'Country' => sanitize_text_field( $attrs['country'] ?? '' ),
        ] );

        if ( $venue_id && ! is_wp_error( $venue_id ) ) {
            if ( $loc_id ) update_post_meta( $venue_id, '_pco_location_id', $loc_id );
            $this->log( 'info', "Created venue {$venue_id} — {$name}." );
            return (int) $venue_id;
        }

        return 0;
    }

    // ── Date helpers ──────────────────────────────────────────────────────────

    /**
     * Convert a PCO ISO8601 UTC datetime to Y-m-d H:i:s in the site timezone.
     */
    private function iso_to_local( string $iso ): string {
        if ( ! $iso ) return '';
        try {
            $dt = new DateTimeImmutable( $iso, new DateTimeZone( 'UTC' ) );
            return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
        } catch ( Exception ) {
            return '';
        }
    }

    /**
     * Build the complete set of date/time args that tribe_create_event() needs.
     * Without the split Hour/Minute/Meridian fields, TEC silently drops the time.
     */
    private function date_args( string $start, string $end ): array {
        if ( ! $start ) return [];
        $end = $end ?: $start;
        try {
            $tz  = wp_timezone();
            $s   = new DateTimeImmutable( $start, $tz );
            $e   = new DateTimeImmutable( $end,   $tz );
        } catch ( Exception ) {
            return [];
        }
        return [
            'EventStartDate'     => $s->format( 'Y-m-d' ),
            'EventStartHour'     => $s->format( 'G' ),
            'EventStartMinute'   => $s->format( 'i' ),
            'EventStartMeridian' => $s->format( 'a' ),
            'EventEndDate'       => $e->format( 'Y-m-d' ),
            'EventEndHour'       => $e->format( 'G' ),
            'EventEndMinute'     => $e->format( 'i' ),
            'EventEndMeridian'   => $e->format( 'a' ),
        ];
    }

    // ── Cron ──────────────────────────────────────────────────────────────────

    public function reschedule_cron( $old, $new ): void {
        $ts = wp_next_scheduled( 'cctec_scheduled_sync' );
        if ( $ts ) wp_unschedule_event( $ts, 'cctec_scheduled_sync' );
        if ( $new ) wp_schedule_event( time() + 60, $new, 'cctec_scheduled_sync' );
    }

    // ── Stats tally ───────────────────────────────────────────────────────────

    private function tally( $result, array &$stats ): void {
        if ( is_wp_error( $result ) ) {
            $stats['errors']++;
            $this->log( 'error', $result->get_error_message() );
        } elseif ( $result === 'created' ) {
            $stats['created']++;
        } elseif ( $result === 'updated' ) {
            $stats['updated']++;
        }
        // 'noop' — skip silently
    }

    // ── Log ───────────────────────────────────────────────────────────────────

    public function log( string $level, string $msg ): void {
        $log   = json_decode( (string) get_option( self::LOG_OPTION, '[]' ), true ) ?: [];
        $log[] = [ 'ts' => current_time( 'mysql' ), 'level' => $level, 'msg' => $msg ];
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
