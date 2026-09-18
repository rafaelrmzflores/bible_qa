<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Archive {

    const PER_PAGE = 20;

    /**
     * Registry of archive types.
     * Each type has:
     *   - query_var: internal query var name (unique per type)
     *   - rewrite:   URL prefix, e.g. "qa-topic"
     *   - table:     DB table (without prefix) to look up the entity
     *   - slug_col:  column holding the slug
     *   - id_col:    column holding the primary key
     *   - rel_col:   column in wp_bible_qa pointing at the entity, OR null
     *   - rel_table: pivot table (without prefix) for many-to-many, OR null
     *   - rel_fk:    column in pivot table pointing at the entity, OR null
     */
    public static function types() {
        return [
            'topic' => [
                'query_var' => 'bqa_topic',
                'rewrite'   => 'qa-topic',
                'table'     => 'bible_qa_terms',
                'slug_col'  => 'slug',
                'id_col'    => 'term_id',
                'rel_col'   => null,
                'rel_table' => 'bible_qa_term_rel',
                'rel_fk'    => 'term_id',
                'label'     => 'Topic',
                'plural'    => 'Topics',
            ],
            'author' => [
                'query_var' => 'bqa_author',
                'rewrite'   => 'qa-author',
                'table'     => 'bible_qa_authors',
                'slug_col'  => 'slug',
                'id_col'    => 'author_id',
                'rel_col'   => 'author_id',
                'rel_table' => null,
                'rel_fk'    => null,
                'label'     => 'Author',
                'plural'    => 'Authors',
            ],
            'source' => [
                'query_var' => 'bqa_source',
                'rewrite'   => 'qa-source',
                'table'     => 'bible_qa_sources',
                'slug_col'  => 'slug',
                'id_col'    => 'source_id',
                'rel_col'   => 'source_id',
                'rel_table' => null,
                'rel_fk'    => null,
                'label'     => 'Source',
                'plural'    => 'Sources',
            ],
        ];
    }

    public static function init() {
        add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
        add_filter( 'template_include', [ __CLASS__, 'maybe_render' ] );
        // Note: enqueue_assets() is called from maybe_render() instead of wp_enqueue_scripts, so that we only load the CSS on archive pages.
        // add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /**
     * Register one rewrite rule per archive type.
     */
    public static function add_rewrite_rules() {
        foreach ( self::types() as $type ) {
            add_rewrite_rule(
                '^' . $type['rewrite'] . '/([^/]+)/?$',
                'index.php?' . $type['query_var'] . '=$matches[1]',
                'top'
            );
        }
    }

    /**
     * Whitelist all query vars.
     */
    public static function register_query_vars( $vars ) {
        foreach ( self::types() as $type ) {
            $vars[] = $type['query_var'];
        }
        return $vars;
    }

    /**
     * Which archive type is this request for? Returns the type config
     * plus the slug, or null if this isn't an archive request.
     */
    public static function detect_current() {
        foreach ( self::types() as $type_key => $type ) {
            $slug = get_query_var( $type['query_var'] );
            if ( $slug ) {
                return [ 'key' => $type_key, 'slug' => $slug, 'config' => $type ];
            }
        }
        return null;
    }

    /**
     * Enqueue CSS on archive pages.
     */
    public static function enqueue_assets() {
        if ( ! self::detect_current() ) {
            return;
        }
        wp_enqueue_style(
            'bible-qa-search',
            BQA_URL . 'assets/search.css',
            [],
            BQA_Shortcode::asset_version( 'assets/search.css' )
        );
    }

    /**
     * Look up an entity by slug using the type config.
     */
    public static function get_entity( $type_config, $slug ) {
        global $wpdb;
        $table = $wpdb->prefix . $type_config['table'];
        $col   = $type_config['slug_col'];

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$col} = %s LIMIT 1",
            $slug
        ) );
    }

    /**
     * Fetch published Q&As related to the given entity ID.
     */
    public static function get_qa_for_entity( $type_config, $entity_id, $page = 1, $per_page = self::PER_PAGE ) {
        global $wpdb;
        $qa     = $wpdb->prefix . 'bible_qa';
        $offset = max( 0, ( $page - 1 ) * $per_page );

        if ( $type_config['rel_table'] ) {
            // Many-to-many pivot (topics)
            $rel = $wpdb->prefix . $type_config['rel_table'];
            $fk  = $type_config['rel_fk'];

            $sql = $wpdb->prepare(
                "SELECT q.id, q.question, q.slug, q.views,
                        LEFT(q.answer, 200) AS excerpt
                 FROM {$qa} q
                 INNER JOIN {$rel} r ON r.qa_id = q.id
                 WHERE r.{$fk} = %d
                   AND q.status = 'published'
                 ORDER BY q.views DESC, q.updated_at DESC
                 LIMIT %d OFFSET %d",
                $entity_id, $per_page, $offset
            );
        } else {
            // Direct FK on wp_bible_qa (authors, sources)
            $fk_col = $type_config['rel_col'];

            $sql = $wpdb->prepare(
                "SELECT q.id, q.question, q.slug, q.views,
                        LEFT(q.answer, 200) AS excerpt
                 FROM {$qa} q
                 WHERE q.{$fk_col} = %d
                   AND q.status = 'published'
                 ORDER BY q.views DESC, q.updated_at DESC
                 LIMIT %d OFFSET %d",
                $entity_id, $per_page, $offset
            );
        }

        return $wpdb->get_results( $sql );
    }

    public static function count_qa_for_entity( $type_config, $entity_id ) {
        global $wpdb;
        $qa = $wpdb->prefix . 'bible_qa';

        if ( $type_config['rel_table'] ) {
            $rel = $wpdb->prefix . $type_config['rel_table'];
            $fk  = $type_config['rel_fk'];

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$qa} q
                 INNER JOIN {$rel} r ON r.qa_id = q.id
                 WHERE r.{$fk} = %d
                   AND q.status = 'published'",
                $entity_id
            ) );
        } else {
            $fk_col = $type_config['rel_col'];

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$qa}
                 WHERE {$fk_col} = %d
                   AND status = 'published'",
                $entity_id
            ) );
        }
    }

    /**
     * Public permalink helper. Use as:
     *   BQA_Archive::permalink( 'topic', $slug )
     *   BQA_Archive::permalink( 'author', $slug )
     *   BQA_Archive::permalink( 'source', $slug )
     */
    public static function permalink( $type_key, $slug ) {
        $types = self::types();
        if ( ! isset( $types[ $type_key ] ) ) {
            return home_url( '/' );
        }
        return home_url( user_trailingslashit( $types[ $type_key ]['rewrite'] . '/' . $slug ) );
    }

   public static function maybe_render( $template ) {
        $current = self::detect_current();
        if ( ! $current ) {
            return $template;
        }

        $type_config = $current['config'];
        $entity      = self::get_entity( $type_config, $current['slug'] );

        if ( ! $entity ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            return get_query_template( '404' );
        }

        $entity_id = $entity->{ $type_config['id_col'] };

        $page  = max( 1, (int) get_query_var( 'paged' ) );
        $total = self::count_qa_for_entity( $type_config, $entity_id );
        $items = self::get_qa_for_entity( $type_config, $entity_id, $page );

        $meta = [];
        if ( $current['key'] === 'source' ) {
            $meta['citation'] = BQA_Single::format_citation( $entity, '' );
            $meta['url']      = ! empty( $entity->url ) ? $entity->url : '';
        }
        if ( $current['key'] === 'author' ) {
            $meta['avatar'] = BQA_Single::get_author_avatar( $entity, 64 );
            $meta['bio']    = ! empty( $entity->bio ) ? $entity->bio : '';
        }

        $GLOBALS['bqa_current_archive'] = (object) [
            'type'      => $current['key'],
            'config'    => $type_config,
            'entity'    => $entity,
            'meta'      => $meta,
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => self::PER_PAGE,
            'max_pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
        ];

        wp_enqueue_style(
            'bible-qa-search',
            BQA_URL . 'assets/search.css',
            [],
            BQA_Shortcode::asset_version( 'assets/search.css' )
        );

        $custom = locate_template( [ 'bqa-archive.php' ] );
        if ( $custom ) {
            return $custom;
        }

        return BQA_PATH . 'templates/archive.php';
    }
}