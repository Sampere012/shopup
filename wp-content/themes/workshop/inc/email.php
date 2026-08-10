<?php
/**
 * Correo: configuración SMTP (wp-admin) + códigos de verificación de 6 dígitos.
 *
 * El administrador configura el servidor SMTP (host, puerto, TLS, usuario,
 * contraseña y remitente) desde wp-admin → ShopUp → Correo SMTP. Por defecto
 * trae una configuración de Gmail editable. wp_mail() usa esas credenciales.
 *
 * La verificación de email (registro de negocios) envía un código de 6 dígitos
 * con caducidad de 15 minutos y validación por intentos.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configuración SMTP actual (opción editable desde wp-admin).
 */
function ws_smtp_settings() {
    $defaults = array(
        'enabled'    => 1,
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'use_tls'    => 1,
        'user'       => 'chaconyadixa123@gmail.com',
        'password'   => 'ubbbzklipvgtxjvs',
        'from_email' => 'chaconyadixa123@gmail.com',
        'from_name'  => '',
    );
    $saved = get_option( 'ws_smtp_settings', array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Aplica SMTP a PHPMailer (wp_mail).
 */
add_action( 'phpmailer_init', 'ws_smtp_configure' );
function ws_smtp_configure( $phpmailer ) {
    $s = ws_smtp_settings();
    if ( empty( $s['enabled'] ) || empty( $s['host'] ) || empty( $s['user'] ) ) {
        return;
    }
    $phpmailer->isSMTP();
    $phpmailer->Host       = $s['host'];
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = (int) $s['port'];
    $phpmailer->Username   = $s['user'];
    $phpmailer->Password   = $s['password'];
    $phpmailer->SMTPSecure = ! empty( $s['use_tls'] ) ? 'tls' : '';
    if ( ! empty( $s['from_email'] ) && is_email( $s['from_email'] ) ) {
        $phpmailer->From     = $s['from_email'];
        $phpmailer->FromName = ! empty( $s['from_name'] ) ? $s['from_name'] : wp_specialchars_decode( get_bloginfo( 'name' ) );
    }
}

// Remitente coherente incluso sin phpmailer_init (p. ej. correos de WP core).
add_filter( 'wp_mail_from', 'ws_smtp_from_email' );
function ws_smtp_from_email( $from ) {
    $s = ws_smtp_settings();
    return ! empty( $s['enabled'] ) && ! empty( $s['from_email'] ) ? $s['from_email'] : $from;
}

add_filter( 'wp_mail_from_name', 'ws_smtp_from_name' );
function ws_smtp_from_name( $name ) {
    $s = ws_smtp_settings();
    if ( ! empty( $s['enabled'] ) && ! empty( $s['from_name'] ) ) {
        return $s['from_name'];
    }
    return $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ) );
}

/**
 * Envía un correo HTML. Devuelve bool (resultado de wp_mail).
 */
function ws_send_mail( $to, $subject, $html ) {
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    $body    = ws_email_template( $html );
    return (bool) wp_mail( $to, $subject, $body, $headers );
}

/**
 * Plantilla HTML base para los correos del sitio.
 */
function ws_email_template( $content ) {
    $brand = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ) ) );
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin:0; padding:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; color:#0f172a; }
        .wrap { max-width:560px; margin:32px auto; background:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #e2e8f0; }
        .head { background:linear-gradient(135deg,#4f46e5,#7c3aed); padding:28px 32px; text-align:center; }
        .head h1 { margin:0; color:#fff; font-size:20px; }
        .body { padding:28px 32px; font-size:15px; line-height:1.6; }
        .code-box { margin:24px auto; padding:16px; background:#eef2ff; border:1px dashed #818cf8; border-radius:12px; text-align:center; font-size:34px; font-weight:800; letter-spacing:10px; color:#4338ca; }
        .muted { color:#64748b; font-size:13px; }
        .btn { display:inline-block; margin-top:18px; padding:12px 24px; background:#4f46e5; color:#fff !important; text-decoration:none; border-radius:10px; font-weight:600; }
        .foot { padding:18px 32px; background:#f8fafc; text-align:center; font-size:12px; color:#94a3b8; }
    </style></head>
    <body><div class="wrap">
        <div class="head"><h1>' . $brand . '</h1></div>
        <div class="body">' . $content . '</div>
        <div class="foot">' . esc_html( sprintf( __( 'Este es un correo automático de %s.', 'workshop' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) ) ) . '</div>
    </div></body></html>';
}

/* -------------------------------------------------------------------------
 * Códigos de verificación de 6 dígitos
 * ---------------------------------------------------------------------- */

/** Clave del transient que guarda un código de verificación. */
function ws_verify_key( $email ) {
    return 'ws_verify_' . md5( strtolower( trim( $email ) ) );
}

/**
 * Cifra/descifra texto corto (p. ej. la contraseña del borrador de registro)
 * para no guardarla en claro en la tabla de opciones (transient). Usa AES
 * si openssl está disponible y, si no, un XOR contra el salt del sitio.
 */
function ws_crypt_text( $text, $decrypt = false ) {
    $key = (string) wp_salt( 'nonce' );
    if ( '' === $key ) {
        $key = (string) wp_salt();
    }
    if ( ! $decrypt && function_exists( 'openssl_encrypt' ) ) {
        $iv = substr( $key, 0, 16 );
        return 'O1:' . openssl_encrypt( (string) $text, 'AES-128-CBC', $key, 0, $iv );
    }
    if ( $decrypt && function_exists( 'openssl_decrypt' ) && 0 === strpos( (string) $text, 'O1:' ) ) {
        $iv  = substr( $key, 0, 16 );
        $raw = openssl_decrypt( substr( (string) $text, 3 ), 'AES-128-CBC', $key, 0, $iv );
        return false !== $raw ? $raw : '';
    }
    if ( ! $decrypt ) {
        $out = '';
        $len = strlen( $key );
        for ( $i = 0; $i < strlen( (string) $text ); $i++ ) {
            $out .= $text[ $i ] ^ $key[ $i % $len ];
        }
        return 'X1:' . base64_encode( $out );
    }
    $bin = base64_decode( substr( (string) $text, 3 ), true );
    $bin = is_string( $bin ) ? $bin : '';
    $out = '';
    $len = strlen( $key );
    for ( $i = 0; $i < strlen( $bin ); $i++ ) {
        $out .= $bin[ $i ] ^ $key[ $i % $len ];
    }
    return $out;
}

/**
 * Genera y envía un código de verificación de 6 dígitos a un email.
 * Guarda el código (hash), la fecha de caducidad (15 min), los intentos y los
 * datos asociados (p. ej. el borrador del registro del negocio).
 * Devuelve true si se envió, WP_Error si falla o hay rate-limit.
 */
function ws_send_verification_code( $email, $data = array() ) {
    $email = sanitize_email( $email );
    if ( ! is_email( $email ) ) {
        return new WP_Error( 'email', __( 'Email inválido.', 'workshop' ) );
    }
    $key   = ws_verify_key( $email );
    $stored = get_transient( $key );
    // Rate limit: máx. 3 envíos en 10 minutos.
    if ( is_array( $stored ) && ! empty( $stored['sent_at'] ) ) {
        $sends = (int) ( $stored['sent_count'] ?? 0 );
        if ( $sends >= 3 && time() - (int) $stored['sent_at'] < 10 * MINUTE_IN_SECONDS ) {
            return new WP_Error( 'rate', __( 'Enviaste muchos códigos. Espera unos minutos y vuelve a intentarlo.', 'workshop' ) );
        }
    }
    $code = wp_rand( 100000, 999999 );
    // No guardar la contraseña del borrador en claro: se cifra.
    if ( ! empty( $data['password'] ) && function_exists( 'ws_crypt_text' ) ) {
        $data['password'] = ws_crypt_text( (string) $data['password'] );
    }
    $payload = array(
        'code'       => wp_hash( (string) $code . $email ),
        'email'      => $email,
        'expires'    => time() + 15 * MINUTE_IN_SECONDS,
        'tries'      => 0,
        'sent_at'    => time(),
        'sent_count' => ( is_array( $stored ) ? (int) ( $stored['sent_count'] ?? 0 ) : 0 ) + 1,
        'data'       => $data,
    );
    set_transient( $key, $payload, 30 * MINUTE_IN_SECONDS );

    $subject = sprintf( __( 'Tu código de verificación · %s', 'workshop' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );
    $content = '<p>' . esc_html__( 'Hola, usamos este código para verificar tu correo:', 'workshop' ) . '</p>'
        . '<div class="code-box">' . esc_html( (string) $code ) . '</div>'
        . '<p class="muted">' . esc_html__( 'El código caduca en 15 minutos. Si no lo solicitaste, ignora este correo.', 'workshop' ) . '</p>';
    $sent = ws_send_mail( $email, $subject, $content );
    if ( ! $sent ) {
        delete_transient( $key );
        return new WP_Error( 'send', __( 'No se pudo enviar el correo. Revisa la configuración SMTP o intenta de nuevo.', 'workshop' ) );
    }
    return true;
}

/**
 * Reenvía el código existente (sin generar otro) si aún no caducó.
 */
function ws_resend_verification_code( $email ) {
    $stored = get_transient( ws_verify_key( $email ) );
    if ( ! is_array( $stored ) || empty( $stored['code'] ) || (int) $stored['expires'] < time() ) {
        return ws_send_verification_code( $email );
    }
    // Genera uno nuevo (el correo anterior pudo perderse).
    return ws_send_verification_code( $email, (array) ( $stored['data'] ?? array() ) );
}

/**
 * Verifica el código enviado por el usuario y devuelve los datos asociados,
 * o un WP_Error. Máximo 5 intentos antes de caducar.
 */
function ws_verify_email_code( $email, $code ) {
    $email  = sanitize_email( $email );
    $stored = get_transient( ws_verify_key( $email ) );
    if ( ! is_array( $stored ) || empty( $stored['code'] ) ) {
        return new WP_Error( 'expired', __( 'El código expiró o no existe. Vuelve a solicitarlo.', 'workshop' ) );
    }
    if ( (int) $stored['expires'] < time() ) {
        delete_transient( ws_verify_key( $email ) );
        return new WP_Error( 'expired', __( 'El código expiró. Vuelve a solicitarlo.', 'workshop' ) );
    }
    $tries = (int) ( $stored['tries'] ?? 0 ) + 1;
    $hash  = wp_hash( (string) $code . $email );
    if ( ! hash_equals( $stored['code'], $hash ) ) {
        if ( $tries >= 5 ) {
            delete_transient( ws_verify_key( $email ) );
            return new WP_Error( 'tries', __( 'Demasiados intentos fallidos. Solicita un código nuevo.', 'workshop' ) );
        }
        $stored['tries'] = $tries;
        set_transient( ws_verify_key( $email ), $stored, 30 * MINUTE_IN_SECONDS );
        return new WP_Error( 'code', sprintf( __( 'Código incorrecto. Te quedan %d intentos.', 'workshop' ), 5 - $tries ) );
    }
    $data = (array) ( $stored['data'] ?? array() );
    delete_transient( ws_verify_key( $email ) );
    return $data;
}
