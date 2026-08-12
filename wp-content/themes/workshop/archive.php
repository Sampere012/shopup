<?php
/**
 * Archivo de entradas de WordPress (categorías, etiquetas, fechas…).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

get_header();
$ws_arch_title = is_category() || is_tag() || is_tax() ? single_term_title( '', false ) : get_the_archive_title();
?>
<div class="ws-landing ws-static-page">
    <section class="ws-static-hero">
        <div class="ws-container">
            <nav class="ws-breadcrumbs" aria-label="<?php esc_attr_e( 'Migas de pan', 'workshop' ); ?>">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><i class="fa-solid fa-house"></i> <?php esc_html_e( 'Inicio', 'workshop' ); ?></a>
                <span class="ws-breadcrumb-sep"><i class="fa-solid fa-chevron-right"></i></span>
                <span aria-current="page"><?php echo esc_html( wp_strip_all_tags( $ws_arch_title ) ); ?></span>
            </nav>
            <span class="ws-hero-badge"><i class="fa-solid fa-folder-open"></i> <?php esc_html_e( 'Archivo', 'workshop' ); ?></span>
            <h1><?php echo esc_html( wp_strip_all_tags( $ws_arch_title ) ); ?></h1>
            <?php the_archive_description( '<p class="ws-static-hero-sub">', '</p>' ); ?>
        </div>
    </section>

    <main class="ws-container ws-wp-list-wrap">
        <?php if ( have_posts() ) : ?>
            <div class="ws-wp-list">
                <?php while ( have_posts() ) : the_post(); ?>
                    <article class="ws-wp-card">
                        <a class="ws-wp-card-link" href="<?php the_permalink(); ?>">
                            <?php if ( has_post_thumbnail() ) : ?>
                                <span class="ws-wp-card-thumb"><?php the_post_thumbnail( 'medium_large' ); ?></span>
                            <?php endif; ?>
                            <span class="ws-wp-card-body">
                                <strong class="ws-wp-card-title"><?php the_title(); ?></strong>
                                <span class="ws-wp-card-meta">
                                    <i class="fa-solid fa-calendar-day"></i> <?php echo esc_html( get_the_date() ); ?>
                                    <?php if ( get_the_author() ) : ?> · <i class="fa-solid fa-user"></i> <?php the_author(); ?><?php endif; ?>
                                </span>
                                <span class="ws-wp-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 28 ) ); ?></span>
                                <span class="ws-wp-card-more"><?php esc_html_e( 'Leer entrada', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></span>
                            </span>
                        </a>
                    </article>
                <?php endwhile; ?>
            </div>
            <?php
            the_posts_pagination( array(
                'mid_size'  => 2,
                'prev_text' => '<i class="fa-solid fa-chevron-left"></i>',
                'next_text' => '<i class="fa-solid fa-chevron-right"></i>',
            ) );
            ?>
        <?php else : ?>
            <p class="ws-empty ws-wp-empty"><?php esc_html_e( 'No hay entradas en esta sección.', 'workshop' ); ?></p>
        <?php endif; ?>
    </main>
</div>
<?php get_footer(); ?>
