<?php
/**
 * Tienda pública de un PV.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$location = get_query_var( 'ws_location' );
// Solo lo VISIBLE EN LA TIENDA: un producto/combo puede existir en el
// inventario y estar oculto del catálogo público (toggle en Stock).
$products = WS_Stock::stock_rows( array( 'location_id' => $location->id, 'store_visible' => 1 ) );
// Combos activos y visibles como ítems del catálogo (stock derivado).
// SOLO los que esta tienda puede vender: el combo necesita stock de TODOS sus
// productos en ESTA ubicación (qty derivado > 0). Si no los tiene, no se
// muestra el combo y sus productos salen sueltos como productos normales.
$combos = array_values( array_filter(
    WS_Combos::catalog_rows( $location->id ),
    fn( $c ) => ! empty( $c['store_visible'] ) && (float) $c['qty'] > 0
) );
// Los productos que componen un combo visible CON STOCK no salen sueltos en
// esta tienda: se agrupan dentro del card del combo (el combo es un producto
// que contiene varios productos). En tiendas donde el combo no tiene stock,
// sus productos sí aparecen sueltos (es el catálogo normal de esa tienda).
$combo_product_ids = array();
foreach ( $combos as $c ) {
    foreach ( (array) ( $c['items'] ?? array() ) as $it ) {
        $combo_product_ids[ (int) ( $it['product_id'] ?? 0 ) ] = true;
    }
}
if ( $combo_product_ids ) {
    $products = array_values( array_filter( $products, function ( $p ) use ( $combo_product_ids ) {
        return ! isset( $combo_product_ids[ (int) ( $p->product_id ?? 0 ) ] );
    } ) );
}
$products = array_merge( $products, $combos );
$payments = ws_payment_methods( $location->id );

// Datos de monedas/tasas/WhatsApp para la tienda.
$store_currency = $location->currency ? $location->currency : ws_currency_symbol();

// Configuración de la tienda pública por ubicación: moneda en la que se
// muestran los precios ('' = la de la ubicación) y qué tasa mostrar
// ('' = automática USD/CUP, 'none' = ninguna, o una moneda concreta).
$store_settings = array();
if ( ! empty( $location->store_settings ) ) {
    $ss_decoded = json_decode( $location->store_settings, true );
    if ( is_array( $ss_decoded ) ) {
        $store_settings = $ss_decoded;
    }
}
$store_display_currency = sanitize_text_field( (string) ( $store_settings['currency'] ?? '' ) );
if ( '' === $store_display_currency ) {
    $store_display_currency = $store_currency;
}
// Precio a mostrar: 'product' = el precio NATIVO del producto (su moneda);
// 'location' = el precio convertido a la moneda de la tienda (la ubicación).
// Con 'product' no se adapta nada (displayCurrency se deja vacío).
$store_price_source = sanitize_text_field( (string) ( $store_settings['price_source'] ?? '' ) );
if ( 'product' === $store_price_source ) {
    $store_display_currency = '';
} elseif ( 'location' !== $store_price_source ) {
    // Sin configurar: comportamiento actual = convertir a la moneda de la ubicación.
    $store_display_currency = $store_currency;
}
$store_rate_mode = sanitize_text_field( (string) ( $store_settings['rate'] ?? '' ) );

// Badge de tasa: el elegido por la ubicación, ninguno, o el automático.
$rate_badge = ws_rate_badge();
if ( 'none' === $store_rate_mode ) {
    $rate_badge = '';
} elseif ( '' !== $store_rate_mode ) {
    // Las tasas son respecto a la moneda BASE del negocio (p. ej. CUP).
    $ws_rates_now = ws_exchange_rates();
    $ws_rate_base = ws_currency_symbol();
    $ws_rate_val  = (float) ( $ws_rates_now[ $store_rate_mode ] ?? 0 );
    $rate_badge   = ( $ws_rate_val > 0 && $store_rate_mode !== $ws_rate_base )
        ? sprintf( '1 %s = %s %s', $store_rate_mode, number_format_i18n( $ws_rate_val, 2 ), $ws_rate_base )
        : '';
}
$ws_store_rates = ws_exchange_rates();
$ws_store_base  = ws_currency_symbol();
$ws_store_currs = ws_currencies();
$ws_store_was   = ws_whatsapp_numbers( $location->id );

// Árbol de categorías para el filtro de la tienda (subcategorías en cascada).
$ws_store_categories = function_exists( 'ws_categories_payload' )
    ? ws_categories_payload()
    : array( 'tree' => array(), 'flat' => array() );

// Fondo del hero de la tienda: la foto del PV (comprimida) si está definida.
$ws_store_has_bg = ! empty( $location->photo );
$ws_store_hero_bg = $ws_store_has_bg
    ? "background-image:url('" . ws_image_url( $location->photo ) . "');background-size:cover;background-position:center;"
    : '';

// Domicilio: el coste tiene SU PROPIA moneda (editable en el panel), que
// puede ser distinta a la de la tienda (p. ej. la tienda vende en USD y el
// domicilio se cobra en CUP). En el hero se muestra en la moneda del
// domicilio y, si la tienda usa otra para los precios, su equivalente.
$ws_delivery_currency = $location->delivery_currency ? $location->delivery_currency : $store_currency;
$ws_delivery_value    = (float) $location->delivery_cost;
$ws_delivery_show_cur = ( '' !== $store_display_currency ) ? $store_display_currency : $ws_delivery_currency;
$ws_delivery_show_val = ws_convert( $ws_delivery_value, $ws_delivery_currency, $ws_delivery_show_cur );

get_header();
?>
<div class="ws-store"
     x-data="wsStore({
        locationId: <?php echo (int) $location->id; ?>,
        deliveryCost: <?php echo (float) $location->delivery_cost; ?>,
        deliveryCurrency: '<?php echo esc_js( $ws_delivery_currency ); ?>',
        currency: '<?php echo esc_js( $store_currency ); ?>',
        slug: '<?php echo esc_js( $location->slug ); ?>'
     })">
    <header class="ws-store-head<?php echo $ws_store_has_bg ? ' ws-has-bg' : ''; ?>" style="<?php echo esc_attr( $ws_store_hero_bg ); ?>">
        <div class="ws-container ws-store-head-inner">
            <div>
                <a class="ws-store-back" href="<?php echo esc_url( ws_business_home() ); ?>"><i class="fa-solid fa-arrow-left"></i></a>
                <h1><?php echo esc_html( $location->name ); ?></h1>
                <?php if ( ! empty( $location->description ) ) : ?>
                    <p class="ws-store-desc"><?php echo esc_html( $location->description ); ?></p>
                <?php endif; ?>
                <p><i class="fa-solid fa-location-dot"></i> <?php echo esc_html( $location->address ); ?></p>
                <div class="ws-store-head-row">
                    <?php if ( (float) $location->delivery_cost > 0 ) : ?>
                        <?php
                        $ws_delivery_label = __( 'Domicilio', 'workshop' ) . ': ' . ws_money( $ws_delivery_value, $ws_delivery_currency );
                        if ( $ws_delivery_show_cur !== $ws_delivery_currency && abs( $ws_delivery_show_val - $ws_delivery_value ) > 0.001 ) {
                            $ws_delivery_label .= ' (≈ ' . ws_money( $ws_delivery_show_val, $ws_delivery_show_cur ) . ')';
                        }
                        ?>
                        <span class="ws-store-meta"><i class="fa-solid fa-truck-fast"></i> <?php echo esc_html( $ws_delivery_label ); ?></span>
                    <?php else : ?>
                        <span class="ws-store-meta"><i class="fa-solid fa-truck-fast"></i> <?php esc_html_e( 'Recogida gratis', 'workshop' ); ?></span>
                    <?php endif; ?>
                    <?php if ( $payments ) : ?>
                        <span class="ws-store-meta"><i class="fa-solid fa-credit-card"></i> <?php echo esc_html( implode( ' · ', $payments ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $rate_badge ) : ?>
                        <span class="ws-store-meta ws-store-rate"><i class="fa-solid fa-arrow-right-arrow-left"></i> <?php echo esc_html( $rate_badge ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ws-cart-btn-wrap">
                <button class="ws-sound-toggle" type="button" @click="toggleSound()" :aria-pressed="soundOn" :title="soundOn ? '<?php echo esc_js( __( 'Sonido activado', 'workshop' ) ); ?>' : '<?php echo esc_js( __( 'Sonido desactivado', 'workshop' ) ); ?>'" aria-label="<?php esc_attr_e( 'Sonido al añadir', 'workshop' ); ?>">
                    <i class="fa-solid" :class="soundOn ? 'fa-volume-high' : 'fa-volume-xmark'"></i>
                </button>
                <button class="ws-btn ws-cart-toggle" @click="openCart()">
                    <i class="fa-solid fa-cart-shopping"></i>
                    <span class="ws-hide-sm"><?php esc_html_e( 'Pedido', 'workshop' ); ?></span>
                    <span class="ws-cart-count" x-show="cartCount > 0" x-text="cartCount"></span>
                </button>
            </div>
        </div>
    </header>

    <div class="ws-container ws-store-layout">
        <main class="ws-store-products">
            <div class="ws-store-breadcrumb">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><i class="fa-solid fa-house"></i> <?php esc_html_e( 'Inicio', 'workshop' ); ?></a>
                <i class="fa-solid fa-chevron-right"></i>
                <a href="<?php echo esc_url( ws_business_home() ); ?>"><?php esc_html_e( 'Tiendas', 'workshop' ); ?></a>
                <i class="fa-solid fa-chevron-right"></i>
                <span><?php echo esc_html( $location->name ); ?></span>
            </div>

            <div class="ws-store-section-head">
                <h2><i class="fa-solid fa-box"></i> <?php esc_html_e( 'Productos', 'workshop' ); ?></h2>
                <span class="ws-store-section-count"><b x-text="filtered.length"></b> <?php esc_html_e( 'disponibles', 'workshop' ); ?></span>
            </div>

            <div class="ws-search ws-search-action">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="<?php esc_attr_e( 'Buscar producto…', 'workshop' ); ?>" x-model="searchTerm">
                <button class="ws-btn ws-btn-primary ws-btn-sm" type="button" @click="applySearch()">
                    <i class="fa-solid fa-magnifying-glass"></i> <?php esc_html_e( 'Buscar', 'workshop' ); ?>
                </button>
            </div>

            <?php if ( ! empty( $ws_store_categories['flat'] ) ) : ?>
            <div class="ws-store-category-filter">
                <div class="ws-store-category-row">
                    <button type="button" class="ws-store-category-chip" :class="categoryFilter === 0 ? 'is-active' : ''" @click="categoryFilter = 0; applySearch();">
                        <?php esc_html_e( 'Todas', 'workshop' ); ?>
                    </button>
                    <template x-for="c in categoryOptions" :key="c.id">
                        <button type="button" class="ws-store-category-chip" :class="categoryFilter === c.id ? 'is-active' : ''" @click="categoryFilter = Number(c.id); applySearch();" x-text="c.name"></button>
                    </template>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( empty( $products ) ) : ?>
                <p class="ws-empty"><?php esc_html_e( 'Esta tienda aún no tiene productos disponibles.', 'workshop' ); ?></p>
            <?php endif; ?>

            <div class="ws-product-grid">
                <template x-for="p in filtered" :key="p.id">
                    <div class="ws-product-card" :data-pid="p.id" :class="(inCart(p.id) > 0 ? 'is-in-cart ' : '') + (p.is_combo ? 'ws-is-combo' : '')" @click="openProduct(p)" role="button" :aria-label="'Ver ' + p.name">
                        <span class="ws-product-out" x-show="p.qty <= 0"><?php esc_html_e( 'Agotado', 'workshop' ); ?></span>
                        <span class="ws-product-incart" x-show="inCart(p.id) > 0"><i class="fa-solid fa-check"></i> <span x-text="'En pedido: ' + inCart(p.id)"></span></span>
                        <div class="ws-product-img">
                            <img x-show="p.image" :src="p.image" :alt="p.name" loading="lazy">
                            <i x-show="!p.image" class="fa-solid fa-box-open"></i>
                        </div>
                        <div class="ws-product-info">
                            <div class="ws-product-title">
                                <h3 x-text="p.name"></h3>
                                <span class="ws-combo-badge" x-show="p.is_combo" title="<?php esc_attr_e( 'Este producto es un combo', 'workshop' ); ?>"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                            </div>
                            <!-- La categoría de un combo es 'Combo': ya lo indica la badge, no repetirlo debajo del nombre. -->
                            <span class="ws-product-category" x-show="p.category && !p.is_combo" x-text="p.category"></span>
                            <div class="ws-product-row">
                                <span class="ws-price" x-text="priceInfo(p).main"></span>
                                <span class="ws-price-equiv" x-show="priceInfo(p).equiv" x-text="priceInfo(p).equiv"></span>
                                <span class="ws-price-transfer" x-show="p.transfer_pct > 0" x-text="transferLine(p)"></span>
                                <span class="ws-stock-badge" :class="p.qty > 0 ? 'ws-text-success' : 'ws-text-danger'" x-text="stockLabel(p)"></span>
                            </div>
                            <div class="ws-combo-items ws-store-combo-items" x-show="p.is_combo && (p.combo_items || []).length">
                                <span class="ws-combo-items-label"><i class="fa-solid fa-cubes"></i> <?php esc_html_e( 'Contiene', 'workshop' ); ?></span>
                                <!-- Solo los primeros 4 componentes para no estirar el card; el resto se ve en el modal. -->
                                <template x-for="it in (p.combo_items || []).slice(0, 4)" :key="it.product_id">
                                    <span class="ws-combo-chip"><i class="fa-solid fa-box"></i><span x-text="it.name"></span><b x-text="'×' + it.qty"></b></span>
                                </template>
                                <button type="button" class="ws-combo-more" x-show="(p.combo_items || []).length > 4" @click.stop="openProduct(p)" :title="'<?php esc_attr_e( 'Ver todos los productos del combo', 'workshop' ); ?>'">
                                    <i class="fa-solid fa-layer-group"></i> +<span x-text="(p.combo_items || []).length - 4"></span> <?php esc_html_e( 'ver más', 'workshop' ); ?>
                                </button>
                            </div>
                            <div class="ws-product-actions">
                                <button class="ws-btn ws-btn-ghost ws-btn-sm ws-btn-block" @click.stop="openProduct(p)">
                                    <i class="fa-solid fa-eye"></i> <?php esc_html_e( 'Ver', 'workshop' ); ?>
                                </button>
                                <button class="ws-btn ws-btn-sm ws-btn-block"
                                        :class="inCart(p.id) > 0 ? 'ws-btn-success' : 'ws-btn-primary'"
                                        :disabled="p.qty <= 0"
                                        @click.stop="add(p)">
                                    <i class="fa-solid" :class="inCart(p.id) > 0 ? 'fa-circle-check' : 'fa-cart-plus'"></i>
                                    <span x-text="inCart(p.id) > 0 ? 'Añadido ✓' : 'Añadir'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Consultar estado de pedido -->
            <div class="ws-track-card ws-card">
                <button class="ws-track-toggle" @click="toggleTrack()">
                    <i class="fa-solid fa-magnifying-glass-location"></i>
                    <span>
                        <strong><?php esc_html_e( '¿Dónde está mi pedido?', 'workshop' ); ?></strong>
                        <small><?php esc_html_e( 'Consulta el estado con tu número de pedido y teléfono.', 'workshop' ); ?></small>
                    </span>
                    <i class="fa-solid fa-chevron-down" :class="trackOpen ? 'ws-rotated' : ''"></i>
                </button>
                <div class="ws-track-body" x-show="trackOpen" x-collapse>
                    <div class="ws-track-form">
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Número de pedido', 'workshop' ); ?></span>
                            <input type="text" x-model="trackNumber" placeholder="WS-XXXXXXX" @keyup.enter="trackStatus()">
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Teléfono', 'workshop' ); ?></span>
                            <input type="tel" x-model="trackPhone" placeholder="+58 412 123 4567" @keyup.enter="trackStatus()">
                        </label>
                        <button class="ws-btn ws-btn-primary" @click="trackStatus()" :disabled="trackBusy">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <span x-text="trackBusy ? 'Consultando…' : 'Consultar'"></span>
                        </button>
                    </div>
                    <p class="ws-track-error" x-show="trackError" x-text="trackError"></p>
                    <div class="ws-track-result" x-show="trackResult" x-cloak>
                        <template x-if="trackResult">
                            <div>
                                <div class="ws-track-result-head">
                                    <span x-text="trackResult.number"></span>
                                    <span class="ws-badge" :class="'ws-badge-' + trackResult.status" x-text="trackResult.status_label"></span>
                                </div>
                                <p class="ws-muted" x-text="'Fecha: ' + trackResult.date"></p>
                                <table class="ws-table">
                                    <thead><tr><th><?php esc_html_e( 'Producto', 'workshop' ); ?></th><th>Cant.</th><th><?php esc_html_e( 'Precio', 'workshop' ); ?></th></tr></thead>
                                    <tbody>
                                        <template x-for="it in trackResult.items" :key="it.product_name">
                                            <tr><td x-text="it.product_name"></td><td x-text="it.qty"></td><td x-text="moneyOf(it.price * it.qty, trackResult.currency)"></td></tr>
                                        </template>
                                    </tbody>
                                </table>
                                <div class="ws-summary-total">
                                    <div class="ws-total"><span><?php esc_html_e( 'Total', 'workshop' ); ?></span><strong x-text="moneyOf(trackResult.total, trackResult.currency)"></strong></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Valoraciones de la tienda: las estrellas valoran al NEGOCIO,
                 no a los productos individuales. -->
            <div class="ws-store-reviews ws-card" id="ws-store-reviews">
                <div class="ws-store-reviews-head">
                    <h3><i class="fa-solid fa-star"></i> <?php esc_html_e( 'Valoraciones de la tienda', 'workshop' ); ?></h3>
                    <span class="ws-store-rating-num" x-text="storeRating ? storeRating.toFixed(1) + '/5' : '—'"></span>
                </div>
                <p class="ws-muted ws-store-reviews-sub"><?php esc_html_e( 'Comparte tu experiencia comprando aquí: las estrellas valoran a esta tienda.', 'workshop' ); ?></p>

                <div class="ws-reviews-summary">
                    <div class="ws-rating-stars ws-rating-stars-lg">
                        <template x-for="i in 5" :key="i">
                            <i class="fa-solid fa-star" :class="i <= Math.round(storeRating) ? 'ws-star-filled' : 'ws-star-empty'"></i>
                        </template>
                    </div>
                    <span x-text="(storeReviews || []).length ? '(' + (storeReviews || []).length + ' reseñas)' : '(Aún sin reseñas)'"></span>
                </div>

                <div class="ws-reviews-list">
                    <template x-if="!(storeReviews || []).length && !reviewBusy">
                        <p class="ws-muted"><?php esc_html_e( 'Sé el primero en valorar esta tienda.', 'workshop' ); ?></p>
                    </template>
                    <template x-for="review in (storeReviews || []).slice(0, 6)" :key="review.id">
                        <div class="ws-review-item">
                            <div class="ws-review-rating">
                                <template x-for="i in 5" :key="i">
                                    <i class="fa-solid fa-star" :class="i <= review.rating ? 'ws-star-filled' : 'ws-star-empty'"></i>
                                </template>
                            </div>
                            <p x-text="review.comment"></p>
                            <small><i class="fa-solid fa-user"></i> <span x-text="review.customer_name"></span> <span class="ws-muted" x-text="review.created_at ? '· ' + formatReviewDate(review.created_at) : ''"></span></small>
                        </div>
                    </template>
                </div>

                <button class="ws-btn ws-btn-ghost ws-btn-sm" @click="showStoreReviewForm = true" x-show="!showStoreReviewForm && !reviewSubmitted && !alreadyReviewed">
                    <i class="fa-solid fa-pen"></i> <?php esc_html_e( 'Escribir reseña', 'workshop' ); ?>
                </button>
                <p class="ws-muted ws-store-review-thanks" x-show="reviewSubmitted"><i class="fa-solid fa-circle-check ws-text-success"></i> <?php esc_html_e( '¡Gracias! Tu reseña se publicará tras una breve revisión.', 'workshop' ); ?></p>
                <p class="ws-muted ws-store-review-thanks" x-show="alreadyReviewed && !reviewSubmitted"><i class="fa-solid fa-circle-info"></i> <?php esc_html_e( 'Ya enviaste una reseña para esta tienda. Solo se permite una por persona.', 'workshop' ); ?></p>

                <!-- Formulario de reseña de la tienda -->
                <div class="ws-review-form" x-show="showStoreReviewForm" x-cloak>
                    <div class="ws-field">
                        <label><?php esc_html_e( 'Tu nombre', 'workshop' ); ?></label>
                        <input type="text" x-model="reviewForm.customer_name" placeholder="<?php esc_attr_e( 'Nombre', 'workshop' ); ?>">
                    </div>
                    <div class="ws-field">
                        <label><?php esc_html_e( 'Valoración', 'workshop' ); ?></label>
                        <div class="ws-rating-input">
                            <template x-for="i in 5" :key="i">
                                <i class="fa-solid fa-star"
                                   :class="i <= reviewForm.rating ? 'ws-star-filled' : 'ws-star-empty'"
                                   @click="reviewForm.rating = i"
                                   style="cursor: pointer;"></i>
                            </template>
                        </div>
                    </div>
                    <div class="ws-field">
                        <label><?php esc_html_e( 'Comentario', 'workshop' ); ?></label>
                        <textarea x-model="reviewForm.comment" rows="3" placeholder="<?php esc_attr_e( 'Comparte tu experiencia...', 'workshop' ); ?>"></textarea>
                    </div>
                    <div class="ws-review-form-actions">
                        <button class="ws-btn ws-btn-primary ws-btn-sm" @click="submitReview()" :disabled="reviewBusy">
                            <i class="fa-solid fa-paper-plane"></i> <?php esc_html_e( 'Enviar reseña', 'workshop' ); ?>
                        </button>
                        <button class="ws-btn ws-btn-ghost ws-btn-sm" @click="showStoreReviewForm = false">
                            <?php esc_html_e( 'Cancelar', 'workshop' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- FAB carrito: anclado abajo a la derecha al hacer scroll -->
    <button class="ws-cart-fab" @click="openCart()" x-show="fabVisible" x-transition.opacity.duration.200ms aria-label="<?php esc_attr_e( 'Abrir pedido', 'workshop' ); ?>">
        <i class="fa-solid fa-cart-shopping"></i>
        <span class="ws-cart-fab-count" x-show="cartCount > 0" x-text="cartCount"></span>
    </button>

    <!-- Carrito: modal fijo centrado -->
    <div class="ws-cart-overlay" x-show="cartOpen" @click.self="cartOpen = false" x-cloak>
        <div class="ws-cart" x-show="cartOpen" x-transition.scale.origin.top @keydown.escape.window="cartOpen = false">

            <!-- Paso 1: revisar carrito -->
            <div x-show="step === 'cart'" class="ws-cart-step">
                <div class="ws-cart-head">
                    <h3><i class="fa-solid fa-cart-shopping"></i> <?php esc_html_e( 'Tu pedido', 'workshop' ); ?></h3>
                    <button class="ws-cart-close" @click="cartOpen = false"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="ws-cart-body">
                    <template x-if="cartItems.length === 0">
                        <p class="ws-empty"><?php esc_html_e( 'Tu carrito está vacío.', 'workshop' ); ?></p>
                    </template>
                    <template x-for="item in cartItems" :key="item.product_id">
                        <div class="ws-cart-item">
                            <div>
                                <strong x-text="item.name"></strong>
                                <div class="ws-qty">
                                    <button @click="changeQty(item, -1)"><i class="fa-solid fa-minus"></i></button>
                                    <input type="number" min="1" :max="stockOf(item.product_id)" class="ws-qty-input"
                                           :value="item.qty" @change="setQty(item, $event.target.value)">
                                    <button @click="changeQty(item, 1)"><i class="fa-solid fa-plus"></i></button>
                                </div>
                            </div>
                            <div class="ws-cart-item-right">
                                <span x-text="moneyOf(item.price * item.qty, item.currency)"></span>
                                <button class="ws-cart-remove" @click="removeItem(item.product_id)"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        </div>
                    </template>
                </div>                <div class="ws-cart-foot">
                    <div class="ws-cart-totals">
                        <div><span><?php esc_html_e( 'Subtotal', 'workshop' ); ?></span><strong x-text="price(subtotal)"></strong></div>
                        <div><span><?php esc_html_e( 'Domicilio', 'workshop' ); ?></span><strong x-text="price(delivery)"></strong></div>
                        <div class="ws-total"><span><?php esc_html_e( 'Total en efectivo', 'workshop' ); ?></span><strong x-text="price(total)"></strong></div>
                        <div class="ws-total ws-total-transfer"><span><?php esc_html_e( 'Total en transferencia', 'workshop' ); ?></span><strong x-text="price(totalTransfer)"></strong></div>
                    </div>
                    <button class="ws-btn ws-btn-primary ws-btn-block ws-cart-next" @click="goCheckout()" :disabled="cartItems.length === 0">
                        <i class="fa-solid fa-circle-check"></i> <?php esc_html_e( 'Realizar pedido', 'workshop' ); ?>
                    </button>
                </div>
            </div>

            <!-- Paso 2: datos del cliente -->
            <div x-show="step === 'data'" class="ws-cart-step">
                <div class="ws-cart-head">
                    <h3><i class="fa-solid fa-user"></i> <?php esc_html_e( 'Tus datos', 'workshop' ); ?></h3>
                    <button class="ws-cart-close" @click="cartOpen = false"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="ws-cart-body">
                    <div class="ws-cart-summary">
                        <template x-for="item in cartItems" :key="item.product_id">
                            <div class="ws-cart-summary-row">
                                <span x-text="item.name"></span>
                                <span class="ws-cart-summary-meta"><strong x-text="item.qty + ' × ' + moneyOf(item.price, item.currency)"></strong></span>
                            </div>
                        </template>
                        <div class="ws-cart-totals">
                            <div><span><?php esc_html_e( 'Subtotal', 'workshop' ); ?></span><strong x-text="price(subtotal)"></strong></div>
                            <div><span><?php esc_html_e( 'Domicilio', 'workshop' ); ?></span><strong x-text="price(delivery)"></strong></div>
                            <div class="ws-total"><span><?php esc_html_e( 'Total en efectivo', 'workshop' ); ?></span><strong x-text="price(total)"></strong></div>
                            <div class="ws-total ws-total-transfer"><span><?php esc_html_e( 'Total en transferencia', 'workshop' ); ?></span><strong x-text="price(totalTransfer)"></strong></div>
                        </div>
                    </div>

                    <form @submit.prevent="checkout" class="ws-checkout-form">
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Nombre', 'workshop' ); ?></span>
                            <input type="text" x-model="customer.name" required>
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Teléfono', 'workshop' ); ?></span>
                            <input type="tel" x-model="customer.phone" required>
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Dirección', 'workshop' ); ?></span>
                            <input type="text" x-model="customer.address">
                        </label>
                        <label class="ws-field" x-show="whatsappNumbers.length > 1">
                            <span><?php esc_html_e( 'Número que atiende tu pedido', 'workshop' ); ?></span>
                            <select x-model="customer.number">
                                <option value="">— <?php esc_html_e( 'Seleccionar', 'workshop' ); ?> —</option>
                                <template x-for="n in whatsappNumbers" :key="n"><option :value="n" x-text="n"></option></template>
                            </select>
                        </label>

                        <button class="ws-btn ws-btn-success ws-btn-block" type="submit" :disabled="busy">
                            <i class="fa-solid fa-circle-check"></i> <span x-text="busy ? 'Enviando…' : 'Confirmar pedido'"></span>
                        </button>
                        <button type="button" class="ws-btn ws-btn-ghost ws-btn-block" @click="backToCart()" :disabled="busy">
                            <i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Volver al carrito', 'workshop' ); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de producto: fuera del overlay del carrito para que se pueda
         abrir desde la tarjeta sin abrir el carrito. -->
    <div class="ws-modal ws-store-modal" x-show="productOpen" x-cloak @click.self="closeProduct()" @keydown.escape.window="closeProduct()">
        <div class="ws-modal-backdrop" @click="closeProduct()"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <div class="ws-store-modal-title">
                    <h3 x-text="activeProduct ? activeProduct.name : ''"></h3>
                    <span class="ws-combo-badge" x-show="activeProduct && activeProduct.is_combo" title="<?php esc_attr_e( 'Este producto es un combo', 'workshop' ); ?>"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                </div>
                <button class="ws-cart-close" @click="closeProduct()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <template x-if="activeProduct">
                <div class="ws-store-modal-body">
                    <div class="ws-store-modal-img">
                        <img x-show="activeProduct.image" :src="activeProduct.image" :alt="activeProduct.name">
                        <i x-show="!activeProduct.image" class="fa-solid fa-box-open"></i>
                    </div>
                    <div class="ws-store-modal-info">
                        <p class="ws-product-barcode" x-text="activeProduct.barcode"></p>
                        <p class="ws-product-desc" x-show="activeProduct.description" x-text="activeProduct.description"></p>
                        <div class="ws-combo-items ws-store-combo-items" x-show="activeProduct.is_combo && (activeProduct.combo_items || []).length">
                            <span class="ws-combo-items-label"><i class="fa-solid fa-cubes"></i> <?php esc_html_e( 'Contiene', 'workshop' ); ?></span>
                            <template x-for="it in (activeProduct.combo_items || [])" :key="it.product_id">
                                <span class="ws-combo-chip"><i class="fa-solid fa-box"></i><span x-text="it.name"></span><b x-text="'×' + it.qty"></b></span>
                            </template>
                        </div>
                        <div class="ws-product-row">
                            <span class="ws-price ws-price-lg" x-text="priceInfo(activeProduct).main"></span>
                            <span class="ws-price-equiv" x-show="priceInfo(activeProduct).equiv" x-text="priceInfo(activeProduct).equiv"></span>
                            <span class="ws-price-transfer" x-show="activeProduct.transfer_pct > 0" x-text="transferLine(activeProduct)"></span>
                        </div>
                        <p class="ws-stock-badge" :class="activeProduct.qty > 0 ? 'ws-text-success' : 'ws-text-danger'" x-text="stockLabel(activeProduct)"></p>
                        <div class="ws-store-modal-qty">
                            <span><?php esc_html_e( 'Cantidad', 'workshop' ); ?></span>
                            <div class="ws-qty">
                                <button @click="setModalQty(modalQty - 1)"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" min="1" :max="activeProduct.qty || 1" class="ws-qty-input" x-model.number="modalQty" @change="setModalQty($event.target.value)">
                                <button @click="setModalQty(modalQty + 1)"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>
                        <button class="ws-btn ws-btn-primary ws-btn-block" :disabled="activeProduct.qty <= 0" @click="addFromModal()">
                            <i class="fa-solid fa-cart-plus"></i> <?php esc_html_e( 'Añadir al pedido', 'workshop' ); ?>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<?php
// Datos iniciales del store para Alpine.
?>
<script>
window.WS_STORE_DATA = <?php echo wp_json_encode( array(
    'location' => array(
        'id'     => (int) $location->id,
        'name'   => $location->name,
        'currency' => $store_currency,
    ),
    'rates'        => $ws_store_rates,
    'baseCurrency' => $ws_store_base,
    'currencies'   => $ws_store_currs,
    'displayCurrency' => $store_display_currency,
    'whatsappNumbers' => $ws_store_was,
    'categories' => $ws_store_categories,
    'products' => array_map( function ( $r ) {
        // Combos (filas de WS_Combos::catalog_rows) llegan como ARRAYS;
        // los productos (WS_Stock::stock_rows) como objetos stdClass.
        if ( is_array( $r ) && ! empty( $r['is_combo'] ) ) {
            return array(
                'id'           => (int) $r['id'],
                'combo_id'     => (int) $r['combo_id'],
                'name'         => $r['name'],
                'barcode'      => '',
                'image'        => ! empty( $r['photo'] ) ? ws_image_url( $r['photo'] ) : '',
                'description'  => '',
                'category_id'  => 0,
                'category'     => __( 'Combo', 'workshop' ),
                'price'        => (float) $r['price'],
                'transfer_pct' => 0,
                'currency'     => $r['currency'],
                'show_equiv'   => 0,
                'qty'          => (float) $r['qty'],
                'is_combo'     => 1,
                'combo_items'  => $r['items'],
            );
        }
        $p = (object) $r;
        return array(
            'id'           => (int) $p->product_id,
            'combo_id'     => 0,
            'name'         => $p->name,
            'barcode'      => $p->barcode,
            'image'        => $p->image ? ws_image_url( $p->image ) : '',
            'description'  => $p->description ?? '',
            'category_id'  => (int) ( $p->category_id ?? 0 ),
            'category'     => (string) ( $p->category ?? '' ),
            'price'        => (float) $p->sale_price,
            'transfer_pct' => (float) $p->transfer_pct,
            'currency'     => $p->currency,
            'show_equiv'   => (int) ( $p->show_equiv ?? 1 ),
            'qty'          => (float) $p->qty,
            'is_combo'     => 0,
            'combo_items'  => array(),
        );
    }, $products ),
) ); ?>;
</script>
<?php get_footer(); ?>
