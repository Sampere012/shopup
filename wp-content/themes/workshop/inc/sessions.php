<?php
/**
 * Sesiones de trabajo de los trabajadores (check-in / check-out).
 *
 * Al entrar, si el trabajador tiene un turno planificado hoy en el calendario
 * (WS_Shifts), se le abre una sesión de trabajo automáticamente. Quien tiene
 * workers_manage (el dueño) las ve en el panel de Trabajadores —entrada,
 * salida y duración— y puede cerrarlas o deshabilitar al trabajador.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Sessions {

    protected static function table( $t ) {
        return ws_table_name( $t );
    }

    /**
     * Sesión abierta (status='open') de un usuario en este negocio.
     */
    public static function get_active( $user_id = 0 ) {
        global $wpdb;
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'work_sessions' ) . "
            WHERE user_id = %d AND status = 'open' ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
    }

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table( 'work_sessions' ) . " WHERE id = %d",
            (int) $id
        ) );
    }

    /**
     * Turno planificado hoy para el trabajador en el calendario (o null).
     */
    public static function shift_today( $user_id, $location_id = 0 ) {
        if ( ! class_exists( 'WS_Shifts' ) ) {
            return null;
        }
        $today  = current_time( 'Y-m-d' );
        $shifts = WS_Shifts::for_range( $today, $today, (int) $location_id );
        foreach ( $shifts as $shift ) {
            if ( (int) $shift->user_id === (int) $user_id ) {
                return $shift;
            }
        }
        return null;
    }

    /**
     * Check-in automático al entrar: solo si hay turno planificado hoy.
     *
     * Idempotente: si el trabajador ya tiene sesión abierta HOY la devuelve.
     * Una sesión abierta de un día anterior (olvidada sin cerrar) se cierra
     * sola al nuevo ingreso y se abre una nueva para hoy.
     */
    public static function auto_clockin( $user_id ) {
        $user_id  = (int) $user_id;
        $today    = current_time( 'Y-m-d' );
        $existing = self::get_active( $user_id );

        if ( $existing ) {
            if ( $existing->session_date === $today ) {
                return $existing;
            }
            self::end( $existing->id, $user_id, __( 'Auto-cerrada por nuevo ingreso', 'workshop' ) );
        }

        $shift = self::shift_today( $user_id );
        if ( ! $shift ) {
            return null;
        }

        global $wpdb;
        $wpdb->insert( self::table( 'work_sessions' ), array(
            'user_id'      => $user_id,
            'location_id'  => (int) $shift->location_id,
            'shift_id'     => (int) $shift->id,
            'session_date' => $today,
            'clock_in'     => current_time( 'mysql' ),
            'status'       => 'open',
        ), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );

        return self::get_active( $user_id );
    }

    /**
     * Cierra una sesión abierta con la hora de salida. Devuelve true o WP_Error.
     */
    public static function end( $id, $closed_by = 0, $note = '' ) {
        global $wpdb;
        $id        = (int) $id;
        $closed_by = $closed_by ? (int) $closed_by : get_current_user_id();
        $session   = self::get( $id );
        if ( ! $session ) {
            return new WP_Error( 'not_found', __( 'Sesión no encontrada.', 'workshop' ) );
        }
        if ( 'open' !== $session->status ) {
            return new WP_Error( 'already_closed', __( 'La sesión ya estaba cerrada.', 'workshop' ) );
        }
        $wpdb->update(
            self::table( 'work_sessions' ),
            array(
                'clock_out' => current_time( 'mysql' ),
                'status'    => 'closed',
                'closed_by' => $closed_by,
                'note'      => sanitize_text_field( $note ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
        return true;
    }

    /**
     * Cierra todas las sesiones abiertas de un trabajador (al deshabilitarlo).
     */
    public static function close_all_open( $user_id, $closed_by = 0, $note = '' ) {
        global $wpdb;
        $closed_by = $closed_by ? (int) $closed_by : get_current_user_id();
        $note      = sanitize_text_field( $note );
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table( 'work_sessions' ) . "
            SET clock_out = %s, status = 'closed', closed_by = %d,
                note = CASE WHEN note = '' THEN %s ELSE CONCAT(note, ' | ', %s) END
            WHERE user_id = %d AND status = 'open'",
            current_time( 'mysql' ), $closed_by, $note, $note, $user_id
        ) );
    }

    /**
     * Todas las sesiones abiertas del negocio (con nombre de trabajador y
     * ubicación), para el panel de quien gestiona trabajadores.
     */
    public static function active() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT s.*, u.display_name AS worker_name, l.name AS location_name
            FROM " . self::table( 'work_sessions' ) . " s
            LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
            LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = s.location_id
            WHERE s.status = 'open'
            ORDER BY s.clock_in ASC"
        );
    }

    /**
     * Sesiones de un día (abiertas y cerradas) con nombres, para el registro
     * del panel: entrada, salida y quién cerró.
     */
    public static function today( $date = '' ) {
        global $wpdb;
        $date = $date ? sanitize_text_field( $date ) : current_time( 'Y-m-d' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, u.display_name AS worker_name, l.name AS location_name,
                cu.display_name AS closed_by_name
            FROM " . self::table( 'work_sessions' ) . " s
            LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
            LEFT JOIN " . self::table( 'locations' ) . " l ON l.id = s.location_id
            LEFT JOIN {$wpdb->users} cu ON cu.ID = s.closed_by
            WHERE s.session_date = %s
            ORDER BY s.clock_in DESC",
            $date
        ) );
    }
}

/**
 * ¿El trabajador está deshabilitado (no puede entrar)?
 */
function ws_worker_disabled( $user_id ) {
    return (bool) get_user_meta( (int) $user_id, 'ws_disabled', true );
}

/**
 * Duración legible de una sesión (transcurrida si aún no tiene salida).
 */
function ws_session_duration( $clock_in, $clock_out = null ) {
    $in   = (int) mysql2date( 'U', $clock_in );
    $out  = ( null !== $clock_out && '' !== (string) $clock_out ) ? (int) mysql2date( 'U', $clock_out ) : current_time( 'timestamp' );
    $diff = max( 0, $out - $in );
    $h    = floor( $diff / 3600 );
    $m    = floor( ( $diff % 3600 ) / 60 );
    if ( $h > 0 ) {
        return sprintf( '%d h %02d min', $h, $m );
    }
    return sprintf( '%d min', $m );
}
