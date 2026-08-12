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

// Tras cualquier login (también vía wp-login.php) redirigir según rol.
add_filter( 'login_redirect', 'ws_login_redirect', 10, 3 );
function ws_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
    if ( is_wp_error( $user ) || ! $user ) {
        return $redirect_to;
    }
    $role = ws_user_role( $user->ID );
    if ( $role ) {
        return ws_panel_url( $role );
    }
    if ( user_can( $user->ID, 'manage_options' ) ) {
        return ws_login_scheme_url( admin_url() );
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

add_action( 'init', 'ws_handle_login_post' );
function ws_handle_login_post() {
    if ( empty( $_POST['ws_login'] ) || ! wp_verify_nonce( $_POST['ws_nonce'], 'ws_login' ) ) {
        return;
    }
    $creds = array(
        'user_login'    => sanitize_text_field( $_POST['ws_user'] ?? '' ),
        'user_password' => (string) ( $_POST['ws_pass'] ?? '' ),
        'remember'      => ! empty( $_POST['ws_remember'] ),
    );
    // Cookie de sesión acorde al esquema (Secure en HTTPS): evita que
    // wp-admin pida re-autenticar al admin y entre en bucle.
    $user = wp_signon( $creds, ws_login_secure_cookie() );
    if ( is_wp_error( $user ) ) {
        $url = add_query_arg( 'ws_login_error', '1', ws_login_scheme_url( home_url( '/login/' ) ) );
        wp_safe_redirect( $url );
        exit;
    } else {
        $role = ws_user_role( $user->ID );
        wp_set_current_user( $user->ID );
        if ( $role ) {
            wp_safe_redirect( ws_panel_url( $role ) );
        } elseif ( user_can( $user->ID, 'manage_options' ) ) {
            // El admin del sistema va directo a wp-admin, con el esquema de
            // la petición (https) para no rebotar a http y buclar.
            wp_safe_redirect( ws_login_scheme_url( admin_url() ) );
        } else {
            wp_safe_redirect( ws_business_home() );
        }
        exit;
    }
}
