<?php
/**
 * CRUD de valoraciones/reseñas de productos.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Reviews {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /* ---------------- Valoraciones ---------------- */

    public static function get_reviews( $args = array() ) {
        global $wpdb;
        $table = self::table( 'reviews' );
        $where = self::reviews_where( $args );
        // LEFT JOIN: las reseñas pueden ser de un producto (product_id>0) o de
        // la tienda (location_id>0 con product_id=0); se trae el nombre de la
        // ubicación para el panel y se mantiene el de producto cuando exista.
        $sql = "SELECT r.*, p.name as product_name, p.image as product_image,
                       l.name as location_name
                FROM {$table} r
                LEFT JOIN " . self::table( 'products' ) . " p ON p.id = r.product_id
                LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = r.location_id
                WHERE " . implode( ' AND ', $where ) . " 
                ORDER BY " . self::reviews_orderby( $args['orderby'] ?? '', $args['order'] ?? 'DESC' );
        
        if ( isset( $args['limit'] ) ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $args['limit'] ) );
        }
        if ( isset( $args['offset'] ) ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', max( 0, (int) $args['offset'] ) );
        }
        
        return $wpdb->get_results( $sql );
    }

    public static function count_reviews( $args = array() ) {
        global $wpdb;
        $table = self::table( 'reviews' );
        $where = self::reviews_where( $args );
        $sql = "SELECT COUNT(*) FROM {$table} r
                LEFT JOIN " . self::table( 'products' ) . " p ON p.id = r.product_id
                LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = r.location_id
                WHERE " . implode( ' AND ', $where );
        return (int) $wpdb->get_var( $sql );
    }

    protected static function reviews_where( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        
        if ( ! empty( $args['product_id'] ) ) {
            $where[] = $wpdb->prepare( 'product_id = %d', $args['product_id'] );
        }

        if ( ! empty( $args['location_id'] ) ) {
            $where[] = $wpdb->prepare( 'location_id = %d', $args['location_id'] );
        }
        
        if ( ! empty( $args['customer_id'] ) ) {
            $where[] = $wpdb->prepare( 'customer_id = %d', $args['customer_id'] );
        }
        
        if ( isset( $args['approved'] ) ) {
            $where[] = $wpdb->prepare( 'approved = %d', $args['approved'] );
        }
        
        // Filtro de estado para el panel (pending/approved/rejected).
        if ( ! empty( $args['status'] ) && in_array( (string) $args['status'], array( 'pending', 'approved', 'rejected' ), true ) ) {
            $where[] = $wpdb->prepare( 'status = %s', $args['status'] );
        }
        
        if ( isset( $args['verified_purchase'] ) ) {
            $where[] = $wpdb->prepare( 'verified_purchase = %d', $args['verified_purchase'] );
        }
        
        if ( ! empty( $args['rating'] ) ) {
            $where[] = $wpdb->prepare( 'rating = %d', $args['rating'] );
        }
        
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = $wpdb->prepare( '(r.customer_name LIKE %s OR r.comment LIKE %s OR r.title LIKE %s OR p.name LIKE %s OR l.name LIKE %s)', $like, $like, $like, $like, $like );
        }
        
        return $where;
    }

    protected static function reviews_orderby( $key = '', $dir = 'DESC' ) {
        $map = array(
            'created_at' => 'created_at', 'rating' => 'rating',
            'product_name' => 'product_name', 'customer_name' => 'customer_name',
            'location_name' => 'location_name',
        );
        $col = isset( $map[ $key ] ) ? $map[ $key ] : 'created_at';
        return $col . ' ' . ( 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC' );
    }

    public static function get_review( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'reviews' ) . " WHERE id = %d", $id
        ) );
    }

    public static function save_review( $data, $id = 0 ) {
        global $wpdb;
        $table = self::table( 'reviews' );
        
        $status = sanitize_key( $data['status'] ?? 'pending' );
        if ( ! in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ) {
            $status = 'pending';
        }
        
        $fields = array(
            'product_id' => (int) ($data['product_id'] ?? 0),
            'location_id' => (int) ($data['location_id'] ?? 0),
            'customer_id' => (int) ($data['customer_id'] ?? 0),
            'customer_name' => sanitize_text_field( $data['customer_name'] ?? '' ),
            'rating' => min( 5, max( 1, (int) ($data['rating'] ?? 5) ) ),
            'title' => sanitize_text_field( $data['title'] ?? '' ),
            'comment' => sanitize_textarea_field( $data['comment'] ?? '' ),
            'verified_purchase' => (int) ($data['verified_purchase'] ?? 0),
            'status' => $status,
            'approved' => 'approved' === $status ? 1 : 0,
        );

        if ( $id ) {
            $wpdb->update( $table, $fields, array( 'id' => $id ), 
                array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%d' ), 
                array( '%d' ) );
            return $id;
        } else {
            $wpdb->insert( $table, $fields, 
                array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%d' ) );
            return $wpdb->insert_id;
        }
    }

    public static function delete_review( $id ) {
        global $wpdb;
        $wpdb->delete( self::table( 'reviews' ), array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * Cambia el estado de moderación de una reseña y mantiene en sincronía
     * el flag legacy `approved` (usado por la tienda pública).
     */
    public static function set_status( $id, $status ) {
        global $wpdb;
        $status = sanitize_key( $status );
        if ( ! in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ) {
            return;
        }
        $wpdb->update(
            self::table( 'reviews' ),
            array(
                'status'   => $status,
                'approved' => 'approved' === $status ? 1 : 0,
            ),
            array( 'id' => $id ),
            array( '%s', '%d' ),
            array( '%d' )
        );
    }

    public static function approve_review( $id ) {
        self::set_status( $id, 'approved' );
    }

    public static function reject_review( $id ) {
        self::set_status( $id, 'rejected' );
    }

    /* ---------------- Estadísticas ---------------- */

    public static function get_product_rating( $product_id ) {
        global $wpdb;
        $table = self::table( 'reviews' );
        
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews 
            FROM {$table} WHERE product_id = %d AND approved = 1",
            $product_id
        ) );
        
        return array(
            'average' => $result ? round( $result->avg_rating, 1 ) : 0,
            'total' => $result ? (int) $result->total_reviews : 0,
        );
    }

    /**
     * Rating promedio de una TIENDA (ubicación): valoraciones aprobadas
     * asociadas a la ubicación (location_id), independientes del producto.
     */
    public static function get_location_rating( $location_id ) {
        global $wpdb;
        $table = self::table( 'reviews' );

        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews 
            FROM {$table} WHERE location_id = %d AND approved = 1",
            $location_id
        ) );

        return array(
            'average' => $result ? round( $result->avg_rating, 1 ) : 0,
            'total' => $result ? (int) $result->total_reviews : 0,
        );
    }

    /**
     * Distribución de estrellas de una TIENDA (ubicación).
     */
    public static function get_location_rating_distribution( $location_id ) {
        global $wpdb;
        $table = self::table( 'reviews' );

        $distribution = array( 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT rating, COUNT(*) as count 
            FROM {$table} WHERE location_id = %d AND approved = 1 
            GROUP BY rating",
            $location_id
        ) );

        foreach ( $results as $row ) {
            $distribution[ $row->rating ] = (int) $row->count;
        }

        return $distribution;
    }

    public static function get_rating_distribution( $product_id ) {
        global $wpdb;
        $table = self::table( 'reviews' );
        
        $distribution = array( 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 );
        
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT rating, COUNT(*) as count 
            FROM {$table} WHERE product_id = %d AND approved = 1 
            GROUP BY rating",
            $product_id
        ) );
        
        foreach ( $results as $row ) {
            $distribution[ $row->rating ] = (int) $row->count;
        }
        
        return $distribution;
    }

    public static function mark_as_verified( $product_id, $customer_id ) {
        global $wpdb;
        $wpdb->update( self::table( 'reviews' ), 
            array( 'verified_purchase' => 1 ), 
            array( 'product_id' => $product_id, 'customer_id' => $customer_id ), 
            array( '%d' ), 
            array( '%d', '%d' ) );
    }

    /**
     * Estadísticas generales de todas las reseñas (módulo Valoraciones).
     */
    public static function get_overall_stats() {
        global $wpdb;
        $table = self::table( 'reviews' );

        $avg = $wpdb->get_var( "SELECT AVG(rating) FROM {$table} WHERE status = 'approved'" );
        $approved = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'approved'" );
        $pending = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
        $rejected = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'rejected'" );

        return array(
            'average_rating' => $avg ? round( (float) $avg, 1 ) : 0,
            'approved_count' => (int) $approved,
            'pending_count'  => (int) $pending,
            'rejected_count' => (int) $rejected,
            'total'          => (int) $approved + (int) $pending + (int) $rejected,
        );
    }
}
