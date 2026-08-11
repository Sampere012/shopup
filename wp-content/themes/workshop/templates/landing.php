<?php
/**
 * Landing / índice público: lista las tiendas (PV) públicas.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$locations = WS_CRUD::get_locations( 'pv' );
$locations = array_values( array_filter( $locations, fn( $l ) => (int) $l->active === 1 ) );
$currency  = ws_currency_symbol();

global $wpdb;
$store_count = count( $locations );
$prod_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . ws_table_name( 'products' ) . " WHERE active=1" );
$loc_all     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . ws_table_name( 'locations' ) . " WHERE active=1" );

get_header();
?>
<div class="ws-landing">
    <section class="ws-landing-hero<?php echo ws_site_hero_has_bg() ? ' ws-has-bg' : ''; ?>" style="<?php echo esc_attr( ws_site_hero_bg_style() ); ?>">
        <div class="ws-container">
            <span class="ws-hero-badge"><i class="fa-solid fa-bolt"></i> <?php echo esc_html( ws_site_hero( 'hero_badge' ) ); ?></span>
            <h1><?php echo esc_html( ws_site_hero( 'hero_title' ) ); ?></h1>
            <p class="ws-hero-sub"><?php echo esc_html( ws_site_hero( 'hero_sub' ) ); ?></p>
            <div class="ws-hero-stats">
                <div class="ws-hero-stat"><strong><?php echo esc_html( number_format_i18n( $store_count ) ); ?></strong><span><?php esc_html_e( 'Tiendas activas', 'workshop' ); ?></span></div>
                <div class="ws-hero-stat"><strong><?php echo esc_html( number_format_i18n( $loc_all ) ); ?></strong><span><?php esc_html_e( 'Sucursales', 'workshop' ); ?></span></div>
                <div class="ws-hero-stat"><strong><?php echo esc_html( number_format_i18n( $prod_count ) ); ?></strong><span><?php esc_html_e( 'Productos', 'workshop' ); ?></span></div>
            </div>
        </div>
    </section>

    <main class="ws-container">
        <div class="ws-store-section-head">
            <h2><i class="fa-solid fa-store"></i> <?php esc_html_e( 'Puntos de venta', 'workshop' ); ?></h2>
            <span class="ws-store-section-count"><b><?php echo esc_html( count( $locations ) ); ?></b> <?php esc_html_e( 'tiendas', 'workshop' ); ?></span>
        </div>

        <div class="ws-landing-grid">
            <?php if ( empty( $locations ) ) : ?>
                <p class="ws-empty"><?php esc_html_e( 'Aún no hay puntos de venta disponibles.', 'workshop' ); ?></p>
            <?php endif; ?>

            <?php foreach ( $locations as $loc ) : ?>
                <a class="ws-store-card" href="<?php echo esc_url( ws_store_url( $loc ) ); ?>">
                    <?php if ( $loc->photo ) : ?>
                        <div class="ws-store-card-img" style="background-image:url('<?php echo esc_url( ws_image_url( $loc->photo ) ); ?>')"></div>
                    <?php else : ?>
                        <div class="ws-store-card-img ws-store-card-img-empty"><i class="fa-solid fa-store"></i></div>
                    <?php endif; ?>
                    <span class="ws-store-card-type"><?php echo esc_html( 'pv' === $loc->type ? __( 'Punto de venta', 'workshop' ) : __( 'Almacén', 'workshop' ) ); ?></span>
                    <div class="ws-store-card-body">
                        <h3><?php echo esc_html( $loc->name ); ?></h3>
                        <p><i class="fa-solid fa-location-dot"></i> <?php echo esc_html( $loc->address ); ?></p>
                        <span class="ws-chip"><i class="fa-solid fa-money-bill"></i> <?php echo esc_html( $loc->currency ); ?></span>
                        <span class="ws-link"><?php esc_html_e( 'Ver tienda', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </main>
</div>
<?php get_footer(); ?>
