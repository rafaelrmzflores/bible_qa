<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$archive = isset( $GLOBALS['bqa_current_archive'] ) ? $GLOBALS['bqa_current_archive'] : null;
if ( ! $archive ) {
    return;
}

get_header();

$config = $archive->config;
$entity = $archive->entity;
$meta   = $archive->meta;
$items  = $archive->items;
$total  = $archive->total;
$type   = $archive->type;

// Heading text differs per type
if ( $type === 'topic' ) {
    $heading  = $entity->name;
    $eyebrow  = 'Topic';
} elseif ( $type === 'author' ) {
    $heading  = $entity->name;
    $eyebrow  = 'Author';
} elseif ( $type === 'source' ) {
    $heading  = $entity->title;
    $eyebrow  = 'Source';
} else {
    $heading  = '';
    $eyebrow  = $config['label'];
}
?>

<main id="bqa-single" class="bqa-single-wrap">
    <article class="bqa-single">

        <header class="bqa-single-header">
            <p class="bqa-topic-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
            <h1 class="bqa-question"><?php echo esc_html( $heading ); ?></h1>

            <?php if ( $type === 'author' ) : ?>
                <?php if ( ! empty( $meta['avatar'] ) ) : ?>
                    <div class="bqa-author-archive-avatar">
                        <img src="<?php echo esc_url( $meta['avatar'] ); ?>"
                             alt="<?php echo esc_attr( $entity->name ); ?>"
                             width="64" height="64" loading="lazy">
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $meta['bio'] ) ) : ?>
                    <p class="bqa-author-archive-bio"><?php echo esc_html( $meta['bio'] ); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( $type === 'source' ) : ?>
                <?php if ( ! empty( $meta['citation'] ) ) : ?>
                    <p class="bqa-source-archive-citation"><?php echo $meta['citation']; ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $meta['url'] ) ) : ?>
                    <p class="bqa-source-archive-url">
                        <a href="<?php echo esc_url( $meta['url'] ); ?>" target="_blank" rel="noopener">View source &rarr;</a>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

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
                <p>No questions found for this <?php echo esc_html( strtolower( $config['label'] ) ); ?> yet.</p>
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

            <?php if ( $archive->max_pages > 1 ) : ?>
                <nav class="bqa-pagination">
                    <?php
                    echo paginate_links( [
                        'base'      => user_trailingslashit( trailingslashit( BQA_Archive::permalink( $type, $entity->{ $config['slug_col'] } ) ) . 'page/%#%' ),
                        'format'    => '',
                        'current'   => $archive->page,
                        'total'     => $archive->max_pages,
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