<?php
/**
 * Módulo POS (Punto de Venta).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$can_sell = ws_can( 'pos_sell' );

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
                    <button class="ws-pos-cash-btn" :class="cashOpen ? 'ws-cash-open' : 'ws-cash-closed'" @click="openCashModal()" title="<?php esc_attr_e( 'Abrir / cerrar caja', 'workshop' ); ?>">
                        <i class="fa-solid fa-cash-register"></i>
                        <span x-text="cashOpen ? '<?php esc_html_e( 'Caja abierta', 'workshop' ); ?>' : '<?php esc_html_e( 'Caja cerrada', 'workshop' ); ?>'"></span>
                    </button>
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
                <div class="ws-products-grid">
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
                    <template x-for="product in filteredProducts" :key="product.id">
                        <div class="ws-product-card" @click="addToCart(product)" :class="{ 'is-out': product.stock <= 0 }">
                            <div class="ws-product-image">
                                <img :src="product.image || '<?php echo WS_URL; ?>assets/images/placeholder.png'" :alt="product.name" loading="lazy">
                            </div>
                            <div class="ws-product-info">
                                <div class="ws-product-name" x-text="product.name"></div>
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

        <!-- Panel de carrito -->
        <div class="ws-pos-cart" :class="{ open: cartOpen }">
            <div class="ws-cart-header">
                <h3><i class="fa-solid fa-shopping-cart"></i><?php esc_html_e( 'Carrito', 'workshop' ); ?></h3>
                <div class="ws-cart-header-actions">
                    <button class="ws-btn-icon" @click="openCustomerModal()" title="<?php esc_attr_e( 'Seleccionar cliente', 'workshop' ); ?>">
                        <i class="fa-solid fa-user"></i>
                    </button>
                    <button class="ws-btn-icon" @click="clearCart()" title="<?php esc_attr_e( 'Limpiar carrito', 'workshop' ); ?>">
                        <i class="fa-solid fa-trash"></i>
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
                            <div class="ws-item-price" x-text="formatPrice(item.price)"></div>
                        </div>
                        <div class="ws-item-qty">
                            <button @click="updateQty(item.product_id, item.qty - 1)">-</button>
                            <span x-text="item.qty"></span>
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

    <!-- Botón flotante del carrito (móvil/tablet) -->
    <button class="ws-pos-cart-toggle" @click="cartOpen = true" x-show="cart.length > 0" x-cloak x-transition>
        <i class="fa-solid fa-shopping-cart"></i>
        <span class="ws-cart-count" x-text="cartCount"></span>
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
                        <button class="ws-btn ws-btn-primary ws-btn-full" @click="closeCash()" :disabled="cashSaving">
                            <i class="fa-solid fa-lock"></i>
                            <?php esc_html_e( 'Cerrar caja', 'workshop' ); ?>
                        </button>
                    </div>
                </template>
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

        init() {
            this.loadLocations();
            this.loadCustomers();
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
        },

        async openCash() {
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
                const response = await $('ws_pos_cash_close', {
                    location_id: this.currentLocationId,
                    closing_amount: closing,
                    note: this.cashClosingNote
                });
                if (response.success) {
                    const d = response.data.data;
                    this.cashOpen = false;
                    this.cashInfo = null;
                    this.showCashModal = false;
                    Swal.fire({
                        icon: 'success',
                        title: '<?php esc_html_e( 'Caja cerrada', 'workshop' ); ?>',
                        html: '<?php esc_html_e( 'Ventas de la jornada', 'workshop' ); ?>: <b>' + this.formatPrice(d.sales_total) + '</b><br>' +
                              '<?php esc_html_e( 'Esperado', 'workshop' ); ?>: <b>' + this.formatPrice(d.expected) + '</b><br>' +
                              '<?php esc_html_e( 'Cuadrado', 'workshop' ); ?>: <b>' + this.formatPrice(d.closing_amount) + '</b><br>' +
                              '<?php esc_html_e( 'Diferencia', 'workshop' ); ?>: <b>' + this.formatPrice(d.difference) + '</b>',
                        confirmButtonText: 'OK'
                    });
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
                    product_name: product.name,
                    price: product.sale_price,
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
                currency: WS.currency || '€',
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
                    product_name: item.product_name,
                    qty: item.qty,
                    price: item.price,
                    discount: 0,
                    subtotal: item.qty * item.price
                })))
            };

            try {
                const response = await $('ws_pos_sale_save', saleData);
                if (response.success) {
                    this.clearCart();
                    this.cartOpen = false;
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
            const sym = WS.currency || '';
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
