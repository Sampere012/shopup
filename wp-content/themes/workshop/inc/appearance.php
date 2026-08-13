<?php
/**
 * Apariencia del sitio (panel del Dueño).
 *
 * Opciones de marca del negocio: nombre, logo, favicon, paleta de colores,
 * textos de portada (hero: texto, imagen de fondo y gradiente) y pie de
 * página. Todo se aplica en el front-end (header, footer, login, landing y
 * panel) y tiene vista previa en tiempo real desde el panel de Apariencia.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Opciones actuales de apariencia del sitio.
 *
 * En el índice del mercado (raíz del sitio) se usa la configuración del
 * administrador (ws_marketplace_theme); en el resto, la del negocio actual.
 * Así la apariencia que configura cada dueño aplica solo a su negocio.
 */
function ws_site_theme() {
    if ( ws_is_marketplace() ) {
        return ws_marketplace_theme();
    }
    $defaults = array(
        'name'        => '',
        'logo'        => '',
        'favicon'     => '',
        'primary'     => '#4f46e5',
        'accent'      => '#f59e0b',
        'hero_badge'    => '',
        'hero_title'    => '',
        'hero_sub'      => '',
        'hero_bg'       => '',
        'hero_gradient' => '',
        'footer_text'   => '',
    );
    $saved = ws_biz_option( 'ws_site_theme', array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Nombre del negocio (personalizado o el de WordPress).
 */
function ws_site_name() {
    $t = ws_site_theme();
    return '' !== $t['name'] ? $t['name'] : get_option( 'blogname' );
}

/**
 * URL del logo del negocio ('' si no hay).
 */
function ws_site_logo() {
    $t = ws_site_theme();
    return $t['logo'] ?? '';
}

/**
 * src del <img> de marca: logo real o un GIF transparente de 1px.
 * esc_url() elimina los URI data:, por eso la constante se emite tal cual.
 */
function ws_site_logo_src() {
    $logo = ws_site_logo();
    $src  = $logo ? esc_url( $logo ) : '';
    return $src ? $src : 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
}

/**
 * Texto descriptivo del pie de página.
 */
function ws_site_footer_text() {
    $t = ws_site_theme();
    if ( '' !== ( $t['footer_text'] ?? '' ) ) {
        return $t['footer_text'];
    }
    return __( 'Multi-tienda conectada: pedidos, stock en tiempo real y control para tu negocio.', 'workshop' );
}

/**
 * Texto de la portada (badge/título/subtítulo) con fallback traducido.
 */
function ws_site_hero( $key ) {
    $defaults = array(
        'hero_badge' => __( 'Pedidos y stock en tiempo real', 'workshop' ),
        'hero_title' => __( 'Tu negocio, multi-tienda', 'workshop' ),
        'hero_sub'   => __( 'Elige tu punto de venta para ver productos y realizar pedidos con entrega a domicilio.', 'workshop' ),
    );
    $t = ws_site_theme();
    return '' !== ( $t[ $key ] ?? '' ) ? $t[ $key ] : $defaults[ $key ];
}

/**
 * Fondo del hero del landing: imagen (URL) o gradiente CSS.
 * Devuelve 'has-bg' si hay imagen para aplicar el overlay oscuro.
 */
function ws_site_hero_has_bg() {
    $t = ws_site_theme();
    return ! empty( $t['hero_bg'] );
}

/**
 * Declaración CSS (inline) del fondo del hero del landing.
 * Prioridad: imagen (URL) > gradiente CSS > degradado por defecto.
 * Devuelve el valor SIN escapar: el template lo escapa una sola vez con
 * esc_attr() en el atributo style (los datos ya se sanitizan al guardar:
 * esc_url_raw para la imagen y wp_strip_all_tags para el gradiente).
 */
function ws_site_hero_bg_style() {
    $t = ws_site_theme();
    if ( ! empty( $t['hero_bg'] ) ) {
        // background-attachment:fixed da el efecto parallax (la imagen se
        // queda fija al hacer scroll). En móvil se desactiva por CSS (bug de
        // iOS con fondos fijos al hacer zoom/scroll).
        return "background-image:url('" . ws_image_url( $t['hero_bg'] ) . "');background-size:cover;background-position:center;background-attachment:fixed;";
    }
    if ( ! empty( $t['hero_gradient'] ) ) {
        return 'background:' . $t['hero_gradient'] . ';';
    }
    return '';
}

/**
 * Ajusta el brillo de un color hex (+ claro / - oscuro).
 */
function ws_hex_shade( $hex, $pct ) {
    $hex = ltrim( (string) $hex, '#' );
    if ( '' === $hex ) {
        return '#4f46e5';
    }
    if ( 3 === strlen( $hex ) ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if ( ! ctype_xdigit( $hex ) || 6 !== strlen( $hex ) ) {
        return '#' . $hex;
    }
    $out = '#';
    for ( $i = 0; $i < 3; $i++ ) {
        $c = max( 0, min( 255, hexdec( substr( $hex, $i * 2, 2 ) ) + $pct ) );
        $out .= str_pad( dechex( $c ), 2, '0', STR_PAD_LEFT );
    }
    return $out;
}

/**
 * Sugerencia de paleta a partir de un logo: toma el color dominante y un
 * acento complementario para que la tienda se vea diferenciada sin que el
 * usuario tenga que elegir manualmente cada tono.
 */
function ws_site_theme_palette_from_logo( $logo_url ) {
    $logo_url = trim( (string) $logo_url );
    if ( '' === $logo_url ) {
        return array( 'primary' => '#4f46e5', 'accent' => '#f59e0b' );
    }

    // El cálculo real de los colores se hace en el cliente con canvas; aquí se
    // deja un fallback muy conservador para el caso de no poder analizar la
    // imagen del servidor.
    return array(
        'primary' => '#4f46e5',
        'accent'  => '#f59e0b',
    );
}

// El nombre del negocio editable reemplaza el de WordPress en todo el sitio.
add_filter( 'pre_option_blogname', 'ws_site_blogname' );
function ws_site_blogname( $value ) {
    $t = ws_site_theme();
    return '' !== ( $t['name'] ?? '' ) ? $t['name'] : $value;
}

// Favicon en el <head>: solo en el panel y en la tienda del negocio (no en el
// mercado, ni en la portada/landing, ni en login/registro).
add_action( 'wp_head', 'ws_site_theme_head', 5 );
function ws_site_theme_head() {
    if ( ! ws_show_favicon() ) {
        return;
    }
    $t = ws_site_theme();
    if ( ! empty( $t['favicon'] ) ) {
        echo "\n" . '<link rel="icon" href="' . esc_url( $t['favicon'] ) . '">' . "\n";
    }
}

/**
 * ¿La petición actual es un contexto de negocio que muestra el favicon?
 * Panel (ws_role) y tienda pública (ws_public store|order) sí; el resto no.
 */
function ws_show_favicon() {
    if ( ws_is_marketplace() ) {
        return false;
    }
    if ( '' !== (string) get_query_var( 'ws_role' ) ) {
        return true;
    }
    if ( in_array( (string) get_query_var( 'ws_public' ), array( 'store', 'order' ), true ) ) {
        return true;
    }
    return false;
}

// CSS de las variables de color.
// Se imprime DESPUÉS de theme.css (wp_add_inline_style) para que la
// paleta del negocio gane especificidad sobre los valores por defecto.
function ws_site_theme_inline_css() {
    $t    = ws_site_theme();
    $css  = ':root{';
    $css .= '--ws-primary:' . esc_html( $t['primary'] ) . ';';
    $css .= '--ws-primary-dark:' . esc_html( ws_hex_shade( $t['primary'], -10 ) ) . ';';
    $css .= '--ws-primary-deep:' . esc_html( ws_hex_shade( $t['primary'], -22 ) ) . ';';
    $css .= '--ws-primary-light:' . esc_html( ws_hex_shade( $t['primary'], 26 ) ) . ';';
    $css .= '--ws-accent:' . esc_html( $t['accent'] ) . ';';
    $css  .= '}';
    return $css;
}

/**
 * Guarda la apariencia del sitio.
 * site_manage controla identidad/colores/CSS; layout_manage la portada y el pie.
 */
add_action( 'wp_ajax_ws_save_site_theme', 'ws_ajax_save_site_theme' );
function ws_ajax_save_site_theme() {
    ws_guard( 'site_manage', 'layout_manage' );
    $cur       = ws_site_theme();
    $can_site  = ws_can( 'site_manage' );

    if ( $can_site ) {
        $primary = sanitize_hex_color( (string) ( $_POST['primary'] ?? '' ) );
        $accent  = sanitize_hex_color( (string) ( $_POST['accent'] ?? '' ) );
        // Usa isset() para no borrar valores que el guardado parcial no envía.
        if ( isset( $_POST['name'] ) ) {
            $cur['name'] = sanitize_text_field( $_POST['name'] );
        }
        if ( isset( $_POST['logo'] ) ) {
            $cur['logo'] = esc_url_raw( (string) $_POST['logo'] );
        }
        if ( isset( $_POST['favicon'] ) ) {
            $cur['favicon'] = esc_url_raw( (string) $_POST['favicon'] );
        }
        $cur['primary']    = $primary ? $primary : $cur['primary'];
        $cur['accent']     = $accent ? $accent : $cur['accent'];
    }

    // Portada y pie: disponibles con cualquiera de las dos capacidades.
    if ( $can_site || ws_can( 'layout_manage' ) ) {
        foreach ( array( 'hero_badge', 'hero_title', 'hero_sub', 'footer_text' ) as $k ) {
            if ( isset( $_POST[ $k ] ) ) {
                $cur[ $k ] = sanitize_text_field( $_POST[ $k ] );
            }
        }
        // Fondo del hero: imagen (URL) o gradiente CSS.
        if ( isset( $_POST['hero_bg'] ) ) {
            $cur['hero_bg'] = esc_url_raw( (string) $_POST['hero_bg'] );
        }
        if ( isset( $_POST['hero_gradient'] ) ) {
            $cur['hero_gradient'] = wp_strip_all_tags( (string) $_POST['hero_gradient'] );
        }
    }

    ws_save_biz_option( 'ws_site_theme', $cur );
    ws_log_audit( 'site_theme_update', 'settings', 0 );
    wp_send_json_success();
}
