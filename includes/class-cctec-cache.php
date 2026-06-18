<?php
/**
 * CCTEC_Cache
 *
 * Thin wrapper around WordPress transients with two extras:
 *   1. Circuit-breaker — if PCO returns 429 (rate-limit), we stop hammering
 *      and serve stale data for CIRCUIT_BREAK_TTL seconds.
 *   2. Stale-while-revalidate — a long-lived copy of the last good response is
 *      kept so visitors never see a hard API error during an outage.
 *
 * The sync engine uses this to cache raw PCO API pages. TEC event posts are
 * the durable store; the cache just reduces API calls between syncs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Cache {

    const DEFAULT_TTL       = 300;   // 5 minutes
    const CIRCUIT_BREAK_TTL = 120;   // 2 minutes after a 429
    const STALE_TTL         = 86400; // keep stale copy for 24 hours

    /**
     * Fetch from cache or call $callback; stores and returns result.
     *
     * @param string   $cache_key  A stable, human-readable key (will be hashed).
     * @param callable $callback   Returns array|WP_Error from the PCO API.
     * @param int|null $ttl        Override TTL in seconds. Null = option value.
     * @return array|WP_Error
     */
    public static function remember( string $cache_key, callable $callback, ?int $ttl = null ) {
        $ttl       = $ttl ?? intval( get_option( 'cctec_cache_ttl', self::DEFAULT_TTL ) );
        $hash      = md5( $cache_key );
        $key       = 'cctec_'   . $hash;
        $stale_key = 'cctec_s_' . $hash;
        $break_key = 'cctec_b_' . $hash;

        // 1. Fresh hit
        $cached = get_transient( $key );
        if ( false !== $cached ) return $cached;

        // 2. Circuit broken — return stale if available
        if ( get_transient( $break_key ) ) {
            $stale = get_transient( $stale_key );
            if ( false !== $stale ) return $stale;
        }

        // 3. Fetch fresh
        $fresh = call_user_func( $callback );

        if ( is_wp_error( $fresh ) ) {
            $data = $fresh->get_error_data();
            if ( isset( $data['status'] ) && (int) $data['status'] === 429 ) {
                set_transient( $break_key, 1, self::CIRCUIT_BREAK_TTL );
                $stale = get_transient( $stale_key );
                if ( false !== $stale ) return $stale;
            }
            return $fresh;
        }

        set_transient( $key,       $fresh, $ttl            );
        set_transient( $stale_key, $fresh, self::STALE_TTL );

        return $fresh;
    }

    /**
     * Invalidate a specific cache key (fresh + circuit; preserves stale).
     */
    public static function flush( string $cache_key ): void {
        $hash = md5( $cache_key );
        delete_transient( 'cctec_'   . $hash );
        delete_transient( 'cctec_b_' . $hash );
    }

    /**
     * Nuke everything written by this plugin.
     * Called on manual "Clear Cache" and after a full sync completes.
     */
    public static function flush_all(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_cctec%'
                OR option_name LIKE '_transient_timeout_cctec%'"
        );
    }

    /**
     * Flush just the event-instance list pages (keeps signup cache).
     */
    public static function flush_event_pages(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_cctec_instances%'
                OR option_name LIKE '_transient_timeout_cctec_instances%'"
        );
    }

    /**
     * Invalidate all cached data for a single PCO event.
     *
     * @param string|int $pco_event_id
     */
    public static function flush_event( $pco_event_id ): void {
        self::flush( 'event_' . $pco_event_id );
        self::flush( 'instances_' . $pco_event_id );
    }
}
