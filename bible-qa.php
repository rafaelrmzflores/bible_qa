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
    bqa_seed_terms(); // optional: inserts a few starter categories
    update_option( 'bqa_db_version', BQA_DB_VERSION );
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'bqa_deactivate' );
function bqa_deactivate() {
    flush_rewrite_rules();
}

/**
 * Run dbDelta on every load if schema version changed.
 * This lets you ship schema updates without reactivating.
 */
add_action( 'plugins_loaded', 'bqa_maybe_upgrade' );
function bqa_maybe_upgrade() {
    if ( get_option( 'bqa_db_version' ) !== BQA_DB_VERSION ) {
        bqa_create_tables();
        update_option( 'bqa_db_version', BQA_DB_VERSION );
    }
}

/* -------------------------------------------------------------------------
 * Table creation (dbDelta-friendly)
 * ---------------------------------------------------------------------- */

function bqa_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix;

    // --- Main Q&A table ---
    $sql_qa = "CREATE TABLE {$prefix}bible_qa (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        question      VARCHAR(500)    NOT NULL,
        answer        LONGTEXT        NOT NULL,
        slug          VARCHAR(255)    NOT NULL,
        status        VARCHAR(20)     NOT NULL DEFAULT 'published',
        views         BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at    DATETIME        NOT NULL,
        updated_at    DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY status (status),
        FULLTEXT KEY search_index (question, answer)
    ) $charset_collate;";

    // --- Meta table ---
    $sql_meta = "CREATE TABLE {$prefix}bible_qa_meta (
        meta_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        qa_id       BIGINT UNSIGNED NOT NULL,
        meta_key    VARCHAR(100)    NOT NULL,
        meta_value  LONGTEXT        NULL,
        PRIMARY KEY  (meta_id),
        KEY qa_id (qa_id),
        KEY meta_key (meta_key)
    ) $charset_collate;";

    // --- Terms (categories/topics) ---
    $sql_terms = "CREATE TABLE {$prefix}bible_qa_terms (
        term_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(100)    NOT NULL,
        slug        VARCHAR(100)    NOT NULL,
        parent_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (term_id),
        UNIQUE KEY slug (slug)
    ) $charset_collate;";

    // --- Term relationships ---
    $sql_term_rel = "CREATE TABLE {$prefix}bible_qa_term_rel (
        qa_id       BIGINT UNSIGNED NOT NULL,
        term_id     BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY  (qa_id, term_id),
        KEY term_id (term_id)
    ) $charset_collate;";

    // --- Search log ---
    $sql_log = "CREATE TABLE {$prefix}bible_qa_search_log (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        search_term   VARCHAR(255)    NOT NULL,
        results_count INT             NOT NULL DEFAULT 0,
        user_ip       VARBINARY(16)   NULL,
        created_at    DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        KEY search_term (search_term),
        KEY created_at (created_at)
    ) $charset_collate;";

    dbDelta( $sql_qa );
    dbDelta( $sql_meta );
    dbDelta( $sql_terms );
    dbDelta( $sql_term_rel );
    dbDelta( $sql_log );
}

/**
 * Insert a handful of starter topic terms on first activation.
 */
function bqa_seed_terms() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa_terms';

    // Only seed once
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    if ( $count > 0 ) {
        return;
    }

    $starters = [
        [ 'Salvation',    'salvation' ],
        [ 'Trinity',      'trinity' ],
        [ 'End Times',    'end-times' ],
        [ 'Prayer',       'prayer' ],
        [ 'Faith',        'faith' ],
        [ 'Grace',        'grace' ],
        [ 'Baptism',      'baptism' ],
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

add_action( 'plugins_loaded', function() {
    BQA_REST::init();
    BQA_Shortcode::init();
    if ( is_admin() ) {
        BQA_Admin::init();
    }
} );