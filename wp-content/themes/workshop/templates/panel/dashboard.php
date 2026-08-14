<?php
/**
 * Dashboard por rol con KPIs.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$role     = ws_user_role();
$user     = wp_get_current_user();
$locations = ws_user_locations();
$loc_ids  = array_map( fn( $l ) => (int) $l->id, $locations );

global $wpdb;
$loc_placeholders = $loc_ids ? implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) ) : '0';
$args = $loc_ids ? $loc_ids : array( 0 );

// IMPORTANTE: las tablas del tema están separadas por negocio
// (ws_table_name). Consultarlas con el prefijo fijo (wp_ws_) apuntaría a
// las tablas del negocio por defecto y devolvería 0 para el resto.
$products_table = ws_table_name( 'products' );
$stock_table    = ws_table_name( 'stock' );
$orders_table   = ws_table_name( 'orders' );
$pos_table      = ws_table_name( 'pos_sales' );

$products_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$products_table} WHERE active=1" );

// Stock bajo con el STOCK DEL GRUPO CONECTADO (stock compartido): el total
// de las ubicaciones vinculadas cuenta para el mínimo, no el stock de cada
// ubicación por separado.
$low_stock = $loc_ids ? WS_Stock::count_low_stock_group_rows( $loc_ids ) : 0;

$pending_orders = 0;
if ( $loc_ids ) {
    $pending_orders = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$orders_table} WHERE location_id IN ({$loc_placeholders}) AND status='pending'",
        ...$args
    ) );
}

// Ventas de hoy: pedidos aceptados/completados + ventas POS completadas
// (el POS guarda sus ventas en ws_pos_sales, no en ws_orders).
$sales_today = 0.0;
if ( $loc_ids ) {
    $sales_today += (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$orders_table}
         WHERE location_id IN ({$loc_placeholders}) AND status IN ('accepted','completed') AND DATE(created_at)=CURDATE()",
        ...$args
    ) );
    $sales_today += (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$pos_table}
         WHERE location_id IN ({$loc_placeholders}) AND status='completed' AND DATE(created_at)=CURDATE()",
        ...$args
    ) );
}
$currency = ws_currency_symbol();

// Ventas de hoy con formato compacto cuando el monto es muy grande
// (ej: 2.112.520 -> "2,1 M CUP"; 2.500.000.000 -> "2,5 mil M CUP"). El
// monto exacto queda en el tooltip al pasar el cursor.
$sales_today_display = ws_money( $sales_today, $currency );
if ( abs( $sales_today ) >= 1000000 ) {
    $sales_today_display = ws_compact_number( $sales_today ) . ' ' . $currency;
}
?>
<div class="ws-kpis">
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-blue"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div><span><?php esc_html_e( 'Productos activos', 'workshop' ); ?></span><strong><?php echo esc_html( number_format_i18n( $products_total ) ); ?></strong></div>
    </div>
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-amber"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div><span><?php esc_html_e( 'Stock bajo', 'workshop' ); ?></span><strong><?php echo esc_html( number_format_i18n( $low_stock ) ); ?></strong></div>
    </div>
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-purple"><i class="fa-solid fa-clock"></i></div>
        <div><span><?php esc_html_e( 'Pedidos pendientes', 'workshop' ); ?></span><strong><?php echo esc_html( number_format_i18n( $pending_orders ) ); ?></strong></div>
    </div>
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-green"><i class="fa-solid fa-circle-dollar"></i></div>
        <div><span><?php esc_html_e( 'Ventas de hoy', 'workshop' ); ?></span><strong title="<?php echo esc_attr( ws_money( $sales_today, $currency ) ); ?>"><?php echo esc_html( $sales_today_display ); ?></strong></div>
    </div>
</div>

<div class="ws-card">
    <h3 class="ws-card-title"><i class="fa-solid fa-location-dot"></i> <?php esc_html_e( 'Mis ubicaciones', 'workshop' ); ?></h3>
    <div class="ws-locations-grid">
        <?php foreach ( $locations as $loc ) : ?>
            <div class="ws-location-mini">
                <div class="ws-location-mini-head">
                    <i class="fa-solid <?php echo 'pv' === $loc->type ? 'fa-store' : 'fa-warehouse'; ?>"></i>
                    <div>
                        <strong><?php echo esc_html( $loc->name ); ?></strong>
                        <small><?php echo 'pv' === $loc->type ? esc_html__( 'Punto de venta', 'workshop' ) : esc_html__( 'Almacén', 'workshop' ); ?></small>
                    </div>
                </div>
                <a class="ws-link" href="<?php echo esc_url( ws_store_url( $loc ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver tienda pública', 'workshop' ); ?> <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ( 'seller' === $role ) : ?>
<div class="ws-card">
    <h3 class="ws-card-title"><i class="fa-solid fa-receipt"></i> <?php esc_html_e( 'Pedidos recientes', 'workshop' ); ?></h3>
    <?php
    $recent = WS_Orders::all( array( 'location_id' => $loc_ids ? $loc_ids[0] : 0 ) );
    if ( empty( $recent ) ) : ?>
        <p class="ws-empty"><?php esc_html_e( 'No hay pedidos todavía.', 'workshop' ); ?></p>
    <?php else : ?>
    <table class="ws-table" data-sortable data-ts="dashboard-recent">
        <thead><tr><th><?php esc_html_e( 'Nº', 'workshop' ); ?></th><th><?php esc_html_e( 'Cliente', 'workshop' ); ?></th><th><?php esc_html_e( 'Total', 'workshop' ); ?></th><th><?php esc_html_e( 'Estado', 'workshop' ); ?></th><th><?php esc_html_e( 'Fecha', 'workshop' ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( array_slice( $recent, 0, 8 ) as $o ) : ?>
            <tr>
                <td><?php echo esc_html( $o->number ); ?></td>
                <td><?php echo esc_html( $o->customer_name ); ?></td>
                <td><?php echo ws_money( $o->total, $o->currency ); ?></td>
                <td><span class="ws-badge ws-badge-<?php echo esc_attr( $o->status ); ?>"><?php echo esc_html( WS_Orders::status_label( $o->status ) ); ?></span></td>
                <td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $o->created_at ) ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>
