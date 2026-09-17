<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_REST {

    const CACHE_GROUP = 'bqa_search';
    const CACHE_TTL   = 3600; // 1 hour
    const SUGGEST_LIMIT = 5;
    const SEARCH_LIMIT  = 20;

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
                'term' => [
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ] );

        register_rest_route( 'bible-qa/v1', '/suggest', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'suggest' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    /* =====================================================================
     * SUGGEST — autocomplete titles
     * ================================================================== */

    public static function suggest( WP_REST_Request $request ) {
        $q = trim( (string) $request->get_param( 'q' ) );

        if ( mb_strlen( $q ) < 2 ) {
            return new WP_REST_Response( [ 'suggestions' => [] ], 200 );
        }

        // Cache key based on the raw query
        $cache_key = 'bqa_suggest_' . md5( strtolower( $q ) );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return new WP_REST_Response( [ 'suggestions' => $cached, 'cached' => true ], 200 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        // Prefer FULLTEXT for speed and relevance
        $words = self::sanitize_words( $q );
        $boolean = self::build_boolean_query( $words );

        $results = [];

        if ( $boolean ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, question, slug
                 FROM {$table}
                 WHERE status = 'published'
                   AND MATCH(question, answer) AGAINST (%s IN BOOLEAN MODE)
                 ORDER BY MATCH(question, answer) AGAINST (%s IN BOOLEAN MODE) DESC
                 LIMIT %d",
                $boolean, $boolean, self::SUGGEST_LIMIT
            ) );
        }

        // If nothing found, fall back to LIKE on the question title
        if ( empty( $results ) ) {
            $like = '%' . $wpdb->esc_like( $q ) . '%';
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, question, slug
                 FROM {$table}
                 WHERE status = 'published'
                   AND question LIKE %s
                 ORDER BY views DESC
                 LIMIT %d",
                $like, self::SUGGEST_LIMIT
            ) );
        }

        // Slim payload
        $suggestions = array_map( function( $row ) {
            return [
                'id'       => (int) $row->id,
                'question' => $row->question,
                'url'      => BQA_Single::permalink( $row->slug ),
            ];
        }, $results ?: [] );

        set_transient( $cache_key, $suggestions, self::CACHE_TTL );

        return new WP_REST_Response( [ 'suggestions' => $suggestions ], 200 );
    }

    /* =====================================================================
     * SEARCH — full results
     * ================================================================== */

    public static function search( WP_REST_Request $request ) {
        $q        = trim( (string) $request->get_param( 'q' ) );
        $per_page = min( max( (int) $request->get_param( 'per_page' ), 1 ), self::SEARCH_LIMIT );
        $term     = (string) $request->get_param( 'term' );

        if ( mb_strlen( $q ) < 2 && ! $term ) {
            return new WP_REST_Response( [
                'results' => [],
                'reason'  => 'query too short',
            ], 200 );
        }

        // Cache key includes all inputs
        $cache_key = 'bqa_search_' . md5( strtolower( $q ) . '|' . $per_page . '|' . $term );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            $cached['cached'] = true;
            return new WP_REST_Response( $cached, 200 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        $results = [];
        $mode    = 'fulltext';

        // 1) Try FULLTEXT first
        $words   = self::sanitize_words( $q );
        $boolean = self::build_boolean_query( $words );

        if ( $boolean ) {
            $results = self::fulltext_search( $table, $boolean, $term, $per_page );
        }

        // 2) Fallback to LIKE if FULLTEXT returned nothing
        if ( empty( $results ) && $q !== '' ) {
            $results = self::like_search( $table, $q, $term, $per_page );
            $mode    = 'like';
        }

        // 3) If a term filter is set but no query, just filter by term
        if ( $q === '' && $term ) {
            $results = self::term_only_search( $table, $term, $per_page );
            $mode    = 'term-only';
        }

        $response = [
            'results' => $results ?: [],
            'count'   => is_array( $results ) ? count( $results ) : 0,
            'mode'    => $mode,
            'query'   => $q,
            'term'    => $term,
        ];

        set_transient( $cache_key, $response, self::CACHE_TTL );

        return new WP_REST_Response( $response, 200 );
    }

    /* =====================================================================
     * Query runners
     * ================================================================== */

    private static function fulltext_search( $table, $boolean, $term, $per_page ) {
        global $wpdb;

        if ( $term ) {
            $rel   = $wpdb->prefix . 'bible_qa_term_rel';
            $terms = $wpdb->prefix . 'bible_qa_terms';

            return $wpdb->get_results( $wpdb->prepare(
                "SELECT q.id, q.question, q.slug, q.views,
                        LEFT(q.answer, 200) AS excerpt,
                        MATCH(q.question, q.answer) AGAINST (%s IN BOOLEAN MODE) AS score
                 FROM {$table} q
                 INNER JOIN {$rel} r ON r.qa_id = q.id
                 INNER JOIN {$terms} t ON t.term_id = r.term_id
                 WHERE q.status = 'published'
                   AND t.slug = %s
                   AND MATCH(q.question, q.answer) AGAINST (%s IN BOOLEAN MODE)
                 ORDER BY score DESC, q.views DESC
                 LIMIT %d",
                $boolean, $term, $boolean, $per_page
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, question, slug, views,
                    LEFT(answer, 200) AS excerpt,
                    MATCH(question, answer) AGAINST (%s IN BOOLEAN MODE) AS score
             FROM {$table}
             WHERE status = 'published'
               AND MATCH(question, answer) AGAINST (%s IN BOOLEAN MODE)
             ORDER BY score DESC, views DESC
             LIMIT %d",
            $boolean, $boolean, $per_page
        ) );
    }

    private static function like_search( $table, $q, $term, $per_page ) {
        global $wpdb;

        $like = '%' . $wpdb->esc_like( $q ) . '%';

        if ( $term ) {
            $rel   = $wpdb->prefix . 'bible_qa_term_rel';
            $terms = $wpdb->prefix . 'bible_qa_terms';

            return $wpdb->get_results( $wpdb->prepare(
                "SELECT q.id, q.question, q.slug, q.views,
                        LEFT(q.answer, 200) AS excerpt
                 FROM {$table} q
                 INNER JOIN {$rel} r ON r.qa_id = q.id
                 INNER JOIN {$terms} t ON t.term_id = r.term_id
                 WHERE q.status = 'published'
                   AND t.slug = %s
                   AND (q.question LIKE %s OR q.answer LIKE %s)
                 ORDER BY
                   CASE WHEN q.question LIKE %s THEN 0 ELSE 1 END,
                   q.views DESC
                 LIMIT %d",
                $term, $like, $like, $like, $per_page
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, question, slug, views,
                    LEFT(answer, 200) AS excerpt
             FROM {$table}
             WHERE status = 'published'
               AND (question LIKE %s OR answer LIKE %s)
             ORDER BY
               CASE WHEN question LIKE %s THEN 0 ELSE 1 END,
               views DESC
             LIMIT %d",
            $like, $like, $like, $per_page
        ) );
    }

    private static function term_only_search( $table, $term, $per_page ) {
        global $wpdb;
        $rel   = $wpdb->prefix . 'bible_qa_term_rel';
        $terms = $wpdb->prefix . 'bible_qa_terms';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT q.id, q.question, q.slug, q.views,
                    LEFT(q.answer, 200) AS excerpt
             FROM {$table} q
             INNER JOIN {$rel} r ON r.qa_id = q.id
             INNER JOIN {$terms} t ON t.term_id = r.term_id
             WHERE q.status = 'published'
               AND t.slug = %s
             ORDER BY q.views DESC, q.updated_at DESC
             LIMIT %d",
            $term, $per_page
        ) );
    }

    /* =====================================================================
     * Helpers
     * ================================================================== */

    /**
     * Normalize a user query into an array of clean words.
     */
    private static function sanitize_words( $q ) {
        $q     = mb_strtolower( $q );
        $words = preg_split( '/[\s,\.\?\!;:"]+/u', $q );
        $words = array_filter( array_map( function( $w ) {
            $w = preg_replace( '/[+\-><\(\)~*\"@]+/', '', $w );
            return trim( $w );
        }, $words ) );

        // Filter out words shorter than 2 chars
        $words = array_filter( $words, fn( $w ) => mb_strlen( $w ) >= 2 );

        return array_values( array_unique( $words ) );
    }

    /**
     * Build a boolean-mode query string: "+word1* +word2* ..."
     * Skips common short words that FULLTEXT will drop anyway.
     */
    private static function build_boolean_query( $words ) {
        if ( empty( $words ) ) {
            return '';
        }

        return implode( ' ', array_map( fn( $w ) => '+' . $w . '*', $words ) );
    }

    /**
     * Invalidate all search + suggest caches.
     * Called on any QA write.
     */
    public static function invalidate_cache() {
        global $wpdb;

        // Delete all transients matching our prefixes
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bqa_search_%'
                OR option_name LIKE '_transient_timeout_bqa_search_%'
                OR option_name LIKE '_transient_bqa_suggest_%'
                OR option_name LIKE '_transient_timeout_bqa_suggest_%'"
        );
    }
}