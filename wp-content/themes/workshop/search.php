<?php
/**
 * Resultados de búsqueda de WordPress.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="ws-landing ws-static-page">
    <section class="ws-static-hero">
        <div class="ws-container">
            <nav class="ws-breadcrumbs" aria-label="<?php esc_attr_e( 'Migas de pan', 'workshop' ); ?>">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><i class="fa-solid fa-house"></i> <?php esc_html_e( 'Inicio', 'workshop' ); ?></a>
                <span class="ws-breadcrumb-sep"><i class="fa-solid fa-chevron-right"></i></span>
                <span aria-current="page"><?php esc_html_e( 'Búsqueda', 'workshop' ); ?></span>
            </nav>
            <span class="ws-hero-badge"><i class="fa-solid fa-magnifying-glass"></i> <?php esc_html_e( 'Búsqueda', 'workshop' ); ?></span>
            <h1>
                <?php
                /* translators: %s: término buscado. */
                printf( esc_html__( 'Resultados para «%s»', 'workshop' ), esc_html( get_search_query() ) );
                ?>
            </h1>
        </div>
    </section>

    <main class="ws-container ws-wp-list-wrap">
        <?php if ( have_posts() ) : ?>
            <div class="ws-wp-list">
                <?php while ( have_posts() ) : the_post(); ?>
                    <article class="ws-wp-card">
                        <a class="ws-wp-card-link" href="<?php the_permalink(); ?>">
                            <span class="ws-wp-card-body">
                                <strong class="ws-wp-card-title"><?php the_title(); ?></strong>
                                <span class="ws-wp-card-meta">
                                    <?php echo esc_html( get_post_type_object( get_post_type() )->labels->singular_name ?? get_post_type() ); ?>
                                    · <i class="fa-solid fa-calendar-day"></i> <?php echo esc_html( get_the_date() ); ?>
                                </span>
                                <span class="ws-wp-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 28 ) ); ?></span>
                                <span class="ws-wp-card-more"><?php esc_html_e( 'Ver resultado', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></span>
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
            <div class="ws-static-card ws-wp-search-empty">
                <div class="ws-static-content">
                    <p><?php esc_html_e( 'No encontramos resultados para tu búsqueda. Prueba con otras palabras o explora el sitio:', 'workshop' ); ?></p>
                    <p>
                        <a class="ws-btn ws-btn-primary" href="<?php echo esc_url( home_url( '/marketplace/' ) ); ?>"><i class="fa-solid fa-store"></i> <?php esc_html_e( 'Ver tiendas', 'workshop' ); ?></a>
                        <a class="ws-btn ws-btn-secondary" href="<?php echo esc_url( home_url( '/ayuda/' ) ); ?>"><i class="fa-solid fa-circle-question"></i> <?php esc_html_e( 'Ayuda', 'workshop' ); ?></a>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php get_footer(); ?>
