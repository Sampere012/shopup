<?php
/**
 * Panel: historial de movimientos.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$locations     = ws_user_locations();
$currency      = ws_currency_symbol();
$can_manage    = ws_can( 'stock_writeoff' );
?>
<div x-data="wsMovements(<?php echo esc_attr( wp_json_encode( array(
    'locations' => array_map( fn( $l ) => array( 'id' => (int) $l->id, 'name' => $l->name ), $locations ),
    'currency'  => $currency,
    'canManageStock' => $can_manage,
) ) ); ?>)">

    <div class="ws-card">
        <div class="ws-tabs">
            <button type="button" class="ws-tab" :class="scope === 'products' && 'is-active'" @click="setScope('products')"><i class="fa-solid fa-box"></i> <?php esc_html_e( 'Productos', 'workshop' ); ?></button>
            <button type="button" class="ws-tab" :class="scope === 'combos' && 'is-active'" @click="setScope('combos')"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combos', 'workshop' ); ?></button>
        </div>

        <div class="ws-stock-head">
            <div class="ws-stock-filters">
                <div class="ws-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" placeholder="<?php esc_attr_e( 'Buscar…', 'workshop' ); ?>" x-model="search" @keyup.enter="onSearch()" @input="onSearch()">
                </div>
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
                <input type="date" x-model="dateFrom" @change="onFilter()" aria-label="<?php esc_attr_e( 'Desde', 'workshop' ); ?>" title="<?php esc_attr_e( 'Desde', 'workshop' ); ?>">
                <input type="date" x-model="dateTo" @change="onFilter()" aria-label="<?php esc_attr_e( 'Hasta', 'workshop' ); ?>" title="<?php esc_attr_e( 'Hasta', 'workshop' ); ?>">
            </div>
            <!-- Deshacer / rehacer: revierte o reaplica el último cambio de stock -->
            <div class="ws-undo-actions" x-show="canManageStock" x-cloak>
                <span class="ws-undo-title"><i class="fa-solid fa-clock-rotate-left"></i> <?php esc_html_e( 'Deshacer / rehacer', 'workshop' ); ?></span>
                <button type="button" class="ws-btn ws-btn-sm ws-btn-undo" @click="undo()" :disabled="!canUndo" :title="canUndo ? undoList[0].label : ''">
                    <i class="fa-solid fa-rotate-left"></i> <?php esc_html_e( 'Deshacer', 'workshop' ); ?>
                </button>
                <button type="button" class="ws-btn ws-btn-sm ws-btn-redo" @click="redo()" :disabled="!canRedo" :title="canRedo ? undoList[0].label : ''">
                    <i class="fa-solid fa-rotate-right"></i> <?php esc_html_e( 'Rehacer', 'workshop' ); ?>
                </button>
            </div>
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
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="m in movements" :key="m.id">
                    <tr :class="m.reverted && 'is-reverted'">
                        <td x-text="m.date"></td>
                        <td>
                            <span class="ws-badge" :class="'ws-move-' + m.type" x-text="typeLabel(m.type)"></span>
                            <span class="ws-badge ws-badge-reverted" x-show="m.reverted" title="<?php esc_attr_e( 'Este movimiento fue revertido', 'workshop' ); ?>"><i class="fa-solid fa-rotate-left"></i> <?php esc_html_e( 'Revertido', 'workshop' ); ?></span>
                        </td>
                        <td>
                            <span x-text="m.product_name"></span>
                            <span class="ws-move-combo" x-show="m.combo_name" :title="'<?php esc_attr_e( 'Movimiento del combo', 'workshop' ); ?>'"><i class="fa-solid fa-layer-group"></i> <span x-text="m.combo_name"></span></span>
                        </td>
                        <td x-text="m.location_name || '—'"></td>
                        <td x-text="m.dest_name || '—'"></td>
                        <td class="ws-strong" :class="['entrada','transferencia'].includes(m.type) && m.dest_location_id == 0 ? 'ws-text-success' : (['salida','baja','venta'].includes(m.type) ? 'ws-text-danger' : '')" x-text="m.qty"></td>
                        <td x-text="m.reference || '—'"></td>
                        <td x-text="m.note || '—'"></td>
                        <td x-text="m.user_name || '—'"></td>
                        <td>
                            <template x-if="canManageStock && m.revertable">
                                <button type="button" class="ws-icon-btn ws-icon-btn-revert" :title="'<?php esc_attr_e( 'Revertir este movimiento (aplica la operación inversa al stock)', 'workshop' ); ?>'" @click="revertMovement(m)"><i class="fa-solid fa-rotate-left"></i></button>
                            </template>
                        </td>
                    </tr>
                </template>
                <tr x-show="total === 0"><td colspan="10"><p class="ws-empty"><?php esc_html_e( 'Sin movimientos.', 'workshop' ); ?></p></td></tr>
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

        <!-- Historial de deshacer/rehacer: DEBAJO de la tabla, colapsable -->
        <div class="ws-undo-panel" x-show="canManageStock && undoList.length" x-cloak>
            <div class="ws-undo-panel-head" @click="undoOpen = !undoOpen">
                <span class="ws-undo-title"><i class="fa-solid fa-clock-rotate-left"></i> <?php esc_html_e( 'Historial de deshacer / rehacer', 'workshop' ); ?></span>
                <span class="ws-undo-count" x-text="undoList.length + (undoList.length === 1 ? ' operación' : ' operaciones')"></span>
                <i class="fa-solid" :class="undoOpen ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
            </div>
            <div x-show="undoOpen" x-collapse>
                <template x-for="u in undoList" :key="u.id">
                    <div class="ws-undo-item" :class="u.undone && 'is-undone'">
                        <i class="fa-solid" :class="u.undone ? 'fa-rotate-right' : 'fa-rotate-left'"></i>
                        <span class="ws-undo-label" x-text="u.label"></span>
                        <span class="ws-undo-meta">
                            <span x-text="u.location_name || '—'"></span> · <span x-text="u.user_name"></span> · <span x-text="u.date"></span>
                        </span>
                        <span class="ws-badge" x-show="u.undone"><?php esc_html_e( 'deshecho', 'workshop' ); ?></span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

