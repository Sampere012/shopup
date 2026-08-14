<?php
/**
 * Panel: historial de movimientos.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$locations = ws_user_locations();
$can_entry = ws_can( 'stock_entry' );
$can_exit  = ws_can( 'stock_exit' );
$can_venta = ws_can( 'pos_sell' ) || $can_exit;
$currency  = ws_currency_symbol();
$sellers   = array();
if ( $can_venta && function_exists( 'ws_announcement_business_users' ) ) {
    foreach ( ws_announcement_business_users( ws_current_business_id() ) as $uid ) {
        $u = get_userdata( $uid );
        if ( $u ) {
            $sellers[] = array( 'id' => (int) $uid, 'name' => $u->display_name );
        }
    }
}
?>
<div x-data="wsMovements(<?php echo esc_attr( wp_json_encode( array(
    'locations' => array_map( fn( $l ) => array( 'id' => (int) $l->id, 'name' => $l->name ), $locations ),
    'currency'  => $currency,
    'canEntry'  => $can_entry,
    'canExit'   => $can_exit,
    'canVenta'  => $can_venta,
    'sellers'   => $sellers,
) ) ); ?>)">

    <div class="ws-card">
        <div class="ws-stock-head">
            <div class="ws-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="<?php esc_attr_e( 'Buscar…', 'workshop' ); ?>" x-model="search" @keyup.enter="onSearch()" @input="onSearch()">
            </div>
            <div class="ws-stock-filters">
                <select x-model="typeFilter" @change="onFilter()">
                    <option value=""><?php esc_html_e( 'Todos los tipos', 'workshop' ); ?></option>
                    <option value="entrada"><?php esc_html_e( 'Entrada', 'workshop' ); ?></option>
                    <option value="salida"><?php esc_html_e( 'Salida', 'workshop' ); ?></option>
                    <option value="venta"><?php esc_html_e( 'Venta', 'workshop' ); ?></option>
                    <option value="baja"><?php esc_html_e( 'Baja', 'workshop' ); ?></option>
                    <option value="transferencia"><?php esc_html_e( 'Transferencia', 'workshop' ); ?></option>
                    <option value="pedido"><?php esc_html_e( 'Pedido', 'workshop' ); ?></option>
                </select>
                <select x-model="locationFilter" @change="onFilter()">
                    <option value=""><?php esc_html_e( 'Todas las ubicaciones', 'workshop' ); ?></option>
                    <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                </select>
            </div>
            <template x-if="canAnyMove">
                <button class="ws-btn ws-btn-primary" @click="openAdd()"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Nuevo movimiento', 'workshop' ); ?></button>
            </template>
        </div>

        <table class="ws-table">
            <thead>
                <tr>
                    <th class="ws-th-sort" @click="sort('date')"><?php esc_html_e( 'Fecha', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('date')"></i></th>
                    <th class="ws-th-sort" @click="sort('type')"><?php esc_html_e( 'Tipo', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('type')"></i></th>
                    <th class="ws-th-sort" @click="sort('product_name')"><?php esc_html_e( 'Producto', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('product_name')"></i></th>
                    <th class="ws-th-sort" @click="sort('location_name')"><?php esc_html_e( 'Ubicación', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('location_name')"></i></th>
                    <th class="ws-th-sort" @click="sort('dest_name')"><?php esc_html_e( 'Destino', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('dest_name')"></i></th>
                    <th class="ws-th-sort" @click="sort('qty')"><?php esc_html_e( 'Cantidad', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('qty')"></i></th>
                    <th class="ws-th-sort" @click="sort('reference')"><?php esc_html_e( 'Referencia', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('reference')"></i></th>
                    <th><?php esc_html_e( 'Descripción', 'workshop' ); ?></th>
                    <th class="ws-th-sort" @click="sort('user_name')"><?php esc_html_e( 'Usuario', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('user_name')"></i></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="m in movements" :key="m.id">
                    <tr>
                        <td x-text="m.date"></td>
                        <td><span class="ws-badge" :class="'ws-move-' + m.type" x-text="typeLabel(m.type)"></span></td>
                        <td x-text="m.product_name"></td>
                        <td x-text="m.location_name || '—'"></td>
                        <td x-text="m.dest_name || '—'"></td>
                        <td class="ws-strong" :class="['entrada','transferencia'].includes(m.type) && m.dest_location_id == 0 ? 'ws-text-success' : (['salida','baja','venta'].includes(m.type) ? 'ws-text-danger' : '')" x-text="m.qty"></td>
                        <td x-text="m.reference || '—'"></td>
                        <td x-text="m.note || '—'"></td>
                        <td x-text="m.user_name || '—'"></td>
                    </tr>
                </template>
                <tr x-show="total === 0"><td colspan="9"><p class="ws-empty"><?php esc_html_e( 'Sin movimientos.', 'workshop' ); ?></p></td></tr>
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

    <!-- Modal: nuevo movimiento -->
    <div class="ws-modal" x-show="addOpen" x-cloak x-transition @keydown.escape.window="addOpen = false">
        <div class="ws-modal-backdrop" @click="addOpen = false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3><?php esc_html_e( 'Nuevo movimiento', 'workshop' ); ?></h3>
                <button class="ws-cart-close" @click="addOpen = false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="doAdd()" class="ws-form">
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Tipo de movimiento *', 'workshop' ); ?></span>
                        <select x-model="form.kind" @change="onKindChange()">
                            <option value="entrada"><?php esc_html_e( 'Entrada', 'workshop' ); ?></option>
                            <option value="salida"><?php esc_html_e( 'Salida', 'workshop' ); ?></option>
                            <option value="baja"><?php esc_html_e( 'Baja', 'workshop' ); ?></option>
                            <option value="venta" x-show="canVenta"><?php esc_html_e( 'Venta (registra en Ventas POS)', 'workshop' ); ?></option>
                            <option value="otro"><?php esc_html_e( 'Otro (personalizado)', 'workshop' ); ?></option>
                        </select>
                    </label>
                    <label class="ws-field" x-show="form.kind === 'otro'">
                        <span><?php esc_html_e( 'Tipo personalizado *', 'workshop' ); ?></span>
                        <input type="text" x-model="form.customType" maxlength="30" placeholder="<?php esc_attr_e( 'Ej. merma, ajuste, devolución…', 'workshop' ); ?>">
                    </label>
                    <label class="ws-field" x-show="form.kind === 'otro'">
                        <span><?php esc_html_e( 'Dirección *', 'workshop' ); ?></span>
                        <select x-model="form.direction">
                            <option value="entrada"><?php esc_html_e( 'Entrada (aumenta stock)', 'workshop' ); ?></option>
                            <option value="salida"><?php esc_html_e( 'Salida (disminuye stock)', 'workshop' ); ?></option>
                        </select>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Producto *', 'workshop' ); ?></span>
                        <select x-model="form.product_id" @change="onProductChange()" required>
                            <option value=""><?php esc_html_e( 'Selecciona un producto…', 'workshop' ); ?></option>
                            <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="p.name + (p.barcode ? ' (' + p.barcode + ')' : '')"></option></template>
                        </select>
                    </label>
                    <label class="ws-field" x-show="form.kind === 'venta'">
                        <span><?php esc_html_e( 'PV / Ubicación *', 'workshop' ); ?></span>
                        <select x-model="form.location_id" required>
                            <option value=""><?php esc_html_e( 'Selecciona…', 'workshop' ); ?></option>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                        </select>
                    </label>
                    <label class="ws-field" x-show="form.kind !== 'venta'">
                        <span><?php esc_html_e( 'Ubicación *', 'workshop' ); ?></span>
                        <select x-model="form.location_id" required>
                            <option value=""><?php esc_html_e( 'Selecciona…', 'workshop' ); ?></option>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                        </select>
                    </label>
                    <label class="ws-field" x-show="form.kind === 'venta'">
                        <span><?php esc_html_e( 'Vendedor *', 'workshop' ); ?></span>
                        <select x-model="form.seller_id" required>
                            <template x-for="s in sellers" :key="s.id"><option :value="s.id" x-text="s.name"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span x-text="form.kind === 'venta' ? '<?php esc_attr_e( 'Cantidad vendida *', 'workshop' ); ?>' : '<?php esc_attr_e( 'Cantidad *', 'workshop' ); ?>'"></span>
                        <input type="number" step="0.01" min="0.01" x-model="form.qty" required>
                    </label>
                    <label class="ws-field" x-show="form.kind === 'venta'">
                        <span><?php esc_html_e( 'Precio de venta *', 'workshop' ); ?></span>
                        <input type="number" step="0.01" min="0" x-model="form.price" required>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Referencia', 'workshop' ); ?></span>
                        <input type="text" x-model="form.reference" placeholder="<?php esc_attr_e( 'Opcional…', 'workshop' ); ?>">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Descripción', 'workshop' ); ?></span>
                        <textarea x-model="form.note" rows="2" placeholder="<?php esc_attr_e( 'Describe el motivo del movimiento…', 'workshop' ); ?>"></textarea>
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="addOpen = false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary" :disabled="saving">
                        <i class="fa-solid" :class="saving ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                        <span x-text="saving ? '<?php esc_attr_e( 'Guardando…', 'workshop' ); ?>' : '<?php esc_attr_e( 'Registrar movimiento', 'workshop' ); ?>'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
