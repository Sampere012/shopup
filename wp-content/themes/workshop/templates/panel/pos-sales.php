<?php
/**
 * Módulo Ventas POS.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$can_view = ws_can( 'pos_view' );

$locations = ws_user_locations();
?>

<div class="ws-module-pos-sales" x-data="wsPOSSales(<?php echo esc_attr( wp_json_encode( array(
    'locations' => array_map( fn( $l ) => array( 'id' => (int) $l->id, 'name' => $l->name ), $locations ),
) ) ); ?>)">
    <div class="ws-module-header">
        <div class="ws-header-left">
            <h2><?php esc_html_e( 'Ventas POS', 'workshop' ); ?></h2>
            <p class="ws-header-desc"><?php esc_html_e( 'Historial de ventas del punto de venta.', 'workshop' ); ?></p>
        </div>
        <div class="ws-tabs">
            <button class="ws-tab" :class="{ active: tab === 'sales' }" @click="switchTab('sales')">
                <i class="fa-solid fa-receipt"></i>
                <?php esc_html_e( 'Ventas', 'workshop' ); ?>
            </button>
            <button class="ws-tab" :class="{ active: tab === 'cash' }" @click="switchTab('cash')">
                <i class="fa-solid fa-cash-register"></i>
                <?php esc_html_e( 'Arqueo de caja', 'workshop' ); ?>
            </button>
        </div>
        <div class="ws-header-actions">
            <button class="ws-btn ws-btn-primary" @click="exportSales()">
                <i class="fa-solid fa-download"></i>
                <?php esc_html_e( 'Exportar', 'workshop' ); ?>
            </button>
        </div>
    </div>

    <div class="ws-module-toolbar" x-show="tab === 'sales'" x-cloak>
        <div class="ws-search-box">
            <i class="fa-solid fa-search"></i>
            <input type="text" 
                   x-model="search" 
                   @input="debounceSearch()"
                   placeholder="<?php esc_attr_e( 'Buscar venta...', 'workshop' ); ?>">
        </div>
        <div class="ws-filters">
            <select x-model="locationFilter" @change="loadSales()" aria-label="<?php esc_attr_e( 'Ubicación', 'workshop' ); ?>">
                <option value=""><?php esc_html_e( 'Todas las ubicaciones', 'workshop' ); ?></option>
                <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
            </select>
            <input type="date" x-model="dateFrom" @change="loadSales()">
            <input type="date" x-model="dateTo" @change="loadSales()">
            <select x-model="statusFilter" @change="loadSales()">
                <option value=""><?php esc_html_e( 'Todos los estados', 'workshop' ); ?></option>
                <option value="completed"><?php esc_html_e( 'Completadas', 'workshop' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendientes', 'workshop' ); ?></option>
                <option value="cancelled"><?php esc_html_e( 'Canceladas', 'workshop' ); ?></option>
            </select>
        </div>
    </div>

    <div class="ws-stats-grid" x-show="tab === 'sales'" x-cloak>
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-green">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Ventas Totales', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="formatCurrency(stats.total_sales)"></div>
            </div>
        </div>
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-blue">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Cantidad Ventas', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="stats.total_count"></div>
            </div>
        </div>
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-purple">
                <i class="fa-solid fa-coins"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Promedio', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="formatCurrency(stats.average_sale)"></div>
            </div>
        </div>
    </div>

    <div class="ws-data-table" x-show="tab === 'sales'" x-cloak>
        <table>
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Cliente', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Vendedor', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Método Pago', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Total', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr>
                        <td colspan="8" class="ws-text-center">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            <?php esc_html_e( 'Cargando...', 'workshop' ); ?>
                        </td>
                    </tr>
                </template>
                <template x-if="!loading && sales.length === 0">
                    <tr>
                        <td colspan="8" class="ws-text-center">
                            <?php esc_html_e( 'No hay ventas registradas', 'workshop' ); ?>
                        </td>
                    </tr>
                </template>
                <template x-for="sale in pagedRows()" :key="sale.id">
                    <tr>
                        <td>#<span x-text="sale.id"></span></td>
                        <td x-text="formatDate(sale.created_at)"></td>
                        <td x-text="sale.customer_name || '-'"></td>
                        <td x-text="sale.seller_name || '-'"></td>
                        <td>
                            <span class="ws-payment-badge" :class="'ws-payment-' + sale.payment_method">
                                <i :class="getPaymentIcon(sale.payment_method)"></i>
                                <span x-text="getPaymentLabel(sale.payment_method)"></span>
                            </span>
                        </td>
                        <td x-text="formatCurrency(sale.total)"></td>
                        <td>
                            <span class="ws-badge" :class="'ws-badge-' + sale.status" x-text="getStatusLabel(sale.status)"></span>
                        </td>
                        <td>
                            <div class="ws-actions">
                                <button class="ws-btn-icon" @click="repeatSale(sale)" title="<?php esc_attr_e( 'Repetir venta (cargar en el POS)', 'workshop' ); ?>">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                                <button class="ws-btn-icon" @click="viewSaleDetails(sale)" title="<?php esc_attr_e( 'Ver detalles', 'workshop' ); ?>">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                                <button class="ws-btn-icon" @click="printSale(sale)" title="<?php esc_attr_e( 'Imprimir', 'workshop' ); ?>">
                                    <i class="fa-solid fa-print"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div class="ws-pagination" x-show="total > pageSize">
            <span class="ws-pagination-info" x-text="(total ? (page - 1) * pageSize + 1 : 0) + '–' + Math.min(page * pageSize, total) + ' de ' + total"></span>
            <div class="ws-pagination-controls">
                <button class="ws-page-btn" @click="prevPage()" :disabled="page <= 1"><i class="fa-solid fa-chevron-left"></i></button>
                <template x-for="n in pages()" :key="n">
                    <button class="ws-page-btn" :class="n === page ? 'is-active' : ''" @click="goPage(n)" x-text="n"></button>
                </template>
                <button class="ws-page-btn" @click="nextPage()" :disabled="page >= totalPages()"><i class="fa-solid fa-chevron-right"></i></button>
                <select class="ws-page-size" x-model.number="pageSize" @change="changePageSize()">
                    <option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Modal de detalles de venta -->
    <div class="ws-modal" x-show="showDetailsModal" x-cloak x-transition>
        <div class="ws-modal-content ws-modal-lg" @click.away="showDetailsModal = false">
            <div class="ws-modal-header">
                <h3><?php esc_html_e( 'Detalles de Venta', 'workshop' ); ?> #<span x-text="selectedSale?.id"></span></h3>
                <button class="ws-modal-close" @click="showDetailsModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <div class="ws-sale-details">
                    <div class="ws-sale-info">
                        <div class="ws-info-row">
                            <span><?php esc_html_e( 'Fecha:', 'workshop' ); ?></span>
                            <span x-text="formatDate(selectedSale?.created_at)"></span>
                        </div>
                        <div class="ws-info-row">
                            <span><?php esc_html_e( 'Cliente:', 'workshop' ); ?></span>
                            <span x-text="selectedSale?.customer_name || '-'"></span>
                        </div>
                        <div class="ws-info-row">
                            <span><?php esc_html_e( 'Vendedor:', 'workshop' ); ?></span>
                            <span x-text="selectedSale?.seller_name || '-'"></span>
                        </div>
                        <div class="ws-info-row">
                            <span><?php esc_html_e( 'Método de pago:', 'workshop' ); ?></span>
                            <span x-text="getPaymentLabel(selectedSale?.payment_method)"></span>
                        </div>
                        <div class="ws-info-row" x-show="selectedSale?.payment_method === 'cash' || selectedSale?.payment_method === 'both'" x-cloak>
                            <span><?php esc_html_e( 'Efectivo:', 'workshop' ); ?></span>
                            <span x-text="formatCurrency(selectedSale?.cash_amount)"></span>
                        </div>
                        <div class="ws-info-row" x-show="selectedSale?.payment_method === 'transfer' || selectedSale?.payment_method === 'both'" x-cloak>
                            <span><?php esc_html_e( 'Transferencia:', 'workshop' ); ?></span>
                            <span x-text="formatCurrency(selectedSale?.transfer_amount)"></span>
                        </div>
                        <div class="ws-info-row" x-show="selectedSale?.payment_method === 'transfer' || selectedSale?.payment_method === 'both'" x-cloak>
                            <span><?php esc_html_e( 'Nº transferencia:', 'workshop' ); ?></span>
                            <span x-text="selectedSale?.transfer_number || '-'"></span>
                        </div>
                        <div class="ws-info-row" x-show="selectedSale?.payment_method === 'transfer' || selectedSale?.payment_method === 'both'" x-cloak>
                            <span><?php esc_html_e( 'Carnet / Cédula:', 'workshop' ); ?></span>
                            <span x-text="selectedSale?.customer_doc || '-'"></span>
                        </div>
                        <div class="ws-info-row" x-show="selectedSale?.customer_phone" x-cloak>
                            <span><?php esc_html_e( 'Teléfono:', 'workshop' ); ?></span>
                            <span x-text="selectedSale?.customer_phone"></span>
                        </div>
                    </div>
                    <div class="ws-sale-items">
                        <h4><?php esc_html_e( 'Productos', 'workshop' ); ?></h4>
                        <table class="ws-items-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Producto', 'workshop' ); ?></th>
                                    <th><?php esc_html_e( 'Cant.', 'workshop' ); ?></th>
                                    <th><?php esc_html_e( 'Precio', 'workshop' ); ?></th>
                                    <th><?php esc_html_e( 'Subtotal', 'workshop' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="item in selectedSaleItems" :key="item.id">
                                    <tr>
                                        <td>
                                            <span x-text="item.product_name"></span>
                                            <span class="ws-combo-badge" x-show="item.combo_id" x-cloak title="<?php esc_attr_e( 'Este producto es un combo', 'workshop' ); ?>"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                                        </td>
                                        <td x-text="item.qty"></td>
                                        <td x-text="formatCurrency(item.price)"></td>
                                        <td x-text="formatCurrency(item.subtotal)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="ws-sale-totals">
                        <div class="ws-total-row">
                            <span><?php esc_html_e( 'Subtotal:', 'workshop' ); ?></span>
                            <span x-text="formatCurrency(selectedSale?.subtotal)"></span>
                        </div>
                        <div class="ws-total-row">
                            <span><?php esc_html_e( 'Descuento:', 'workshop' ); ?></span>
                            <span x-text="formatCurrency(selectedSale?.discount)"></span>
                        </div>
                        <div class="ws-total-row ws-total-final">
                            <span><?php esc_html_e( 'Total:', 'workshop' ); ?></span>
                            <span x-text="formatCurrency(selectedSale?.total)"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pestaña: Arqueo de caja -->
    <div class="ws-module-toolbar" x-show="tab === 'cash'" x-cloak>
        <div class="ws-filters">
            <select x-model="locationFilter" @change="loadCashHistory()" aria-label="<?php esc_attr_e( 'Ubicación', 'workshop' ); ?>">
                <option value=""><?php esc_html_e( 'Todas las ubicaciones', 'workshop' ); ?></option>
                <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
            </select>
            <input type="date" x-model="cashDateFrom" @change="loadCashHistory()">
            <input type="date" x-model="cashDateTo" @change="loadCashHistory()">
            <select x-model="cashStatusFilter" @change="loadCashHistory()">
                <option value=""><?php esc_html_e( 'Todos los estados', 'workshop' ); ?></option>
                <option value="open"><?php esc_html_e( 'Abiertas', 'workshop' ); ?></option>
                <option value="closed"><?php esc_html_e( 'Cerradas', 'workshop' ); ?></option>
            </select>
        </div>
    </div>

    <div class="ws-data-table" x-show="tab === 'cash'" x-cloak>
        <table>
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Ubicación', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Apertura', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Cierre', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Fondo inicial', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Ventas', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Esperado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Cuadrado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Diferencia', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-if="cashLoading">
                    <tr>
                        <td colspan="11" class="ws-text-center">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            <?php esc_html_e( 'Cargando...', 'workshop' ); ?>
                        </td>
                    </tr>
                </template>
                <template x-if="!cashLoading && cashHistory.length === 0">
                    <tr>
                        <td colspan="11" class="ws-text-center">
                            <?php esc_html_e( 'No hay arqueos de caja', 'workshop' ); ?>
                        </td>
                    </tr>
                </template>
                <template x-for="cash in cashPagedRows()" :key="cash.id">
                    <tr>
                        <td>#<span x-text="cash.id"></span></td>
                        <td x-text="cash.location_name || '-'"></td>
                        <td x-text="formatDateTime(cash.opened_at)"></td>
                        <td x-text="cash.closed_at ? formatDateTime(cash.closed_at) : '-'"></td>
                        <td x-text="formatCurrency(cash.opening_amount)"></td>
                        <td x-text="formatCurrency(cash.sales_total)"></td>
                        <td x-text="formatCurrency(cash.expected)"></td>
                        <td x-text="formatCurrency(cash.closing_amount)"></td>
                        <td>
                            <span class="ws-badge" :class="cash.difference >= 0 ? 'ws-badge-completed' : 'ws-badge-cancelled'" x-text="formatCurrency(cash.difference)"></span>
                        </td>
                        <td>
                            <span class="ws-badge" :class="cash.status === 'closed' ? 'ws-badge-completed' : 'ws-badge-pending'" x-text="cash.status === 'closed' ? '<?php esc_html_e( 'Cerrada', 'workshop' ); ?>' : '<?php esc_html_e( 'Abierta', 'workshop' ); ?>'"></span>
                        </td>
                        <td>
                            <template x-if="cash.status === 'closed'">
                                <button class="ws-btn-icon" title="<?php esc_attr_e( 'Ver cuadre de inventario (físico vs virtual)', 'workshop' ); ?>" @click="viewCashCounts(cash)">
                                    <i class="fa-solid fa-list-check"></i>
                                </button>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div class="ws-pagination" x-show="cashTotal > cashPageSize">
            <span class="ws-pagination-info" x-text="(cashTotal ? (cashPage - 1) * cashPageSize + 1 : 0) + '–' + Math.min(cashPage * cashPageSize, cashTotal) + ' de ' + cashTotal"></span>
            <div class="ws-pagination-controls">
                <button class="ws-page-btn" @click="cashPrevPage()" :disabled="cashPage <= 1"><i class="fa-solid fa-chevron-left"></i></button>
                <template x-for="n in cashPages()" :key="n">
                    <button class="ws-page-btn" :class="n === cashPage ? 'is-active' : ''" @click="cashGoPage(n)" x-text="n"></button>
                </template>
                <button class="ws-page-btn" @click="cashNextPage()" :disabled="cashPage >= cashTotalPages()"><i class="fa-solid fa-chevron-right"></i></button>
                <select class="ws-page-size" x-model.number="cashPageSize" @change="cashChangePageSize()">
                    <option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Modal: cuadre de inventario de un cierre (físico vs virtual) -->
    <div class="ws-modal" x-show="showCountsModal" x-cloak x-transition @keydown.escape.window="showCountsModal = false">
        <div class="ws-modal-backdrop" @click="showCountsModal = false"></div>
        <div class="ws-modal-box ws-modal-lg">
            <div class="ws-modal-head">
                <h3><i class="fa-solid fa-list-check"></i> <?php esc_html_e( 'Cuadre de inventario', 'workshop' ); ?> #<span x-text="countsRegisterId"></span></h3>
                <button class="ws-cart-close" @click="showCountsModal = false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="ws-modal-body">
                <template x-if="countsLoading">
                    <p class="ws-empty"><i class="fa-solid fa-spinner fa-spin"></i> <?php esc_html_e( 'Cargando cuadre…', 'workshop' ); ?></p>
                </template>
                <template x-if="!countsLoading && countsItems.length === 0">
                    <p class="ws-empty"><?php esc_html_e( 'Este cierre no registró cuadre de inventario.', 'workshop' ); ?></p>
                </template>
                <template x-if="!countsLoading && countsItems.length > 0">
                    <div>
                        <div class="ws-cash-cuadre-summary" style="margin-bottom:12px">
                            <span><i class="fa-solid fa-circle-check ws-text-success"></i> <?php esc_html_e( 'Cuadrados', 'workshop' ); ?>: <b x-text="countsSummary.count - countsSummary.sobrante - countsSummary.faltante"></b></span>
                            <span><i class="fa-solid fa-plus-circle ws-text-success"></i> <?php esc_html_e( 'Sobrantes', 'workshop' ); ?>: <b x-text="countsSummary.sobrante"></b></span>
                            <span><i class="fa-solid fa-minus-circle ws-text-danger"></i> <?php esc_html_e( 'Faltantes', 'workshop' ); ?>: <b x-text="countsSummary.faltante"></b></span>
                        </div>
                        <table class="ws-items-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Producto', 'workshop' ); ?></th>
                                    <th><?php esc_html_e( 'Virtual (app)', 'workshop' ); ?></th>
                                    <th><?php esc_html_e( 'Físico (contado)', 'workshop' ); ?></th>
                                    <th><?php esc_html_e( 'Diferencia', 'workshop' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="c in countsItems" :key="c.product_id">
                                    <tr>
                                        <td x-text="c.product_name"></td>
                                        <td x-text="c.virtual_qty"></td>
                                        <td x-text="c.physical_qty"></td>
                                        <td>
                                            <span class="ws-badge" :class="c.diff > 0.004 ? 'ws-badge-completed' : (c.diff < -0.004 ? 'ws-badge-cancelled' : 'ws-badge-pending')" x-text="(c.diff > 0 ? '+' : '') + c.diff"></span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
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
    Alpine.data('wsPOSSales', (opts) => ({
        ...WSClientPager('sales'),
        ...WSClientPager('cashHistory', 'cash'),
        sales: [],
        selectedSale: null,
        selectedSaleItems: [],
        loading: false,
        showDetailsModal: false,
        locations: (opts && opts.locations) || [],
        locationFilter: '',
        search: '',
        dateFrom: '',
        dateTo: '',
        statusFilter: '',
        stats: { total_sales: 0, total_count: 0, average_sale: 0 },
        searchTimeout: null,
        // Arqueo de caja
        tab: 'sales',
        cashHistory: [],
        cashLoading: false,
        cashDateFrom: '',
        cashDateTo: '',
        cashStatusFilter: '',
        // Cuadre de inventario de un cierre (físico vs virtual)
        showCountsModal: false,
        countsLoading: false,
        countsRegisterId: 0,
        countsItems: [],
        countsSummary: { count: 0, sobrante: 0, faltante: 0 },

        init() {
            this.setTodayDates();
            this.setCashDates();
            this.loadSales();
            this.loadStats();
        },

        setCashDates() {
            const today = new Date();
            this.cashDateFrom = today.toISOString().split('T')[0];
            this.cashDateTo = today.toISOString().split('T')[0];
        },

        switchTab(tab) {
            this.tab = tab;
            if (tab === 'cash' && !this.cashHistory.length) {
                this.loadCashHistory();
            }
        },

        async loadCashHistory() {
            this.cashLoading = true;
            this.cashOnPageFilter();
            try {
                const response = await $('ws_pos_cash_history', {
                    location_id: this.locationFilter,
                    date_from: this.cashDateFrom,
                    date_to: this.cashDateTo,
                    status: this.cashStatusFilter,
                    limit: 500,
                    offset: 0
                });
                if (response.success) {
                    this.cashHistory = response.data.data || [];
                }
            } catch (error) {
                console.error('Error cargando arqueo:', error);
            }
            this.cashLoading = false;
        },

        // Abre el detalle del cuadre de inventario de un cierre cerrado:
        // compara el conteo FÍSICO que se ingresó al cerrar con el stock
        // VIRTUAL que manejaba la app en ese momento, producto por producto.
        async viewCashCounts(cash) {
            this.countsRegisterId = cash.id || 0;
            this.countsItems = [];
            this.countsSummary = { count: 0, sobrante: 0, faltante: 0 };
            this.showCountsModal = true;
            this.countsLoading = true;
            try {
                const response = await $('ws_pos_cash_counts_get', { register_id: cash.id });
                if (response.success) {
                    this.countsItems = (response.data.data && response.data.data.items) || [];
                    this.countsSummary = (response.data.data && response.data.data.summary) || this.countsSummary;
                }
            } catch (error) {
                console.error('Error cargando cuadre:', error);
            }
            this.countsLoading = false;
        },

        formatDateTime(date) {
            return new Date(date).toLocaleString('es-ES');
        },

        setTodayDates() {
            const today = new Date();
            this.dateFrom = today.toISOString().split('T')[0];
            this.dateTo = today.toISOString().split('T')[0];
        },

        async loadSales() {
            this.loading = true;
            this.onPageFilter();
            try {
                const response = await $('ws_pos_sales_get', {
                    location_id: this.locationFilter,
                    search: this.search,
                    date_from: this.dateFrom,
                    date_to: this.dateTo,
                    status: this.statusFilter,
                    limit: 500,
                    offset: 0
                });
                if (response.success) {
                    this.sales = response.data.data || [];
                }
            } catch (error) {
                console.error('Error cargando ventas:', error);
            }
            this.loading = false;
        },

        async loadStats() {
            try {
                const response = await $('ws_pos_stats', {
                    location_id: this.locationFilter,
                    date_from: this.dateFrom,
                    date_to: this.dateTo
                });
                if (response.success) {
                    this.stats = response.data.data || { total_sales: 0, total_count: 0, average_sale: 0 };
                }
            } catch (error) {
                console.error('Error cargando estadísticas:', error);
            }
        },

        debounceSearch() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => this.loadSales(), 500);
        },

        async viewSaleDetails(sale) {
            this.selectedSale = sale;
            try {
                const response = await $('ws_pos_sale_items_get', { sale_id: sale.id });
                if (response.success) {
                    this.selectedSaleItems = response.data.data || [];
                }
            } catch (error) {
                console.error('Error cargando items:', error);
            }
            this.showDetailsModal = true;
        },

        // Repetir venta: carga los productos de una venta anterior en el carrito
        // del POS (via localStorage) para cobrarla de nuevo sin reescribir nada.
        // El vendedor solo ajusta cantidades o el cliente y cobra por el flujo
        // normal del POS (caja, pago, stock…).
        async repeatSale(sale) {
            try {
                const response = await $('ws_pos_sale_items_get', { sale_id: sale.id });
                if (!response.success) throw new Error('No se pudieron cargar los productos');
                const items = response.data.data || [];
                if (!items.length) {
                    if (window.Swal) Swal.fire({ icon: 'warning', title: '<?php esc_html_e( 'Sin productos', 'workshop' ); ?>', text: '<?php esc_html_e( 'Esta venta no tiene productos para repetir.', 'workshop' ); ?>', timer: 2000, showConfirmButton: false });
                    return;
                }
                try {
                    localStorage.setItem('ws_pos_repeat_items', JSON.stringify(items));
                } catch (e) { /* almacenamiento no disponible */ }
                // Ir al POS: la página de POS detecta los items guardados y los
                // pone en el carrito para revisarlos antes de cobrar.
                window.location.href = location.pathname.replace(/pos-sales\/?$/, 'pos/');
            } catch (error) {
                if (window.Swal) Swal.fire({ icon: 'error', title: '<?php esc_html_e( 'Error', 'workshop' ); ?>', text: '<?php esc_html_e( 'No se pudo repetir la venta.', 'workshop' ); ?>' });
            }
        },

        printSale(sale) {
            window.print();
        },

        exportSales() {
            const csv = this.generateCSV();
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'ventas-pos-' + this.dateFrom + '.csv';
            a.click();
        },

        generateCSV() {
            const headers = ['ID', 'Fecha', 'Cliente', 'Vendedor', 'Método', 'Efectivo', 'Transferencia', 'Nº Transf.', 'Total', 'Estado'];
            const rows = this.sales.map(s => [
                s.id,
                s.created_at,
                s.customer_name || '-',
                s.seller_name || '-',
                this.getPaymentLabel(s.payment_method),
                s.cash_amount || 0,
                s.transfer_amount || 0,
                s.transfer_number || '',
                s.total,
                s.status
            ]);
            return [headers, ...rows].map(row => row.map(c => String(c).replace(/,/g, ';')).join(',')).join('\n');
        },

        formatDate(date) {
            return new Date(date).toLocaleDateString('es-ES');
        },

        formatCurrency(amount) {
            const val = Number(amount) || 0;
            const sym = WS.currency || '';
            if (/^[A-Z]{3}$/.test(sym)) {
                try {
                    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: sym }).format(val);
                } catch (e) { /* símbolo o código inválido: formato manual */ }
            }
            const num = val.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return (sym ? sym + ' ' : '') + num;
        },

        getPaymentLabel(method) {
            const labels = {
                cash: '<?php esc_html_e( 'Efectivo', 'workshop' ); ?>',
                card: '<?php esc_html_e( 'Tarjeta', 'workshop' ); ?>',
                transfer: '<?php esc_html_e( 'Transferencia', 'workshop' ); ?>',
                both: '<?php esc_html_e( 'Efectivo + Transferencia', 'workshop' ); ?>'
            };
            return labels[method] || method;
        },

        getPaymentIcon(method) {
            const icons = {
                cash: 'fa-money-bill',
                card: 'fa-credit-card',
                transfer: 'fa-building-columns',
                both: 'fa-money-bill-transfer'
            };
            return 'fa-solid ' + (icons[method] || 'fa-money-bill');
        },

        getStatusLabel(status) {
            const labels = {
                completed: '<?php esc_html_e( 'Completada', 'workshop' ); ?>',
                pending: '<?php esc_html_e( 'Pendiente', 'workshop' ); ?>',
                cancelled: '<?php esc_html_e( 'Cancelada', 'workshop' ); ?>'
            };
            return labels[status] || status;
        }
    }));
});
</script>
