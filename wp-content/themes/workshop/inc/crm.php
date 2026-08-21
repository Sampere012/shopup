<?php
/**
 * CRUD de clientes (CRM).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_CRM {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /* ---------------- Clientes ---------------- */

    public static function get_customers( $args = array() ) {
        global $wpdb;
        $table = self::table( 'customers' );
        $where = self::customers_where( $args );
        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY " . self::customers_orderby( $args['orderby'] ?? '', $args['order'] ?? 'ASC' );
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        return $wpdb->get_results( $sql );
    }

    public static function count_customers( $args = array() ) {
        global $wpdb;
        $table = self::table( 'customers' );
        $where = self::customers_where( $args );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where ) );
    }

    protected static function customers_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( "(name LIKE %s OR doc LIKE %s OR phone LIKE %s OR address LIKE %s)", $like, $like, $like, $like );
        }
        if ( ! empty( $args['status'] ) ) {
            if ( 'active' === $args['status'] ) {
                $where[] = 'active = 1';
            } elseif ( 'inactive' === $args['status'] ) {
                $where[] = 'active = 0';
            }
        }
        if ( ! empty( $args['city'] ) ) {
            $where[] = $wpdb->prepare( 'city = %s', $args['city'] );
        }
        if ( isset( $args['min_points'] ) ) {
            $where[] = $wpdb->prepare( 'loyalty_points >= %d', $args['min_points'] );
        }
        return $where;
    }

    protected static function customers_orderby( $key = '', $dir = 'ASC' ) {
        $map = array(
            'name' => 'name', 'doc' => 'doc', 'phone' => 'phone',
            'address' => 'address', 'loyalty_points' => 'loyalty_points',
            'total_spent' => 'total_spent', 'orders_count' => 'orders_count',
            'created_at' => 'created_at',
        );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'name';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    public static function get_customer( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'customers' ) . " WHERE id = %d", $id
        ) );
        return $row;
    }

    public static function get_customer_by_email( $email ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'customers' ) . " WHERE email = %s", $email
        ) );
        return $row;
    }

    public static function get_customer_by_phone( $phone ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'customers' ) . " WHERE phone = %s", $phone
        ) );
        return $row;
    }

    public static function save_customer( $data, $id = 0 ) {
        global $wpdb;
        $table = self::table( 'customers' );
        
        $fields = array(
            'name'    => sanitize_text_field( $data['name'] ?? '' ),
            'phone'   => sanitize_text_field( $data['phone'] ?? '' ),
            'doc'     => sanitize_text_field( $data['doc'] ?? '' ),
            'address' => sanitize_textarea_field( $data['address'] ?? '' ),
        );

        if ( $id ) {
            if ( isset( $data['active'] ) ) {
                $fields['active'] = (int) ( $data['active'] ? 1 : 0 );
            }
            $formats = array( '%s', '%s', '%s', '%s' );
            if ( isset( $fields['active'] ) ) {
                $formats[] = '%d';
            }
            $wpdb->update( $table, $fields, array( 'id' => $id ), $formats, array( '%d' ) );
            return $id;
        } else {
            $fields['active'] = isset( $data['active'] ) ? (int) ( $data['active'] ? 1 : 0 ) : 1;
            $fields['loyalty_points'] = 0;
            $fields['total_spent'] = 0;
            $fields['orders_count'] = 0;
            $wpdb->insert( $table, $fields, array( '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%d' ) );
            return $wpdb->insert_id;
        }
    }

    public static function delete_customer( $id ) {
        global $wpdb;
        $wpdb->delete( self::table( 'customers' ), array( 'id' => $id ), array( '%d' ) );
    }

    /* ---------------- Fidelización ---------------- */

    public static function add_loyalty_points( $customer_id, $points, $type = 'earned', $reference = '', $order_id = 0, $note = '' ) {
        global $wpdb;
        
        // Actualizar puntos del cliente
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table( 'customers' ) . " SET loyalty_points = loyalty_points + %d WHERE id = %d",
            $points, $customer_id
        ) );

        // Registrar transacción
        $wpdb->insert( self::table( 'loyalty_transactions' ), array(
            'customer_id' => $customer_id,
            'points' => $points,
            'type' => $type,
            'reference' => $reference,
            'order_id' => $order_id,
            'note' => $note,
        ), array( '%d', '%d', '%s', '%s', '%d', '%s' ) );

        return $wpdb->insert_id;
    }

    public static function get_loyalty_transactions( $customer_id, $limit = 50 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'loyalty_transactions' ) . " 
            WHERE customer_id = %d ORDER BY created_at DESC LIMIT %d",
            $customer_id, $limit
        ) );
    }

    public static function get_customer_points_balance( $customer_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT loyalty_points FROM " . self::table( 'customers' ) . " WHERE id = %d",
            $customer_id
        ) );
    }

    public static function update_customer_stats( $customer_id, $amount_spent ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table( 'customers' ) . " 
            SET total_spent = total_spent + %f, orders_count = orders_count + 1 
            WHERE id = %d",
            $amount_spent, $customer_id
        ) );
    }
}
