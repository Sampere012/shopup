<?php
/**
 * Panel: Control de gastos.
 *
 * Registra los gastos del negocio con su FECHA (el gasto es por mes) y
 * calcula la utilidad mensual: ingresos del mes (pedidos + ventas POS)
 * menos los gastos del mismo mes, todo filtrado por fecha.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WS_Expenses' ) ) {
    echo '<p class="ws-empty">' . esc_html__( 'Módulo no disponible.', 'workshop' ) . '</p>';
    return;
}

$ws_exp_can = ws_can( 'expenses_manage' );
$ws_exp_now = current_time( 'timestamp' );
$ws_exp_month = (int) gmdate( 'n', $ws_exp_now );
$ws_exp_year  = (int) gmdate( 'Y', $ws_exp_now );
$ws_exp_currency = ws_currency_symbol();

// Gastos del mes actual (servidor) + resumen del mes.
$ws_exp_list = array_map( static function ( $e ) {
    return array(
        'id'            => (int) $e->id,
        'concept'       => (string) $e->concept,
        'amount'        => (float) $e->amount,
        'category'      => (string) $e->category,
        'note'          => (string) ( $e->note ?? '' ),
        'location_id'   => (int) ( $e->location_id ?? 0 ),
        'location_name' => (string) WS_Expenses::location_name( $e ),
        'date_label'    => mysql2date( 'd/m/Y', $e->expense_date ),
        'date_raw'      => gmdate( 'Y-m-d', strtotime( $e->expense_date ) ),
    );
}, WS_Expenses::all( $ws_exp_year, $ws_exp_month ) );
$ws_exp_summary = WS_Expenses::month_summary( $ws_exp_year, $ws_exp_month );
$ws_exp_locations = ws_user_locations();

// Meses en español para el selector.
$ws_months = array(
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
);
?>
<div class="ws-exp-page" x-data="wsExpenses(<?php echo esc_attr( wp_json_encode( array(
    'can'      => $ws_exp_can,
    'currency' => $ws_exp_currency,
    'months'   => $ws_months,
    'year'     => $ws_exp_year,
    'month'    => $ws_exp_month,
    'list'     => $ws_exp_list,
    'summary'  => $ws_exp_summary,
    'locations'=> array_map( static function ( $l ) {
        return array( 'id' => (int) $l->id, 'name' => (string) $l->name );
    }, $ws_exp_locations ),
) ) ); ?>)">

    <div class="ws-alert ws-alert-info">
        <i class="fa-solid fa-money-bill-wave"></i>
        <span>
            <?php esc_html_e( 'Registra aquí los gastos de tu negocio con su fecha (el gasto es por mes). Elige si el gasto es GENERAL (se reparte a todas las ubicaciones) o de UNA ubicación concreta. Las utilidades se calculan en Reportes por fecha y por ubicación.', 'workshop' ); ?>
        </span>
    </div>

    <!-- Filtro de mes para la lista + total del mes -->
    <div class="ws-card ws-exp-summary">
        <div class="ws-exp-month-picker">
            <label class="ws-field">
                <span><?php esc_html_e( 'Mes', 'workshop' ); ?></span>
                <select x-model.number="month" @change="load()">
                    <template x-for="(mname, mnum) in months" :key="mnum">
                        <option :value="Number(mnum)" x-text="mname"></option>
                    </template>
                </select>
            </label>
            <label class="ws-field">
                <span><?php esc_html_e( 'Año', 'workshop' ); ?></span>
                <select x-model.number="year" @change="load()">
                    <template x-for="y in years()" :key="y">
                        <option :value="y" x-text="y"></option>
                    </template>
                </select>
            </label>
        </div>
        <div class="ws-exp-total">
            <span><?php esc_html_e( 'Gastos del mes', 'workshop' ); ?></span>
            <strong x-text="money(total())"></strong>
        </div>
    </div>

    <?php if ( $ws_exp_can ) : ?>
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-plus"></i> <span x-text="editingId ? '<?php esc_attr_e( 'Editar gasto', 'workshop' ); ?>' : '<?php esc_attr_e( 'Nuevo gasto', 'workshop' ); ?>'"></span></h3>
        <form class="ws-form ws-grid-2" @submit.prevent="save()">
            <label class="ws-field">
                <span><?php esc_html_e( 'Concepto *', 'workshop' ); ?></span>
                <input type="text" x-model="form.concept" required maxlength="255" placeholder="<?php esc_attr_e( 'Ej.: Pago alquiler local', 'workshop' ); ?>">
            </label>
            <label class="ws-field">
                <span><?php esc_html_e( 'Monto *', 'workshop' ); ?></span>
                <input type="number" step="0.01" min="0.01" x-model.number="form.amount" required>
            </label>
            <label class="ws-field">
                <span><?php esc_html_e( 'Ubicación', 'workshop' ); ?></span>
                <select x-model.number="form.location_id">
                    <option value="0"><?php esc_html_e( 'General (todas las ubicaciones)', 'workshop' ); ?></option>
                    <template x-for="l in locations" :key="l.id">
                        <option :value="l.id" x-text="l.name"></option>
                    </template>
                </select>
                <p class="ws-muted" style="font-size:.8em;margin:4px 0 0"><?php esc_html_e( 'General = se reparte a todas las ubicaciones en los reportes. Si es de un punto de venta, solo cuenta para esa ubicación.', 'workshop' ); ?></p>
            </label>
            <label class="ws-field">
                <span><?php esc_html_e( 'Categoría', 'workshop' ); ?></span>
                <input type="text" x-model="form.category" maxlength="120" list="ws-exp-cats" placeholder="<?php esc_attr_e( 'Ej.: Alquiler, Servicios, Salarios…', 'workshop' ); ?>">
                <datalist id="ws-exp-cats">
                    <template x-for="c in categories" :key="c"><option :value="c"></option></template>
                </datalist>
            </label>
            <label class="ws-field">
                <span><?php esc_html_e( 'Fecha del gasto *', 'workshop' ); ?></span>
                <input type="date" x-model="form.expense_date" required>
            </label>
            <label class="ws-field ws-span-2">
                <span><?php esc_html_e( 'Nota', 'workshop' ); ?></span>
                <textarea x-model="form.note" rows="2" placeholder="<?php esc_attr_e( 'Detalle opcional…', 'workshop' ); ?>"></textarea>
            </label>
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
        <h3 class="ws-card-title"><i class="fa-solid fa-list"></i> <?php esc_html_e( 'Gastos del mes', 'workshop' ); ?> <span class="ws-ann-count" x-text="list.length"></span></h3>
        <template x-if="list.length === 0">
            <p class="ws-empty"><?php esc_html_e( 'Aún no hay gastos registrados en este mes.', 'workshop' ); ?></p>
        </template>
        <table class="ws-table" x-show="list.length > 0">
            <thead><tr><th><?php esc_html_e( 'Concepto', 'workshop' ); ?></th><th><?php esc_html_e( 'Categoría', 'workshop' ); ?></th><th><?php esc_html_e( 'Ubicación', 'workshop' ); ?></th><th><?php esc_html_e( 'Fecha', 'workshop' ); ?></th><th><?php esc_html_e( 'Monto', 'workshop' ); ?></th><th></th></tr></thead>
            <tbody>
                <template x-for="e in list" :key="e.id">
                    <tr>
                        <td><strong x-text="e.concept"></strong><small class="ws-muted" x-show="e.note" x-text="e.note"></small></td>
                        <td x-text="e.category || '—'"></td>
                        <td>
                            <span class="ws-badge ws-badge-general" x-show="!e.location_id"><?php esc_html_e( 'General', 'workshop' ); ?></span>
                            <span x-show="e.location_id" x-text="e.location_name || ('#' + e.location_id)"></span>
                        </td>
                        <td class="ws-muted" x-text="e.date_label"></td>
                        <td class="ws-strong" x-text="money(e.amount)"></td>
                        <td class="ws-actions" x-show="can">
                            <button class="ws-icon-btn" title="<?php esc_attr_e( 'Duplicar (repetir el gasto el mes siguiente)', 'workshop' ); ?>" @click="duplicate(e)"><i class="fa-solid fa-copy"></i></button>
                            <button class="ws-icon-btn" title="<?php esc_attr_e( 'Editar', 'workshop' ); ?>" @click="edit(e)"><i class="fa-solid fa-pen"></i></button>
                            <button class="ws-icon-btn ws-danger" title="<?php esc_attr_e( 'Eliminar', 'workshop' ); ?>" @click="remove(e)"><i class="fa-solid fa-trash-can"></i></button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

</div>
