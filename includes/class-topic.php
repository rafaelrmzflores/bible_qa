<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Topic {

    const QUERY_VAR = 'bqa_topic';
    const PER_PAGE  = 20;

    public static function init() {
        add_action( 'init', [ __CLASS__, 'add_rewrite_rule' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /**
     * Register the /qa-topic/{slug}/ rewrite rule.
     */
    public static function add_rewrite_rule() {
        add_rewrite_rule(
            '^qa-topic/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    public static function register_query_var( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Enqueue plugin styles for the archive page.
     */
    public static function enqueue_assets() {
        if ( ! get_query_var( self::QUERY_VAR ) ) {
            return;
        }
        wp_enqueue_style(
            'bible-qa-search',
            BQA_URL . 'assets/search.css',
            [],
            BQA_VERSION
        );
    }

    /**
     * Fetch a term row by slug.
     */
    public static function get_term_by_slug( $slug ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_terms';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE slug = %s LIMIT 1",
            $slug
        ) );
    }

    /**
     * Fetch published Q&As tagged with a given term ID, with pagination.
     */
    public static function get_qa_for_term( $term_id, $page = 1, $per_page = self::PER_PAGE ) {
        global $wpdb;
        $qa  = $wpdb->prefix . 'bible_qa';
        $rel = $wpdb->prefix . 'bible_qa_term_rel';

        $offset = max( 0, ( $page - 1 ) * $per_page );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT q.id, q.question, q.slug, q.views,
                    LEFT(q.answer, 200) AS excerpt
             FROM {$qa} q
             INNER JOIN {$rel} r ON r.qa_id = q.id
             WHERE r.term_id = %d
               AND q.status = 'published'
             ORDER BY q.views DESC, q.updated_at DESC
             LIMIT %d OFFSET %d",
            $term_id, $per_page, $offset
        ) );
    }

    /**
     * Count published Q&As tagged with a given term.
     */
    public static function count_qa_for_term( $term_id ) {
        global $wpdb;
        $qa  = $wpdb->prefix . 'bible_qa';
        $rel = $wpdb->prefix . 'bible_qa_term_rel';

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$qa} q
             INNER JOIN {$rel} r ON r.qa_id = q.id
             WHERE r.term_id = %d
               AND q.status = 'published'",
            $term_id
        ) );
    }

    /**
     * Permalink helper.
     */
    public static function permalink( $slug ) {
        return home_url( user_trailingslashit( 'qa-topic/' . $slug ) );
    }

    /**
     * If the request is for a topic archive, load our template.
     */
    public static function maybe_render() {
        $slug = get_query_var( self::QUERY_VAR );
        if ( ! $slug ) {
            return;
        }

        $term = self::get_term_by_slug( $slug );

        if ( ! $term ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            include get_query_template( '404' );
            exit;
        }

        $page  = max( 1, (int) get_query_var( 'paged' ) );
        $total = self::count_qa_for_term( $term->term_id );
        $items = self::get_qa_for_term( $term->term_id, $page );

        // Attach the current term to the global scope for the template
        $GLOBALS['bqa_current_topic'] = (object) [
            'term'      => $term,
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => self::PER_PAGE,
            'max_pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
        ];

        $template = locate_template( [ 'bqa-topic-archive.php' ] );
        if ( ! $template ) {
            $template = BQA_PATH . 'templates/topic-archive.php';
        }

        load_template( $template, false );
        exit;
    }
}