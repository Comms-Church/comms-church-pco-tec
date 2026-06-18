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
     *
     * @param string $base     One of the BASE constants.
     * @param string $endpoint Path starting with '/', e.g. '/events'.
     * @param array  $params   Query-string parameters.
     * @return array|WP_Error  Decoded JSON array or WP_Error.
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

    /**
     * Fetch a page of events.
     *
     * Useful params:
     *   per_page   int  (max 100)
     *   offset     int
     *   filter     string  'future' | 'past' | 'all'  (PCO uses date ranges on event instances; filter here is advisory)
     *   after      ISO8601 datetime – only events whose instances start on/after this
     *   before     ISO8601 datetime
     *   include    comma list: 'event_instances,event_times,location,tags,event_connections'
     *
     * @return array|WP_Error
     */
    public function get_events( array $args = [] ): array|WP_Error {
        $defaults = [
            'per_page' => 100,
            'include'  => 'event_instances,event_times,location,tags',
        ];
        return $this->get( self::CALENDAR_BASE, '/events', wp_parse_args( $args, $defaults ) );
    }

    /**
     * Fetch a single event by its PCO calendar event ID.
     *
     * @param string|int $id
     * @return array|WP_Error
     */
    public function get_event( $id ): array|WP_Error {
        return $this->get( self::CALENDAR_BASE, '/events/' . intval( $id ), [
            'include' => 'event_instances,event_times,location,tags',
        ] );
    }

    /**
     * Fetch event instances (occurrences) for a specific event.
     * Instances carry the concrete start/end datetimes.
     *
     * @param string|int $event_id
     * @param array      $args     Optional per_page, offset, filter
     * @return array|WP_Error
     */
    public function get_event_instances( $event_id, array $args = [] ): array|WP_Error {
        return $this->get( self::CALENDAR_BASE, '/events/' . intval( $event_id ) . '/event_instances',
            wp_parse_args( $args, [ 'per_page' => 50 ] )
        );
    }

    /**
     * Fetch all event_instances (upcoming) in one shot — used for the full sync.
     * Iterates pages automatically up to $max_pages.
     *
     * @param string $after      ISO8601 — omit events before this date.
     * @param int    $max_pages  Safety cap to avoid runaway loops.
     * @return array             Flat array of event_instance resource objects.
     */
    public function get_all_event_instances( string $after = '', int $max_pages = 20 ): array {
        $results = [];
        $offset  = 0;
        $per     = 100;
        $page    = 0;

        do {
            $params = [ 'per_page' => $per, 'offset' => $offset, 'include' => 'event,location' ];
            if ( $after ) $params['filter'] = 'future'; // PCO calendar supports 'future' instance filter

            $body = $this->get( self::CALENDAR_BASE, '/event_instances', $params );
            if ( is_wp_error( $body ) ) break;

            $data     = $body['data']     ?? [];
            $included = $body['included'] ?? [];
            $total    = $body['meta']['total_count'] ?? count( $data );

            // Index included resources so the sync engine can do fast lookups
            foreach ( $data as &$instance ) {
                $instance['_included'] = $this->index_included( $included );
            }
            unset( $instance );

            $results = array_merge( $results, $data );
            $offset += $per;
            $page++;

        } while ( count( $results ) < $total && $page < $max_pages );

        return $results;
    }

    /**
     * Fetch resource locations (venues) from PCO Calendar.
     *
     * @return array|WP_Error
     */
    public function get_locations( array $args = [] ): array|WP_Error {
        return $this->get( self::CALENDAR_BASE, '/locations', wp_parse_args( $args, [ 'per_page' => 100 ] ) );
    }

    // ── Registrations v2 endpoints ────────────────────────────────────────────

    /**
     * Fetch all signups, optionally filtered by status.
     * Used when 'pull_registrations' setting is on to match signups to calendar events.
     *
     * @return array|WP_Error
     */
    public function get_signups( array $args = [] ): array|WP_Error {
        return $this->get( self::REGISTRATIONS_BASE, '/signups',
            wp_parse_args( $args, [ 'per_page' => 100, 'filter' => 'unarchived' ] )
        );
    }

    /**
     * Fetch a single signup with its selection types (ticket tiers / prices).
     *
     * @param int $signup_id
     * @return array|WP_Error
     */
    public function get_signup( int $signup_id ): array|WP_Error {
        return $this->get( self::REGISTRATIONS_BASE, '/signups/' . $signup_id, [
            'include' => 'selection_types,signup_location',
        ] );
    }

    /**
     * Fetch selection types (ticket/price tiers) for a signup.
     *
     * @param int $signup_id
     * @return array|WP_Error
     */
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
     *
     * @param array $included  The 'included' array from a PCO JSON:API response.
     * @return array
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

    /**
     * Build an indexed map of signups keyed by the signup name (lowercased/trimmed)
     * so the sync engine can fuzzy-match them to calendar event names.
     *
     * @return array  [ 'event name' => signup_resource ]
     */
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
