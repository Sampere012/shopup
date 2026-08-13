<?php
/**
 * Control de gastos del negocio (módulo del panel).
 *
 * Registra los gastos con su fecha (el gasto es POR MES) y calcula la
 * utilidad mensual: ingresos del mes (pedidos aceptados/completados + ventas
 * POS completadas) MENOS los gastos del mismo mes, filtrados por fecha.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Expenses {

    /** Tabla de gastos del negocio actual. */
    public static function table() {
        return ws_table_name( 'expenses' );
    }

    /**
     * Gastos del negocio actual, opcionalmente filtrados por mes.
     * $location_id: 0 = todas; -1 = solo los generales; >0 = esa ubicación.
     */
    public static function all( $year = 0, $month = 0, $location_id = 0 ) {
        global $wpdb;
        $t = self::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            return array();
        }
        $where = array( '1=1' );
        $args  = array();
        if ( $year && $month ) {
            $from = gmdate( 'Y-m-d 00:00:00', mktime( 0, 0, 0, (int) $month, 1, (int) $year ) );
            $to   = gmdate( 'Y-m-d 00:00:00', mktime( 0, 0, 0, (int) $month + 1, 1, (int) $year ) );
            $where[] = 'expense_date >= %s';
            $where[] = 'expense_date < %s';
            $args[]  = $from;
            $args[]  = $to;
        }
        $lid = (int) $location_id;
        if ( $lid > 0 ) {
            $where[] = 'location_id = %d';
            $args[]  = $lid;
        } elseif ( $lid < 0 ) {
            $where[] = 'location_id = 0';
        }
        $sql = 'SELECT * FROM ' . $t . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY expense_date DESC, id DESC LIMIT 200';
        return $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_results( $sql );
    }

    /** Nombre de la ubicación de un gasto ('' si no existe la columna/tabla). */
    public static function location_name( $expense ) {
        $lid = (int) ( $expense->location_id ?? 0 );
        if ( ! $lid ) {
            return '';
        }
        $loc = WS_CRUD::get_location( $lid );
        return $loc ? (string) $loc->name : '';
    }

    /** Guarda (crea o edita) un gasto. */
    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $id       = (int) $id;
        $concept  = sanitize_text_field( $data['concept'] ?? '' );
        $amount   = (float) ( $data['amount'] ?? 0 );
        $category = sanitize_text_field( $data['category'] ?? '' );
        $note     = sanitize_textarea_field( $data['note'] ?? '' );
        $date     = sanitize_text_field( $data['expense_date'] ?? '' );
        $location_id = (int) ( $data['location_id'] ?? 0 );
        if ( $location_id < 0 ) {
            $location_id = 0;
        }
        if ( '' === $concept ) {
            return new WP_Error( 'concept', __( 'El concepto del gasto es obligatorio.', 'workshop' ) );
        }
        if ( $amount <= 0 ) {
            return new WP_Error( 'amount', __( 'El monto del gasto debe ser mayor que 0.', 'workshop' ) );
        }
        if ( $location_id > 0 ) {
            $loc_ids = ws_user_location_ids();
            if ( ! in_array( $location_id, $loc_ids, true ) ) {
                return new WP_Error( 'location', __( 'La ubicación elegida no es válida.', 'workshop' ) );
            }
        }
        $ts = '' !== $date ? strtotime( $date ) : current_time( 'timestamp' );
        if ( ! $ts ) {
            return new WP_Error( 'date', __( 'La fecha del gasto no es válida.', 'workshop' ) );
        }
        $fields = array(
            'location_id'  => $location_id,
            'concept'      => mb_substr( $concept, 0, 255 ),
            'amount'       => $amount,
            'category'     => mb_substr( $category, 0, 120 ),
            'note'         => $note,
            'expense_date' => gmdate( 'Y-m-d H:i:s', $ts ),
            'created_by'   => get_current_user_id(),
        );
        if ( $id ) {
            unset( $fields['created_by'] );
            $wpdb->update( self::table(), $fields, array( 'id' => $id ) );
            return $id;
        }
        $wpdb->insert( self::table(), $fields );
        return (int) $wpdb->insert_id;
    }

    /** Elimina un gasto. */
    public static function delete( $id ) {
        global $wpdb;
        $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
    }

    /**
     * Utilidad mensual: ingresos del mes (pedidos aceptados/completados +
     * ventas POS completadas) MENOS los gastos del mismo mes. El gasto es
     * por mes: todo se filtra por la fecha dentro del mes elegido.
     */
    public static function month_summary( $year, $month ) {
        global $wpdb;
        $year  = max( 2000, (int) $year );
        $month = max( 1, min( 12, (int) $month ) );
        $from  = gmdate( 'Y-m-d 00:00:00', mktime( 0, 0, 0, $month, 1, $year ) );
        $to    = gmdate( 'Y-m-d 00:00:00', mktime( 0, 0, 0, $month + 1, 1, $year ) );
        $cur   = ws_currency_symbol();

        $loc_ids = array_map( fn( $l ) => (int) $l->id, ws_user_locations() );
        $ph      = $loc_ids ? implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) ) : '0';
        $args    = $loc_ids ? $loc_ids : array( 0 );

        $income = 0.0;
        if ( $loc_ids ) {
            $orders_t = ws_table_name( 'orders' );
            $pos_t    = ws_table_name( 'pos_sales' );
            $income  += (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(total),0) FROM {$orders_t} WHERE location_id IN ({$ph}) AND status IN ('accepted','completed') AND created_at >= %s AND created_at < %s",
                ...array_merge( $args, array( $from, $to ) )
            ) );
            $income  += (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(total),0) FROM {$pos_t} WHERE location_id IN ({$ph}) AND status='completed' AND created_at >= %s AND created_at < %s",
                ...array_merge( $args, array( $from, $to ) )
            ) );
        }

        $expenses = (float) $wpdb->get_var( $wpdb->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM ' . self::table() . ' WHERE expense_date >= %s AND expense_date < %s',
            $from, $to
        ) );

        return array(
            'year'     => $year,
            'month'    => $month,
            'income'   => $income,
            'expenses' => $expenses,
            'utility'  => $income - $expenses,
            'currency' => $cur,
        );
    }
}

/* -------------------------------------------------------------------------
 * AJAX del módulo de gastos (panel de negocio)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_expenses_list', 'ws_ajax_expenses_list' );
function ws_ajax_expenses_list() {
    ws_guard( 'expenses_manage' );
    $year  = (int) ( $_POST['year'] ?? 0 );
    $month = (int) ( $_POST['month'] ?? 0 );
    $out   = array();
    foreach ( WS_Expenses::all( $year, $month ) as $e ) {
        $out[] = array(
            'id'            => (int) $e->id,
            'concept'       => (string) $e->concept,
            'amount'        => (float) $e->amount,
            'category'      => (string) $e->category,
            'note'          => (string) ( $e->note ?? '' ),
            'location_id'   => (int) ( $e->location_id ?? 0 ),
            'location_name' => (string) WS_Expenses::location_name( $e ),
            'date_label'    => mysql2date( 'd/m/Y', $e->expense_date ),
            'date_raw'      => gmdate( 'Y-m-d', strtotime( $e->expense_date ) ),
            'by'            => (int) $e->created_by,
        );
    }
    $summary = WS_Expenses::month_summary(
        $year ? $year : (int) gmdate( 'Y' ),
        $month ? $month : (int) gmdate( 'n' )
    );
    wp_send_json_success( array( 'expenses' => $out, 'summary' => $summary ) );
}

add_action( 'wp_ajax_ws_expense_save', 'ws_ajax_expense_save' );
function ws_ajax_expense_save() {
    ws_guard( 'expenses_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    $result = WS_Expenses::save( $_POST, $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( $id ? 'expense_update' : 'expense_create', 'expense', $result, array( 'concept' => $_POST['concept'] ?? '' ) );
    wp_send_json_success( array( 'id' => (int) $result ) );
}

add_action( 'wp_ajax_ws_expense_delete', 'ws_ajax_expense_delete' );
function ws_ajax_expense_delete() {
    ws_guard( 'expenses_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    WS_Expenses::delete( $id );
    ws_log_audit( 'expense_delete', 'expense', $id );
    wp_send_json_success();
}
