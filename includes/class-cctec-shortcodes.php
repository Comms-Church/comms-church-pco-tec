<?php
/**
 * CCTEC_Shortcodes
 *
 * Two shortcodes that surface PCO registration data stored on TEC event posts.
 *
 * [pco_register]
 * ───────────────
 * Renders a "Register Now" button. Can be dropped on any page/post.
 *
 * Attributes:
 *   id          int     (required) WP post ID of the TEC event. If omitted and
 *                       the shortcode is inside a TEC single-event template, the
 *                       current post ID is used automatically.
 *   label       string  Button text. Default: "Register Now"
 *   color       string  Hex background color. Default: global brand color option.
 *   class       string  Extra CSS classes on the <a> tag.
 *   new_tab     bool    Open in new tab? Default: true.
 *
 * Example:
 *   [pco_register id="42" label="Sign Up" color="#c0392b"]
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * [pco_event_card]
 * ─────────────────
 * Renders a full event card: featured image, title, date, location, price, button.
 * The card is self-contained and can be dropped anywhere.
 *
 * Attributes:
 *   id           int     (required) WP post ID of the TEC event.
 *   show_image   bool    Show featured image. Default: true.
 *   show_date    bool    Show event date/time.    Default: true.
 *   show_location bool   Show venue name.         Default: true.
 *   show_price   bool    Show price range.         Default: true.
 *   show_desc    bool    Show excerpt/description. Default: true.
 *   label        string  Button label. Default: "Register Now".
 *   color        string  Brand color hex.
 *   image_shape  string  cinematic | square | portrait. Default: cinematic.
 *
 * Example:
 *   [pco_event_card id="42" show_desc="false" label="Reserve Your Spot"]
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Shortcodes {

    public function __construct() {
        add_shortcode( 'pco_register',   [ $this, 'render_register_button' ] );
        add_shortcode( 'pco_event_card', [ $this, 'render_event_card' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_assets' ] );

        // Automatically inject the Register button on TEC single event pages,
        // after the event description and before the Add to Calendar widget.
        add_action( 'tribe_events_single_event_after_the_content', [ $this, 'inject_register_button' ] );
    }

    // ── Asset loading ─────────────────────────────────────────────────────────

    public function enqueue_front_assets(): void {
        // Only load if a shortcode is actually present on this page.
        // We enqueue lazily on first render instead, but this hook handles
        // cases where content is loaded via block editor or widget.
        if ( $this->page_has_shortcode() ) {
            $this->do_enqueue();
        }
    }

    private function do_enqueue(): void {
        if ( wp_style_is( 'cctec-front', 'enqueued' ) ) return;
        wp_enqueue_style( 'cctec-front', CCTEC_PLUGIN_URL . 'assets/front.css', [], CCTEC_VERSION );
    }

    private function page_has_shortcode(): bool {
        global $post;
        // Also load on TEC single event pages so the injected button is styled.
        if ( function_exists( 'tribe_is_event' ) && tribe_is_event() ) return true;
        if ( ! is_a( $post, 'WP_Post' ) ) return false;
        return has_shortcode( $post->post_content, 'pco_register' )
            || has_shortcode( $post->post_content, 'pco_event_card' );
    }

    // ── [pco_register] ────────────────────────────────────────────────────────

    public function render_register_button( $atts ): string {
        $atts = shortcode_atts( [
            'id'      => 0,
            'label'   => __( 'Register Now', 'comms-church-pco-tec' ),
            'color'   => '',
            'class'   => '',
            'new_tab' => 'true',
        ], $atts, 'pco_register' );

        $this->do_enqueue();

        $post_id = $atts['id'] ? intval( $atts['id'] ) : get_the_ID();
        if ( ! $post_id ) return '';

        $reg_url = get_post_meta( $post_id, '_pco_registration_url', true );
        if ( ! $reg_url ) return '';

        $color  = $this->resolve_color( $atts['color'] );
        $target = $atts['new_tab'] !== 'false' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $extra  = $atts['class'] ? ' ' . esc_attr( $atts['class'] ) : '';

        return sprintf(
            '<a href="%s" class="cctec-btn cctec-btn-primary%s" style="--cctec-brand:%s"%s>%s</a>',
            esc_url( $reg_url ),
            $extra,
            esc_attr( $color ),
            $target,
            esc_html( $atts['label'] )
        );
    }

    // ── [pco_event_card] ──────────────────────────────────────────────────────

    public function render_event_card( $atts ): string {
        $atts = shortcode_atts( [
            'id'            => 0,
            'show_image'    => 'true',
            'show_date'     => 'true',
            'show_location' => 'true',
            'show_price'    => 'true',
            'show_desc'     => 'true',
            'label'         => __( 'Register Now', 'comms-church-pco-tec' ),
            'color'         => '',
            'image_shape'   => 'cinematic',
        ], $atts, 'pco_event_card' );

        $this->do_enqueue();

        $post_id = $atts['id'] ? intval( $atts['id'] ) : get_the_ID();
        if ( ! $post_id ) return '';

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'tribe_events' ) return '';

        // ── Pull all the data we need ─────────────────────────────────────
        $color    = $this->resolve_color( $atts['color'] );
        $reg_url  = get_post_meta( $post_id, '_pco_registration_url', true );
        $min_price = get_post_meta( $post_id, '_pco_min_price', true );
        $max_price = get_post_meta( $post_id, '_pco_max_price', true );
        $shape    = in_array( $atts['image_shape'], [ 'cinematic', 'square', 'portrait' ], true )
                    ? $atts['image_shape'] : 'cinematic';

        // ── Image ─────────────────────────────────────────────────────────
        $image_html = '';
        if ( $atts['show_image'] !== 'false' ) {
            if ( has_post_thumbnail( $post_id ) ) {
                $image_html = get_the_post_thumbnail( $post_id, 'large', [
                    'loading' => 'lazy',
                    'alt'     => esc_attr( $post->post_title ),
                ] );
            } else {
                // Placeholder using the brand color
                $image_html = '<div class="cctec-image-placeholder" aria-hidden="true"><span>'
                    . esc_html( $post->post_title ) . '</span></div>';
            }
        }

        // ── Date/time from TEC meta ────────────────────────────────────────
        $date_html = '';
        if ( $atts['show_date'] !== 'false' ) {
            $start = get_post_meta( $post_id, '_EventStartDate', true );
            $end   = get_post_meta( $post_id, '_EventEndDate',   true );
            $all_day = get_post_meta( $post_id, '_EventAllDay',  true );
            if ( $start ) {
                $date_html = $this->format_event_date( $start, $end, $all_day === 'yes' );
            }
        }

        // ── Venue from TEC ────────────────────────────────────────────────
        $venue_html = '';
        if ( $atts['show_location'] !== 'false' ) {
            $venue_id = get_post_meta( $post_id, '_EventVenueID', true );
            if ( $venue_id ) {
                $venue_name = get_the_title( $venue_id );
                if ( $venue_name ) {
                    $venue_html = esc_html( $venue_name );
                }
            }
        }

        // ── Price ─────────────────────────────────────────────────────────
        $price_html = '';
        if ( $atts['show_price'] !== 'false' && ( $min_price !== '' && $min_price !== null ) ) {
            $min = (float) $min_price;
            $max = (float) $max_price;
            if ( $min === 0.0 ) {
                $price_html = '<span class="cctec-price-free">' . esc_html__( 'Free', 'comms-church-pco-tec' ) . '</span>';
            } elseif ( $max && $max > $min ) {
                $price_html = '<span class="cctec-price">'
                    . esc_html( sprintf( __( 'From $%s', 'comms-church-pco-tec' ), number_format( $min, 2 ) ) )
                    . '</span>';
            } else {
                $price_html = '<span class="cctec-price">'
                    . esc_html( '$' . number_format( $min, 2 ) )
                    . '</span>';
            }
        }

        // ── Description ───────────────────────────────────────────────────
        $desc_html = '';
        if ( $atts['show_desc'] !== 'false' && $post->post_content ) {
            $desc_html = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 );
        }

        // ── Build output ──────────────────────────────────────────────────
        ob_start(); ?>
        <article class="cctec-event-card" style="--cctec-brand:<?php echo esc_attr( $color ); ?>">

            <?php if ( $image_html ) : ?>
            <div class="cctec-card-image cctec-shape-<?php echo esc_attr( $shape ); ?>">
                <?php echo $image_html; ?>
            </div>
            <?php endif; ?>

            <div class="cctec-card-body">
                <h3 class="cctec-card-title">
                    <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
                        <?php echo esc_html( $post->post_title ); ?>
                    </a>
                </h3>

                <div class="cctec-card-meta">
                    <?php if ( $date_html ) : ?>
                    <p class="cctec-meta-date">
                        <?php echo $this->icon_calendar(); ?>
                        <span><?php echo esc_html( $date_html ); ?></span>
                    </p>
                    <?php endif; ?>

                    <?php if ( $venue_html ) : ?>
                    <p class="cctec-meta-location">
                        <?php echo $this->icon_location(); ?>
                        <span><?php echo $venue_html; ?></span>
                    </p>
                    <?php endif; ?>

                    <?php if ( $price_html ) : ?>
                    <p class="cctec-meta-price"><?php echo $price_html; ?></p>
                    <?php endif; ?>
                </div>

                <?php if ( $desc_html ) : ?>
                <p class="cctec-card-desc"><?php echo esc_html( $desc_html ); ?></p>
                <?php endif; ?>

                <?php if ( $reg_url ) : ?>
                <div class="cctec-card-actions">
                    <a href="<?php echo esc_url( $reg_url ); ?>"
                       class="cctec-btn cctec-btn-primary"
                       target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html( $atts['label'] ); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>

        </article>
        <?php return ob_get_clean();
    }

    // ── Auto-inject on TEC single event pages ────────────────────────────────

    /**
     * Fires after the event description on TEC single event pages.
     * Renders a Register Now button only when the event has a PCO registration URL.
     * Respects the global brand color and a 'disable' option for per-event opt-out.
     */
    public function inject_register_button(): void {
        $post_id = get_the_ID();
        if ( ! $post_id ) return;

        // Only show on events synced from PCO (have a registration URL).
        $reg_url = get_post_meta( $post_id, '_pco_registration_url', true );
        if ( ! $reg_url ) return;

        $color = $this->resolve_color( '' );
        $label = get_option( 'cctec_register_label', __( 'Register Now', 'comms-church-pco-tec' ) );

        $this->do_enqueue();

        echo '<div class="cctec-single-register">';
        echo '<a href="' . esc_url( $reg_url ) . '" class="cctec-btn cctec-btn-primary" '
           . 'style="--cctec-brand:' . esc_attr( $color ) . '" '
           . 'target="_blank" rel="noopener noreferrer">'
           . esc_html( $label )
           . '</a>';
        echo '</div>';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolve_color( string $color ): string {
        if ( $color && preg_match( '/^#[0-9a-fA-F]{3,6}$/', $color ) ) return $color;
        return (string) get_option( 'cctec_brand_color', '#1a4a8a' );
    }

    private function format_event_date( string $start, string $end, bool $all_day ): string {
        $s_ts = strtotime( $start );
        $e_ts = $end ? strtotime( $end ) : null;

        if ( $all_day ) {
            if ( $e_ts && wp_date( 'Y-m-d', $s_ts ) !== wp_date( 'Y-m-d', $e_ts ) ) {
                return wp_date( 'F j', $s_ts ) . ' – ' . wp_date( 'F j, Y', $e_ts );
            }
            return wp_date( 'F j, Y', $s_ts );
        }

        $out = wp_date( 'F j, Y \a\t g:i a', $s_ts );
        if ( $e_ts ) {
            $out .= wp_date( 'Y-m-d', $s_ts ) === wp_date( 'Y-m-d', $e_ts )
                ? ' – ' . wp_date( 'g:i a', $e_ts )
                : ' – ' . wp_date( 'F j, Y \a\t g:i a', $e_ts );
        }
        return $out;
    }

    private function icon_calendar(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="14" height="14"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>';
    }

    private function icon_location(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="14" height="14"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>';
    }
}
