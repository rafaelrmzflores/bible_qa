<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Admin {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
    }

    public static function menu() {
        add_menu_page(
            'Bible Q&A',
            'Bible Q&A',
            'manage_options',
            'bible-qa',
            [ __CLASS__, 'render_page' ],
            'dashicons-book-alt',
            30
        );
    }

    public static function render_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        // Handle new submission
        if ( isset( $_POST['bqa_new'] ) && check_admin_referer( 'bqa_new_qa' ) ) {
            $question = sanitize_text_field( wp_unslash( $_POST['question'] ?? '' ) );
            $answer   = wp_kses_post( wp_unslash( $_POST['answer'] ?? '' ) );

            if ( $question && $answer ) {
                $wpdb->insert( $table, [
                    'question'   => $question,
                    'answer'     => $answer,
                    'slug'       => sanitize_title( $question ),
                    'status'     => 'published',
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ] );
                echo '<div class="notice notice-success"><p>Question saved.</p></div>';
            }
        }

        $rows = $wpdb->get_results( "SELECT id, question, status, views, created_at FROM {$table} ORDER BY id DESC LIMIT 100" );
        ?>
        <div class="wrap">
            <h1>Bible Q&A</h1>

            <h2>Add New Question</h2>
            <form method="post">
                <?php wp_nonce_field( 'bqa_new_qa' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="question">Question</label></th>
                        <td><input type="text" name="question" id="question" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="answer">Answer</label></th>
                        <td><?php wp_editor( '', 'answer', [ 'textarea_rows' => 10 ] ); ?></td>
                    </tr>
                </table>
                <p><button class="button button-primary" name="bqa_new" value="1">Save Question</button></p>
            </form>

            <h2>Recent Questions</h2>
            <table class="widefat striped">
                <thead>
                    <tr><th>ID</th><th>Question</th><th>Status</th><th>Views</th><th>Created</th></tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="5">No questions yet.</td></tr>
                <?php else : foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo (int) $row->id; ?></td>
                        <td><?php echo esc_html( $row->question ); ?></td>
                        <td><?php echo esc_html( $row->status ); ?></td>
                        <td><?php echo (int) $row->views; ?></td>
                        <td><?php echo esc_html( $row->created_at ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}