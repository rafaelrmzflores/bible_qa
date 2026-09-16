<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$qa = isset( $GLOBALS['bqa_current'] ) ? $GLOBALS['bqa_current'] : null;
if ( ! $qa ) {
    return;
}

get_header();
?>

<main id="bqa-single" class="bqa-single-wrap">
    <article class="bqa-single">

        <header class="bqa-single-header">
            <h1 class="bqa-question"><?php echo esc_html( $qa->question ); ?></h1>

            <?php if ( ! empty( $qa->author ) ) : ?>
                <div class="bqa-author">
                    <?php $avatar = BQA_Single::get_author_avatar( $qa->author, 48 ); ?>
                    <?php if ( $avatar ) : ?>
                        <img class="bqa-author-avatar"
                            src="<?php echo esc_url( $avatar ); ?>"
                            alt="<?php echo esc_attr( $qa->author->name ); ?>"
                            width="48" height="48" loading="lazy">
                    <?php endif; ?>
                    <div class="bqa-author-info">
                        <span class="bqa-author-name">
                            Answered by <strong><?php echo esc_html( $qa->author->name ); ?></strong>
                        </span>
                        <?php if ( ! empty( $qa->author->bio ) ) : ?>
                            <span class="bqa-author-bio"><?php echo esc_html( wp_trim_words( $qa->author->bio, 20 ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php
                $refs = BQA_Admin::get_meta( $qa->id, 'scripture_refs' );
                if ( $refs ) :
                ?>
                    <p class="bqa-scripture-refs">
                        <strong>Scripture:</strong> <?php echo esc_html( $refs ); ?>
                    </p>
                <?php endif; ?>

            <?php if ( ! empty( $qa->terms ) ) : ?>
                <ul class="bqa-terms">
                    <?php foreach ( $qa->terms as $term ) : ?>
                        <li>
                            <a href="<?php echo esc_url( add_query_arg( 'term', $term->slug, home_url( '/' ) ) ); ?>">
                                <?php echo esc_html( $term->name ); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </header>

        <div class="bqa-answer">
            <?php
            // Render the answer. If you stored plain text, use wpautop + esc_html.
            // If you stored HTML from a rich editor, use wp_kses_post.
            echo wpautop( wp_kses_post( $qa->answer ) );
            ?>
        </div>

        <footer class="bqa-single-footer">
            <p class="bqa-meta">
                <?php echo (int) $qa->views; ?> views &middot;
                Last updated <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $qa->updated_at ) ) ); ?>
            </p>
        </footer>

    </article>

    <?php if ( ! empty( $qa->related ) ) : ?>
        <aside class="bqa-related">
            <h2>Related Questions</h2>
            <ul>
                <?php foreach ( $qa->related as $rel ) : ?>
                    <li>
                        <a href="<?php echo esc_url( BQA_Single::permalink( $rel->slug ) ); ?>">
                            <?php echo esc_html( $rel->question ); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>
    <?php endif; ?>

    <?php
    $referer = wp_get_referer();
    $search  = BQA_Single::search_url();
    if ( $referer && strpos( $referer, $search ) === 0 ) :
    ?>
        <p class="bqa-back">
            <a href="<?php echo esc_url( $referer ); ?>">&larr; Back to search</a>
        </p>
    <?php endif; ?>
</main>

<?php
get_footer();