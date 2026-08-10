<?php
/**
 * Registro público de negocios (2 pasos, con verificación por email).
 *
 * Paso 1: el usuario envía los datos del negocio y del dueño. Se validan y se
 * envía un código de verificación de 6 dígitos a su email (SMTP configurado
 * desde wp-admin).
 *
 * Paso 2: el usuario introduce el código. Si es correcto se crea el negocio
 * (con acceso automático al marketplace), el usuario con rol de dueño y una
 * suscripción de prueba gratis (7 días por defecto). El usuario queda logueado
 * y se le redirige a su panel.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Paso 1: validar datos y enviar código
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_nopriv_ws_register_step1', 'ws_ajax_register_step1' );
add_action( 'wp_ajax_ws_register_step1', 'ws_ajax_register_step1' );
function ws_ajax_register_step1() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada. Recarga la página.', 'workshop' ) ) );
    }
    if ( is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Ya tienes una sesión iniciada.', 'workshop' ) ) );
    }
    $data = array(
        'biz_name'   => sanitize_text_field( $_POST['biz_name'] ?? '' ),
        'slug'       => sanitize_title( (string) ( $_POST['slug'] ?? '' ) ),
        'owner_name' => sanitize_text_field( $_POST['owner_name'] ?? '' ),
        'email'      => sanitize_email( $_POST['email'] ?? '' ),
        'phone'      => sanitize_text_field( $_POST['phone'] ?? '' ),
        'username'   => sanitize_user( $_POST['username'] ?? '' ),
        'password'   => (string) ( $_POST['password'] ?? '' ),
    );

    if ( '' === $data['biz_name'] ) {
        wp_send_json_error( array( 'msg' => __( 'El nombre del negocio es obligatorio.', 'workshop' ) ) );
    }
    if ( '' === $data['slug'] ) {
        wp_send_json_error( array( 'msg' => __( 'La dirección (slug) del negocio es obligatoria.', 'workshop' ) ) );
    }
    if ( in_array( $data['slug'], WS_Business::RESERVED_SLUGS, true ) || WS_Business::slug_taken( $data['slug'] ) ) {
        wp_send_json_error( array( 'msg' => __( 'Esa dirección ya está en uso. Prueba con otra.', 'workshop' ) ) );
    }
    if ( '' === $data['owner_name'] ) {
        wp_send_json_error( array( 'msg' => __( 'Tu nombre es obligatorio.', 'workshop' ) ) );
    }
    if ( ! is_email( $data['email'] ) ) {
        wp_send_json_error( array( 'msg' => __( 'Email inválido.', 'workshop' ) ) );
    }
    if ( email_exists( $data['email'] ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ese email ya está registrado. Inicia sesión.', 'workshop' ) ) );
    }
    if ( '' === $data['username'] ) {
        wp_send_json_error( array( 'msg' => __( 'El usuario es obligatorio.', 'workshop' ) ) );
    }
    if ( username_exists( $data['username'] ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ese nombre de usuario ya existe.', 'workshop' ) ) );
    }
    if ( strlen( $data['password'] ) < 8 ) {
        wp_send_json_error( array( 'msg' => __( 'La contraseña debe tener al menos 8 caracteres.', 'workshop' ) ) );
    }

    $result = ws_send_verification_code( $data['email'], $data );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    wp_send_json_success( array(
        'msg'      => sprintf( __( 'Te enviamos un código de 6 dígitos a %s', 'workshop' ), $data['email'] ),
        'email'    => $data['email'],
        'expires'  => 15 * MINUTE_IN_SECONDS,
    ) );
}

/* -------------------------------------------------------------------------
 * Reenviar código
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_nopriv_ws_register_resend', 'ws_ajax_register_resend' );
add_action( 'wp_ajax_ws_register_resend', 'ws_ajax_register_resend' );
function ws_ajax_register_resend() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada. Recarga la página.', 'workshop' ) ) );
    }
    $email = sanitize_email( $_POST['email'] ?? '' );
    if ( ! is_email( $email ) ) {
        wp_send_json_error( array( 'msg' => __( 'Email inválido.', 'workshop' ) ) );
    }
    $result = ws_resend_verification_code( $email );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    wp_send_json_success( array( 'msg' => __( 'Te reenviamos un código nuevo.', 'workshop' ) ) );
}

/* -------------------------------------------------------------------------
 * Paso 2: verificar código y crear el negocio
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_nopriv_ws_register_verify', 'ws_ajax_register_verify' );
add_action( 'wp_ajax_ws_register_verify', 'ws_ajax_register_verify' );
function ws_ajax_register_verify() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada. Recarga la página.', 'workshop' ) ) );
    }
    if ( is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Ya tienes una sesión iniciada.', 'workshop' ) ) );
    }
    $email = sanitize_email( $_POST['email'] ?? '' );
    $code  = preg_replace( '/[^0-9]/', '', (string) ( $_POST['code'] ?? '' ) );
    if ( ! is_email( $email ) || '' === $code ) {
        wp_send_json_error( array( 'msg' => __( 'Datos incompletos.', 'workshop' ) ) );
    }

    $data = ws_verify_email_code( $email, $code );
    if ( is_wp_error( $data ) ) {
        wp_send_json_error( array( 'msg' => $data->get_error_message() ) );
    }
    // La contraseña del borrador se guardó cifrada: descifrar para crear el usuario.
    if ( ! empty( $data['password'] ) && function_exists( 'ws_crypt_text' ) ) {
        $data['password'] = ws_crypt_text( (string) $data['password'], true );
    }
    if ( empty( $data['password'] ) || strlen( (string) $data['password'] ) < 8 ) {
        wp_send_json_error( array( 'msg' => __( 'La sesión de registro expiró. Vuelve a empezar con tus datos.', 'workshop' ) ) );
    }

    // Validaciones de seguridad repetidas (el borrador pudo quedar obsoleto).
    if ( empty( $data['slug'] ) || WS_Business::slug_taken( $data['slug'] ) || email_exists( $email ) ) {
        wp_send_json_error( array( 'msg' => __( 'Los datos ya no son válidos (la dirección o el email cambiaron). Vuelve a empezar.', 'workshop' ) ) );
    }
    $username = sanitize_user( $data['username'] ?? '' );
    if ( username_exists( $username ) ) {
        $username = strtolower( sanitize_user( (string) ( $data['owner_name'] ?? 'negocio' ) ) ) . wp_rand( 10, 99 );
    }

    // 1) Crea el negocio (con acceso al marketplace por defecto).
    $biz_id = WS_Business::create( array(
        'name'                => $data['biz_name'],
        'slug'                => $data['slug'],
        'description'         => sprintf( __( 'Negocio de %s', 'workshop' ), $data['owner_name'] ),
        'active'              => true,
        'marketplace_enabled' => true,
    ) );
    if ( is_wp_error( $biz_id ) ) {
        wp_send_json_error( array( 'msg' => $biz_id->get_error_message() ) );
    }

    // 2) Crea el usuario dueño y lo asigna al negocio.
    $user_id = wp_insert_user( array(
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => $data['password'],
        'display_name' => sanitize_text_field( $data['owner_name'] ),
        'role'         => 'ws_owner',
        'user_registered' => current_time( 'mysql' ),
    ) );
    if ( is_wp_error( $user_id ) ) {
        WS_Business::delete( $biz_id );
        wp_send_json_error( array( 'msg' => $user_id->get_error_message() ) );
    }
    update_user_meta( $user_id, 'ws_business_id', $biz_id );

    // 3) Suscripción de prueba gratis (ws_trial_days() días por defecto).
    // ensure() crea la fila con estado trial y su fecha de caducidad (UTC).
    $biz = WS_Business::get( $biz_id );
    WS_Subscriptions::ensure( $biz );

    ws_log_audit( 'business_registered', 'business', $biz_id, array( 'source' => 'public' ) );

    // 4) Login automático.
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true );

    // 5) Primer uso: el panel abre la bienvenida con el tutorial paso a paso.
    update_user_meta( $user_id, 'ws_tutorial_pending', 1 );

    wp_send_json_success( array(
        'msg'      => __( '¡Tu negocio está listo!', 'workshop' ),
        'redirect' => ws_panel_url( 'owner', 'dashboard', $biz ),
    ) );
}
