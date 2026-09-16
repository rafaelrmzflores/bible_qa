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
                'q' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'per_page' => [
                    'default'           => 10,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    public static function search( WP_REST_Request $request ) {
        global $wpdb;

        $q        = trim( (string) $request->get_param( 'q' ) );
        $per_page = min( max( (int) $request->get_param( 'per_page' ), 1 ), 25 );

        if ( mb_strlen( $q ) < 2 ) {
            return new WP_REST_Response( [
                'results' => [],
                'reason'  => 'query too short',
            ], 200 );
        }

        $table = $wpdb->prefix . 'bible_qa';

        // Build boolean string: "+word1* +word2* ..."
        $words = preg_split( '/\s+/', $q );
        $words = array_filter( array_map( function( $w ) {
            return preg_replace( '/[+\-><\(\)~*\"@]+/', '', $w );
        }, $words ) );

        if ( empty( $words ) ) {
            return new WP_REST_Response( [
                'results' => [],
                'reason'  => 'no valid words',
            ], 200 );
        }

        $boolean = implode( ' ', array_map( fn( $w ) => '+' . $w . '*', $words ) );

        $sql = $wpdb->prepare(
            "SELECT id, question, slug, views,
                    LEFT(answer, 200) AS excerpt,
                    MATCH(question, answer) AGAINST (%s IN BOOLEAN MODE) AS score
             FROM {$table}
             WHERE status = 'published'
               AND MATCH(question, answer) AGAINST (%s IN BOOLEAN MODE)
             ORDER BY score DESC
             LIMIT %d",
            $boolean,
            $boolean,
            $per_page
        );

        $results = $wpdb->get_results( $sql );

        return new WP_REST_Response( [
            'results' => is_array( $results ) ? $results : [],
        ], 200 );

        // return new WP_REST_Response( [
        //     'results' => is_array( $results ) ? $results : [],
        //     'query'   => $boolean,
        //     'count'   => is_array( $results ) ? count( $results ) : 0,
        //     'table'   => $table,
        //     'sql_err' => $wpdb->last_error ?: null,
        // ], 200 );
    }
}