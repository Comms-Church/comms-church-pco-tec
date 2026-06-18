<?php
/**
 * CCTEC_API
 *
 * Authenticated client for two Planning Center API modules:
 *   • Calendar v2  — events, event instances, resource bookings, locations
 *   • Registrations v2 — signups matched to calendar events (optional)
 *
 * Both APIs share the same App ID / Secret credential pair. A single instance
 * is shared across the plugin via CCTEC_Sync.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_API {

    const CALENDAR_BASE      = 'https://api.planningcenteronline.com/calendar/v2';
    const REGISTRATIONS_BASE = 'https://api.planningcenteronline.com/registrations/v2';

    private string $app_id;
    private string $secret;

    public function __construct() {
        $this->app_id = (string) get_option( 'cctec_app_id', '' );
        $this->secret = (string) get_option( 'cctec_secret', '' );
    }

    // ── Credential helpers ────────────────────────────────────────────────────

    public function is_configured(): bool {
        return $this->app_id !== '' && $this->secret !== '';
    }

    // ── Low-level request ─────────────────────────────────────────────────────

    /**
     * Authenticated GET against any PCO API base URL.
     */
    private function get( string $base, string $endpoint, array $params = [] ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error(
                'cctec_not_configured',
                __( 'Planning Center API credentials are not configured.', 'comms-church-pco-tec' )
            );
        }

        $url      = add_query_arg( $params, $base . $endpoint );
        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $this->app_id . ':' . $this->secret ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['errors'][0]['detail']
                ?? $body['errors'][0]['title']
                ?? __( 'Unknown PCO API error.', 'comms-church-pco-tec' );
            return new WP_Error( 'cctec_api_error', $msg, [ 'status' => $code ] );
        }

        return $body;
    }

    // ── Calendar v2 endpoints ─────────────────────────────────────────────────

    public function get_events( array $args = [] ): array|WP_Error {
        $defaults = [
            'per_page' => 100,
            'include'  => 'event_instances,event_times,location,tags',
        ];
        return $this->get( self::CALENDAR_BASE, '/events', wp_parse_args( $args, $defaults ) );
    }

    public function get_event( $id ): array|WP_Error {
        return $this->get( self::CALENDAR_BASE, '/events/' . intval( $id ), [
            'include' => 'event_instances,event_times,location,tags',
        ] );
    }

    public function get_event_instances( $event_id, array $args = [] ): array|WP_Error {
        return $this->get( self::CALENDAR_BASE, '/events/' . intval( $event_id ) . '/event_instances',
            wp_parse_args( $args, [ 'per_page' => 50 ] )
        );
    }

    /**
     * Fetch all future event_instances, paginated. Used for Calendar and Both modes.
     * Builds included index once per page to avoid memory explosion.
     *
     * @param int $max_pages  Safety cap.
     * @return array  Flat array of event_instance resource objects.
     */
    public function get_all_event_instances( int $max_pages = 20 ): array {
        $results = [];
        $offset  = 0;
        $per     = 100;
        $page    = 0;

        do {
            // Always filter to future instances to avoid loading all historical data.
            $params = [
                'per_page' => $per,
                'offset'   => $offset,
                'include'  => 'event,location',
                'filter'   => 'future',
            ];

            $body = $this->get( self::CALENDAR_BASE, '/event_instances', $params );
            if ( is_wp_error( $body ) ) break;

            $data     = $body['data']     ?? [];
            $included = $body['included'] ?? [];
            $total    = $body['meta']['total_count'] ?? count( $data );

            // Build included index ONCE per page, attach reference to each instance.
            // Do NOT call index_included() inside the loop — that duplicates the full
            // included blob onto every instance and exhausts PHP memory on large calendars.
            $included_index = $this->index_included( $included );
            foreach ( $data as &$instance ) {
                $instance['_included'] = $included_index;
            }
            unset( $instance, $included_index );

            $results = array_merge( $results, $data );
            $offset += $per;
            $page++;

        } while ( count( $results ) < $total && $page < $max_pages );

        return $results;
    }

    /**
     * Fetch all unarchived signups with their next SignupTime and location included.
     * Used for Registrations-only mode.
     *
     * Dates live on SignupTime resources (relationships.next_signup_time), NOT on
     * the Calendar API. The Registrations API is self-contained.
     *
     * @param int $max_pages
     * @return array  Flat array of signup resource objects with _next_time and _location attached.
     */
    public function get_all_registration_instances( int $max_pages = 20 ): array {
        $results = [];
        $offset  = 0;
        $per     = 100;
        $page    = 0;

        do {
            $body = $this->get( self::REGISTRATIONS_BASE, '/signups', [
                'per_page' => $per,
                'offset'   => $offset,
                'filter'   => 'unarchived',
                'include'  => 'next_signup_time,signup_location',
            ] );
            if ( is_wp_error( $body ) ) break;

            $data     = $body['data']     ?? [];
            $included = $body['included'] ?? [];
            $total    = $body['meta']['total_count'] ?? count( $data );

            $times_map    = $this->build_map( $included, 'SignupTime' );
            $location_map = $this->build_map( $included, 'SignupLocation' );

            foreach ( $data as &$signup ) {
                // Attach next signup time (carries starts_at, ends_at, all_day).
                $time_rel = $signup['relationships']['next_signup_time']['data'] ?? null;
                $signup['_next_time'] = $time_rel ? ( $times_map[ $time_rel['id'] ] ?? null ) : null;

                // Attach location.
                $loc_rel = $signup['relationships']['signup_location']['data'] ?? null;
                $signup['_location'] = $loc_rel ? ( $location_map[ $loc_rel['id'] ] ?? null ) : null;

                $results[] = $signup;
            }
            unset( $signup );

            $offset += $per;
            $page++;

        } while ( count( $results ) < $total && $page < $max_pages );

        return $results;
    }

    /**
     * Build a map of included resources keyed by ID, filtered by type.
     * Mirrors the working plugin's build_map() helper.
     */
    public function build_map( array $included, string $type ): array {
        $map = [];
        foreach ( $included as $item ) {
            if ( ( $item['type'] ?? '' ) === $type ) {
                $map[ $item['id'] ] = $item;
            }
        }
        return $map;
    }

    public function get_locations( array $args = [] ): array|WP_Error {
        return $this->get( self::CALENDAR_BASE, '/locations', wp_parse_args( $args, [ 'per_page' => 100 ] ) );
    }

    // ── Registrations v2 endpoints ────────────────────────────────────────────

    public function get_signups( array $args = [] ): array|WP_Error {
        return $this->get( self::REGISTRATIONS_BASE, '/signups',
            wp_parse_args( $args, [ 'per_page' => 100, 'filter' => 'unarchived' ] )
        );
    }

    public function get_signup( int $signup_id ): array|WP_Error {
        return $this->get( self::REGISTRATIONS_BASE, '/signups/' . $signup_id, [
            'include' => 'selection_types,signup_location',
        ] );
    }

    public function get_selection_types( int $signup_id ): array|WP_Error {
        return $this->get( self::REGISTRATIONS_BASE,
            '/signups/' . $signup_id . '/selection_types',
            [ 'per_page' => 50, 'filter' => 'publicly_available' ]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a keyed map of included resources by type+id for O(1) lookups.
     * e.g. $map['Event']['12345'] = [ 'id'=>..., 'attributes'=>... ]
     */
    public function index_included( array $included ): array {
        $map = [];
        foreach ( $included as $resource ) {
            $type = $resource['type'] ?? '';
            $id   = $resource['id']   ?? '';
            if ( $type && $id ) {
                $map[ $type ][ $id ] = $resource;
            }
        }
        return $map;
    }

    public function get_signups_by_name(): array {
        $body = $this->get_signups();
        if ( is_wp_error( $body ) ) return [];

        $map = [];
        foreach ( $body['data'] ?? [] as $signup ) {
            $name = strtolower( trim( $signup['attributes']['name'] ?? '' ) );
            if ( $name ) $map[ $name ] = $signup;
        }
        return $map;
    }
}
