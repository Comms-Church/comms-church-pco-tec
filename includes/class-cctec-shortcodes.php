<?php
/**
 * CCTEC_Shortcodes
 *
 * [pco_register]
 * ───────────────
 *   id        int     WP post ID of the TEC event. Defaults to current post.
 *   label     string  Button text. Defaults to global cctec_register_label option.
 *   color     string  Hex. Defaults to global cctec_brand_color option.
 *   class     string  Extra CSS classes.
 *   new_tab   bool    Open in new tab. Default true.
 *
 * [pco_event_card]
 * ─────────────────
 *   id             int     WP post ID. Defaults to current post.
 *   show_image     bool    Default true.
 *   show_date      bool    Default true.
 *   show_location  bool    Default true.
 *   show_price     bool    Default true.
 *   show_desc      bool    Default true.
 *   label          string  Button text.
 *   color          string  Hex brand color.
 *   image_shape    string  cinematic | square | portrait. Default cinematic.
 *
 * Auto-inject
 * ────────────
 * A Register Now button is automatically appended to the_content on TEC single
 * event pages that have a _pco_registration_url. Uses the_content filter so it
 * works with Bricks Builder, Elementor, Divi, and native TEC templates.
 * The injection is suppressed if [pco_register] is already present in the content.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CCTEC_Shortcodes {

    public function __construct() {
        add_shortcode( 'pco_register',   [ $this, 'render_register_button' ] );
        add_shortcode( 'pco_event_card', [ $this, 'render_event_card' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_assets' ] );
        add_filter( 'the_content', [ $this, 'maybe_inject_button' ] );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_front_assets(): void {
        if ( $this->needs_front_css() ) $this->do_enqueue();
    }

    private function needs_front_css(): bool {
        // TEC single event pages always need it (auto-injected button).
        if ( is_singular( 'tribe_events' ) ) return true;
        global $post;
        if ( ! $post instanceof WP_Post ) return false;
        return has_shortcode( $post->post_content, 'pco_register' )
            || has_shortcode( $post->post_content, 'pco_event_card' );
    }

    private function do_enqueue(): void {
        if ( wp_style_is( 'cctec-front', 'enqueued' ) ) return;
        wp_enqueue_style( 'cctec-front', CCTEC_PLUGIN_URL . 'assets/front.css', [], CCTEC_VERSION );
    }

    // ── [pco_register] ────────────────────────────────────────────────────────

    public function render_register_button( $atts ): string {
        $atts = shortcode_atts( [
            'id'      => 0,
            'label'   => '',
            'color'   => '',
            'class'   => '',
            'new_tab' => 'true',
        ], $atts, 'pco_register' );

        $this->do_enqueue();

        $post_id = $atts['id'] ? (int) $atts['id'] : get_the_ID();
        if ( ! $post_id ) return '';

        $reg_url = get_post_meta( $post_id, '_pco_registration_url', true );
        if ( ! $reg_url ) return '';

        $label  = $atts['label'] ?: $this->default_label();
        $color  = $this->resolve_color( $atts['color'] );
        $target = $atts['new_tab'] !== 'false' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $extra  = $atts['class'] ? ' ' . esc_attr( $atts['class'] ) : '';

        return sprintf(
            '<a href="%s" class="cctec-btn cctec-btn-primary%s" style="--cctec-brand:%s"%s>%s</a>',
            esc_url( $reg_url ),
            $extra,
            esc_attr( $color ),
            $target,
            esc_html( $label )
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
            'label'         => '',
            'color'         => '',
            'image_shape'   => 'cinematic',
        ], $atts, 'pco_event_card' );

        $this->do_enqueue();

        $post_id = $atts['id'] ? (int) $atts['id'] : get_the_ID();
        if ( ! $post_id ) return '';

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'tribe_events' ) return '';

        $color     = $this->resolve_color( $atts['color'] );
        $label     = $atts['label'] ?: $this->default_label();
        $reg_url   = get_post_meta( $post_id, '_pco_registration_url', true );
        $min_price = get_post_meta( $post_id, '_pco_min_price', true );
        $max_price = get_post_meta( $post_id, '_pco_max_price', true );
        $shape     = in_array( $atts['image_shape'], [ 'cinematic', 'square', 'portrait' ], true )
                     ? $atts['image_shape'] : 'cinematic';

        // Image
        $image_html = '';
        if ( $atts['show_image'] !== 'false' ) {
            if ( has_post_thumbnail( $post_id ) ) {
                $image_html = get_the_post_thumbnail( $post_id, 'large', [
                    'loading' => 'lazy',
                    'alt'     => esc_attr( $post->post_title ),
                ] );
            } else {
                $image_html = '<div class="cctec-img-placeholder" aria-hidden="true">'
                    . '<span>' . esc_html( $post->post_title ) . '</span></div>';
            }
        }

        // Date
        $date_str = '';
        if ( $atts['show_date'] !== 'false' ) {
            $start   = get_post_meta( $post_id, '_EventStartDate', true );
            $end     = get_post_meta( $post_id, '_EventEndDate',   true );
            $all_day = get_post_meta( $post_id, '_EventAllDay',    true ) === 'yes';
            if ( $start ) $date_str = $this->format_date( $start, $end, $all_day );
        }

        // Venue
        $venue_str = '';
        if ( $atts['show_location'] !== 'false' ) {
            $vid = get_post_meta( $post_id, '_EventVenueID', true );
            if ( $vid ) $venue_str = get_the_title( (int) $vid );
        }

        // Price
        $price_html = '';
        if ( $atts['show_price'] !== 'false' && $min_price !== '' && $min_price !== null ) {
            $min = (float) $min_price;
            $max = (float) $max_price;
            if ( $min === 0.0 ) {
                $price_html = '<span class="cctec-price-free">'
                    . esc_html__( 'Free', 'comms-church-pco-tec' ) . '</span>';
            } elseif ( $max && $max > $min ) {
                $price_html = '<span class="cctec-price">'
                    . esc_html( sprintf( __( 'From $%s', 'comms-church-pco-tec' ), number_format( $min, 2 ) ) )
                    . '</span>';
            } else {
                $price_html = '<span class="cctec-price">'
                    . esc_html( '$' . number_format( $min, 2 ) ) . '</span>';
            }
        }

        // Description excerpt
        $desc = '';
        if ( $atts['show_desc'] !== 'false' && $post->post_content ) {
            $desc = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 );
        }

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
            <?php if ( $date_str ) : ?>
            <p class="cctec-meta-item">
                <?php echo $this->icon( 'calendar' ); ?>
                <span><?php echo esc_html( $date_str ); ?></span>
            </p>
            <?php endif; ?>

            <?php if ( $venue_str ) : ?>
            <p class="cctec-meta-item">
                <?php echo $this->icon( 'location' ); ?>
                <span><?php echo esc_html( $venue_str ); ?></span>
            </p>
            <?php endif; ?>

            <?php if ( $price_html ) : ?>
            <p class="cctec-meta-item cctec-meta-price"><?php echo $price_html; ?></p>
            <?php endif; ?>
        </div>

        <?php if ( $desc ) : ?>
        <p class="cctec-card-desc"><?php echo esc_html( $desc ); ?></p>
        <?php endif; ?>

        <?php if ( $reg_url ) : ?>
        <div class="cctec-card-actions">
            <a href="<?php echo esc_url( $reg_url ); ?>"
               class="cctec-btn cctec-btn-primary"
               target="_blank" rel="noopener noreferrer">
                <?php echo esc_html( $label ); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>

</article>
        <?php return ob_get_clean();
    }

    // ── Auto-inject on TEC single event pages ─────────────────────────────────

    /**
     * Append a Register Now button after the event content.
     *
     * Guards:
     *  - Only fires on singular tribe_events posts.
     *  - Only fires in the main query loop.
     *  - Suppressed if [pco_register] shortcode is already in the content
     *    (prevents double button when both shortcode + auto-inject are active).
     *  - Suppressed when the event has no _pco_registration_url.
     */
    public function maybe_inject_button( string $content ): string {
        if ( ! is_singular( 'tribe_events' ) ) return $content;
        if ( ! in_the_loop() || ! is_main_query() ) return $content;

        $post_id = get_the_ID();
        if ( ! $post_id ) return $content;

        // Don't double-up if a [pco_register] shortcode is already rendered.
        if ( has_shortcode( get_post_field( 'post_content', $post_id ), 'pco_register' ) ) {
            return $content;
        }

        $reg_url = get_post_meta( $post_id, '_pco_registration_url', true );
        if ( ! $reg_url ) return $content;

        $this->do_enqueue();

        $color = $this->resolve_color( '' );
        $label = $this->default_label();

        $button = '<div class="cctec-single-register">'
            . '<a href="' . esc_url( $reg_url ) . '"'
            . ' class="cctec-btn cctec-btn-primary"'
            . ' style="--cctec-brand:' . esc_attr( $color ) . '"'
            . ' target="_blank" rel="noopener noreferrer">'
            . esc_html( $label )
            . '</a>'
            . '</div>';

        return $content . $button;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolve_color( string $color ): string {
        if ( $color && preg_match( '/^#[0-9a-fA-F]{3,6}$/', $color ) ) return $color;
        return (string) get_option( 'cctec_brand_color', '#1a4a8a' );
    }

    private function default_label(): string {
        return (string) get_option( 'cctec_register_label', __( 'Register Now', 'comms-church-pco-tec' ) );
    }

    private function format_date( string $start, string $end, bool $all_day ): string {
        $s = strtotime( $start );
        $e = $end ? strtotime( $end ) : null;

        if ( $all_day ) {
            if ( $e && wp_date( 'Y-m-d', $s ) !== wp_date( 'Y-m-d', $e ) ) {
                return wp_date( 'F j', $s ) . ' – ' . wp_date( 'F j, Y', $e );
            }
            return wp_date( 'F j, Y', $s );
        }

        $out = wp_date( 'F j, Y \a\t g:i a', $s );
        if ( $e ) {
            $out .= wp_date( 'Y-m-d', $s ) === wp_date( 'Y-m-d', $e )
                ? ' – ' . wp_date( 'g:i a', $e )
                : ' – ' . wp_date( 'F j, Y \a\t g:i a', $e );
        }
        return $out;
    }

    private function icon( string $name ): string {
        $icons = [
            'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="14" height="14"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>',
            'location' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="14" height="14"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>',
        ];
        return $icons[ $name ] ?? '';
    }
}
