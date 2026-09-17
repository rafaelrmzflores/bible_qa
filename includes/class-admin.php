<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Admin {

    const MENU_SLUG        = 'bible-qa';
    const CAPABILITY       = 'manage_options';
    const PER_PAGE         = 20;

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /* ---------------------------------------------------------------------
     * Menu
     * ------------------------------------------------------------------ */

    public static function register_menu() {
        add_menu_page(
            'Bible Q&A',
            'Bible Q&A',
            self::CAPABILITY,
            self::MENU_SLUG,
            [ __CLASS__, 'render_list_page' ],
            'dashicons-book-alt',
            30
        );

        add_submenu_page(
            self::MENU_SLUG,
            'All Questions',
            'All Questions',
            self::CAPABILITY,
            self::MENU_SLUG,
            [ __CLASS__, 'render_list_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Add New',
            'Add New',
            self::CAPABILITY,
            self::MENU_SLUG . '-edit',
            [ __CLASS__, 'render_edit_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Topics',
            'Topics',
            self::CAPABILITY,
            self::MENU_SLUG . '-topics',
            [ __CLASS__, 'render_topics_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Authors',
            'Authors',
            self::CAPABILITY,
            self::MENU_SLUG . '-authors',
            [ __CLASS__, 'render_authors_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Sources',
            'Sources',
            self::CAPABILITY,
            self::MENU_SLUG . '-sources',
            [ __CLASS__, 'render_sources_page' ]
        );
    }

    /* ---------------------------------------------------------------------
     * Assets (admin)
     * ------------------------------------------------------------------ */

    public static function enqueue_assets( $hook ) {
        // Only load on our screens
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }

        wp_enqueue_editor(); // ensures wp_editor() works on edit screen
    }

    /* ---------------------------------------------------------------------
     * Action routing (POST handlers) — runs on admin_init
     * ------------------------------------------------------------------ */

    public static function handle_actions() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        // Detect which action is being submitted
        $action = isset( $_REQUEST['bqa_action'] ) ? sanitize_key( $_REQUEST['bqa_action'] ) : '';

        switch ( $action ) {
            case 'save_qa':         self::action_save_qa();         break;
            case 'delete_qa':       self::action_delete_qa();       break;
            case 'toggle_qa':       self::action_toggle_qa();       break;
            case 'save_term':       self::action_save_term();       break;
            case 'delete_term':     self::action_delete_term();     break;
            case 'save_author':     self::action_save_author();     break;
            case 'delete_author':   self::action_delete_author();   break;
            case 'save_source':     self::action_save_source();     break;
            case 'delete_source':   self::action_delete_source();   break;
        }
    }

    /* ---------------------------------------------------------------------
     * Action: save a Q&A (insert or update)
     * ------------------------------------------------------------------ */

    private static function action_save_qa() {
        check_admin_referer( 'bqa_save_qa' );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        $id       = isset( $_POST['qa_id'] ) ? (int) $_POST['qa_id'] : 0;
        $question = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
        $answer   = isset( $_POST['answer'] ) ? wp_kses_post( wp_unslash( $_POST['answer'] ) ) : '';
        $status   = isset( $_POST['status'] ) && in_array( $_POST['status'], [ 'draft', 'published' ], true )
                    ? $_POST['status'] : 'published';
        $terms    = isset( $_POST['terms'] ) ? array_map( 'intval', (array) $_POST['terms'] ) : [];
        $refs     = isset( $_POST['scripture_refs'] ) ? sanitize_text_field( wp_unslash( $_POST['scripture_refs'] ) ) : '';

        if ( ! $question || ! $answer ) {
            self::redirect_with_notice( 'edit', [ 'qa_id' => $id ], 'error', 'Question and answer are required.' );
        }

        if ( $id > 0 ) {
            // Keep existing slug on updates
            $existing_slug = $wpdb->get_var( $wpdb->prepare(
                "SELECT slug FROM {$table} WHERE id = %d", $id
            ) );
            $slug = $existing_slug ?: sanitize_title( $question );
        } else {
            $slug = sanitize_title( $question );
        }

        // Ensure slug uniqueness (append -2, -3, etc.)
        $slug = self::unique_slug( $slug, $id, $table );

        $author_id      = isset( $_POST['author_id'] ) ? (int) $_POST['author_id'] : 0;
        $source_id      = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
        $source_locator = isset( $_POST['source_locator'] ) ? sanitize_text_field( wp_unslash( $_POST['source_locator'] ) ) : '';

        $data = [
            'question'       => $question,
            'answer'         => $answer,
            'author_id'      => $author_id ?: null,
            'source_id'      => $source_id ?: null,
            'source_locator' => $source_locator ?: null,
            'slug'           => $slug,
            'status'         => $status,
            'updated_at'     => current_time( 'mysql' ),
        ];

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $data['views']      = 0;
            $wpdb->insert( $table, $data );
            $id = (int) $wpdb->insert_id;
        }

        // Sync terms
        self::sync_terms( $id, $terms );

        // Sync scripture refs
        self::upsert_meta( $id, 'scripture_refs', $refs );

        self::redirect_with_notice( 'edit', [ 'qa_id' => $id ], 'success', 'Question saved.' );
    }

    /* ---------------------------------------------------------------------
     * Action: delete a Q&A
     * ------------------------------------------------------------------ */

    private static function action_delete_qa() {
        $id = isset( $_GET['qa_id'] ) ? (int) $_GET['qa_id'] : 0;
        check_admin_referer( 'bqa_delete_qa_' . $id );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'bible_qa',          [ 'id'      => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'bible_qa_meta',     [ 'qa_id'   => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'bible_qa_term_rel', [ 'qa_id'   => $id ], [ '%d' ] );

        self::redirect_with_notice( 'list', [], 'success', 'Question deleted.' );
    }

    /* ---------------------------------------------------------------------
     * Action: toggle publish/draft
     * ------------------------------------------------------------------ */

    private static function action_toggle_qa() {
        $id = isset( $_GET['qa_id'] ) ? (int) $_GET['qa_id'] : 0;
        check_admin_referer( 'bqa_toggle_qa_' . $id );

        global $wpdb;
        $table   = $wpdb->prefix . 'bible_qa';
        $current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
        $new     = ( $current === 'published' ) ? 'draft' : 'published';

        $wpdb->update( $table, [ 'status' => $new, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        self::redirect_with_notice( 'list', [], 'success', 'Status updated.' );
    }

    /* ---------------------------------------------------------------------
     * Action: save a term
     * ------------------------------------------------------------------ */

    private static function action_save_term() {
        check_admin_referer( 'bqa_save_term' );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_terms';

        $term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
        $name    = isset( $_POST['term_name'] ) ? sanitize_text_field( wp_unslash( $_POST['term_name'] ) ) : '';
        $slug    = isset( $_POST['term_slug'] ) ? sanitize_title( wp_unslash( $_POST['term_slug'] ) ) : '';
        $parent  = isset( $_POST['term_parent'] ) ? (int) $_POST['term_parent'] : 0;

        if ( ! $name ) {
            self::redirect_with_notice( 'topics', [], 'error', 'Name is required.' );
        }

        if ( ! $slug ) {
            $slug = sanitize_title( $name );
        }
        $slug = self::unique_slug( $slug, $term_id, $table, 'term_id' );

        $data = [
            'name'      => $name,
            'slug'      => $slug,
            'parent_id' => $parent,
        ];

        if ( $term_id > 0 ) {
            $wpdb->update( $table, $data, [ 'term_id' => $term_id ] );
        } else {
            $wpdb->insert( $table, $data );
        }

        self::redirect_with_notice( 'topics', [], 'success', 'Topic saved.' );
    }

    /* ---------------------------------------------------------------------
     * Action: delete a term
     * ------------------------------------------------------------------ */

    private static function action_delete_term() {
        $term_id = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;
        check_admin_referer( 'bqa_delete_term_' . $term_id );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'bible_qa_terms',    [ 'term_id' => $term_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'bible_qa_term_rel', [ 'term_id' => $term_id ], [ '%d' ] );

        self::redirect_with_notice( 'topics', [], 'success', 'Topic deleted.' );
    }

    /* ---------------------------------------------------------------------
     * Action: save an author
     * ------------------------------------------------------------------ */
    private static function action_save_author() {
        check_admin_referer( 'bqa_save_author' );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_authors';

        $author_id = isset( $_POST['author_id'] ) ? (int) $_POST['author_id'] : 0;
        $name      = isset( $_POST['author_name'] ) ? sanitize_text_field( wp_unslash( $_POST['author_name'] ) ) : '';
        $slug      = isset( $_POST['author_slug'] ) ? sanitize_title( wp_unslash( $_POST['author_slug'] ) ) : '';
        $email     = isset( $_POST['author_email'] ) ? sanitize_email( wp_unslash( $_POST['author_email'] ) ) : '';
        $website   = isset( $_POST['author_website'] ) ? esc_url_raw( wp_unslash( $_POST['author_website'] ) ) : '';
        $avatar    = isset( $_POST['author_avatar'] ) ? esc_url_raw( wp_unslash( $_POST['author_avatar'] ) ) : '';
        $bio       = isset( $_POST['author_bio'] ) ? wp_kses_post( wp_unslash( $_POST['author_bio'] ) ) : '';

        if ( ! $name ) {
            self::redirect_with_notice( 'authors', [], 'error', 'Author name is required.' );
        }

        if ( ! $slug ) {
            $slug = sanitize_title( $name );
        }
        $slug = self::unique_slug( $slug, $author_id, $table, 'author_id' );

        $data = [
            'name'       => $name,
            'slug'       => $slug,
            'email'      => $email ?: null,
            'website'    => $website ?: null,
            'avatar_url' => $avatar ?: null,
            'bio'        => $bio ?: null,
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( $author_id > 0 ) {
            $wpdb->update( $table, $data, [ 'author_id' => $author_id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        self::redirect_with_notice( 'authors', [], 'success', 'Author saved.' );
    }

    /* ---------------------------------------------------------------------
     * Action: delete an author
     * ------------------------------------------------------------------ */
    private static function action_delete_author() {
        $author_id = isset( $_GET['author_id'] ) ? (int) $_GET['author_id'] : 0;
        check_admin_referer( 'bqa_delete_author_' . $author_id );

        global $wpdb;

        // Null out the author_id on any questions that reference this author
        $wpdb->update(
            $wpdb->prefix . 'bible_qa',
            [ 'author_id' => null ],
            [ 'author_id' => $author_id ]
        );

        // Delete the author row
        $wpdb->delete(
            $wpdb->prefix . 'bible_qa_authors',
            [ 'author_id' => $author_id ],
            [ '%d' ]
        );

        self::redirect_with_notice( 'authors', [], 'success', 'Author deleted.' );
    }

    /* ---------------------------------------------------------------------
     * Action: save a source
     * ------------------------------------------------------------------ */
    private static function action_save_source() {
        check_admin_referer( 'bqa_save_source' );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_sources';

        $source_id = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
        $title     = isset( $_POST['source_title'] ) ? sanitize_text_field( wp_unslash( $_POST['source_title'] ) ) : '';
        $author    = isset( $_POST['source_author'] ) ? sanitize_text_field( wp_unslash( $_POST['source_author'] ) ) : '';
        $publisher = isset( $_POST['source_publisher'] ) ? sanitize_text_field( wp_unslash( $_POST['source_publisher'] ) ) : '';
        $year      = isset( $_POST['source_year'] ) ? sanitize_text_field( wp_unslash( $_POST['source_year'] ) ) : '';
        $edition   = isset( $_POST['source_edition'] ) ? sanitize_text_field( wp_unslash( $_POST['source_edition'] ) ) : '';
        $isbn      = isset( $_POST['source_isbn'] ) ? sanitize_text_field( wp_unslash( $_POST['source_isbn'] ) ) : '';
        $url       = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
        $notes     = isset( $_POST['source_notes'] ) ? wp_kses_post( wp_unslash( $_POST['source_notes'] ) ) : '';

        if ( ! $title ) {
            self::redirect_with_notice( 'sources', [], 'error', 'Title is required.' );
        }

        $slug = sanitize_title( $title );
        $slug = self::unique_slug( $slug, $source_id, $table, 'source_id' );

        $data = [
            'title'      => $title,
            'author'     => $author ?: null,
            'publisher'  => $publisher ?: null,
            'year'       => $year ?: null,
            'edition'    => $edition ?: null,
            'isbn'       => $isbn ?: null,
            'url'        => $url ?: null,
            'notes'      => $notes ?: null,
            'slug'       => $slug,
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( $source_id > 0 ) {
            $wpdb->update( $table, $data, [ 'source_id' => $source_id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        self::redirect_with_notice( 'sources', [], 'success', 'Source saved.' );
    }

    /* ---------------------------------------------------------------------
     * Action: delete a source
     * ------------------------------------------------------------------ */

    private static function action_delete_source() {
        $source_id = isset( $_GET['source_id'] ) ? (int) $_GET['source_id'] : 0;
        check_admin_referer( 'bqa_delete_source_' . $source_id );

        global $wpdb;

        // Clear the reference on any Q&As pointing at this source
        $wpdb->update(
            $wpdb->prefix . 'bible_qa',
            [ 'source_id' => null ],
            [ 'source_id' => $source_id ]
        );

        $wpdb->delete(
            $wpdb->prefix . 'bible_qa_sources',
            [ 'source_id' => $source_id ],
            [ '%d' ]
        );

        self::redirect_with_notice( 'sources', [], 'success', 'Source deleted.' );
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function sync_terms( $qa_id, $term_ids ) {
        global $wpdb;
        $rel = $wpdb->prefix . 'bible_qa_term_rel';

        // Wipe existing
        $wpdb->delete( $rel, [ 'qa_id' => $qa_id ], [ '%d' ] );

        // Insert new
        foreach ( array_unique( $term_ids ) as $tid ) {
            $wpdb->insert( $rel, [ 'qa_id' => $qa_id, 'term_id' => $tid ] );
        }
    }

    private static function upsert_meta( $qa_id, $key, $value ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_meta';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$table} WHERE qa_id = %d AND meta_key = %s LIMIT 1",
            $qa_id, $key
        ) );

        if ( $existing ) {
            $wpdb->update( $table, [ 'meta_value' => $value ], [ 'meta_id' => $existing ] );
        } else {
            $wpdb->insert( $table, [
                'qa_id'      => $qa_id,
                'meta_key'   => $key,
                'meta_value' => $value,
            ] );
        }
    }

    public static function get_meta( $qa_id, $key, $default = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_meta';
        $val   = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$table} WHERE qa_id = %d AND meta_key = %s LIMIT 1",
            $qa_id, $key
        ) );
        return $val !== null ? $val : $default;
    }

    private static function unique_slug( $slug, $exclude_id, $table, $id_col = 'id' ) {
        global $wpdb;
        $base = $slug;
        $i    = 2;

        while ( true ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT {$id_col} FROM {$table} WHERE slug = %s AND {$id_col} != %d LIMIT 1",
                $slug, $exclude_id
            ) );
            if ( ! $existing ) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }

    private static function redirect_with_notice( $screen, $args, $type, $message ) {
        $args = array_merge(
            [ 'page' => self::MENU_SLUG . ( $screen === 'list' ? '' : '-' . $screen ) ],
            $args,
            [ 'bqa_notice' => $type, 'bqa_message' => rawurlencode( $message ) ]
        );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function render_notice() {
        if ( empty( $_GET['bqa_notice'] ) || empty( $_GET['bqa_message'] ) ) {
            return;
        }
        $type = $_GET['bqa_notice'] === 'error' ? 'error' : 'success';
        $msg  = sanitize_text_field( wp_unslash( $_GET['bqa_message'] ) );
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }

    /* ---------------------------------------------------------------------
     * Screen: list all Q&As
     * ------------------------------------------------------------------ */

    public static function render_list_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        $paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $offset = ( $paged - 1 ) * self::PER_PAGE;

        $where  = '1=1';
        $params = [];

        if ( $search ) {
            $where   .= ' AND (question LIKE %s OR answer LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Total
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
            : (int) $wpdb->get_var( $count_sql );

        // Rows
        $sql = "SELECT id, question, slug, status, views, created_at, updated_at
                FROM {$table}
                WHERE {$where}
                ORDER BY id DESC
                LIMIT %d OFFSET %d";
        $args = array_merge( $params, [ self::PER_PAGE, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

        $total_pages = ceil( $total / self::PER_PAGE );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Bible Q&A</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-edit' ) ); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">

            <?php self::render_notice(); ?>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
                <p class="search-box">
                    <label class="screen-reader-text" for="bqa-search-input">Search Questions</label>
                    <input type="search" id="bqa-search-input" name="s" value="<?php echo esc_attr( $search ); ?>">
                    <input type="submit" class="button" value="Search">
                </p>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Question</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:80px;">Views</th>
                        <th style="width:160px;">Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="5">No questions found.</td></tr>
                <?php else : foreach ( $rows as $row ) : ?>
                    <?php
                    $edit_url = add_query_arg(
                        [ 'page' => self::MENU_SLUG . '-edit', 'qa_id' => $row->id ],
                        admin_url( 'admin.php' )
                    );
                    $del_url = wp_nonce_url(
                        add_query_arg(
                            [ 'bqa_action' => 'delete_qa', 'qa_id' => $row->id ],
                            admin_url( 'admin.php' )
                        ),
                        'bqa_delete_qa_' . $row->id
                    );
                    $tog_url = wp_nonce_url(
                        add_query_arg(
                            [ 'bqa_action' => 'toggle_qa', 'qa_id' => $row->id ],
                            admin_url( 'admin.php' )
                        ),
                        'bqa_toggle_qa_' . $row->id
                    );
                    $toggle_label = $row->status === 'published' ? 'Unpublish' : 'Publish';
                    ?>
                    <tr>
                        <td><?php echo (int) $row->id; ?></td>
                        <td>
                            <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row->question ); ?></a></strong>
                            <div class="row-actions">
                                <span><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                <span><a href="<?php echo esc_url( $tog_url ); ?>"><?php echo esc_html( $toggle_label ); ?></a> | </span>
                                <span class="trash">
                                    <a href="<?php echo esc_url( $del_url ); ?>"
                                       onclick="return confirm('Delete this question permanently?');"
                                       style="color:#b32d2e;">Delete</a>
                                </span>
                                <?php if ( $row->status === 'published' ) : ?>
                                    <span> | <a href="<?php echo esc_url( home_url( 'qa/' . $row->slug . '/' ) ); ?>" target="_blank">View</a></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo esc_html( $row->status ); ?></td>
                        <td><?php echo (int) $row->views; ?></td>
                        <td><?php echo esc_html( $row->updated_at ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links( [
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'current'   => $paged,
                            'total'     => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ] );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Screen: add/edit a Q&A
     * ------------------------------------------------------------------ */

    public static function render_edit_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $qa_table    = $wpdb->prefix . 'bible_qa';
        $terms_table = $wpdb->prefix . 'bible_qa_terms';
        $rel_table   = $wpdb->prefix . 'bible_qa_term_rel';

        $id = isset( $_GET['qa_id'] ) ? (int) $_GET['qa_id'] : 0;
        $qa = null;

        if ( $id > 0 ) {
            $qa = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$qa_table} WHERE id = %d", $id ) );
            if ( ! $qa ) {
                echo '<div class="wrap"><h1>Question not found</h1></div>';
                return;
            }
        }

        $all_authors = $wpdb->get_results(
            "SELECT author_id, name FROM {$wpdb->prefix}bible_qa_authors ORDER BY name ASC"
        );

        $all_sources = $wpdb->get_results(
            "SELECT source_id, title, author FROM {$wpdb->prefix}bible_qa_sources ORDER BY title ASC"
        );

        // Term assignment
        $assigned_ids = [];
        if ( $id > 0 ) {
            $assigned_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT term_id FROM {$rel_table} WHERE qa_id = %d",
                $id
            ) );
            $assigned_ids = array_map( 'intval', $assigned_ids );
        }

        $all_terms = $wpdb->get_results( "SELECT term_id, name FROM {$terms_table} ORDER BY name ASC" );

        $scripture = $id > 0 ? self::get_meta( $id, 'scripture_refs' ) : '';

        $heading = $id > 0 ? 'Edit Question' : 'Add New Question';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $heading ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">&larr; Back to list</a>

            <?php self::render_notice(); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-top:1em;">
                <?php wp_nonce_field( 'bqa_save_qa' ); ?>
                <input type="hidden" name="bqa_action" value="save_qa">
                <input type="hidden" name="qa_id" value="<?php echo (int) $id; ?>">

                <table class="form-table">
                    <tr>
                        <th><label for="question">Question</label></th>
                        <td>
                            <input type="text" name="question" id="question" class="large-text"
                                   value="<?php echo esc_attr( $qa ? $qa->question : '' ); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="answer">Answer</label></th>
                        <td>
                            <?php
                            wp_editor(
                                $qa ? $qa->answer : '',
                                'answer',
                                [
                                    'textarea_name' => 'answer',
                                    'textarea_rows' => 12,
                                    'media_buttons' => false,
                                    'teeny'         => false,
                                ]
                            );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="scripture_refs">Scripture References</label></th>
                        <td>
                            <input type="text" name="scripture_refs" id="scripture_refs" class="large-text"
                                   value="<?php echo esc_attr( $scripture ); ?>"
                                   placeholder="e.g. John 3:16; Romans 5:8">
                            <p class="description">Separate multiple references with semicolons.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="published" <?php selected( $qa ? $qa->status : 'published', 'published' ); ?>>Published</option>
                                <option value="draft" <?php selected( $qa ? $qa->status : '', 'draft' ); ?>>Draft</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="author_id">Author</label></th>
                        <td>
                            <select name="author_id" id="author_id">
                                <option value="0">— None —</option>
                                <?php foreach ( $all_authors as $a ) : ?>
                                    <option value="<?php echo (int) $a->author_id; ?>"
                                        <?php selected( $qa ? (int) $qa->author_id : 0, (int) $a->author_id ); ?>>
                                        <?php echo esc_html( $a->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-authors' ) ); ?>" target="_blank" rel="noopener">Manage authors</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="source_id">Source</label></th>
                        <td>
                            <select name="source_id" id="source_id" style="max-width:500px;">
                                <option value="0">— None —</option>
                                <?php foreach ( $all_sources as $s ) : ?>
                                    <?php
                                    $label = $s->title;
                                    if ( $s->author ) {
                                        $label .= ' — ' . $s->author;
                                    }
                                    ?>
                                    <option value="<?php echo (int) $s->source_id; ?>"
                                        <?php selected( $qa ? (int) $qa->source_id : 0, (int) $s->source_id ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-sources' ) ); ?>" target="_blank" rel="noopener">Manage sources</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="source_locator">Source Locator</label></th>
                        <td>
                            <input type="text" name="source_locator" id="source_locator" class="regular-text"
                                value="<?php echo esc_attr( $qa ? $qa->source_locator : '' ); ?>"
                                placeholder="e.g. p. 145, Chapter 3, Sermon on Romans 9:16">
                            <p class="description">Where in the source this answer came from.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Topics</th>
                        <td>
                            <?php if ( empty( $all_terms ) ) : ?>
                                <em>No topics yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-topics' ) ); ?>">Add some first.</a></em>
                            <?php else : ?>
                                <fieldset>
                                    <?php foreach ( $all_terms as $t ) : ?>
                                        <label style="display:block;">
                                            <input type="checkbox" name="terms[]" value="<?php echo (int) $t->term_id; ?>"
                                                <?php checked( in_array( (int) $t->term_id, $assigned_ids, true ) ); ?>>
                                            <?php echo esc_html( $t->name ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endif; ?>
                            <p class="description">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-topics' ) ); ?>" target="_blank" rel="noopener">Manage topics</a>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo $id > 0 ? 'Update Question' : 'Create Question'; ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Screen: topics
     * ------------------------------------------------------------------ */

    public static function render_topics_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_terms';

        $terms = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );

        // Editing a specific term?
        $edit_id = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;
        $edit    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE term_id = %d", $edit_id ) ) : null;
        ?>
        <div class="wrap">
            <h1>Topics</h1>

            <?php self::render_notice(); ?>

            <div style="display:flex; gap:2em; margin-top:1em;">
                <div style="flex:0 0 320px;">
                    <h2><?php echo $edit ? 'Edit Topic' : 'Add Topic'; ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <?php wp_nonce_field( 'bqa_save_term' ); ?>
                        <input type="hidden" name="bqa_action" value="save_term">
                        <input type="hidden" name="term_id" value="<?php echo (int) ( $edit ? $edit->term_id : 0 ); ?>">

                        <p>
                            <label for="term_name"><strong>Name</strong></label><br>
                            <input type="text" name="term_name" id="term_name" class="regular-text"
                                   value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>" required>
                        </p>
                        <p>
                            <label for="term_slug"><strong>Slug</strong></label><br>
                            <input type="text" name="term_slug" id="term_slug" class="regular-text"
                                   value="<?php echo esc_attr( $edit ? $edit->slug : '' ); ?>">
                            <br><small>Leave blank to auto-generate.</small>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary">
                                <?php echo $edit ? 'Update' : 'Create'; ?>
                            </button>
                            <?php if ( $edit ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-topics' ) ); ?>" class="button">Cancel</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="flex:1;">
                    <h2>All Topics</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>Name</th>
                                <th style="width:200px;">Slug</th>
                                <th style="width:120px;">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $terms ) ) : ?>
                            <tr><td colspan="4">No topics yet.</td></tr>
                        <?php else : foreach ( $terms as $t ) : ?>
                            <?php
                            $count = (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}bible_qa_term_rel WHERE term_id = %d",
                                $t->term_id
                            ) );
                            $edit_url = add_query_arg(
                                [ 'page' => self::MENU_SLUG . '-topics', 'term_id' => $t->term_id ],
                                admin_url( 'admin.php' )
                            );
                            $del_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'bqa_action' => 'delete_term', 'term_id' => $t->term_id ],
                                    admin_url( 'admin.php' )
                                ),
                                'bqa_delete_term_' . $t->term_id
                            );
                            ?>
                            <tr>
                                <td><?php echo (int) $t->term_id; ?></td>
                                <td>
                                    <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $t->name ); ?></a></strong>
                                    <div class="row-actions">
                                        <span><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                        <span class="trash">
                                            <a href="<?php echo esc_url( $del_url ); ?>"
                                               onclick="return confirm('Delete this topic? Questions tagged with it will lose the tag.');"
                                               style="color:#b32d2e;">Delete</a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $t->slug ); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Screen: authors
     * ------------------------------------------------------------------ */

    public static function render_authors_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_authors';

        $authors = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );

        $edit_id = isset( $_GET['author_id'] ) ? (int) $_GET['author_id'] : 0;
        $edit    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE author_id = %d", $edit_id ) ) : null;
        ?>
        <div class="wrap">
            <h1>Authors</h1>
            <?php self::render_notice(); ?>

            <div style="display:flex; gap:2em; margin-top:1em;">
                <div style="flex:0 0 360px;">
                    <h2><?php echo $edit ? 'Edit Author' : 'Add Author'; ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <?php wp_nonce_field( 'bqa_save_author' ); ?>
                        <input type="hidden" name="bqa_action" value="save_author">
                        <input type="hidden" name="author_id" value="<?php echo (int) ( $edit ? $edit->author_id : 0 ); ?>">

                        <p>
                            <label><strong>Name</strong></label><br>
                            <input type="text" name="author_name" class="regular-text" required
                                value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Slug</strong></label><br>
                            <input type="text" name="author_slug" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->slug : '' ); ?>">
                            <br><small>Leave blank to auto-generate.</small>
                        </p>
                        <p>
                            <label><strong>Email</strong></label><br>
                            <input type="email" name="author_email" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->email : '' ); ?>">
                            <br><small>Used for Gravatar fallback.</small>
                        </p>
                        <p>
                            <label><strong>Website</strong></label><br>
                            <input type="url" name="author_website" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->website : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Avatar URL</strong></label><br>
                            <input type="url" name="author_avatar" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->avatar_url : '' ); ?>">
                            <br><small>Overrides Gravatar if set.</small>
                        </p>
                        <p>
                            <label><strong>Bio</strong></label><br>
                            <textarea name="author_bio" rows="4" class="large-text"><?php echo esc_textarea( $edit ? $edit->bio : '' ); ?></textarea>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary">
                                <?php echo $edit ? 'Update' : 'Create'; ?>
                            </button>
                            <?php if ( $edit ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-authors' ) ); ?>" class="button">Cancel</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="flex:1;">
                    <h2>All Authors</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>Name</th>
                                <th style="width:160px;">Slug</th>
                                <th style="width:80px;">Answers</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $authors ) ) : ?>
                            <tr><td colspan="4">No authors yet.</td></tr>
                        <?php else : foreach ( $authors as $a ) : ?>
                            <?php
                            $count = (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}bible_qa WHERE author_id = %d",
                                $a->author_id
                            ) );
                            $edit_url = add_query_arg(
                                [ 'page' => self::MENU_SLUG . '-authors', 'author_id' => $a->author_id ],
                                admin_url( 'admin.php' )
                            );
                            $del_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'bqa_action' => 'delete_author', 'author_id' => $a->author_id ],
                                    admin_url( 'admin.php' )
                                ),
                                'bqa_delete_author_' . $a->author_id
                            );
                            ?>
                            <tr>
                                <td><?php echo (int) $a->author_id; ?></td>
                                <td>
                                    <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $a->name ); ?></a></strong>
                                    <div class="row-actions">
                                        <span><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                        <span class="trash">
                                            <a href="<?php echo esc_url( $del_url ); ?>"
                                            onclick="return confirm('Delete this author? Their answers will be reassigned to “None”.');"
                                            style="color:#b32d2e;">Delete</a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $a->slug ); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Screen: sources
     * ------------------------------------------------------------------ */
    public static function render_sources_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_sources';

        $sources = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC" );

        $edit_id = isset( $_GET['source_id'] ) ? (int) $_GET['source_id'] : 0;
        $edit    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d", $edit_id ) ) : null;
        ?>
        <div class="wrap">
            <h1>Sources</h1>
            <?php self::render_notice(); ?>

            <div style="display:flex; gap:2em; margin-top:1em;">
                <div style="flex:0 0 400px;">
                    <h2><?php echo $edit ? 'Edit Source' : 'Add Source'; ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <?php wp_nonce_field( 'bqa_save_source' ); ?>
                        <input type="hidden" name="bqa_action" value="save_source">
                        <input type="hidden" name="source_id" value="<?php echo (int) ( $edit ? $edit->source_id : 0 ); ?>">

                        <p>
                            <label><strong>Title</strong></label><br>
                            <input type="text" name="source_title" class="large-text" required
                                value="<?php echo esc_attr( $edit ? $edit->title : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Author</strong></label><br>
                            <input type="text" name="source_author" class="large-text"
                                value="<?php echo esc_attr( $edit ? $edit->author : '' ); ?>"
                                placeholder="e.g. R.C. Sproul">
                        </p>
                        <p>
                            <label><strong>Publisher</strong></label><br>
                            <input type="text" name="source_publisher" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->publisher : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Year</strong></label><br>
                            <input type="text" name="source_year" class="small-text"
                                value="<?php echo esc_attr( $edit ? $edit->year : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Edition</strong></label><br>
                            <input type="text" name="source_edition" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->edition : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>ISBN</strong></label><br>
                            <input type="text" name="source_isbn" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->isbn : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>URL</strong></label><br>
                            <input type="url" name="source_url" class="large-text"
                                value="<?php echo esc_attr( $edit ? $edit->url : '' ); ?>"
                                placeholder="https://...">
                        </p>
                        <p>
                            <label><strong>Notes</strong></label><br>
                            <textarea name="source_notes" rows="4" class="large-text"><?php echo esc_textarea( $edit ? $edit->notes : '' ); ?></textarea>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary">
                                <?php echo $edit ? 'Update' : 'Create'; ?>
                            </button>
                            <?php if ( $edit ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-sources' ) ); ?>" class="button">Cancel</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="flex:1;">
                    <h2>All Sources</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>Title</th>
                                <th style="width:180px;">Author</th>
                                <th style="width:80px;">Year</th>
                                <th style="width:80px;">Answers</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $sources ) ) : ?>
                            <tr><td colspan="5">No sources yet.</td></tr>
                        <?php else : foreach ( $sources as $s ) : ?>
                            <?php
                            $count = (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}bible_qa WHERE source_id = %d",
                                $s->source_id
                            ) );
                            $edit_url = add_query_arg(
                                [ 'page' => self::MENU_SLUG . '-sources', 'source_id' => $s->source_id ],
                                admin_url( 'admin.php' )
                            );
                            $del_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'bqa_action' => 'delete_source', 'source_id' => $s->source_id ],
                                    admin_url( 'admin.php' )
                                ),
                                'bqa_delete_source_' . $s->source_id
                            );
                            ?>
                            <tr>
                                <td><?php echo (int) $s->source_id; ?></td>
                                <td>
                                    <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $s->title ); ?></a></strong>
                                    <div class="row-actions">
                                        <span><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                        <span class="trash">
                                            <a href="<?php echo esc_url( $del_url ); ?>"
                                            onclick="return confirm('Delete this source? Questions citing it will have their source cleared.');"
                                            style="color:#b32d2e;">Delete</a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $s->author ); ?></td>
                                <td><?php echo esc_html( $s->year ); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}