<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Shortcode {

    public static function init() {
        add_shortcode( 'bible_qa_search', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
    }

    public static function assets() {
        wp_register_script(
            'bible-qa-search',
            BQA_URL . 'assets/search.js',
            [],
            BQA_VERSION,
            true
        );
        wp_register_style(
            'bible-qa-search',
            BQA_URL . 'assets/search.css',
            [],
            BQA_VERSION
        );
    }

    public static function render( $atts ) {
        $atts = shortcode_atts( [
            'placeholder' => 'Search Bible questions…',
        ], $atts, 'bible_qa_search' );

        wp_enqueue_script( 'bible-qa-search' );
        wp_enqueue_style( 'bible-qa-search' );

        wp_localize_script( 'bible-qa-search', 'BibleQA', [
            'root'  => esc_url_raw( rest_url( 'bible-qa/v1/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );

        ob_start(); ?>
        <div class="bqa-search-wrap">
            <input type="search"
                   id="bible-qa-search"
                   class="bqa-input"
                   placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
                   autocomplete="off" />
            <div id="bible-qa-results" class="bqa-results" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}