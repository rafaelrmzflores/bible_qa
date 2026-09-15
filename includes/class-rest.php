<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_REST {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route( 'bible-qa/v1', '/search', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'search' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q'        => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'per_page' => [
                    'default'           => 10,
                    'sanitize_callback' => 'absint',
                ],
                'term'     => [
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ] );
    }

    public static function search( WP_REST_Request $request ) {
        global $wpdb;
        $q        = trim( $request->get_param( 'q' ) );
        $per_page = min( max( (int) $request->get_param( 'per_page' ), 1 ), 25 );
        $term     = $request->get_param( 'term' );

        if ( mb_strlen( $q ) < 2 ) {
            return new WP_REST_Response( [ 'results' => [] ], 200 );
        }

        $table      = $wpdb->prefix . 'bible_qa';
        $terms      = $wpdb->prefix . 'bible_qa_terms';
        $rel        = $wpdb->prefix . 'bible_qa_term_rel';

        // Build boolean query: require every word, allow prefix wildcard.
        $words = preg_split( '/\s+/', $q );
        $words = array_filter( array_map( function( $w ) {
            return preg_replace( '/[+\-><\(\)~*\"@]+/', '', $w );
        }, $words ) );

        if ( empty( $words ) ) {
            return new WP_REST_Response( [ 'results' => [] ], 200 );
        }

        $boolean = implode( ' ', array_map( fn( $w ) => '+' . $w . '*', $words ) );

        $join  = '';
        $where = "WHERE qa.status = 'published'
                  AND MATCH(qa.question, qa.answer) AGAINST (%s IN BOOLEAN MODE)";

        $params = [ $boolean ];

        if ( $term ) {
            $join  = "INNER JOIN {$rel} r ON r.qa_id = qa.id
                      INNER JOIN {$terms} t ON t.term_id = r.term_id";
            $where .= " AND t.slug = %s";
            $params[] = $term;
        }

        $params[] = $boolean; // for score
        $params[] = $per_page;

        $sql = $wpdb->prepare(
            "SELECT qa.id, qa.question, qa.slug, qa.views,
                    LEFT(qa.answer, 200) AS excerpt,
                    MATCH(qa.question, qa.answer) AGAINST (%s IN BOOLEAN MODE) AS score
             FROM {$table} qa
             {$join}
             {$where}
             ORDER BY score DESC, qa.views DESC
             LIMIT %d",
            ...$params
        );

        $results = $wpdb->get_results( $sql );

        // Non-blocking-ish log
        $wpdb->insert( $wpdb->prefix . 'bible_qa_search_log', [
            'search_term'   => mb_substr( $q, 0, 255 ),
            'results_count' => count( $results ),
            'user_ip'       => isset( $_SERVER['REMOTE_ADDR'] )
                ? @inet_pton( $_SERVER['REMOTE_ADDR'] ) ?: null
                : null,
            'created_at'    => current_time( 'mysql' ),
        ] );

        return new WP_REST_Response( [ 'results' => $results ], 200 );
    }
}