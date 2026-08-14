<?php
/**
 * Panel: ubicaciones (PV y almacenes).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$locations = WS_CRUD::get_locations();
$can_manage = ws_can( 'locations_manage' );
$currency   = ws_currency_symbol();
$currencies = ws_currencies();
?>
<div x-data="wsLocations(<?php echo esc_attr( wp_json_encode( array( 'currency' => $currency, 'currencies' => $currencies, 'canManage' => $can_manage ) ) ); ?>)">

    <div class="ws-toolbar">
        <div class="ws-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" placeholder="<?php esc_attr_e( 'Buscar…', 'workshop' ); ?>" x-model="search" @input="onSearch()">
        </div>
        <?php if ( $can_manage ) : ?>
            <button class="ws-btn ws-btn-primary" @click="openForm()"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Nueva ubicación', 'workshop' ); ?></button>
        <?php endif; ?>
    </div>

    <div class="ws-card">
        <table class="ws-table">
            <thead>
                <tr>
                    <th class="ws-th-sort" @click="sort('name')"><?php esc_html_e( 'Ubicación', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('name')"></i></th>
                    <th class="ws-th-sort" @click="sort('type')"><?php esc_html_e( 'Tipo', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('type')"></i></th>
                    <th class="ws-th-sort" @click="sort('address')"><?php esc_html_e( 'Dirección', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('address')"></i></th>
                    <th class="ws-th-sort" @click="sort('whatsapp')"><?php esc_html_e( 'WhatsApp', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('whatsapp')"></i></th>
                    <th class="ws-th-sort" @click="sort('currency')"><?php esc_html_e( 'Moneda', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('currency')"></i></th>
                    <th class="ws-th-sort" @click="sort('delivery_cost')"><?php esc_html_e( 'Domicilio', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('delivery_cost')"></i></th>
                    <th><?php esc_html_e( 'Tienda', 'workshop' ); ?></th>
                    <th class="ws-th-sort" @click="sort('slug')"><?php esc_html_e( 'URL', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('slug')"></i></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="l in locations" :key="l.id">
                    <tr>
                        <td>
                            <div class="ws-cell-product">
                                <div class="ws-thumb"><i class="fa-solid" :class="l.type === 'pv' ? 'fa-store' : 'fa-warehouse'"></i></div>
                                <div><strong x-text="l.name"></strong></div>
                            </div>
                        </td>
                        <td><span class="ws-badge" :class="l.type === 'pv' ? 'ws-badge-pv' : 'ws-badge-wh'"><span x-text="l.type === 'pv' ? 'PV' : 'Almacén'"></span></span></td>
                        <td x-text="l.address || '—'"></td>
                        <td x-text="l.whatsapp || '—'"></td>
                        <td x-text="l.currency"></td>
                        <td x-text="money(l.delivery_cost)"></td>
                        <td>
                            <a class="ws-link" :href="storeUrl(l.slug)" target="_blank" rel="noopener" :title="l.type === 'pv' ? '<?php echo esc_js( __( 'Ver tienda pública (PV)', 'workshop' ) ); ?>' : '<?php echo esc_js( __( 'Ver tienda pública (almacén)', 'workshop' ) ); ?>'"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                        </td>
                        <td>
                            <code class="ws-slug-text" x-text="'/' + (l.slug || '')"></code>
                        </td>
                        <td class="ws-actions">
                            <template x-if="canManage">
                                <button class="ws-icon-btn" title="Editar" @click="openForm(l)"><i class="fa-solid fa-pen"></i></button>
                            </template>
                            <template x-if="canManage">
                                <button class="ws-icon-btn ws-danger" title="Eliminar" @click="remove(l)"><i class="fa-solid fa-trash-can"></i></button>
                            </template>
                        </td>
                    </tr>
                </template>
                <tr x-show="total === 0"><td colspan="9"><p class="ws-empty"><?php esc_html_e( 'Sin resultados.', 'workshop' ); ?></p></td></tr>
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

    <?php if ( $can_manage ) : ?>
    <div class="ws-card ws-link-card">
        <div class="ws-card-head">
            <div>
                <h3><i class="fa-solid fa-share-nodes"></i> <?php esc_html_e( 'Conectar ubicaciones · stock compartido', 'workshop' ); ?></h3>
                <p class="ws-muted"><?php esc_html_e( 'Arrastra desde el asa «conectar» de una ubicación hasta otra para vincularlas: al vender en una, el stock se rebaja en todas las conectadas (por transitividad). Haz clic en una línea para desconectarla.', 'workshop' ); ?></p>
                <span class="ws-link-dirty" x-show="isDirty()"><i class="fa-solid fa-circle-exclamation"></i> <?php esc_html_e( 'Cambios sin guardar', 'workshop' ); ?></span>
            </div>
            <div class="ws-link-tools">
                <button class="ws-btn ws-btn-secondary" @click="autoLayout()" title="<?php esc_attr_e( 'Ordena las ubicaciones en círculo', 'workshop' ); ?>"><i class="fa-solid fa-arrows-to-circle"></i> <?php esc_html_e( 'Ordenar', 'workshop' ); ?></button>
                <button class="ws-btn ws-btn-secondary" @click="clearLinks()"><i class="fa-solid fa-circle-minus"></i> <?php esc_html_e( 'Limpiar', 'workshop' ); ?></button>
                <button class="ws-btn ws-btn-primary" @click="saveLinks()" :disabled="savingLinks"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
            </div>
        </div>

        <div class="ws-link-canvas" x-ref="canvas">
            <template x-for="link in displayLinks()" :key="'l' + linkKey(link.a, link.b)">
                <div class="ws-link-line" :style="lineStyle(link)" :title="'Desconectar: ' + locName(link.a) + ' ↔ ' + locName(link.b)" @click="removeLink(link)"></div>
            </template>
            <template x-for="link in displayLinks()" :key="'m' + linkKey(link.a, link.b)">
                <span class="ws-link-mid" :style="midStyle(link)" :title="'Desconectar: ' + locName(link.a) + ' ↔ ' + locName(link.b)" @click="removeLink(link)"><i class="fa-solid fa-link"></i></span>
            </template>
            <template x-if="linkMode === 'connect' && tempLine && linkFrom">
                <div class="ws-link-temp" :style="tempLineStyle()"></div>
            </template>

            <template x-for="l in canvasLocations" :key="l.id">
                <div class="ws-link-node" :class="nodeClass(l.id)" :data-node-id="l.id" :style="nodeStyle(l.id)" @pointerdown.stop="startMove(l.id, $event)">
                    <div class="ws-link-node-icon" :class="l.type === 'pv' ? 'is-pv' : 'is-wh'">
                        <i class="fa-solid" :class="l.type === 'pv' ? 'fa-store' : 'fa-warehouse'"></i>
                    </div>
                    <div class="ws-link-node-body">
                        <strong x-text="l.name"></strong>
                        <small x-text="l.type === 'pv' ? 'PV' : 'Almacén'"></small>
                    </div>
                    <span class="ws-link-count" x-show="nodeLinks(l.id).length" x-text="nodeLinks(l.id).length"></span>
                    <button class="ws-link-handle" title="Conectar con otra ubicación" @pointerdown.stop="startConnect(l.id, $event)"><i class="fa-solid fa-link"></i></button>
                </div>
            </template>

            <p class="ws-empty" x-show="!canvasLocations.length"><?php esc_html_e( 'Crea ubicaciones para poder conectarlas.', 'workshop' ); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="ws-modal" x-show="formOpen" x-cloak @keydown.escape.window="formOpen=false">
        <div class="ws-modal-backdrop" @click="formOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="form.id ? 'Editar ubicación' : 'Nueva ubicación'"></h3>
                <button class="ws-cart-close" @click="formOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="save" class="ws-form">
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Tipo *', 'workshop' ); ?></span>
                        <select x-model="form.type">
                            <option value="pv"><?php esc_html_e( 'Punto de venta (PV)', 'workshop' ); ?></option>
                            <option value="almacen"><?php esc_html_e( 'Almacén', 'workshop' ); ?></option>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Nombre *', 'workshop' ); ?></span>
                        <input type="text" x-model="form.name" required @input="onNameInput()">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'URL de acceso', 'workshop' ); ?></span>
                        <input type="text" x-model="form.slug" placeholder="mi-tienda">
                        <small class="ws-muted ws-slug-preview"><i class="fa-solid fa-link"></i> <span x-text="storeUrlPreview()"></span></small>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Dirección', 'workshop' ); ?></span>
                        <input type="text" x-model="form.address">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Foto URL', 'workshop' ); ?></span>
                        <input type="url" x-model="form.photo" placeholder="https://…">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Moneda', 'workshop' ); ?></span>
                        <select x-model="form.currency">
                            <template x-for="c in currencies" :key="c"><option :value="c" x-text="c"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'WhatsApp para pedidos', 'workshop' ); ?></span>
                        <input type="text" x-model="form.whatsapp" placeholder="+58 412 123 4567">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Coste de domicilio', 'workshop' ); ?></span>
                        <input type="number" step="0.01" min="0" x-model="form.delivery_cost">
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Activa', 'workshop' ); ?></span>
                        <label class="ws-check"><input type="checkbox" x-model="form.active"><span><?php esc_html_e( 'Visible', 'workshop' ); ?></span></label>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Métodos de pago', 'workshop' ); ?></span>
                        <div class="ws-check-group">
                            <template x-for="m in ['Efectivo','Tarjeta','Transferencia','Pago móvil']" :key="m">
                                <label class="ws-check">
                                    <input type="checkbox" :value="m" x-model="form.payment_methods">
                                    <span x-text="m"></span>
                                </label>
                            </template>
                        </div>
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="formOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
