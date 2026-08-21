<?php
/**
 * Módulo POS (Punto de Venta).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$can_sell = ws_can( 'pos_sell' );
$can_cash = ws_can( 'pos_cash_view' );
$can_count = ws_can( 'stock_count_view' );

// Estado de la suscripción: aviso palpable cuando la prueba o el plan están a
// punto de vencer (las ventas se cortan en cuanto vence).
$sub_data = ws_subscription_data();
$sub_banner = '';
if ( ! empty( $sub_data['is_trial'] ) && $sub_data['trial_days_left'] > 0 && $sub_data['trial_days_left'] <= 3 ) {
    $sub_banner = array(
        'type'  => 'warning',
        'title' => sprintf(
            _n( 'Tu prueba gratis vence en %d día', 'Tu prueba gratis vence en %d días', $sub_data['trial_days_left'], 'workshop' ),
            $sub_data['trial_days_left']
        ),
        'text'  => __( 'Al vencer la prueba, el negocio queda en pausa y no podrás registrar ventas. Elige un plan para continuar.', 'workshop' ),
    );
} elseif ( ! empty( $sub_data['is_active'] ) && $sub_data['plan_days_left'] > 0 && $sub_data['plan_days_left'] <= 3 ) {
    $sub_banner = array(
        'type'  => 'warning',
        'title' => sprintf(
            _n( 'Tu plan vence en %d día', 'Tu plan vence en %d días', $sub_data['plan_days_left'], 'workshop' ),
            $sub_data['plan_days_left']
        ),
        'text'  => __( 'Al vencer el plan, el negocio queda en pausa y no podrás registrar ventas. Renueva o solicita un upgrade para continuar.', 'workshop' ),
    );
} elseif ( ! empty( $sub_data['upgrade_pending'] ) ) {
    $sub_banner = array(
        'type'  => 'info',
        'title' => __( 'Tienes una solicitud de plan pendiente', 'workshop' ),
        'text'  => __( 'El administrador la revisará y habilitará tu negocio cuando la apruebe.', 'workshop' ),
    );
}
?>

<div class="ws-module-pos" x-data="wsPOS()">
    <?php if ( $sub_banner ) : ?>
    <div class="ws-alert ws-alert-<?php echo 'info' === $sub_banner['type'] ? 'info' : 'warning'; ?> ws-pos-sub-banner">
        <div class="ws-pos-sub-banner-head">
            <i class="fa-solid <?php echo 'info' === $sub_banner['type'] ? 'fa-hourglass-half' : 'fa-triangle-exclamation'; ?>"></i>
            <strong><?php echo esc_html( $sub_banner['title'] ); ?></strong>
            <a class="ws-link" href="<?php echo esc_url( ws_panel_url( 'owner', 'plan' ) ); ?>"><?php esc_html_e( 'Ver plan', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <p class="ws-pos-sub-banner-text"><?php echo esc_html( $sub_banner['text'] ); ?></p>
    </div>
    <?php endif; ?>
    <div class="ws-pos-layout">
        <!-- Panel de productos -->
        <div class="ws-pos-products">
            <div class="ws-pos-header">
                <h2><?php esc_html_e( 'Punto de Venta', 'workshop' ); ?></h2>
                <div class="ws-pos-header-actions">
                    <button class="ws-pos-frozen-btn" @click="showFrozenModal = true" title="<?php esc_attr_e( 'Pedidos congelados', 'workshop' ); ?>">
                        <i class="fa-solid fa-snowflake"></i>
                        <span><?php esc_html_e( 'Congelados', 'workshop' ); ?></span>
                        <span class="ws-cart-count" x-show="frozenOrders.length > 0" x-text="frozenOrders.length"></span>
                    </button>
                    <?php if ( $can_cash ) : ?><button class="ws-pos-cash-btn" :class="cashOpen ? 'ws-cash-open' : 'ws-cash-closed'" @click="openCashModal()" title="<?php esc_attr_e( 'Abrir / cerrar caja', 'workshop' ); ?>">
                        <i class="fa-solid fa-cash-register"></i>
                        <span x-text="cashOpen ? '<?php esc_html_e( 'Caja abierta', 'workshop' ); ?>' : '<?php esc_html_e( 'Caja cerrada', 'workshop' ); ?>'"></span>
                    </button><?php endif; ?>
                    <div class="ws-pos-location" title="<?php esc_attr_e( 'Cambiar ubicación', 'workshop' ); ?>">
                        <select x-model="currentLocationId" @change="changeLocation()" :disabled="locations.length <= 1" x-cloak>
                            <template x-for="loc in locations" :key="loc.id">
                                <option :value="loc.id" x-text="loc.name"></option>
                            </template>
                        </select>
                    </div>
                </div>
            </div>

            <div class="ws-pos-search">
                <div class="ws-search-box">
                    <i class="fa-solid fa-search"></i>
                    <input type="text"
                           x-model="searchQuery"
                           placeholder="<?php esc_attr_e( 'Buscar producto o escanear código...', 'workshop' ); ?>"
                           @keydown.enter="searchByBarcode()">
                </div>
            </div>

            <div class="ws-products-scroll">
                <template x-if="loadingProducts">
                    <div class="ws-loading">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        <?php esc_html_e( 'Cargando productos...', 'workshop' ); ?>
                    </div>
                </template>
                <template x-if="!loadingProducts && filteredProducts.length === 0">
                    <div class="ws-empty">
                        <?php esc_html_e( 'No hay productos disponibles', 'workshop' ); ?>
                    </div>
                </template>
                <template x-if="!loadingProducts && filteredProducts.length > 0">
                    <div>
                        <!-- Combos agrupados en su propia sección, con sus componentes -->
                        <div class="ws-pos-section" x-show="posCombos.length > 0" x-cloak>
                            <h4 class="ws-pos-section-title"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combos', 'workshop' ); ?> <small class="ws-muted" x-text="'(' + posCombos.length + ')'"></small></h4>
                            <div class="ws-products-grid">
                                <template x-for="product in posCombos" :key="'c' + product.id">
                                    <div class="ws-product-card ws-product-combo" @click="addToCart(product)" :class="{ 'is-out': product.stock <= 0 }">
                                        <div class="ws-product-image">
                                            <img :src="product.image || '<?php echo WS_URL; ?>assets/images/placeholder.png'" :alt="product.name" loading="lazy">
                                        </div>
                                        <div class="ws-product-info">
                                            <div class="ws-product-name-row">
                                                <div class="ws-product-name" x-text="product.name"></div>
                                                <span class="ws-combo-badge" title="<?php esc_attr_e( 'Este producto es un combo', 'workshop' ); ?>"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                                            </div>
                                            <div class="ws-product-price" x-text="formatPrice(product.sale_price)"></div>
                                            <div class="ws-product-stock" :class="product.stock > 0 ? 'ws-stock-ok' : 'ws-stock-low'">
                                                <i class="fa-solid fa-box"></i>
                                                <span x-text="product.stock"></span>
                                            </div>
                                            <!-- Ojo: abre el modal con TODOS los componentes del combo
                                                 (el card queda compacto: solo nombre, precio y stock). -->
                                            <button type="button" class="ws-pos-combo-view" @click.stop="openComboDetail(product)" :disabled="product.stock <= 0" title="<?php esc_attr_e( 'Ver contenido del combo', 'workshop' ); ?>">
                                                <i class="fa-solid fa-eye"></i> <?php esc_html_e( 'Ver combo', 'workshop' ); ?>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <!-- Productos sueltos -->
                        <div class="ws-pos-section" x-show="posProducts.length > 0" x-cloak>
                            <h4 class="ws-pos-section-title"><i class="fa-solid fa-box"></i> <?php esc_html_e( 'Productos', 'workshop' ); ?> <small class="ws-muted" x-text="'(' + posProducts.length + ')'"></small></h4>
                            <div class="ws-products-grid">
                                <template x-for="product in posProducts" :key="'p' + product.id">
                                    <div class="ws-product-card" @click="addToCart(product)" :class="{ 'is-out': product.stock <= 0 }">
                                        <div class="ws-product-image">
                                            <img :src="product.image || '<?php echo WS_URL; ?>assets/images/placeholder.png'" :alt="product.name" loading="lazy">
                                        </div>
                                        <div class="ws-product-info">
                                            <div class="ws-product-name-row">
                                                <div class="ws-product-name" x-text="product.name"></div>
                                                <span class="ws-combo-badge" x-show="product.is_combo" title="<?php esc_attr_e( 'Este producto es un combo', 'workshop' ); ?>"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                                            </div>
                                            <div class="ws-product-price" x-text="formatPrice(product.sale_price)"></div>
                                            <div class="ws-product-stock" :class="product.stock > 0 ? 'ws-stock-ok' : 'ws-stock-low'">
                                                <i class="fa-solid fa-box"></i>
                                                <span x-text="product.stock"></span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Panel de carrito -->
        <div class="ws-pos-cart" :class="{ open: cartOpen }" @keydown.escape.window="cartOpen = false">
            <div class="ws-cart-header">
                <h3><i class="fa-solid fa-shopping-cart"></i><?php esc_html_e( 'Carrito', 'workshop' ); ?></h3>
                <div class="ws-cart-header-actions">
                    <button class="ws-btn-icon ws-pos-freeze-btn" @click="freezeCart()" :disabled="cart.length === 0" title="<?php esc_attr_e( 'Congelar pedido', 'workshop' ); ?>">
                        <i class="fa-solid fa-snowflake"></i>
                    </button>
                    <button class="ws-btn-icon" @click="openCustomerModal()" title="<?php esc_attr_e( 'Seleccionar cliente', 'workshop' ); ?>">
                        <i class="fa-solid fa-user"></i>
                    </button>
                    <button class="ws-btn-icon" @click="clearCart()" title="<?php esc_attr_e( 'Limpiar carrito', 'workshop' ); ?>">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                    <button class="ws-btn-icon" @click="cartOpen = false" title="<?php esc_attr_e( 'Cerrar carrito', 'workshop' ); ?>" aria-label="<?php esc_attr_e( 'Cerrar carrito', 'workshop' ); ?>">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="ws-cart-items">
                <template x-if="cart.length === 0">
                    <div class="ws-cart-empty">
                        <i class="fa-solid fa-shopping-cart"></i>
                        <p><?php esc_html_e( 'Carrito vacío', 'workshop' ); ?></p>
                    </div>
                </template>
                <template x-for="item in cart" :key="item.product_id">
                    <div class="ws-cart-item">
                        <div class="ws-item-info">
                            <div class="ws-item-name" x-text="item.product_name"></div>
                            <span class="ws-combo-badge" x-show="item.combo_id" x-cloak title="<?php esc_attr_e( 'Este producto es un combo', 'workshop' ); ?>"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                            <div class="ws-item-price" x-text="formatPrice(item.price)"></div>
                        </div>
                        <div class="ws-item-qty">
                            <button @click="updateQty(item.product_id, item.qty - 1)">-</button>
                            <input type="number" min="1" step="1" class="ws-qty-input"
                                   :max="item.stock"
                                   x-model.number="item.qty"
                                   @change="clampQty(item.product_id)"
                                   @keydown.enter.prevent="$event.target.blur()"
                                   @focus="$event.target.select()">
                            <button @click="updateQty(item.product_id, item.qty + 1)">+</button>
                        </div>
                        <div class="ws-item-total" x-text="formatPrice(item.qty * item.price)"></div>
                        <button class="ws-btn-icon ws-btn-danger" @click="removeFromCart(item.product_id)" title="<?php esc_attr_e( 'Quitar', 'workshop' ); ?>">
                            <i class="fa-solid fa-times"></i>
                        </button>
                    </div>
                </template>
            </div>

            <div class="ws-cart-footer">
                <div class="ws-pos-customer" x-show="customer" x-cloak>
                    <div class="ws-cust-info">
                        <i class="fa-solid fa-user-check"></i>
                        <span x-text="customer?.name"></span>
                    </div>
                    <button class="ws-cust-clear" @click="customer = null" title="<?php esc_attr_e( 'Quitar cliente', 'workshop' ); ?>">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="ws-cart-summary">
                    <div class="ws-summary-row">
                        <span><?php esc_html_e( 'Subtotal', 'workshop' ); ?></span>
                        <span x-text="formatPrice(subtotal)"></span>
                    </div>
                    <div class="ws-summary-row">
                        <span><?php esc_html_e( 'Descuento', 'workshop' ); ?></span>
                        <input type="number" x-model.number="discount" @input="calculateTotal()" min="0" step="0.01">
                    </div>
                    <div class="ws-summary-row ws-summary-total">
                        <span><?php esc_html_e( 'Total', 'workshop' ); ?></span>
                        <span x-text="formatPrice(total)"></span>
                    </div>
                </div>

                <div class="ws-pay-methods">
                    <button type="button" class="ws-pay-tab" :class="{ 'is-active': paymentMode === 'cash' }" @click="setPaymentMode('cash')">
                        <i class="fa-solid fa-money-bill"></i><?php esc_html_e( 'Efectivo', 'workshop' ); ?>
                    </button>
                    <button type="button" class="ws-pay-tab" :class="{ 'is-active': paymentMode === 'transfer' }" @click="setPaymentMode('transfer')">
                        <i class="fa-solid fa-building-columns"></i><?php esc_html_e( 'Transfer.', 'workshop' ); ?>
                    </button>
                    <button type="button" class="ws-pay-tab" :class="{ 'is-active': paymentMode === 'both' }" @click="setPaymentMode('both')">
                        <i class="fa-solid fa-money-bill-transfer"></i><?php esc_html_e( 'Ambos', 'workshop' ); ?>
                    </button>
                    <button type="button" class="ws-pay-tab" :class="{ 'is-active': paymentMode === 'card' }" @click="setPaymentMode('card')">
                        <i class="fa-solid fa-credit-card"></i><?php esc_html_e( 'Tarjeta', 'workshop' ); ?>
                    </button>
                </div>

                <!-- Efectivo: monto recibido + vuelto -->
                <div class="ws-pay-panel" x-show="paymentMode === 'cash' || paymentMode === 'both'" x-cloak x-transition>
                    <div class="ws-pay-field">
                        <label><?php esc_html_e( 'Monto recibido en efectivo', 'workshop' ); ?></label>
                        <input type="number" min="0" step="0.01" x-model.number="cashAmount" :placeholder="formatPrice(total)">
                    </div>
                    <div class="ws-change-box" :class="changeDue >= 0 ? 'ws-change-ok' : 'ws-change-missing'">
                        <span><?php esc_html_e( 'Vuelto', 'workshop' ); ?></span>
                        <strong x-text="formatPrice(Math.max(changeDue, 0))"></strong>
                    </div>
                    <div class="ws-pay-hint" x-show="changeDue < 0" x-cloak>
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span x-text="'<?php esc_attr_e( 'Faltan', 'workshop' ); ?> ' + formatPrice(-changeDue)"></span>
                    </div>
                </div>

                <!-- Transferencia: datos obligatorios del cliente -->
                <div class="ws-pay-panel" x-show="paymentMode === 'transfer' || paymentMode === 'both'" x-cloak x-transition>
                    <div class="ws-pay-grid">
                        <div class="ws-pay-field">
                            <label><?php esc_html_e( 'Nombre', 'workshop' ); ?> <span class="ws-req">*</span></label>
                            <input type="text" x-model="payCustomerName" :placeholder="customer ? customer.name : '<?php esc_attr_e( 'Nombre del cliente', 'workshop' ); ?>'">
                        </div>
                        <div class="ws-pay-field">
                            <label><?php esc_html_e( 'Carnet / Cédula', 'workshop' ); ?> <span class="ws-req">*</span></label>
                            <input type="text" x-model="payCustomerDoc" placeholder="V-12345678">
                        </div>
                        <div class="ws-pay-field">
                            <label><?php esc_html_e( 'Teléfono', 'workshop' ); ?> <span class="ws-req">*</span></label>
                            <input type="tel" x-model="payCustomerPhone" :placeholder="customer ? customer.phone : '+58 412 123 4567'">
                        </div>
                        <div class="ws-pay-field">
                            <label><?php esc_html_e( 'Nº de transferencia', 'workshop' ); ?> <span class="ws-req">*</span></label>
                            <input type="text" x-model="transferNumber" placeholder="REF-000000">
                        </div>
                    </div>
                    <div class="ws-pay-field" x-show="paymentMode === 'both'" x-cloak>
                        <label><?php esc_html_e( 'Monto transferido', 'workshop' ); ?></label>
                        <input type="number" min="0" step="0.01" x-model.number="transferAmount" :placeholder="formatPrice(total)">
                    </div>
                </div>

                <!-- Validación pago mixto (efectivo + transferencia = total) -->
                <div class="ws-pay-status" x-show="paymentMode === 'both' && cart.length > 0" x-cloak :class="bothStatusClass">
                    <i :class="bothStatusIcon"></i>
                    <span x-text="bothStatusText"></span>
                </div>

                <div class="ws-cart-actions">
                    <button class="ws-btn ws-btn-secondary ws-btn-full" @click="openCustomerModal()">
                        <i class="fa-solid fa-user"></i>
                        <span x-text="customer ? customer.name : '<?php esc_html_e( 'Seleccionar cliente', 'workshop' ); ?>'"></span>
                    </button>
                    <button class="ws-btn ws-btn-primary ws-btn-full" @click="completeSale()" :disabled="cart.length === 0">
                        <i class="fa-solid fa-check"></i>
                        <?php esc_html_e( 'Completar Venta', 'workshop' ); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Botón flotante del carrito (siempre visible; se agrupa con el chat en
         los tres puntos cuando hay más de un FAB, igual que el carrito de la
         tienda). En móvil abre el drawer con backdrop; en desktop hace scroll
         hasta el carrito (que está visible como columna). -->
    <button class="ws-pos-cart-toggle" @click="toggleCart()" aria-label="<?php esc_attr_e( 'Abrir carrito', 'workshop' ); ?>">
        <i class="fa-solid fa-shopping-cart"></i>
        <span class="ws-cart-count" x-show="cart.length > 0" x-text="cartCount"></span>
    </button>
    <div class="ws-pos-backdrop" :class="{ show: cartOpen }" @click="cartOpen = false"></div>

    <!-- Modal de cliente -->
    <div class="ws-modal" x-show="showCustomerModal" x-cloak x-transition @keydown.escape.window="showCustomerModal = false">
        <div class="ws-modal-content" @click.away="showCustomerModal = false">
            <div class="ws-modal-header">
                <h3><?php esc_html_e( 'Seleccionar Cliente', 'workshop' ); ?></h3>
                <button class="ws-modal-close" @click="showCustomerModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <div class="ws-search-box">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" x-model="customerSearch" placeholder="<?php esc_attr_e( 'Buscar cliente...', 'workshop' ); ?>">
                </div>
                <div class="ws-customers-list">
                    <template x-for="customer in filteredCustomers" :key="customer.id">
                        <div class="ws-customer-option" @click="selectCustomer(customer)">
                            <div class="ws-customer-name" x-text="customer.name"></div>
                            <div class="ws-customer-email" x-text="customer.phone || customer.email || ''"></div>
                        </div>
                    </template>
                    <template x-if="filteredCustomers.length === 0">
                        <div class="ws-empty"><?php esc_html_e( 'Sin clientes encontrados', 'workshop' ); ?></div>
                    </template>
                </div>
                <button class="ws-btn ws-btn-secondary ws-btn-full" @click="customer = null; showCustomerModal = false">
                    <?php esc_html_e( 'Venta sin cliente', 'workshop' ); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de caja (apertura / cierre) -->
    <div class="ws-modal" x-show="showCashModal" x-cloak x-transition @keydown.escape.window="showCashModal = false">
        <div class="ws-modal-content ws-modal-cash" @click.away="showCashModal = false">
            <div class="ws-modal-header">
                <h3 x-text="cashOpen ? '<?php esc_html_e( 'Cerrar caja', 'workshop' ); ?>' : '<?php esc_html_e( 'Abrir caja', 'workshop' ); ?>'"></h3>
                <button class="ws-modal-close" @click="showCashModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <template x-if="!cashOpen">
                    <div class="ws-cash-form">
                        <div class="ws-pay-field">
                            <label><?php esc_html_e( 'Monto inicial en caja', 'workshop' ); ?></label>
                            <input type="number" x-model.number="cashOpeningAmount" min="0" step="0.01" :placeholder="formatPrice(0)">
                        </div>
                        <div class="ws-pay-field">
                            <label><?php esc_html_e( 'Nota (opcional)', 'workshop' ); ?></label>
                            <input type="text" x-model="cashOpeningNote" placeholder="<?php esc_attr_e( 'Fondo inicial, observaciones...', 'workshop' ); ?>">
                        </div>
                        <button class="ws-btn ws-btn-primary ws-btn-full" @click="openCash()" :disabled="cashSaving">
                            <i class="fa-solid fa-cash-register"></i>
                            <?php esc_html_e( 'Abrir caja', 'workshop' ); ?>
                        </button>
                    </div>
                </template>
                <template x-if="cashOpen">
                    <div class="ws-cash-form">
                        <div class="ws-cash-info">
                            <div><span><?php esc_html_e( 'Abierta por', 'workshop' ); ?>:</span> <b x-text="cashInfo?.seller_name || '-'"></b></div>
                            <div><span><?php esc_html_e( 'Apertura', 'workshop' ); ?>:</span> <b x-text="formatPrice(cashInfo?.opening_amount)"></b></div>
                            <div><span><?php esc_html_e( 'Inicio', 'workshop' ); ?>:</span> <b x-text="cashInfo?.opened_at"></b></div>
                        </div>
                        <div class="ws-pay-field">
                            <label><?php esc_html_e( 'Monto final en caja', 'workshop' ); ?></label>
                            <input type="number" x-model.number="cashClosingAmount" min="0" step="0.01" :placeholder="formatPrice(0)">
                        </div>
                        <div class="ws-pay-field">
                            <label><?php esc_html_e( 'Nota de cierre (opcional)', 'workshop' ); ?></label>
                            <input type="text" x-model="cashClosingNote" placeholder="<?php esc_attr_e( 'Arqueo, incidencias...', 'workshop' ); ?>">
                        </div>

                        <?php if ( $can_count ) : ?><!-- Cuadre de inventario: conteo físico vs. stock virtual -->
                        <div class="ws-cash-cuadre">
                            <div class="ws-cash-cuadre-head">
                                <strong><i class="fa-solid fa-list-check"></i> <?php esc_html_e( 'Cuadre de inventario', 'workshop' ); ?></strong>
                                <span class="ws-muted"><?php esc_html_e( 'Conteo físico vs. stock de la app', 'workshop' ); ?></span>
                            </div>
                            <template x-if="cuadreLoading">
                                <p class="ws-empty"><i class="fa-solid fa-spinner fa-spin"></i> <?php esc_html_e( 'Cargando stock…', 'workshop' ); ?></p>
                            </template>
                            <template x-if="!cuadreLoading && cuadre.length === 0">
                                <p class="ws-empty"><?php esc_html_e( 'Sin productos con stock en esta ubicación.', 'workshop' ); ?></p>
                            </template>
                            <template x-if="!cuadreLoading && cuadre.length > 0">
                                <div class="ws-cash-cuadre-table">
                                    <template x-for="row in cuadre" :key="row.product_id">
                                        <div class="ws-cash-cuadre-row" :class="{ 'ws-cuadre-faltante': cuadreDiff(row) < -0.004, 'ws-cuadre-sobrante': cuadreDiff(row) > 0.004 }">
                                            <div class="ws-cash-cuadre-name"><b x-text="row.name"></b><span><?php esc_html_e( 'Virtual', 'workshop' ); ?>: <b x-text="row.qty"></b></span></div>
                                            <div class="ws-cash-cuadre-input">
                                                <input type="number" step="0.01" min="0" x-model.number="row.physical" :placeholder="row.qty">
                                            </div>
                                            <div class="ws-cash-cuadre-diff">
                                                <span class="ws-muted"><?php esc_html_e( 'Dif.', 'workshop' ); ?>:</span>
                                                <b :class="cuadreDiff(row) > 0.004 ? 'ws-text-success' : (cuadreDiff(row) < -0.004 ? 'ws-text-danger' : '')" x-text="cuadreDiffText(row)"></b>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="cuadre.length > 0">
                                <div class="ws-cash-cuadre-summary">
                                    <span><i class="fa-solid fa-circle-check ws-text-success"></i> <?php esc_html_e( 'Cuadrado', 'workshop' ); ?>: <b x-text="cuadreOkCount()"></b></span>
                                    <span><i class="fa-solid fa-plus-circle ws-text-success"></i> <?php esc_html_e( 'Sobrantes', 'workshop' ); ?>: <b x-text="cuadreSobrantes()"></b></span>
                                    <span><i class="fa-solid fa-minus-circle ws-text-danger"></i> <?php esc_html_e( 'Faltantes', 'workshop' ); ?>: <b x-text="cuadreFaltantes()"></b></span>
                                </div>
                            </template>
                        </div><?php endif; ?>

                        <button class="ws-btn ws-btn-primary ws-btn-full" @click="closeCash()" :disabled="cashSaving">
                            <i class="fa-solid fa-lock"></i>
                            <?php esc_html_e( 'Cerrar caja', 'workshop' ); ?>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Modal: congelar / editar pedido congelado -->
    <div class="ws-modal ws-freeze-modal" x-show="showFreezeModal" x-cloak x-transition @keydown.escape.window="showFreezeModal = false">
        <div class="ws-modal-content" @click.away="showFreezeModal = false">
            <div class="ws-modal-header">
                <h3><i class="fa-solid fa-snowflake"></i> <span x-text="freezeMode === 'edit' ? '<?php esc_html_e( 'Editar pedido congelado', 'workshop' ); ?>' : '<?php esc_html_e( 'Congelar pedido', 'workshop' ); ?>'"></span></h3>
                <button class="ws-modal-close" @click="showFreezeModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <template x-if="freezeMode === 'edit' && freezeTarget">
                    <div class="ws-freeze-items">
                        <h4><?php esc_html_e( 'Productos', 'workshop' ); ?></h4>
                        <template x-for="(item, i) in freezeTarget.items" :key="i">
                            <div class="ws-cart-item ws-freeze-edit-item">
                                <div class="ws-item-info">
                                    <div class="ws-item-name" x-text="item.product_name"></div>
                                    <div class="ws-item-price" x-text="formatPrice(item.price)"></div>
                                </div>
                                <div class="ws-item-qty">
                                    <button @click="frozenItemMinus(freezeTarget, i)">-</button>
                                    <span class="ws-qty-input ws-qty-static" x-text="item.qty"></span>
                                    <button @click="frozenItemPlus(freezeTarget, i)">+</button>
                                </div>
                                <div class="ws-item-total" x-text="formatPrice(item.qty * item.price)"></div>
                                <button class="ws-btn-icon ws-btn-danger" @click="frozenItemRemove(freezeTarget, i)" title="<?php esc_attr_e( 'Quitar', 'workshop' ); ?>">
                                    <i class="fa-solid fa-times"></i>
                                </button>
                            </div>
                        </template>
                        <div class="ws-freeze-summary" x-show="freezeTarget.items.length">
                            <span><?php esc_html_e( 'Subtotal', 'workshop' ); ?></span>
                            <strong x-text="formatPrice(frozenTotal(freezeTarget))"></strong>
                        </div>
                    </div>
                </template>
                <div class="ws-pay-grid">
                    <div class="ws-pay-field">
                        <label><?php esc_html_e( 'Nombre', 'workshop' ); ?> <span class="ws-req">*</span></label>
                        <input type="text" x-model="freezeData.name" placeholder="<?php esc_attr_e( 'Nombre del cliente', 'workshop' ); ?>">
                    </div>
                    <div class="ws-pay-field">
                        <label><?php esc_html_e( 'Carnet / Cédula', 'workshop' ); ?> <span class="ws-req">*</span></label>
                        <input type="text" x-model="freezeData.doc" placeholder="V-12345678">
                    </div>
                    <div class="ws-pay-field">
                        <label><?php esc_html_e( 'Teléfono', 'workshop' ); ?> <span class="ws-req">*</span></label>
                        <input type="tel" x-model="freezeData.phone" placeholder="+58 412 123 4567">
                    </div>
                    <div class="ws-pay-field">
                        <label><?php esc_html_e( 'Nota (opcional)', 'workshop' ); ?></label>
                        <input type="text" x-model="freezeData.note" placeholder="<?php esc_attr_e( 'Observaciones…', 'workshop' ); ?>">
                    </div>
                </div>
            </div>
            <div class="ws-modal-foot">
                <button type="button" class="ws-btn ws-btn-secondary" @click="showFreezeModal = false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                <button type="button" class="ws-btn ws-btn-primary" @click="saveFreeze()">
                    <i class="fa-solid fa-snowflake"></i>
                    <span x-text="freezeMode === 'edit' ? '<?php esc_html_e( 'Guardar cambios', 'workshop' ); ?>' : '<?php esc_html_e( 'Congelar', 'workshop' ); ?>'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: lista de pedidos congelados -->
    <div class="ws-modal ws-frozen-modal" x-show="showFrozenModal" x-cloak x-transition @keydown.escape.window="showFrozenModal = false">
        <div class="ws-modal-content" @click.away="showFrozenModal = false">
            <div class="ws-modal-header">
                <h3><i class="fa-solid fa-snowflake"></i> <?php esc_html_e( 'Pedidos congelados', 'workshop' ); ?> <small class="ws-muted" x-text="'(' + frozenOrders.length + ')'"></small></h3>
                <button class="ws-modal-close" @click="showFrozenModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <div class="ws-search-box">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" x-model="frozenSearch" placeholder="<?php esc_attr_e( 'Buscar por nombre, carnet o teléfono...', 'workshop' ); ?>">
                </div>
                <template x-if="filteredFrozen.length === 0">
                    <div class="ws-empty"><?php esc_html_e( 'No hay pedidos congelados.', 'workshop' ); ?></div>
                </template>
                <div class="ws-frozen-list">
                    <template x-for="f in filteredFrozen" :key="f.id">
                        <div class="ws-frozen-item">
                            <div class="ws-frozen-head">
                                <div class="ws-frozen-cust">
                                    <strong x-text="f.name"></strong>
                                    <span x-text="'<?php esc_html_e( 'C.I.', 'workshop' ); ?> ' + f.doc"></span>
                                    <span x-text="f.phone"></span>
                                </div>
                                <div class="ws-frozen-meta">
                                    <span x-text="frozenItemsCount(f) + ' <?php esc_html_e( 'ítems', 'workshop' ); ?>'"></span>
                                    <strong x-text="formatPrice(frozenTotal(f))"></strong>
                                </div>
                            </div>
                            <div class="ws-frozen-note" x-show="f.note" x-cloak x-text="'<?php esc_html_e( 'Nota', 'workshop' ); ?>: ' + f.note"></div>
                            <div class="ws-frozen-date" x-text="frozenDate(f)"></div>
                            <div class="ws-frozen-actions">
                                <button class="ws-btn ws-btn-primary ws-btn-sm ws-frozen-unfreeze" @click="unfreezeOrder(f)">
                                    <i class="fa-solid fa-temperature-high"></i> <?php esc_html_e( 'Descongelar', 'workshop' ); ?>
                                </button>
                                <button class="ws-btn ws-btn-secondary ws-btn-sm ws-frozen-edit" @click="editFrozen(f)">
                                    <i class="fa-solid fa-pen"></i> <?php esc_html_e( 'Editar', 'workshop' ); ?>
                                </button>
                                <button class="ws-btn ws-btn-danger ws-btn-sm ws-frozen-delete" @click="deleteFrozen(f)">
                                    <i class="fa-solid fa-trash"></i> <?php esc_html_e( 'Eliminar', 'workshop' ); ?>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: contenido completo de un combo (desde el ojo del card del POS) -->
    <div class="ws-modal" x-show="comboDetail" x-cloak @keydown.escape.window="comboDetail = null">
        <div class="ws-modal-backdrop" @click="comboDetail = null"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3><i class="fa-solid fa-layer-group"></i> <span x-text="comboDetail?.name"></span></h3>
                <button class="ws-cart-close" @click="comboDetail = null"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="ws-modal-body">
                <template x-if="comboDetail">
                    <div>
                        <div class="ws-combo-detail-img">
                            <img x-show="comboDetail.image" :src="comboDetail.image" :alt="comboDetail.name" loading="lazy">
                            <i x-show="!comboDetail.image" class="fa-solid fa-layer-group"></i>
                        </div>
                        <div class="ws-combo-detail-meta">
                            <span class="ws-combo-badge"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                            <strong class="ws-combo-detail-price" x-text="formatPrice(comboDetail.sale_price)"></strong>
                            <span class="ws-product-stock" :class="comboDetail.stock > 0 ? 'ws-stock-ok' : 'ws-stock-low'">
                                <i class="fa-solid fa-box"></i>
                                <span x-text="comboDetail.stock"></span>
                            </span>
                        </div>
                        <div class="ws-combo-detail-items">
                            <h4><?php esc_html_e( 'Contiene', 'workshop' ); ?></h4>
                            <template x-for="it in (comboDetail.combo_items || [])" :key="it.product_id">
                                <div class="ws-combo-detail-item">
                                    <i class="fa-solid fa-box"></i>
                                    <span x-text="it.name"></span>
                                    <b x-text="'× ' + it.qty"></b>
                                </div>
                            </template>
                            <p class="ws-empty" x-show="!(comboDetail.combo_items || []).length"><?php esc_html_e( 'Este combo no tiene productos definidos.', 'workshop' ); ?></p>
                        </div>
                    </div>
                </template>
            </div>
            <div class="ws-modal-foot">
                <button type="button" class="ws-btn ws-btn-secondary" @click="comboDetail = null"><?php esc_html_e( 'Cerrar', 'workshop' ); ?></button>
                <button type="button" class="ws-btn ws-btn-primary" @click="addComboToCart()" :disabled="comboDetail && comboDetail.stock <= 0">
                    <i class="fa-solid fa-cart-plus"></i> <?php esc_html_e( 'Añadir al carrito', 'workshop' ); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Helper AJAX
const $ = (path, data) => fetch(WS.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(Object.assign({ action: path, ws_nonce: WS.nonce }, data || {}))
}).then(r => r.json());

document.addEventListener('alpine:init', () => {
    Alpine.data('wsPOS', () => ({
        products: [],
        cart: [],
        customers: [],
        locations: [],
        loadingProducts: false,
        searchQuery: '',
        customerSearch: '',
        discount: 0,
        paymentMode: 'cash',
        customer: null,
        showCustomerModal: false,
        cartOpen: false,
        currentLocationId: null,
        currentLocationName: '',
        // Moneda de la ubicación actual: el POS cobra en la moneda del PV
        // (los precios ya llegan convertidos) y la venta se guarda en ella.
        currentLocationCurrency: '',
        // Datos de pago
        cashAmount: 0,
        transferAmount: 0,
        transferNumber: '',
        payCustomerName: '',
        payCustomerDoc: '',
        payCustomerPhone: '',
        searchTimeout: null,
        // Caja POS
        cashOpen: false,
        cashInfo: null,
        showCashModal: false,
        cashSaving: false,
        cashOpeningAmount: 0,
        cashOpeningNote: '',
        cashClosingAmount: 0,
        cashClosingNote: '',
        // Cuadre de inventario del cierre (físico vs. virtual)
        cuadre: [],
        cuadreLoading: false,
        // Pedidos congelados (cache local): se guardan con nombre, carnet y
        // teléfono obligatorios; se pueden editar, descongelar o eliminar y
        // al descongelar vuelven al carrito para seguir agregando o cobrar.
        frozenOrders: [],
        showFrozenModal: false,
        showFreezeModal: false,
        freezeMode: 'create',          // 'create' | 'edit'
        freezeTarget: null,            // pedido congelado que se está editando
        freezeData: { name: '', doc: '', phone: '', note: '' },
        frozenSearch: '',

        init() {
            this.loadLocations();
            this.loadCustomers();
            this.loadFrozen();
        },

        // Abre el carrito con la misma lógica que el carrito de compra de la
        // tienda: en móvil (drawer) abre el panel lateral con backdrop; en
        // desktop el carrito ya es la columna visible, así que solo lleva la
        // vista hasta él con scroll suave (sin activar el backdrop).
        toggleCart() {
            // El drawer del carrito solo existe en la media query ≤520px del CSS;
            // usar matchMedia garantiza que el umbral coincida siempre (en
            // 521-768px el carrito sigue siendo columna, no hay drawer).
            if (window.matchMedia('(max-width: 520px)').matches) {
                this.cartOpen = true;
                return;
            }
            const el = this.$el ? this.$el.querySelector('.ws-pos-cart') : null;
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        },

        async loadLocations() {
            try {
                const response = await $('ws_my_locations');
                if (response.success) {
                    this.locations = response.data.data || [];
                    if (!this.locations.length) return;
                    const saved = localStorage.getItem('ws_current_location_id');
                    const found = this.locations.find(l => String(l.id) === String(saved));
                    this.currentLocationId = found ? found.id : this.locations[0].id;
                    this.currentLocationName = (found || this.locations[0]).name;
                    this.currentLocationCurrency = (found || this.locations[0]).currency || WS.currency || '€';
                    localStorage.setItem('ws_current_location_id', this.currentLocationId);
                    this.loadCashStatus();
                    this.loadProducts();
                }
            } catch (error) {
                console.error('Error cargando ubicaciones:', error);
            }
        },

        changeLocation() {
            const loc = this.locations.find(l => l.id === this.currentLocationId);
            this.currentLocationName = loc ? loc.name : '';
            this.currentLocationCurrency = (loc && loc.currency) || WS.currency || '€';
            localStorage.setItem('ws_current_location_id', this.currentLocationId);
            this.cart = [];
            this.clearPayment();
            this.loadCashStatus();
            this.loadProducts();
        },

        async loadCashStatus() {
            if (!this.currentLocationId) return;
            try {
                const response = await $('ws_pos_cash_status', { location_id: this.currentLocationId });
                if (response.success) {
                    this.cashOpen = !!response.data.data.open;
                    this.cashInfo = response.data.data.cash;
                }
            } catch (error) {
                console.error('Error cargando estado de caja:', error);
            }
        },

        openCashModal() {
            this.showCashModal = true;
            if (this.cashOpen) {
                this.loadCuadre();
            }
        },

        // Carga el stock VIRTUAL de la ubicación para el cuadre del cierre.
        async loadCuadre() {
            if (!this.currentLocationId || this.cuadreLoading) return;
            this.cuadreLoading = true;
            try {
                const response = await $('ws_pos_cash_stock', { location_id: this.currentLocationId });
                if (response.success) {
                    this.cuadre = (response.data.data || []).map(r => Object.assign({}, r, { physical: r.qty }));
                }
            } catch (error) {
                console.error('Error cargando cuadre:', error);
            }
            this.cuadreLoading = false;
        },

        cuadreDiff(row) { return (Number(row.physical) || 0) - Number(row.qty); },
        cuadreDiffText(row) {
            const d = this.cuadreDiff(row);
            return d > 0 ? '+' + d : d;
        },
        cuadreOkCount() { return this.cuadre.filter(r => Math.abs(this.cuadreDiff(r)) <= 0.004).length; },
        cuadreSobrantes() { return this.cuadre.filter(r => this.cuadreDiff(r) > 0.004).length; },
        cuadreFaltantes() { return this.cuadre.filter(r => this.cuadreDiff(r) < -0.004).length; },

        async openCash() {
            // Si las ubicaciones aún no cargaron, no mandar location_id nulo
            // (el botón se puede pulsar antes de que terminen de cargar).
            if (!this.currentLocationId) {
                Swal.fire({ icon: 'warning', title: '<?php esc_html_e( 'Espera un momento', 'workshop' ); ?>', text: '<?php esc_html_e( 'Cargando ubicaciones…', 'workshop' ); ?>' });
                return;
            }
            this.cashSaving = true;
            try {
                const response = await $('ws_pos_cash_open', {
                    location_id: this.currentLocationId,
                    opening_amount: Number(this.cashOpeningAmount) || 0,
                    note: this.cashOpeningNote
                });
                if (response.success) {
                    this.cashOpen = true;
                    this.cashInfo = response.data.data;
                    this.showCashModal = false;
                    Swal.fire({
                        icon: 'success',
                        title: '<?php esc_html_e( 'Caja abierta', 'workshop' ); ?>',
                        text: '<?php esc_html_e( 'Ya puedes vender en esta ubicación.', 'workshop' ); ?>',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({ icon: 'error', title: '<?php esc_html_e( 'Error', 'workshop' ); ?>', text: response.data?.msg || '' });
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: '<?php esc_html_e( 'Error', 'workshop' ); ?>', text: error.responseJSON?.data?.msg || '' });
            }
            this.cashSaving = false;
        },

        async closeCash() {
            const closing = Number(this.cashClosingAmount) || 0;
            this.cashSaving = true;
            try {
                const cuadreData = {};
                this.cuadre.forEach(r => { cuadreData[r.product_id] = Number(r.physical) || 0; });
                const response = await $('ws_pos_cash_close', {
                    location_id: this.currentLocationId,
                    closing_amount: closing,
                    note: this.cashClosingNote,
                    cuadre: JSON.stringify(cuadreData)
                });
                if (response.success) {
                    const d = response.data.data;
                    this.cashOpen = false;
                    this.cashInfo = null;
                    this.showCashModal = false;
                    const c = d.cuadre || {};
                    let cuadreHtml = '';
                    if (c.count) {
                        cuadreHtml = '<br><i class="fa-solid fa-list-check"></i> <?php esc_html_e( 'Cuadre de inventario', 'workshop' ); ?>: ' +
                            c.count + ' <?php esc_html_e( 'productos', 'workshop' ); ?> · ' +
                            '<span style="color:#16a34a">' + (c.sobrante || 0) + ' <?php esc_html_e( 'sobrantes', 'workshop' ); ?></span> · ' +
                            '<span style="color:#dc2626">' + (c.faltante || 0) + ' <?php esc_html_e( 'faltantes', 'workshop' ); ?></span>';
                    }
                    Swal.fire({
                        icon: 'success',
                        title: '<?php esc_html_e( 'Caja cerrada', 'workshop' ); ?>',
                        html: '<?php esc_html_e( 'Ventas de la jornada', 'workshop' ); ?>: <b>' + this.formatPrice(d.sales_total) + '</b><br>' +
                              '<?php esc_html_e( 'Esperado', 'workshop' ); ?>: <b>' + this.formatPrice(d.expected) + '</b><br>' +
                              '<?php esc_html_e( 'Cuadrado', 'workshop' ); ?>: <b>' + this.formatPrice(d.closing_amount) + '</b><br>' +
                              '<?php esc_html_e( 'Diferencia', 'workshop' ); ?>: <b>' + this.formatPrice(d.difference) + '</b>' + cuadreHtml,
                        confirmButtonText: 'OK'
                    });
                    this.cuadre = [];
                } else {
                    Swal.fire({ icon: 'error', title: '<?php esc_html_e( 'Error', 'workshop' ); ?>', text: response.data?.msg || '' });
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: '<?php esc_html_e( 'Error', 'workshop' ); ?>', text: error.responseJSON?.data?.msg || '' });
            }
            this.cashSaving = false;
        },

        async loadProducts() {
            const reqLocation = this.currentLocationId;
            this.loadingProducts = true;
            try {
                const response = await $('ws_products_get', {
                    location_id: this.currentLocationId,
                    active: 1,
                    limit: 100
                });
                // Ignorar respuestas obsoletas si el usuario cambió de ubicación
                // mientras la petición estaba en vuelo.
                if (response.success && String(this.currentLocationId) === String(reqLocation)) {
                    this.products = response.data.data || [];
                    // Venta repetida desde "Ventas POS": poner los productos en el carrito.
                    this.restoreRepeatSale();
                }
            } catch (error) {
                console.error('Error cargando productos:', error);
            }
            this.loadingProducts = false;
        },

        async loadCustomers() {
            try {
                const response = await $('ws_customers_get', { limit: 100 });
                if (response.success) {
                    this.customers = response.data.data || [];
                }
            } catch (error) {
                console.error('Error cargando clientes:', error);
            }
        },

        get filteredProducts() {
            if (!this.searchQuery) return this.products;
            const query = this.searchQuery.toLowerCase();
            return this.products.filter(p =>
                p.name.toLowerCase().includes(query) ||
                (p.barcode || '').includes(query)
            );
        },

        // Combos y productos sueltos por separado: el grid los agrupa en
        // secciones para que los combos se vean como lo que son (un producto
        // que contiene varios) y no mezclados al final de la lista.
        get posCombos() { return this.filteredProducts.filter(p => p.is_combo); },
        get posProducts() { return this.filteredProducts.filter(p => !p.is_combo); },

        // Detalle del combo: el card del POS es compacto (nombre, precio y
        // stock); el ojo abre este modal con TODOS los componentes.
        comboDetail: null,
        openComboDetail(product) {
            this.comboDetail = product;
        },
        addComboToCart() {
            if (!this.comboDetail) return;
            this.addToCart(this.comboDetail);
            this.comboDetail = null;
        },

        get filteredCustomers() {
            if (!this.customerSearch) return this.customers;
            const query = this.customerSearch.toLowerCase();
            return this.customers.filter(c =>
                c.name.toLowerCase().includes(query) ||
                (c.email || '').toLowerCase().includes(query) ||
                (c.phone || '').includes(query)
            );
        },

        get subtotal() {
            return this.cart.reduce((sum, item) => sum + (item.qty * item.price), 0);
        },

        get total() {
            return this.subtotal - this.discount;
        },

        get cartCount() {
            return this.cart.reduce((sum, item) => sum + item.qty, 0);
        },

        /* ---------- Pagos ---------- */

        setPaymentMode(mode) {
            this.paymentMode = mode;
            if (mode === 'transfer') {
                // Por defecto el total se paga completo por transferencia.
                this.transferAmount = 0;
            } else if (mode === 'both') {
                this.cashAmount = this.total;
                this.transferAmount = 0;
            } else if (mode === 'cash') {
                this.cashAmount = 0;
            }
        },

        clearPayment() {
            this.cashAmount = 0;
            this.transferAmount = 0;
            this.transferNumber = '';
            this.payCustomerName = this.customer ? this.customer.name : '';
            this.payCustomerDoc = '';
            this.payCustomerPhone = this.customer ? this.customer.phone : '';
        },

        // Vuelto en modo efectivo: recibido - total.
        get changeDue() {
            if (this.paymentMode !== 'cash') return 0;
            return (Number(this.cashAmount) || 0) - this.total;
        },

        // Diferencia en pago mixto: positivo = falta, negativo = sobra.
        get bothDiff() {
            const sum = (Number(this.cashAmount) || 0) + (Number(this.transferAmount) || 0);
            return this.total - sum;
        },

        get bothStatusClass() {
            return Math.abs(this.bothDiff) < 0.01 ? 'ws-pay-ok' : 'ws-pay-warn';
        },

        get bothStatusIcon() {
            return Math.abs(this.bothDiff) < 0.01 ? 'fa-solid fa-circle-check' : 'fa-solid fa-triangle-exclamation';
        },

        get bothStatusText() {
            if (Math.abs(this.bothDiff) < 0.01) return '<?php esc_html_e( 'Pago parejo: efectivo + transferencia = total', 'workshop' ); ?>';
            return this.bothDiff > 0
                ? '<?php esc_html_e( 'Faltan', 'workshop' ); ?> ' + this.formatPrice(this.bothDiff)
                : '<?php esc_html_e( 'Sobran', 'workshop' ); ?> ' + this.formatPrice(-this.bothDiff);
        },

        /* ---------- Carrito ---------- */

        // Restaura en el carrito los productos de una venta repetida desde
        // "Ventas POS" (guardados en localStorage). Solo aplica los productos
        // que existen y tienen stock en la ubicación actual; el vendedor
        // revisa el carrito y cobra por el flujo normal.
        restoreRepeatSale() {
            let raw = null;
            try { raw = localStorage.getItem('ws_pos_repeat_items'); } catch (e) {}
            if (!raw) return;
            let items = [];
            try { items = JSON.parse(raw) || []; } catch (e) { items = []; }
            if (!items.length) {
                try { localStorage.removeItem('ws_pos_repeat_items'); } catch (e) {}
                return;
            }
            // Productos aún no cargados: reintentar cuando termine loadProducts.
            if (!this.products.length) return;
            try { localStorage.removeItem('ws_pos_repeat_items'); } catch (e) {}
            let added = 0;
            items.forEach((it) => {
                // Un ítem COMBO se guarda con product_id=0 y combo_id: se busca
                // por combo_id en el catálogo. Un producto normal, por su id.
                const p = this.products.find((x) => String(x.id) === String(it.product_id) || (Number(it.combo_id) > 0 && Number(x.combo_id) === Number(it.combo_id)));
                if (!p || !(Number(p.stock) > 0)) return;
                const qty = Math.min(Math.max(1, Math.floor(Number(it.qty) || 1)), Number(p.stock));
                const existing = this.cart.find((c) => String(c.product_id) === String(p.id));
                if (existing) {
                    existing.qty = Math.min((Number(existing.qty) || 0) + qty, Number(p.stock));
                } else {
                    this.cart.push({ product_id: p.id, combo_id: p.combo_id || 0, product_name: p.name, price: p.sale_price, cost_price: p.cost_price || 0, qty: qty, stock: p.stock });
                }
                added++;
            });
            if (window.Swal) {
                if (added) {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '<?php esc_html_e( 'Venta repetida: revisa el carrito y cobra', 'workshop' ); ?>', showConfirmButton: false, timer: 2500 });
                } else {
                    Swal.fire({ icon: 'warning', title: '<?php esc_html_e( 'Venta no repetida', 'workshop' ); ?>', text: '<?php esc_html_e( 'Los productos de esa venta no están disponibles en esta ubicación.', 'workshop' ); ?>', timer: 3000, showConfirmButton: false });
                }
            }
        },

        addToCart(product) {
            if (product.stock <= 0) {
                Swal.fire({
                    icon: 'warning',
                    title: '<?php esc_html_e( 'Sin stock', 'workshop' ); ?>',
                    text: '<?php esc_html_e( 'Este producto no tiene stock disponible', 'workshop' ); ?>'
                });
                return;
            }

            const existing = this.cart.find(item => item.product_id === product.id);
            if (existing) {
                if (existing.qty < product.stock) {
                    existing.qty++;
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: '<?php esc_html_e( 'Stock máximo', 'workshop' ); ?>',
                        text: '<?php esc_html_e( 'No hay más stock de este producto', 'workshop' ); ?>',
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
            } else {
                this.cart.push({
                    product_id: product.id,
                    combo_id: product.combo_id || 0,
                    product_name: product.name,
                    price: product.sale_price,
                    cost_price: product.cost_price || 0,
                    qty: 1,
                    stock: product.stock
                });
            }
        },

        updateQty(productId, qty) {
            const item = this.cart.find(i => i.product_id === productId);
            if (item) {
                if (qty <= 0) {
                    this.removeFromCart(productId);
                } else if (qty <= item.stock) {
                    item.qty = qty;
                }
            }
        },

        clampQty(productId) {
            const item = this.cart.find(i => i.product_id === productId);
            if (!item) return;
            let qty = Math.floor(Number(item.qty));
            if (isNaN(qty) || qty <= 0) {
                this.removeFromCart(productId);
                return;
            }
            item.qty = Math.min(qty, item.stock);
        },

        removeFromCart(productId) {
            this.cart = this.cart.filter(i => i.product_id !== productId);
        },

        clearCart() {
            this.cart = [];
            this.discount = 0;
            this.customer = null;
            this.clearPayment();
        },

        calculateTotal() {
            // El total se recalcula con el getter; solo se conserva por compatibilidad.
        },

        /* ---------- Pedidos congelados ---------- */

        loadFrozen() {
            try {
                const raw = localStorage.getItem('ws_pos_frozen_v1');
                this.frozenOrders = raw ? (JSON.parse(raw) || []) : [];
            } catch (e) { this.frozenOrders = []; }
        },
        saveFrozenStore() {
            try { localStorage.setItem('ws_pos_frozen_v1', JSON.stringify(this.frozenOrders)); } catch (e) {}
        },
        get frozenCount() { return this.frozenOrders.length; },
        frozenItemsCount(f) { return (f.items || []).reduce((a, i) => a + (Number(i.qty) || 0), 0); },
        frozenSubtotal(f) { return (f.items || []).reduce((a, i) => a + (Number(i.qty) || 0) * (Number(i.price) || 0), 0); },
        frozenTotal(f) { return this.frozenSubtotal(f) - (Number(f.discount) || 0); },
        get filteredFrozen() {
            if (!this.frozenSearch) return this.frozenOrders;
            const q = this.frozenSearch.toLowerCase();
            return this.frozenOrders.filter(f =>
                (f.name || '').toLowerCase().includes(q) ||
                (f.doc || '').toLowerCase().includes(q) ||
                (f.phone || '').toLowerCase().includes(q)
            );
        },
        frozenDate(f) {
            try { return new Date(f.created_at).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); } catch (e) { return f.created_at || ''; }
        },

        // Abre el modal de congelar con los datos del cliente prefijados si ya
        // se escribieron en el pago (o se eligió un cliente).
        freezeCart() {
            if (!this.cart.length) {
                Swal.fire({ icon: 'warning', title: '<?php esc_html_e( 'Carrito vacío', 'workshop' ); ?>', text: '<?php esc_html_e( 'Agrega productos antes de congelar el pedido.', 'workshop' ); ?>' });
                return;
            }
            this.freezeMode = 'create';
            this.freezeTarget = null;
            this.freezeData = {
                name: this.payCustomerName || (this.customer ? this.customer.name : '') || '',
                doc: this.payCustomerDoc || '',
                phone: this.payCustomerPhone || (this.customer ? this.customer.phone : '') || '',
                note: ''
            };
            this.showFreezeModal = true;
        },

        // Abre el modal de edición de un pedido congelado (datos + ítems).
        editFrozen(f) {
            this.freezeMode = 'edit';
            this.freezeTarget = f;
            this.freezeData = { name: f.name || '', doc: f.doc || '', phone: f.phone || '', note: f.note || '' };
            this.showFrozenModal = false;
            this.showFreezeModal = true;
        },

        // Guarda el pedido congelado (nuevo) o los cambios (edición). El nombre,
        // el carnet/cédula y el teléfono son obligatorios en ambos casos.
        saveFreeze() {
            const name = (this.freezeData.name || '').trim();
            const doc = (this.freezeData.doc || '').trim();
            const phone = (this.freezeData.phone || '').trim();
            if (!name || !doc || !phone) {
                Swal.fire({ icon: 'warning', title: '<?php esc_html_e( 'Datos incompletos', 'workshop' ); ?>', text: '<?php esc_html_e( 'El nombre, el carnet/cédula y el teléfono son obligatorios.', 'workshop' ); ?>' });
                return;
            }
            // Modo edición: actualiza los datos y recalcula los totales.
            if (this.freezeMode === 'edit' && this.freezeTarget) {
                const f = this.freezeTarget;
                f.name = name;
                f.doc = doc;
                f.phone = phone;
                f.note = (this.freezeData.note || '').trim();
                f.subtotal = this.frozenSubtotal(f);
                f.total = this.frozenTotal(f);
                this.saveFrozenStore();
                this.showFreezeModal = false;
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '<?php esc_html_e( 'Pedido actualizado', 'workshop' ); ?>', showConfirmButton: false, timer: 2000 });
                return;
            }
            if (!this.cart.length) {
                Swal.fire({ icon: 'warning', title: '<?php esc_html_e( 'Carrito vacío', 'workshop' ); ?>', text: '<?php esc_html_e( 'Agrega productos antes de congelar el pedido.', 'workshop' ); ?>' });
                return;
            }
            const frozen = {
                id: 'f_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
                name, doc, phone,
                note: (this.freezeData.note || '').trim(),
                location_id: this.currentLocationId,
                location_name: this.currentLocationName,
                items: this.cart.map(i => ({
                    product_id: i.product_id,
                    combo_id: i.combo_id || 0,
                    product_name: i.product_name,
                    price: i.price,
                    cost_price: i.cost_price || 0,
                    qty: i.qty,
                    stock: i.stock
                })),
                subtotal: this.subtotal,
                discount: this.discount,
                total: this.total,
                created_at: new Date().toISOString()
            };
            this.frozenOrders.unshift(frozen);
            this.saveFrozenStore();
            this.showFreezeModal = false;
            this.clearCart();
            this.freezeData = { name: '', doc: '', phone: '', note: '' };
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '<?php esc_html_e( 'Pedido congelado', 'workshop' ); ?>', text: name + ' · ' + this.frozenItemsCount(frozen) + ' <?php esc_html_e( 'ítems', 'workshop' ); ?>', showConfirmButton: false, timer: 2500 });
        },

        // Descongela: vuelve a poner los ítems en el carrito (con el stock
        // disponible), prellena los datos del cliente en el pago y elimina la
        // entrada congelada para seguir agregando o cobrar.
        unfreezeOrder(f) {
            let added = 0;
            (f.items || []).forEach((it) => {
                const p = this.products.find((x) => String(x.id) === String(it.product_id) || (Number(it.combo_id) > 0 && Number(x.combo_id) === Number(it.combo_id)));
                if (!p || !(Number(p.stock) > 0)) return;
                const qty = Math.min(Math.max(1, Math.floor(Number(it.qty) || 1)), Number(p.stock));
                const existing = this.cart.find((c) => String(c.product_id) === String(p.id));
                if (existing) {
                    existing.qty = Math.min((Number(existing.qty) || 0) + qty, Number(p.stock));
                } else {
                    this.cart.push({ product_id: p.id, combo_id: p.combo_id || 0, product_name: p.name, price: p.sale_price, cost_price: p.cost_price || 0, qty: qty, stock: p.stock });
                }
                added++;
            });
            if (!added) {
                Swal.fire({ icon: 'warning', title: '<?php esc_html_e( 'No se pudo descongelar', 'workshop' ); ?>', text: '<?php esc_html_e( 'Los productos de ese pedido no están disponibles en esta ubicación.', 'workshop' ); ?>' });
                return;
            }
            this.discount = Number(f.discount) || 0;
            this.payCustomerName = f.name || '';
            this.payCustomerDoc = f.doc || '';
            this.payCustomerPhone = f.phone || '';
            this.removeFrozen(f.id);
            this.showFrozenModal = false;
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '<?php esc_html_e( 'Pedido descongelado: revisa el carrito y cobra', 'workshop' ); ?>', showConfirmButton: false, timer: 2500 });
        },

        removeFrozen(id) {
            this.frozenOrders = this.frozenOrders.filter(f => f.id !== id);
            this.saveFrozenStore();
        },

        deleteFrozen(f) {
            Swal.fire({
                icon: 'warning',
                title: '<?php esc_html_e( '¿Eliminar pedido congelado?', 'workshop' ); ?>',
                text: '<?php esc_html_e( 'Se borrará el pedido de', 'workshop' ); ?> ' + (f.name || '') + '. <?php esc_html_e( 'No podrás recuperarlo.', 'workshop' ); ?>',
                showCancelButton: true,
                confirmButtonText: '<?php esc_html_e( 'Eliminar', 'workshop' ); ?>',
                cancelButtonText: '<?php esc_html_e( 'Cancelar', 'workshop' ); ?>',
                confirmButtonColor: '#dc2626'
            }).then((res) => {
                if (res.isConfirmed) {
                    this.removeFrozen(f.id);
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '<?php esc_html_e( 'Pedido eliminado', 'workshop' ); ?>', showConfirmButton: false, timer: 1500 });
                }
            });
        },

        // Edición de ítems dentro del modal de editar congelado.
        frozenItemPlus(f, i) {
            const it = f.items[i];
            if (!it) return;
            const p = this.products.find((x) => String(x.id) === String(it.product_id) || (Number(it.combo_id) > 0 && Number(x.combo_id) === Number(it.combo_id)));
            const max = p ? Math.floor(Number(p.stock) || 0) : 0;
            it.qty = Math.min((Number(it.qty) || 1) + 1, max || 9999);
            f.subtotal = this.frozenSubtotal(f);
            f.total = this.frozenTotal(f);
        },
        frozenItemMinus(f, i) {
            const it = f.items[i];
            if (!it) return;
            if ((Number(it.qty) || 1) <= 1) { this.frozenItemRemove(f, i); return; }
            it.qty = (Number(it.qty) || 1) - 1;
            f.subtotal = this.frozenSubtotal(f);
            f.total = this.frozenTotal(f);
        },
        frozenItemRemove(f, i) {
            f.items.splice(i, 1);
            f.subtotal = this.frozenSubtotal(f);
            f.total = this.frozenTotal(f);
            if (!f.items.length) { this.removeFrozen(f.id); this.showFreezeModal = false; }
        },

        openCustomerModal() {
            this.customerSearch = '';
            this.showCustomerModal = true;
        },

        selectCustomer(customer) {
            this.customer = customer;
            this.showCustomerModal = false;
            // Rellenar datos de transferencia si aún no se escribieron.
            if (!this.payCustomerName.trim()) this.payCustomerName = customer.name || '';
            if (!this.payCustomerPhone.trim()) this.payCustomerPhone = customer.phone || '';
        },

        /* ---------- Venta ---------- */

        async completeSale() {
            if (this.cart.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: '<?php esc_html_e( 'Carrito vacío', 'workshop' ); ?>',
                    text: '<?php esc_html_e( 'Agrega productos al carrito para completar la venta', 'workshop' ); ?>'
                });
                return;
            }

            // Caja POS: sin caja abierta no se permite vender.
            if (!this.cashOpen) {
                Swal.fire({
                    icon: 'warning',
                    title: '<?php esc_html_e( 'Caja cerrada', 'workshop' ); ?>',
                    text: '<?php esc_html_e( 'Debes abrir la caja antes de vender.', 'workshop' ); ?>'
                });
                this.openCashModal();
                return;
            }

            // Efectivo: el monto recibido debe cubrir el total.
            if (this.paymentMode === 'cash' && (Number(this.cashAmount) || 0) < this.total - 0.001) {
                Swal.fire({
                    icon: 'warning',
                    title: '<?php esc_html_e( 'Monto insuficiente', 'workshop' ); ?>',
                    text: '<?php esc_html_e( 'Faltan', 'workshop' ); ?> ' + this.formatPrice(this.total - (Number(this.cashAmount) || 0))
                });
                return;
            }

            // Transferencia (sola o mixta): datos obligatorios del cliente.
            const needsTransfer = this.paymentMode === 'transfer' || this.paymentMode === 'both';
            if (needsTransfer) {
                const missing = [];
                if (!this.payCustomerName.trim()) missing.push('<?php esc_html_e( 'nombre', 'workshop' ); ?>');
                if (!this.payCustomerDoc.trim()) missing.push('<?php esc_html_e( 'carnet/cédula', 'workshop' ); ?>');
                if (!this.payCustomerPhone.trim()) missing.push('<?php esc_html_e( 'teléfono', 'workshop' ); ?>');
                if (!this.transferNumber.trim()) missing.push('<?php esc_html_e( 'nº de transferencia', 'workshop' ); ?>');
                if (missing.length) {
                    Swal.fire({
                        icon: 'warning',
                        title: '<?php esc_html_e( 'Datos de transferencia incompletos', 'workshop' ); ?>',
                        text: '<?php esc_html_e( 'Completa', 'workshop' ); ?>: ' + missing.join(', ')
                    });
                    return;
                }
            }

            // Pago mixto: efectivo + transferencia debe cuadrar con el total.
            if (this.paymentMode === 'both' && Math.abs(this.bothDiff) >= 0.01) {
                Swal.fire({
                    icon: 'warning',
                    title: '<?php esc_html_e( 'El pago no cuadra', 'workshop' ); ?>',
                    text: this.bothStatusText
                });
                return;
            }

            const isCash  = this.paymentMode === 'cash';
            const isTrans = this.paymentMode === 'transfer';
            const isBoth  = this.paymentMode === 'both';

            const saleData = {
                location_id: this.currentLocationId,
                seller_id: WS.userId,
                customer_id: this.customer?.id || 0,
                customer_name: (needsTransfer && this.payCustomerName.trim()) ? this.payCustomerName : (this.customer?.name || '<?php esc_html_e( 'Venta general', 'workshop' ); ?>'),
                customer_doc: needsTransfer ? this.payCustomerDoc : '',
                customer_phone: needsTransfer ? this.payCustomerPhone : (this.customer?.phone || ''),
                currency: this.currentLocationCurrency || WS.currency || '€',
                subtotal: this.subtotal,
                discount: this.discount,
                total: this.total,
                payment_method: this.paymentMode,
                cash_amount: isCash ? (Number(this.cashAmount) || 0) : (isBoth ? (Number(this.cashAmount) || 0) : 0),
                transfer_amount: isBoth ? (Number(this.transferAmount) || 0) : (isTrans ? this.total : 0),
                transfer_number: needsTransfer ? this.transferNumber : '',
                status: 'completed',
                items: JSON.stringify(this.cart.map(item => ({
                    product_id: item.product_id,
                    combo_id: item.combo_id || 0,
                    product_name: item.product_name,
                    qty: item.qty,
                    price: item.price,
                    cost_price: item.cost_price || 0,
                    discount: 0,
                    subtotal: item.qty * item.price
                })))
            };

            try {
                const response = await $('ws_pos_sale_save', saleData);
                if (response.success) {
                    this.clearCart();
                    this.cartOpen = false;
                    // Refresca el stock de los productos de la ubicación para
                    // que el descuento del inventario se vea de inmediato.
                    this.loadProducts();
                    Swal.fire({
                        icon: 'success',
                        title: '<?php esc_html_e( 'Venta completada', 'workshop' ); ?>',
                        text: '<?php echo esc_html( __( 'Venta', 'workshop' ) ); ?> #' + response.data.sale_id + ' <?php echo esc_html( __( 'guardada exitosamente', 'workshop' ) ); ?>',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: '<?php esc_html_e( 'Error', 'workshop' ); ?>',
                        text: response.data?.msg || '<?php esc_html_e( 'No se pudo completar la venta', 'workshop' ); ?>'
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '<?php esc_html_e( 'Error', 'workshop' ); ?>',
                    text: error.responseJSON?.data?.msg || '<?php esc_html_e( 'No se pudo completar la venta', 'workshop' ); ?>'
                });
            }
        },

        searchByBarcode() {
            const product = this.products.find(p => p.barcode === this.searchQuery);
            if (product) {
                this.addToCart(product);
                this.searchQuery = '';
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: '<?php esc_html_e( 'Producto no encontrado', 'workshop' ); ?>',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        },

        formatPrice(price) {
            const val = Number(price) || 0;
            const sym = this.currentLocationCurrency || WS.currency || '';
            if (/^[A-Z]{3}$/.test(sym)) {
                try {
                    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: sym }).format(val);
                } catch (e) { /* símbolo o código inválido: formato manual */ }
            }
            const num = val.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return (sym ? sym + ' ' : '') + num;
        }
    }));
});
</script>
