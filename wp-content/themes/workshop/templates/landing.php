<?php
/**
 * Landing / índice público: lista las tiendas (PV) públicas.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

// Tiendas públicas: puntos de venta Y almacenes activos — cada ubicación
// activa tiene su tienda (como un PV). Primero los PV, luego los almacenes;
// dentro de cada tipo, por nombre.
$locations = WS_CRUD::get_locations( '' );
$locations = array_values( array_filter( $locations, fn( $l ) => (int) $l->active === 1 ) );
usort( $locations, function ( $a, $b ) {
    $ta = ( 'pv' === $a->type ) ? 0 : 1;
    $tb = ( 'pv' === $b->type ) ? 0 : 1;
    if ( $ta !== $tb ) {
        return $ta - $tb;
    }
    return strcasecmp( (string) $a->name, (string) $b->name );
} );
$currency  = ws_currency_symbol();
$biz       = ws_current_business();

global $wpdb;
$store_count = count( $locations );
// Solo cuenta lo VISIBLE EN LA TIENDA (ojito POR UBICACIÓN): lo que está en
// el inventario pero oculto del catálogo público no cuenta como "producto".
// El override de ws_store_visibility (visible en AL MENOS una ubicación
// activa) manda sobre el flag global.
$pro_t = ws_table_name( 'products' );
$loc_t = ws_table_name( 'locations' );
$sv_t  = ws_table_name( 'store_visibility' );
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sv_t ) ) === $sv_t ) {
    $prod_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.id) FROM {$pro_t} p
        INNER JOIN {$loc_t} l ON l.active=1
        LEFT JOIN {$sv_t} sv ON sv.entity_type='product' AND sv.entity_id=p.id AND sv.location_id=l.id AND sv.channel='store'
        WHERE p.active=1 AND COALESCE(sv.visible, p.store_visible) = 1" );
} else {
    $prod_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pro_t} WHERE active=1 AND store_visible=1" );
}

get_header();
?>
<div class="ws-landing">
    <section class="ws-landing-hero<?php echo ws_site_hero_has_bg() ? ' ws-has-bg' : ''; ?>" style="<?php echo esc_attr( ws_site_hero_bg_style() ); ?>">
        <div class="ws-container">
            <nav class="ws-breadcrumbs" aria-label="<?php esc_attr_e( 'Migas de pan', 'workshop' ); ?>">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><i class="fa-solid fa-house"></i> <?php esc_html_e( 'Inicio', 'workshop' ); ?></a>
                <span class="ws-breadcrumb-sep"><i class="fa-solid fa-chevron-right"></i></span>
                <span aria-current="page"><?php echo esc_html( ( $biz && ! empty( $biz->name ) ) ? $biz->name : ws_site_hero( 'hero_title' ) ); ?></span>
            </nav>
            <span class="ws-hero-badge"><i class="fa-solid fa-bolt"></i> <?php echo esc_html( ws_site_hero( 'hero_badge' ) ); ?></span>
            <h1><?php echo esc_html( ws_site_hero( 'hero_title' ) ); ?></h1>
            <p class="ws-hero-sub"><?php echo esc_html( ws_site_hero( 'hero_sub' ) ); ?></p>
            <div class="ws-hero-stats">
                <div class="ws-hero-stat"><strong><?php echo esc_html( ws_compact_number( $store_count ) ); ?></strong><span><?php esc_html_e( 'Tiendas activas', 'workshop' ); ?></span></div>
                <div class="ws-hero-stat"><strong><?php echo esc_html( ws_compact_number( $prod_count ) ); ?></strong><span><?php esc_html_e( 'Productos', 'workshop' ); ?></span></div>
            </div>
        </div>
    </section>

    <main class="ws-container">
        <?php
        // Anuncios globales del sitio (creados por el admin): anclados y
        // activos aparecen como banner en la portada de todos los negocios.
        if ( function_exists( 'ws_announcements_site' ) ) :
            foreach ( ws_announcements_site() as $ws_ann ) :
                if ( ! (int) $ws_ann->active || ! (int) $ws_ann->pinned ) {
                    continue;
                }
                // Vigencia programada (desde/hasta y fijado hasta): solo se
                // muestra el banner dentro de su ventana de tiempo.
                if ( function_exists( 'ws_announcement_is_visible' ) && ! ws_announcement_is_visible( $ws_ann ) ) {
                    continue;
                }
                $ws_ann_kind = 'warning' === $ws_ann->type ? 'warn' : $ws_ann->type;
                if ( ! in_array( $ws_ann_kind, array( 'danger', 'info', 'warn' ), true ) ) {
                    $ws_ann_kind = 'info';
                }
                ?>
                <div class="ws-banner ws-banner-<?php echo esc_attr( $ws_ann_kind ); ?> ws-ann-banner ws-ann-banner-site" data-ann="<?php echo (int) $ws_ann->id; ?>">
                    <i class="fa-solid fa-bullhorn"></i>
                    <div>
                        <strong><?php echo esc_html( $ws_ann->title ); ?></strong>
                        <span><?php echo esc_html( $ws_ann->message ); ?></span>
                    </div>
                    <?php if ( function_exists( 'ws_announcement_can_close' ) && ws_announcement_can_close( $ws_ann ) ) : ?>
                        <button type="button" class="ws-banner-close" onclick="wsDismissAnnouncement(<?php echo (int) $ws_ann->id; ?>, this)" aria-label="<?php esc_attr_e( 'Ocultar anuncio', 'workshop' ); ?>"><i class="fa-solid fa-xmark"></i></button>
                    <?php endif; ?>
                </div>
                <?php
            endforeach;
        endif;
        ?>
        <div class="ws-store-section-head">
            <h2><i class="fa-solid fa-store"></i> <?php esc_html_e( 'Tiendas', 'workshop' ); ?></h2>
            <span class="ws-store-section-count"><b><?php echo esc_html( count( $locations ) ); ?></b> <?php esc_html_e( 'tiendas', 'workshop' ); ?></span>
        </div>

        <div class="ws-landing-grid">
            <?php if ( empty( $locations ) ) : ?>
                <p class="ws-empty"><?php esc_html_e( 'Aún no hay tiendas disponibles.', 'workshop' ); ?></p>
            <?php endif; ?>

            <?php foreach ( $locations as $loc ) : ?>
                <a class="ws-store-card" href="<?php echo esc_url( ws_store_url( $loc ) ); ?>">
                    <?php if ( $loc->photo ) : ?>
                        <div class="ws-store-card-img" style="background-image:url('<?php echo esc_url( ws_image_url( $loc->photo ) ); ?>')"></div>
                    <?php else : ?>
                        <div class="ws-store-card-img ws-store-card-img-empty"><i class="fa-solid <?php echo 'pv' === $loc->type ? 'fa-store' : 'fa-warehouse'; ?>"></i></div>
                    <?php endif; ?>
                    <span class="ws-store-card-type"><?php echo esc_html( 'pv' === $loc->type ? __( 'Punto de venta', 'workshop' ) : __( 'Almacén', 'workshop' ) ); ?></span>
                    <div class="ws-store-card-body">
                        <h3><?php echo esc_html( $loc->name ); ?></h3>
                        <?php if ( ! empty( $loc->description ) ) : ?>
                            <p class="ws-store-card-desc"><?php echo esc_html( $loc->description ); ?></p>
                        <?php endif; ?>
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
