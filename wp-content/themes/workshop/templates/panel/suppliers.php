<?php
/**
 * Panel: proveedores.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$suppliers  = WS_CRUD::get_suppliers();
$can_manage = ws_can( 'suppliers_manage' );
?>
<div x-data="wsSuppliers(<?php echo esc_attr( wp_json_encode( array( 'canManage' => $can_manage ) ) ); ?>)">

    <div class="ws-toolbar">
        <div class="ws-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" placeholder="<?php esc_attr_e( 'Buscar…', 'workshop' ); ?>" x-model="search" @input="onSearch()">
        </div>
        <?php if ( $can_manage ) : ?>
            <button class="ws-btn ws-btn-primary" @click="openForm()"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Nuevo proveedor', 'workshop' ); ?></button>
        <?php endif; ?>
    </div>

    <div class="ws-card">
        <table class="ws-table">
            <thead>
                <tr>
                    <th class="ws-th-sort" @click="sort('name')"><?php esc_html_e( 'Nombre', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('name')"></i></th>
                    <th class="ws-th-sort" @click="sort('phone')"><?php esc_html_e( 'Teléfono', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('phone')"></i></th>
                    <th class="ws-th-sort" @click="sort('address')"><?php esc_html_e( 'Dirección', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('address')"></i></th>
                    <th class="ws-th-sort" @click="sort('country')"><?php esc_html_e( 'País', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('country')"></i></th>
                    <th class="ws-th-sort" @click="sort('province')"><?php esc_html_e( 'Provincia', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('province')"></i></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="s in suppliers" :key="s.id">
                    <tr>
                        <td><strong x-text="s.name"></strong></td>
                        <td x-text="s.phone || '—'"></td>
                        <td x-text="s.address || '—'"></td>
                        <td x-text="s.country || '—'"></td>
                        <td x-text="s.province || '—'"></td>
                        <td class="ws-actions">
                            <template x-if="canManage">
                                <button class="ws-icon-btn" title="Editar" @click="openForm(s)"><i class="fa-solid fa-pen"></i></button>
                            </template>
                            <template x-if="canManage">
                                <button class="ws-icon-btn ws-danger" title="Eliminar" @click="remove(s)"><i class="fa-solid fa-trash-can"></i></button>
                            </template>
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

    <div class="ws-modal" x-show="formOpen" x-cloak @keydown.escape.window="formOpen=false">
        <div class="ws-modal-backdrop" @click="formOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="form.id ? 'Editar proveedor' : 'Nuevo proveedor'"></h3>
                <button class="ws-cart-close" @click="formOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="save" class="ws-form">
                <div class="ws-form-grid">
                    <label class="ws-field ws-span-2"><span><?php esc_html_e( 'Nombre *', 'workshop' ); ?></span><input type="text" x-model="form.name" required></label>
                    <label class="ws-field"><span><?php esc_html_e( 'Teléfono', 'workshop' ); ?></span><input type="tel" x-model="form.phone"></label>
                    <label class="ws-field"><span><?php esc_html_e( 'Dirección', 'workshop' ); ?></span><input type="text" x-model="form.address"></label>
                    <label class="ws-field"><span><?php esc_html_e( 'País', 'workshop' ); ?></span><input type="text" x-model="form.country"></label>
                    <label class="ws-field"><span><?php esc_html_e( 'Provincia', 'workshop' ); ?></span><input type="text" x-model="form.province"></label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="formOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
