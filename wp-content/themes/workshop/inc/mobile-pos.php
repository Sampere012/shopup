<?php
/**
 * Endpoints de sincronización POS y arqueo para la app móvil y dashboard web.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Historial de ventas POS: obtener lista filtrada
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_mobile_pos_sales_history', 'ws_ajax_mobile_pos_sales_history' );
add_action( 'wp_ajax_nopriv_ws_mobile_pos_sales_history', 'ws_ajax_mobile_pos_sales_history' );
function ws_ajax_mobile_pos_sales_history() {
    ws_mobile_auth_user();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'No autorizado.', 'workshop' ) ) );
    }

    $location_ids = ws_user_location_ids();
    if ( empty( $location_ids ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin ubicaciones asignadas.', 'workshop' ) ) );
    }

    $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );

    $args = array(
        'location_ids' => $location_id ? array( $location_id ) : $location_ids,
        'date_from'    => $date_from,
        'date_to'      => $date_to,
        'orderby'      => 'created_at',
        'order'        => 'DESC',
        'limit'        => 500,
    );

    if ( ! class_exists( 'WS_POS' ) ) {
        wp_send_json_error( array( 'msg' => 'POS no disponible.' ) );
    }

    $sales = WS_POS::get_sales( $args );
    $data  = array();
    foreach ( $sales as $sale ) {
        $data[] = array(
            'id'              => (int) $sale->id,
            'number'          => (string) $sale->number,
            'location_id'     => (int) $sale->location_id,
            'location_name'   => (string) $sale->location_name,
            'seller_id'       => (int) $sale->seller_id,
            'seller_name'     => (string) $sale->seller_name,
            'customer_name'   => (string) $sale->customer_name,
            'total'           => (float) $sale->total,
            'currency'        => (string) $sale->currency,
            'payment_method'  => (string) $sale->payment_method,
            'status'          => (string) $sale->status,
            'created_at'      => (string) $sale->created_at,
            'client_ref'      => (string) ( $sale->client_ref ?? '' ),
        );
    }

    wp_send_json_success( array( 'sales' => $data, 'count' => count( $data ) ) );
}

/* -------------------------------------------------------------------------
 * Detalles de una venta POS: artículos y montos
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_mobile_pos_sale_detail', 'ws_ajax_mobile_pos_sale_detail' );
add_action( 'wp_ajax_nopriv_ws_mobile_pos_sale_detail', 'ws_ajax_mobile_pos_sale_detail' );
function ws_ajax_mobile_pos_sale_detail() {
    ws_mobile_auth_user();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'No autorizado.', 'workshop' ) ) );
    }

    $sale_id = (int) ( $_POST['sale_id'] ?? 0 );
    if ( ! $sale_id ) {
        wp_send_json_error( array( 'msg' => __( 'ID de venta requerido.', 'workshop' ) ) );
    }

    if ( ! class_exists( 'WS_POS' ) ) {
        wp_send_json_error( array( 'msg' => 'POS no disponible.' ) );
    }

    global $wpdb;
    $location_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT location_id FROM " . ws_table_name( 'pos_sales' ) . " WHERE id = %d",
        $sale_id
    ) );

    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Venta no encontrada o sin acceso.', 'workshop' ) ) );
    }

    $sale = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . ws_table_name( 'pos_sales' ) . " WHERE id = %d",
        $sale_id
    ) );

    if ( ! $sale ) {
        wp_send_json_error( array( 'msg' => __( 'Venta no encontrada.', 'workshop' ) ) );
    }

    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . ws_table_name( 'pos_sale_items' ) . " WHERE sale_id = %d",
        $sale_id
    ) );

    $items_data = array();
    foreach ( $items as $item ) {
        $items_data[] = array(
            'id'            => (int) $item->id,
            'product_id'    => (int) $item->product_id,
            'combo_id'      => (int) $item->combo_id,
            'product_name'  => (string) $item->product_name,
            'qty'           => (float) $item->qty,
            'price'         => (float) $item->price,
            'cost_price'    => (float) $item->cost_price,
            'discount'      => (float) $item->discount,
            'subtotal'      => (float) $item->subtotal,
        );
    }

    wp_send_json_success( array(
        'sale' => array(
            'id'              => (int) $sale->id,
            'number'          => (string) $sale->number,
            'location_id'     => (int) $sale->location_id,
            'seller_id'       => (int) $sale->seller_id,
            'customer_name'   => (string) $sale->customer_name,
            'customer_doc'    => (string) $sale->customer_doc,
            'customer_phone'  => (string) $sale->customer_phone,
            'subtotal'        => (float) $sale->subtotal,
            'discount'        => (float) $sale->discount,
            'total'           => (float) $sale->total,
            'currency'        => (string) $sale->currency,
            'payment_method'  => (string) $sale->payment_method,
            'cash_amount'     => (float) $sale->cash_amount,
            'transfer_amount' => (float) $sale->transfer_amount,
            'status'          => (string) $sale->status,
            'created_at'      => (string) $sale->created_at,
        ),
        'items' => $items_data,
    ) );
}

/* -------------------------------------------------------------------------
 * Historial de arqueo/apertura de caja: obtener lista
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_mobile_pos_cash_history', 'ws_ajax_mobile_pos_cash_history' );
add_action( 'wp_ajax_nopriv_ws_mobile_pos_cash_history', 'ws_ajax_mobile_pos_cash_history' );
function ws_ajax_mobile_pos_cash_history() {
    ws_mobile_auth_user();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'No autorizado.', 'workshop' ) ) );
    }

    $location_ids = ws_user_location_ids();
    if ( empty( $location_ids ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin ubicaciones asignadas.', 'workshop' ) ) );
    }

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $status      = sanitize_text_field( $_POST['status'] ?? '' );
    $date_from   = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to     = sanitize_text_field( $_POST['date_to'] ?? '' );

    global $wpdb;
    $where = array( '1=1' );

    if ( $location_id ) {
        if ( ! in_array( $location_id, $location_ids, true ) ) {
            wp_send_json_error( array( 'msg' => __( 'Ubicación no autorizada.', 'workshop' ) ) );
        }
        $where[] = $wpdb->prepare( 'location_id = %d', $location_id );
    } else {
        $ids = array_values( array_map( 'intval', $location_ids ) );
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $where[] = $wpdb->prepare( "location_id IN ({$ph})", ...$ids );
    }

    if ( $status ) {
        $where[] = $wpdb->prepare( 'status = %s', $status );
    }

    if ( $date_from ) {
        $where[] = $wpdb->prepare( 'opened_at >= %s', $date_from . ' 00:00:00' );
    }

    if ( $date_to ) {
        $where[] = $wpdb->prepare( 'opened_at <= %s', $date_to . ' 23:59:59' );
    }

    $sql = "SELECT c.*, l.name as location_name, u.display_name as seller_name
            FROM " . ws_table_name( 'pos_cash' ) . " c
            LEFT JOIN " . ws_table_name( 'locations' ) . " l ON l.id = c.location_id
            LEFT JOIN {$wpdb->users} u ON u.ID = c.seller_id
            WHERE " . implode( ' AND ', $where ) . "
            ORDER BY c.opened_at DESC
            LIMIT 500";

    $cash_records = $wpdb->get_results( $sql );

    $data = array();
    foreach ( $cash_records as $cash ) {
        $data[] = array(
            'id'              => (int) $cash->id,
            'location_id'     => (int) $cash->location_id,
            'location_name'   => (string) $cash->location_name,
            'seller_id'       => (int) $cash->seller_id,
            'seller_name'     => (string) $cash->seller_name,
            'opening_amount'  => (float) $cash->opening_amount,
            'opening_note'    => (string) $cash->opening_note,
            'opened_at'       => (string) $cash->opened_at,
            'closing_amount'  => (float) $cash->closing_amount,
            'closing_note'    => (string) $cash->closing_note,
            'closed_at'       => (string) ( $cash->closed_at ?? '' ),
            'status'          => (string) $cash->status,
        );
    }

    wp_send_json_success( array( 'registers' => $data, 'count' => count( $data ) ) );
}

/* -------------------------------------------------------------------------
 * Detalles de arqueo: cuadre de caja y conteo de stock
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_mobile_pos_cash_detail', 'ws_ajax_mobile_pos_cash_detail' );
add_action( 'wp_ajax_nopriv_ws_mobile_pos_cash_detail', 'ws_ajax_mobile_pos_cash_detail' );
function ws_ajax_mobile_pos_cash_detail() {
    ws_mobile_auth_user();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'No autorizado.', 'workshop' ) ) );
    }

    $cash_id = (int) ( $_POST['cash_id'] ?? 0 );
    if ( ! $cash_id ) {
        wp_send_json_error( array( 'msg' => __( 'ID de caja requerido.', 'workshop' ) ) );
    }

    global $wpdb;
    $location_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT location_id FROM " . ws_table_name( 'pos_cash' ) . " WHERE id = %d",
        $cash_id
    ) );

    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Registro de caja no encontrado o sin acceso.', 'workshop' ) ) );
    }

    $cash = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . ws_table_name( 'pos_cash' ) . " WHERE id = %d",
        $cash_id
    ) );

    if ( ! $cash ) {
        wp_send_json_error( array( 'msg' => __( 'Registro de caja no encontrado.', 'workshop' ) ) );
    }

    $counts = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . ws_table_name( 'pos_cash_counts' ) . " WHERE register_id = %d",
        $cash_id
    ) );

    $counts_data = array();
    foreach ( $counts as $count ) {
        $counts_data[] = array(
            'id'             => (int) $count->id,
            'product_id'     => (int) $count->product_id,
            'product_name'   => (string) $count->product_name,
            'virtual_qty'    => (float) $count->virtual_qty,
            'physical_qty'   => (float) $count->physical_qty,
            'diff'           => (float) $count->diff,
            'created_at'     => (string) $count->created_at,
        );
    }

    wp_send_json_success( array(
        'cash' => array(
            'id'              => (int) $cash->id,
            'location_id'     => (int) $cash->location_id,
            'seller_id'       => (int) $cash->seller_id,
            'opening_amount'  => (float) $cash->opening_amount,
            'opening_note'    => (string) $cash->opening_note,
            'opened_at'       => (string) $cash->opened_at,
            'closing_amount'  => (float) $cash->closing_amount,
            'closing_note'    => (string) $cash->closing_note,
            'closed_at'       => (string) ( $cash->closed_at ?? '' ),
            'status'          => (string) $cash->status,
        ),
        'counts' => $counts_data,
    ) );
}

/* -------------------------------------------------------------------------
 * Historial de precios de un producto (para la gráfica de fluctuación):
 * devuelve la serie completa ordenada por fecha, para que la app la pinte
 * por semana / mes / año sin recomputar en cada cambio de pestaña.
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_mobile_price_history', 'ws_ajax_mobile_price_history' );
add_action( 'wp_ajax_nopriv_ws_mobile_price_history', 'ws_ajax_mobile_price_history' );
function ws_ajax_mobile_price_history() {
    ws_mobile_auth_user();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'No autorizado.', 'workshop' ) ) );
    }

    $product_id = (int) ( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) {
        wp_send_json_error( array( 'msg' => __( 'Producto inválido.', 'workshop' ) ) );
    }

    $rows = WS_CRUD::get_price_history( array( 'product_id' => $product_id, 'limit' => 500 ) );

    $data = array();
    foreach ( $rows as $h ) {
        $data[] = array(
            'id'         => (int) $h->id,
            'old_cost'   => (float) $h->old_cost,
            'new_cost'   => (float) $h->new_cost,
            'old_sale'   => (float) $h->old_sale,
            'new_sale'   => (float) $h->new_sale,
            'currency'   => (string) $h->currency,
            'created_at' => (string) $h->created_at,
        );
    }

    wp_send_json_success( array( 'product_id' => $product_id, 'history' => $data ) );
}

/* -------------------------------------------------------------------------
 * Dashboard: datos de resumen (hoy: ventas, cajas abiertas, cambios de ubicación)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_mobile_dashboard', 'ws_ajax_mobile_dashboard' );
add_action( 'wp_ajax_nopriv_ws_mobile_dashboard', 'ws_ajax_mobile_dashboard' );
function ws_ajax_mobile_dashboard() {
    ws_mobile_auth_user();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'No autorizado.', 'workshop' ) ) );
    }

    $location_ids = ws_user_location_ids();
    if ( empty( $location_ids ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin ubicaciones asignadas.', 'workshop' ) ) );
    }

    global $wpdb;
    $today_start = gmdate( 'Y-m-d 00:00:00', time() );
    $today_end   = gmdate( 'Y-m-d 23:59:59', time() );

    $ids = array_values( array_map( 'intval', $location_ids ) );
    $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

    // Ventas hoy
    $sales_today = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . ws_table_name( 'pos_sales' ) . " 
         WHERE location_id IN ({$ph}) AND created_at >= %s AND created_at <= %s",
        array_merge( $ids, array( $today_start, $today_end ) )
    ) );

    $total_today = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM " . ws_table_name( 'pos_sales' ) . " 
         WHERE location_id IN ({$ph}) AND created_at >= %s AND created_at <= %s",
        array_merge( $ids, array( $today_start, $today_end ) )
    ) );

    // Cajas abiertas
    $open_registers = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . ws_table_name( 'pos_cash' ) . " 
         WHERE location_id IN ({$ph}) AND status = 'open'",
        $ids
    ) );

    wp_send_json_success( array(
        'sales_today'     => $sales_today,
        'total_today'     => number_format( $total_today, 2, '.', '' ),
        'open_registers'  => $open_registers,
    ) );
}
