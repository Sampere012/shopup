<?php
/**
 * Reto de verificación de correo en el login WEB (el mismo que ya usa la app
 * móvil). El ajuste es el mismo de wp-admin → ShopUp → Sesión y seguridad
 * (ws_login_email_challenge: unverified | always | off):
 *
 *  - Después de wp_signon(), si el reto aplica, la sesión recién creada NO
 *    navega todavía: se marca pendiente de verificar y se redirige a
 *    /login/verify/ con un token opaco (hash de la sesión; no revela nada).
 *  - La pantalla pide el código de 6 dígitos enviado al buzón del usuario.
 *  - Al verificar: se marca ws_email_verified_at y la sesión queda
 *    liberada (ws_login_verified_<sid>) y se reanuda la redirección.
 *  - El modo «Siempre» con TRABAJADOR avisa al DUEÑO del negocio del
 *    ingreso (mismo comportamiento que la app móvil).
 *
 * Registro de rutas: no usamos regla de reescritura fija; la pantalla vive
 * en la query var ws_login_verify con cookie de sesión.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/** Cookie de sesión el login clásico de WP (necesaria durante el reto). */
function ws_login_challenge_marker() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return '';
    }
    return 'ws_login_verified_' . md5( (string) session_id() );
}

/** ¿La sesión actual ya pasó el reto de correo? */
function ws_login_session_verified() {
    $key = ws_login_challenge_marker();
    if ( '' === $key ) {
        return false;
    }
    $sid = session_id();
    if ( '' === $sid ) {
        // La web entra por wp_signon sin iniciar sesión PHP; la marca vive
        // en un transient por usuario+sello (corto) como respaldo.
        return (bool) get_user_meta( get_current_user_id(), 'ws_login_seen_ok', true );
    }
    return (bool) get_transient( $key );
}

/** Reserva pendiente de verificación de la sesión POST-login. */
function ws_login_challenge_pending() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return false;
    }
    $sid = session_id();
    if ( '' === $sid ) {
        return (bool) get_user_meta( $user_id, 'ws_login_challenge_pending', true );
    }
    return (bool) get_transient( 'ws_login_challenge_pending_' . md5( $sid ) );
}

add_action( 'init', 'ws_login_challenge_session_boot', 1 );
function ws_login_challenge_session_boot() {
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }
    if ( '' === session_id() && ! headers_sent() ) {
        @session_start();
    }
}

add_action( 'init', 'ws_login_challenge_handle_post', 5 );
function ws_login_challenge_handle_post() {
    if ( empty( $_POST['ws_verify'] ) && empty( $_POST['ws_resend'] ) ) {
        return;
    }
    if ( ! wp_verify_nonce( $_POST['ws_verify_nonce'] ?? '', 'ws_login_verify' ) ) {
        return;
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_safe_redirect( ws_login_scheme_url( home_url( '/login/' ) ) );
        exit;
    }
    // Rate limit por IP: verificar 15/hora, reenviar 5/hora (como la app).
    $ip = function_exists( 'ws_client_ip' ) ? ws_client_ip() : '';
    if ( empty( $_POST['ws_resend'] ) ) {
        $limit = function_exists( 'ws_rate_limit' ) ? ws_rate_limit( 'web_verify_ip', $ip, 15, HOUR_IN_SECONDS ) : true;
        if ( true !== $limit ) {
            ws_login_verify_page( array( 'token' => (string) ( $_POST['ws_v_token'] ?? '' ), 'redirect' => (string) ( $_POST['ws_v_redirect'] ?? '' ), 'error' => (string) $limit ) );
            exit;
        }
        $code = preg_replace( '/[^0-9]/', '', (string) ( $_POST['ws_code'] ?? '' ) );
        $u    = wp_get_current_user();
        $data = ws_verify_email_code( $u->user_email, $code );
        if ( is_wp_error( $data ) ) {
            ws_login_verify_page( array( 'token' => (string) ( $_POST['ws_v_token'] ?? '' ), 'redirect' => (string) ( $_POST['ws_v_redirect'] ?? '' ), 'error' => $data->get_error_message() ) );
            exit;
        }
        update_user_meta( $user_id, 'ws_email_verified_at', time() );
        delete_user_meta( $user_id, 'ws_login_challenge_pending' );
        $sid = session_id();
        if ( '' !== $sid ) {
            set_transient( ws_login_challenge_marker(), 1, 30 * MINUTE_IN_SECONDS );
        }
        set_user_meta_fallback_ok( $user_id );
        ws_log_audit( 'web_login_verified', 'user', $user_id );
        ws_login_challenge_redirect( (string) ( $_POST['ws_v_redirect'] ?? '' ), $user_id );
        exit;
    }
    // Reenvío del código (usando el mismo correo del usuario en sesión).
    $limit = function_exists( 'ws_rate_limit' ) ? ws_rate_limit( 'web_resend_ip', $ip, 5, HOUR_IN_SECONDS ) : true;
    if ( true !== $limit ) {
        ws_login_verify_page( array( 'token' => (string) ( $_POST['ws_v_token'] ?? '' ), 'redirect' => (string) ( $_POST['ws_v_redirect'] ?? '' ), 'error' => (string) $limit ) );
        exit;
    }
    $u = wp_get_current_user();
    $sent = ws_resend_verification_code( $u->user_email );
    $error = is_wp_error( $sent ) ? $sent->get_error_message() : '';
    ws_login_verify_page( array(
        'token'    => (string) ( $_POST['ws_v_token'] ?? '' ),
        'redirect' => (string) ( $_POST['ws_v_redirect'] ?? '' ),
        'error'    => $error,
    ) );
    exit;
}

/** Marca la cuenta con visto bueno por si la sesión PHP no existe (cli). */
function set_user_meta_fallback_ok( $user_id ) {
    update_user_meta( $user_id, 'ws_login_seen_ok', time() );
}

/** Redirige tras verificar: redirect_to si llegó, si no al panel del rol. */
function ws_login_challenge_redirect( $redirect, $user_id ) {
    if ( $redirect && ( '#' === $redirect[0] || 0 === strpos( $redirect, home_url() ) || 0 === strpos( $redirect, '/' ) ) ) {
        wp_safe_redirect( $redirect );
        exit;
    }
    if ( user_can( $user_id, 'manage_options' ) ) {
        wp_safe_redirect( admin_url() );
        exit;
    }
    $role = ws_user_role( $user_id );
    if ( $role ) {
        wp_safe_redirect( ws_panel_url( $role ) );
        exit;
    }
    wp_safe_redirect( ws_business_home() );
    exit;
}

/** Renderiza la pantalla de verificación (plantilla verify-login.php). */
function ws_login_verify_page( array $args = array(), $template = true ) {
    $email = '';
    if ( get_current_user_id() ) {
        $email = (string) wp_get_current_user()->user_email;
    }
    $args = wp_parse_args( $args, array(
        'email'      => $email,
        'email_mask' => ws_mask_email( $email ),
        'can_resend' => true,
    ) );
    status_header( 200 );
    if ( $template ) {
        include WS_PATH . 'templates/verify-login.php';
    }
}

/** Mascara el email (u***@dominio) para mostrarlo de forma segura. */
function ws_mask_email( $email ) {
    $email = (string) $email;
    if ( '' === $email ) {
        return '';
    }
    $parts = explode( '@', $email );
    if ( count( $parts ) !== 2 ) {
        return $email;
    }
    $local = $parts[0];
    $l     = strlen( $local );
    if ( $l <= 2 ) {
        $masked = str_repeat( '*', $l );
    } else {
        $masked = substr( $local, 0, 1 ) . str_repeat( '*', $l - 1 );
    }
    return $masked . '@' . $parts[1];
}

/**
 * Correo del DUEÑO del negocio de un usuario (para avisos del reto
 * «Siempre» con trabajadores). Vacío si no hay dueño distinto del usuario.
 */
function ws_owner_email_of( $user_id ) {
    $biz_id = (int) get_user_meta( (int) $user_id, 'ws_business_id', true );
    $owners = get_users( array(
        'role'       => 'ws_owner',
        'meta_key'   => 'ws_business_id',
        'meta_value' => $biz_id,
        'number'     => 1,
        'fields'     => 'all',
    ) );
    if ( ! $owners ) {
        return '';
    }
    $own = $owners[0];
    return ( (int) $own->ID !== (int) $user_id && is_email( $own->user_email ) ) ? (string) $own->user_email : '';
}

/** Redirección de seguridad si la sesión se perdió durante el reto. */

/** ¿Aplica el reto a este usuario? (misma lógica que la app móvil). */
function ws_login_challenge_needed( $user_id, $challenge_mode = null ) {
    if ( null === $challenge_mode ) {
        $challenge_mode = (string) get_option( 'ws_login_email_challenge', 'unverified' );
    }
    if ( ! in_array( $challenge_mode, array( 'unverified', 'always', 'off' ), true ) ) {
        $challenge_mode = 'unverified';
    }
    if ( 'off' === $challenge_mode ) {
        return false;
    }
    $uid = (int) $user_id;
    if ( 'always' === $challenge_mode ) {
        return true;
    }
    return function_exists( 'ws_email_verified_at' ) ? ws_email_verified_at( $uid ) <= 0 : false;
}
