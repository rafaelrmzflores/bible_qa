<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$topic = isset( $GLOBALS['bqa_current_topic'] ) ? $GLOBALS['bqa_current_topic'] : null;
if ( ! $topic ) {
    return;
}

get_header();

$term  = $topic->term;
$items = $topic->items;
$total = $topic->total;
?>

<main id="bqa-single" class="bqa-single-wrap">
    <article class="bqa-single">

        <header class="bqa-single-header">
            <p class="bqa-topic-eyebrow">Topic</p>
            <h1 class="bqa-question"><?php echo esc_html( $term->name ); ?></h1>
            <p class="bqa-topic-count">
                <?php
                printf(
                    _n( '%d question', '%d questions', $total, 'bible-qa' ),
                    $total
                );
                ?>
            </p>
        </header>

        <?php if ( empty( $items ) ) : ?>
            <div class="bqa-answer">
                <p>No questions tagged with this topic yet.</p>
            </div>
        <?php else : ?>
            <ul class="bqa-archive-list">
                <?php foreach ( $items as $item ) : ?>
                    <li class="bqa-archive-item">
                        <h2 class="bqa-archive-question">
                            <a href="<?php echo esc_url( BQA_Single::permalink( $item->slug ) ); ?>">
                                <?php echo esc_html( $item->question ); ?>
                            </a>
                        </h2>
                        <?php if ( ! empty( $item->excerpt ) ) : ?>
                            <p class="bqa-archive-excerpt"><?php echo esc_html( $item->excerpt ); ?>…</p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ( $topic->max_pages > 1 ) : ?>
                <nav class="bqa-pagination">
                    <?php
                    echo paginate_links( [
                        'base'      => user_trailingslashit( trailingslashit( BQA_Topic::permalink( $term->slug ) ) . 'page/%#%' ),
                        'format'    => '',
                        'current'   => $topic->page,
                        'total'     => $topic->max_pages,
                        'prev_text' => '&laquo; Previous',
                        'next_text' => 'Next &raquo;',
                    ] );
                    ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

        <p class="bqa-back">
            <a href="<?php echo esc_url( BQA_Single::search_url() ); ?>">&larr; Back to search</a>
        </p>

    </article>
</main>

<?php
get_footer();