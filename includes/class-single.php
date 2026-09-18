<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Single {

    const QUERY_VAR = 'bqa_qa';

    public static function init() {
        // Register the rewrite rule
        add_action( 'init', [ __CLASS__, 'add_rewrite_rule' ] );

        // Whitelist the custom query var so WP doesn't strip it
        add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );

        // Intercept requests to /qa/{slug}/
        add_filter( 'template_include', [ __CLASS__, 'maybe_render' ] );

        // Flush rewrite rules once after activation (safe one-time)
        add_action( 'wp_loaded', [ __CLASS__, 'maybe_flush_rewrites' ] );

        add_action( 'admin_init', [ __CLASS__, 'maybe_detect_search_page' ] );
    }

    /**
     * Register the /qa/{slug}/ rewrite rule.
     */
    public static function add_rewrite_rule() {
        add_rewrite_rule(
            '^qa/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * Tell WP about our custom query var.
     */
    public static function register_query_var( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Flush rewrite rules only when our version flag changes.
     * We use the plugin's BQA_DB_VERSION as the trigger.
     */
    public static function maybe_flush_rewrites() {
        $flag = 'bqa_rewrites_flushed_' . BQA_DB_VERSION;
        if ( get_option( $flag ) ) {
            return;
        }
        flush_rewrite_rules( false );
        update_option( $flag, 1 );
    }

    /**
     * If the request is for a single Q&A, swap the template file.
     * Uses template_include so the theme's normal hierarchy still runs.
     */
    public static function maybe_render( $template ) {
        $slug = get_query_var( self::QUERY_VAR );
        if ( ! $slug ) {
            return $template; // not our page, let WP handle it
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        $qa = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table}
            WHERE slug = %s AND status = 'published'
            LIMIT 1",
            $slug
        ) );

        if ( ! $qa ) {
            // Slug doesn't match a published question — let WP render its 404
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            return get_query_template( '404' );
        }

        // Load terms, related, author, source, etc.
        $qa->terms           = self::get_terms_for( $qa->id );
        $qa->related         = self::get_related( $qa->id, wp_list_pluck( $qa->terms, 'term_id' ) );
        $qa->author          = self::get_author( $qa->author_id );
        $qa->source          = self::get_source( $qa->source_id );
        $qa->other_by_author = $qa->author ? self::get_other_by_author( $qa->author_id, $qa->id, 5 ) : [];
        $qa->more_from_source= $qa->source ? self::get_more_from_source( $qa->source_id, $qa->id, 5 ) : [];

        // Bump view counter
        self::maybe_increment_views( $qa->id );

        // Expose to template
        $GLOBALS['bqa_current'] = $qa;

        // Enqueue plugin stylesheet
        wp_enqueue_style(
            'bible-qa-search',
            BQA_URL . 'assets/search.css',
            [],
            BQA_SHORTCODE::asset_version( 'assets/search.css' )
        );

        // Theme override allowed
        $custom = locate_template( [ 'single-bible-qa.php' ] );
        if ( $custom ) {
            return $custom;
        }

        return BQA_PATH . 'templates/single-qa.php';
    }

    /**
     * Fetch the terms for a Q&A.
     */
    public static function get_terms_for( $qa_id ) {
        global $wpdb;
        $terms = $wpdb->prefix . 'bible_qa_terms';
        $rel   = $wpdb->prefix . 'bible_qa_term_rel';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug
             FROM {$rel} r
             INNER JOIN {$terms} t ON t.term_id = r.term_id
             WHERE r.qa_id = %d
             ORDER BY t.name ASC",
            $qa_id
        ) );
    }

    /**
     * Fetch related Q&As ranked by shared-term count.
     * Q&As sharing more terms with the current one rank higher.
     * Ties broken by view count.
     *
     * @param int   $qa_id      The current QA ID
     * @param array $term_ids   Term IDs attached to the current QA
     * @param int   $limit      Max results
     */
    public static function get_related( $qa_id, $term_ids, $limit = 5 ) {
        if ( empty( $term_ids ) ) {
            return [];
        }

        global $wpdb;
        $qa  = $wpdb->prefix . 'bible_qa';
        $rel = $wpdb->prefix . 'bible_qa_term_rel';

        // Build a placeholder list for the IN clause
        $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

        // SQL: count matching terms per related QA, order by that count desc
        $sql = $wpdb->prepare(
            "SELECT q.id, q.question, q.slug, q.views,
                    COUNT(DISTINCT r.term_id) AS shared_terms
            FROM {$qa} q
            INNER JOIN {$rel} r ON r.qa_id = q.id
            WHERE q.id != %d
            AND q.status = 'published'
            AND r.term_id IN ({$placeholders})
            GROUP BY q.id
            ORDER BY shared_terms DESC, q.views DESC
            LIMIT %d",
            array_merge( [ $qa_id ], $term_ids, [ $limit ] )
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Increment the view counter, once per session per question.
     */
    public static function maybe_increment_views( $qa_id ) {
        $cookie_name = 'bqa_viewed_' . (int) $qa_id;
        if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET views = views + 1 WHERE id = %d",
            $qa_id
        ) );

        // Set a cookie good for 6 hours
        setcookie(
            $cookie_name,
            '1',
            time() + 6 * HOUR_IN_SECONDS,
            '/'
        );
    }

    /**
     * Helper for templates: builds a permalink to a Q&A.
     */
    public static function permalink( $slug ) {
        return home_url( user_trailingslashit( 'qa/' . $slug ) );
    }

    /**
     * Return the URL of the page that contains the [bible_qa_search] shortcode.
     * Falls back to the site home if not configured.
     */
    public static function search_url() {
        $page_id = (int) get_option( 'bqa_search_page_id', 0 );
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return $url;
            }
        }
        return home_url( '/' );
    }

    /**
     * On admin_init, find the page containing our shortcode and remember its ID.
     * Runs once (or whenever the option is empty).
     */
    public static function maybe_detect_search_page() {
        if ( get_option( 'bqa_search_page_id' ) ) {
            return;
        }

        global $wpdb;
        $page = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
            WHERE post_status = 'publish'
            AND post_type = 'page'
            AND post_content LIKE '%[bible_qa_search%'
            LIMIT 1"
        );

        if ( $page ) {
            update_option( 'bqa_search_page_id', (int) $page );
        }
    }

    /**
     * Fetch an author row by ID.
     */
    public static function get_author( $author_id ) {
        $author_id = (int) $author_id;
        if ( ! $author_id ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_authors';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE author_id = %d LIMIT 1",
            $author_id
        ) );
    }

    /**
     * Return the author's avatar URL, falling back to a Gravatar from email,
     * then to a data URI placeholder.
     */
    public static function get_author_avatar( $author, $size = 64 ) {
        if ( ! $author ) {
            return '';
        }

        if ( ! empty( $author->avatar_url ) ) {
            return $author->avatar_url;
        }

        if ( ! empty( $author->email ) ) {
            return get_avatar_url( $author->email, [ 'size' => $size ] );
        }

        return '';
    }

    /**
     * Permalink for an author archive page (we'll wire this up later).
     */
    public static function author_permalink( $slug ) {
        return home_url( user_trailingslashit( 'qa-author/' . $slug ) );
    }

    /**
     * Fetch a source row by ID.
     */
    public static function get_source( $source_id ) {
        $source_id = (int) $source_id;
        if ( ! $source_id ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_sources';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_id = %d LIMIT 1",
            $source_id
        ) );
    }

    /**
     * Build a human-readable citation string.
     * Example: "R.C. Sproul, Essential Truths of the Christian Faith (Tyndale, 1992), p. 145"
     */
    public static function format_citation( $source, $locator = '' ) {
        if ( ! $source ) {
            return '';
        }

        $parts = [];

        if ( ! empty( $source->author ) ) {
            $parts[] = $source->author . ',';
        }

        $title = '<em>' . esc_html( $source->title ) . '</em>';

        $meta_bits = [];
        if ( ! empty( $source->publisher ) ) {
            $meta_bits[] = $source->publisher;
        }
        if ( ! empty( $source->year ) ) {
            $meta_bits[] = $source->year;
        }
        if ( ! empty( $source->edition ) ) {
            $meta_bits[] = $source->edition;
        }
        if ( $meta_bits ) {
            $title .= ' (' . implode( ', ', $meta_bits ) . ')';
        }

        $parts[] = $title;

        if ( $locator ) {
            $parts[] = ', ' . $locator;
        }

        return implode( ' ', $parts );
    }

    /**
     * Permalink for a source archive page (we'll wire this up later).
     */
    public static function source_permalink( $slug ) {
        return home_url( user_trailingslashit( 'qa-source/' . $slug ) );
    }

    /**
     * Render the breadcrumb trail for a single Q&A.
     * Chain: Home › Bible Q&A › [Topic] › [Question]
     */
    public static function render_breadcrumbs( $qa ) {
        if ( ! $qa ) {
            return '';
        }

        $home_url   = home_url( '/' );
        $search_url = self::search_url();

        $crumbs = [
            [
                'label' => 'Home',
                'url'   => $home_url,
            ],
            [
                'label' => 'Bible Q&A',
                'url'   => $search_url,
            ],
        ];

        // Add primary topic (first alphabetically) if the QA has one
        if ( ! empty( $qa->terms ) ) {
            $terms = $qa->terms;
            // Sort alphabetically by name, case-insensitive
            usort( $terms, function( $a, $b ) {
                return strcasecmp( $a->name, $b->name );
            } );
            $primary = $terms[0];
            $crumbs[] = [
                'label' => $primary->name,
                'url'   => BQA_Archive::permalink( 'topic', $primary->slug ),
            ];
        }

        // Current page (not linked)
        $crumbs[] = [
            'label' => $qa->question,
            'url'   => null,
        ];

        // Render as HTML
        $html  = '<nav class="bqa-breadcrumbs" aria-label="Breadcrumb">';
        $html .= '<ol class="bqa-breadcrumbs-list">';

        $count = count( $crumbs );
        foreach ( $crumbs as $i => $crumb ) {
            $is_last = ( $i === $count - 1 );

            $html .= '<li class="bqa-breadcrumb-item' . ( $is_last ? ' bqa-breadcrumb-current' : '' ) . '">';

            if ( ! empty( $crumb['url'] ) && ! $is_last ) {
                $html .= '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a>';
            } else {
                $html .= '<span>' . esc_html( $crumb['label'] ) . '</span>';
            }

            if ( ! $is_last ) {
                $html .= '<span class="bqa-breadcrumb-sep" aria-hidden="true">›</span>';
            }

            $html .= '</li>';
        }

        $html .= '</ol>';
        $html .= '</nav>';

        return $html;
    }

    /**
     * Output JSON-LD BreadcrumbList schema for the current QA.
     */
    public static function render_breadcrumbs_jsonld( $qa ) {
        if ( ! $qa ) {
            return;
        }

        $items = [];

        // Home
        $items[] = [
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => 'Home',
            'item'     => home_url( '/' ),
        ];

        // Bible Q&A (search page)
        $items[] = [
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => 'Bible Q&A',
            'item'     => self::search_url(),
        ];

        $position = 3;

        // Primary topic
        if ( ! empty( $qa->terms ) ) {
            $terms = $qa->terms;
            usort( $terms, function( $a, $b ) {
                return strcasecmp( $a->name, $b->name );
            } );
            $primary = $terms[0];
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $primary->name,
                'item'     => BQA_Archive::permalink( 'topic', $primary->slug ),
            ];
        }

        // Question
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => $qa->question,
            'item'     => self::permalink( $qa->slug ),
        ];

        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];

        echo '<script type="application/ld+json">'
            . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            . '</script>' . "\n";
    }

    /**
     * Fetch other published Q&As by the same author, excluding the current one.
     */
    public static function get_other_by_author( $author_id, $exclude_qa_id, $limit = 5 ) {
        $author_id = (int) $author_id;
        if ( ! $author_id ) {
            return [];
        }

        global $wpdb;
        $qa = $wpdb->prefix . 'bible_qa';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, question, slug, views
            FROM {$qa}
            WHERE author_id = %d
            AND id != %d
            AND status = 'published'
            ORDER BY views DESC, updated_at DESC
            LIMIT %d",
            $author_id, $exclude_qa_id, $limit
        ) );
    }

    /**
     * Fetch other published Q&As from the same source, excluding the current one.
     */
    public static function get_more_from_source( $source_id, $exclude_qa_id, $limit = 5 ) {
        $source_id = (int) $source_id;
        if ( ! $source_id ) {
            return [];
        }

        global $wpdb;
        $qa = $wpdb->prefix . 'bible_qa';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, question, slug, views
            FROM {$qa}
            WHERE source_id = %d
            AND id != %d
            AND status = 'published'
            ORDER BY views DESC, updated_at DESC
            LIMIT %d",
            $source_id, $exclude_qa_id, $limit
        ) );
    }
}