<?php
/**
 * Entrada (post) nativa de WordPress.
 *
 * Las Entradas creadas desde wp-admin → Entradas se publican aquí con el
 * diseño del tema. El contenido —textos, fotos y bloques— se edita con el
 * editor normal de WordPress.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$ws_post = get_post();
if ( ! $ws_post ) {
    return;
}

$ws_author = get_the_author_meta( 'display_name', (int) $ws_post->post_author );

get_header();
?>
<div class="ws-landing ws-static-page">
    <section class="ws-static-hero">
        <div class="ws-container">
            <nav class="ws-breadcrumbs" aria-label="<?php esc_attr_e( 'Migas de pan', 'workshop' ); ?>">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><i class="fa-solid fa-house"></i> <?php esc_html_e( 'Inicio', 'workshop' ); ?></a>
                <span class="ws-breadcrumb-sep"><i class="fa-solid fa-chevron-right"></i></span>
                <span aria-current="page"><?php echo esc_html( get_the_title( $ws_post ) ); ?></span>
            </nav>
            <span class="ws-hero-badge"><i class="fa-solid fa-newspaper"></i> <?php esc_html_e( 'Entrada', 'workshop' ); ?></span>
            <h1><?php echo esc_html( get_the_title( $ws_post ) ); ?></h1>
            <p class="ws-static-hero-sub ws-wp-meta">
                <?php if ( $ws_author ) : ?>
                    <span><i class="fa-solid fa-user"></i> <?php echo esc_html( $ws_author ); ?></span>
                <?php endif; ?>
                <span><i class="fa-solid fa-calendar-day"></i> <?php echo esc_html( get_the_date( '', $ws_post ) ); ?></span>
            </p>
        </div>
    </section>

    <main class="ws-container ws-static-layout">
        <div class="ws-static-main">
            <article class="ws-static-card ws-static-card-content ws-wp-content">
                <?php if ( has_post_thumbnail( $ws_post ) ) : ?>
                    <div class="ws-wp-featured">
                        <?php echo get_the_post_thumbnail( $ws_post, 'large' ); ?>
                    </div>
                <?php endif; ?>
                <div class="ws-static-content">
                    <?php echo apply_filters( 'the_content', $ws_post->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contenido del editor de WP. ?>
                </div>
                <?php
                wp_link_pages( array(
                    'before' => '<div class="ws-wp-pagination">' . esc_html__( 'Páginas:', 'workshop' ),
                    'after'  => '</div>',
                ) );
                ?>
                <p class="ws-wp-back">
                    <a href="<?php echo esc_url( get_permalink( get_option( 'page_for_posts' ) ) ?: home_url( '/' ) ); ?>">
                        <i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Volver a las entradas', 'workshop' ); ?>
                    </a>
                </p>
            </article>
        </div>
    </main>
</div>
<?php get_footer(); ?>
