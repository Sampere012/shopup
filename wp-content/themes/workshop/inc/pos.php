<?php
/**
 * CRUD de sistema POS (Punto de Venta) para vendedores.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_POS {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /* ---------------- Ventas POS ---------------- */

    public static function get_sales( $args = array() ) {
        global $wpdb;
        $table = self::table( 'pos_sales' );
        $where = self::sales_where( $args );
        $sql = "SELECT s.*, l.name as location_name, u.display_name as seller_name 
                FROM {$table} s
                LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = s.location_id
                LEFT JOIN {$wpdb->users} u ON u.ID = s.seller_id
                WHERE " . implode( ' AND ', $where ) . " 
                ORDER BY " . self::sales_orderby( $args['orderby'] ?? '', $args['order'] ?? 'DESC' );
        
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        
        return $wpdb->get_results( $sql );
    }

    public static function count_sales( $args = array() ) {
        global $wpdb;
        $table = self::table( 'pos_sales' );
        $where = self::sales_where( $args );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} s WHERE " . implode( ' AND ', $where ) );
    }

    protected static function sales_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        
        if ( ! empty( $args['location_id'] ) ) {
            $where[] = $wpdb->prepare( 'location_id = %d', $args['location_id'] );
        }
        
        if ( ! empty( $args['seller_id'] ) ) {
            $where[] = $wpdb->prepare( 'seller_id = %d', $args['seller_id'] );
        }
        
        if ( ! empty( $args['customer_id'] ) ) {
            $where[] = $wpdb->prepare( 'customer_id = %d', $args['customer_id'] );
        }

        if ( ! empty( $args['register_id'] ) ) {
            $where[] = $wpdb->prepare( 'register_id = %d', $args['register_id'] );
        }
        
        if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
            $where[] = $wpdb->prepare( 'status = %s', $args['status'] );
        }
        
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( '(s.number LIKE %s OR s.customer_name LIKE %s)', $like, $like );
        }
        
        if ( ! empty( $args['date_from'] ) ) {
            $where[] = $wpdb->prepare( 's.created_at >= %s', $args['date_from'] . ' 00:00:00' );
        }
        
        if ( ! empty( $args['date_to'] ) ) {
            $where[] = $wpdb->prepare( 's.created_at <= %s', $args['date_to'] . ' 23:59:59' );
        }
        
        return $where;
    }

    protected static function sales_orderby( $key = '', $dir = 'DESC' ) {
        $map = array(
            'created_at' => 's.created_at', 'total' => 's.total',
            'number' => 's.number', 'customer_name' => 's.customer_name',
            'id' => 's.id',
        );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 's.created_at';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    public static function get_sale( $id ) {
        global $wpdb;
        $sale = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'pos_sales' ) . " WHERE id = %d", $id
        ) );
        
        if ( $sale ) {
            $sale->items = self::get_sale_items( $id );
        }
        
        return $sale;
    }

    public static function get_sale_items( $sale_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'pos_sale_items' ) . " WHERE sale_id = %d", $sale_id
        ) );
    }

    public static function save_sale( $data, $id = 0 ) {
        global $wpdb;
        $table = self::table( 'pos_sales' );
        
        $payment_method = sanitize_text_field( $data['payment_method'] ?? 'cash' );

        $fields = array(
            'location_id'     => (int) ( $data['location_id'] ?? 0 ),
            'seller_id'       => (int) ( $data['seller_id'] ?? 0 ),
            'customer_id'     => (int) ( $data['customer_id'] ?? 0 ),
            'customer_name'   => sanitize_text_field( $data['customer_name'] ?? '' ),
            'customer_doc'    => sanitize_text_field( $data['customer_doc'] ?? '' ),
            'customer_phone'  => sanitize_text_field( $data['customer_phone'] ?? '' ),
            'currency'        => sanitize_text_field( $data['currency'] ?? '€' ),
            'subtotal'        => (float) ( $data['subtotal'] ?? 0 ),
            'discount'        => (float) ( $data['discount'] ?? 0 ),
            'total'           => (float) ( $data['total'] ?? 0 ),
            'payment_method'  => $payment_method,
            'cash_amount'     => (float) ( $data['cash_amount'] ?? 0 ),
            'transfer_amount' => (float) ( $data['transfer_amount'] ?? 0 ),
            'transfer_number' => sanitize_text_field( $data['transfer_number'] ?? '' ),
            'status'          => sanitize_text_field( $data['status'] ?? 'completed' ),
            'register_id'     => (int) ( $data['register_id'] ?? 0 ),
            // Referencia única del cliente (ventas offline): permite detectar
            // y evitar duplicados cuando la cola reintenta un envío. NULL en
            // ventas en línea para no chocar con el índice único.
            'client_ref'      => ( isset( $data['client_ref'] ) && '' !== $data['client_ref'] ) ? sanitize_text_field( $data['client_ref'] ) : null,
        );

        // Formatos asociativos (robusto ante el orden de claves).
        $formats = array(
            'location_id'     => '%d',
            'seller_id'       => '%d',
            'customer_id'     => '%d',
            'customer_name'   => '%s',
            'customer_doc'    => '%s',
            'customer_phone'  => '%s',
            'currency'        => '%s',
            'subtotal'        => '%f',
            'discount'        => '%f',
            'total'           => '%f',
            'payment_method'  => '%s',
            'cash_amount'     => '%f',
            'transfer_amount' => '%f',
            'transfer_number' => '%s',
            'status'          => '%s',
            'register_id'     => '%d',
            'client_ref'      => '%s',
        );

        if ( $id ) {
            $wpdb->update( $table, $fields, array( 'id' => $id ), $formats, array( '%d' ) );
        } else {
            // Generar número de venta
            $fields['number'] = self::generate_sale_number( $fields['location_id'] );
            $formats['number'] = '%s';
            $wpdb->insert( $table, $fields, $formats );
            $id = $wpdb->insert_id;
        }

        // Guardar items de la venta
        if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
            self::save_sale_items( $id, $data['items'] );
        }

        return $id;
    }

    protected static function generate_sale_number( $location_id ) {
        global $wpdb;
        $prefix = 'POS-' . $location_id . '-';
        $last = $wpdb->get_var( $wpdb->prepare(
            "SELECT number FROM " . self::table( 'pos_sales' ) . " 
            WHERE number LIKE %s ORDER BY id DESC LIMIT 1",
            $prefix . '%'
        ) );
        
        if ( $last ) {
            $num = (int) str_replace( $prefix, '', $last );
            return $prefix . str_pad( $num + 1, 6, '0', STR_PAD_LEFT );
        }
        
        return $prefix . '000001';
    }

    protected static function save_sale_items( $sale_id, $items ) {
        global $wpdb;
        $items_table = self::table( 'pos_sale_items' );
        
        // Eliminar items existentes
        $wpdb->delete( $items_table, array( 'sale_id' => $sale_id ), array( '%d' ) );
        
        // Insertar nuevos items
        foreach ( $items as $item ) {
            // El costo se guarda en el momento de la venta: permite calcular la
            // ganancia real (precio − costo) × qty aunque el costo del producto
            // cambie después. Si no llega, se intenta el costo actual del producto.
            $cost = (float) ( $item['cost_price'] ?? 0 );
            if ( $cost <= 0 && ! empty( $item['product_id'] ) ) {
                $p_cost = $wpdb->get_var( $wpdb->prepare(
                    "SELECT cost_price FROM " . self::table( 'products' ) . " WHERE id = %d", (int) $item['product_id']
                ) );
                $cost = (float) ( $p_cost ?? 0 );
            }
            $wpdb->insert( $items_table, array(
                'sale_id' => $sale_id,
                'product_id' => (int) ($item['product_id'] ?? 0),
                'product_name' => sanitize_text_field( $item['product_name'] ?? '' ),
                'qty' => (float) ($item['qty'] ?? 0),
                'price' => (float) ($item['price'] ?? 0),
                'cost_price' => $cost,
                'discount' => (float) ($item['discount'] ?? 0),
                'subtotal' => (float) ($item['subtotal'] ?? 0),
            ), array( '%d', '%d', '%s', '%f', '%f', '%f', '%f', '%f' ) );
        }
    }

    public static function delete_sale( $id ) {
        global $wpdb;
        $wpdb->delete( self::table( 'pos_sale_items' ), array( 'sale_id' => $id ), array( '%d' ) );
        $wpdb->delete( self::table( 'pos_sales' ), array( 'id' => $id ), array( '%d' ) );
    }

    /* ---------------- Estadísticas ---------------- */

    /**
     * Estadísticas de ventas con filtros combinables (vendedor/ubicación/fechas).
     * Devuelve claves que espera el panel: total_sales (ingresos), total_count,
     * average_sale, total_revenue (alias de total_sales).
     */
    public static function get_stats( $args = array() ) {
        global $wpdb;
        $table = self::table( 'pos_sales' );
        $where = self::sales_where( $args );

        $result = $wpdb->get_row(
            "SELECT COUNT(*) as total_count, SUM(total) as total_revenue, AVG(total) as avg_sale 
            FROM {$table} s WHERE " . implode( ' AND ', $where )
        );

        $revenue = (float) ( $result->total_revenue ?? 0 );
        $count   = (int) ( $result->total_count ?? 0 );

        return array(
            'total_sales'   => $revenue,
            'total_revenue' => $revenue,
            'total_count'   => $count,
            'average_sale'  => $count > 0 ? round( $revenue / $count, 2 ) : 0,
            'avg_sale'      => $count > 0 ? round( $revenue / $count, 2 ) : 0,
        );
    }

    public static function get_seller_stats( $seller_id, $date_from = null, $date_to = null ) {
        return self::get_stats( array(
            'seller_id' => $seller_id,
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ) );
    }

    public static function get_location_stats( $location_id, $date_from = null, $date_to = null ) {
        return self::get_stats( array(
            'location_id' => $location_id,
            'date_from'   => $date_from,
            'date_to'     => $date_to,
        ) );
    }

    /* ---------------- Caja (apertura / cierre) ---------------- */

    /**
     * Caja abierta actual de una ubicación (o null si no hay).
     */
    public static function get_open_cash( $location_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT c.*, u.display_name AS seller_name
            FROM " . self::table( 'pos_cash' ) . " c
            LEFT JOIN {$wpdb->users} u ON u.ID = c.seller_id
            WHERE c.location_id = %d AND c.status = 'open'
            ORDER BY c.id DESC LIMIT 1",
            (int) $location_id
        ) );
    }

    /**
     * Abre caja en una ubicación. Si ya hay una abierta devuelve la existente.
     */
    public static function open_cash( $location_id, $opening_amount = 0, $note = '', $seller_id = 0 ) {
        global $wpdb;
        $location_id  = (int) $location_id;
        $seller_id    = $seller_id ? (int) $seller_id : get_current_user_id();
        $opening_amount = (float) $opening_amount;

        $existing = self::get_open_cash( $location_id );
        if ( $existing ) {
            return $existing;
        }

        $wpdb->insert( self::table( 'pos_cash' ), array(
            'location_id'    => $location_id,
            'seller_id'      => $seller_id,
            'opening_amount' => $opening_amount,
            'opening_note'   => sanitize_text_field( $note ),
            'status'         => 'open',
        ), array( '%d', '%d', '%f', '%s', '%s' ) );

        return self::get_open_cash( $location_id );
    }

    /**
     * Cierra la caja abierta de una ubicación con el arqueo final.
     * Devuelve el resumen (ventas + caja esperada) o WP_Error.
     */
    public static function close_cash( $location_id, $closing_amount = 0, $note = '' ) {
        global $wpdb;
        $cash = self::get_open_cash( $location_id );
        if ( ! $cash ) {
            return new WP_Error( 'no_open', __( 'No hay caja abierta en esta ubicación.', 'workshop' ) );
        }

        $stats = self::get_stats( array(
            'location_id' => $location_id,
            'register_id' => $cash->id,
            'status'      => 'completed',
        ) );

        $sales_total = (float) $stats['total_sales'];
        $expected    = round( $sales_total + (float) $cash->opening_amount, 2 );

        $wpdb->update( self::table( 'pos_cash' ), array(
            'closing_amount' => (float) $closing_amount,
            'closing_note'   => sanitize_text_field( $note ),
            'closed_at'      => current_time( 'mysql' ),
            'status'         => 'closed',
        ), array( 'id' => $cash->id ), array( '%f', '%s', '%s', '%s' ), array( '%d' ) );

        return array(
            'id'             => (int) $cash->id,
            'opening_amount' => (float) $cash->opening_amount,
            'sales_total'    => $sales_total,
            'expected'       => $expected,
            'closing_amount' => (float) $closing_amount,
            'difference'     => round( (float) $closing_amount - $expected, 2 ),
            'sales_count'    => (int) $stats['total_count'],
        );
    }

    /**
     * Historial de cierres de caja con filtros.
     */
    public static function cash_history( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );

        if ( ! empty( $args['location_id'] ) ) {
            $where[] = $wpdb->prepare( 'c.location_id = %d', (int) $args['location_id'] );
        }
        if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
            $where[] = $wpdb->prepare( 'c.status = %s', $args['status'] );
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[] = $wpdb->prepare( 'c.opened_at >= %s', $args['date_from'] . ' 00:00:00' );
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[] = $wpdb->prepare( 'c.opened_at <= %s', $args['date_to'] . ' 23:59:59' );
        }

        $sql = "SELECT c.*, u.display_name AS seller_name, l.name AS location_name
                FROM " . self::table( 'pos_cash' ) . " c
                LEFT JOIN {$wpdb->users} u ON u.ID = c.seller_id
                LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = c.location_id
                WHERE " . implode( ' AND ', $where ) . " ORDER BY c.id DESC";

        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }

        $rows = $wpdb->get_results( $sql );
        foreach ( $rows as $row ) {
            $row->sales_total = 0;
            $row->expected    = 0;
            $row->difference  = 0;
            if ( 'closed' === $row->status ) {
                $stats = self::get_stats( array(
                    'location_id' => (int) $row->location_id,
                    'register_id' => (int) $row->id,
                    'status'      => 'completed',
                ) );
                $row->sales_total = (float) $stats['total_sales'];
                $row->expected    = round( (float) $row->sales_total + (float) $row->opening_amount, 2 );
                $row->difference  = round( (float) $row->closing_amount - (float) $row->expected, 2 );
            }
        }
        return $rows;
    }
}
