<?php
/**
 * Setup del tema: soporte, assets y activación.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'ws_setup' );
function ws_setup() {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'custom-logo' );
    add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
    load_theme_textdomain( 'workshop', WS_PATH . 'languages' );
    register_nav_menus( array( 'primary' => __( 'Menú principal', 'workshop' ) ) );
    
    // Optimización de caché
    add_theme_support( 'automatic-feed-links' );
    
    // Deshabilitar emojis de WordPress para mejorar rendimiento
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    
    // Deshabilitar embeds de WordPress
    remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
    remove_action( 'wp_head', 'wp_oembed_add_host_js' );
    
    // Deshabilitar REST API para usuarios no autenticados
    add_filter( 'rest_authentication_errors', function( $result ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_not_logged_in', 'REST API solo disponible para usuarios autenticados', array( 'status' => 401 ) );
        }
        return $result;
    });
}

add_action( 'wp_enqueue_scripts', 'ws_enqueue_assets' );
function ws_enqueue_assets() {
    $fa = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css';
    wp_enqueue_style( 'ws-fontawesome', $fa, array(), '6.5.1' );

    $font = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap';
    wp_enqueue_style( 'ws-fonts', $font, array(), null );

    // SweetAlert2 local (no CDN): el bloqueo de rastreadores de algunos
    // navegadores (Tracking Prevention / Brave) bloquea cdn.jsdelivr.net y
    // hacía que wsConfirm cayera al confirm() nativo.
    wp_enqueue_style( 'ws-sweetalert', WS_URL . 'assets/css/vendor-sweetalert2.min.css', array(), '11.26.25' );
    wp_enqueue_script( 'ws-sweetalert', WS_URL . 'assets/js/vendor/sweetalert2.min.js', array(), '11.26.25', true );

    $fullcalendar_css = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css';
    wp_enqueue_style( 'ws-fullcalendar', $fullcalendar_css, array(), '6.1.10' );
    $fullcalendar = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js';
    wp_enqueue_script( 'ws-fullcalendar', $fullcalendar, array(), '6.1.10', true );

    wp_enqueue_style( 'ws-theme', WS_URL . 'assets/css/theme.css', array(), WS_VERSION );

    // Paleta del negocio (después de theme.css).
    if ( function_exists( 'ws_site_theme_inline_css' ) ) {
        wp_add_inline_style( 'ws-theme', ws_site_theme_inline_css() );
    }

    // theme.js debe cargarse ANTES que Alpine para registrar los componentes.
    wp_enqueue_script( 'ws-theme', WS_URL . 'assets/js/theme.js', array(), WS_VERSION, true );
    // Plugin Collapse de Alpine: la sección "¿Dónde está mi pedido?" de la
    // tienda usa x-collapse; debe cargarse antes de Alpine para registrarse.
    $alpine_collapse = WS_URL . 'assets/js/vendor/alpine-collapse.min.js';
    wp_enqueue_script( 'ws-alpine-collapse', $alpine_collapse, array( 'ws-theme' ), '3.14.1', true );
    $alpine = WS_URL . 'assets/js/vendor/alpine.min.js';
    wp_enqueue_script( 'ws-alpine', $alpine, array( 'ws-theme', 'ws-alpine-collapse' ), '3.14.1', true );

    wp_localize_script( 'ws-theme', 'WS',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => esc_url_raw( rest_url() ),
            'nonce'   => wp_create_nonce( 'ws_nonce' ),
            'userId'  => get_current_user_id(),
            'role'    => ws_user_role(),
            'home'    => ws_business_home(),
            'business' => ( function () {
                $b = ws_current_business();
                return $b ? $b->slug : '';
            } )(),
            'currency' => ws_currency_symbol(),
        )
    );
}



// La barra de WordPress solo se muestra al superadmin, no a los roles de negocio.
add_filter( 'show_admin_bar', 'ws_show_admin_bar' );
function ws_show_admin_bar( $show ) {
    if ( ! is_user_logged_in() ) {
        return $show;
    }
    $user = wp_get_current_user();
    if ( in_array( 'administrator', (array) $user->roles, true ) ) {
        return $show;
    }
    if ( ws_user_role() ) {
        return false;
    }
    return $show;
}

// Redirigir a usuarios no-admin lejos de wp-admin salvo AJAX/REST.
add_action( 'admin_init', 'ws_block_non_admin_admin' );
function ws_block_non_admin_admin() {
    $user = wp_get_current_user();
    if ( ! $user || ! $user->exists() ) {
        return;
    }
    if ( in_array( 'administrator', $user->roles, true ) ) {
        return;
    }
    if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }
    $allowed = array( 'admin-ajax.php', 'profile.php', 'admin-post.php' );
    $base    = basename( $_SERVER['SCRIPT_NAME'] ?? '' );
    if ( ! in_array( $base, $allowed, true ) ) {
        wp_safe_redirect( ws_dashboard_url() );
        exit;
    }
}

// Al activar el tema: crear tablas, roles y permalinks.
add_action( 'after_switch_theme', 'ws_activate' );
function ws_activate() {
    ws_db_install();
    ws_register_roles();
    ws_default_permissions();
    flush_rewrite_rules();
}

// Instalación diferida por si faltan tablas (p. ej. si se activó por DB o el
// tema se activó antes de que existieran ciertas tablas en el código).
// Segura bajo concurrencia: usa un transient como candado para que solo un
// request ejecute dbDelta (varios ALTER/CREATE simultáneos colgarían MySQL)
// y un marcador de versión para no repetir el trabajo en cada página.
add_action( 'init', 'ws_lazy_install' );
function ws_lazy_install() {
    global $wpdb;

    if ( ! defined( 'WS_DB_VERSION' ) || ! function_exists( 'ws_db_install' ) || ! function_exists( 'ws_db_tables' ) ) {
        return;
    }

    $db_version = (string) get_option( 'ws_db_version', '0.0.0' );

    // Ya instalado en esta versión: nada que hacer (barato, sin queries).
    if ( version_compare( $db_version, WS_DB_VERSION, '>=' ) ) {
        return;
    }
    // Otro request ya está instalando: salir y reintentar en la próxima carga.
    if ( get_transient( 'ws_db_install_lock' ) ) {
        return;
    }
    set_transient( 'ws_db_install_lock', 1, 120 );

    if ( version_compare( $db_version, '0.2.0', '<' ) ) {
        ws_db_install();
        ws_register_roles();
        ws_default_permissions();
        flush_rewrite_rules();
        delete_transient( 'ws_db_install_lock' );
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();
    $created = 0;
    foreach ( ws_db_tables() as $key => $sql ) {
        $table = $wpdb->prefix . WS_TABLE_PREFIX . $key;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            continue;
        }
        $sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
        $sql = str_replace( '{charset}', $charset_collate, $sql );
        dbDelta( $sql );
        $created++;
    }
    if ( $created ) {
        ws_register_roles();
        ws_default_permissions();
        // La nueva ruta /registro/ (y otras) solo funciona tras regenerar
        // los permalinks. Al añadir tablas se asume un cambio de versión.
        flush_rewrite_rules();
    }
    // Negocios que existían antes del sistema de suscripciones: plan
    // ilimitado (legacy) para no bloquearlos por sorpresa. Se ejecuta aunque
    // en este request no se crearan tablas (deploy parcial): la función es
    // idempotente (marcador ws_subs_migrated + verificación SHOW TABLES).
    if ( function_exists( 'ws_migrate_existing_subscriptions' ) ) {
        ws_migrate_existing_subscriptions();
    }
    // Marcar como instalado aunque no se creara nada: evita re-ejecutar el
    // barrido de SHOW TABLES en cada petición.
    update_option( 'ws_db_version', WS_DB_VERSION );
    delete_transient( 'ws_db_install_lock' );
}

