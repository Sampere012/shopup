<?php
/**
 * Tasas de cambio: monedas configuradas + scraper de la tasa informal de El Toque.
 *
 * La configuración vive en wp_options:
 *   - ws_currencies     -> string separada por comas, p. ej. "USD, CUP"
 *   - ws_currency       -> moneda por defecto (símbolo/código)
 *   - ws_rates          -> array [ moneda => unidades de moneda por defecto por 1 ],
 *                          p. ej. [ 'USD' => 670 ] = 1 USD = 670 CUP (default CUP).
 *   - ws_eltoque_url    -> URL de la que se scrapea la tasa (opcional).
 *   - ws_rates_updated  -> timestamp de la última actualización automática.
 *
 * El scraper lee la página pública de El Toque con varios patrones tolerantes
 * y guarda la tasa relativa a la moneda por defecto. También hay botón manual
 * en Ajustes y refresco diario por cron.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL de la que se scrapea la tasa (configurable; por defecto la portada).
 */
function ws_eltoque_url() {
    $url = get_option( 'ws_eltoque_url', '' );
    return $url ? $url : 'https://eltoque.com/';
}

/**
 * Obtiene el HTML de la página de El Toque ('' si falla).
 */
function ws_eltoque_html() {
    $resp = wp_remote_get( ws_eltoque_url(), array(
        'timeout'   => 20,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        'sslverify' => false,
    ) );
    if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
        return '';
    }
    return wp_remote_retrieve_body( $resp );
}

/**
 * Parsea \"1 USD = X CUP\" del HTML con patrones tolerantes.
 * Devuelve el número (float) o 0 si no lo encuentra.
 */
function ws_eltoque_parse_rate( $html ) {
    if ( '' === $html ) {
        return 0.0;
    }
    $patterns = array(
        '/1\s*USD\s*[=:]\s*([\d.,]+)\s*CUP/i',
        '/USD\s*[=:]\s*([\d.,]+)\s*CUP/i',
        '/"USD"\s*:\s*"?([\d.,]+)"?/i',
        '/([\d.,]+)\s*CUP\s*(?:por|per)\s*1?\s*USD/i',
        '/USD[^0-9]{0,50}([\d]{2,4}(?:[.,]\d+)?)\s*CUP/i',
    );
    foreach ( $patterns as $p ) {
        if ( preg_match( $p, $html, $m ) ) {
            $v = (float) str_replace( ',', '.', preg_replace( '/[^\d.,]/', '', $m[1] ) );
            if ( $v > 0 ) {
                return $v;
            }
        }
    }
    return 0.0;
}

/**
 * Actualiza la tasa desde El Toque: guarda ws_rates['USD'] (o 'CUP' si el
 * default es USD) relativo a la moneda por defecto.
 *
 * @return array|WP_Error Array con 'rate' y 'updated' o WP_Error.
 */
function ws_update_rate_from_eltoque() {
    $html = ws_eltoque_html();
    if ( '' === $html ) {
        return new WP_Error( 'eltoque', __( 'No se pudo conectar con El Toque.', 'workshop' ) );
    }
    $rate = ws_eltoque_parse_rate( $html );
    if ( $rate <= 0 ) {
        return new WP_Error( 'eltoque', __( 'No se encontró la tasa USD/CUP en la página de El Toque.', 'workshop' ) );
    }
    $base  = ws_currency_symbol();
    $rates = ws_exchange_rates();
    if ( 'USD' === $base ) {
        // La tasa de El Toque es 1 USD = X CUP; con default USD guardamos CUP = 1/X.
        $rates['CUP'] = round( 1 / $rate, 6 );
    } else {
        $rates['USD'] = round( $rate, 4 );
    }
    ws_save_biz_option( 'ws_rates', $rates );
    ws_save_biz_option( 'ws_rates_updated', current_time( 'mysql' ) );
    return array( 'rate' => $rates, 'updated' => ws_biz_option( 'ws_rates_updated' ) );
}

/**
 * Badge de texto para mostrar la tasa en la tienda: "1 USD = X CUP".
 * Solo usa las monedas USD y CUP (las de El Toque). '' si no hay datos.
 */
function ws_rate_badge() {
    $base  = ws_currency_symbol();
    $rates = ws_exchange_rates();
    if ( 'CUP' === $base && ! empty( $rates['USD'] ) ) {
        return sprintf( '1 USD = %s CUP', number_format_i18n( (float) $rates['USD'], 2 ) );
    }
    if ( 'USD' === $base && ! empty( $rates['CUP'] ) && (float) $rates['CUP'] > 0 ) {
        return sprintf( '1 USD = %s CUP', number_format_i18n( 1 / (float) $rates['CUP'], 2 ) );
    }
    return '';
}

/**
 * Endpoint AJAX: actualizar la tasa desde El Toque (solo dueño/config).
 */
add_action( 'wp_ajax_ws_update_rate', 'ws_ajax_update_rate' );
function ws_ajax_update_rate() {
    ws_guard( 'settings_manage' );
    $result = ws_update_rate_from_eltoque();
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( 'rates_update', 'settings', 0, array( 'rate' => $result['rate'] ) );
    wp_send_json_success( $result );
}

// Refresco automático diario de la tasa.
add_action( 'init', 'ws_rates_schedule' );
function ws_rates_schedule() {
    if ( ! wp_next_scheduled( 'ws_rates_daily' ) ) {
        wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ws_rates_daily' );
    }
}

add_action( 'ws_rates_daily', 'ws_cron_update_rate' );
function ws_cron_update_rate() {
    $result = ws_update_rate_from_eltoque();
    if ( is_wp_error( $result ) ) {
        error_log( 'ws_rates: ' . $result->get_error_message() );
    }
}
