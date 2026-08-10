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
$currency     = ws_currency_symbol();
?>
<div x-data="wsStock(<?php echo esc_attr( wp_json_encode( array(
    'locations'    => array_map( fn( $l ) => array( 'id' => (int) $l->id, 'name' => $l->name, 'type' => $l->type ), $locations ),
    'currency'     => $currency,
    'canEntry'     => $can_entry,
    'canExit'      => $can_exit,
    'canWriteoff'  => $can_writeoff,
    'canTransfer'  => $can_transfer,
) ) ); ?>)">

    <div class="ws-card">
        <div class="ws-stock-head">
            <div class="ws-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="<?php esc_attr_e( 'Buscar…', 'workshop' ); ?>" x-model="search" @keyup.enter="onSearch()" @input="onSearch()">
            </div>
            <div class="ws-stock-filters">
                <select x-model="locationId" @change="onFilter()">
                    <option value=""><?php esc_html_e( 'Todas las ubicaciones', 'workshop' ); ?></option>
                    <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name + (l.type === 'pv' ? ' (PV)' : '')"></option></template>
                </select>
                <label class="ws-check"><input type="checkbox" x-model="lowOnly" @change="onFilter()"><span><?php esc_html_e( 'Solo stock bajo', 'workshop' ); ?></span></label>
                <template x-if="canAnyMove">
                    <button class="ws-btn ws-btn-primary" @click="openWizard"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Nuevo movimiento', 'workshop' ); ?></button>
                </template>
            </div>
        </div>

        <table class="ws-table">
            <thead>
                <tr>
                    <th class="ws-th-sort" @click="sort('name')"><?php esc_html_e( 'Producto', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('name')"></i></th>
                    <th class="ws-th-sort" @click="sort('location_name')"><?php esc_html_e( 'Ubicación', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('location_name')"></i></th>
                    <th class="ws-th-sort" @click="sort('qty')"><?php esc_html_e( 'Stock', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('qty')"></i></th>
                    <th class="ws-th-sort" @click="sort('min_stock')"><?php esc_html_e( 'Mínimo', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('min_stock')"></i></th>
                    <th class="ws-th-sort" @click="sort('sale_price')"><?php esc_html_e( 'Precio venta', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('sale_price')"></i></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="row in rows" :key="row.product_id + '-' + row.location_id">
                    <tr :class="row.qty <= row.min_stock ? 'ws-row-low' : ''">
                        <td>
                            <div class="ws-cell-product">
                                <div class="ws-thumb"><img x-show="row.image" :src="row.image" :alt="row.name" loading="lazy"><i x-show="!row.image" class="fa-solid fa-box"></i></div>
                                <strong x-text="row.name"></strong>
                            </div>
                        </td>
                        <td><span class="ws-badge" :class="row.location_type === 'pv' ? 'ws-badge-pv' : 'ws-badge-wh'" x-text="row.location_name"></span></td>
                        <td class="ws-strong" :class="row.qty <= row.min_stock ? 'ws-text-danger' : ''" x-text="row.qty"></td>
                        <td x-text="row.min_stock"></td>
                        <td x-text="money(row.sale_price, row.currency)"></td>
                        <td class="ws-actions">
                            <template x-if="canEntry"><button class="ws-icon-btn" title="Entrada" @click="openMove('entrada', row)"><i class="fa-solid fa-down-long"></i></button></template>
                            <template x-if="canExit"><button class="ws-icon-btn" title="Salida" @click="openMove('salida', row)"><i class="fa-solid fa-up-long"></i></button></template>
                            <template x-if="canWriteoff"><button class="ws-icon-btn ws-danger" title="Baja" @click="openMove('baja', row)"><i class="fa-solid fa-trash-can"></i></button></template>
                            <template x-if="canTransfer"><button class="ws-icon-btn" title="Transferencia" @click="openTransfer(row)"><i class="fa-solid fa-arrow-right-arrow-left"></i></button></template>
                        </td>
                    </tr>
                </template>
                <tr x-show="total === 0"><td colspan="6"><p class="ws-empty"><?php esc_html_e( 'Sin resultados.', 'workshop' ); ?></p></td></tr>
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

    <!-- Modal movimiento -->
    <div class="ws-modal" x-show="moveOpen" x-cloak @keydown.escape.window="moveOpen=false">
        <div class="ws-modal-backdrop" @click="moveOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="moveType === 'entrada' ? 'Entrada de stock' : (moveType === 'salida' ? 'Salida de stock' : 'Baja de stock')"></h3>
                <button class="ws-cart-close" @click="moveOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="doMove" class="ws-form">
                <p><strong x-text="moveProduct.name"></strong> <span class="ws-muted" x-text="'(' + moveProduct.barcode + ')'"></span></p>
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
                <p><strong x-text="moveProduct.name"></strong> <span class="ws-muted" x-text="'(' + moveProduct.barcode + ')'"></span></p>
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
                        </select>
                    </label>
                    <label class="ws-field" x-show="wizType !== 'traslado'">
                        <span><?php esc_html_e( 'Ubicación *', 'workshop' ); ?></span>
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
                <div class="ws-search ws-mb">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" placeholder="<?php esc_attr_e( 'Buscar producto…', 'workshop' ); ?>" x-model="wizSearch">
                </div>
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
                                <small x-text="p.barcode"></small>
                            </span>
                            <span class="ws-wiz-stock" x-show="wizType !== 'entrada'"><?php esc_html_e( 'Stock:', 'workshop' ); ?> <b x-text="p.stock"></b></span>
                            <span class="ws-wiz-qty" x-show="p.selected">
                                <input type="number" step="0.01" min="0.01" x-model.number="p.qty" @change="wizQty(p)" :max="wizType !== 'entrada' ? p.stock : null">
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
                    <p class="ws-muted"><strong x-text="typeLabel(wizType)"></strong> <template x-if="wizType === 'traslado'"><span x-text="' · ' + locationName(wizFrom) + ' → ' + locationName(wizTo)"></span></template><template x-else><span x-text="' · ' + locationName(wizLocation)"></span></template></p>
                    <table class="ws-table">
                        <thead><tr><th><?php esc_html_e( 'Producto', 'workshop' ); ?></th><th><?php esc_html_e( 'Cantidad', 'workshop' ); ?></th></tr></thead>
                        <tbody>
                            <template x-for="i in wizItems" :key="i.product_id">
                                <tr><td x-text="i.name"></td><td class="ws-strong" x-text="i.qty"></td></tr>
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
