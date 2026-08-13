<?php
/**
 * Panel: Anuncios.
 *
 * El dueño envía mensajes y notificaciones ancladas a los usuarios de SU
 * negocio (dueños, almaceneros y vendedores) — el badge solo se ve en su
 * tienda/panel. El admin del sistema puede enviar anuncios globales (scope
 * 'site') que llegan a todos los negocios y a la portada. Cada anuncio llega
 * como notificación (campana + asistente) y, si está anclado, como banner en
 * el panel de los destinatarios.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ws_announcements_panel' ) ) {
    echo '<p class="ws-empty">' . esc_html__( 'Módulo no disponible.', 'workshop' ) . '</p>';
    return;
}

$ws_ann_can      = ws_announcement_can();
$ws_ann_can_site = ws_announcement_can_site();
$ws_ann_list     = ws_announcements_json();
$ws_ann_types    = array(
    'info'    => array( 'label' => __( 'Información', 'workshop' ), 'icon' => 'fa-circle-info' ),
    'success' => array( 'label' => __( 'Éxito', 'workshop' ), 'icon' => 'fa-circle-check' ),
    'warning' => array( 'label' => __( 'Aviso', 'workshop' ), 'icon' => 'fa-triangle-exclamation' ),
    'danger'  => array( 'label' => __( 'Urgente', 'workshop' ), 'icon' => 'fa-circle-exclamation' ),
);
?>
<div class="ws-ann-page" x-data="wsAnuncios(<?php echo esc_attr( wp_json_encode( array(
    'can'     => $ws_ann_can,
    'canSite' => $ws_ann_can_site,
    'list'    => $ws_ann_list,
) ) ); ?>)">

    <div class="ws-alert ws-alert-info">
        <i class="fa-solid fa-bullhorn"></i>
        <span>
            <?php
            if ( $ws_ann_can_site ) {
                esc_html_e( 'Los anuncios de "Mi negocio" se envían a los usuarios de este negocio (dueños, almaceneros y vendedores): les llega la notificación y, si los anclas, aparecen como banner en su panel. Los anuncios de "Todo el sitio" van a todos los negocios y a la portada.', 'workshop' );
            } else {
                esc_html_e( 'Los anuncios se envían a TODOS los usuarios de tu negocio (dueños, almaceneros y vendedores): les llega la notificación y el asistente la muestra como mensaje automático. Si los anclas, además aparecen como banner destacado en el panel de tu negocio.', 'workshop' );
            }
            ?>
        </span>
    </div>

    <?php if ( $ws_ann_can ) : ?>
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-plus"></i> <span x-text="editingId ? '<?php esc_attr_e( 'Editar anuncio', 'workshop' ); ?>' : '<?php esc_attr_e( 'Nuevo anuncio', 'workshop' ); ?>'"></span></h3>
        <form class="ws-form" @submit.prevent="save()">
            <label class="ws-field">
                <span><?php esc_html_e( 'Título', 'workshop' ); ?></span>
                <input type="text" x-model="form.title" required maxlength="255" placeholder="<?php esc_attr_e( 'Ej. Promoción de fin de semana', 'workshop' ); ?>">
            </label>
            <label class="ws-field">
                <span><?php esc_html_e( 'Mensaje', 'workshop' ); ?></span>
                <textarea x-model="form.message" rows="3" placeholder="<?php esc_attr_e( 'Escribe el detalle del anuncio…', 'workshop' ); ?>"></textarea>
            </label>
            <div class="ws-grid-2">
                <label class="ws-field">
                    <span><?php esc_html_e( 'Tipo', 'workshop' ); ?></span>
                    <select x-model="form.type">
                        <template x-for="(t, key) in types" :key="key">
                            <option :value="key" x-text="t.label"></option>
                        </template>
                    </select>
                </label>
                <?php if ( $ws_ann_can_site ) : ?>
                <label class="ws-field">
                    <span><?php esc_html_e( 'Alcance', 'workshop' ); ?></span>
                    <select x-model="scope">
                        <option value="business"><?php esc_html_e( 'Mi negocio (tienda y equipo)', 'workshop' ); ?></option>
                        <option value="site"><?php esc_html_e( 'Todo el sitio (landing y todos los negocios)', 'workshop' ); ?></option>
                    </select>
                </label>
                <?php endif; ?>
                <label class="ws-field ws-ann-pin-check">
                    <span><?php esc_html_e( 'Fijar como banner', 'workshop' ); ?></span>
                    <label class="ws-check">
                        <input type="checkbox" x-model="form.pinned">
                        <span><i class="fa-solid fa-thumbtack"></i> <?php esc_html_e( 'Mostrar anclado en el panel', 'workshop' ); ?></span>
                    </label>
                </label>
            </div>
            <div class="ws-ann-form-actions">
                <button class="ws-btn ws-btn-primary" type="submit" :disabled="saving">
                    <i class="fa-solid" :class="saving ? 'fa-spinner fa-spin' : (editingId ? 'fa-floppy-disk' : 'fa-paper-plane')"></i>
                    <span x-text="saving ? '<?php esc_attr_e( 'Guardando…', 'workshop' ); ?>' : (editingId ? '<?php esc_attr_e( 'Guardar cambios', 'workshop' ); ?>' : (scope === 'site' ? '<?php esc_attr_e( 'Enviar a todo el sitio', 'workshop' ); ?>' : '<?php esc_attr_e( 'Enviar a mi equipo', 'workshop' ); ?>'))"></span>
                </button>
                <button class="ws-btn ws-btn-secondary" type="button" x-show="editingId" @click="resetForm()"><i class="fa-solid fa-xmark"></i> <?php esc_html_e( 'Cancelar edición', 'workshop' ); ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-list"></i> <?php esc_html_e( 'Anuncios enviados', 'workshop' ); ?> <span class="ws-ann-count" x-text="list.length"></span></h3>

        <template x-if="list.length === 0">
            <p class="ws-empty"><?php esc_html_e( 'Aún no has enviado anuncios. Crea el primero arriba.', 'workshop' ); ?></p>
        </template>

        <div class="ws-ann-list">
            <template x-for="a in list" :key="a.id">
                <div class="ws-ann-item" :class="'ws-ann-' + a.type + (a.active ? '' : ' is-inactive')">
                    <span class="ws-ann-item-ico" :class="'ws-ann-ico-' + a.type"><i class="fa-solid" :class="(types[a.type] || {}).icon || 'fa-bullhorn'"></i></span>
                    <div class="ws-ann-item-body">
                        <strong x-text="a.title"></strong>
                        <p x-text="a.message"></p>
                        <em x-text="a.date"></em>
                        <span class="ws-ann-tag ws-ann-tag-site" x-show="a.scope === 'site'"><i class="fa-solid fa-globe"></i> <?php esc_html_e( 'Todo el sitio', 'workshop' ); ?></span>
                        <span class="ws-ann-tag ws-ann-tag-pin" x-show="a.pinned"><i class="fa-solid fa-thumbtack"></i> <?php esc_html_e( 'Anclado', 'workshop' ); ?></span>
                        <span class="ws-ann-tag" x-show="!a.active"><i class="fa-solid fa-eye-slash"></i> <?php esc_html_e( 'Inactivo', 'workshop' ); ?></span>
                    </div>
                    <template x-if="can">
                        <div class="ws-ann-item-actions">
                            <button type="button" class="ws-btn ws-btn-sm ws-btn-secondary" @click="edit(a)" title="<?php esc_attr_e( 'Editar', 'workshop' ); ?>"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="ws-btn ws-btn-sm ws-btn-secondary" @click="toggle(a, 'pinned')" :title="a.pinned ? '<?php esc_attr_e( 'Desfijar', 'workshop' ); ?>' : '<?php esc_attr_e( 'Fijar como banner', 'workshop' ); ?>'">
                                <i class="fa-solid" :class="a.pinned ? 'fa-thumbtack-slash' : 'fa-thumbtack'"></i>
                            </button>
                            <button type="button" class="ws-btn ws-btn-sm ws-btn-secondary" @click="toggle(a, 'active')" :title="a.active ? '<?php esc_attr_e( 'Desactivar', 'workshop' ); ?>' : '<?php esc_attr_e( 'Activar', 'workshop' ); ?>'">
                                <i class="fa-solid" :class="a.active ? 'fa-eye-slash' : 'fa-eye'"></i>
                            </button>
                            <button type="button" class="ws-btn ws-btn-sm ws-btn-danger" @click="remove(a)" title="<?php esc_attr_e( 'Eliminar', 'workshop' ); ?>"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

</div>
<script>
/* Componente Alpine de Anuncios, registrado EN LA PÁGINA (igual que el resto
   de módulos del panel): no depende de que theme.js haya cargado y registrado
   componentes antes de que Alpine arranque. */
document.addEventListener('alpine:init', function () {
    Alpine.data('wsAnuncios', function (data) {
        return {
            can: !!data.can,
            canSite: !!data.canSite,
            list: data.list || [],
            scope: 'business',
            types: {
                info:    { label: '<?php echo esc_js( __( 'Información', 'workshop' ) ); ?>', icon: 'fa-circle-info' },
                success: { label: '<?php echo esc_js( __( 'Éxito', 'workshop' ) ); ?>', icon: 'fa-circle-check' },
                warning: { label: '<?php echo esc_js( __( 'Aviso', 'workshop' ) ); ?>', icon: 'fa-triangle-exclamation' },
                danger:  { label: '<?php echo esc_js( __( 'Urgente', 'workshop' ) ); ?>', icon: 'fa-circle-exclamation' }
            },
            editingId: 0,
            saving: false,
            form: { title: '', message: '', type: 'info', pinned: false },

            resetForm: function () {
                this.editingId = 0;
                this.form = { title: '', message: '', type: 'info', pinned: false };
            },

            edit: function (a) {
                this.editingId = a.id;
                this.form = {
                    title: a.title || '',
                    message: a.message || '',
                    type: a.type || 'info',
                    pinned: !!a.pinned
                };
                if (a.scope === 'site') { this.scope = 'site'; }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },

            api: function (action, extra, cb) {
                var body = new URLSearchParams();
                body.append('action', action);
                body.append('ws_nonce', (window.WS && WS.nonce) || '');
                Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
                fetch((window.WS && WS.ajaxUrl) || '/wp-admin/admin-ajax.php', {
                    method: 'POST', credentials: 'same-origin', body: body
                }).then(function (r) { return r.json(); }).then(cb)
                  .catch(function () { cb({ success: false, data: { msg: 'Sin conexión.' } }); });
            },

            save: function () {
                if (!this.form.title.trim()) { return; }
                var self = this;
                this.saving = true;
                this.api('ws_announcement_save', {
                    id: this.editingId || 0,
                    title: this.form.title,
                    message: this.form.message,
                    type: this.form.type,
                    scope: this.canSite ? this.scope : 'business',
                    pinned: this.form.pinned ? '1' : '0'
                }, function (json) {
                    self.saving = false;
                    if (json && json.success) {
                        self.list = json.data.list;
                        self.resetForm();
                        window.Swal ? Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: json.data.msg, showConfirmButton: false, timer: 2500 })
                                    : alert(json.data.msg);
                    } else {
                        window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: (json && json.data && json.data.msg) || 'No se pudo guardar.' })
                                    : alert((json && json.data && json.data.msg) || 'No se pudo guardar.');
                    }
                });
            },

            toggle: function (a, field) {
                var self = this;
                this.api('ws_announcement_toggle', { id: a.id, field: field }, function (json) {
                    if (json && json.success) { self.list = json.data.list; }
                });
            },

            remove: function (a) {
                var self = this;
                function doRemove() {
                    self.api('ws_announcement_delete', { id: a.id }, function (json) {
                        if (json && json.success) { self.list = json.data.list; }
                    });
                }
                if (window.Swal) {
                    Swal.fire({
                        title: '<?php echo esc_js( __( 'Eliminar anuncio', 'workshop' ) ); ?>',
                        text: '<?php echo esc_js( __( '¿Seguro? Se quitará para todos sus destinatarios.', 'workshop' ) ); ?>',
                        icon: 'warning', showCancelButton: true,
                        confirmButtonText: '<?php echo esc_js( __( 'Sí, eliminar', 'workshop' ) ); ?>',
                        cancelButtonText: '<?php echo esc_js( __( 'Cancelar', 'workshop' ) ); ?>'
                    }).then(function (r) { if (r.isConfirmed) { doRemove(); } });
                } else if (confirm('<?php echo esc_js( __( '¿Eliminar este anuncio?', 'workshop' ) ); ?>')) { doRemove(); }
            }
        };
    });
});
</script>