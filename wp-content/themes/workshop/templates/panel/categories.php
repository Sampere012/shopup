<?php
/**
 * Panel: Categorías de productos (árbol con subcategorías).
 *
 * El dueño organiza el catálogo en una jerarquía: cada categoría puede tener
 * hijos (subcategorías) y hermanos. Se pueden podar (eliminar toda la rama),
 * editar y eliminar; al podar, los productos de la rama pasan a la categoría
 * padre (o quedan sin categoría si era raíz).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WS_Categories' ) ) {
    echo '<p class="ws-empty">' . esc_html__( 'Módulo no disponible.', 'workshop' ) . '</p>';
    return;
}

$ws_cat_can = ws_can( 'categories_manage' );
$ws_cat_all = array_map( static function ( $c ) {
    return array(
        'id'         => (int) $c->id,
        'parent_id'  => (int) $c->parent_id,
        'name'       => (string) $c->name,
        'slug'       => (string) $c->slug,
        'active'     => (int) $c->active,
        'sort_order' => (int) $c->sort_order,
        'path'       => WS_Categories::path_text( (int) $c->id ),
        'children'   => count( WS_Categories::children( (int) $c->id ) ),
        'products'   => WS_Categories::products_count( (int) $c->id ),
    );
}, WS_Categories::all() );
// Para el <select> de «categoría padre» se muestra la RUTA (Padre / Hijo).
$ws_cat_flat = array_map( static function ( $c ) {
    return array( 'id' => (int) $c['id'], 'name' => $c['path'] );
}, $ws_cat_all );
?>
<div class="ws-cats-page" x-data="wsCategories(<?php echo esc_attr( wp_json_encode( array(
    'can'  => $ws_cat_can,
    'list' => $ws_cat_all,
    'flat' => $ws_cat_flat,
) ) ); ?>)">

    <div class="ws-alert ws-alert-info">
        <i class="fa-solid fa-sitemap"></i>
        <span>
            <?php esc_html_e( 'Organiza tu catálogo en un árbol de categorías y subcategorías. Los productos usan estas categorías (en su formulario y en la tienda). Al eliminar una categoría se poda toda su rama: sus productos pasan a la categoría padre.', 'workshop' ); ?>
        </span>
    </div>

    <?php if ( $ws_cat_can ) : ?>
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-plus"></i> <span x-text="editingId ? '<?php esc_attr_e( 'Editar categoría', 'workshop' ); ?>' : '<?php esc_attr_e( 'Nueva categoría', 'workshop' ); ?>'"></span></h3>
        <form class="ws-form ws-grid-2" @submit.prevent="save()">
            <label class="ws-field">
                <span><?php esc_html_e( 'Nombre *', 'workshop' ); ?></span>
                <input type="text" x-model="form.name" required maxlength="150" placeholder="<?php esc_attr_e( 'Ej.: Bebidas', 'workshop' ); ?>">
            </label>
            <label class="ws-field">
                <span><?php esc_html_e( 'Categoría padre', 'workshop' ); ?></span>
                <select x-model.number="form.parent_id">
                    <option value="0">— <?php esc_html_e( 'Ninguna (categoría raíz)', 'workshop' ); ?> —</option>
                    <template x-for="c in parentOptions()" :key="c.id">
                        <option :value="c.id" x-text="c.name"></option>
                    </template>
                </select>
            </label>
            <label class="ws-field">
                <span><?php esc_html_e( 'Orden', 'workshop' ); ?></span>
                <input type="number" min="0" x-model.number="form.sort_order">
            </label>
            <div class="ws-field">
                <label class="ws-check ws-check-switch">
                    <input type="checkbox" x-model="form.active">
                    <span><?php esc_html_e( 'Categoría activa', 'workshop' ); ?></span>
                </label>
            </div>
            <div class="ws-span-2 ws-modal-foot" style="padding:0;border:0">
                <button class="ws-btn ws-btn-primary" type="submit" :disabled="saving">
                    <i class="fa-solid" :class="saving ? 'fa-spinner fa-spin' : 'fa-floppy-disk'"></i>
                    <span x-text="saving ? '<?php esc_attr_e( 'Guardando…', 'workshop' ); ?>' : '<?php esc_attr_e( 'Guardar', 'workshop' ); ?>'"></span>
                </button>
                <button class="ws-btn ws-btn-secondary" type="button" x-show="editingId" @click="resetForm()"><i class="fa-solid fa-xmark"></i> <?php esc_html_e( 'Cancelar edición', 'workshop' ); ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-list"></i> <?php esc_html_e( 'Categorías', 'workshop' ); ?> <span class="ws-ann-count" x-text="list.length"></span></h3>

        <template x-if="list.length === 0">
            <p class="ws-empty"><?php esc_html_e( 'Aún no hay categorías. Crea la primera arriba (o usa Productos con el texto libre y se migrarán cuando elijas una categoría).', 'workshop' ); ?></p>
        </template>

        <table class="ws-table" x-show="list.length > 0">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Ruta', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Subcategorías', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Productos', 'workshop' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="c in list" :key="c.id">
                    <tr :class="!c.active ? 'is-inactive' : ''">
                        <td>
                            <span class="ws-badge" x-show="!c.active" style="margin-right:6px"><?php esc_html_e( 'Inactiva', 'workshop' ); ?></span>
                            <span x-text="'— '.repeat(Math.max(0, c.path.split(' / ').length - 1))"></span>
                            <strong x-text="c.name"></strong>
                            <small class="ws-muted" x-show="c.slug" x-text="' (' + c.slug + ')'"></small>
                        </td>
                        <td x-text="c.children"></td>
                        <td x-text="c.products"></td>
                        <td class="ws-actions" x-show="can">
                            <button class="ws-icon-btn" title="<?php esc_attr_e( 'Editar', 'workshop' ); ?>" @click="edit(c)"><i class="fa-solid fa-pen"></i></button>
                            <button class="ws-icon-btn ws-danger" title="<?php esc_attr_e( 'Eliminar (podar rama)', 'workshop' ); ?>" @click="remove(c)"><i class="fa-solid fa-trash-can"></i></button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

</div>
