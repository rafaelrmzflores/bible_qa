<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_CSV {

    const EXPORT_NONCE = 'bqa_export_csv';
    const IMPORT_NONCE = 'bqa_import_csv';

    public static function init() {
        add_action( 'admin_post_bqa_export_csv', [ __CLASS__, 'handle_export' ] );
        add_action( 'admin_post_bqa_import_csv', [ __CLASS__, 'handle_import' ] );
    }

    /* =====================================================================
     * EXPORT
     * ================================================================== */

    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( self::EXPORT_NONCE );

        global $wpdb;
        $qa_table      = $wpdb->prefix . 'bible_qa';
        $authors_table = $wpdb->prefix . 'bible_qa_authors';
        $sources_table = $wpdb->prefix . 'bible_qa_sources';
        $terms_table   = $wpdb->prefix . 'bible_qa_terms';
        $rel_table     = $wpdb->prefix . 'bible_qa_term_rel';
        $meta_table    = $wpdb->prefix . 'bible_qa_meta';

        $rows = $wpdb->get_results(
            "SELECT q.id, q.question, q.answer, q.slug, q.status,
                    q.author_id, q.source_id, q.source_locator,
                    a.name AS author_name,
                    s.title AS source_title,
                    s.author AS source_author,
                    s.publisher AS source_publisher,
                    s.year AS source_year,
                    s.url AS source_url,
                    s.edition AS source_edition,
                    s.isbn AS source_isbn
             FROM {$qa_table} q
             LEFT JOIN {$authors_table} a ON a.author_id = q.author_id
             LEFT JOIN {$sources_table} s ON s.source_id = q.source_id
             ORDER BY q.id ASC"
        );

        // Fetch topics and scripture refs for each row (batched)
        $topics_by_qa = [];
        $topic_rows = $wpdb->get_results(
            "SELECT r.qa_id, t.name
             FROM {$rel_table} r
             INNER JOIN {$terms_table} t ON t.term_id = r.term_id
             ORDER BY t.name ASC"
        );
        foreach ( $topic_rows as $tr ) {
            $topics_by_qa[ (int) $tr->qa_id ][] = $tr->name;
        }

        $refs_by_qa = [];
        $ref_rows = $wpdb->get_results(
            "SELECT qa_id, meta_value
             FROM {$meta_table}
             WHERE meta_key = 'scripture_refs'"
        );
        foreach ( $ref_rows as $mr ) {
            $refs_by_qa[ (int) $mr->qa_id ] = (string) $mr->meta_value;
        }

        // Send headers
        $filename = 'bible-qa-' . gmdate( 'Y-m-d-His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );

        // Optional UTF-8 BOM for Excel compatibility
        fwrite( $out, "\xEF\xBB\xBF" );

        // Header row
        fputcsv( $out, [
            'slug',
            'question',
            'answer',
            'status',
            'author',
            'source_title',
            'source_author',
            'source_publisher',
            'source_year',
            'source_edition',
            'source_isbn',
            'source_url',
            'source_locator',
            'topics',
            'scripture_refs',
        ] );

        foreach ( $rows as $row ) {
            fputcsv( $out, [
                $row->slug,
                $row->question,
                $row->answer,
                $row->status,
                (string) $row->author_name,
                (string) $row->source_title,
                (string) $row->source_author,
                (string) $row->source_publisher,
                (string) $row->source_year,
                (string) $row->source_edition,
                (string) $row->source_isbn,
                (string) $row->source_url,
                (string) $row->source_locator,
                implode( ', ', $topics_by_qa[ (int) $row->id ] ?? [] ),
                (string) ( $refs_by_qa[ (int) $row->id ] ?? '' ),
            ] );
        }

        fclose( $out );
        exit;
    }

    /* =====================================================================
     * IMPORT
     * ================================================================== */

    public static function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( self::IMPORT_NONCE );

        if ( empty( $_FILES['bqa_csv']['tmp_name'] ) ) {
            self::redirect_back( 'error', 'No file uploaded.' );
        }

        $file = $_FILES['bqa_csv']['tmp_name'];
        $fh   = fopen( $file, 'r' );

        if ( ! $fh ) {
            self::redirect_back( 'error', 'Could not open the uploaded file.' );
        }

        // Detect and strip UTF-8 BOM
        $bom = fread( $fh, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) {
            rewind( $fh );
        }

        $header = fgetcsv( $fh );
        if ( ! $header ) {
            fclose( $fh );
            self::redirect_back( 'error', 'Could not read the CSV header row.' );
        }

        // Normalize header names: lowercase, trim, replace spaces with underscores
        $header = array_map( function( $col ) {
            $col = strtolower( trim( $col ) );
            $col = preg_replace( '/[\s\-]+/', '_', $col );
            return $col;
        }, $header );

        // Required columns
        $required = [ 'question', 'answer' ];
        foreach ( $required as $col ) {
            if ( ! in_array( $col, $header, true ) ) {
                fclose( $fh );
                self::redirect_back( 'error', "Missing required column: {$col}" );
            }
        }

        $stats = [
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'errors'    => [],
        ];

        $line_num = 1; // header is line 1
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            $line_num++;

            // Skip blank rows
            if ( count( $row ) === 1 && trim( $row[0] ) === '' ) {
                continue;
            }

            $data = [];
            foreach ( $header as $i => $col ) {
                $data[ $col ] = isset( $row[ $i ] ) ? trim( $row[ $i ] ) : '';
            }

            if ( empty( $data['question'] ) || empty( $data['answer'] ) ) {
                $stats['skipped']++;
                $stats['errors'][] = "Line {$line_num}: missing question or answer.";
                continue;
            }

            $result = self::import_row( $data );
            if ( $result === 'created' ) {
                $stats['created']++;
            } elseif ( $result === 'updated' ) {
                $stats['updated']++;
            } else {
                $stats['skipped']++;
                $stats['errors'][] = "Line {$line_num}: " . $result;
            }
        }

        fclose( $fh );

        // Invalidate search cache since content changed
        if ( class_exists( 'BQA_REST' ) ) {
            BQA_REST::invalidate_cache();
        }

        // Redirect with stats
        $url = add_query_arg( [
            'page'    => 'bible-qa-import',
            'bqa_msg' => 'import_done',
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'skipped' => $stats['skipped'],
            'errors'  => count( $stats['errors'] ),
        ], admin_url( 'admin.php' ) );

        wp_safe_redirect( $url );
        exit;
    }

    /* =====================================================================
     * Row handler
     * ================================================================== */

    private static function import_row( $data ) {
        global $wpdb;
        $qa_table = $wpdb->prefix . 'bible_qa';

        // Determine slug
        $slug = ! empty( $data['slug'] )
            ? sanitize_title( $data['slug'] )
            : sanitize_title( $data['question'] );

        // Look for existing row
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$qa_table} WHERE slug = %s LIMIT 1",
            $slug
        ) );

        // Resolve author
        $author_id = null;
        if ( ! empty( $data['author'] ) ) {
            $author_id = self::ensure_author( $data['author'] );
        }

        // Resolve source
        $source_id = null;
        if ( ! empty( $data['source_title'] ) ) {
            $source_id = self::ensure_source( [
                'title'     => $data['source_title'],
                'author'    => $data['source_author']    ?? '',
                'publisher' => $data['source_publisher'] ?? '',
                'year'      => $data['source_year']      ?? '',
                'edition'   => $data['source_edition']   ?? '',
                'isbn'      => $data['source_isbn']      ?? '',
                'url'       => $data['source_url']       ?? '',
            ] );
        }

        $status = ! empty( $data['status'] ) && in_array( $data['status'], [ 'published', 'draft' ], true )
            ? $data['status']
            : 'published';

        $now = current_time( 'mysql' );

        $payload = [
            'question'       => $data['question'],
            'answer'         => $data['answer'],
            'slug'           => $slug,
            'status'         => $status,
            'author_id'      => $author_id,
            'source_id'      => $source_id,
            'source_locator' => ! empty( $data['source_locator'] ) ? $data['source_locator'] : null,
            'updated_at'     => $now,
        ];

        if ( $existing ) {
            $wpdb->update( $qa_table, $payload, [ 'id' => $existing->id ] );
            $qa_id = (int) $existing->id;
            $action = 'updated';
        } else {
            $payload['created_at'] = $now;
            $payload['views']      = 0;
            $wpdb->insert( $qa_table, $payload );
            $qa_id = (int) $wpdb->insert_id;
            $action = 'created';

            if ( ! $qa_id ) {
                return 'Database insert failed.';
            }
        }

        // Topics
        if ( ! empty( $data['topics'] ) ) {
            self::sync_topics( $qa_id, $data['topics'] );
        }

        // Scripture refs
        if ( array_key_exists( 'scripture_refs', $data ) ) {
            self::upsert_meta( $qa_id, 'scripture_refs', $data['scripture_refs'] );
        }

        return $action;
    }

    /* =====================================================================
     * Entity resolvers
     * ================================================================== */

    private static function ensure_author( $name ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_authors';
        $name  = trim( $name );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT author_id FROM {$table} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
            $name
        ) );

        if ( $existing ) {
            return (int) $existing;
        }

        $slug = self::unique_slug( sanitize_title( $name ), $table, 'slug', 'author_id' );

        $wpdb->insert( $table, [
            'name'       => $name,
            'slug'       => $slug,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );

        return (int) $wpdb->insert_id;
    }

    private static function ensure_source( $fields ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'bible_qa_sources';
        $title  = trim( $fields['title'] );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT source_id FROM {$table} WHERE LOWER(title) = LOWER(%s) LIMIT 1",
            $title
        ) );

        if ( $existing ) {
            return (int) $existing;
        }

        $slug = self::unique_slug( sanitize_title( $title ), $table, 'slug', 'source_id' );

        $wpdb->insert( $table, [
            'title'      => $title,
            'author'     => $fields['author']    ?: null,
            'publisher'  => $fields['publisher'] ?: null,
            'year'       => $fields['year']      ?: null,
            'edition'    => $fields['edition']   ?: null,
            'isbn'       => $fields['isbn']      ?: null,
            'url'        => $fields['url']       ?: null,
            'slug'       => $slug,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );

        return (int) $wpdb->insert_id;
    }

    private static function sync_topics( $qa_id, $topics_csv ) {
        global $wpdb;
        $terms = $wpdb->prefix . 'bible_qa_terms';
        $rel   = $wpdb->prefix . 'bible_qa_term_rel';

        // Split on comma, trim, drop empties
        $names = array_filter( array_map( 'trim', explode( ',', $topics_csv ) ) );

        // Wipe existing assignments
        $wpdb->delete( $rel, [ 'qa_id' => $qa_id ], [ '%d' ] );

        foreach ( $names as $name ) {
            $term_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT term_id FROM {$terms} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
                $name
            ) );

            if ( ! $term_id ) {
                $slug = self::unique_slug( sanitize_title( $name ), $terms, 'slug', 'term_id' );
                $wpdb->insert( $terms, [
                    'name'      => $name,
                    'slug'      => $slug,
                    'parent_id' => 0,
                ] );
                $term_id = (int) $wpdb->insert_id;
            }

            $wpdb->insert( $rel, [
                'qa_id'   => $qa_id,
                'term_id' => (int) $term_id,
            ] );
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

    private static function unique_slug( $slug, $table, $col = 'slug', $id_col = 'id' ) {
        global $wpdb;
        $base = $slug;
        $i    = 2;

        while ( true ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT {$id_col} FROM {$table} WHERE {$col} = %s LIMIT 1",
                $slug
            ) );
            if ( ! $existing ) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }

    private static function redirect_back( $type, $message ) {
        wp_safe_redirect( add_query_arg( [
            'page'    => 'bible-qa-import',
            'bqa_msg' => $type,
            'bqa_txt' => rawurlencode( $message ),
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}