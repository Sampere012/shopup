<?php
/**
 * Panel: reportes.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$locations = ws_user_locations();
$loc_ids   = array_map( fn( $l ) => (int) $l->id, $locations );

global $wpdb;
$ph   = $loc_ids ? implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) ) : '0';
$args = $loc_ids ? $loc_ids : array( 0 );

// IMPORTANTE: tablas por negocio (ws_table_name); el prefijo fijo (wp_ws_)
// apuntaría al negocio por defecto y los reportes saldrían vacíos.
$orders_table      = ws_table_name( 'orders' );
$movements_table   = ws_table_name( 'movements' );
$order_items_table = ws_table_name( 'order_items' );

// Ventas por día (últimos 14 días).
$sales = array();
if ( $loc_ids ) {
    $sales = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(created_at) AS d, SUM(total) AS total, COUNT(*) AS n
         FROM {$orders_table}
         WHERE location_id IN ({$ph}) AND status IN ('accepted','completed')
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(created_at) ORDER BY d ASC",
        ...$args
    ) );
}

// Movimientos por tipo.
$by_type = array();
if ( $loc_ids ) {
    $by_type = $wpdb->get_results( $wpdb->prepare(
        "SELECT type, COUNT(*) AS n, COALESCE(SUM(qty),0) AS qty
         FROM {$movements_table}
         WHERE location_id IN ({$ph})
         GROUP BY type",
        ...$args
    ) );
}

// Top productos vendidos (por pedidos aceptados).
$top = array();
if ( $loc_ids ) {
    $top = $wpdb->get_results( $wpdb->prepare(
        "SELECT oi.product_name, SUM(oi.qty) AS qty, SUM(oi.price * oi.qty) AS total
         FROM {$order_items_table} oi
         INNER JOIN {$orders_table} o ON o.id = oi.order_id
         WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed')
         GROUP BY oi.product_id, oi.product_name
         ORDER BY qty DESC LIMIT 10",
        ...$args
    ) );
}

$currency = ws_currency_symbol();
?>
<div class="ws-kpis">
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-green"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div><span><?php esc_html_e( 'Ventas 14 días', 'workshop' ); ?></span><strong><?php echo ws_money( array_sum( array_map( fn( $s ) => (float) $s->total, $sales ) ), $currency ); ?></strong></div>
    </div>
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-blue"><i class="fa-solid fa-receipt"></i></div>
        <div><span><?php esc_html_e( 'Pedidos 14 días', 'workshop' ); ?></span><strong><?php echo esc_html( array_sum( array_map( fn( $s ) => (int) $s->n, $sales ) ) ); ?></strong></div>
    </div>
    <div class="ws-kpi">
        <div class="ws-kpi-icon ws-kpi-amber"><i class="fa-solid fa-right-left"></i></div>
        <div><span><?php esc_html_e( 'Movimientos', 'workshop' ); ?></span><strong><?php echo esc_html( array_sum( array_map( fn( $t ) => (int) $t->n, $by_type ) ) ); ?></strong></div>
    </div>
</div>

<div class="ws-grid-2">
    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-chart-column"></i> <?php esc_html_e( 'Ventas por día', 'workshop' ); ?></h3>
        <?php if ( empty( $sales ) ) : ?>
            <p class="ws-empty"><?php esc_html_e( 'Sin ventas aún.', 'workshop' ); ?></p>
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
        <p class="ws-empty"><?php esc_html_e( 'Sin ventas aún.', 'workshop' ); ?></p>
    <?php else : ?>
    <table class="ws-table" data-sortable data-ts="reports-top">
        <thead><tr><th>#</th><th><?php esc_html_e( 'Producto', 'workshop' ); ?></th><th><?php esc_html_e( 'Unidades', 'workshop' ); ?></th><th><?php esc_html_e( 'Total', 'workshop' ); ?></th></tr></thead>
        <tbody>
        <?php $i = 1; foreach ( $top as $p ) : ?>
            <tr><td><?php echo esc_html( $i++ ); ?></td><td><?php echo esc_html( $p->product_name ); ?></td><td><?php echo esc_html( $p->qty ); ?></td><td><?php echo ws_money( $p->total, $currency ); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
