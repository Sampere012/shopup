<?php
/**
 * ws-e2e-mail-capture.php — SOLO entorno local de tests E2E.
 *
 * Cuando la opción ws_e2e_capture está activa (la activa ws-e2e-helper.php
 * capture on), intercepta el envío real de correos (no depende de SMTP/Gmail
 * en los tests), extrae el código de verificación de 6 dígitos del HTML y lo
 * guarda en la opción ws_e2e_codes (email => código) para que los tests de
 * Playwright lo lean a través del helper CLI.
 *
 * En producción (WP_ENVIRONMENT_TYPE != local o captura apagada) no hace nada.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', 'ws_e2e_capture_mail', 10, 2 );
function ws_e2e_capture_mail( $null, $atts ) {
    if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) || 'local' !== WP_ENVIRONMENT_TYPE ) {
        return $null;
    }
    if ( ! get_option( 'ws_e2e_capture', 0 ) ) {
        return $null;
    }
    $to  = isset( $atts['to'] ) ? ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'] ) : '';
    $msg = isset( $atts['message'] ) ? (string) $atts['message'] : '';
    // El código se imprime dentro del div .code-box (ver ws_email_template).
    if ( preg_match( '/class="code-box">\s*(\d{6})\s*</', $msg, $m ) ) {
        $codes = (array) get_option( 'ws_e2e_codes', array() );
        $codes[ trim( $to ) ] = $m[1];
        update_option( 'ws_e2e_codes', $codes, false );
    }
    // Aborta el envío real: wp_mail() devuelve true (éxito) sin salir por red.
    return true;
}
