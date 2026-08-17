<?php
/**
 * Panel: turnos con calendario.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$locations = ws_user_locations();
$workers   = WS_CRUD::get_workers();
$can_manage = ws_can( 'shifts_manage' );
// Cada trabajador llega con las ubicaciones donde trabaja: al elegirlo en el
// formulario, el desplegable de Ubicación se filtra a SOLO esas (el calendario
// muestra el turno en el punto de venta donde el trabajador realmente labora).
$workers   = array_map( fn( $w ) => array(
    'id'        => (int) $w->ID,
    'name'      => $w->display_name,
    'locations' => array_map( fn( $l ) => (int) $l->id, WS_CRUD::get_user_locations( $w->ID ) ),
), $workers );
?>
<div x-data="wsShifts(<?php echo esc_attr( wp_json_encode( array(
    'locations' => array_map( fn( $l ) => array( 'id' => (int) $l->id, 'name' => $l->name ), $locations ),
    'workers'   => $workers,
    'canManage' => $can_manage,
) ) ); ?>)" x-init="initCalendar()">

    <div class="ws-card">
        <div class="ws-stock-head">
            <h3 class="ws-card-title" style="margin:0"><i class="fa-solid fa-calendar-days"></i> <?php esc_html_e( 'Turnos', 'workshop' ); ?></h3>
            <div class="ws-stock-filters">
                <select x-model="locationFilter" @change="calendarReload()">
                    <option value=""><?php esc_html_e( 'Todas las ubicaciones', 'workshop' ); ?></option>
                    <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                </select>
            </div>
        </div>
        <div id="ws-calendar" class="ws-calendar"></div>
    </div>

    <div class="ws-modal" x-show="shiftOpen" x-cloak @keydown.escape.window="shiftOpen=false">
        <div class="ws-modal-backdrop" @click="shiftOpen=false"></div>
        <div class="ws-modal-box">
            <div class="ws-modal-head">
                <h3 x-text="shift.id ? 'Editar turno' : 'Nuevo turno'"></h3>
                <button class="ws-cart-close" @click="shiftOpen=false"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form @submit.prevent="saveShift" class="ws-form">
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Trabajador *', 'workshop' ); ?></span>
                        <select x-model="shift.user_id" required @change="onWorkerChange()">
                            <template x-for="w in workers" :key="w.id"><option :value="w.id" x-text="w.name"></option></template>
                        </select>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Ubicación *', 'workshop' ); ?></span>
                        <select x-model="shift.location_id" required>
                            <template x-if="availableLocations().length === 0"><option value="">—</option></template>
                            <template x-for="l in availableLocations()" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                        </select>
                        <small class="ws-muted" x-show="workerLocations().length"><?php esc_html_e( 'Solo las ubicaciones donde trabaja el trabajador.', 'workshop' ); ?></small>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Fecha *', 'workshop' ); ?></span>
                        <input type="date" x-model="shift.shift_date" required>
                    </label>
                    <label class="ws-field ws-grid-2">
                        <span><?php esc_html_e( 'Inicio', 'workshop' ); ?></span>
                        <input type="time" x-model="shift.time_start" required>
                    </label>
                    <label class="ws-field ws-grid-2">
                        <span><?php esc_html_e( 'Fin', 'workshop' ); ?></span>
                        <input type="time" x-model="shift.time_end" required>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Nota', 'workshop' ); ?></span>
                        <input type="text" x-model="shift.note">
                    </label>
                </div>
                <div class="ws-modal-foot">
                    <button type="button" class="ws-btn ws-btn-danger" x-show="shift.id" @click="deleteShift()"><i class="fa-solid fa-trash-can"></i> <?php esc_html_e( 'Eliminar', 'workshop' ); ?></button>
                    <button type="button" class="ws-btn ws-btn-secondary" @click="shiftOpen=false"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                    <button type="submit" class="ws-btn ws-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
