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

            <?php
                $refs = BQA_Admin::get_meta( $qa->id, 'scripture_refs' );
                if ( $refs ) :
                ?>
                    <p class="bqa-scripture-refs">
                        <span class="bqa-label">Scripture:</span> <?php echo esc_html( $refs ); ?>
                    </p>
                <?php endif; ?>

             <?php if ( ! empty( $qa->terms ) ) : ?>
                <ul class="bqa-terms">
                    <?php foreach ( $qa->terms as $term ) : ?>
                        <li>
                            <a href="<?php echo esc_url( BQA_Archive::permalink( 'topic', $term->slug ) ); ?>">
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

     <?php if ( ! empty( $qa->author ) ) : ?>
    <div class="bqa-author-info">
        <?php $avatar = BQA_Single::get_author_avatar( $qa->author, 32 ); ?>
        <?php if ( $avatar ) : ?>
            <img class="bqa-author-avatar-inline"
                 src="<?php echo esc_url( $avatar ); ?>"
                 alt="<?php echo esc_attr( $qa->author->name ); ?>"
                 width="32" height="32" loading="lazy">
        <?php endif; ?>

        <span class="bqa-author-name">
            Answered by
            <strong><a href="<?php echo esc_url( BQA_Archive::permalink( 'author', $qa->author->slug ) ); ?>"><?php echo esc_html( $qa->author->name ); ?></a></strong>
        </span>

        <?php if ( ! empty( $qa->source ) ) : ?>
            <span class="bqa-source-line">
                in
                <?php
                $source  = $qa->source;
                $locator = $qa->source_locator;

                $source_link = BQA_Archive::permalink( 'source', $source->slug );
                $title_html  = '<a href="' . esc_url( $source_link ) . '"><em>' . esc_html( $source->title ) . '</em></a>';

                $same_author = ! empty( $source->author )
                    && strcasecmp( trim( $source->author ), trim( $qa->author->name ) ) === 0;

                if ( $same_author ) {
                    $bits = [];
                    if ( $source->publisher ) $bits[] = $source->publisher;
                    if ( $source->year )      $bits[] = $source->year;
                    $meta     = $bits ? ' (' . implode( ', ', $bits ) . ')' : '';
                    $citation = $title_html . $meta;
                    if ( $locator ) $citation .= ', ' . esc_html( $locator );
                    echo $citation;
                } else {
                    $parts = [];
                    if ( ! empty( $source->author ) ) {
                        $parts[] = esc_html( $source->author ) . ',';
                    }
                    $title_full = $title_html;
                    $meta_bits = [];
                    if ( $source->publisher ) $meta_bits[] = $source->publisher;
                    if ( $source->year )      $meta_bits[] = $source->year;
                    if ( $source->edition )   $meta_bits[] = $source->edition;
                    if ( $meta_bits ) {
                        $title_full .= ' (' . implode( ', ', $meta_bits ) . ')';
                    }
                    $parts[] = $title_full;
                    if ( $locator ) $parts[] = ', ' . esc_html( $locator );
                    echo implode( ' ', $parts );
                }
                ?>

                <?php if ( ! empty( $source->url ) ) : ?>
                    — <a href="<?php echo esc_url( $source->url ); ?>" target="_blank" rel="noopener">View source</a>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>

<?php elseif ( ! empty( $qa->source ) ) : ?>
    <div class="bqa-author-info bqa-author-info--source-only">
        <span class="bqa-source-line">
            Source:
            <?php
            $source  = $qa->source;
            $locator = $qa->source_locator;

            $source_link = BQA_Archive::permalink( 'source', $source->slug );
            $title_html  = '<a href="' . esc_url( $source_link ) . '"><em>' . esc_html( $source->title ) . '</em></a>';

            $parts = [];
            if ( ! empty( $source->author ) ) {
                $parts[] = esc_html( $source->author ) . ',';
            }
            $title_full = $title_html;
            $meta_bits = [];
            if ( $source->publisher ) $meta_bits[] = $source->publisher;
            if ( $source->year )      $meta_bits[] = $source->year;
            if ( $meta_bits ) {
                $title_full .= ' (' . implode( ', ', $meta_bits ) . ')';
            }
            $parts[] = $title_full;
            if ( $locator ) $parts[] = ', ' . esc_html( $locator );
            echo implode( ' ', $parts );
            ?>

            <?php if ( ! empty( $qa->source->url ) ) : ?>
                — <a href="<?php echo esc_url( $qa->source->url ); ?>" target="_blank" rel="noopener">View source</a>
            <?php endif; ?>
        </span>
    </div>
<?php endif; ?>


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