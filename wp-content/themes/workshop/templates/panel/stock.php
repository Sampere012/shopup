<?php
/**
 * Panel: stock por ubicación + movimientos (entrada, salida, baja, transferencia).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$locations    = ws_user_locations();
$can_entry    = ws_can( 'stock_entry' );
$can_exit     = ws_can( 'stock_exit' );
$can_writeoff = ws_can( 'stock_writeoff' );
$can_transfer = ws_can( 'stock_transfer' );
$can_venta    = ws_can( 'pos_sell' ) || $can_exit;
$currency     = ws_currency_symbol();
$sellers      = array();
if ( $can_venta && function_exists( 'ws_announcement_business_users' ) ) {
    foreach ( ws_announcement_business_users( ws_current_business_id() ) as $uid ) {
        $u = get_userdata( $uid );
        if ( $u ) {
            $sellers[] = array( 'id' => (int) $uid, 'name' => $u->display_name );
        }
    }
}
?>
<div x-data="wsStock(<?php echo esc_attr( wp_json_encode( array(
    'locations'    => array_map( fn( $l ) => array( 'id' => (int) $l->id, 'name' => $l->name, 'type' => $l->type ), $locations ),
    'currency'     => $currency,
    'canEntry'     => $can_entry,
    'canExit'      => $can_exit,
    'canWriteoff'  => $can_writeoff,
    'canTransfer'  => $can_transfer,
    'canVenta'     => $can_venta,
    'canManageStore' => ws_can( 'products_edit' ),
    'sellers'      => $sellers,
) ) ); ?>)">

    <div class="ws-card">
        <div class="ws-tabs">
            <button type="button" class="ws-tab" :class="stockTab === 'moves' && 'is-active'" @click="setStockTab('moves')"><i class="fa-solid fa-boxes-stacked"></i> <?php esc_html_e( 'Movimientos', 'workshop' ); ?></button>
            <button type="button" class="ws-tab" :class="stockTab === 'count' && 'is-active'" @click="goCount()"><i class="fa-solid fa-list-check"></i> <?php esc_html_e( 'Cuadre', 'workshop' ); ?></button>
            <button type="button" class="ws-tab" :class="stockTab === 'hist' && 'is-active'" @click="goHistory()"><i class="fa-solid fa-clock-rotate-left"></i> <?php esc_html_e( 'Historial cuadre', 'workshop' ); ?></button>
        </div>

        <!-- Pestaña: Movimientos (stock por ubicación + crear movimientos) -->
        <div x-show="stockTab === 'moves'" x-cloak>
        <div class="ws-stock-head">
            <div class="ws-stock-filters">
                <div class="ws-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" placeholder="<?php esc_attr_e( 'Buscar…', 'workshop' ); ?>" x-model="search" @keyup.enter="onSearch()" @input="onSearch()">
                </div>
                <select x-model="locationId" @change="onFilter()">
                    <option value=""><?php esc_html_e( 'Todas las ubicaciones', 'workshop' ); ?></option>
                    <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                </select>
                <label class="ws-check ws-check-pill"><input type="checkbox" x-model="lowOnly" @change="onFilter()"><span><?php esc_html_e( 'Solo stock bajo', 'workshop' ); ?></span></label>
            </div>
            <div class="ws-stock-actions">
                <template x-if="canAnyMove">
                    <button class="ws-btn ws-btn-primary" @click="openWizard"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Nuevo movimiento', 'workshop' ); ?></button>
                </template>
            </div>
        </div>

        <!-- Sub-pestañas del tab Movimientos: productos y combos en tablas
             separadas (más organizado que mezclarlos o mostrarlos en cards). -->
        <div class="ws-stock-subtabs">
            <button type="button" class="ws-tab" :class="stockSub === 'products' && 'is-active'" @click="setStockSub('products')"><i class="fa-solid fa-box"></i> <?php esc_html_e( 'Productos', 'workshop' ); ?></button>
            <button type="button" class="ws-tab" :class="stockSub === 'combos' && 'is-active'" @click="setStockSub('combos')"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combos', 'workshop' ); ?> <small class="ws-muted" x-text="'(' + combos.length + ')'"></small></button>
        </div>

        <!-- Tabla de COMBOS (su propia pestaña): stock derivado por ubicación,
             precio y las mismas acciones que un producto (entrada/salida/baja/
             transferencia + visibilidad en la tienda). -->
        <div x-show="stockSub === 'combos'" x-cloak>
            <table class="ws-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Combo', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Ubicación', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Stock', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Precio venta', 'workshop' ); ?></th>
                        <th title="<?php esc_attr_e( 'Visible en la tienda pública (el stock sigue en el inventario)', 'workshop' ); ?>"><?php esc_html_e( 'Tienda', 'workshop' ); ?></th>
                        <th title="<?php esc_attr_e( 'Visible en el punto de venta (POS) de esta ubicación', 'workshop' ); ?>"><?php esc_html_e( 'POS', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="c in comboRows" :key="c.combo_id + '-' + c.location_id">
                        <tr>
                            <td>
                                <div class="ws-cell-product">
                                    <div class="ws-thumb"><img x-show="c.image" :src="c.image" :alt="c.name" loading="lazy"><i x-show="!c.image" class="fa-solid fa-layer-group"></i></div>
                                    <strong x-text="c.name"></strong>
                                    <span class="ws-combo-badge" x-cloak><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                                </div>
                            </td>
                            <td><span class="ws-badge" x-text="c.location_name"></span></td>
                            <td class="ws-strong" x-text="c.qty"></td>
                            <td x-text="money(c.sale_price, c.currency)"></td>
                            <td class="ws-actions">
                                <template x-if="canManageStore">
                                    <button type="button" class="ws-icon-btn" :class="!c.store_visible && 'ws-icon-btn-muted'" :title="c.store_visible ? '<?php esc_attr_e( 'Visible en la tienda', 'workshop' ); ?>' : '<?php esc_attr_e( 'Oculto de la tienda (sigue en el inventario)', 'workshop' ); ?>'" @click="toggleVisible('store', 'combo', c)">
                                        <i class="fa-solid" :class="c.store_visible ? 'fa-eye' : 'fa-eye-slash'"></i>
                                    </button>
                                </template>
                            </td>
                            <td class="ws-actions">
                                <template x-if="canManageStore">
                                    <button type="button" class="ws-icon-btn ws-icon-btn-pos" :class="!c.pos_visible && 'ws-icon-btn-muted'" :title="c.pos_visible ? '<?php esc_attr_e( 'Visible en el POS', 'workshop' ); ?>' : '<?php esc_attr_e( 'Oculto del POS (sigue en el inventario)', 'workshop' ); ?>'" @click="toggleVisible('pos', 'combo', c)">
                                        <i class="fa-solid" :class="c.pos_visible ? 'fa-cash-register' : 'fa-eye-slash'"></i>
                                    </button>
                                </template>
                            </td>
                            <td class="ws-actions">
                                <template x-if="canEntry"><button class="ws-icon-btn" title="<?php esc_attr_e( 'Entrada', 'workshop' ); ?>" @click="openMove('entrada', c)"><i class="fa-solid fa-down-long"></i></button></template>
                                <template x-if="canExit"><button class="ws-icon-btn" title="<?php esc_attr_e( 'Salida', 'workshop' ); ?>" @click="openMove('salida', c)"><i class="fa-solid fa-up-long"></i></button></template>
                                <template x-if="canWriteoff"><button class="ws-icon-btn ws-danger" title="<?php esc_attr_e( 'Baja', 'workshop' ); ?>" @click="openMove('baja', c)"><i class="fa-solid fa-trash-can"></i></button></template>
                                <template x-if="canWriteoff"><button class="ws-icon-btn ws-icon-btn-clean" title="<?php esc_attr_e( 'Limpiar (elimina el registro de stock por completo, no queda en 0)', 'workshop' ); ?>" @click="cleanStock('combo', c)"><i class="fa-solid fa-eraser"></i></button></template>
                                <template x-if="canTransfer"><button class="ws-icon-btn" title="<?php esc_attr_e( 'Transferencia', 'workshop' ); ?>" @click="openTransfer(c)"><i class="fa-solid fa-arrow-right-arrow-left"></i></button></template>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="comboRows.length === 0"><td colspan="7"><p class="ws-empty"><?php esc_html_e( 'Sin combos.', 'workshop' ); ?></p></td></tr>
                </tbody>
            </table>
        </div>

        <table class="ws-table" x-show="stockSub === 'products'" x-cloak>
            <thead>
                <tr>
                    <th class="ws-th-sort" @click="sort('name')"><?php esc_html_e( 'Producto', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('name')"></i></th>
                    <th class="ws-th-sort" @click="sort('location_name')"><?php esc_html_e( 'Ubicación', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('location_name')"></i></th>
                    <th class="ws-th-sort" @click="sort('qty')"><?php esc_html_e( 'Stock', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('qty')"></i></th>
                    <th title="<?php esc_attr_e( 'Suma del stock en todas las ubicaciones conectadas (stock compartido)', 'workshop' ); ?>"><?php esc_html_e( 'Stock grupo', 'workshop' ); ?> <i class="fa-solid fa-share-nodes ws-muted"></i></th>
                    <th class="ws-th-sort" @click="sort('min_stock')"><?php esc_html_e( 'Mínimo', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('min_stock')"></i></th>
                    <th class="ws-th-sort" @click="sort('sale_price')"><?php esc_html_e( 'Precio venta', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('sale_price')"></i></th>
                    <th title="<?php esc_attr_e( 'Visible en la tienda pública (el stock sigue en el inventario)', 'workshop' ); ?>"><?php esc_html_e( 'Tienda', 'workshop' ); ?></th>
                    <th title="<?php esc_attr_e( 'Visible en el punto de venta (POS) de esta ubicación', 'workshop' ); ?>"><?php esc_html_e( 'POS', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="row in rows" :key="row.product_id + '-' + row.location_id">
                    <tr :class="row.group_total <= row.min_stock ? 'ws-row-low' : ''">
                        <td>
                            <div class="ws-cell-product">
                                <div class="ws-thumb"><img x-show="row.image" :src="row.image" :alt="row.name" loading="lazy"><i x-show="!row.image" class="fa-solid fa-box"></i></div>
                                <strong x-text="row.name"></strong>
                            </div>
                        </td>
                        <td>
                            <span class="ws-badge" :class="row.location_type === 'pv' ? 'ws-badge-pv' : 'ws-badge-wh'" x-text="row.location_name"></span>
                            <small class="ws-loc-desc" x-show="row.location_description" x-text="row.location_description"></small>
                        </td>
                        <td>
                            <span class="ws-strong" :class="row.min_stock > 0 && row.group_total <= row.min_stock ? 'ws-text-danger' : ''" x-text="row.qty"></span>
                            <span class="ws-badge ws-badge-low" x-show="row.min_stock > 0 && row.group_total <= row.min_stock" x-cloak><i class="fa-solid fa-triangle-exclamation"></i> <?php esc_html_e( 'Bajo', 'workshop' ); ?></span>
                        </td>
                        <td>
                            <template x-if="row.group_parts && row.group_parts.length > 1">
                                <span class="ws-group-badge" :title="groupTitle(row)">
                                    <i class="fa-solid fa-share-nodes"></i>
                                    <b x-text="row.group_total"></b>
                                    <small x-text="'· ' + row.group_parts.length + ' ubs.'"></small>
                                </span>
                            </template>
                            <span x-show="!(row.group_parts && row.group_parts.length > 1)" class="ws-muted" x-text="row.group_total"></span>
                        </td>
                        <td x-text="row.min_stock"></td>
                        <td x-text="money(row.sale_price, row.currency)"></td>
                        <td class="ws-actions">
                            <template x-if="canManageStore">
                                <button type="button" class="ws-icon-btn" :class="!row.store_visible && 'ws-icon-btn-muted'" :title="row.store_visible ? '<?php esc_attr_e( 'Visible en la tienda', 'workshop' ); ?>' : '<?php esc_attr_e( 'Oculto de la tienda (sigue en el inventario)', 'workshop' ); ?>'" @click="toggleVisible('store', 'product', row)">
                                    <i class="fa-solid" :class="row.store_visible ? 'fa-eye' : 'fa-eye-slash'"></i>
                                </button>
                            </template>
                        </td>
                        <td class="ws-actions">
                            <template x-if="canManageStore">
                                <button type="button" class="ws-icon-btn ws-icon-btn-pos" :class="!row.pos_visible && 'ws-icon-btn-muted'" :title="row.pos_visible ? '<?php esc_attr_e( 'Visible en el POS', 'workshop' ); ?>' : '<?php esc_attr_e( 'Oculto del POS (sigue en el inventario)', 'workshop' ); ?>'" @click="toggleVisible('pos', 'product', row)">
                                    <i class="fa-solid" :class="row.pos_visible ? 'fa-cash-register' : 'fa-eye-slash'"></i>
                                </button>
                            </template>
                        </td>
                        <td class="ws-actions">
                            <template x-if="canEntry"><button class="ws-icon-btn" title="Entrada" @click="openMove('entrada', row)"><i class="fa-solid fa-down-long"></i></button></template>
                            <template x-if="canExit"><button class="ws-icon-btn" title="Salida" @click="openMove('salida', row)"><i class="fa-solid fa-up-long"></i></button></template>
                            <template x-if="canWriteoff"><button class="ws-icon-btn ws-danger" title="Baja" @click="openMove('baja', row)"><i class="fa-solid fa-trash-can"></i></button></template>
                            <template x-if="canWriteoff"><button class="ws-icon-btn ws-icon-btn-clean" title="Limpiar (elimina el registro de stock por completo, no queda en 0)" @click="cleanStock('product', row)"><i class="fa-solid fa-eraser"></i></button></template>
                            <template x-if="canTransfer"><button class="ws-icon-btn" title="Transferencia" @click="openTransfer(row)"><i class="fa-solid fa-arrow-right-arrow-left"></i></button></template>
                        </td>
                    </tr>
                </template>
                <tr x-show="total === 0"><td colspan="9"><p class="ws-empty"><?php esc_html_e( 'Sin resultados.', 'workshop' ); ?></p></td></tr>
            </tbody>
        </table>
        <div class="ws-pagination" x-show="stockSub === 'products' && total > pageSize">
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

        <!-- Pestaña: Cuadre de inventario (físico vs virtual) -->
        <div x-show="stockTab === 'count'" x-cloak>
            <p class="ws-muted" style="margin-top:0"><?php esc_html_e( 'Escribe el stock FÍSICO (lo que contaste) de cada producto; la app lo compara con el VIRTUAL (lo que dice la app) y te marca sobrantes y faltantes. Puedes corregir el stock automáticamente para que quede al 100%.', 'workshop' ); ?></p>
            <div class="ws-form-grid" style="margin-bottom:12px">
                <label class="ws-field">
                    <span><?php esc_html_e( 'Ubicación *', 'workshop' ); ?></span>
                    <select x-model="count.location_id" @change="loadCountVirtual()" required>
                        <option value="">— <?php esc_html_e( 'Seleccionar', 'workshop' ); ?> —</option>
                        <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                    </select>
                </label>
                <label class="ws-field">
                    <span><?php esc_html_e( 'Nota (opcional)', 'workshop' ); ?></span>
                    <input type="text" x-model="count.note" placeholder="<?php esc_attr_e( 'Ej.: conteo semanal', 'workshop' ); ?>">
                </label>
            </div>

            <div class="ws-cash-cuadre-summary" style="margin-bottom:12px">
                <span><i class="fa-solid fa-circle-check ws-text-success"></i> <?php esc_html_e( 'Cuadrados', 'workshop' ); ?>: <b x-text="countStats.cuadrados"></b></span>
                <span><i class="fa-solid fa-plus-circle ws-text-success"></i> <?php esc_html_e( 'Sobrantes', 'workshop' ); ?>: <b x-text="countStats.sobrante"></b></span>
                <span><i class="fa-solid fa-minus-circle ws-text-danger"></i> <?php esc_html_e( 'Faltantes', 'workshop' ); ?>: <b x-text="countStats.faltante"></b></span>
            </div>

            <div x-show="countLoading" class="ws-empty"><i class="fa-solid fa-spinner fa-spin"></i> <?php esc_html_e( 'Cargando stock virtual…', 'workshop' ); ?></div>
            <div class="ws-search ws-mb" x-show="!countLoading">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="<?php esc_attr_e( 'Buscar producto…', 'workshop' ); ?>" x-model="countSearch">
            </div>
            <div x-show="!countLoading && countFiltered.length === 0">
                <p class="ws-empty"><?php esc_html_e( 'No hay productos en esta ubicación.', 'workshop' ); ?></p>
                <button type="button" class="ws-btn ws-btn-secondary" @click="loadCountVirtual()"><i class="fa-solid fa-rotate"></i> <?php esc_html_e( 'Recargar', 'workshop' ); ?></button>
            </div>
            <table class="ws-items-table" x-show="!countLoading && countFiltered.length > 0">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Producto', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Virtual (app)', 'workshop' ); ?></th>
                        <th title="<?php esc_attr_e( 'Suma del stock en todas las ubicaciones conectadas (stock compartido)', 'workshop' ); ?>"><?php esc_html_e( 'Stock grupo', 'workshop' ); ?> <i class="fa-solid fa-share-nodes ws-muted"></i></th>
                        <th><?php esc_html_e( 'Físico (contado)', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Dif.', 'workshop' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="c in countFiltered" :key="c.product_id">
                        <tr>
                            <td><strong x-text="c.name"></strong></td>
                            <td x-text="c.virtual_qty"></td>
                            <td>
                                <template x-if="c.group_parts && c.group_parts.length > 1">
                                    <span class="ws-group-badge" :title="groupTitle(c)">
                                        <i class="fa-solid fa-share-nodes"></i>
                                        <b x-text="c.group_total"></b>
                                        <small x-text="'· ' + c.group_parts.length + ' ubs.'"></small>
                                    </span>
                                </template>
                                <span x-show="!(c.group_parts && c.group_parts.length > 1)" class="ws-muted" x-text="c.group_total"></span>
                            </td>
                            <td><input type="number" step="0.01" min="0" x-model.number="c.physical" class="ws-count-input"></td>
                            <td>
                                <span class="ws-badge" :class="countDiff(c) > 0.004 ? 'ws-badge-completed' : (countDiff(c) < -0.004 ? 'ws-badge-cancelled' : 'ws-badge-pending')" x-text="countDiffText(c)"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div class="ws-stock-count-foot">
                <label class="ws-check">
                    <input type="checkbox" x-model="count.adjust">
                    <span><strong><?php esc_html_e( 'Corregir stock automáticamente', 'workshop' ); ?></strong> — <?php esc_html_e( 'la app ajusta el inventario a lo físico (entrada/salida de ajuste por producto con diferencia).', 'workshop' ); ?></span>
                </label>
                <button type="button" class="ws-btn ws-btn-primary" @click="saveCount()" :disabled="countSaving || countItems.length === 0">
                    <i class="fa-solid" :class="countSaving ? 'fa-spinner fa-spin' : 'fa-floppy-disk'"></i>
                    <span x-text="countSaving ? '<?php esc_attr_e( 'Guardando…', 'workshop' ); ?>' : '<?php esc_attr_e( 'Guardar cuadre', 'workshop' ); ?>'"></span>
                </button>
            </div>
        </div>

        <!-- Pestaña: Historial de cuadres -->
        <div x-show="stockTab === 'hist'" x-cloak>
            <template x-if="countsHistLoading">
                <p class="ws-empty"><i class="fa-solid fa-spinner fa-spin"></i> <?php esc_html_e( 'Cargando…', 'workshop' ); ?></p>
            </template>
            <template x-if="!countsHistLoading && countsHist.length === 0">
                <p class="ws-empty"><?php esc_html_e( 'Aún no hay cuadres guardados.', 'workshop' ); ?></p>
            </template>
            <template x-for="h in countsHist" :key="h.id">
                <div class="ws-card" style="margin-bottom:10px;padding:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                        <strong>#<span x-text="h.id"></span> · <span x-text="h.location_name"></span></strong>
                        <span class="ws-muted" x-text="h.created_at"></span>
                    </div>
                    <div class="ws-muted" x-text="h.summary"></div>
                    <div x-show="h.note" class="ws-muted" x-text="'✎ ' + h.note"></div>
                    <div style="margin-top:8px">
                        <span class="ws-badge ws-badge-completed" x-show="h.adjusted"><i class="fa-solid fa-wand-magic-sparkles"></i> <?php esc_html_e( 'Stock corregido', 'workshop' ); ?></span>
                        <button type="button" class="ws-btn ws-btn-secondary" style="margin-left:6px" @click="viewCountDetail(h)"><i class="fa-solid fa-eye"></i> <?php esc_html_e( 'Ver detalle', 'workshop' ); ?></button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Modal movimiento -->
    <div class="ws-modal" x-show="moveOpen" x-cloak @keydown.escape.window="moveOpen=false">
        <div class="ws-modal-backdrop" @click="moveOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="moveType === 'entrada' ? 'Entrada de stock' : (moveType === 'salida' ? 'Salida de stock' : 'Baja de stock')"></h3>
                <button class="ws-cart-close" @click="moveOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="doMove" class="ws-form">
                <p><strong x-text="moveProduct.name"></strong> <span class="ws-muted" x-show="moveProduct.barcode" x-text="'(' + moveProduct.barcode + ')'"></span> <span class="ws-combo-badge" x-show="moveProduct.is_combo" x-cloak><i class="fa-solid fa-layer-group"></i> Combo</span></p>
                <div class="ws-form-grid">
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Ubicación', 'workshop' ); ?></span>
                        <select x-model="move.location_id" required>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Cantidad *', 'workshop' ); ?></span>
                        <input type="number" step="0.01" min="0.01" x-model="move.qty" required>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Referencia', 'workshop' ); ?></span>
                        <input type="text" x-model="move.reference" placeholder="Factura #…">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Nota', 'workshop' ); ?></span>
                        <input type="text" x-model="move.note">
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="moveOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-check"></i> <?php esc_html_e( 'Confirmar', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal transferencia -->
    <div class="ws-modal" x-show="transferOpen" x-cloak @keydown.escape.window="transferOpen=false">
        <div class="ws-modal-backdrop" @click="transferOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3><?php esc_html_e( 'Transferencia', 'workshop' ); ?></h3>
                <button class="ws-cart-close" @click="transferOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="doTransfer" class="ws-form">
                <p><strong x-text="moveProduct.name"></strong> <span class="ws-muted" x-show="moveProduct.barcode" x-text="'(' + moveProduct.barcode + ')'"></span> <span class="ws-combo-badge" x-show="moveProduct.is_combo" x-cloak><i class="fa-solid fa-layer-group"></i> Combo</span></p>
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Origen', 'workshop' ); ?></span>
                        <select x-model="transfer.from_location" required>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Destino', 'workshop' ); ?></span>
                        <select x-model="transfer.to_location" required>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Cantidad *', 'workshop' ); ?></span>
                        <input type="number" step="0.01" min="0.01" x-model="transfer.qty" required>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Nota', 'workshop' ); ?></span>
                        <input type="text" x-model="transfer.note">
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="transferOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-arrow-right-arrow-left"></i> <?php esc_html_e( 'Transferir', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal detalle de un cuadre guardado -->
    <div class="ws-modal" x-show="countDetailOpen" x-cloak @keydown.escape.window="countDetailOpen=false">
        <div class="ws-modal-backdrop" @click="countDetailOpen=false"></div>
        <div class="ws-modal-box ws-modal-lg">
            <div class="ws-modal-head">
                <h3><i class="fa-solid fa-list-check"></i> <?php esc_html_e( 'Detalle del cuadre', 'workshop' ); ?> #<span x-text="countDetail.id"></span></h3>
                <button class="ws-cart-close" @click="countDetailOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="ws-modal-body">
                <template x-if="countDetail.items.length === 0">
                    <p class="ws-empty"><?php esc_html_e( 'Sin productos en este cuadre.', 'workshop' ); ?></p>
                </template>
                <table class="ws-items-table" x-show="countDetail.items.length > 0">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Producto', 'workshop' ); ?></th>
                            <th><?php esc_html_e( 'Virtual (app)', 'workshop' ); ?></th>
                            <th><?php esc_html_e( 'Stock grupo', 'workshop' ); ?></th>
                            <th><?php esc_html_e( 'Físico (contado)', 'workshop' ); ?></th>
                            <th><?php esc_html_e( 'Diferencia', 'workshop' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in countDetail.items" :key="c.product_id">
                            <tr>
                                <td x-text="c.name"></td>
                                <td x-text="c.virtual_qty"></td>
                                <td>
                                    <template x-if="c.group_parts && c.group_parts.length > 1">
                                        <span class="ws-group-badge" :title="groupTitle(c)">
                                            <i class="fa-solid fa-share-nodes"></i>
                                            <b x-text="c.group_total"></b>
                                            <small x-text="'· ' + c.group_parts.length + ' ubs.'"></small>
                                        </span>
                                    </template>
                                    <span x-show="!(c.group_parts && c.group_parts.length > 1)" class="ws-muted" x-text="c.group_total"></span>
                                </td>
                                <td x-text="c.physical_qty"></td>
                                <td>
                                    <span class="ws-badge" :class="c.diff > 0.004 ? 'ws-badge-completed' : (c.diff < -0.004 ? 'ws-badge-cancelled' : 'ws-badge-pending')" x-text="(c.diff > 0 ? '+' : '') + c.diff"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo movimiento (asistente) -->
    <div class="ws-modal" x-show="wizOpen" x-cloak @keydown.escape.window="wizOpen=false">
        <div class="ws-modal-backdrop" @click="wizOpen=false"></div>
        <div class="ws-modal-box ws-modal-wide">
            <div class="ws-modal-head">
                <h3 x-text="wizStep === 1 ? 'Nuevo movimiento' : (wizStep === 2 ? 'Selecciona productos' : 'Confirmar movimiento')"></h3>
                <button class="ws-cart-close" @click="wizOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Paso 1: tipo + ubicación -->
            <div x-show="wizStep === 1">
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Tipo de movimiento *', 'workshop' ); ?></span>
                        <select x-model="wizType">
                            <template x-if="canEntry"><option value="entrada"><?php esc_html_e( 'Entrada', 'workshop' ); ?></option></template>
                            <template x-if="canExit"><option value="salida"><?php esc_html_e( 'Salida', 'workshop' ); ?></option></template>
                            <template x-if="canWriteoff"><option value="baja"><?php esc_html_e( 'Baja', 'workshop' ); ?></option></template>
                            <template x-if="canTransfer"><option value="traslado"><?php esc_html_e( 'Traslado', 'workshop' ); ?></option></template>
                            <template x-if="canVenta"><option value="venta"><?php esc_html_e( 'Venta (registra en Ventas POS)', 'workshop' ); ?></option></template>
                            <option value="otro"><?php esc_html_e( 'Otro (personalizado)', 'workshop' ); ?></option>
                        </select>
                    </label>
                    <label class="ws-field" x-show="wizType === 'otro'">
                        <span><?php esc_html_e( 'Tipo personalizado *', 'workshop' ); ?></span>
                        <input type="text" x-model="wizCustomType" maxlength="30" placeholder="<?php esc_attr_e( 'Ej. merma, ajuste, devolución…', 'workshop' ); ?>">
                    </label>
                    <label class="ws-field" x-show="wizType === 'otro'">
                        <span><?php esc_html_e( 'Dirección *', 'workshop' ); ?></span>
                        <select x-model="wizDirection">
                            <option value="entrada"><?php esc_html_e( 'Entrada (aumenta stock)', 'workshop' ); ?></option>
                            <option value="salida"><?php esc_html_e( 'Salida (disminuye stock)', 'workshop' ); ?></option>
                        </select>
                    </label>
                    <label class="ws-field" x-show="wizType === 'venta'">
                        <span><?php esc_html_e( 'Vendedor *', 'workshop' ); ?></span>
                        <select x-model="wizSeller" required>
                            <option value="">— <?php esc_html_e( 'Seleccionar', 'workshop' ); ?> —</option>
                            <template x-for="s in sellers" :key="s.id"><option :value="s.id" x-text="s.name"></option></template>
                        </select>
                    </label>
                    <label class="ws-field" x-show="wizType !== 'traslado' && wizType !== 'venta'">
                        <span><?php esc_html_e( 'Ubicación *', 'workshop' ); ?></span>
                        <select x-model="wizLocation" required>
                            <option value="">— <?php esc_html_e( 'Seleccionar', 'workshop' ); ?> —</option>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                        </select>
                    </label>
                    <label class="ws-field" x-show="wizType === 'venta'">
                        <span><?php esc_html_e( 'PV / Ubicación *', 'workshop' ); ?></span>
                        <select x-model="wizLocation" required>
                            <option value="">— <?php esc_html_e( 'Seleccionar', 'workshop' ); ?> —</option>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                        </select>
                    </label>
                    <template x-if="wizType === 'traslado'">
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Ubicación origen *', 'workshop' ); ?></span>
                            <select x-model="wizFrom" required>
                                <option value="">— <?php esc_html_e( 'Seleccionar', 'workshop' ); ?> —</option>
                                <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                            </select>
                        </label>
                    </template>
                    <template x-if="wizType === 'traslado'">
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Ubicación destino *', 'workshop' ); ?></span>
                            <select x-model="wizTo" required>
                                <option value="">— <?php esc_html_e( 'Seleccionar', 'workshop' ); ?> —</option>
                                <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                            </select>
                        </label>
                    </template>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="wizOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="button" class="ws-btn ws-btn-primary" @click="wizNext"><i class="fa-solid fa-arrow-right"></i> <?php esc_html_e( 'Continuar', 'workshop' ); ?></button>
                </div>
            </div>

            <!-- Paso 2: productos -->
            <div x-show="wizStep === 2">
                <div class="ws-search ws-mb" style="margin-bottom:0">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" placeholder="<?php esc_attr_e( 'Buscar producto…', 'workshop' ); ?>" x-model="wizSearch">
                </div>
                <label class="ws-check ws-check-pill" style="margin:8px 0 10px" title="<?php esc_attr_e( 'Muestra únicamente los combos', 'workshop' ); ?>"><input type="checkbox" x-model="wizCombosOnly"><span><?php esc_html_e( 'Solo combos', 'workshop' ); ?></span></label>
                <div x-show="wizLoading" class="ws-empty"><?php esc_html_e( 'Cargando…', 'workshop' ); ?></div>
                <div class="ws-wiz-list" x-show="!wizLoading">
                    <template x-if="wizProducts.length === 0">
                        <p class="ws-empty"><?php esc_html_e( 'No hay productos disponibles en esta ubicación.', 'workshop' ); ?></p>
                    </template>
                    <template x-for="p in wizFiltered" :key="p.product_id">
                        <label class="ws-wiz-item">
                            <input type="checkbox" :checked="p.selected" @change="toggleWiz(p)">
                            <span class="ws-wiz-thumb"><img x-show="p.image" :src="p.image" alt=""><i x-show="!p.image" class="fa-solid fa-box"></i></span>
                            <span class="ws-wiz-info">
                                <strong x-text="p.name"></strong>
                                <span class="ws-combo-badge" x-show="p.is_combo" x-cloak title="<?php esc_attr_e( 'Este producto es un combo', 'workshop' ); ?>"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combo', 'workshop' ); ?></span>
                                <small x-text="p.barcode"></small>
                            </span>
                            <span class="ws-wiz-stock" x-show="wizDecreases"><?php esc_html_e( 'Stock:', 'workshop' ); ?> <b x-text="p.stock"></b></span>
                            <span class="ws-wiz-price" x-show="wizType === 'venta' && p.selected">
                                <input type="number" step="0.01" min="0" x-model.number="p.price" @change="wizPrice(p)" placeholder="<?php esc_attr_e( 'Precio', 'workshop' ); ?>">
                            </span>
                            <span class="ws-wiz-qty" x-show="p.selected">
                                <input type="number" step="0.01" min="0.01" x-model.number="p.qty" @change="wizQty(p)" :max="wizDecreases ? p.stock : null">
                            </span>
                        </label>
                    </template>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="wizBack"><i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Atrás', 'workshop' ); ?></button>
                    <span class="ws-wiz-count" x-text="wizSelectedCount + ' producto(s) seleccionado(s)'"></span>
                    <button type="button" class="ws-btn ws-btn-primary" @click="wizNext"><i class="fa-solid fa-arrow-right"></i> <?php esc_html_e( 'Continuar', 'workshop' ); ?></button>
                </div>
            </div>

            <!-- Paso 3: confirmar -->
            <div x-show="wizStep === 3">
                <div class="ws-wiz-summary">
                    <p class="ws-muted"><strong x-text="typeLabel(wizType)"></strong> <template x-if="wizType === 'traslado'"><span x-text="' · ' + locationName(wizFrom) + ' → ' + locationName(wizTo)"></span></template><template x-else><span x-text="' · ' + locationName(wizLocation)"></span></template><template x-if="wizType === 'venta'"><span x-text="' · ' + sellerName(wizSeller)"></span></template></p>
                    <table class="ws-table">
                        <thead><tr><th><?php esc_html_e( 'Producto', 'workshop' ); ?></th><th x-show="wizType === 'venta'"><?php esc_html_e( 'Precio', 'workshop' ); ?></th><th><?php esc_html_e( 'Cantidad', 'workshop' ); ?></th><th x-show="wizType === 'venta'"><?php esc_html_e( 'Total', 'workshop' ); ?></th></tr></thead>
                        <tbody>
                            <template x-for="i in wizItems" :key="i.product_id">
                                <tr><td x-text="i.name"></td><td x-show="wizType === 'venta'" x-text="money(i.price, currency)"></td><td class="ws-strong" x-text="i.qty"></td><td x-show="wizType === 'venta'" class="ws-strong" x-text="money((Number(i.qty) || 0) * (Number(i.price) || 0), currency)"></td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="ws-form-grid">
                    <label class="ws-field" x-show="wizType !== 'traslado'">
                        <span><?php esc_html_e( 'Referencia', 'workshop' ); ?></span>
                        <input type="text" x-model="wizRef" placeholder="Factura #…">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Nota', 'workshop' ); ?></span>
                        <input type="text" x-model="wizNote">
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="wizBack"><i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Atrás', 'workshop' ); ?></button>
                    <button type="button" class="ws-btn ws-btn-primary" @click="wizSubmit"><i class="fa-solid fa-check"></i> <?php esc_html_e( 'Confirmar', 'workshop' ); ?></button>
                </div>
            </div>
        </div>
    </div>

</div>
