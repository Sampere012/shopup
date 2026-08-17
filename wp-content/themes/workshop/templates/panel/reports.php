<?php
/**
 * Panel: reportes.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$role = ws_user_role();
if ( ! $role ) {
    $role = get_query_var( 'ws_role' ) ? (string) get_query_var( 'ws_role' ) : 'owner';
}

// Filtros desde la URL (ws_loc = ubicación, ws_from/ws_to = rango de fechas).
// Los datos se comparten con la exportación a Excel (inc/reports.php).
$filters = ws_reports_filters( false );
$data    = ws_reports_data( $filters );
$utils   = ws_reports_utilities( $filters );

$sales    = $data['sales'];
$by_type  = $data['by_type'];
$top      = $data['top'];
$currency = ws_currency_symbol( $filters['location_id'] ? (int) $filters['location_id'] : 0 );
$base_url = ws_panel_url( $role, 'reports' );

// Valores para los campos de fecha (vacío en "todo el historial").
$date_from = $filters['period_start'] > '1900-01-01' ? $filters['period_start'] : '';
$date_to   = $filters['period_end'];

// Contexto de negocio para la exportación vía AJAX (negocios con slug).
$ws_biz_slug = '';
$ws_biz      = ws_current_business();
if ( $ws_biz && ! empty( $ws_biz->slug ) ) {
    $ws_biz_slug = $ws_biz->slug;
}
?>
<div class="ws-stock-head ws-reports-head">
    <div class="ws-stock-filters">
        <select id="ws-reports-loc" aria-label="<?php esc_attr_e( 'Ubicación', 'workshop' ); ?>">
            <option value="0"><?php esc_html_e( 'Todas las ubicaciones', 'workshop' ); ?></option>
            <?php foreach ( $filters['locations'] as $l ) : ?>
                <option value="<?php echo (int) $l->id; ?>" <?php selected( $filters['location_id'], (int) $l->id ); ?>><?php echo esc_html( $l->name ); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="ws-report-date">
            <input type="date" id="ws-reports-from" value="<?php echo esc_attr( $date_from ); ?>" aria-label="<?php esc_attr_e( 'Desde', 'workshop' ); ?>">
            <span><?php esc_html_e( 'desde', 'workshop' ); ?></span>
        </label>
        <label class="ws-report-date">
            <input type="date" id="ws-reports-to" value="<?php echo esc_attr( $date_to ); ?>" aria-label="<?php esc_attr_e( 'Hasta', 'workshop' ); ?>">
            <span><?php esc_html_e( 'hasta', 'workshop' ); ?></span>
        </label>
        <button type="button" class="ws-btn" id="ws-reports-reset" title="<?php esc_attr_e( 'Últimos 14 días', 'workshop' ); ?>">
            <i class="fa-solid fa-rotate-left"></i>
        </button>
    </div>
    <button type="button" class="ws-btn ws-btn-primary" id="ws-reports-export">
        <i class="fa-solid fa-file-excel"></i> <?php esc_html_e( 'Exportar Excel', 'workshop' ); ?>
    </button>
</div>

<div class="ws-reports-currency"><i class="fa-solid fa-coins"></i> <?php echo esc_html( sprintf( __( 'Moneda del reporte: %s', 'workshop' ), $currency ) ); ?></div>

<div class="ws-kpis">
    <?php
        // Total de ventas convertido a la moneda del reporte (si hay varias
        // monedas, sumar sin convertir mezclaría CUP y USD).
        $total_kpi = array_sum( array_map( fn( $c ) => ws_convert( (float) $c->total, $c->currency, $currency ), (array) $data['currency_totals'] ) );
    ?>
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-green"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div><span><?php echo esc_html( sprintf( __( 'Ventas · %s', 'workshop' ), $filters['period_label'] ) ); ?></span><strong><?php echo ws_money( $total_kpi, $currency ); ?></strong></div>
    </div>
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-blue"><i class="fa-solid fa-receipt"></i></div>
        <div><span><?php esc_html_e( 'Pedidos', 'workshop' ); ?></span><strong><?php echo esc_html( number_format_i18n( $data['total_orders'] ) ); ?></strong></div>
    </div>
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-amber"><i class="fa-solid fa-right-left"></i></div>
        <div><span><?php esc_html_e( 'Movimientos', 'workshop' ); ?></span><strong><?php echo esc_html( number_format_i18n( $data['total_moves'] ) ); ?></strong></div>
    </div>
</div>

<?php if ( ! empty( $data['currency_totals'] ) && count( $data['currency_totals'] ) > 0 ) : ?>
<div class="ws-card">
    <h3 class="ws-card-title"><i class="fa-solid fa-coins"></i> <?php esc_html_e( 'Ventas por moneda', 'workshop' ); ?></h3>
    <p class="ws-muted" style="margin:0 0 10px;font-size:.82em"><?php esc_html_e( 'Cada moneda con su total real (los montos no se mezclan) y su equivalente en la moneda base del negocio.', 'workshop' ); ?></p>
    <table class="ws-table" data-sortable data-ts="reports-currency">
        <thead><tr>
            <th><?php esc_html_e( 'Moneda', 'workshop' ); ?></th>
            <th><?php esc_html_e( 'Pedidos (tienda)', 'workshop' ); ?></th>
            <th><?php esc_html_e( 'Ventas POS', 'workshop' ); ?></th>
            <th><?php esc_html_e( 'Total', 'workshop' ); ?></th>
            <th><?php esc_html_e( 'Equivalente', 'workshop' ); ?> <?php echo esc_html( $currency ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $data['currency_totals'] as $ct ) : ?>
            <tr>
                <td><strong><?php echo esc_html( $ct->currency ); ?></strong></td>
                <td><?php echo esc_html( number_format_i18n( $ct->orders ) ); ?></td>
                <td><?php echo esc_html( number_format_i18n( $ct->pos ) ); ?></td>
                <td><?php echo ws_money( $ct->total, $ct->currency ); ?></td>
                <td class="ws-muted"><?php echo ws_money( ws_convert( (float) $ct->total, $ct->currency, $currency ), $currency ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Utilidades: ingresos menos gastos, por mes y por punto de venta -->
<div class="ws-grid-2">
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-scale-balanced"></i> <?php esc_html_e( 'Utilidades mensuales', 'workshop' ); ?></h3>
        <?php if ( empty( $utils['months'] ) ) : ?>
            <p class="ws-empty"><?php esc_html_e( 'Sin movimientos en el período.', 'workshop' ); ?></p>
        <?php else : ?>
        <table class="ws-table" data-sortable data-ts="reports-utilities">
            <thead><tr>
                <th><?php esc_html_e( 'Mes', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Ingresos', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Ganancia (venta − costo)', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Gastos', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Utilidad', 'workshop' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( array_reverse( $utils['months'] ) as $m ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $m['label'] ); ?></strong></td>
                    <td><?php echo ws_money( $m['income'], $currency ); ?></td>
                    <td class="ws-strong" style="color:<?php echo $m['profit'] >= 0 ? 'var(--ws-success)' : 'var(--ws-danger)'; ?>"><?php echo ws_money( $m['profit'], $currency ); ?></td>
                    <td><?php echo ws_money( $m['expenses'], $currency ); ?></td>
                    <td class="ws-strong" style="color:<?php echo $m['utility'] >= 0 ? 'var(--ws-success)' : 'var(--ws-danger)'; ?>"><?php echo ws_money( $m['utility'], $currency ); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td><strong><?php esc_html_e( 'Total del período', 'workshop' ); ?></strong></td>
                    <td><strong><?php echo ws_money( $utils['totals']['income'], $currency ); ?></strong></td>
                    <td class="ws-strong" style="color:<?php echo $utils['totals']['profit'] >= 0 ? 'var(--ws-success)' : 'var(--ws-danger)'; ?>"><strong><?php echo ws_money( $utils['totals']['profit'], $currency ); ?></strong></td>
                    <td><strong><?php echo ws_money( $utils['totals']['expenses'], $currency ); ?></strong></td>
                    <td class="ws-strong" style="color:<?php echo $utils['totals']['utility'] >= 0 ? 'var(--ws-success)' : 'var(--ws-danger)'; ?>"><strong><?php echo ws_money( $utils['totals']['utility'], $currency ); ?></strong></td>
                </tr>
            </tbody>
        </table>
        <p class="ws-muted" style="margin:10px 0 0;font-size:.82em"><?php esc_html_e( 'Ganancia = (precio de venta − costo) × unidades vendidas, guardada en el momento de cada venta. Utilidad = ingresos (pedidos + ventas POS) − gastos del negocio. Los gastos generales se reparten a todas las ubicaciones; los de una ubicación concreta solo cuentan para esa ubicación.', 'workshop' ); ?></p>
        <?php endif; ?>
    </div>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-store"></i> <?php esc_html_e( 'Utilidad por punto de venta', 'workshop' ); ?></h3>
        <?php
            // Puntos de venta con ingresos o gastos en el período.
            $ws_util_locs = array_unique( array_merge(
                array_keys( (array) $utils['by_loc'] ),
                array_keys( (array) $utils['exp_by_loc'] )
            ) );
            sort( $ws_util_locs );
        ?>
        <?php if ( empty( $ws_util_locs ) ) : ?>
            <p class="ws-empty"><?php esc_html_e( 'Sin ingresos ni gastos en el período.', 'workshop' ); ?></p>
        <?php else : ?>
        <table class="ws-table" data-sortable data-ts="reports-by-loc">
            <thead><tr>
                <th><?php esc_html_e( 'Punto de venta', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Ingresos', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Ganancia (venta − costo)', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Gastos', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Utilidad', 'workshop' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $ws_util_locs as $lid ) :
                    $inc = (float) ( $utils['by_loc'][ $lid ] ?? 0 );
                    $exp = (float) ( $utils['exp_by_loc'][ $lid ] ?? 0 );
                    $prf = (float) ( $utils['profit_by_loc'][ $lid ] ?? 0 );
                    $utl = $inc - $exp;
                    $lname = '#' . $lid;
                    foreach ( $utils['locations'] as $l ) {
                        if ( (int) $l->id === (int) $lid ) { $lname = $l->name; break; }
                    }
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $lname ); ?></strong></td>
                    <td><?php echo ws_money( $inc, $currency ); ?></td>
                    <td class="ws-strong" style="color:<?php echo $prf >= 0 ? 'var(--ws-success)' : 'var(--ws-danger)'; ?>"><?php echo ws_money( $prf, $currency ); ?></td>
                    <td><?php echo ws_money( $exp, $currency ); ?></td>
                    <td class="ws-strong" style="color:<?php echo $utl >= 0 ? 'var(--ws-success)' : 'var(--ws-danger)'; ?>"><?php echo ws_money( $utl, $currency ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="ws-muted" style="margin:10px 0 0;font-size:.82em"><?php esc_html_e( 'Los gastos generales se suman a cada punto de venta; los de una ubicación solo a la suya.', 'workshop' ); ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="ws-grid-2">
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-chart-column"></i> <?php esc_html_e( 'Ventas por día', 'workshop' ); ?></h3>
        <?php if ( empty( $sales ) ) : ?>
            <p class="ws-empty"><?php esc_html_e( 'Sin ventas en el período.', 'workshop' ); ?></p>
        <?php else : ?>
        <table class="ws-table" data-sortable data-ts="reports-sales">
            <thead><tr><th><?php esc_html_e( 'Día', 'workshop' ); ?></th><th><?php esc_html_e( 'Pedidos', 'workshop' ); ?></th><th><?php esc_html_e( 'Total', 'workshop' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( array_reverse( $sales ) as $s ) : ?>
                <tr><td><?php echo esc_html( mysql2date( 'd/m/Y', $s->d ) ); ?></td><td><?php echo esc_html( $s->n ); ?></td><td><?php echo ws_money( $s->total, $currency ); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-list"></i> <?php esc_html_e( 'Movimientos por tipo', 'workshop' ); ?></h3>
        <?php if ( empty( $by_type ) ) : ?>
            <p class="ws-empty"><?php esc_html_e( 'Sin movimientos.', 'workshop' ); ?></p>
        <?php else : ?>
        <table class="ws-table" data-sortable data-ts="reports-movements">
            <thead><tr><th><?php esc_html_e( 'Tipo', 'workshop' ); ?></th><th><?php esc_html_e( 'Cantidad', 'workshop' ); ?></th><th><?php esc_html_e( 'Total', 'workshop' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $by_type as $t ) : ?>
                <tr>
                    <td><?php echo esc_html( ucfirst( $t->type ) ); ?></td>
                    <td><?php echo esc_html( $t->n ); ?></td>
                    <td><?php echo esc_html( $t->qty ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="ws-card">
    <h3 class="ws-card-title"><i class="fa-solid fa-trophy"></i> <?php esc_html_e( 'Top productos vendidos', 'workshop' ); ?></h3>
    <?php if ( empty( $top ) ) : ?>
        <p class="ws-empty"><?php esc_html_e( 'Sin ventas en el período.', 'workshop' ); ?></p>
    <?php else : ?>
    <table class="ws-table" data-sortable data-ts="reports-top">
        <thead><tr><th>#</th><th><?php esc_html_e( 'Producto', 'workshop' ); ?></th><th><?php esc_html_e( 'Unidades', 'workshop' ); ?></th><th><?php esc_html_e( 'Transacciones', 'workshop' ); ?></th><th><?php esc_html_e( 'Total', 'workshop' ); ?></th></tr></thead>
        <tbody>
        <?php $i = 1; foreach ( $top as $p ) : ?>
            <tr><td><?php echo esc_html( $i++ ); ?></td><td><?php echo esc_html( $p->product_name ); ?></td><td><?php echo esc_html( $p->qty ); ?></td><td><?php echo esc_html( $p->orders ); ?></td><td><?php echo ws_money( $p->total, $currency ); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<script>
(function () {
    var base = <?php echo wp_json_encode( $base_url ); ?>;

    function apply() {
        var loc = document.getElementById('ws-reports-loc').value;
        var from = document.getElementById('ws-reports-from').value;
        var to = document.getElementById('ws-reports-to').value;
        var sep = base.indexOf('?') === -1 ? '?' : '&';
        var params = 'ws_loc=' + encodeURIComponent(loc);
        if (from || to) {
            params += '&ws_from=' + encodeURIComponent(from) + '&ws_to=' + encodeURIComponent(to);
        } else {
            params += '&ws_period=14';
        }
        location.href = base + sep + params;
    }

    document.getElementById('ws-reports-loc').addEventListener('change', apply);
    var fromEl = document.getElementById('ws-reports-from');
    var toEl = document.getElementById('ws-reports-to');
    fromEl.addEventListener('change', apply);
    toEl.addEventListener('change', apply);
    document.getElementById('ws-reports-reset').addEventListener('click', function () {
        fromEl.value = '';
        toEl.value = '';
        apply();
    });

    var btn = document.getElementById('ws-reports-export');
    if (btn) btn.addEventListener('click', function () {
        if (btn.dataset.busy) return;
        btn.dataset.busy = '1';
        btn.classList.add('is-loading');
        var icon = btn.querySelector('i');
        if (icon) icon.className = 'fa-solid fa-spinner fa-spin';

        var body = new URLSearchParams({
            action: 'ws_reports_export',
            ws_nonce: WS.nonce,
            ws_loc: document.getElementById('ws-reports-loc').value,
            ws_from: fromEl.value,
            ws_to: toEl.value,
            ws_biz: <?php echo wp_json_encode( $ws_biz_slug ); ?>
        });

        fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (res) {
                var ct = res.headers.get('Content-Type') || '';
                if (ct.indexOf('application/json') !== -1) {
                    return res.json().then(function (j) { throw new Error((j.data && j.data.msg) || 'No se pudo exportar.'); });
                }
                return Promise.all([res.blob(), Promise.resolve(res.headers.get('X-WS-Filename') || ('reporte-' + Date.now() + '.' + (res.headers.get('X-WS-Export') || 'xlsx')))]);
            })
            .then(function (tuple) {
                var blob = tuple[0], filename = tuple[1];
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
            })
            .catch(function (err) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: '<?php esc_html_e( 'Exportar', 'workshop' ); ?>', text: (err && err.message) || '<?php esc_html_e( 'No se pudo exportar.', 'workshop' ); ?>' });
                } else {
                    console.error('[ws]', (err && err.message) || '<?php esc_html_e( 'No se pudo exportar.', 'workshop' ); ?>');
                }
            })
            .finally(function () {
                delete btn.dataset.busy;
                btn.classList.remove('is-loading');
                if (icon) icon.className = 'fa-solid fa-file-excel';
            });
    });
})();
</script>