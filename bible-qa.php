<?php
/**
 * Plugin Name:       Bible Q&A
 * Plugin URI:        https://example.com/bible-qa
 * Description:       A searchable database of Bible questions and answers.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * Text Domain:       bible-qa
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BQA_VERSION', '1.0.0' );
define( 'BQA_FILE', __FILE__ );
define( 'BQA_PATH', plugin_dir_path( __FILE__ ) );
define( 'BQA_URL',  plugin_dir_url( __FILE__ ) );
define( 'BQA_DB_VERSION', '1.0.0' );

/* -------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'bqa_activate' );
function bqa_activate() {
    bqa_create_tables();
    bqa_seed_terms();
    update_option( 'bqa_db_version', BQA_DB_VERSION );
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'bqa_deactivate' );
function bqa_deactivate() {
    flush_rewrite_rules();
}

add_action( 'plugins_loaded', 'bqa_maybe_upgrade' );
function bqa_maybe_upgrade() {
    if ( get_option( 'bqa_db_version' ) !== BQA_DB_VERSION ) {
        bqa_create_tables();
        update_option( 'bqa_db_version', BQA_DB_VERSION );
    }
}

/* -------------------------------------------------------------------------
 * Table creation
 * ---------------------------------------------------------------------- */

function bqa_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix;

    $sql_qa = "CREATE TABLE {$prefix}bible_qa (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        question varchar(500) NOT NULL,
        answer longtext NOT NULL,
        slug varchar(255) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'published',
        views bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY status (status)
    ) $charset_collate;";

    $sql_meta = "CREATE TABLE {$prefix}bible_qa_meta (
        meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        qa_id bigint(20) unsigned NOT NULL,
        meta_key varchar(100) NOT NULL,
        meta_value longtext NULL,
        PRIMARY KEY  (meta_id),
        KEY qa_id (qa_id),
        KEY meta_key (meta_key)
    ) $charset_collate;";

    $sql_terms = "CREATE TABLE {$prefix}bible_qa_terms (
        term_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        slug varchar(100) NOT NULL,
        parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (term_id),
        UNIQUE KEY slug (slug)
    ) $charset_collate;";

    $sql_term_rel = "CREATE TABLE {$prefix}bible_qa_term_rel (
        qa_id bigint(20) unsigned NOT NULL,
        term_id bigint(20) unsigned NOT NULL,
        PRIMARY KEY  (qa_id, term_id),
        KEY term_id (term_id)
    ) $charset_collate;";

    $sql_log = "CREATE TABLE {$prefix}bible_qa_search_log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        search_term varchar(255) NOT NULL,
        results_count int NOT NULL DEFAULT 0,
        user_ip varbinary(16) NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY search_term (search_term),
        KEY created_at (created_at)
    ) $charset_collate;";

    dbDelta( $sql_qa );
    dbDelta( $sql_meta );
    dbDelta( $sql_terms );
    dbDelta( $sql_term_rel );
    dbDelta( $sql_log );

    bqa_ensure_fulltext_index();
}

function bqa_ensure_fulltext_index() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa';

    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $exists ) {
        return false;
    }

    $has_index = $wpdb->get_var( $wpdb->prepare(
        "SHOW INDEX FROM {$table} WHERE Key_name = %s",
        'search_index'
    ) );
    if ( $has_index ) {
        return true;
    }

    return $wpdb->query(
        "ALTER TABLE {$table} ADD FULLTEXT KEY search_index (question, answer)"
    ) !== false;
}

function bqa_seed_terms() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa_terms';

    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    if ( $count > 0 ) {
        return;
    }

    $starters = [
        [ 'Salvation',        'salvation' ],
        [ 'Trinity',          'trinity' ],
        [ 'End Times',        'end-times' ],
        [ 'Prayer',           'prayer' ],
        [ 'Faith',            'faith' ],
        [ 'Grace',            'grace' ],
        [ 'Baptism',          'baptism' ],
        [ 'Sin & Repentance', 'sin-repentance' ],
    ];

    foreach ( $starters as $term ) {
        $wpdb->insert( $table, [
            'name'      => $term[0],
            'slug'      => $term[1],
            'parent_id' => 0,
        ] );
    }
}

/* -------------------------------------------------------------------------
 * Load plugin classes
 * ---------------------------------------------------------------------- */

require_once BQA_PATH . 'includes/class-rest.php';
require_once BQA_PATH . 'includes/class-shortcode.php';
require_once BQA_PATH . 'includes/class-admin.php';

// Register hooks immediately. Do NOT wrap in plugins_loaded — that hook may
// have already fired by the time this file loads, which is why the routes
// weren't registering.
BQA_REST::init();
BQA_Shortcode::init();
if ( is_admin() ) {
    BQA_Admin::init();
}

/* -------------------------------------------------------------------------
 * Temporary diagnostic route — remove once search works.
 * ---------------------------------------------------------------------- */

// add_action( 'rest_api_init', function() {
//     register_rest_route( 'bible-qa/v1', '/diag', [
//         'methods'             => 'GET',
//         'permission_callback' => '__return_true',
//         'callback'            => function() {
//             $out = [];

//             $out['BQA_PATH']          = defined( 'BQA_PATH' ) ? BQA_PATH : 'NOT DEFINED';
//             $out['BQA_URL']           = defined( 'BQA_URL' ) ? BQA_URL : 'NOT DEFINED';
//             $out['BQA_DB_VERSION']    = defined( 'BQA_DB_VERSION' ) ? BQA_DB_VERSION : 'NOT DEFINED';
//             $out['db_version_option'] = get_option( 'bqa_db_version' );

//             $out['file_rest_exists']      = file_exists( BQA_PATH . 'includes/class-rest.php' );
//             $out['file_shortcode_exists'] = file_exists( BQA_PATH . 'includes/class-shortcode.php' );
//             $out['file_admin_exists']     = file_exists( BQA_PATH . 'includes/class-admin.php' );

//             $out['class_BQA_REST']      = class_exists( 'BQA_REST' );
//             $out['class_BQA_Shortcode'] = class_exists( 'BQA_Shortcode' );
//             $out['class_BQA_Admin']     = class_exists( 'BQA_Admin' );

//             if ( class_exists( 'BQA_REST' ) ) {
//                 $out['BQA_REST_has_init']     = method_exists( 'BQA_REST', 'init' );
//                 $out['BQA_REST_has_search']   = method_exists( 'BQA_REST', 'search' );
//                 $out['BQA_REST_has_register'] = method_exists( 'BQA_REST', 'register_routes' );
//             }

//             $routes = array_filter(
//                 array_keys( rest_get_server()->get_routes() ),
//                 fn( $r ) => strpos( $r, 'bible-qa' ) !== false
//             );
//             $out['registered_bible_qa_routes'] = array_values( $routes );

//             global $wpdb;
//             $table = $wpdb->prefix . 'bible_qa';
//             $out['table_name']   = $table;
//             $out['table_exists'] = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );

//             if ( $out['table_exists'] ) {
//                 $out['row_count']       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
//                 $out['published_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'published'" );
//                 $out['statuses']        = $wpdb->get_col( "SELECT DISTINCT status FROM {$table}" );

//                 $out['direct_fulltext_test_atonement'] = $wpdb->get_results(
//                     "SELECT id, question,
//                             MATCH(question, answer) AGAINST ('+atonement*' IN BOOLEAN MODE) AS score
//                      FROM {$table}
//                      WHERE status = 'published'
//                        AND MATCH(question, answer) AGAINST ('+atonement*' IN BOOLEAN MODE)"
//                 );
//                 $out['last_sql_error'] = $wpdb->last_error ?: null;
//             }

//             return new WP_REST_Response( $out, 200 );
//         },
//     ] );
// } );