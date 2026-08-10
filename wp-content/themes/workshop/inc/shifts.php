<?php
/**
 * Turnos y asignación de trabajadores.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Shifts {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $fields = array(
            'location_id' => (int) ( $data['location_id'] ?? 0 ),
            'user_id'     => (int) ( $data['user_id'] ?? 0 ),
            'shift_date'  => sanitize_text_field( $data['shift_date'] ?? '' ),
            'time_start'  => sanitize_text_field( $data['time_start'] ?? '' ),
            'time_end'    => sanitize_text_field( $data['time_end'] ?? '' ),
            'note'        => sanitize_text_field( $data['note'] ?? '' ),
        );
        if ( ! $fields['location_id'] || ! $fields['user_id'] || empty( $fields['shift_date'] ) ) {
            return new WP_Error( 'invalid', __( 'Completa ubicación, trabajador y fecha.', 'workshop' ) );
        }
        if ( $id ) {
            $wpdb->update( self::table( 'shifts' ), $fields, array( 'id' => $id ) );
        } else {
            $wpdb->insert( self::table( 'shifts' ), $fields );
            $id = $wpdb->insert_id;
        }
        return (int) $id;
    }

    public static function delete( $id ) {
        global $wpdb;
        $wpdb->delete( self::table( 'shifts' ), array( 'id' => $id ) );
    }

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table( 'shifts' ) . " WHERE id = %d", $id ) );
    }

    public static function for_range( $start, $end, $location_id = 0 ) {
        global $wpdb;
        $where = array( $wpdb->prepare( 's.shift_date BETWEEN %s AND %s', $start, $end ) );
        if ( $location_id ) {
            $where[] = $wpdb->prepare( 's.location_id = %d', $location_id );
        }
        $sql = "SELECT s.*, l.name AS location_name, u.display_name AS user_name
                FROM " . self::table( 'shifts' ) . " s
                LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = s.location_id
                LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
                WHERE " . implode( ' AND ', $where ) . " ORDER BY s.shift_date ASC, s.time_start ASC";
        return $wpdb->get_results( $sql );
    }
}
