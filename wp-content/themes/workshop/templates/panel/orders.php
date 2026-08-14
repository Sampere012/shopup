<?php
/**
 * Panel: pedidos.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$locations = ws_user_locations();
$orders    = array();
$loc_ids   = array_map( fn( $l ) => (int) $l->id, $locations );
foreach ( $loc_ids as $lid ) {
    foreach ( WS_Orders::all( array( 'location_id' => $lid ) ) as $o ) {
        $orders[] = $o;
    }
}
usort( $orders, fn( $a, $b ) => strtotime( $b->created_at ) - strtotime( $a->created_at ) );

$can_accept = ws_can( 'orders_accept' );
$statuses   = WS_Orders::statuses();
?>
<div x-data="wsOrders(<?php echo esc_attr( wp_json_encode( array( 'canAccept' => $can_accept ) ) ); ?>)">

    <div class="ws-toolbar">
        <div class="ws-stock-filters">
            <select x-model="statusFilter" @change="onFilter()">
                <option value=""><?php esc_html_e( 'Todos los estados', 'workshop' ); ?></option>
                <?php foreach ( $statuses as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="ws-muted" style="font-size:.85em"><?php esc_html_e( 'Desde', 'workshop' ); ?></span>
            <input type="date" x-model="dateFrom" @change="onFilter()" aria-label="<?php esc_attr_e( 'Desde', 'workshop' ); ?>" title="<?php esc_attr_e( 'Desde', 'workshop' ); ?>">
            <span class="ws-muted" style="font-size:.85em"><?php esc_html_e( 'Hasta', 'workshop' ); ?></span>
            <input type="date" x-model="dateTo" @change="onFilter()" aria-label="<?php esc_attr_e( 'Hasta', 'workshop' ); ?>" title="<?php esc_attr_e( 'Hasta', 'workshop' ); ?>">
            <button type="button" class="ws-btn ws-btn-secondary" @click="clearDates()" x-show="dateFrom || dateTo"><i class="fa-solid fa-rotate-left"></i> <?php esc_html_e( 'Limpiar', 'workshop' ); ?></button>
        </div>
    </div>

    <div class="ws-card">
        <table class="ws-table">
            <thead>
                <tr>
                    <th class="ws-th-sort" @click="sort('number')"><?php esc_html_e( 'Nº', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('number')"></i></th>
                    <th class="ws-th-sort" @click="sort('location_name')"><?php esc_html_e( 'Tienda', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('location_name')"></i></th>
                    <th class="ws-th-sort" @click="sort('customer_name')"><?php esc_html_e( 'Cliente', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('customer_name')"></i></th>
                    <th class="ws-th-sort" @click="sort('customer_phone')"><?php esc_html_e( 'Teléfono', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('customer_phone')"></i></th>
                    <th class="ws-th-sort" @click="sort('total')"><?php esc_html_e( 'Total', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('total')"></i></th>
                    <th class="ws-th-sort" @click="sort('status')"><?php esc_html_e( 'Estado', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('status')"></i></th>
                    <th class="ws-th-sort" @click="sort('date')"><?php esc_html_e( 'Fecha', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('date')"></i></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="o in orders" :key="o.id">
                    <tr>
                        <td x-text="o.number"></td>
                        <td x-text="o.location_name"></td>
                        <td x-text="o.customer_name"></td>
                        <td x-text="o.customer_phone"></td>
                        <td class="ws-strong" x-text="money(o.total, o.currency)"></td>
                        <td><span class="ws-badge" :class="'ws-badge-' + o.status" x-text="statusLabel(o.status)"></span></td>
                        <td x-text="o.date"></td>
                        <td class="ws-actions">
                            <button class="ws-icon-btn" title="Ver" @click="view(o)"><i class="fa-solid fa-eye"></i></button>
                            <template x-if="canAccept && o.status === 'pending'">
                                <button class="ws-icon-btn ws-success" title="Aceptar" @click="accept(o)"><i class="fa-solid fa-check"></i></button>
                            </template>
                            <template x-if="canAccept && o.status === 'pending'">
                                <button class="ws-icon-btn ws-danger" title="Rechazar" @click="reject(o)"><i class="fa-solid fa-xmark"></i></button>
                            </template>
                            <template x-if="canAccept && o.status === 'accepted'">
                                <button class="ws-icon-btn" title="Completar" @click="complete(o)"><i class="fa-solid fa-flag-checkered"></i></button>
                            </template>
                        </td>
                    </tr>
                </template>
                <tr x-show="total === 0"><td colspan="8"><p class="ws-empty"><?php esc_html_e( 'Sin pedidos.', 'workshop' ); ?></p></td></tr>
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

    <!-- Modal detalle -->
    <div class="ws-modal" x-show="detailOpen" x-cloak @keydown.escape.window="detailOpen=false">
        <div class="ws-modal-backdrop" @click="detailOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="'Pedido ' + (detail.number || '')"></h3>
                <button class="ws-cart-close" @click="detailOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="ws-detail-meta">
                <div><span><?php esc_html_e( 'Cliente', 'workshop' ); ?></span><strong x-text="detail.customer_name"></strong></div>
                <div><span><?php esc_html_e( 'Teléfono', 'workshop' ); ?></span><strong x-text="detail.customer_phone"></strong></div>
                <div><span><?php esc_html_e( 'Dirección', 'workshop' ); ?></span><strong x-text="detail.customer_address || '—'"></strong></div>
                <div><span><?php esc_html_e( 'Estado', 'workshop' ); ?></span><strong x-text="statusLabel(detail.status)"></strong></div>
            </div>
            <table class="ws-table">
                <thead><tr><th><?php esc_html_e( 'Producto', 'workshop' ); ?></th><th>Cant.</th><th><?php esc_html_e( 'Precio', 'workshop' ); ?></th><th><?php esc_html_e( 'Subtotal', 'workshop' ); ?></th></tr></thead>
                <tbody>
                    <template x-for="it in detail.items" :key="it.product_id">
                        <tr>
                            <td x-text="it.product_name"></td>
                            <td x-text="it.qty"></td>
                            <td x-text="money(it.price, detail.currency)"></td>
                            <td x-text="money(it.price * it.qty, detail.currency)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div class="ws-summary-total">
                <div><span><?php esc_html_e( 'Subtotal', 'workshop' ); ?></span><strong x-text="money(detail.subtotal, detail.currency)"></strong></div>
                <div><span><?php esc_html_e( 'Domicilio', 'workshop' ); ?></span><strong x-text="money(detail.delivery_cost, detail.delivery_currency || detail.currency)"></strong></div>
                <div class="ws-total"><span><?php esc_html_e( 'Total', 'workshop' ); ?></span><strong x-text="money(detail.total, detail.currency)"></strong></div>
            </div>
            <div class="ws-modal-foot">
                <button type="button" class="ws-btn ws-btn-secondary" @click="detailOpen=false"><?php esc_html_e( 'Cerrar', 'workshop' ); ?></button>
            </div>
        </div>
    </div>
</div>
