<?php
/**
 * Panel: trabajadores (asignación de roles y ubicaciones).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$workers   = WS_CRUD::get_workers();
$locations = WS_CRUD::get_locations();
$role_opts = array(
    'ws_storekeeper' => __( 'Almacenero', 'workshop' ),
    'ws_seller'      => __( 'Vendedor/PV', 'workshop' ),
);
// Solo el administrador del sistema puede crear/asignar el rol de dueño.
if ( current_user_can( 'manage_options' ) ) {
    $role_opts = array_merge( array( 'ws_owner' => __( 'Dueño del negocio', 'workshop' ) ), $role_opts );
}
$ws_active_threshold = strtotime( '-30 days' );
foreach ( $workers as $w ) {
    $last = get_user_meta( $w->ID, 'ws_last_login', true );
    $w->last_login = $last ? strtotime( $last ) : 0;
    $w->is_active  = $w->last_login && $w->last_login >= $ws_active_threshold;
    $w->is_disabled = ws_worker_disabled( $w->ID );
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
) ) ); ?>)">

    <div class="ws-toolbar">
        <div></div>
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
        <table class="ws-table" data-sortable data-ts="workers">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Trabajador', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Rol', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Ubicaciones', 'workshop' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $workers as $w ) : ?>
                    <?php
                    $wloc = WS_CRUD::get_user_locations( $w->ID );
                    // El rol de la fila se deriva de los roles REALES del usuario
                    // (no de role_opts, que oculta ws_owner a los no-admins): así
                    // las filas de dueños muestran la insignia y nunca un select.
                    $role = '';
                    foreach ( array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ) as $r ) {
                        if ( in_array( $r, (array) $w->roles, true ) ) {
                            $role = $r;
                            break;
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <div class="ws-cell-product">
                                <div class="ws-avatar ws-avatar-sm"><?php echo esc_html( strtoupper( substr( $w->display_name, 0, 1 ) ) ); ?></div>
                                <strong><?php echo esc_html( $w->display_name ); ?></strong>
                            </div>
                        </td>
                        <td x-text="'<?php echo esc_js( $w->user_email ); ?>'"></td>
                        <td>
                            <?php if ( $w->is_disabled ) : ?>
                                <span class="ws-badge ws-badge-danger"><span class="ws-dot ws-dot-inactive"></span><?php esc_html_e( 'Deshabilitado', 'workshop' ); ?></span>
                            <?php elseif ( $w->is_active ) : ?>
                                <span class="ws-badge ws-badge-accepted"><span class="ws-dot ws-dot-active"></span><?php esc_html_e( 'Activo', 'workshop' ); ?></span>
                            <?php else : ?>
                                <span class="ws-badge"><span class="ws-dot ws-dot-inactive"></span><?php esc_html_e( 'Inactivo', 'workshop' ); ?></span>
                            <?php endif; ?>
                            <span class="ws-last-login"><?php echo $w->last_login ? esc_html( mysql2date( 'd/m/Y H:i', gmdate( 'Y-m-d H:i:s', $w->last_login ) ) ) : esc_html__( 'Nunca', 'workshop' ); ?></span>
                        </td>
                        <td>
                            <?php if ( 'ws_owner' === $role && ! current_user_can( 'manage_options' ) ) : ?>
                                <span class="ws-badge ws-badge-accepted"><i class="fa-solid fa-crown"></i> <?php esc_html_e( 'Dueño del negocio', 'workshop' ); ?></span>
                            <?php else : ?>
                                <select class="ws-inline-select" @change="saveWorker(<?php echo (int) $w->ID; ?>, $event.target.value, []); showWorker(<?php echo (int) $w->ID; ?>, <?php echo esc_attr( wp_json_encode( $wloc ) ); ?>)">
                                    <?php foreach ( $role_opts as $key => $label ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $role ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="ws-muted" x-data @click="showWorker(<?php echo (int) $w->ID; ?>, <?php echo esc_attr( wp_json_encode( $wloc ) ); ?>)"><?php echo esc_html( implode( ', ', array_map( fn( $l ) => $l->name, $wloc ) ) ?: '—' ); ?></span>
                        </td>
                        <td class="ws-actions">
                            <button class="ws-icon-btn" title="Editar" @click="editWorker(<?php echo (int) $w->ID; ?>, <?php echo esc_attr( wp_json_encode( $w->display_name ) ); ?>, <?php echo esc_attr( wp_json_encode( $w->user_email ) ); ?>, <?php echo esc_attr( wp_json_encode( $role ) ); ?>, <?php echo esc_attr( wp_json_encode( $wloc ) ); ?>)"><i class="fa-solid fa-pen"></i></button>
                            <button class="ws-icon-btn" title="Asignar ubicaciones" @click="showWorker(<?php echo (int) $w->ID; ?>, <?php echo esc_attr( wp_json_encode( $wloc ) ); ?>)"><i class="fa-solid fa-location-dot"></i></button>
                            <?php if ( $w->is_disabled ) : ?>
                                <button class="ws-icon-btn" title="Habilitar" @click="setDisabled(<?php echo (int) $w->ID; ?>, <?php echo esc_attr( wp_json_encode( $w->display_name ) ); ?>, false)"><i class="fa-solid fa-user-check"></i></button>
                            <?php else : ?>
                                <button class="ws-icon-btn ws-danger" title="Deshabilitar (bloquea su acceso)" @click="setDisabled(<?php echo (int) $w->ID; ?>, <?php echo esc_attr( wp_json_encode( $w->display_name ) ); ?>, true)"><i class="fa-solid fa-ban"></i></button>
                            <?php endif; ?>
                            <button class="ws-icon-btn ws-danger" title="Eliminar" @click="deleteWorker(<?php echo (int) $w->ID; ?>, <?php echo esc_attr( wp_json_encode( $w->display_name ) ); ?>)"><i class="fa-solid fa-trash-can"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
