<?php
/**
 * CCTEC_Images
 *
 * Pulls the event image (header/logo) from PCO and stores it as the WordPress
 * featured image (post thumbnail) on the corresponding TEC event post.
 *
 * How it works
 * ─────────────
 * After each successful upsert in CCTEC_Sync, the sync engine calls
 * CCTEC_Images::maybe_sideload( $post_id, $image_url, $title ).
 *
 * maybe_sideload() compares $image_url against the previously stored
 * _pco_image_url meta. If it hasn't changed, it returns immediately (no
 * extra HTTP request, no extra media-library entry). When the URL is new or
 * different, it sideloads the file via media_sideload_image(), sets it as
 * the featured image, and saves the URL so the next sync can skip it.
 *
 * PCO image fields used
 * ──────────────────────
 *  Calendar events   → attributes.header_image.original  (preferred)
 *                    → attributes.logo_url                (fallback)
 *  Registration signups → attributes.logo_url
 *
 * These are resolved in CCTEC_Sync before calling maybe_sideload().
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Images {

    /**
     * Sideload $image_url as the featured image of $post_id if it has changed.
     *
     * @param int    $post_id    WP post ID of the TEC event.
     * @param string $image_url  Remote image URL from PCO (may be empty string).
     * @param string $title      Event name — used as the attachment title/alt.
     * @return bool  True if a new image was sideloaded, false if skipped/failed.
     */
    public static function maybe_sideload( int $post_id, string $image_url, string $title = '' ): bool {
        if ( ! $image_url ) return false;

        // Skip if the URL hasn't changed since the last sync.
        $stored = get_post_meta( $post_id, '_pco_image_url', true );
        if ( $stored === $image_url ) return false;

        // media_sideload_image() lives in wp-admin/includes — load it on the front end.
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Remove any existing featured image attachment that this plugin created,
        // so the media library doesn't grow unboundedly across re-syncs.
        $old_thumb_id = (int) get_post_meta( $post_id, '_pco_image_attachment_id', true );
        if ( $old_thumb_id ) {
            // Only delete if it was created by this plugin (has our marker meta).
            if ( get_post_meta( $old_thumb_id, '_pco_sideloaded', true ) ) {
                wp_delete_attachment( $old_thumb_id, true );
            }
        }

        // Sideload and get the new attachment ID.
        $attachment_id = media_sideload_image( $image_url, $post_id, $title, 'id' );

        if ( is_wp_error( $attachment_id ) ) {
            // Log failure but don't crash the sync.
            error_log( 'CCTEC_Images: failed to sideload ' . $image_url . ' — ' . $attachment_id->get_error_message() );
            return false;
        }

        // Mark the attachment so we know we own it (safe to delete on re-sync).
        update_post_meta( $attachment_id, '_pco_sideloaded', '1' );

        // Set as featured image.
        set_post_thumbnail( $post_id, $attachment_id );

        // Store the current URL and attachment ID for future change-detection.
        update_post_meta( $post_id, '_pco_image_url',           $image_url );
        update_post_meta( $post_id, '_pco_image_attachment_id', $attachment_id );

        return true;
    }

    /**
     * Extract the best available image URL from a PCO Calendar event resource.
     * Prefers the full header image; falls back to logo_url.
     *
     * @param array $event_attrs  The 'attributes' array of a PCO Calendar event resource.
     * @return string  URL or empty string.
     */
    public static function url_from_calendar_event( array $event_attrs ): string {
        // Calendar v2: header_image is an object with original/medium/thumb keys.
        $header = $event_attrs['header_image'] ?? null;
        if ( is_array( $header ) ) {
            foreach ( [ 'original', 'large', 'medium' ] as $size ) {
                if ( ! empty( $header[ $size ] ) ) return (string) $header[ $size ];
            }
        }
        // Fallback: some events expose a flat logo_url.
        return (string) ( $event_attrs['logo_url'] ?? '' );
    }

    /**
     * Extract the best available image URL from a PCO Registrations signup resource.
     *
     * @param array $signup_attrs  The 'attributes' array of a PCO signup resource.
     * @return string  URL or empty string.
     */
    public static function url_from_signup( array $signup_attrs ): string {
        // Registrations v2: logo is a nested object with original/thumb.
        $logo = $signup_attrs['logo'] ?? null;
        if ( is_array( $logo ) ) {
            foreach ( [ 'original', 'large', 'medium', 'thumb' ] as $size ) {
                if ( ! empty( $logo[ $size ] ) ) return (string) $logo[ $size ];
            }
        }
        // Flat field fallback used by some response shapes.
        return (string) ( $signup_attrs['logo_url'] ?? '' );
    }
}
