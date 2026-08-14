<?php
/**
 * Login/logout front-end.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

// Registrar el último acceso del usuario.
add_action( 'wp_login', 'ws_track_last_login', 10, 2 );
function ws_track_last_login( $user_login, $user ) {
    if ( $user instanceof WP_User ) {
        update_user_meta( $user->ID, 'ws_last_login', current_time( 'mysql' ) );
    }
}

// Check-in automático de jornada: si el trabajador tiene turno planificado hoy
// según el calendario (WS_Shifts), al entrar se le abre su sesión de trabajo.
// El dueño la ve (entrada/salida) en el panel de Trabajadores.
add_action( 'wp_login', 'ws_auto_clockin_shift', 20, 2 );
function ws_auto_clockin_shift( $user_login, $user ) {
    if ( ! $user instanceof WP_User ) {
        return;
    }
    $role = ws_user_role( $user->ID );
    if ( ! in_array( $role, array( 'storekeeper', 'seller' ), true ) ) {
        return;
    }
    if ( ! class_exists( 'WS_Sessions' ) || ws_worker_disabled( $user->ID ) ) {
        return;
    }
    WS_Sessions::auto_clockin( $user->ID );
}

// Bloquear el acceso de trabajadores deshabilitados por el dueño.
add_filter( 'authenticate', 'ws_block_disabled_login', 30, 3 );
function ws_block_disabled_login( $user, $username, $password ) {
    if ( $user instanceof WP_User && ws_worker_disabled( $user->ID ) ) {
        return new WP_Error(
            'ws_disabled',
            __( 'Tu cuenta está deshabilitada. Contacta al dueño del negocio.', 'workshop' )
        );
    }
    return $user;
}

// Tras cualquier login (también vía wp-login.php) redirigir según rol.
add_filter( 'login_redirect', 'ws_login_redirect', 10, 3 );
function ws_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
    if ( is_wp_error( $user ) || ! $user ) {
        return $redirect_to;
    }
    // El admin del sistema siempre va a wp-admin, aunque tenga un rol de
    // negocio asignado: no participa en los paneles de negocio.
    if ( user_can( $user->ID, 'manage_options' ) ) {
        return ws_login_scheme_url( admin_url() );
    }
    $role = ws_user_role( $user->ID );
    if ( $role ) {
        return ws_panel_url( $role );
    }
    return $redirect_to;
}

/**
 * Esquema (http/https) de una URL según la petición actual.
 *
 * La BD guarda home/siteurl en http, pero el sitio se sirve por HTTPS en
 * producción. Si las redirecciones del login devuelven URLs http, el
 * navegador rebota http↔https y WordPress exige re-autenticar la sesión:
 * el resultado es el clásico «te ha redirigido demasiadas veces» al entrar
 * a wp-admin (afecta a móviles/otros PCs; el laptop con sesión previa no lo
 * nota). Con esta función el esquema siempre coincide con la petición.
 */
function ws_login_scheme_url( $url ) {
    $scheme = ( is_ssl() || force_ssl_admin() ) ? 'https' : 'http';
    return set_url_scheme( $url, $scheme );
}

/**
 * Cookie de sesión segura (Secure) cuando toca: coincide con la lógica de
 * wp-login.php. Forzar false (como antes) en un sitio HTTPS hacía que
 * wp-admin volviera a pedir autenticación → bucle de redirecciones.
 */
function ws_login_secure_cookie() {
    return is_ssl() || force_ssl_admin();
}

// Redirigir el login de WordPress al login de la plantilla.
add_action( 'init', 'ws_redirect_wp_login', 5 );
function ws_redirect_wp_login() {
    if ( wp_doing_ajax() || ! isset( $_SERVER['SCRIPT_NAME'] ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
        return;
    }
    if ( 'wp-login.php' === basename( $_SERVER['SCRIPT_NAME'] ) && empty( $_GET['action'] ) ) {
        $redirect = ! empty( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '';
        // URL de /login/ con el esquema de la petición (https si toca) para
        // no degradar a http y volver a entrar en el bucle http↔https.
        $url = ws_login_scheme_url( home_url( '/login/' ) );
        if ( $redirect ) {
            // add_query_arg ya codifica el valor: sin urlencode() doble aquí
            // (antes quedaba redirect_to=%252F…, doble-encodificado).
            $url = add_query_arg( 'redirect_to', $redirect, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}

// Sesión de larga duración: los usuarios trabajan offline (PWA con cola de
// sincronización) y no deben perder la sesión a mitad de jornada ni al cerrar
// el navegador. Los días se configuran en wp-admin > ShopUp > Sesión y
// seguridad (ws_session_expiration_days, 30 por defecto; el triple si el
// usuario marcó «Recuérdame»).
add_filter( 'auth_cookie_expiration', function ( $expiration, $user_id, $remember ) {
    $days = max( 1, min( 365, (int) get_option( 'ws_session_expiration_days', 30 ) ) );
    return ( (int) $remember ? 3 : 1 ) * $days * DAY_IN_SECONDS;
}, 10, 3 );

add_action( 'init', 'ws_handle_login_post' );
function ws_handle_login_post() {
    if ( empty( $_POST['ws_login'] ) || ! wp_verify_nonce( $_POST['ws_nonce'], 'ws_login' ) ) {
        return;
    }
    $login_value = sanitize_text_field( $_POST['ws_user'] ?? '' );
    // Validador de correo en el acceso: si el usuario escribe un email, debe
    // ser un correo real y permanente (no desechable) como en el registro.
    if ( false !== strpos( $login_value, '@' ) ) {
        $email_check = function_exists( 'ws_email_allowed' ) ? ws_email_allowed( $login_value ) : true;
        if ( true !== $email_check ) {
            $url = add_query_arg( 'ws_login_error', '1', ws_login_scheme_url( home_url( '/login/' ) ) );
            wp_safe_redirect( $url );
            exit;
        }
    }
    $creds = array(
        'user_login'    => $login_value,
        'user_password' => (string) ( $_POST['ws_pass'] ?? '' ),
        // Sesión de larga duración SIEMPRE: WordPress solo envía la cookie con
        // Expires cuando remember=true (si no, es cookie de sesión y se cae al
        // cerrar el navegador, rompiendo el trabajo offline). La duración real
        // la fija el filtro auth_cookie_expiration (90 días).
        'remember'      => true,
    );
    // Cookie de sesión acorde al esquema (Secure en HTTPS): evita que
    // wp-admin pida re-autenticar al admin y entre en bucle.
    $user = wp_signon( $creds, ws_login_secure_cookie() );
    if ( is_wp_error( $user ) ) {
        $url = add_query_arg( 'ws_login_error', '1', ws_login_scheme_url( home_url( '/login/' ) ) );
        wp_safe_redirect( $url );
        exit;
    } else {
        wp_set_current_user( $user->ID );
        if ( user_can( $user->ID, 'manage_options' ) ) {
            // El admin del sistema va directo a wp-admin, con el esquema de
            // la petición (https) para no rebotar a http y buclar. Aunque
            // tenga rol de negocio, no participa en los paneles.
            wp_safe_redirect( ws_login_scheme_url( admin_url() ) );
            exit;
        }
        $role = ws_user_role( $user->ID );
        if ( $role ) {
            wp_safe_redirect( ws_panel_url( $role ) );
        } else {
            wp_safe_redirect( ws_business_home() );
        }
        exit;
    }
}
