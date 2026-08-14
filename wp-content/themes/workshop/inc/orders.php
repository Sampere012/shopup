<?php
/**
 * Flujo de pedidos.
 *
 * - Creación desde la tienda pública de un PV (pedido "pending").
 * - El vendedor acepta -> decrementa stock de forma atómica y registra historial.
 * - El vendedor rechaza o cancela.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Orders {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    public static function statuses() {
        return array(
            'pending'    => __( 'Pendiente', 'workshop' ),
            'accepted'   => __( 'Aceptado', 'workshop' ),
            'rejected'   => __( 'Rechazado', 'workshop' ),
            'cancelled'  => __( 'Cancelado', 'workshop' ),
            'completed'  => __( 'Completado', 'workshop' ),
        );
    }

    public static function status_label( $status ) {
        $map = self::statuses();
        return isset( $map[ $status ] ) ? $map[ $status ] : $status;
    }

    /**
     * Crea un pedido a partir de items. Cada ítem puede ser:
     *   [product_id => qty]            (formato clásico)
     *   [ ['product_id'=>int,'combo_id'=>int,'qty'=>float], ... ]
     * Los ítems de combo (combo_id>0) se guardan con product_id = -combo_id
     * y el precio del combo (manual o auto) convertido a la moneda de la
     * ubicación. Al aceptar el pedido se descuentan los componentes.
     */
    public static function create( $location_id, $items, $customer ) {
        global $wpdb;
        $location_id = (int) $location_id;
        if ( ! $location_id || empty( $items ) ) {
            return new WP_Error( 'invalid', __( 'Pedido inválido.', 'workshop' ) );
        }
        $loc = WS_CRUD::get_location( $location_id );
        if ( ! $loc ) {
            return new WP_Error( 'location', __( 'Ubicación no encontrada.', 'workshop' ) );
        }

        $currency   = ws_location_currency( $location_id );
        $delivery   = (float) $loc->delivery_cost;
        // El domicilio tiene SU propia moneda (puede ser distinta a la de la
        // tienda): se guarda en esa moneda y se convierte para el total.
        $delivery_currency = $loc->delivery_currency ? $loc->delivery_currency : $currency;
        $delivery_in_cur   = ws_convert( $delivery, $delivery_currency, $currency );
        $subtotal   = 0.0;
        $order_items = array();

        $wpdb->query( 'START TRANSACTION' );
        foreach ( $items as $key => $val ) {
            if ( is_array( $val ) ) {
                $product_id = (int) ( $val['product_id'] ?? 0 );
                $combo_id   = (int) ( $val['combo_id'] ?? 0 );
                $qty        = (float) ( $val['qty'] ?? 0 );
            } else {
                // Formato clásico: items[product_id] = qty.
                $product_id = (int) $key;
                $combo_id   = 0;
                $qty        = (float) $val;
            }
            if ( $qty <= 0 ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'qty', __( 'Cantidad inválida.', 'workshop' ) );
            }

            // Ítem de COMBO: el precio viene del combo (manual o auto).
            if ( $combo_id > 0 ) {
                $combo = WS_Combos::get( $combo_id );
                if ( ! $combo ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new WP_Error( 'product', __( 'Combo no encontrado.', 'workshop' ) );
                }
                $price = ws_convert( WS_Combos::price( $combo ), $combo->currency, $currency );
                $subtotal += $price * $qty;
                $order_items[] = array(
                    'product_id'   => -1 * $combo_id,
                    'combo_id'     => $combo_id,
                    'product_name' => $combo->name,
                    'qty'          => $qty,
                    'price'        => round( $price, 2 ),
                );
                continue;
            }

            if ( ! $product_id ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'product', __( 'Producto no encontrado.', 'workshop' ) );
            }
            $product = WS_CRUD::get_product( $product_id );
            if ( ! $product ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'product', __( 'Producto no encontrado.', 'workshop' ) );
            }
            // Precio en la moneda de la ubicación (convierte si el producto
            // está en otra moneda configurada, p. ej. USD -> CUP).
            $price    = ws_convert( (float) $product->sale_price, $product->currency, $currency );
            $subtotal += $price * $qty;
            $order_items[] = array(
                'product_id'   => $product_id,
                'combo_id'     => 0,
                'product_name' => $product->name,
                'qty'          => $qty,
                'price'        => $price,
            );
        }

        $number = 'WS-' . strtoupper( wp_generate_password( 8, false, false ) );
        $wpdb->insert( self::table( 'orders' ), array(
            'number'           => $number,
            'location_id'      => $location_id,
            'customer_name'    => sanitize_text_field( $customer['name'] ?? '' ),
            'customer_phone'   => sanitize_text_field( $customer['phone'] ?? '' ),
            'customer_address' => sanitize_text_field( $customer['address'] ?? '' ),
            'currency'         => $currency,
            'subtotal'         => $subtotal,
            'delivery_cost'    => $delivery,
            'delivery_currency'=> $delivery_currency,
            'total'            => $subtotal + $delivery_in_cur,
            'status'           => 'pending',
        ) );
        $order_id = $wpdb->insert_id;

        foreach ( $order_items as $item ) {
            $wpdb->insert( self::table( 'order_items' ), array(
                'order_id'     => $order_id,
                'product_id'   => $item['product_id'],
                'combo_id'     => $item['combo_id'],
                'product_name' => $item['product_name'],
                'qty'          => $item['qty'],
                'price'        => $item['price'],
            ) );
        }
        $wpdb->query( 'COMMIT' );

        ws_log_audit( 'order_create', 'order', $order_id, array( 'number' => $number, 'location' => $location_id ) );
        return $order_id;
    }

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table( 'orders' ) . " WHERE id = %d", $id ) );
    }

    public static function get_items( $order_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'order_items' ) . " WHERE order_id = %d", $order_id
        ) );
    }

    public static function all( $args = array() ) {
        global $wpdb;
        $where = self::orders_where( $args );
        $limit = isset( $args['limit'] ) ? (int) $args['limit'] : 200;
        $offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
        $sql = "SELECT o.*, l.name AS location_name
                FROM " . self::table( 'orders' ) . " o
                LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = o.location_id
                WHERE " . implode( ' AND ', $where ) . " ORDER BY " . self::orders_orderby( $args['orderby'] ?? '', $args['order'] ?? 'DESC' ) . " LIMIT {$limit} OFFSET {$offset}";
        return $wpdb->get_results( $sql );
    }

    /**
     * Conteo de pedidos con los mismos filtros (para paginación).
     */
    public static function count_all( $args = array() ) {
        global $wpdb;
        $where = self::orders_where( $args );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table( 'orders' ) . " o WHERE " . implode( ' AND ', $where ) );
    }

    /**
     * WHERE compartido de pedidos (listado y conteo).
     */
    protected static function orders_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( ! empty( $args['location_id'] ) ) {
            $where[] = $wpdb->prepare( 'o.location_id = %d', $args['location_id'] );
        }
        if ( isset( $args['location_ids'] ) && is_array( $args['location_ids'] ) ) {
            $ids = array_values( array_filter( array_map( 'intval', $args['location_ids'] ) ) );
            if ( $ids ) {
                $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $where[] = $wpdb->prepare( 'o.location_id IN (' . $ph . ')', ...$ids );
            } else {
                // Usuario sin ubicaciones asignadas: no ver nada.
                $where[] = '1=0';
            }
        }
        if ( ! empty( $args['status'] ) ) {
            $where[] = $wpdb->prepare( 'o.status = %s', $args['status'] );
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[] = $wpdb->prepare( 'o.created_at >= %s', $args['date_from'] . ' 00:00:00' );
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[] = $wpdb->prepare( 'o.created_at <= %s', $args['date_to'] . ' 23:59:59' );
        }
        return $where;
    }

    /**
     * ORDER BY seguro por columna (whitelist) para pedidos.
     */
    protected static function orders_orderby( $key = '', $dir = 'DESC' ) {
        $map = array(
            'id' => 'o.id', 'created_at' => 'o.created_at', 'number' => 'o.number',
            'location_name' => 'l.name', 'customer_name' => 'o.customer_name',
            'customer_phone' => 'o.customer_phone', 'total' => 'o.total',
            'status' => 'o.status', 'date' => 'o.created_at',
        );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'o.id';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    /**
     * Acepta el pedido: decrementa stock de cada ítem de forma atómica
     * y registra movimiento en el historial.
     */
    public static function accept( $order_id ) {
        global $wpdb;
        $order = self::get( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'not_found', __( 'Pedido no encontrado.', 'workshop' ) );
        }
        if ( 'pending' !== $order->status ) {
            return new WP_Error( 'status', __( 'El pedido ya fue procesado.', 'workshop' ) );
        }
        if ( ! ws_can( 'orders_accept' ) ) {
            return new WP_Error( 'permission', __( 'Sin permiso para aceptar pedidos.', 'workshop' ) );
        }

        $items = self::get_items( $order_id );
        $wpdb->query( 'START TRANSACTION' );
        foreach ( $items as $item ) {
            // Ítem de combo: se descuentan sus componentes (cada producto × qty).
            if ( (int) $item->combo_id > 0 ) {
                $result = WS_Combos::decrease_in_tx(
                    (int) $item->combo_id, $order->location_id, (float) $item->qty, 'pedido',
                    $order->number, 'Venta PV (pedido combo)', get_current_user_id()
                );
            } else {
                $result = WS_Stock::decrease_in_tx(
                    $item->product_id, $order->location_id, $item->qty, 'pedido',
                    $order->number, 'Venta PV (pedido)', get_current_user_id()
                );
            }
            if ( is_wp_error( $result ) ) {
                $wpdb->query( 'ROLLBACK' );
                return $result;
            }
        }
        $wpdb->update( self::table( 'orders' ), array( 'status' => 'accepted' ), array( 'id' => $order_id ) );
        $wpdb->query( 'COMMIT' );

        ws_log_audit( 'order_accept', 'order', $order_id, array( 'number' => $order->number ) );
        return true;
    }

    public static function reject( $order_id ) {
        global $wpdb;
        $order = self::get( $order_id );
        if ( ! $order || 'pending' !== $order->status ) {
            return new WP_Error( 'status', __( 'El pedido ya fue procesado.', 'workshop' ) );
        }
        $wpdb->update( self::table( 'orders' ), array( 'status' => 'rejected' ), array( 'id' => $order_id ) );
        ws_log_audit( 'order_reject', 'order', $order_id, array( 'number' => $order->number ) );
        return true;
    }

    public static function complete( $order_id ) {
        global $wpdb;
        $order = self::get( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'not_found', __( 'Pedido no encontrado.', 'workshop' ) );
        }
        $wpdb->update( self::table( 'orders' ), array( 'status' => 'completed' ), array( 'id' => $order_id ) );
        ws_log_audit( 'order_complete', 'order', $order_id, array( 'number' => $order->number ) );
        return true;
    }

    public static function cancel( $order_id ) {
        global $wpdb;
        $order = self::get( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'not_found', __( 'Pedido no encontrado.', 'workshop' ) );
        }
        $wpdb->update( self::table( 'orders' ), array( 'status' => 'cancelled' ), array( 'id' => $order_id ) );
        ws_log_audit( 'order_cancel', 'order', $order_id, array( 'number' => $order->number ) );
        return true;
    }
}
