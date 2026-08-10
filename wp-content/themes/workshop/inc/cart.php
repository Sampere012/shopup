<?php
/**
 * CRUD de carrito de compras persistente.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Cart {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /* ---------------- Carrito ---------------- */

    public static function get_cart( $session_id, $location_id = 0, $user_id = 0 ) {
        global $wpdb;
        $table = self::table( 'cart' );
        $where = array( $wpdb->prepare( 'session_id = %s', $session_id ) );
        
        if ( $location_id ) {
            $where[] = $wpdb->prepare( 'location_id = %d', $location_id );
        }
        
        if ( $user_id ) {
            $where[] = $wpdb->prepare( 'user_id = %d', $user_id );
        }

        $sql = "SELECT c.*, p.name, p.sale_price, p.image, p.currency 
                FROM {$table} c
                JOIN " . self::table( 'products' ) . " p ON p.id = c.product_id
                WHERE " . implode( ' AND ', $where );
        
        return $wpdb->get_results( $sql );
    }

    public static function add_to_cart( $session_id, $location_id, $product_id, $qty = 1, $user_id = 0 ) {
        global $wpdb;
        $table = self::table( 'cart' );
        
        // Verificar si el producto ya está en el carrito
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, qty FROM {$table} WHERE session_id = %s AND location_id = %d AND product_id = %d",
            $session_id, $location_id, $product_id
        ) );

        if ( $existing ) {
            // Actualizar cantidad
            $wpdb->update( $table, 
                array( 'qty' => $existing->qty + $qty, 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $existing->id ),
                array( '%f', '%s' ),
                array( '%d' )
            );
            return $existing->id;
        } else {
            // Insertar nuevo item
            $wpdb->insert( $table, array(
                'session_id' => $session_id,
                'user_id' => $user_id,
                'location_id' => $location_id,
                'product_id' => $product_id,
                'qty' => $qty,
            ), array( '%s', '%d', '%d', '%d', '%f' ) );
            return $wpdb->insert_id;
        }
    }

    public static function update_cart_item( $cart_id, $qty ) {
        global $wpdb;
        $table = self::table( 'cart' );
        
        if ( $qty <= 0 ) {
            $wpdb->delete( $table, array( 'id' => $cart_id ), array( '%d' ) );
        } else {
            $wpdb->update( $table, 
                array( 'qty' => $qty, 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $cart_id ),
                array( '%f', '%s' ),
                array( '%d' )
            );
        }
    }

    public static function remove_from_cart( $cart_id ) {
        global $wpdb;
        $wpdb->delete( self::table( 'cart' ), array( 'id' => $cart_id ), array( '%d' ) );
    }

    public static function clear_cart( $session_id, $location_id = 0 ) {
        global $wpdb;
        $table = self::table( 'cart' );
        $where = array( $wpdb->prepare( 'session_id = %s', $session_id ) );
        
        if ( $location_id ) {
            $where[] = $wpdb->prepare( 'location_id = %d', $location_id );
        }

        $wpdb->query( "DELETE FROM {$table} WHERE " . implode( ' AND ', $where ) );
    }

    public static function get_cart_total( $session_id, $location_id = 0 ) {
        global $wpdb;
        $table = self::table( 'cart' );
        $where = array( $wpdb->prepare( 'session_id = %s', $session_id ) );
        
        if ( $location_id ) {
            $where[] = $wpdb->prepare( 'location_id = %d', $location_id );
        }

        $sql = "SELECT SUM(c.qty * p.sale_price) as total
                FROM {$table} c
                JOIN " . self::table( 'products' ) . " p ON p.id = c.product_id
                WHERE " . implode( ' AND ', $where );
        
        return (float) $wpdb->get_var( $sql );
    }

    public static function get_cart_count( $session_id, $location_id = 0 ) {
        global $wpdb;
        $table = self::table( 'cart' );
        $where = array( $wpdb->prepare( 'session_id = %s', $session_id ) );
        
        if ( $location_id ) {
            $where[] = $wpdb->prepare( 'location_id = %d', $location_id );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where ) );
    }

    /* ---------------- Utilidades ---------------- */

    public static function generate_session_id() {
        return wp_generate_password( 32, false, false );
    }

    public static function merge_guest_cart( $session_id, $user_id, $location_id ) {
        global $wpdb;
        $table = self::table( 'cart' );
        
        // Actualizar items del carrito de invitado al usuario
        $wpdb->update( $table,
            array( 'user_id' => $user_id ),
            array( 'session_id' => $session_id, 'location_id' => $location_id, 'user_id' => 0 ),
            array( '%d' ),
            array( '%s', '%d', '%d' )
        );
    }

    public static function cleanup_old_carts( $days = 30 ) {
        global $wpdb;
        $table = self::table( 'cart' );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }
}
