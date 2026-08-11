<?php
/**
 * Directorio de tiendas del mercado (/marketplace/).
 *
 * Muestra TODOS los negocios del mercado con su marketing propio (descripción,
 * logo, actividad) y permite ordenarlos por recomendación, ventas, valoración
 * e interacción. Rompe el molde del card en grid con filas horizontales de
 * perfil: número de ranking, qué vende, por qué comprar y CTA directo a la
 * tienda del negocio. Los dueños venden su negocio con descripción, logo y
 * actividad (pedidos, tiendas, facturación, valoraciones).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$businesses = WS_Business::marketplace_ranked();
$GLOBALS['ws_marketplace'] = true;
$mp_theme = ws_marketplace_theme();

$total_pvs      = 0;
$total_products = 0;
$total_orders   = 0;
foreach ( $businesses as $b ) {
    $total_pvs      += (int) $b->ws_pvs;
    $total_products += (int) $b->ws_products;
    $total_orders   += (int) $b->ws_orders;
}

// Estrellas de valoración (misma guarda que marketplace.php).
if ( ! function_exists( 'ws_mp_stars' ) ) {
    function ws_mp_stars( $rating ) {
        $rating = max( 0, min( 5, (float) $rating ) );
        $out    = '<span class="ws-rating" aria-label="' . esc_attr( sprintf( '%s / 5', number_format_i18n( $rating, 1 ) ) ) . '">';
        for ( $i = 1; $i <= 5; $i++ ) {
            $out .= $rating >= $i - .25 ? '<i class="fa-solid fa-star"></i>'
                : ( $rating >= $i - .75 ? '<i class="fa-solid fa-star-half-stroke"></i>' : '<i class="fa-regular fa-star"></i>' );
        }
        $out .= '</span>';
        return $out;
    }
}

get_header();
?>
<div class="ws-landing ws-marketplace ws-mp-pro ws-stores-page">

    <!-- ============ CABECERA ============ -->
    <section class="ws-stores-hero<?php echo ws_site_hero_has_bg() ? ' ws-has-bg' : ''; ?>" style="<?php echo esc_attr( ws_site_hero_bg_style() ); ?>">
        <div class="ws-stores-hero-bg" aria-hidden="true"><span class="ws-mp-grid-overlay"></span></div>
        <div class="ws-stores-hero-overlay" aria-hidden="true"></div>
        <div class="ws-container ws-stores-hero-inner">
            <span class="ws-hero-badge ws-mp-hero-badge"><i class="fa-solid fa-store"></i> <?php esc_html_e( 'Directorio de tiendas', 'workshop' ); ?></span>
            <h1><?php esc_html_e( 'Todas las tiendas del mercado', 'workshop' ); ?></h1>
            <p class="ws-stores-hero-sub"><?php esc_html_e( 'Descubre qué vende cada negocio, por qué comprarle y entra directo a su tienda. Los dueños cuentan su historia: tú eliges.', 'workshop' ); ?></p>
            <div class="ws-hero-stats ws-mp-hero-stats">
                <div class="ws-hero-stat"><strong><?php echo esc_html( ws_compact_number( count( $businesses ) ) ); ?></strong><span><i class="fa-solid fa-store"></i> <?php esc_html_e( 'Negocios', 'workshop' ); ?></span></div>
                <div class="ws-hero-stat"><strong><?php echo esc_html( ws_compact_number( $total_pvs ) ); ?></strong><span><i class="fa-solid fa-location-dot"></i> <?php esc_html_e( 'Tiendas', 'workshop' ); ?></span></div>
                <div class="ws-hero-stat"><strong><?php echo esc_html( ws_compact_number( $total_products ) ); ?></strong><span><i class="fa-solid fa-box"></i> <?php esc_html_e( 'Productos', 'workshop' ); ?></span></div>
                <div class="ws-hero-stat"><strong><?php echo esc_html( ws_compact_number( $total_orders ) ); ?></strong><span><i class="fa-solid fa-bag-shopping"></i> <?php esc_html_e( 'Pedidos', 'workshop' ); ?></span></div>
            </div>
        </div>
    </section>

    <main class="ws-container">

        <!-- ============ ORDEN Y LISTA ============ -->
        <section class="ws-stores-block" id="ws-tiendas">
            <div class="ws-stores-head">
                <div class="ws-mp-filter-tabs ws-stores-sort" id="ws-stores-sort">
                    <button type="button" class="is-active" data-sort="score"><i class="fa-solid fa-wand-magic-sparkles"></i> <?php esc_html_e( 'Recomendados', 'workshop' ); ?></button>
                    <button type="button" data-sort="orders"><i class="fa-solid fa-fire"></i> <?php esc_html_e( 'Más vendidos', 'workshop' ); ?></button>
                    <button type="button" data-sort="rating"><i class="fa-solid fa-star"></i> <?php esc_html_e( 'Mejor valorados', 'workshop' ); ?></button>
                    <button type="button" data-sort="interact"><i class="fa-solid fa-comments"></i> <?php esc_html_e( 'Más interacción', 'workshop' ); ?></button>
                </div>
                <span class="ws-stores-count" id="ws-stores-count"><?php echo esc_html( sprintf( _n( '%d tienda', '%d tiendas', count( $businesses ), 'workshop' ), count( $businesses ) ) ); ?></span>
            </div>

            <?php if ( empty( $businesses ) ) : ?>
                <div class="ws-mp-empty">
                    <i class="fa-solid fa-store"></i>
                    <h3><?php esc_html_e( 'Aún no hay tiendas en el mercado', 'workshop' ); ?></h3>
                    <p><?php esc_html_e( 'El administrador del sitio está preparando los negocios. Vuelve pronto.', 'workshop' ); ?></p>
                    <a class="ws-btn ws-btn-primary" href="<?php echo esc_url( ws_register_url() ); ?>"><?php esc_html_e( '¿Tienes un negocio? Únete gratis', 'workshop' ); ?></a>
                </div>
            <?php else : ?>
                <div class="ws-stores-list" id="ws-stores-list">
                    <?php foreach ( $businesses as $i => $biz ) :
                        $biz_theme = ws_biz_option_for( 'ws_site_theme', array(), (int) $biz->id );
                        $biz_theme = is_array( $biz_theme ) ? $biz_theme : array();
                        $biz_logo  = ! empty( $biz_theme['logo'] ) ? $biz_theme['logo']
                            : ( ! empty( $biz->logo ) ? $biz->logo : '' );
                        $biz_url   = ws_business_url( $biz->slug );
                        $ws_cur    = (string) ws_biz_option_for( 'ws_currency', '€', (int) $biz->id );
                        $interact  = ( (int) $biz->ws_pvs * 3 ) + ( (int) $biz->ws_reviews * 2 ) + ( (int) $biz->ws_orders * 2 );
                        $rank      = $i + 1;
                        ?>
                        <a class="ws-store-row ws-business-card<?php echo 0 === $i ? ' ws-store-row-first' : ''; ?>"
                           href="<?php echo esc_url( $biz_url ); ?>"
                           data-name="<?php echo esc_attr( strtolower( $biz->name ) ); ?>"
                           data-score="<?php echo (int) $biz->ws_score; ?>"
                           data-orders="<?php echo (int) $biz->ws_orders; ?>"
                           data-rating="<?php echo (float) $biz->ws_rating; ?>"
                           data-interact="<?php echo (int) $interact; ?>">
                            <span class="ws-store-row-rank" aria-hidden="true"><?php echo esc_html( $rank ); ?></span>

                            <?php if ( $biz_logo ) : ?>
                                <span class="ws-store-row-img" style="background-image:url('<?php echo esc_url( ws_image_url( $biz_logo ) ); ?>')"></span>
                            <?php else : ?>
                                <span class="ws-store-row-img ws-store-row-img-empty"><i class="fa-solid fa-store"></i></span>
                            <?php endif; ?>

                            <span class="ws-store-row-main">
                                <span class="ws-store-row-title">
                                    <b><?php echo esc_html( $biz->name ); ?></b>
                                    <?php if ( $biz->ws_reviews ) : ?>
                                        <?php echo ws_mp_stars( $biz->ws_rating ); ?>
                                        <small class="ws-rating-count"><?php echo esc_html( ws_compact_number( $biz->ws_reviews ) ); ?></small>
                                    <?php endif; ?>
                                </span>
                                <?php if ( ! empty( $biz->description ) ) : ?>
                                    <span class="ws-store-row-desc"><?php echo esc_html( $biz->description ); ?></span>
                                <?php else : ?>
                                    <span class="ws-store-row-desc ws-store-row-desc-empty"><?php esc_html_e( 'Negocio verificado en el mercado. Entra y descubre sus productos.', 'workshop' ); ?></span>
                                <?php endif; ?>
                                <span class="ws-store-row-chips">
                                    <span class="ws-chip"><i class="fa-solid fa-location-dot"></i> <?php echo esc_html( sprintf( _n( '%s tienda', '%s tiendas', $biz->ws_pvs, 'workshop' ), ws_compact_number( $biz->ws_pvs ) ) ); ?></span>
                                    <span class="ws-chip"><i class="fa-solid fa-box"></i> <?php echo esc_html( sprintf( _n( '%s producto', '%s productos', $biz->ws_products, 'workshop' ), ws_compact_number( $biz->ws_products ) ) ); ?></span>
                                    <?php if ( $biz->ws_orders ) : ?>
                                        <span class="ws-chip"><i class="fa-solid fa-bag-shopping"></i> <?php echo esc_html( sprintf( _n( '%s pedido', '%s pedidos', $biz->ws_orders, 'workshop' ), ws_compact_number( $biz->ws_orders ) ) ); ?></span>
                                        <span class="ws-chip ws-chip-accent"><i class="fa-solid fa-coins"></i> <?php echo esc_html( $ws_cur . ws_compact_number( $biz->ws_revenue ) ); ?> <?php esc_html_e( 'facturados', 'workshop' ); ?></span>
                                    <?php else : ?>
                                        <span class="ws-chip ws-chip-new"><i class="fa-solid fa-sparkles"></i> <?php esc_html_e( 'Nuevo en el mercado', 'workshop' ); ?></span>
                                    <?php endif; ?>
                                </span>
                            </span>

                            <span class="ws-store-row-cta">
                                <span class="ws-btn ws-btn-primary ws-btn-sm"><?php esc_html_e( 'Visitar tienda', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></span>
                                <small><i class="fa-solid fa-rotate-right"></i> <?php esc_html_e( 'Pedidos en línea', 'workshop' ); ?></small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="ws-stores-empty-search" id="ws-stores-empty" hidden>
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <p><?php esc_html_e( 'Ninguna tienda coincide con tu búsqueda. Prueba con otro nombre.', 'workshop' ); ?></p>
                </div>
            <?php endif; ?>
        </section>

        <!-- ============ CTA PARA NEGOCIOS ============ -->
        <section class="ws-mp-section-block">
            <div class="ws-mp-cta">
                <div class="ws-mp-cta-bg" aria-hidden="true"><span class="ws-blob ws-blob-cta"></span></div>
                <div class="ws-mp-cta-inner">
                    <span class="ws-hero-badge"><i class="fa-solid fa-bullhorn"></i> <?php esc_html_e( '¿Tienes un negocio?', 'workshop' ); ?></span>
                    <h2><?php esc_html_e( 'Aparece aquí y cuenta tu historia', 'workshop' ); ?></h2>
                    <p><?php esc_html_e( 'Con tu descripción, logo y actividad bien presentados, los clientes te encuentran y te eligen. Ábrelo gratis por 7 días.', 'workshop' ); ?></p>
                    <div class="ws-mp-cta-actions">
                        <a class="ws-btn ws-btn-accent ws-btn-lg" href="<?php echo esc_url( ws_register_url() ); ?>">
                            <i class="fa-solid fa-rocket"></i> <?php esc_html_e( 'Empezar', 'workshop' ); ?>
                        </a>
                        <span class="ws-mp-cta-note"><i class="fa-solid fa-clock"></i> <?php esc_html_e( '7 días gratis · Sin tarjeta · Te guiamos paso a paso', 'workshop' ); ?></span>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
<script>
(function () {
    'use strict';
    var list = document.getElementById('ws-stores-list');
    if (!list) return;

    var rows = Array.prototype.slice.call(list.querySelectorAll('.ws-store-row'));
    var sortBtns = document.querySelectorAll('#ws-stores-sort button');
    var searchInput = document.getElementById('ws-mp-header-search');
    var countEl = document.getElementById('ws-stores-count');
    var emptyEl = document.getElementById('ws-stores-empty');
    var currentSort = 'score';
    var query = '';

    // Ordena la lista según el criterio activo y renumeriza los puestos.
    var applySort = function () {
        rows.sort(function (a, b) {
            var av = parseFloat(a.getAttribute('data-' + currentSort)) || 0;
            var bv = parseFloat(b.getAttribute('data-' + currentSort)) || 0;
            if (av !== bv) return bv - av;
            // Desempate: a igual métrica, mejor puntuación primero.
            return parseInt(b.getAttribute('data-score'), 10) - parseInt(a.getAttribute('data-score'), 10);
        });
        rows.forEach(function (row, idx) {
            list.appendChild(row);
            var rank = row.querySelector('.ws-store-row-rank');
            if (rank) rank.textContent = idx + 1;
            row.classList.toggle('ws-store-row-first', idx === 0);
        });
    };

    var applyFilters = function () {
        var visible = 0;
        rows.forEach(function (row) {
            var nameMatch = !query || row.getAttribute('data-name').indexOf(query) !== -1;
            row.style.display = nameMatch ? '' : 'none';
            if (nameMatch) visible++;
        });
        if (countEl) countEl.textContent = visible === rows.length
            ? (rows.length + ' ' + (rows.length === 1 ? 'tienda' : 'tiendas'))
            : (visible + (visible === 1 ? ' tienda' : ' tiendas'));
        if (emptyEl) emptyEl.hidden = visible !== 0;
    };

    sortBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            sortBtns.forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            currentSort = btn.getAttribute('data-sort') || 'score';
            applySort();
            applyFilters();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            query = searchInput.value.trim().toLowerCase();
            applyFilters();
        });
    }

    // Revelado suave de las filas al hacer scroll.
    if ('IntersectionObserver' in window && rows.length) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });
        rows.forEach(function (row) { io.observe(row); });
    } else {
        rows.forEach(function (row) { row.classList.add('is-visible'); });
    }
})();
</script>
<?php get_footer(); ?>
