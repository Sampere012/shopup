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
            <?php esc_html_e( 'Organiza tu catálogo en un árbol de categorías y subcategorías (máximo 3 niveles). Crea la primera categoría arriba y pulsa + en una categoría del acordeón para añadirle una subcategoría. Los productos usan estas categorías (en su formulario y en la tienda). Al eliminar una categoría se poda toda su rama: sus productos pasan a la categoría padre.', 'workshop' ); ?>
        </span>
    </div>

    <?php if ( $ws_cat_can ) : ?>
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-plus"></i> <span x-text="editingId ? '<?php esc_attr_e( 'Editar categoría', 'workshop' ); ?>' : (form.parent_id ? '<?php esc_attr_e( 'Nueva subcategoría', 'workshop' ); ?>' : '<?php esc_attr_e( 'Nueva categoría', 'workshop' ); ?>')"></span></h3>
        <form class="ws-form ws-grid-2" @submit.prevent="save()">
            <label class="ws-field ws-span-2">
                <span><?php esc_html_e( 'Nombre *', 'workshop' ); ?></span>
                <input type="text" x-model="form.name" required maxlength="150" placeholder="<?php esc_attr_e( 'Ej.: Bebidas', 'workshop' ); ?>">
            </label>
            <div class="ws-field ws-span-2">
                <span><?php esc_html_e( 'Categoría padre', 'workshop' ); ?></span>
                <div class="ws-cat-parent">
                    <template x-if="form.parent_id">
                        <span class="ws-badge ws-badge-general">
                            <i class="fa-solid fa-sitemap"></i>
                            <span x-text="'<?php esc_attr_e( 'Padre', 'workshop' ); ?>: ' + parentLabel()"></span>
                            <button type="button" class="ws-cat-parent-clear" @click="form.parent_id = 0" :title="'<?php esc_attr_e( 'Quitar padre (categoría raíz)', 'workshop' ); ?>'"><i class="fa-solid fa-xmark"></i></button>
                        </span>
                    </template>
                    <template x-if="!form.parent_id">
                        <span class="ws-muted"><?php esc_html_e( '— Categoría raíz —', 'workshop' ); ?></span>
                    </template>
                </div>
            </div>
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
                <button class="ws-btn ws-btn-secondary" type="button" x-show="editingId || form.parent_id" @click="resetForm()"><i class="fa-solid fa-xmark"></i> <?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-list"></i> <?php esc_html_e( 'Categorías', 'workshop' ); ?> <span class="ws-ann-count" x-text="list.length"></span></h3>

        <template x-if="list.length === 0">
            <p class="ws-empty"><?php esc_html_e( 'Aún no hay categorías. Crea la primera arriba (o usa Productos con el texto libre y se migrarán cuando elijas una categoría).', 'workshop' ); ?></p>
        </template>

        <div class="ws-cat-accordion" x-show="list.length > 0" x-cloak>
            <template x-for="c in list" :key="c.id">
                <div class="ws-cat-node" :class="isOpen(c.id) && 'is-open'" x-show="isVisible(c)" x-cloak>
                    <div class="ws-cat-head" @click="hasChildren(c) && toggle(c.id)">
                        <span class="ws-cat-indent" :style="'width:' + (depth(c) * 20) + 'px'"></span>
                        <span class="ws-cat-chevron">
                            <i class="fa-solid" :class="hasChildren(c)
                                ? (isOpen(c.id) ? 'fa-chevron-down' : 'fa-chevron-right')
                                : 'fa-circle'"></i>
                        </span>
                        <span class="ws-badge" x-show="!c.active" style="margin-right:2px"><?php esc_html_e( 'Inactiva', 'workshop' ); ?></span>
                        <strong class="ws-cat-name" x-text="c.name"></strong>
                        <small class="ws-muted" x-show="c.slug" x-text="'(' + c.slug + ')'"></small>
                        <span class="ws-cat-count" x-show="c.children" :title="c.children + ' <?php esc_attr_e( 'subcategorías', 'workshop' ); ?>'" x-text="c.children"></span>
                        <span class="ws-cat-products"><i class="fa-solid fa-box"></i> <span x-text="c.products"></span></span>
                        <span class="ws-cat-actions" x-show="can" @click.stop>
                            <button class="ws-icon-btn" title="<?php esc_attr_e( 'Añadir subcategoría', 'workshop' ); ?>" @click="addChild(c)" x-show="canAddChild(c)"><i class="fa-solid fa-plus"></i></button>
                            <button class="ws-icon-btn" title="<?php esc_attr_e( 'Editar', 'workshop' ); ?>" @click="edit(c)"><i class="fa-solid fa-pen"></i></button>
                            <button class="ws-icon-btn ws-danger" title="<?php esc_attr_e( 'Eliminar (podar rama)', 'workshop' ); ?>" @click="remove(c)"><i class="fa-solid fa-trash-can"></i></button>
                        </span>
                    </div>
                </div>
            </template>
        </div>
    </div>

</div>
