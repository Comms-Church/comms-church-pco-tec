<?php
/**
 * CCTEC_Images
 *
 * Pulls event images from PCO and stores them as WordPress featured images.
 *
 * Flow
 * ─────
 * After a successful upsert, CCTEC_Sync calls CCTEC_Images::maybe_sideload().
 * That method compares the incoming URL against _pco_image_url meta. If the URL
 * hasn't changed, it returns immediately — no HTTP request, no write. If it has
 * changed, it sideloads via media_sideload_image(), sets the featured image, and
 * records the new URL. The old attachment (if we created it) is deleted AFTER
 * the new one succeeds — so a failed download never wipes the existing image.
 *
 * PCO image fields
 * ─────────────────
 *  Calendar events  → attributes.header_image.{original|large|medium}
 *                   → attributes.logo_url (fallback)
 *  Signup resources → attributes.logo.{original|large|medium|thumb}
 *                   → attributes.logo_url (fallback)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Images {

    /**
     * Sideload $image_url as the featured image of $post_id, only if changed.
     *
     * @param int    $post_id
     * @param string $image_url  Remote URL from PCO.
     * @param string $title      Used as attachment title + alt text.
     * @return bool  True if a new image was sideloaded.
     */
    public static function maybe_sideload( int $post_id, string $image_url, string $title = '' ): bool {
        if ( ! $image_url ) return false;

        // Skip if URL unchanged.
        if ( get_post_meta( $post_id, '_pco_image_url', true ) === $image_url ) return false;

        // Load WP sideload helpers (safe to call more than once).
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Download the new image first. Only delete the old one if this succeeds.
        $new_attachment_id = media_sideload_image( $image_url, $post_id, $title, 'id' );

        if ( is_wp_error( $new_attachment_id ) ) {
            error_log( sprintf(
                'CCTEC_Images: sideload failed for post %d (%s) — %s',
                $post_id,
                $image_url,
                $new_attachment_id->get_error_message()
            ) );
            return false;
        }

        // Success — now it's safe to remove the old attachment we created.
        $old_id = (int) get_post_meta( $post_id, '_pco_image_attachment_id', true );
        if ( $old_id && get_post_meta( $old_id, '_pco_sideloaded', true ) ) {
            wp_delete_attachment( $old_id, true );
        }

        // Mark this attachment as plugin-owned so future syncs can clean it up.
        update_post_meta( $new_attachment_id, '_pco_sideloaded', '1' );

        set_post_thumbnail( $post_id, $new_attachment_id );
        update_post_meta( $post_id, '_pco_image_url',           $image_url );
        update_post_meta( $post_id, '_pco_image_attachment_id', $new_attachment_id );

        return true;
    }

    /**
     * Extract the best image URL from a PCO Calendar event attributes array.
     *
     * @param array $event_attrs  attributes from a Calendar /events resource.
     * @return string  URL or empty string.
     */
    public static function url_from_calendar_event( array $event_attrs ): string {
        $header = $event_attrs['header_image'] ?? null;
        if ( is_array( $header ) ) {
            foreach ( [ 'original', 'large', 'medium' ] as $size ) {
                if ( ! empty( $header[ $size ] ) ) return (string) $header[ $size ];
            }
        }
        return (string) ( $event_attrs['logo_url'] ?? '' );
    }

    /**
     * Extract the best image URL from a PCO Registrations signup attributes array.
     *
     * @param array $signup_attrs  attributes from a Registrations /signups resource.
     * @return string  URL or empty string.
     */
    public static function url_from_signup( array $signup_attrs ): string {
        $logo = $signup_attrs['logo'] ?? null;
        if ( is_array( $logo ) ) {
            foreach ( [ 'original', 'large', 'medium', 'thumb' ] as $size ) {
                if ( ! empty( $logo[ $size ] ) ) return (string) $logo[ $size ];
            }
        }
        return (string) ( $signup_attrs['logo_url'] ?? '' );
    }
}
