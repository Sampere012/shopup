<?php
/**
 * Mercado de negocios (portada de la raíz) — edición pro.
 *
 * El índice del sitio es el mercado con TODOS los negocios a los que el
 * administrador concedió acceso y que tienen su suscripción activa. Cada
 * tarjeta enlaza a la página principal del negocio (/negocio/), donde el
 * cliente entra en sus tiendas. Los negocios se ordenan por "mejor primero".
 *
 * Incluye secciones pensadas para captar negocios nuevos: cómo funciona,
 * planes y CTAs de registro con la prueba gratis de 7 días.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$businesses = WS_Business::marketplace_ranked();

// El índice del mercado usa la configuración del administrador (logo, portada
// y pie), no la apariencia del negocio por defecto ni de cada dueño.
$GLOBALS['ws_marketplace'] = true;
$mp_theme = ws_marketplace_theme();

$total_pvs      = 0;
$total_products = 0;
foreach ( $businesses as $b ) {
    $total_pvs      += (int) $b->ws_pvs;
    $total_products += (int) $b->ws_products;
}
$plans = WS_Plans::active();

// Estrellas de valoración.
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

// Frases rotativas de la máquina de escribir del hero (decorativas).
// La primera frase se pinta en el HTML como fallback si el JS no corre.
$ws_typed_phrases = array(
    __( 'Pedidos al instante', 'workshop' ),
    __( 'Stock sincronizado en tiempo real', 'workshop' ),
    __( 'Turnos y calendario de tu equipo', 'workshop' ),
    __( 'Reportes para decidir con datos', 'workshop' ),
    __( 'Tu tienda en el mercado desde hoy', 'workshop' ),
);

get_header();
?>
<div class="ws-landing ws-marketplace ws-mp-pro">

    <!-- ============ HERO ============ -->
    <section class="ws-mp-hero<?php echo ws_site_hero_has_bg() ? ' ws-has-bg' : ''; ?>" style="<?php echo esc_attr( ws_site_hero_bg_style() ); ?>">
        <div class="ws-mp-hero-bg" aria-hidden="true">
            <span class="ws-mp-grid-overlay"></span>
        </div>
        <!-- Overlay oscuro: legibilidad del texto sobre la imagen de fondo. -->
        <div class="ws-mp-hero-overlay" aria-hidden="true"></div>
        <!-- Humo con mix-blend-mode: capa ligera que da profundidad y brillo. -->
        <div class="ws-mp-hero-smoke" aria-hidden="true">
            <span class="ws-smoke ws-smoke-1"></span>
            <span class="ws-smoke ws-smoke-2"></span>
            <span class="ws-smoke ws-smoke-3"></span>
        </div>
        <div class="ws-container ws-mp-hero-inner">
            <div class="ws-mp-hero-cols">
                <div class="ws-mp-hero-left">
                    <span class="ws-hero-badge ws-mp-hero-badge"><i class="fa-solid fa-store"></i> <?php echo esc_html( ws_site_hero( 'hero_badge' ) ); ?></span>
                    <h1 class="ws-mp-hero-title">
                        <?php echo esc_html( ws_site_hero( 'hero_title' ) ); ?>
                    </h1>
                    <!-- Máquina de escribir: rota frases clave (decorativa). -->
                    <div class="ws-mp-typewriter" aria-hidden="true">
                        <span class="ws-mp-typewriter-text" id="ws-typewriter"><?php echo esc_html( $ws_typed_phrases[0] ); ?></span><span class="ws-mp-typewriter-caret"></span>
                    </div>
                    

                    <div class="ws-hero-stats ws-mp-hero-stats">
                        <div class="ws-hero-stat"><strong class="ws-count" data-count="<?php echo (int) count( $businesses ); ?>">0</strong><span><i class="fa-solid fa-store"></i> <?php esc_html_e( 'Negocios', 'workshop' ); ?></span></div>
                        <div class="ws-hero-stat"><strong class="ws-count" data-count="<?php echo (int) $total_pvs; ?>">0</strong><span><i class="fa-solid fa-location-dot"></i> <?php esc_html_e( 'Tiendas', 'workshop' ); ?></span></div>
                        <div class="ws-hero-stat"><strong class="ws-count" data-count="<?php echo (int) $total_products; ?>">0</strong><span><i class="fa-solid fa-box"></i> <?php esc_html_e( 'Productos', 'workshop' ); ?></span></div>
                    </div>
                </div>

                <!-- Mockup 3D del panel: impacto sin peso (CSS puro). -->
                <div class="ws-mp-hero-right" aria-hidden="true">
                    <div class="ws-mp-glow"></div>
                    <div class="ws-mp-mock">
                        <div class="ws-mp-mock-inner">
                            <div class="ws-mp-mock-top">
                                <span class="ws-mp-mock-dots"><i></i><i></i><i></i></span>
                                <span class="ws-mp-mock-url">panel.mitienda.com</span>
                            </div>
                            <div class="ws-mp-mock-body">
                                <div class="ws-mp-mock-kpis">
                                    <div class="ws-mp-mock-kpi"><span class="ws-mp-mock-kpi-num">128</span><span class="ws-mp-mock-kpi-lbl">Pedidos hoy</span></div>
                                    <div class="ws-mp-mock-kpi"><span class="ws-mp-mock-kpi-num">+34%</span><span class="ws-mp-mock-kpi-lbl">Ventas</span></div>
                                    <div class="ws-mp-mock-kpi"><span class="ws-mp-mock-kpi-num">42</span><span class="ws-mp-mock-kpi-lbl">Stock bajo</span></div>
                                </div>
                                <div class="ws-mp-mock-rows">
                                    <div class="ws-mp-mock-row">
                                        <span class="ws-mp-mock-row-ico ws-mp-ok"><i class="fa-solid fa-check"></i></span>
                                        <span class="ws-mp-mock-row-txt"><b>Pedido #1284</b><small>Juan P. · Café + croissant</small></span>
                                        <span class="ws-mp-mock-row-val">$12.50</span>
                                    </div>
                                    <div class="ws-mp-mock-row">
                                        <span class="ws-mp-mock-row-ico ws-mp-live"><i class="fa-solid fa-bolt"></i></span>
                                        <span class="ws-mp-mock-row-txt"><b>Stock sincronizado</b><small>Frijol especial · 42 und</small></span>
                                        <span class="ws-mp-mock-row-val ws-mp-good">En vivo</span>
                                    </div>
                                    <div class="ws-mp-mock-row">
                                        <span class="ws-mp-mock-row-ico ws-mp-chart"><i class="fa-solid fa-chart-line"></i></span>
                                        <span class="ws-mp-mock-row-txt"><b>Reporte de ventas</b><small>Últimos 7 días</small></span>
                                        <span class="ws-mp-mock-row-val">+18%</span>
                                    </div>
                                </div>
                                <div class="ws-mp-mock-bars">
                                    <span style="--h:42%"></span><span style="--h:68%"></span><span style="--h:34%"></span><span style="--h:78%"></span><span style="--h:55%"></span><span style="--h:92%"></span>
                                </div>
                            </div>
                        </div>
                        <span class="ws-mp-mock-chip ws-mp-mock-chip-1"><i class="fa-solid fa-bell"></i> +12 pedidos</span>
                        <span class="ws-mp-mock-chip ws-mp-mock-chip-2"><i class="fa-solid fa-bolt"></i> Stock en vivo</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <main class="ws-container">

        <!-- ============ TOP TIENDAS (carrusel) ============ -->
        <?php if ( ! empty( $businesses ) ) : ?>
        <section class="ws-mp-section-block ws-mp-top-block" id="ws-negocios">
            <div class="ws-section-head">
                <div>
                    <span class="ws-section-kicker"><i class="fa-solid fa-crown"></i> <?php esc_html_e( 'Lo mejor del mercado', 'workshop' ); ?></span>
                    <h2><?php esc_html_e( 'Top 5 tiendas', 'workshop' ); ?></h2>
                    <p class="ws-muted"><?php esc_html_e( 'Las tiendas con más actividad, ventas y mejores valoraciones.', 'workshop' ); ?></p>
                </div>
                <a class="ws-btn ws-btn-ghost ws-btn-sm ws-all-stores-btn" href="<?php echo esc_url( ws_marketplace_stores_url() ); ?>">
                    <i class="fa-solid fa-store"></i> <?php esc_html_e( 'Ver todas las tiendas', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <?php if ( empty( $businesses ) ) : ?>
                <div class="ws-mp-empty">
                    <i class="fa-solid fa-store"></i>
                    <h3><?php esc_html_e( 'Aún no hay negocios en el mercado', 'workshop' ); ?></h3>
                    <p><?php esc_html_e( 'El administrador del sitio está preparando las tiendas. Vuelve pronto.', 'workshop' ); ?></p>
                    <a class="ws-btn ws-btn-primary" href="<?php echo esc_url( ws_register_url() ); ?>"><?php esc_html_e( '¿Tienes un negocio? Únete gratis', 'workshop' ); ?></a>
                </div>
            <?php else : ?>
                <div class="ws-mp-carousel-nav" aria-hidden="true">
                    <button type="button" class="ws-car-btn" data-dir="-1" aria-label="<?php esc_attr_e( 'Anterior', 'workshop' ); ?>"><i class="fa-solid fa-chevron-left"></i></button>
                    <button type="button" class="ws-car-btn" data-dir="1" aria-label="<?php esc_attr_e( 'Siguiente', 'workshop' ); ?>"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
                <div class="ws-mp-carousel" id="ws-mp-carousel">
                    <?php foreach ( array_slice( $businesses, 0, 5 ) as $i => $biz ) :
                        // Logo de la tarjeta: el configurado en "Apariencia" del
                        // negocio (ws_site_theme); si no hay, el del registro de
                        // negocios (biz->logo); si nada, ícono genérico.
                        $biz_theme = ws_biz_option_for( 'ws_site_theme', array(), (int) $biz->id );
                        $biz_theme = is_array( $biz_theme ) ? $biz_theme : array();
                        $biz_logo  = ! empty( $biz_theme['logo'] ) ? $biz_theme['logo']
                            : ( ! empty( $biz->logo ) ? $biz->logo : '' );
                        $biz_url  = ws_business_url( $biz->slug );
                        $rank     = $i + 1;
                        ?>
                        <a class="ws-store-card ws-business-card ws-car-card<?php echo 0 === $i ? ' ws-car-card-top' : ''; ?>"
                           href="<?php echo esc_url( $biz_url ); ?>"
                           data-name="<?php echo esc_attr( strtolower( $biz->name ) ); ?>">
                            <span class="ws-car-rank" aria-hidden="true"><?php echo esc_html( $rank ); ?></span>
                            <?php if ( 0 === $i ) : ?>
                                <span class="ws-store-card-badge ws-featured-badge ws-car-badge"><i class="fa-solid fa-crown"></i> <?php esc_html_e( 'Nº 1 del mercado', 'workshop' ); ?></span>
                            <?php endif; ?>
                            <?php if ( $biz_logo ) : ?>
                                <div class="ws-store-card-img" style="background-image:url('<?php echo esc_url( ws_image_url( $biz_logo ) ); ?>')"></div>
                            <?php else : ?>
                                <div class="ws-store-card-img ws-store-card-img-empty"><i class="fa-solid fa-store"></i></div>
                            <?php endif; ?>
                            <span class="ws-store-card-type"><?php esc_html_e( 'Negocio', 'workshop' ); ?></span>
                            <div class="ws-store-card-body">
                                <div class="ws-card-title-row">
                                    <h3><?php echo esc_html( $biz->name ); ?></h3>
                                    <?php if ( $biz->ws_reviews ) : ?>
                                        <?php echo ws_mp_stars( $biz->ws_rating ); ?>
                                        <span class="ws-rating-count"><?php echo esc_html( number_format_i18n( $biz->ws_reviews ) ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( ! empty( $biz->description ) ) : ?>
                                    <p><?php echo esc_html( $biz->description ); ?></p>
                                <?php endif; ?>
                                <div class="ws-card-chips">
                                    <span class="ws-chip"><i class="fa-solid fa-location-dot"></i> <?php echo esc_html( sprintf( _n( '%d tienda', '%d tiendas', $biz->ws_pvs, 'workshop' ), $biz->ws_pvs ) ); ?></span>
                                    <span class="ws-chip"><i class="fa-solid fa-box"></i> <?php echo esc_html( sprintf( _n( '%d producto', '%d productos', $biz->ws_products, 'workshop' ), $biz->ws_products ) ); ?></span>
                                </div>
                                <span class="ws-link"><?php esc_html_e( 'Ver negocio', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="ws-mp-carousel-empty" id="ws-mp-carousel-empty" hidden>
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <p><?php esc_html_e( 'Ninguna tienda coincide con tu búsqueda. Explora el directorio completo.', 'workshop' ); ?></p>
                    <a class="ws-btn ws-btn-primary ws-btn-sm" href="<?php echo esc_url( ws_marketplace_stores_url() ); ?>"><?php esc_html_e( 'Ver todas las tiendas', 'workshop' ); ?></a>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ============ QUÉ SOLUCIONA / POR QUÉ REGISTRARSE ============ -->
        <section class="ws-mp-section-block ws-mp-value" id="ws-por-que">
            <div class="ws-section-head ws-section-head-center">
                <div>
                    <span class="ws-section-kicker"><i class="fa-solid fa-lightbulb"></i> <?php esc_html_e( 'Deja de complicarte', 'workshop' ); ?></span>
                    <h2><?php esc_html_e( '¿Cansado de anotar todo en tu libreta?', 'workshop' ); ?></h2>
                    <p class="ws-muted"><?php esc_html_e( 'Planillas arcaicas, depender de otras personas, perder el control de cada sucursal… esta plataforma lo integra todo, estés donde estés.', 'workshop' ); ?></p>
                </div>
            </div>

            <div class="ws-value-grid">
                <div class="ws-value-col">
                    <h3 class="ws-value-col-title"><i class="fa-solid fa-circle-check"></i> <?php esc_html_e( 'Qué soluciona', 'workshop' ); ?></h3>
                    <div class="ws-value-card">
                        <span class="ws-value-ico"><i class="fa-solid fa-book"></i></span>
                        <div>
                            <h4><?php esc_html_e( 'Adiós a la libreta y el papel', 'workshop' ); ?></h4>
                            <p><?php esc_html_e( 'Stock, pedidos y ventas se registran solos, en tiempo real. Nada se pierde.', 'workshop' ); ?></p>
                        </div>
                    </div>
                    <div class="ws-value-card">
                        <span class="ws-value-ico"><i class="fa-solid fa-sheet-plastic"></i></span>
                        <div>
                            <h4><?php esc_html_e( 'Fuera los modelos arcaicos', 'workshop' ); ?></h4>
                            <p><?php esc_html_e( 'Olvida las planillas a mano: los reportes y movimientos se generan solos.', 'workshop' ); ?></p>
                        </div>
                    </div>
                    <div class="ws-value-card">
                        <span class="ws-value-ico"><i class="fa-solid fa-user-group"></i></span>
                        <div>
                            <h4><?php esc_html_e( 'No dependas de nadie', 'workshop' ); ?></h4>
                            <p><?php esc_html_e( 'Tú controlas tu negocio: roles y permisos para tu equipo, sin intermediarios.', 'workshop' ); ?></p>
                        </div>
                    </div>
                    <div class="ws-value-card">
                        <span class="ws-value-ico"><i class="fa-solid fa-location-dot"></i></span>
                        <div>
                            <h4><?php esc_html_e( 'Dondequiera que estés', 'workshop' ); ?></h4>
                            <p><?php esc_html_e( 'Controla todas tus sucursales desde un solo panel, en línea y desde el móvil.', 'workshop' ); ?></p>
                        </div>
                    </div>
                </div>

                <div class="ws-value-col">
                    <h3 class="ws-value-col-title ws-value-col-title-accent"><i class="fa-solid fa-gift"></i> <?php esc_html_e( 'Por qué registrarte', 'workshop' ); ?></h3>
                    <div class="ws-value-card ws-value-card-accent">
                        <span class="ws-value-ico"><i class="fa-solid fa-gift"></i></span>
                        <div>
                            <h4><?php esc_html_e( '7 días gratis, sin tarjeta', 'workshop' ); ?></h4>
                            <p><?php esc_html_e( 'Prueba todo el sistema sin pagar nada y sin compromiso.', 'workshop' ); ?></p>
                        </div>
                    </div>
                    <div class="ws-value-card ws-value-card-accent">
                        <span class="ws-value-ico"><i class="fa-solid fa-rocket"></i></span>
                        <div>
                            <h4><?php esc_html_e( 'Vende en minutos', 'workshop' ); ?></h4>
                            <p><?php esc_html_e( 'Tu tienda online y tu negocio en el mercado, visibles para todos al instante.', 'workshop' ); ?></p>
                        </div>
                    </div>
                    <div class="ws-value-card ws-value-card-accent">
                        <span class="ws-value-ico"><i class="fa-solid fa-bolt"></i></span>
                        <div>
                            <h4><?php esc_html_e( 'Stock y pedidos en vivo', 'workshop' ); ?></h4>
                            <p><?php esc_html_e( 'Evita faltantes y sorpresas: todo sincronizado entre almacén y tiendas.', 'workshop' ); ?></p>
                        </div>
                    </div>
                    <div class="ws-value-card ws-value-card-accent">
                        <span class="ws-value-ico"><i class="fa-solid fa-layer-group"></i></span>
                        <div>
                            <h4><?php esc_html_e( 'Todo en un solo lugar', 'workshop' ); ?></h4>
                            <p><?php esc_html_e( 'POS, turnos, clientes, reportes y fidelización sin cambiar de aplicación.', 'workshop' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ CÓMO FUNCIONA ============ -->
        <section class="ws-mp-section-block ws-how-block" id="ws-como-funciona">
            <div class="ws-section-head ws-section-head-center">
                <div>
                    <span class="ws-section-kicker"><i class="fa-solid fa-wand-magic-sparkles"></i> <?php esc_html_e( 'Así de fácil', 'workshop' ); ?></span>
                    <h2><?php esc_html_e( 'Abre tu tienda en 3 pasos', 'workshop' ); ?></h2>
                    <p class="ws-muted"><?php esc_html_e( 'No necesitas experiencia técnica. En minutos tienes tu negocio online.', 'workshop' ); ?></p>
                </div>
            </div>
            <div class="ws-how-grid">
                <div class="ws-how-card">
                    <span class="ws-how-num">1</span>
                    <span class="ws-how-icon"><i class="fa-solid fa-user-plus"></i></span>
                    <h3><?php esc_html_e( 'Crea tu cuenta', 'workshop' ); ?></h3>
                    <p><?php esc_html_e( 'Regístrate con tu email, verifica tu correo y activa tu prueba gratis de 7 días.', 'workshop' ); ?></p>
                </div>
                <div class="ws-how-card">
                    <span class="ws-how-num">2</span>
                    <span class="ws-how-icon"><i class="fa-solid fa-palette"></i></span>
                    <h3><?php esc_html_e( 'Personaliza tu tienda', 'workshop' ); ?></h3>
                    <p><?php esc_html_e( 'Logo, colores, portada, productos y puntos de venta desde tu panel de control.', 'workshop' ); ?></p>
                </div>
                <div class="ws-how-card">
                    <span class="ws-how-num">3</span>
                    <span class="ws-how-icon"><i class="fa-solid fa-rocket"></i></span>
                    <h3><?php esc_html_e( 'Vende y crece', 'workshop' ); ?></h3>
                    <p><?php esc_html_e( 'Apareces en el mercado, recibes pedidos por WhatsApp y controlas stock y ventas.', 'workshop' ); ?></p>
                </div>
            </div>
        </section>

        <!-- ============ PLANES ============ -->
        <?php if ( ! empty( $plans ) ) : ?>
        <section class="ws-mp-section-block ws-plans-block" id="ws-planes">
            <div class="ws-section-head ws-section-head-center">
                <div>
                    <span class="ws-section-kicker"><i class="fa-solid fa-crown"></i> <?php esc_html_e( 'Precios', 'workshop' ); ?></span>
                    <h2><?php esc_html_e( 'Empieza gratis, crece a tu ritmo', 'workshop' ); ?></h2>
                    <p class="ws-muted"><?php esc_html_e( 'Todos los planes incluyen tu tienda, el panel y el marketplace. Cambia de plan cuando quieras.', 'workshop' ); ?></p>
                    <p class="ws-muted ws-plane-plan-note"><i class="fa-solid fa-gift"></i> <?php esc_html_e( 'La primera semana es freemium: usa todo sin coste.', 'workshop' ); ?></p>
                </div>
            </div>
            <div class="ws-plans-grid ws-plans-grid-front">
                <?php foreach ( $plans as $p ) :
                    $limits = WS_Plans::limits( $p );
                    $is_trial = (int) $p->is_trial === 1;
                    $popular = 'pro' === $p->slug;
                    ?>
                    <div class="ws-plan-card<?php echo $popular ? ' is-popular' : ''; ?>">
                        <?php if ( $is_trial ) : ?>
                            <span class="ws-plan-ribbon ws-plan-ribbon-trial"><i class="fa-solid fa-gift"></i> <?php esc_html_e( 'Prueba gratis', 'workshop' ); ?></span>
                        <?php elseif ( $popular ) : ?>
                            <span class="ws-plan-ribbon"><i class="fa-solid fa-fire"></i> <?php esc_html_e( 'Más popular', 'workshop' ); ?></span>
                        <?php endif; ?>
                        <h4><?php echo esc_html( $p->name ); ?></h4>
                        <p class="ws-plan-price">
                            <?php echo esc_html( WS_Plans::format_price( $p ) ); ?>
                            <small><?php echo esc_html( WS_Plans::duration_label( $p ) ); ?></small>
                        </p>
                        <?php if ( ! empty( $p->description ) ) : ?>
                            <p class="ws-plan-desc"><?php echo esc_html( $p->description ); ?></p>
                        <?php endif; ?>
                        <ul class="ws-plan-features">
                            <?php foreach ( $limits as $k => $v ) : ?>
                                <li><i class="fa-solid fa-check"></i>
                                    <?php echo esc_html( sprintf( __( '%1$s: %2$s', 'workshop' ), ucfirst( WS_Plans::limit_label( $k ) ), $v > 0 ? number_format_i18n( $v ) : '∞' ) ); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ============ CTA REGISTRO ============ -->
        <section class="ws-mp-section-block">
            <div class="ws-mp-cta">
                <div class="ws-mp-cta-bg" aria-hidden="true"><span class="ws-blob ws-blob-cta"></span></div>
                <div class="ws-mp-cta-inner">
                    <span class="ws-hero-badge"><i class="fa-solid fa-gift"></i> <?php esc_html_e( 'Tu oportunidad', 'workshop' ); ?></span>
                    <h2><?php esc_html_e( '¿Tienes un negocio? Ábrelo gratis por 7 días', 'workshop' ); ?></h2>
                    <p><?php esc_html_e( 'Crea tu tienda, personalízala y empieza a vender sin pagar nada. Sin tarjeta, sin compromiso.', 'workshop' ); ?></p>
                    <div class="ws-mp-cta-actions">
                        <a class="ws-btn ws-btn-accent ws-btn-lg" href="<?php echo esc_url( ws_register_url() ); ?>">
                            <i class="fa-solid fa-rocket"></i> <?php esc_html_e( 'Empezar', 'workshop' ); ?>
                        </a>
                        <span class="ws-mp-cta-note"><i class="fa-solid fa-clock"></i> <?php esc_html_e( 'La prueba dura 7 días · Te guiamos paso a paso al entrar', 'workshop' ); ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ BLOQUES DEL ADMIN ============ -->
        <?php if ( ! empty( $mp_theme['sections'] ) ) : ?>
            <div class="ws-mp-sections">
                <?php foreach ( $mp_theme['sections'] as $mp_section ) : ?>
                    <section class="ws-mp-section">
                        <?php if ( '' !== ( $mp_section['title'] ?? '' ) ) : ?>
                            <h2><?php echo esc_html( $mp_section['title'] ); ?></h2>
                        <?php endif; ?>
                        <?php if ( '' !== ( $mp_section['content'] ?? '' ) ) : ?>
                            <div class="ws-mp-section-content"><?php echo wp_kses_post( $mp_section['content'] ); ?></div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<script>
(function () {
    'use strict';
    var wsTypePhrases = <?php echo wp_json_encode( $ws_typed_phrases ); ?>;
    var carousel = document.getElementById('ws-mp-carousel');
    var searchInput = document.getElementById('ws-mp-header-search');

    // El buscador del topbar filtra las tarjetas del carrusel de top tiendas.
    var applyFilters = function () {
        if (!carousel) return;
        var q = (searchInput ? searchInput.value : '').trim().toLowerCase();
        var visible = 0;
        carousel.querySelectorAll('.ws-business-card').forEach(function (card) {
            var nameMatch = !q || card.getAttribute('data-name').indexOf(q) !== -1;
            card.style.display = nameMatch ? '' : 'none';
            if (nameMatch) visible++;
        });
        var empty = document.getElementById('ws-mp-carousel-empty');
        if (empty) empty.hidden = visible !== 0;
    };

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
        // Atajo de teclado: '/' enfoca el buscador del topbar.
        document.addEventListener('keydown', function (e) {
            if (e.key === '/' && document.activeElement && /^(input|textarea|select)$/i.test(document.activeElement.tagName)) return;
            if (e.key === '/' && !e.metaKey && !e.ctrlKey) {
                e.preventDefault();
                searchInput.focus();
            }
        });
    }

    // Flechas del carrusel de top tiendas (scroll suave por tarjetas).
    if (carousel) {
        var carBtns = document.querySelectorAll('.ws-mp-carousel-nav .ws-car-btn');
        carBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dir = parseInt(btn.getAttribute('data-dir'), 10) || 1;
                carousel.scrollBy({ left: dir * Math.round(carousel.clientWidth * 0.75), behavior: 'smooth' });
            });
        });
    }

    // Contadores animados del hero (IntersectionObserver).
    var counters = document.querySelectorAll('.ws-count[data-count]');
    if ('IntersectionObserver' in window && counters.length) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                io.unobserve(entry.target);
                var el = entry.target;
                var target = parseInt(el.getAttribute('data-count'), 10) || 0;
                var duration = 1200;
                var start = null;
                var step = function (ts) {
                    if (!start) start = ts;
                    var p = Math.min(1, (ts - start) / duration);
                    el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString('es');
                    if (p < 1) requestAnimationFrame(step);
                };
                requestAnimationFrame(step);
            });
        }, { threshold: 0.4 });
        counters.forEach(function (c) { io.observe(c); });
    } else {
        counters.forEach(function (c) { c.textContent = parseInt(c.getAttribute('data-count'), 10).toLocaleString('es'); });
    }

    // Revelado suave de tarjetas al hacer scroll.
    var reveal = document.querySelectorAll('.ws-business-card, .ws-how-card, .ws-plan-card, .ws-value-card');
    if ('IntersectionObserver' in window && reveal.length) {
        var rio = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    rio.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        reveal.forEach(function (el) { rio.observe(el); });
    } else {
        reveal.forEach(function (el) { el.classList.add('is-visible'); });
    }

    // Máquina de escribir del hero: rota frases clave con un caret parpadeante.
    var typeEl = document.getElementById('ws-typewriter');
    var typePhrases = (typeof wsTypePhrases !== 'undefined' && wsTypePhrases.length) ? wsTypePhrases : [];
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (typeEl && typePhrases.length && !reduced) {
        var pi = 0, ci = 0, deleting = false;
        (function typeTick() {
            var word = typePhrases[pi];
            ci = deleting ? ci - 1 : ci + 1;
            typeEl.textContent = word.slice(0, ci);
            var delay = deleting ? 28 : 75;
            if (!deleting && ci === word.length) { delay = 1900; deleting = true; }
            else if (deleting && ci === 0) { deleting = false; pi = (pi + 1) % typePhrases.length; delay = 420; }
            setTimeout(typeTick, delay);
        })();
    } else if (typeEl && typePhrases.length) {
        typeEl.textContent = typePhrases[0];
    }

    // Parallax de scroll: el contenido del hero sube más lento que el scroll
    // y se atenúa al alejarse, invitando al usuario a seguir explorando.
    var heroInner = document.querySelector('.ws-mp-hero-inner');
    if (heroInner && !reduced) {
        var scrollTicking = false;
        var onHeroScroll = function () {
            if (scrollTicking) return;
            scrollTicking = true;
            requestAnimationFrame(function () {
                var y = window.scrollY || window.pageYOffset;
                var vh = window.innerHeight || 1;
                // Solo parallax de desplazamiento: el contenido nunca se
                // desvanece, queda siempre visible mientras se hace scroll.
                if (y < vh * 1.4) {
                    heroInner.style.transform = 'translateY(' + Math.round(y * .1) + 'px)';
                }
                scrollTicking = false;
            });
        };
        window.addEventListener('scroll', onHeroScroll, { passive: true });
        onHeroScroll();
    }

    // Tilt 3D del mockup: sigue el cursor sobre su columna (solo escritorio).
    var mock = document.querySelector('.ws-mp-mock');
    var mockZone = document.querySelector('.ws-mp-hero-right');
    var mmq = window.matchMedia ? window.matchMedia('(min-width: 901px)') : null;
    if (mock && mockZone && !reduced && mmq && mmq.matches) {
        var tiltTicking = false;
        mockZone.addEventListener('mousemove', function (e) {
            if (tiltTicking) return;
            tiltTicking = true;
            requestAnimationFrame(function () {
                var r = mockZone.getBoundingClientRect();
                var x = (e.clientX - r.left) / r.width - .5;
                var y = (e.clientY - r.top) / r.height - .5;
                mock.style.transform = 'perspective(950px) rotateY(' + (x * 12).toFixed(2) + 'deg) rotateX(' + (-y * 10).toFixed(2) + 'deg)';
                tiltTicking = false;
            });
        }, { passive: true });
        mockZone.addEventListener('mouseleave', function () { mock.style.transform = ''; });
    }
})();
</script>
<?php get_footer(); ?>
