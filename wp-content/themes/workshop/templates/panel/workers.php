<?php
/**
 * Panel: trabajadores (asignación de roles y ubicaciones).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

// Solo las ubicaciones habilitadas en el POS se pueden asignar a empleados
// (el vendedor trabaja en el punto de venta): el check de Ubicaciones decide
// qué ubicaciones aparecen al crear/editar un trabajador.
$locations = array_values( array_filter(
    WS_CRUD::get_locations(),
    static fn( $l ) => (int) ( $l->pos_enabled ?? 1 ) === 1
) );
$role_opts = array(
    'ws_storekeeper' => __( 'Almacenero', 'workshop' ),
    'ws_seller'      => __( 'Vendedor/PV', 'workshop' ),
);
// Solo el administrador del sistema puede crear/asignar el rol de dueño.
if ( current_user_can( 'manage_options' ) ) {
    $role_opts = array_merge( array( 'ws_owner' => __( 'Dueño del negocio', 'workshop' ) ), $role_opts );
}
// Sesiones de trabajo: activas (abiertas) y el registro del día.
$sessions_active = array();
$sessions_today  = array();
if ( class_exists( 'WS_Sessions' ) ) {
    $sessions_active = WS_Sessions::active();
    $sessions_today  = WS_Sessions::today();
}
?>
<div x-data="wsWorkers(<?php echo esc_attr( wp_json_encode( array(
    'roleOptions' => $role_opts,
    'locations'   => array_map( fn( $l ) => array( 'id' => (int) $l->id, 'name' => $l->name ), $locations ),
    'isAdmin'     => current_user_can( 'manage_options' ),
) ) ); ?>)">

    <div class="ws-toolbar">
        <div class="ws-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" placeholder="<?php esc_attr_e( 'Buscar…', 'workshop' ); ?>" x-model="search" @input="onSearch()">
        </div>
        <button class="ws-btn ws-btn-primary" @click="openNew()"><i class="fa-solid fa-user-plus"></i> <?php esc_html_e( 'Nuevo trabajador', 'workshop' ); ?></button>
    </div>

    <!-- Sesiones de trabajo activas (check-in/out de la jornada) -->
    <?php if ( ! empty( $sessions_active ) ) : ?>
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-clock-rotate-left"></i> <?php esc_html_e( 'Sesiones de trabajo activas', 'workshop' ); ?></h3>
        <table class="ws-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Trabajador', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Ubicación', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Entró', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Tiempo', 'workshop' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sessions_active as $s ) : ?>
                <tr>
                    <td>
                        <div class="ws-cell-product">
                            <div class="ws-avatar ws-avatar-sm"><span class="ws-dot ws-dot-active"></span><?php echo esc_html( strtoupper( mb_substr( $s->worker_name, 0, 1 ) ) ); ?></div>
                            <strong><?php echo esc_html( $s->worker_name ); ?></strong>
                        </div>
                    </td>
                    <td><?php echo esc_html( $s->location_name ?: '—' ); ?></td>
                    <td><?php echo esc_html( mysql2date( 'H:i', $s->clock_in ) ); ?></td>
                    <td><span class="ws-elapsed" data-in="<?php echo (int) mysql2date( 'U', $s->clock_in ); ?>"><?php echo esc_html( ws_session_duration( $s->clock_in ) ); ?></span></td>
                    <td class="ws-actions">
                        <button class="ws-icon-btn" title="<?php esc_attr_e( 'Cerrar sesión', 'workshop' ); ?>" @click="closeSession(<?php echo (int) $s->id; ?>)"><i class="fa-solid fa-flag-checkered"></i></button>
                        <button class="ws-icon-btn ws-danger" title="<?php esc_attr_e( 'Cerrar y deshabilitar', 'workshop' ); ?>" @click="setDisabled(<?php echo (int) $s->user_id; ?>, <?php echo esc_attr( wp_json_encode( $s->worker_name ) ); ?>, true)"><i class="fa-solid fa-ban"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Registro de sesiones del día -->
    <?php if ( ! empty( $sessions_today ) ) : ?>
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-list-check"></i> <?php esc_html_e( 'Sesiones de hoy', 'workshop' ); ?></h3>
        <table class="ws-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Trabajador', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Ubicación', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Entró', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Salió', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Duración', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sessions_today as $s ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $s->worker_name ); ?></strong></td>
                    <td><?php echo esc_html( $s->location_name ?: '—' ); ?></td>
                    <td><?php echo esc_html( mysql2date( 'H:i', $s->clock_in ) ); ?></td>
                    <td><?php echo $s->clock_out ? esc_html( mysql2date( 'H:i', $s->clock_out ) ) : '—'; ?></td>
                    <td><?php echo esc_html( ws_session_duration( $s->clock_in, $s->clock_out ) ); ?></td>
                    <td>
                        <?php if ( 'open' === $s->status ) : ?>
                            <span class="ws-badge ws-badge-pending"><span class="ws-dot ws-dot-active"></span><?php esc_html_e( 'En curso', 'workshop' ); ?></span>
                        <?php else : ?>
                            <span class="ws-badge ws-badge-completed"><?php esc_html_e( 'Cerrada', 'workshop' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="ws-card">
        <table class="ws-table">
            <thead>
                <tr>
                    <th class="ws-th-sort" @click="sort('display_name')"><?php esc_html_e( 'Trabajador', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('display_name')"></i></th>
                    <th class="ws-th-sort" @click="sort('user_email')"><?php esc_html_e( 'Email', 'workshop' ); ?> <i class="fa-solid" :class="sortIcon('user_email')"></i></th>
                    <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Rol', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Ubicaciones', 'workshop' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="w in workers" :key="w.id">
                    <tr>
                        <td>
                            <div class="ws-cell-product">
                                <div class="ws-avatar ws-avatar-sm" x-text="(w.display_name || '?').charAt(0).toUpperCase()"></div>
                                <strong x-text="w.display_name"></strong>
                            </div>
                        </td>
                        <td x-text="w.user_email"></td>
                        <td>
                            <span class="ws-badge" :class="w.is_disabled ? 'ws-badge-danger' : (w.is_active ? 'ws-badge-accepted' : '')">
                                <span class="ws-dot" :class="(w.is_disabled || !w.is_active) ? 'ws-dot-inactive' : 'ws-dot-active'"></span>
                                <span x-text="w.is_disabled ? 'Deshabilitado' : (w.is_active ? 'Activo' : 'Inactivo')"></span>
                            </span>
                            <span class="ws-last-login" x-text="w.last_login_text || 'Nunca'"></span>
                        </td>
                        <td>
                            <template x-if="w.role === 'ws_owner' && !isAdmin">
                                <span class="ws-badge ws-badge-accepted"><i class="fa-solid fa-crown"></i> <?php esc_html_e( 'Dueño del negocio', 'workshop' ); ?></span>
                            </template>
                            <template x-if="!(w.role === 'ws_owner' && !isAdmin)">
                                <select class="ws-inline-select" x-model="w.role" @change="saveWorker(w)">
                                    <template x-for="(label, key) in roleOptions" :key="key"><option :value="key" x-text="label"></option></template>
                                </select>
                            </template>
                        </td>
                        <td>
                            <span class="ws-muted" @click="showWorker(w)" x-text="(w.locations || []).map(l => l.name).join(', ') || '—'"></span>
                        </td>
                        <td class="ws-actions">
                            <button class="ws-icon-btn" title="Editar" @click="editWorker(w)"><i class="fa-solid fa-pen"></i></button>
                            <button class="ws-icon-btn" title="Asignar ubicaciones" @click="showWorker(w)"><i class="fa-solid fa-location-dot"></i></button>
                            <template x-if="w.is_disabled">
                                <button class="ws-icon-btn" title="Habilitar" @click="setDisabled(w.id, w.display_name, false)"><i class="fa-solid fa-user-check"></i></button>
                            </template>
                            <template x-if="!w.is_disabled">
                                <button class="ws-icon-btn ws-danger" title="Deshabilitar (bloquea su acceso)" @click="setDisabled(w.id, w.display_name, true)"><i class="fa-solid fa-ban"></i></button>
                            </template>
                            <button class="ws-icon-btn ws-danger" title="Eliminar" @click="deleteWorker(w.id, w.display_name)"><i class="fa-solid fa-trash-can"></i></button>
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

    <!-- Modal nuevo trabajador -->
    <div class="ws-modal" x-show="newOpen" x-cloak @keydown.escape.window="newOpen=false">
        <div class="ws-modal-backdrop" @click="newOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3><?php esc_html_e( 'Nuevo trabajador', 'workshop' ); ?></h3>
                <button class="ws-cart-close" @click="newOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="createWorker" class="ws-form">
                <div class="ws-form-grid">
                    <label class="ws-field"><span><?php esc_html_e( 'Nombre', 'workshop' ); ?></span><input type="text" x-model="newUser.display_name"></label>
                    <label class="ws-field"><span><?php esc_html_e( 'Usuario *', 'workshop' ); ?></span><input type="text" x-model="newUser.username" required></label>
                    <label class="ws-field"><span><?php esc_html_e( 'Email *', 'workshop' ); ?></span><input type="email" x-model="newUser.email" required></label>
                    <label class="ws-field"><span><?php esc_html_e( 'Contraseña *', 'workshop' ); ?></span><input type="password" x-model="newUser.password" required></label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Rol *', 'workshop' ); ?></span>
                        <select x-model="newUser.role" required>
                            <option value="">—</option>
                            <template x-for="(label, key) in roleOptions" :key="key"><option :value="key" x-text="label"></option></template>
                        </select>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Ubicaciones', 'workshop' ); ?></span>
                        <div class="ws-check-group ws-grid-2">
                            <template x-for="l in locations" :key="l.id">
                                <label class="ws-check"><input type="checkbox" :value="l.id" x-model="newUser.locations"><span x-text="l.name"></span></label>
                            </template>
                        </div>
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="newOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Crear', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal editar trabajador -->
    <div class="ws-modal" x-show="editOpen" x-cloak @keydown.escape.window="editOpen=false">
        <div class="ws-modal-backdrop" @click="editOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3><?php esc_html_e( 'Editar trabajador', 'workshop' ); ?></h3>
                <button class="ws-cart-close" @click="editOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="saveEditWorker" class="ws-form">
                <div class="ws-form-grid">
                    <label class="ws-field"><span><?php esc_html_e( 'Nombre', 'workshop' ); ?></span><input type="text" x-model="editUser.display_name" required></label>
                    <label class="ws-field"><span><?php esc_html_e( 'Email *', 'workshop' ); ?></span><input type="email" x-model="editUser.email" required></label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Rol *', 'workshop' ); ?></span>
                        <select x-model="editUser.role" required>
                            <template x-for="(label, key) in roleOptions" :key="key"><option :value="key" x-text="label"></option></template>
                        </select>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Nueva contraseña (opcional)', 'workshop' ); ?></span>
                        <input type="password" x-model="editUser.password" placeholder="Dejar vacío para no cambiar">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Ubicaciones', 'workshop' ); ?></span>
                        <div class="ws-check-group ws-grid-2">
                            <template x-for="l in locations" :key="l.id">
                                <label class="ws-check"><input type="checkbox" :value="l.id" x-model="editUser.locations"><span x-text="l.name"></span></label>
                            </template>
                        </div>
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-secondary" @click="editOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal asignar ubicaciones -->
    <div class="ws-modal" x-show="workerOpen" x-cloak @keydown.escape.window="workerOpen=false">
        <div class="ws-modal-backdrop" @click="workerOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="'Ubicaciones de ' + workerUser.name"></h3>
                <button class="ws-cart-close" @click="workerOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="ws-check-group">
                <template x-for="l in locations" :key="l.id">
                    <label class="ws-check"><input type="checkbox" :value="l.id" x-model="workerUser.locations"><span x-text="l.name"></span></label>
                </template>
            </div>
            <div class="ws-modal-foot">
                <button type="button" class="ws-btn ws-btn-secondary" @click="workerOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                <button type="button" class="ws-btn ws-btn-primary" @click="saveWorkerLocations()"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
            </div>
        </div>
    </div>
</div>
