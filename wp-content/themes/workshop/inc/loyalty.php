<?php
/**
 * Sistema de Fidelización.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Loyalty {

    /**
     * Obtener clientes con puntos.
     */
    public static function get_customers_with_points( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'search' => '',
            'sort_by' => 'points_desc',
            'limit' => 20,
            'offset' => 0,
        );
        $args = wp_parse_args( $args, $defaults );

        $table = ws_table_name( 'customers' );
        $where = array( '1=1' );
        $orderby = 'c.loyalty_points DESC';

        if ( ! empty( $args['search'] ) ) {
            $where[] = $wpdb->prepare( "(c.name LIKE %s OR c.email LIKE %s)", '%' . $args['search'] . '%', '%' . $args['search'] . '%' );
        }

        switch ( $args['sort_by'] ) {
            case 'points_asc':
                $orderby = 'c.loyalty_points ASC';
                break;
            case 'name_asc':
                $orderby = 'c.name ASC';
                break;
            case 'name_desc':
                $orderby = 'c.name DESC';
                break;
            default:
                $orderby = 'c.loyalty_points DESC';
        }

        $where_clause = implode( ' AND ', $where );

        $sql = $wpdb->prepare(
            "SELECT c.*,
                    COALESCE(c.loyalty_points, 0) as points,
                    COALESCE(c.total_spent, 0) as total_spent,
                    c.created_at as last_activity
            FROM $table c
            WHERE $where_clause
            ORDER BY $orderby
            LIMIT %d OFFSET %d",
            $args['limit'],
            $args['offset']
        );

        $customers = $wpdb->get_results( $sql );

        // Calcular tier para cada cliente
        foreach ( $customers as $customer ) {
            $customer->tier = self::calculate_tier( $customer->loyalty_points );
        }

        return $customers;
    }

    /**
     * Contar clientes con puntos.
     */
    public static function count_customers_with_points( $args = array() ) {
        global $wpdb;

        $table = ws_table_name( 'customers' );
        $where = array( '1=1' );

        if ( ! empty( $args['search'] ) ) {
            $where[] = $wpdb->prepare( "(name LIKE %s OR email LIKE %s)", '%' . $args['search'] . '%', '%' . $args['search'] . '%' );
        }

        $where_clause = implode( ' AND ', $where );

        return $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_clause" );
    }

    /**
     * Obtener estadísticas generales.
     */
    public static function get_overall_stats() {
        global $wpdb;

        $customers_table = ws_table_name( 'customers' );
        $transactions_table = ws_table_name( 'loyalty_transactions' );

        $total_points_earned = $wpdb->get_var( "SELECT COALESCE(SUM(points), 0) FROM $transactions_table WHERE points > 0" );
        $total_points_redeemed = $wpdb->get_var( "SELECT COALESCE(SUM(ABS(points)), 0) FROM $transactions_table WHERE points < 0" );
        $active_customers = $wpdb->get_var( "SELECT COUNT(*) FROM $customers_table WHERE loyalty_points > 0" );

        return array(
            'total_points_earned' => (int) $total_points_earned,
            'total_points_redeemed' => (int) $total_points_redeemed,
            'active_customers' => (int) $active_customers,
        );
    }

    /**
     * Obtener configuración del programa.
     */
    public static function get_settings() {
        $defaults = array(
            'points_per_euro' => 1,
            'point_value' => 0.01,
            'silver_tier' => 100,
            'gold_tier' => 500,
        );

        $settings = ws_biz_option( 'ws_loyalty_settings', $defaults );
        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Guardar configuración del programa.
     */
    public static function save_settings( $settings ) {
        ws_save_biz_option( 'ws_loyalty_settings', $settings );
    }

    /**
     * Ajustar puntos de un cliente.
     */
    public static function adjust_points( $customer_id, $points, $reason ) {
        global $wpdb;

        $customers_table = ws_table_name( 'customers' );
        $transactions_table = ws_table_name( 'loyalty_transactions' );

        // Actualizar puntos del cliente
        $wpdb->query( $wpdb->prepare(
            "UPDATE $customers_table SET loyalty_points = loyalty_points + %d WHERE id = %d",
            $points,
            $customer_id
        ) );

        // Registrar transacción de puntos
        $wpdb->insert( $transactions_table, array(
            'customer_id' => $customer_id,
            'points' => $points,
            'type' => $points > 0 ? 'earned' : 'redeemed',
            'reference' => 'manual',
            'order_id' => 0,
            'note' => $reason,
        ), array( '%d', '%d', '%s', '%s', '%d', '%s' ) );
    }

    /**
     * Calcular tier basado en puntos.
     */
    private static function calculate_tier( $points ) {
        $settings = self::get_settings();

        if ( $points >= $settings['gold_tier'] ) {
            return 'gold';
        } elseif ( $points >= $settings['silver_tier'] ) {
            return 'silver';
        } else {
            return 'bronze';
        }
    }

    /**
     * Agregar puntos por compra.
     */
    public static function add_points_for_purchase( $customer_id, $amount ) {
        $settings = self::get_settings();
        $points = floor( $amount * $settings['points_per_euro'] );

        if ( $points > 0 ) {
            self::adjust_points( $customer_id, $points, sprintf( __( 'Compra de %s', 'workshop' ), number_format( $amount, 2 ) ) );
        }
    }
}
