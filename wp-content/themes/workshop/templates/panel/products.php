<?php
/**
 * Panel: productos (CRUD + bulk).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$suppliers = WS_CRUD::get_suppliers();
$products  = WS_CRUD::get_products();
$can_create = ws_can( 'products_create' );
$can_edit   = ws_can( 'products_edit' );
$can_delete = ws_can( 'products_delete' );
$can_bulk   = ws_can( 'products_bulk' );
$currency   = ws_currency_symbol();
$currencies = ws_currencies();
$can_fraction = ws_can( 'products_fraction' );
// Categorías en ÁRBOL: selector con indentación por nivel (Padre / Hijo / Nieto).
$cat_payload = function_exists( 'ws_categories_payload' ) ? ws_categories_payload() : array( 'flat' => array() );
$categories  = $cat_payload['flat'];
// Las categorías viven como PESTAÑA dentro de Productos (solo quien las gestiona).
$can_categories = ws_can( 'categories_manage' );
// Los proveedores también viven como PESTAÑA dentro de Productos (quien los ve).
$can_suppliers = ws_can( 'suppliers_view' );

// Estado del plan: límite de productos palpable en la pantalla de creación.
$plan_status = ws_plan_limit_status( 'products' );
$plan_full   = ! empty( $plan_status['full'] );
$plan_pct    = (int) $plan_status['pct'];
$plan_name   = '';
$upgrade_url = '';
$sub_data    = ws_subscription_data();
if ( ! empty( $sub_data['plan'] ) ) {
    $plan_name = $sub_data['plan']->name;
}
$upgrade_url = ws_panel_url( 'owner', 'plan' );
?>
<div x-data="wsProducts(<?php echo esc_attr( wp_json_encode( array(
    'suppliers'   => $suppliers,
    'currency'    => $currency,
    'currencies'  => $currencies,
    'categories'  => $categories,
    'locations'   => WS_CRUD::get_locations(),
    'canEdit'     => $can_edit,
    'canDelete'   => $can_delete,
    'canCreate'   => $can_create,
    'canFraction' => $can_fraction,
    'canTransfer' => ws_can( 'stock_transfer' ),
) ) ); ?>)">

    <div class="ws-tabs">
        <button type="button" class="ws-tab" :class="tab === 'products' && 'is-active'" @click="setTab('products')"><i class="fa-solid fa-boxes-stacked"></i> <?php esc_html_e( 'Productos', 'workshop' ); ?></button>
        <button type="button" class="ws-tab" :class="tab === 'combos' && 'is-active'" @click="setTab('combos')"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Combos', 'workshop' ); ?></button>
        <?php if ( $can_categories ) : ?>
        <button type="button" class="ws-tab" :class="tab === 'categories' && 'is-active'" @click="setTab('categories')"><i class="fa-solid fa-sitemap"></i> <?php esc_html_e( 'Categorías', 'workshop' ); ?></button>
        <?php endif; ?>
        <?php if ( $can_suppliers ) : ?>
        <button type="button" class="ws-tab" :class="tab === 'suppliers' && 'is-active'" @click="setTab('suppliers')"><i class="fa-solid fa-truck-field"></i> <?php esc_html_e( 'Proveedores', 'workshop' ); ?></button>
        <?php endif; ?>
        <button type="button" class="ws-tab" :class="tab === 'history' && 'is-active'" @click="setTab('history')"><i class="fa-solid fa-chart-line"></i> <?php esc_html_e( 'Historial de precios', 'workshop' ); ?></button>
    </div>

    <div x-show="tab === 'products'">
        <?php if ( (int) $plan_status['limit'] > 0 ) : ?>
        <div class="ws-plan-banner<?php echo $plan_full ? ' is-over' : ( $plan_pct >= 80 ? ' is-warn' : '' ); ?>" role="status">
            <div class="ws-plan-banner-head">
                <span class="ws-plan-banner-label">
                    <i class="fa-solid fa-boxes-stacked"></i>
                    <?php esc_html_e( 'Plan', 'workshop' ); ?> <strong><?php echo esc_html( $plan_name ); ?></strong> —
                    <?php echo esc_html( sprintf(
                        /* translators: 1: usados, 2: límite */
                        __( '%1$d de %2$s %3$s', 'workshop' ),
                        (int) $plan_status['used'],
                        (int) $plan_status['limit'] > 0 ? number_format_i18n( (int) $plan_status['limit'] ) : '∞',
                        ( 1 === (int) $plan_status['used'] ) ? __( 'producto', 'workshop' ) : __( 'productos', 'workshop' )
                    ) ); ?>
                </span>
                <a class="ws-link" href="<?php echo esc_url( $upgrade_url ); ?>"><?php esc_html_e( 'Ver mi plan', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></a>
            </div>
            <div class="ws-usage-track"><div class="ws-usage-fill" style="width:<?php echo esc_attr( (string) $plan_pct ); ?>%"></div></div>
            <?php if ( $plan_full ) : ?>
                <p class="ws-plan-banner-msg"><i class="fa-solid fa-triangle-exclamation"></i>
                    <?php esc_html_e( 'Alcanzaste el límite de productos de tu plan. No puedes crear ni importar más productos hasta que elimines algunos o solicites un upgrade.', 'workshop' ); ?>
                    <a class="ws-link" href="<?php echo esc_url( $upgrade_url ); ?>"><?php esc_html_e( 'Solicitar upgrade', 'workshop' ); ?> <i class="fa-solid fa-arrow-up-right-dots"></i></a>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="ws-toolbar">
            <div class="ws-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="<?php esc_attr_e( 'Buscar…', 'workshop' ); ?>" x-model="search" @input="onSearch()">
            </div>
            <div class="ws-toolbar-actions">
                <?php if ( $can_bulk && ! $plan_full ) : ?>
                    <button class="ws-btn ws-btn-secondary" @click="importModal = true"><i class="fa-solid fa-file-import"></i> <?php esc_html_e( 'Importar CSV', 'workshop' ); ?></button>
                <?php elseif ( $can_bulk && $plan_full ) : ?>
                    <button class="ws-btn ws-btn-secondary" disabled title="<?php esc_attr_e( 'Alcanzaste el límite de productos de tu plan.', 'workshop' ); ?>"><i class="fa-solid fa-file-import"></i> <?php esc_html_e( 'Importar CSV', 'workshop' ); ?></button>
                <?php endif; ?>
                <?php if ( $can_create && ! $plan_full ) : ?>
                    <button class="ws-btn ws-btn-primary" @click="openForm()"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Nuevo producto', 'workshop' ); ?></button>
                <?php elseif ( $can_create && $plan_full ) : ?>
                    <a class="ws-btn ws-btn-primary" href="<?php echo esc_url( $upgrade_url ); ?>" title="<?php esc_attr_e( 'Alcanzaste el límite de productos de tu plan.', 'workshop' ); ?>"><i class="fa-solid fa-arrow-up-right-dots"></i> <?php esc_html_e( 'Solicitar upgrade', 'workshop' ); ?></a>
                <?php endif; ?>
            </div>
        </div>

        <div class="ws-card">
            <div class="ws-bulk-bar" x-show="canEdit && selected.length > 0" x-cloak>
                <span class="ws-bulk-count"><i class="fa-solid fa-check-double"></i> <b x-text="selected.length"></b> <?php esc_html_e( 'seleccionados', 'workshop' ); ?></span>
                <button type="button" class="ws-btn ws-btn-secondary" @click="toggleAllResults()" x-show="selected.length < total" title="<?php esc_attr_e( 'Selecciona todos los productos que coinciden con la búsqueda actual, en todas las páginas', 'workshop' ); ?>"><i class="fa-solid fa-list-check"></i> <?php esc_html_e( 'Seleccionar todos los resultados', 'workshop' ); ?></button>
                <span class="ws-bulk-spacer"></span>
                <button type="button" class="ws-btn ws-btn-secondary" @click="clearSelection()"><i class="fa-solid fa-xmark"></i> <?php esc_html_e( 'Limpiar', 'workshop' ); ?></button>
                <button type="button" class="ws-btn ws-btn-primary" @click="openBulkEdit()"><i class="fa-solid fa-wand-magic-sparkles"></i> <?php esc_html_e( 'Editar en lote', 'workshop' ); ?></button>
            </div>
            <table class="ws-table">
                <thead>
                    <tr>
                        <template x-if="canEdit">
                            <th class="ws-th-check"><input type="checkbox" :checked="allPageSelected" @change="togglePage($event.target.checked)" title="<?php esc_attr_e( 'Seleccionar los de esta página', 'workshop' ); ?>"></th>
                        </template>
                        <th class="ws-th-sort" @click="sort('name')"><?php esc_html_e( 'Producto', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('name')"></i></th>
                        <th class="ws-th-sort" @click="sort('supplier_name')"><?php esc_html_e( 'Proveedor', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('supplier_name')"></i></th>
                        <th class="ws-th-sort" @click="sort('cost_price')"><?php esc_html_e( 'Costo', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('cost_price')"></i></th>
                        <th class="ws-th-sort" @click="sort('sale_price')"><?php esc_html_e( 'Venta', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('sale_price')"></i></th>
                        <th class="ws-th-sort" @click="sort('transfer_pct')"><?php esc_html_e( '% Transf.', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('transfer_pct')"></i></th>
                        <th class="ws-th-sort" @click="sort('min_stock')"><?php esc_html_e( 'Stock mín.', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('min_stock')"></i></th>
                        <th class="ws-th-sort" @click="sort('production_date')" title="<?php esc_attr_e( 'Fecha de producción', 'workshop' ); ?>"><?php esc_html_e( 'Fecha P.', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('production_date')"></i></th>
                        <th class="ws-th-sort" @click="sort('expiry_date')" title="<?php esc_attr_e( 'Fecha de vencimiento', 'workshop' ); ?>"><?php esc_html_e( 'Fecha V.', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('expiry_date')"></i></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="p in products" :key="p.id">
                        <tr>
                            <template x-if="canEdit">
                                <td class="ws-th-check"><input type="checkbox" :checked="selected.indexOf(p.id) !== -1" @change="toggleRow(p.id)"></td>
                            </template>
                            <td>
                                <div class="ws-cell-product">
                                    <div class="ws-thumb"><img x-show="p.image" :src="p.image" :alt="p.name" loading="lazy"><i x-show="!p.image" class="fa-solid fa-box"></i></div>
                                    <div>
                                        <strong x-text="p.name"></strong>
                                        <span class="ws-badge ws-badge-fraction" x-show="p.fraction_parent" x-cloak title="<?php esc_attr_e( 'Producto fraccionado', 'workshop' ); ?>">
                                            <i class="fa-solid fa-scale-balanced"></i>
                                            <?php esc_html_e( 'Fracción de', 'workshop' ); ?> #<span x-text="p.fraction_parent"></span>
                                        </span>
                                        <small class="ws-muted ws-product-category" x-show="p.category_path" x-text="p.category_path"></small>
                                        <small class="ws-muted" x-show="p.description" x-text="p.description"></small>
                                    </div>
                                </div>
                            </td>
                            <td x-text="p.supplier_name || '—'"></td>
                            <td x-text="money(p.cost_price, p.currency)"></td>
                            <td class="ws-strong" x-text="money(p.sale_price, p.currency)"></td>
                            <td x-text="p.transfer_pct + '%'"></td>
                            <td x-text="p.min_stock"></td>
                            <td>
                                <span x-show="p.production_date" x-text="fmtDate(p.production_date)" class="ws-muted"></span>
                                <span x-show="!p.production_date" class="ws-muted">—</span>
                            </td>
                            <td>
                                <span x-show="p.expiry_date && !p.expired" x-text="fmtDate(p.expiry_date)" :class="p.expiring ? 'ws-badge ws-badge-warn' : 'ws-muted'"></span>
                                <span x-show="p.expiry_date && p.expired" class="ws-badge ws-badge-danger" title="<?php esc_attr_e( 'Producto vencido', 'workshop' ); ?>"><i class="fa-solid fa-triangle-exclamation"></i> <span x-text="fmtDate(p.expiry_date)"></span></span>
                                <span x-show="!p.expiry_date" class="ws-muted">—</span>
                            </td>
                            <td class="ws-actions">
                                <template x-if="canEdit"><button class="ws-icon-btn" title="Editar" @click="openForm(p)"><i class="fa-solid fa-pen"></i></button></template>
                                <template x-if="canCreate"><button class="ws-icon-btn" title="Clonar" @click="clone(p)"><i class="fa-solid fa-clone"></i></button></template>
                                <template x-if="canDelete"><button class="ws-icon-btn ws-danger" title="Eliminar" @click="remove(p)"><i class="fa-solid fa-trash-can"></i></button></template>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="total === 0"><td :colspan="canEdit ? 10 : 9"><p class="ws-empty"><?php esc_html_e( 'Sin resultados.', 'workshop' ); ?></p></td></tr>
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
    </div>

    <!-- Pestaña: Combos (agrupar productos; el combo solo se habilita/deshabilita) -->
    <div x-show="tab === 'combos'" x-cloak>
        <div class="ws-toolbar">
            <div class="ws-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="<?php esc_attr_e( 'Buscar combo…', 'workshop' ); ?>" x-model="comboSearch" @input="onComboSearch()">
            </div>
            <button class="ws-btn ws-btn-primary" @click="openComboForm()" x-show="canEdit"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Nuevo combo', 'workshop' ); ?></button>
        </div>

        <div class="ws-card">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Combo', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Precio', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Componentes', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                        <th class="ws-actions"><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="c in combos" :key="c.id">
                        <tr>
                            <td>
                                <div class="ws-cell-product">
                                    <div class="ws-thumb"><img x-show="c.photo" :src="c.photo" :alt="c.name" loading="lazy"><i x-show="!c.photo" class="fa-solid fa-layer-group"></i></div>
                                    <div><strong x-text="c.name"></strong></div>
                                </div>
                            </td>
                            <td>
                                <strong x-text="money(c.price, c.currency)"></strong>
                                <small class="ws-muted ws-block" x-text="c.price_mode === 'auto' ? 'Auto-calculado' : 'Manual'"></small>
                            </td>
                            <td>
                                <span x-text="c.items.map(i => (i.qty !== 1 ? i.qty + '× ' : '') + i.name).join(', ')" style="font-size:.8em"></span>
                            </td>
                            <td>
                                <button type="button" class="ws-toggle" :class="c.active ? 'is-on' : ''" @click="toggleCombo(c)" :title="c.active ? '<?php esc_attr_e( 'Deshabilitar combo', 'workshop' ); ?>' : '<?php esc_attr_e( 'Habilitar combo', 'workshop' ); ?>'" x-show="canEdit">
                                    <i class="fa-solid" :class="c.active ? 'fa-toggle-on' : 'fa-toggle-off'"></i>
                                </button>
                                <span class="ws-badge" :class="c.active ? 'ws-badge-ok' : 'ws-badge-muted'" x-text="c.active ? '<?php esc_html_e( 'Activo', 'workshop' ); ?>' : '<?php esc_html_e( 'Inactivo', 'workshop' ); ?>'" x-show="!canEdit"></span>
                            </td>
                            <td class="ws-actions">
                                <template x-if="canTransfer">
                                    <button class="ws-icon-btn" title="<?php esc_attr_e( 'Transferir combo', 'workshop' ); ?>" @click="openComboTransfer(c)"><i class="fa-solid fa-arrow-right-arrow-left"></i></button>
                                </template>
                                <template x-if="canEdit">
                                    <button class="ws-icon-btn" title="<?php esc_attr_e( 'Editar', 'workshop' ); ?>" @click="openComboForm(c)"><i class="fa-solid fa-pen"></i></button>
                                </template>
                                <template x-if="canDelete">
                                    <button class="ws-icon-btn ws-danger" title="<?php esc_attr_e( 'Eliminar', 'workshop' ); ?>" @click="removeCombo(c)"><i class="fa-solid fa-trash-can"></i></button>
                                </template>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!combos.length"><td colspan="5"><p class="ws-empty"><?php esc_html_e( 'Sin combos. Crea uno agrupando productos.', 'workshop' ); ?></p></td></tr>
                </tbody>
            </table>
            <div class="ws-pagination" x-show="comboTotal > comboPageSize">
                <span><?php esc_html_e( 'Página', 'workshop' ); ?> <b x-text="comboPage"></b> / <b x-text="comboPages"></b></span>
                <div>
                    <button class="ws-page-btn" :disabled="comboPage <= 1" @click="comboGo(comboPage - 1)"><i class="fa-solid fa-chevron-left"></i></button>
                    <button class="ws-page-btn" :disabled="comboPage >= comboPages" @click="comboGo(comboPage + 1)"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal combo: crear/editar -->
    <div class="ws-modal" x-show="comboFormOpen" x-cloak @keydown.escape.window="comboFormOpen=false">
        <div class="ws-modal-backdrop" @click="comboFormOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="comboForm.id ? 'Editar combo' : 'Nuevo combo'"></h3>
                <button class="ws-cart-close" @click="comboFormOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="saveCombo" class="ws-form">
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Nombre *', 'workshop' ); ?></span>
                        <input type="text" x-model="comboForm.name" required>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Foto del combo', 'workshop' ); ?></span>
                        <input type="url" x-model="comboForm.photo" placeholder="https://…">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Precio', 'workshop' ); ?></span>
                        <select x-model="comboForm.price_mode">
                            <option value="auto"><?php esc_html_e( 'Auto (suma de los productos)', 'workshop' ); ?></option>
                            <option value="manual"><?php esc_html_e( 'Manual (lo pongo yo)', 'workshop' ); ?></option>
                        </select>
                    </label>
                    <template x-if="comboForm.price_mode === 'manual'">
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Precio del combo *', 'workshop' ); ?></span>
                            <input type="number" step="0.01" min="0" x-model.number="comboForm.price" required>
                        </label>
                    </template>
                    <template x-if="comboForm.price_mode === 'manual'">
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Moneda del precio', 'workshop' ); ?></span>
                            <select x-model="comboForm.currency">
                                <template x-for="c in currencies" :key="c"><option :value="c" x-text="c"></option></template>
                            </select>
                        </label>
                    </template>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( '¿Qué productos incluye?', 'workshop' ); ?></span>
                        <div class="ws-form-hint">
                            <i class="fa-solid fa-circle-info"></i>
                            <span><?php esc_html_e( 'Elige el producto y cuánto se coge de él (opcional: vacío = 1). Cada combo consume estas cantidades; el stock del combo es el mínimo disponible de sus productos.', 'workshop' ); ?></span>
                        </div>
                        <div class="ws-combo-items">
                            <template x-for="(row, ri) in comboForm.items" :key="ri">
                                <div class="ws-combo-item-row">
                                    <select x-model="row.product_id" required>
                                        <option value="">— <?php esc_html_e( 'Elige un producto', 'workshop' ); ?> —</option>
                                        <template x-for="p in allProducts" :key="p.id"><option :value="p.id" x-text="p.name + (p.currency && p.currency !== currency ? ' (' + p.currency + ')' : '')"></option></template>
                                    </select>
                                    <input type="number" step="0.01" min="0" x-model.number="row.qty" placeholder="<?php esc_attr_e( 'Cantidad (1)', 'workshop' ); ?>" title="<?php esc_attr_e( 'Vacío = 1 unidad', 'workshop' ); ?>">
                                    <button type="button" class="ws-icon-btn ws-danger" @click="removeComboItem(ri)" x-show="comboForm.items.length > 1"><i class="fa-solid fa-xmark"></i></button>
                                </div>
                            </template>
                            <button type="button" class="ws-btn ws-btn-secondary ws-btn-sm" @click="addComboItem()"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Añadir producto', 'workshop' ); ?></button>
                        </div>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Habilitado', 'workshop' ); ?></span>
                        <label class="ws-check"><input type="checkbox" x-model="comboForm.active"><span><?php esc_html_e( 'Visible en tienda y POS', 'workshop' ); ?></span></label>
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="comboFormOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar combo', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal transferir combo -->
    <div class="ws-modal" x-show="comboTransferOpen" x-cloak @keydown.escape.window="comboTransferOpen=false">
        <div class="ws-modal-backdrop" @click="comboTransferOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3><i class="fa-solid fa-arrow-right-arrow-left"></i> <?php esc_html_e( 'Transferir combo', 'workshop' ); ?> <span class="ws-muted" x-text="comboTransfer.combo ? ('«' + comboTransfer.combo.name + '»') : ''"></span></h3>
                <button class="ws-cart-close" @click="comboTransferOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="saveComboTransfer" class="ws-form">
                <p class="ws-muted" style="font-size:.82em"><?php esc_html_e( 'Se moverán los PRODUCTOS del combo (cada componente × cantidad). El stock del origen se rebaja y el del destino aumenta.', 'workshop' ); ?></p>
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Desde *', 'workshop' ); ?></span>
                        <select x-model="comboTransfer.from_location" required>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Hacia *', 'workshop' ); ?></span>
                        <select x-model="comboTransfer.to_location" required>
                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Cantidad de combos *', 'workshop' ); ?></span>
                        <input type="number" step="1" min="1" x-model.number="comboTransfer.count" required>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Nota', 'workshop' ); ?></span>
                        <input type="text" x-model="comboTransfer.note">
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="comboTransferOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-right-left"></i> <?php esc_html_e( 'Transferir', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pestaña: Historial de precios (trazabilidad costo/venta) -->
    <div x-show="tab === 'history'" x-cloak>
        <div class="ws-toolbar">
            <div class="ws-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="<?php esc_attr_e( 'Buscar por producto…', 'workshop' ); ?>" x-model="historySearch" @input="historyOnSearch()">
            </div>
        </div>
        <div class="ws-card">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th class="ws-th-sort" @click="historySort('product_name')"><?php esc_html_e( 'Producto', 'workshop' ); ?> <i class="fa-solid" :class="historySortIcon('product_name')"></i></th>
                        <th class="ws-th-sort" @click="historySort('old_cost')"><?php esc_html_e( 'Costo anterior', 'workshop' ); ?> <i class="fa-solid" :class="historySortIcon('old_cost')"></i></th>
                        <th class="ws-th-sort" @click="historySort('new_cost')"><?php esc_html_e( 'Costo nuevo', 'workshop' ); ?> <i class="fa-solid" :class="historySortIcon('new_cost')"></i></th>
                        <th class="ws-th-sort" @click="historySort('old_sale')"><?php esc_html_e( 'Venta anterior', 'workshop' ); ?> <i class="fa-solid" :class="historySortIcon('old_sale')"></i></th>
                        <th class="ws-th-sort" @click="historySort('new_sale')"><?php esc_html_e( 'Venta nueva', 'workshop' ); ?> <i class="fa-solid" :class="historySortIcon('new_sale')"></i></th>
                        <th class="ws-th-sort" @click="historySort('user_name')"><?php esc_html_e( 'Quién', 'workshop' ); ?> <i class="fa-solid" :class="historySortIcon('user_name')"></i></th>
                        <th class="ws-th-sort" @click="historySort('created_at')"><?php esc_html_e( 'Fecha', 'workshop' ); ?> <i class="fa-solid" :class="historySortIcon('created_at')"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="h in historyRows" :key="h.id">
                        <tr>
                            <td><strong x-text="h.product_name"></strong></td>
                            <td x-text="money(h.old_cost, h.currency)"></td>
                            <td>
                                <span x-text="money(h.new_cost, h.currency)"></span>
                                <i x-show="h.new_cost !== h.old_cost" :class="priceTrend(h.old_cost, h.new_cost)" style="margin-left:4px"></i>
                            </td>
                            <td x-text="money(h.old_sale, h.currency)"></td>
                            <td>
                                <span x-text="money(h.new_sale, h.currency)"></span>
                                <i x-show="h.new_sale !== h.old_sale" :class="priceTrend(h.old_sale, h.new_sale)" style="margin-left:4px"></i>
                            </td>
                            <td x-text="h.user_name || '—'"></td>
                            <td class="ws-muted" x-text="h.date"></td>
                        </tr>
                    </template>
                    <tr x-show="historyTotal === 0"><td colspan="7"><p class="ws-empty"><?php esc_html_e( 'Aún no hay cambios de precio registrados. Edita un producto para empezar la trazabilidad.', 'workshop' ); ?></p></td></tr>
                </tbody>
            </table>
            <div class="ws-pagination" x-show="historyTotal > historyPageSize">
                <span class="ws-pagination-info" x-text="(historyTotal ? (historyPage - 1) * historyPageSize + 1 : 0) + '–' + Math.min(historyPage * historyPageSize, historyTotal) + ' de ' + historyTotal"></span>
                <div class="ws-pagination-controls">
                    <button class="ws-page-btn" @click="historyPrev()" :disabled="historyPage <= 1"><i class="fa-solid fa-chevron-left"></i></button>
                    <template x-for="n in historyPages()" :key="n">
                        <button class="ws-page-btn" :class="n === historyPage ? 'is-active' : ''" @click="historyGo(n)" x-text="n"></button>
                    </template>
                    <button class="ws-page-btn" @click="historyNext()" :disabled="historyPage >= historyTotalPages()"><i class="fa-solid fa-chevron-right"></i></button>
                    <select class="ws-page-size" x-model.number="historyPageSize" @change="historyChangePageSize()">
                        <option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Pestaña: Categorías (árbol de subcategorías, dentro de Productos) -->
    <?php if ( $can_categories ) : ?>
    <div x-show="tab === 'categories'" x-cloak>
        <?php include WS_PATH . 'templates/panel/categories.php'; ?>
    </div>
    <?php endif; ?>

    <!-- Pestaña: Proveedores (dentro de Productos) -->
    <?php if ( $can_suppliers ) : ?>
    <div x-show="tab === 'suppliers'" x-cloak>
        <?php include WS_PATH . 'templates/panel/suppliers.php'; ?>
    </div>
    <?php endif; ?>

    <!-- Modal formulario -->
    <div class="ws-modal" x-show="formOpen" x-cloak @keydown.escape.window="formOpen=false">
        <div class="ws-modal-backdrop" @click="formOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="form.id ? 'Editar producto' : 'Nuevo producto'"></h3>
                <button class="ws-cart-close" @click="formOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <?php if ( (int) $plan_status['limit'] > 0 ) : ?>
            <p class="ws-plan-form-hint">
                <i class="fa-solid fa-circle-info"></i>
                <?php echo esc_html( sprintf(
                    /* translators: 1: límite del plan, 2: quedan libres */
                    __( 'Tu plan permite %1$d productos. Te quedan %2$s libres; al agotarlas no podrás crear más hasta liberar o hacer upgrade.', 'workshop' ),
                    (int) $plan_status['limit'],
                    (int) $plan_status['remaining'] > 0 ? number_format_i18n( (int) $plan_status['remaining'] ) : '0'
                ) ); ?>
            </p>
            <?php endif; ?>
            <form @submit.prevent="save" class="ws-form">
                <div class="ws-form-grid">
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Nombre *', 'workshop' ); ?></span>
                        <input type="text" x-model="form.name" required>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Código de barras', 'workshop' ); ?></span>
                        <input type="text" x-model="form.barcode">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Categoría', 'workshop' ); ?></span>
                        <select x-model.number="form.category_id">
                            <option value="0">— <?php esc_html_e( 'Sin categoría', 'workshop' ); ?> —</option>
                            <template x-for="c in categories" :key="c.id">
                                <option :value="c.id" x-text="c.name"></option>
                            </template>
                        </select>
                        <p class="ws-muted" style="font-size:.8em;margin:4px 0 0"><?php esc_html_e( 'Elige una categoría (puede ser subcategoría). Los productos de una categoría también se ven en sus subcategorías en la tienda.', 'workshop' ); ?></p>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Imagen URL', 'workshop' ); ?></span>
                        <input type="url" x-model="form.image" placeholder="https://…">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Descripción', 'workshop' ); ?></span>
                        <textarea x-model="form.description" rows="2"></textarea>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Precio costo', 'workshop' ); ?></span>
                        <input type="number" step="0.01" min="0" x-model="form.cost_price">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Precio venta', 'workshop' ); ?></span>
                        <input type="number" step="0.01" min="0" x-model="form.sale_price">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( '% Transferencia', 'workshop' ); ?></span>
                        <input type="number" step="0.01" min="0" max="100" x-model="form.transfer_pct">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Moneda', 'workshop' ); ?></span>
                        <select x-model="form.currency">
                            <template x-for="c in currencies" :key="c"><option :value="c" x-text="c"></option></template>
                        </select>
                    </label>
                    <div class="ws-span-2">
                        <label class="ws-check ws-check-switch">
                            <input type="checkbox" x-model="form.show_equiv">
                            <span><?php esc_html_e( 'Mostrar precio equivalente en la tienda (CUP ↔ USD)', 'workshop' ); ?></span>
                        </label>
                        <p class="ws-muted" style="font-size:.8em;margin:4px 0 0"><?php esc_html_e( 'Si está activo, la tarjeta del producto mostrará su precio y su equivalente en la otra moneda configurada.', 'workshop' ); ?></p>
                    </div>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Proveedor', 'workshop' ); ?></span>
                        <select x-model="form.supplier_id">
                            <option value="0">—</option>
                            <template x-for="s in suppliers" :key="s.id"><option :value="s.id" x-text="s.name"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Stock mínimo', 'workshop' ); ?></span>
                        <input type="number" step="0.01" min="0" x-model="form.min_stock">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Fecha de producción', 'workshop' ); ?></span>
                        <input type="date" x-model="form.production_date">
                        <p class="ws-muted" style="font-size:.8em;margin:4px 0 0"><?php esc_html_e( 'Opcional. Ej.: cuándo se fabricó o envasó el producto.', 'workshop' ); ?></p>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Fecha de vencimiento', 'workshop' ); ?></span>
                        <input type="date" x-model="form.expiry_date">
                        <p class="ws-muted" style="font-size:.8em;margin:4px 0 0"><?php esc_html_e( 'Opcional. Te avisamos por notificación y por el bot cuando esté por vencer o haya vencido.', 'workshop' ); ?></p>
                    </label>
                    <div class="ws-span-2" x-show="canFraction" x-cloak>
                        <div class="ws-fraction-box">
                            <h4 class="ws-fraction-title"><i class="fa-solid fa-scale-balanced"></i> <?php esc_html_e( 'Fraccionamiento (producto hijo)', 'workshop' ); ?></h4>
                            <p class="ws-muted" style="font-size:.8em;margin:4px 0 8px">
                                <?php esc_html_e( 'Conecta este producto como una unidad menor de un producto madre (Ej.: 1 saco = 3 jabas). La venta o entrada de uno descuenta/aumenta automáticamente el otro.', 'workshop' ); ?>
                            </p>
                            <div class="ws-form-grid" style="grid-template-columns:1fr 1fr;gap:10px">
                                <label class="ws-field">
                                    <span><?php esc_html_e( 'Producto madre', 'workshop' ); ?></span>
                                    <select x-model="form.fraction_parent">
                                        <option value="0"><?php esc_html_e( '— Ninguno (es producto único)', 'workshop' ); ?></option>
                                        <template x-for="p in parentCandidates()" :key="p.id">
                                            <option :value="p.id" x-text="p.name + ' (#' + p.id + ')'"></option>
                                        </template>
                                    </select>
                                </label>
                                <label class="ws-field" x-show="form.fraction_parent > 0" x-cloak>
                                    <span><?php esc_html_e( 'Cuántos hijos = 1 madre', 'workshop' ); ?></span>
                                    <input type="number" step="0.01" min="0" x-model.number="form.fraction_qty">
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="formOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal edición en lote (campo único aplicado a los seleccionados) -->
    <div class="ws-modal" x-show="bulkOpen" x-cloak @keydown.escape.window="bulkOpen=false">
        <div class="ws-modal-backdrop" @click="bulkOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3><i class="fa-solid fa-wand-magic-sparkles"></i> <?php esc_html_e( 'Editar en lote', 'workshop' ); ?> <span class="ws-muted" x-text="'(' + selected.length + ')'"></span></h3>
                <button class="ws-cart-close" @click="bulkOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="applyBulk" class="ws-form">
                <p class="ws-muted"><?php esc_html_e( 'El valor se aplicará a los', 'workshop' ); ?> <b x-text="selected.length"></b> <?php esc_html_e( 'productos seleccionados.', 'workshop' ); ?></p>
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Campo *', 'workshop' ); ?></span>
                        <select x-model="bulk.field">
                            <option value="min_stock"><?php esc_html_e( 'Stock mínimo', 'workshop' ); ?></option>
                            <option value="cost_price"><?php esc_html_e( 'Precio costo', 'workshop' ); ?></option>
                            <option value="sale_price"><?php esc_html_e( 'Precio venta', 'workshop' ); ?></option>
                            <option value="production_date"><?php esc_html_e( 'Fecha de producción', 'workshop' ); ?></option>
                            <option value="expiry_date"><?php esc_html_e( 'Fecha de vencimiento', 'workshop' ); ?></option>
                        </select>
                    </label>
                    <label class="ws-field" x-show="bulkIsNumeric">
                        <span><?php esc_html_e( 'Modo *', 'workshop' ); ?></span>
                        <select x-model="bulk.mode">
                            <option value="set"><?php esc_html_e( 'Fijar a (mismo valor en todos)', 'workshop' ); ?></option>
                            <option value="add"><?php esc_html_e( 'Ajustar (+ / − al valor actual)', 'workshop' ); ?></option>
                        </select>
                    </label>
                    <label class="ws-field" :class="!bulkIsNumeric ? 'ws-span-2' : ''">
                        <span x-text="bulkIsNumeric ? (bulk.mode === 'add' ? '<?php esc_attr_e( 'Cantidad a sumar (+ / −)', 'workshop' ); ?>' : '<?php esc_attr_e( 'Valor *', 'workshop' ); ?>') : '<?php esc_attr_e( 'Fecha', 'workshop' ); ?>'"></span>
                        <input x-show="bulkIsNumeric" type="number" step="0.01" :min="bulk.mode === 'set' ? 0 : null" x-model.number="bulk.value" required>
                        <input x-show="bulkIsDate" type="date" x-model="bulk.value">
                        <button x-show="bulkIsDate" type="button" class="ws-btn ws-btn-secondary" style="margin-top:6px" @click="bulk.value=''"><i class="fa-solid fa-eraser"></i> <?php esc_html_e( 'Limpiar fecha (dejar sin fecha)', 'workshop' ); ?></button>
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="bulkOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary" :disabled="bulkSaving">
                        <i class="fa-solid" :class="bulkSaving ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                        <?php esc_html_e( 'Aplicar a', 'workshop' ); ?> <span x-text="selected.length"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal import CSV -->
    <div class="ws-modal" x-show="importModal" x-cloak @keydown.escape.window="importModal=false">
        <div class="ws-modal-backdrop" @click="importModal=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3><?php esc_html_e( 'Importar productos (CSV)', 'workshop' ); ?></h3>
                <button class="ws-cart-close" @click="importModal=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p class="ws-muted"><?php esc_html_e( 'Formato: nombre, codigo, descripcion, costo, venta, transferencia, moneda, proveedor_id, stock_minimo. Primera fila encabezados.', 'workshop' ); ?></p>
            <div class="ws-dropzone" @dragover.prevent @drop.prevent="handleDrop($event)" :class="{'is-over': dragOver}" @dragenter="dragOver=true" @dragleave="dragOver=false">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <p><?php esc_html_e( 'Arrastra el CSV aquí o', 'workshop' ); ?></p>
                <button type="button" class="ws-btn ws-btn-secondary" @click="$refs.file.click()"><?php esc_html_e( 'Seleccionar archivo', 'workshop' ); ?></button>
                <input type="file" accept=".csv,text/csv" x-ref="file" class="ws-hidden-input" @change="importCsv($event.target)">
            </div>
            <div class="ws-modal-foot">
                <button type="button" class="ws-btn ws-btn-secondary" @click="importModal=false"><?php esc_html_e( 'Cerrar', 'workshop' ); ?></button>
            </div>
        </div>
    </div>
</div>
