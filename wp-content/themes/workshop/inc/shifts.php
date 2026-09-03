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

    /**
     * Guarda un turno por cada fecha del array (asignación de mes completo).
     * No duplica: si ya existe un turno del trabajador en esa fecha se omite.
     *
     * @param array  $dates Lista de fechas Y-m-d.
     * @param array  $data  Campos comunes: location_id, user_id, time_start, time_end, note.
     * @return array{created:int[],skipped:int} IDs creados y cuántos se omitieron.
     */
    public static function save_bulk( $dates, $data ) {
        global $wpdb;
        $created = array();
        $skipped = 0;
        $location_id = (int) ( $data['location_id'] ?? 0 );
        $user_id     = (int) ( $data['user_id'] ?? 0 );
        $time_start  = sanitize_text_field( $data['time_start'] ?? '' );
        $time_end    = sanitize_text_field( $data['time_end'] ?? '' );
        $note        = sanitize_text_field( $data['note'] ?? '' );
        if ( ! $location_id || ! $user_id || empty( $dates ) ) {
            return $created;
        }
        $dates = array_values( array_unique( array_filter( (array) $dates, function ( $d ) {
            return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
        } ) ) );
        foreach ( $dates as $shift_date ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . self::table( 'shifts' ) . "
                 WHERE shift_date=%s AND user_id=%d AND location_id=%d LIMIT 1",
                $shift_date, $user_id, $location_id
            ) );
            if ( $exists ) {
                $skipped++;
                continue;
            }
            $wpdb->insert( self::table( 'shifts' ), array(
                'location_id' => $location_id,
                'user_id'     => $user_id,
                'shift_date'  => $shift_date,
                'time_start'  => $time_start,
                'time_end'    => $time_end,
                'note'        => $note,
            ) );
            if ( $wpdb->insert_id ) {
                $created[] = (int) $wpdb->insert_id;
            }
        }
        return array( 'created' => $created, 'skipped' => $skipped );
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
