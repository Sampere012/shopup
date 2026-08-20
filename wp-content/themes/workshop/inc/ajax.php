<?php
/**
 * Endpoints AJAX del tema.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/* ---------------- Autenticación de la app móvil (token) ---------------- */

/**
 * CORS para la app móvil: las peticiones con token (header X-WS-Token) pueden
 * venir del origen file:// de Cordova o de cualquier origen de desarrollo. El
 * token es una credencial explícita (como una API key), así que el origen
 * puede ser * sin exponer cookies. Las peticiones sin token no se ven
 * afectadas (misma política de siempre).
 */
add_action( 'init', function () {
    $action    = (string) ( $_POST['action'] ?? '' );
    $has_token = isset( $_SERVER['HTTP_X_WS_TOKEN'] ) || ( isset( $_POST['ws_token'] ) && '' !== (string) $_POST['ws_token'] );
    // Endpoints móviles sin token previo (login) o petición CORS (preflight).
    $mobile_ep = in_array( $action, array( 'ws_mobile_login', 'ws_mobile_me', 'ws_mobile_logout', 'ws_mobile_state' ), true );
    $preflight = 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && '' !== ( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '' );
    if ( $has_token || $mobile_ep || $preflight ) {
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Headers: Content-Type, X-WS-Token' );
        header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
        if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            status_header( 204 );
            exit;
        }
    }
} );

/**
 * Crea un token de sesión móvil para el usuario, con la misma duración que
 * la sesión web configurada (ws_session_expiration_days; 30 por defecto).
 * Se guarda hasheado (wp_hash) para que la BD no contenga tokens en claro.
 */
function ws_mobile_token_create( $user_id ) {
    $token = wp_generate_password( 40, false, false );
    $days  = max( 1, min( 365, (int) get_option( 'ws_session_expiration_days', 30 ) ) );
    update_user_meta( (int) $user_id, 'ws_mobile_token', wp_hash( $token ) );
    update_user_meta( (int) $user_id, 'ws_mobile_token_expires', time() + $days * DAY_IN_SECONDS );
    return array( 'token' => $token, 'expiresAt' => time() + $days * DAY_IN_SECONDS, 'sessionDays' => $days );
}

/**
 * Valida el token de la app móvil (header X-WS-Token o campo ws_token) y
 * devuelve el user_id (0 si no hay token o es inválido/vencido). Sin efectos
 * secundarios: no cambia el usuario actual (eso lo hace ws_mobile_auth_user
 * y el filtro determine_current_user). Estático: valida una vez por petición.
 */
function ws_mobile_token_user() {
    static $ws_mt_cached = false;
    static $ws_mt_uid    = 0;
    if ( false !== $ws_mt_cached ) {
        return $ws_mt_uid;
    }
    $ws_mt_cached = true;
    $token = (string) ( $_SERVER['HTTP_X_WS_TOKEN'] ?? ( $_POST['ws_token'] ?? '' ) );
    if ( '' === $token ) {
        return 0;
    }
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='ws_mobile_token' AND meta_value=%s",
        wp_hash( $token )
    ) );
    if ( ! $row ) {
        return 0;
    }
    $expires = (int) get_user_meta( (int) $row->user_id, 'ws_mobile_token_expires', true );
    if ( $expires && $expires < time() ) {
        delete_user_meta( (int) $row->user_id, 'ws_mobile_token' );
        delete_user_meta( (int) $row->user_id, 'ws_mobile_token_expires' );
        return 0;
    }
    $ws_mt_uid = (int) $row->user_id;
    return $ws_mt_uid;
}

/**
 * Autentica la petición con el token (equivale a la sesión web): además de
 * validar, pone al usuario como usuario actual para las comprobaciones de
 * permisos que corren después (ws_can, roles, etc.).
 */
function ws_mobile_auth_user() {
    $uid = ws_mobile_token_user();
    if ( $uid ) {
        wp_set_current_user( $uid );
    }
    return $uid;
}

/**
 * El token llega por header y WordPress decide el hook AJAX (wp_ajax_* vs
 * wp_ajax_nopriv_*) según is_user_logged_in() ANTES de ejecutar el handler.
 * admin_init corre en admin-ajax.php justo antes del dispatch, así que aquí
 * establecemos el usuario del token y is_user_logged_in() dispara wp_ajax_*.
 */
add_action( 'admin_init', function () {
    if ( empty( $_SERVER['HTTP_X_WS_TOKEN'] ) && empty( $_POST['ws_token'] ) ) {
        return;
    }
    ws_mobile_auth_user();
} );

/**
 * Capacidades que la app móvil realmente usa: la app solo gestiona los
 * módulos/acciones de esta lista. La web tiene módulos avanzados que la app
 * no implementa (proveedores, categorías en árbol, fraccionamiento, etc.);
 * esa parte se sigue gestionando y aplicando solo en la web.
 */
function ws_app_caps() {
    return array(
        'products_view', 'products_create', 'products_edit',
        'locations_view', 'locations_manage',
        'stock_view', 'stock_entry', 'stock_exit', 'stock_writeoff', 'stock_transfer',
        'stock_count_view',
        'movements_view',
        'pos_sell', 'pos_view',
        'orders_view', 'orders_accept',
        'shifts_view', 'shifts_manage',
        'workers_view', 'workers_manage',
        'customers_view', 'customers_create', 'customers_edit',
        'reviews_view', 'reviews_moderate',
        'loyalty_manage', 'expenses_manage',
        'settings_manage', 'permissions_manage', 'reports_view',
        'site_manage', 'layout_manage',
    );
}

/**
 * Payload de sesión para la app móvil: identidad, rol, permisos y el menú
 * del panel filtrado por capacidades (mismos ítems que templates/panel.php).
 */
function ws_mobile_me_payload() {
    $user_id = get_current_user_id();
    $role    = ws_user_role( $user_id );
    $items   = array(
        'dashboard' => array( 'icon' => 'fa-gauge-high', 'label' => __( 'Dashboard', 'workshop' ), 'caps' => array() ),
        'products'  => array( 'icon' => 'fa-boxes-stacked', 'label' => __( 'Productos', 'workshop' ), 'caps' => array( 'products_view' ) ),
        'locations' => array( 'icon' => 'fa-location-dot', 'label' => __( 'Ubicaciones', 'workshop' ), 'caps' => array( 'locations_view' ) ),
        'stock'     => array( 'icon' => 'fa-warehouse', 'label' => __( 'Stock', 'workshop' ), 'caps' => array( 'stock_view' ) ),
        'counts'    => array( 'icon' => 'fa-list-check', 'label' => __( 'Cuadre', 'workshop' ), 'caps' => array( 'stock_count_view' ) ),
        'movements' => array( 'icon' => 'fa-clock-rotate-left', 'label' => __( 'Historial', 'workshop' ), 'caps' => array( 'movements_view' ) ),
        'orders'    => array( 'icon' => 'fa-receipt', 'label' => __( 'Pedidos', 'workshop' ), 'caps' => array( 'orders_view' ) ),
        'shifts'    => array( 'icon' => 'fa-calendar-days', 'label' => __( 'Turnos', 'workshop' ), 'caps' => array( 'shifts_view' ) ),
        'workers'   => array( 'icon' => 'fa-user-gear', 'label' => __( 'Trabajadores', 'workshop' ), 'caps' => array( 'workers_manage' ) ),
        'customers' => array( 'icon' => 'fa-users', 'label' => __( 'Clientes', 'workshop' ), 'caps' => array( 'customers_view' ) ),
        'pos'       => array( 'icon' => 'fa-cash-register', 'label' => __( 'POS', 'workshop' ), 'caps' => array( 'pos_sell' ) ),
        'pos-sales' => array( 'icon' => 'fa-chart-line', 'label' => __( 'Ventas POS', 'workshop' ), 'caps' => array( 'pos_view' ) ),
        'reviews'   => array( 'icon' => 'fa-star', 'label' => __( 'Valoraciones', 'workshop' ), 'caps' => array( 'reviews_view' ) ),
        'loyalty'   => array( 'icon' => 'fa-gift', 'label' => __( 'Fidelización', 'workshop' ), 'caps' => array( 'loyalty_manage' ) ),
        'expenses'  => array( 'icon' => 'fa-money-bill-wave', 'label' => __( 'Gastos', 'workshop' ), 'caps' => array( 'expenses_manage' ) ),
        'anuncios'  => array( 'icon' => 'fa-bullhorn', 'label' => __( 'Anuncios', 'workshop' ), 'caps' => array( 'settings_manage', 'workers_manage' ) ),
        'plan'      => array( 'icon' => 'fa-crown', 'label' => __( 'Plan', 'workshop' ), 'caps' => array() ),
        'permissions' => array( 'icon' => 'fa-shield-halved', 'label' => __( 'Permisos', 'workshop' ), 'caps' => array( 'permissions_manage' ) ),
        'reports'   => array( 'icon' => 'fa-chart-pie', 'label' => __( 'Reportes', 'workshop' ), 'caps' => array( 'reports_view' ) ),
        'appearance' => array( 'icon' => 'fa-palette', 'label' => __( 'Apariencia', 'workshop' ), 'caps' => array( 'site_manage', 'layout_manage' ) ),
        'settings'  => array( 'icon' => 'fa-gear', 'label' => __( 'Configuración', 'workshop' ), 'caps' => array( 'settings_manage' ) ),
        'account'   => array( 'icon' => 'fa-user', 'label' => __( 'Mi cuenta', 'workshop' ), 'caps' => array() ),
    );
    $menu = array();
    foreach ( $items as $key => $it ) {
        $visible = true;
        if ( ! empty( $it['caps'] ) ) {
            $visible = false;
            foreach ( $it['caps'] as $cap ) {
                if ( ws_can( $cap ) ) {
                    $visible = true;
                    break;
                }
            }
        }
        if ( $visible ) {
            $menu[] = array( 'key' => $key, 'label' => $it['label'], 'icon' => $it['icon'] );
        }
    }
    $caps = array();
    if ( class_exists( 'WS_Capabilities' ) ) {
        $all = WS_Capabilities::all_caps();
        foreach ( ws_app_caps() as $cap ) {
            $caps[ $cap ] = (bool) ws_can( $cap );
        }
    }
    // Ubicaciones a las que tiene acceso el usuario (dueño/admin: todas). La
    // app filtra sus selectores y el contenido en caché con estas.
    $my_locations = array_map(
        function ( $l ) {
            return array(
                'id'         => (int) $l->id,
                'name'       => (string) $l->name,
                'pos_enabled' => (int) ( $l->pos_enabled ?? 0 ),
            );
        },
        ws_user_locations( $user_id )
    );
    $biz = function_exists( 'ws_current_business' ) ? ws_current_business() : null;
    return array(
        'userId'       => $user_id,
        'name'         => wp_get_current_user()->display_name,
        'email'        => wp_get_current_user()->user_email,
        'role'         => $role,
        'roleLabel'    => ws_role_label( $role ),
        'business'     => $biz ? (string) ( $biz->slug ?? '' ) : '',
        'businessName' => $biz ? (string) ( $biz->name ?? '' ) : '',
        'currency'     => ws_currency_symbol(),
        'home'         => ws_business_home(),
        'sessionDays'  => (int) get_option( 'ws_session_expiration_days', 30 ),
        'caps'         => $caps,
        'menu'         => $menu,
        'locations'    => $my_locations,
        'serverTime'   => current_time( 'mysql' ),
        'wsVersion'    => defined( 'WS_VERSION' ) ? WS_VERSION : '',
    );
}

add_action( 'wp_ajax_ws_mobile_login', 'ws_ajax_mobile_login' );
add_action( 'wp_ajax_nopriv_ws_mobile_login', 'ws_ajax_mobile_login' );
function ws_ajax_mobile_login() {
    $user = sanitize_text_field( $_POST['ws_user'] ?? '' );
    $pass = (string) ( $_POST['ws_pass'] ?? '' );
    if ( '' === $user || '' === $pass ) {
        wp_send_json_error( array( 'msg' => __( 'Usuario y contraseña son obligatorios.', 'workshop' ) ) );
    }
    $creds = array(
        'user_login'    => $user,
        'user_password' => $pass,
        'remember'      => true,
    );
    $u = wp_signon( $creds, function_exists( 'ws_login_secure_cookie' ) ? ws_login_secure_cookie() : false );
    if ( is_wp_error( $u ) ) {
        wp_send_json_error( array( 'msg' => __( 'Usuario o contraseña incorrectos.', 'workshop' ) ) );
    }
    wp_set_current_user( $u->ID );
    $role = ws_user_role( $u->ID );
    if ( ! $role ) {
        wp_send_json_error( array( 'msg' => __( 'Esta cuenta no tiene acceso al panel del negocio.', 'workshop' ) ) );
    }
    $t = ws_mobile_token_create( $u->ID );
    ws_log_audit( 'mobile_login', 'user', $u->ID );
    wp_send_json_success( array(
        'token'       => $t['token'],
        'expiresAt'   => $t['expiresAt'],
        'sessionDays' => $t['sessionDays'],
        'me'          => ws_mobile_me_payload(),
    ) );
}

add_action( 'wp_ajax_ws_mobile_me', 'ws_ajax_mobile_me' );
add_action( 'wp_ajax_nopriv_ws_mobile_me', 'ws_ajax_mobile_me' );
function ws_ajax_mobile_me() {
    if ( ! ws_mobile_auth_user() ) {
        wp_send_json_success( array( 'loggedIn' => false ) );
    }
    wp_send_json_success( array( 'loggedIn' => true, 'me' => ws_mobile_me_payload() ) );
}

add_action( 'wp_ajax_ws_mobile_logout', 'ws_ajax_mobile_logout' );
add_action( 'wp_ajax_nopriv_ws_mobile_logout', 'ws_ajax_mobile_logout' );
function ws_ajax_mobile_logout() {
    $uid = ws_mobile_auth_user();
    if ( $uid ) {
        delete_user_meta( $uid, 'ws_mobile_token' );
        delete_user_meta( $uid, 'ws_mobile_token_expires' );
        // Destruir también la sesión WP (cookie) para que el panel deje
        // de renderizarse al abrir de nuevo la app en el WebView.
        wp_destroy_current_session();
    }
    $biz = function_exists( 'ws_current_business' ) ? ws_current_business() : null;
    $login_url = $biz && ! empty( $biz->slug )
        ? home_url( '/' . $biz->slug . '/login/' )
        : home_url( '/login/' );
    wp_send_json_success( array( 'loginUrl' => $login_url ) );
}

/**
 * Hash del estado del negocio para el bridge WebSocket de la app: resume los
 * conteos y el momento del último cambio de las tablas clave (pedidos, stock,
 * ventas, movimientos). Si cambia algo, el hash cambia y el bridge difunde
 * 'changes' a las apps conectadas (sincronización en tiempo real).
 */
add_action( 'wp_ajax_ws_mobile_state', 'ws_ajax_mobile_state' );
add_action( 'wp_ajax_nopriv_ws_mobile_state', 'ws_ajax_mobile_state' );
function ws_ajax_mobile_state() {
    if ( ! ws_mobile_auth_user() ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    global $wpdb;
    $tables = array( 'orders', 'stock', 'movements', 'pos_sales', 'products' );
    $parts = array();
    foreach ( $tables as $t ) {
        $table = ws_table_name( $t );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            continue;
        }
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $max   = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" );
        $parts[ $t ] = $count . ':' . ( $max ? $max : '' );
    }
    $hash = md5( wp_json_encode( $parts ) );
    wp_send_json_success( array( 'hash' => $hash ) );
}

/**
 * Configuración del negocio en JSON para la app móvil (módulo Configuración).
 */
add_action( 'wp_ajax_ws_settings_get', 'ws_ajax_settings_get' );
add_action( 'wp_ajax_nopriv_ws_settings_get', 'ws_ajax_settings_get' );
function ws_ajax_settings_get() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Debes iniciar sesión.', 'workshop' ) ) );
    }
    $rates         = function_exists( 'ws_exchange_rates' ) ? ws_exchange_rates() : array();
    $payment       = (array) get_option( 'ws_payment_methods', array( 'Efectivo', 'Tarjeta', 'Transferencia' ) );
    wp_send_json_success( array(
        'data' => array(
            'currency'        => get_option( 'ws_currency', '€' ),
            'currencies'      => get_option( 'ws_currencies', '' ),
            'rates'           => $rates,
            'rates_updated'   => get_option( 'ws_rates_updated', '' ),
            'payment_methods' => $payment,
            'whatsapp'        => get_option( 'ws_whatsapp', '' ),
            'session_days'    => (int) get_option( 'ws_session_expiration_days', 30 ),
            'cloudinary'      => (int) ( class_exists( 'WS_Business' ) && WS_Business::has_cloudinary() ),
        ),
    ) );
}

/**
 * Estado del plan para la app móvil (módulo Plan): suscripción, uso y planes.
 */
add_action( 'wp_ajax_ws_plan_info', 'ws_ajax_plan_info' );
add_action( 'wp_ajax_nopriv_ws_plan_info', 'ws_ajax_plan_info' );
function ws_ajax_plan_info() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Debes iniciar sesión.', 'workshop' ) ) );
    }
    $biz = function_exists( 'ws_current_business' ) ? ws_current_business() : null;
    if ( ! $biz ) {
        wp_send_json_error( array( 'msg' => __( 'Sin negocio.', 'workshop' ) ) );
    }
    $d = function_exists( 'ws_subscription_data' ) ? ws_subscription_data( $biz ) : array();
    $plans = array();
    if ( class_exists( 'WS_Plans' ) && method_exists( 'WS_Plans', 'all' ) ) {
        foreach ( WS_Plans::all() as $p ) {
            $plans[] = array(
                'id'          => (int) $p->id,
                'name'        => (string) $p->name,
                'slug'        => (string) ( $p->slug ?? '' ),
                'price_text'  => method_exists( 'WS_Plans', 'format_price' ) ? WS_Plans::format_price( $p ) : '',
                'description' => (string) ( $p->description ?? '' ),
                'is_trial'    => (int) ( $p->is_trial ?? 0 ) === 1,
            );
        }
    }
    wp_send_json_success( array(
        'data' => array(
            'status'          => (string) ( $d['status'] ?? 'trial' ),
            'status_label'    => (string) ( $d['status_label'] ?? '' ),
            'is_trial'        => ! empty( $d['is_trial'] ),
            'is_active'       => ! empty( $d['is_active'] ),
            'trial_days_left' => (int) ( $d['trial_days_left'] ?? 0 ),
            'plan_days_left'  => (int) ( $d['plan_days_left'] ?? 0 ),
            'plan_name'       => $d['plan'] ? (string) ( $d['plan']->name ?? '' ) : '',
            'usage'           => $d['usage'] ?? array(),
            'limits'          => $d['limits'] ?? array(),
            'locked'          => ! empty( $d['locked'] ),
            'lock'            => is_string( $d['lock'] ?? null ) ? $d['lock'] : '',
            'upgrade_pending' => ! empty( $d['upgrade_pending'] ),
            'upgrade_plan'    => ( $d['upgrade_plan'] ?? null ) ? (string) ( $d['upgrade_plan']->name ?? '' ) : '',
            'plans'           => $plans,
        ),
    ) );
}

/**
 * Helper: nonce + permiso.
 */
function ws_guard( $cap, $fallback = '' ) {
    // App móvil: el token (X-WS-Token) autentica sin cookie ni nonce; la
    // validez del token equivale a la sesión web (misma duración configurada).
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    $ok = ws_can( $cap );
    if ( ! $ok && $fallback ) {
        $ok = ws_can( $fallback );
    }
    if ( ! $ok ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso para esta acción.', 'workshop' ) ) );
    }
    // Negocio con la suscripción vencida, suspendida o con un límite superado:
    // ninguna operación vía AJAX queda disponible, ni siquiera POS. El
    // administrador del sitio sí puede seguir gestionando.
    if ( ! current_user_can( 'manage_options' ) && class_exists( 'WS_Subscriptions' ) ) {
        static $ws_lock_checked = false;
        static $ws_locked       = false;
        static $ws_lock_status  = '';
        static $ws_lock_msg     = '';
        if ( ! $ws_lock_checked ) {
            $biz = ws_current_business();
            if ( $biz && ! WS_Business::is_default( $biz ) ) {
                // lock_reason() refresca el estado y devuelve el motivo del
                // bloqueo (expired, suspended o limit_*), o null si puede operar.
                $reason = WS_Subscriptions::lock_reason( $biz );
                if ( $reason ) {
                    $ws_locked      = true;
                    $ws_lock_status = $reason['key'];
                    $ws_lock_msg    = $reason['message'];
                }
            }
            $ws_lock_checked = true;
        }
        if ( $ws_locked ) {
            $msg = ( 'suspended' === $ws_lock_status )
                ? __( 'Tu negocio está suspendido. Contacta con soporte.', 'workshop' )
                : ( ! empty( $ws_lock_msg )
                    ? $ws_lock_msg . ' ' . __( 'Solicita un upgrade para reactivarlo.', 'workshop' )
                    : __( 'Tu plan venció: el negocio está en pausa.', 'workshop' ) );
            wp_send_json_error( array( 'msg' => $msg ) );
        }
    }
}

/* ---------------- Listados (front) ---------------- */

/**
 * Parámetros de paginación y orden enviados por los paneles Alpine.
 * Devuelve claves listas para la capa de datos (orderby/order/limit/offset).
 */
function ws_list_paging() {
    $page = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
    $page_size = isset( $_POST['pageSize'] ) ? (int) $_POST['pageSize'] : 10;
    $page_size = in_array( $page_size, array( 10, 25, 50, 100 ), true ) ? $page_size : 10;
    $sort = sanitize_key( $_POST['sort'] ?? '' );
    $dir  = ( ( $_POST['dir'] ?? 'asc' ) === 'desc' ) ? 'DESC' : 'ASC';
    return array(
        'paged'    => isset( $_POST['page'] ) || isset( $_POST['pageSize'] ),
        'page'     => $page,
        'pageSize' => $page_size,
        'limit'    => $page_size,
        'offset'   => ( $page - 1 ) * $page_size,
        'sort'     => $sort,
        'dir'      => $dir,
    );
}

/**
 * Aplica paginación/orden + conteo a un listado y responde JSON.
 *
 * @param string $rows_key Clave de las filas en la respuesta.
 * @param callable $fetch  fn( $args ) -> array de filas.
 * @param callable $count  fn( $filter_args ) -> int total.
 * @param array $filter_args Argumentos de filtro (search/type/estado…).
 */
function ws_send_list( $rows_key, $fetch, $count, $filter_args ) {
    $pg   = ws_list_paging();
    $args = array_merge( $filter_args, array( 'orderby' => $pg['sort'], 'order' => $pg['dir'] ) );
    $total = (int) call_user_func( $count, $filter_args );
    $total_pages = max( 1, (int) ceil( $total / $pg['pageSize'] ) );
    $page = min( $pg['page'], $total_pages );
    if ( $pg['paged'] ) {
        $args['limit']  = $pg['limit'];
        $args['offset'] = ( $page - 1 ) * $pg['pageSize'];
    }
    $out = array();
    foreach ( call_user_func( $fetch, $args ) as $row ) {
        $out[] = $row;
    }
    wp_send_json_success( array( $rows_key => $out, 'total' => $total, 'page' => $page, 'pageSize' => $pg['pageSize'] ) );
}

add_action( 'wp_ajax_ws_products_list', 'ws_ajax_products_list' );
function ws_ajax_products_list() {
    ws_guard( 'products_view' );
    $search      = sanitize_text_field( $_POST['search'] ?? '' );
    $show_combos = ! empty( $_POST['show_combos'] );
    // Trabajadores: solo productos con stock registrado en sus ubicaciones.
    // El dueño/admin (o rol vacío) ve TODOS los productos del negocio.
    $is_full = in_array( ws_user_role(), array( 'owner', '' ), true );
    $loc_ids = $is_full ? array() : ws_user_location_ids();

    $row_map = function ( $p ) {
        return array(
            'id'           => (int) $p->id,
            'name'         => $p->name,
            'barcode'      => $p->barcode,
            'category'     => (string) ( $p->category ?? '' ),
            'category_id'  => (int) ( $p->category_id ?? 0 ),
            // Ruta de la categoría en árbol (Padre / Hijo) para la lista.
            'category_path'=> ( ! empty( $p->category_id ) && class_exists( 'WS_Categories' ) )
                ? WS_Categories::path_text( (int) $p->category_id )
                : (string) ( $p->category ?? '' ),
            'description'  => $p->description,
            'image'        => $p->image,
            'gallery'      => WS_CRUD::product_gallery( $p ),
            'cost_price'   => (float) $p->cost_price,
            'sale_price'   => (float) $p->sale_price,
            'transfer_pct' => (float) $p->transfer_pct,
            'currency'     => $p->currency,
            'show_equiv'   => (int) ( $p->show_equiv ?? 1 ),
            'supplier_id'  => (int) $p->supplier_id,
            'supplier_name'=> $p->supplier_name,
            'min_stock'    => (float) $p->min_stock,
            'production_date' => (string) ( $p->production_date ?? '' ),
            'expiry_date'     => (string) ( $p->expiry_date ?? '' ),
            // Estado de caducidad para la tabla: vencido o por vencer (≤7 días).
            'expired'      => ( ! empty( $p->expiry_date ) && strtotime( (string) $p->expiry_date . ' 00:00:00' ) < strtotime( 'today midnight' ) ) ? 1 : 0,
            'expiring'     => ( ! empty( $p->expiry_date ) ) ? ( ( strtotime( (string) $p->expiry_date . ' 00:00:00' ) >= strtotime( 'today midnight' ) && strtotime( (string) $p->expiry_date . ' 23:59:59' ) <= strtotime( 'today midnight +7 days' ) ) ? 1 : 0 ) : 0,
            'fraction_parent' => (int) ( $p->fraction_parent ?? 0 ),
            'fraction_qty'    => (float) ( $p->fraction_qty ?? 0 ),
            'active'       => (int) $p->active,
            'is_combo'     => 0,
            'combo_id'     => 0,
        );
    };

    if ( ! $show_combos ) {
        ws_send_list( 'products', function ( $args ) use ( $search, $row_map, $loc_ids ) {
            $rows = WS_CRUD::get_products( array_merge( array( 'search' => $search, 'location_ids' => $loc_ids ), $args ) );
            return array_map( $row_map, $rows );
        }, function () use ( $search, $loc_ids ) {
            return WS_CRUD::count_products( array( 'search' => $search, 'location_ids' => $loc_ids ) );
        }, array( 'search' => $search, 'location_ids' => $loc_ids ) );
    }

    // Combos disponibles en las ubicaciones asignadas (stock > 0), igual que
    // hace el POS: los combos de otras ubicaciones no le salen al trabajador.
    $allowed_combos = array();
    foreach ( ws_user_locations() as $al ) {
        foreach ( WS_Combos::catalog_rows( (int) $al->id ) as $ac ) {
            if ( (float) $ac['qty'] > 0 ) {
                $allowed_combos[ (int) $ac['combo_id'] ] = true;
            }
        }
    }

    // Con "Mostrar combos": mezclamos los combos activos con los productos y
    // paginamos en PHP (los combos viven en otra tabla y no participan del SQL).
    $pg   = ws_list_paging();
    $rows = array_map( $row_map, WS_CRUD::get_products( array( 'search' => $search, 'location_ids' => $loc_ids ) ) );
    foreach ( WS_Combos::all( array( 'active' => 1, 'search' => $search ) ) as $c ) {
        if ( ! isset( $allowed_combos[ (int) $c->id ] ) ) {
            continue;
        }
        $rows[] = array(
            'id'               => -1 * (int) $c->id,
            'name'             => $c->name,
            'barcode'          => '',
            'category'         => '',
            'category_id'      => 0,
            'category_path'    => '',
            'description'      => '',
            'image'            => $c->photo,
            'cost_price'       => 0.0,
            'sale_price'       => WS_Combos::price( $c ),
            'transfer_pct'     => 0.0,
            'currency'         => $c->currency,
            'show_equiv'       => 1,
            'supplier_id'      => 0,
            'supplier_name'    => '',
            'min_stock'        => 0.0,
            'production_date'  => '',
            'expiry_date'      => '',
            'expired'          => 0,
            'expiring'         => 0,
            'fraction_parent'  => 0,
            'fraction_qty'     => 0.0,
            'active'           => 1,
            'is_combo'         => 1,
            'combo_id'         => (int) $c->id,
        );
    }
    $sortable = array( 'name', 'supplier_name', 'cost_price', 'sale_price', 'transfer_pct', 'min_stock', 'production_date', 'expiry_date' );
    $sort = in_array( $pg['sort'], $sortable, true ) ? $pg['sort'] : 'name';
    usort( $rows, function ( $a, $b ) use ( $sort, $pg ) {
        $x = $a[ $sort ];
        $y = $b[ $sort ];
        $cmp = ( is_numeric( $x ) && is_numeric( $y ) ) ? ( (float) $x <=> (float) $y ) : strcasecmp( (string) $x, (string) $y );
        return 'DESC' === $pg['dir'] ? -$cmp : $cmp;
    } );
    $total       = count( $rows );
    $total_pages = max( 1, (int) ceil( $total / $pg['pageSize'] ) );
    $page        = min( $pg['page'], $total_pages );
    wp_send_json_success( array(
        'products' => array_slice( $rows, ( $page - 1 ) * $pg['pageSize'], $pg['pageSize'] ),
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pg['pageSize'],
    ) );
}

add_action( 'wp_ajax_ws_locations_list', 'ws_ajax_locations_list' );
function ws_ajax_locations_list() {
    ws_guard( 'locations_view' );
    $search = sanitize_text_field( $_POST['search'] ?? '' );
    // Trabajadores: solo ven sus ubicaciones asignadas (admin/owner: todas).
    $loc_ids = ws_user_location_ids();
    ws_send_list( 'locations', function ( $args ) use ( $search, $loc_ids ) {
        $rows = WS_CRUD::get_locations( '', array_merge( array( 'search' => $search, 'location_ids' => $loc_ids ), $args ) );
        $out = array();
        foreach ( $rows as $l ) {
            $methods = is_string( $l->payment_methods ) ? json_decode( $l->payment_methods, true ) : $l->payment_methods;
            $store_settings = is_string( $l->store_settings ?? '' ) ? json_decode( $l->store_settings, true ) : $l->store_settings;
            $out[] = array(
                'id'              => (int) $l->id,
                'type'            => $l->type,
                'name'            => $l->name,
                'slug'            => $l->slug,
                'address'         => $l->address,
                'description'     => (string) ( $l->description ?? '' ),
                'photo'           => $l->photo,
                'currency'        => $l->currency,
                'payment_methods' => is_array( $methods ) ? $methods : array(),
                'store_settings'  => is_array( $store_settings ) ? $store_settings : array(),
                'whatsapp'        => $l->whatsapp,
                'delivery_cost'   => (float) $l->delivery_cost,
                'delivery_currency' => $l->delivery_currency ? $l->delivery_currency : ( $l->currency ? $l->currency : ws_currency_symbol() ),
                'active'          => (int) $l->active,
                'pos_enabled'     => (int) ( $l->pos_enabled ?? 1 ),
            );
        }
        return $out;
    }, function () use ( $search, $loc_ids ) {
        return WS_CRUD::count_locations( '', array( 'search' => $search, 'location_ids' => $loc_ids ) );
    }, array( 'search' => $search, 'location_ids' => $loc_ids ) );
}

add_action( 'wp_ajax_ws_my_locations', 'ws_ajax_my_locations' );
function ws_ajax_my_locations() {
    ws_guard( 'locations_view' );
    $out = array();
    foreach ( ws_user_locations() as $l ) {
        // Solo se muestra en el POS la ubicación marcada en Ubicaciones
        // (pos_enabled): el check decide dónde se vende en punto de venta.
        if ( ! (int) ( $l->pos_enabled ?? 1 ) ) {
            continue;
        }
        $out[] = array(
            'id'       => (int) $l->id,
            'name'     => $l->name,
            'slug'     => $l->slug,
            'type'     => $l->type,
            'active'   => (int) $l->active,
            'currency' => $l->currency ? $l->currency : ws_currency_symbol(),
        );
    }
    wp_send_json_success( array( 'data' => $out ) );
}

add_action( 'wp_ajax_ws_suppliers_list', 'ws_ajax_suppliers_list' );
function ws_ajax_suppliers_list() {
    ws_guard( 'suppliers_view' );
    $search = sanitize_text_field( $_POST['search'] ?? '' );
    ws_send_list( 'suppliers', function ( $args ) use ( $search ) {
        $rows = WS_CRUD::get_suppliers( array_merge( array( 'search' => $search ), $args ) );
        $out = array();
        foreach ( $rows as $s ) {
            $out[] = array(
                'id'       => (int) $s->id,
                'name'     => $s->name,
                'phone'    => $s->phone,
                'address'  => $s->address,
                'country'  => $s->country,
                'province' => $s->province,
            );
        }
        return $out;
    }, function () use ( $search ) {
        return WS_CRUD::count_suppliers( array( 'search' => $search ) );
    }, array( 'search' => $search ) );
}

/* ---------------- Productos ---------------- */

add_action( 'wp_ajax_ws_save_product', 'ws_ajax_save_product' );
function ws_ajax_save_product() {
    ws_guard( 'products_create', 'products_edit' );
    $id = (int) ( $_POST['id'] ?? 0 );
    if ( $id && ! ws_can( 'products_edit' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso para editar productos.', 'workshop' ) ) );
    }
    // Fraccionamiento: requiere el permiso específico (crear o editar un hijo).
    $is_fraction = ! empty( $_POST['fraction_parent'] ) && (float) ( $_POST['fraction_qty'] ?? 0 ) > 0;
    if ( $is_fraction && ! ws_can( 'products_fraction' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso para configurar productos fraccionados.', 'workshop' ) ) );
    }
    // Límite del plan al crear (los edit no cuentan).
    if ( ! $id ) {
        $limit = ws_plan_guard( 'products' );
        if ( is_wp_error( $limit ) ) {
            wp_send_json_error( array( 'msg' => $limit->get_error_message() ) );
        }
    }
    $result = WS_CRUD::save_product( $_POST, $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( $id ? 'product_update' : 'product_create', 'product', $result, array( 'name' => $_POST['name'] ?? '' ) );
    // Incluye el resultado de la conversión de stock del fraccionamiento
    // (padre -1 → hijo +factor) para mostrarlo en el panel.
    $response = array( 'id' => $result );
    $fraction = WS_CRUD::last_fraction_conversion();
    if ( is_array( $fraction ) ) {
        $response['fraction'] = $fraction;
    } elseif ( is_wp_error( $fraction ) ) {
        // Fallo raro (p. ej. concurrencia): avisar sin bloquear el guardado.
        $response['fraction'] = array(
            'attempted' => true,
            'converted' => 0,
            'locations' => array(),
            'error'     => $fraction->get_error_message(),
        );
    }
    wp_send_json_success( $response );
}

add_action( 'wp_ajax_ws_delete_product', 'ws_ajax_delete_product' );
function ws_ajax_delete_product() {
    ws_guard( 'products_delete' );
    $id = (int) ( $_POST['id'] ?? 0 );
    $res = WS_CRUD::delete_product( $id );
    if ( is_wp_error( $res ) ) {
        wp_send_json_error( array( 'msg' => $res->get_error_message() ) );
    }
    ws_log_audit( 'product_delete', 'product', $id );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_product_delete_check', 'ws_ajax_product_delete_check' );
function ws_ajax_product_delete_check() {
    ws_guard( 'products_view' );
    $id   = (int) ( $_POST['id'] ?? 0 );
    $refs = WS_CRUD::product_references( $id );
    $keys = array_map( 'strval', array_keys( $refs ) );
    wp_send_json_success( array( 'can' => empty( $refs ), 'refs' => $keys ) );
}

add_action( 'wp_ajax_ws_product_toggle', 'ws_ajax_product_toggle' );
function ws_ajax_product_toggle() {
    ws_guard( 'products_edit' );
    global $wpdb;
    $id     = (int) ( $_POST['id'] ?? 0 );
    $active = ! empty( $_POST['active'] ) ? 1 : 0;
    $row    = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, name FROM " . ws_table_name( 'products' ) . " WHERE id = %d", $id
    ) );
    if ( ! $row ) {
        wp_send_json_error( array( 'msg' => __( 'Producto no encontrado.', 'workshop' ) ) );
    }
    $wpdb->update( ws_table_name( 'products' ), array( 'active' => $active ), array( 'id' => $id ) );
    ws_log_audit( $active ? 'product_activate' : 'product_deactivate', 'product', $id );
    wp_send_json_success( array( 'active' => $active ) );
}

add_action( 'wp_ajax_ws_products_bulk_edit', 'ws_ajax_products_bulk_edit' );
function ws_ajax_products_bulk_edit() {
    ws_guard( 'products_edit', 'products_bulk' );
    $ids   = isset( $_POST['ids'] ) ? json_decode( wp_unslash( (string) $_POST['ids'] ), true ) : array();
    $field = sanitize_key( $_POST['field'] ?? '' );
    $mode  = ( 'add' === ( $_POST['mode'] ?? '' ) ) ? 'add' : 'set';
    $value = isset( $_POST['value'] ) ? wp_unslash( (string) $_POST['value'] ) : '';

    $updated = WS_CRUD::bulk_update_products( is_array( $ids ) ? $ids : array(), $field, $value, $mode );
    if ( is_wp_error( $updated ) ) {
        wp_send_json_error( array( 'msg' => $updated->get_error_message() ) );
    }
    ws_log_audit( 'product_bulk_edit', 'product', 0, array(
        'field' => $field, 'mode' => $mode, 'value' => $value, 'updated' => $updated,
    ) );
    wp_send_json_success( array( 'updated' => $updated ) );
}

/* ---------------- Combos ---------------- */

add_action( 'wp_ajax_ws_combos_list', 'ws_ajax_combos_list' );
function ws_ajax_combos_list() {
    ws_guard( 'products_view' );
    $search    = sanitize_text_field( $_POST['search'] ?? '' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    ws_send_list( 'combos', function ( $args ) use ( $search, $location_id ) {
        $rows = WS_Combos::all( array_merge( array( 'search' => $search ), $args ) );
        $out  = array();
        foreach ( $rows as $c ) {
            $price = WS_Combos::price( $c );
            $out[] = array(
                'id'          => (int) $c->id,
                'name'        => $c->name,
                'photo'       => $c->photo,
                'price_mode'  => $c->price_mode,
                'price'       => round( $price, 2 ),
                'currency'    => $c->currency,
                'active'      => (int) $c->active,
                'store_visible' => (int) ( $c->store_visible ?? 1 ),
                'item_count'  => (int) $c->item_count,
                'stock'       => $location_id ? WS_Combos::stock( (int) $c->id, $location_id ) : null,
                'items'       => array_map( function ( $it ) {
                    return array(
                        'product_id' => (int) $it->product_id,
                        'name'       => $it->product_name,
                        'qty'        => (float) $it->qty,
                        'sale_price' => (float) $it->sale_price,
                        'currency'   => $it->product_currency,
                    );
                }, WS_Combos::items( (int) $c->id ) ),
            );
        }
        return $out;
    }, function () use ( $search ) {
        return WS_Combos::count( array( 'search' => $search ) );
    }, array( 'search' => $search ) );
}

add_action( 'wp_ajax_ws_combo_save', 'ws_ajax_combo_save' );
function ws_ajax_combo_save() {
    ws_guard( 'products_create', 'products_edit' );
    $id = (int) ( $_POST['id'] ?? 0 );
    if ( $id && ! ws_can( 'products_edit' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso para editar combos.', 'workshop' ) ) );
    }
    if ( ! $id ) {
        $limit = ws_plan_guard( 'products' );
        if ( is_wp_error( $limit ) ) {
            wp_send_json_error( array( 'msg' => $limit->get_error_message() ) );
        }
    }
    $items = isset( $_POST['items'] ) ? (array) json_decode( wp_unslash( $_POST['items'] ), true ) : array();
    $result = WS_Combos::save( array_merge( $_POST, array( 'items' => $items ) ), $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( $id ? 'combo_update' : 'combo_create', 'combo', $result, array( 'name' => $_POST['name'] ?? '' ) );
    wp_send_json_success( array( 'id' => $result ) );
}

add_action( 'wp_ajax_ws_combo_delete', 'ws_ajax_combo_delete' );
function ws_ajax_combo_delete() {
    ws_guard( 'products_delete' );
    $id = (int) ( $_POST['id'] ?? 0 );
    WS_Combos::delete( $id );
    ws_log_audit( 'combo_delete', 'combo', $id );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_combo_toggle', 'ws_ajax_combo_toggle' );
function ws_ajax_combo_toggle() {
    ws_guard( 'products_edit' );
    $id     = (int) ( $_POST['id'] ?? 0 );
    $active = ! empty( $_POST['active'] );
    WS_Combos::set_active( $id, $active );
    ws_log_audit( 'combo_toggle', 'combo', $id, array( 'active' => $active ) );
    wp_send_json_success( array( 'active' => $active ) );
}

add_action( 'wp_ajax_ws_combo_transfer', 'ws_ajax_combo_transfer' );
function ws_ajax_combo_transfer() {
    ws_guard( 'stock_transfer' );
    $combo_id = (int) ( $_POST['combo_id'] ?? 0 );
    $from     = (int) ( $_POST['from_location'] ?? 0 );
    $to       = (int) ( $_POST['to_location'] ?? 0 );
    $count    = (float) ( $_POST['count'] ?? 0 );
    $note     = sanitize_text_field( $_POST['note'] ?? '' );
    $result = WS_Combos::transfer( $combo_id, $from, $to, $count, 'Transferencia combo', $note, get_current_user_id() );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( 'combo_transfer', 'combo', $combo_id, array( 'from' => $from, 'to' => $to, 'count' => $count ) );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_import_products', 'ws_ajax_import_products' );
function ws_ajax_import_products() {
    ws_guard( 'products_bulk' );
    $rows = isset( $_POST['rows'] ) ? (array) json_decode( wp_unslash( $_POST['rows'] ), true ) : array();
    if ( empty( $rows ) ) {
        wp_send_json_error( array( 'msg' => __( 'No hay filas para importar.', 'workshop' ) ) );
    }
    // Límite del plan: la importación no puede superar el máximo de productos.
    $limit = ws_plan_guard( 'products' );
    if ( is_wp_error( $limit ) ) {
        wp_send_json_error( array( 'msg' => $limit->get_error_message() ) );
    }
    $created = 0;
    $errors  = array();
    foreach ( $rows as $i => $row ) {
        $data = array(
            'name'         => $row['name'] ?? '',
            'barcode'      => $row['barcode'] ?? '',
            'description'  => $row['description'] ?? '',
            'cost_price'   => $row['cost_price'] ?? 0,
            'sale_price'   => $row['sale_price'] ?? 0,
            'transfer_pct' => $row['transfer_pct'] ?? 0,
            'currency'     => $row['currency'] ?? ws_currency_symbol(),
            'supplier_id'  => $row['supplier_id'] ?? 0,
            'min_stock'    => $row['min_stock'] ?? 0,
            'image'        => $row['image'] ?? '',
        );
        if ( empty( $data['name'] ) ) {
            $errors[] = sprintf( __( 'Fila %d: falta el nombre.', 'workshop' ), $i + 1 );
            continue;
        }
        $existing = WS_CRUD::get_products( array( 'search' => $data['barcode'] ) );
        $found = false;
        foreach ( $existing as $p ) {
            if ( $p->barcode === $data['barcode'] ) {
                $found = true;
                break;
            }
        }
        if ( $found ) {
            $errors[] = sprintf( __( 'Fila %d: código ya existe.', 'workshop' ), $i + 1 );
            continue;
        }
        $result = WS_CRUD::save_product( $data );
        if ( is_wp_error( $result ) ) {
            $errors[] = sprintf( __( 'Fila %d: %s', 'workshop' ), $i + 1, $result->get_error_message() );
        } else {
            $created++;
        }
    }
    ws_log_audit( 'products_import', 'product', 0, array( 'created' => $created, 'errors' => count( $errors ) ) );
    wp_send_json_success( array( 'created' => $created, 'errors' => $errors ) );
}

add_action( 'wp_ajax_ws_price_history_list', 'ws_ajax_price_history_list' );
function ws_ajax_price_history_list() {
    ws_guard( 'products_view' );
    $search     = sanitize_text_field( $_POST['search'] ?? '' );
    $product_id = (int) ( $_POST['product_id'] ?? 0 );
    ws_send_list( 'history', function ( $args ) use ( $search, $product_id ) {
        $rows = WS_CRUD::get_price_history( array_merge( array( 'search' => $search, 'product_id' => $product_id ), $args ) );
        $out = array();
        foreach ( $rows as $h ) {
            $out[] = array(
                'id'           => (int) $h->id,
                'product_id'   => (int) $h->product_id,
                'product_name' => $h->product_name,
                'old_cost'     => (float) $h->old_cost,
                'new_cost'     => (float) $h->new_cost,
                'old_sale'     => (float) $h->old_sale,
                'new_sale'     => (float) $h->new_sale,
                'currency'     => $h->currency,
                'user_name'    => $h->user_name ?? '',
                'date'         => mysql2date( 'd/m/Y H:i', $h->created_at ),
            );
        }
        return $out;
    }, function () use ( $search, $product_id ) {
        return WS_CRUD::count_price_history( array( 'search' => $search, 'product_id' => $product_id ) );
    }, array( 'search' => $search, 'product_id' => $product_id ) );
}

/* ---------------- Proveedores ---------------- */

add_action( 'wp_ajax_ws_save_supplier', 'ws_ajax_save_supplier' );
function ws_ajax_save_supplier() {
    ws_guard( 'suppliers_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    if ( ! $id ) {
        $limit = ws_plan_guard( 'suppliers' );
        if ( is_wp_error( $limit ) ) {
            wp_send_json_error( array( 'msg' => $limit->get_error_message() ) );
        }
    }
    $result = WS_CRUD::save_supplier( $_POST, $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( $id ? 'supplier_update' : 'supplier_create', 'supplier', $result );
    wp_send_json_success( array( 'id' => $result ) );
}

add_action( 'wp_ajax_ws_delete_supplier', 'ws_ajax_delete_supplier' );
function ws_ajax_delete_supplier() {
    ws_guard( 'suppliers_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    WS_CRUD::delete_supplier( $id );
    ws_log_audit( 'supplier_delete', 'supplier', $id );
    wp_send_json_success();
}

/* ---------------- Ubicaciones ---------------- */

add_action( 'wp_ajax_ws_save_location', 'ws_ajax_save_location' );
function ws_ajax_save_location() {
    ws_guard( 'locations_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    if ( ! $id ) {
        // Límite del plan según el tipo: punto de venta o almacén.
        $type = ( 'pv' === ( $_POST['type'] ?? 'pv' ) ) ? 'pvs' : 'warehouses';
        $limit = ws_plan_guard( $type );
        if ( is_wp_error( $limit ) ) {
            wp_send_json_error( array( 'msg' => $limit->get_error_message() ) );
        }
    }
    $result = WS_CRUD::save_location( $_POST, $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( $id ? 'location_update' : 'location_create', 'location', $result, array( 'name' => $_POST['name'] ?? '' ) );
    wp_send_json_success( array( 'id' => $result ) );
}

add_action( 'wp_ajax_ws_delete_location', 'ws_ajax_delete_location' );
function ws_ajax_delete_location() {
    ws_guard( 'locations_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    WS_CRUD::delete_location( $id );
    ws_log_audit( 'location_delete', 'location', $id );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_location_links_get', 'ws_ajax_location_links_get' );
function ws_ajax_location_links_get() {
    ws_guard( 'locations_links_view' );
    wp_send_json_success( array( 'links' => WS_CRUD::get_location_links() ) );
}

add_action( 'wp_ajax_ws_location_links_save', 'ws_ajax_location_links_save' );
function ws_ajax_location_links_save() {
    ws_guard( 'locations_manage' );
    $pairs = isset( $_POST['links'] ) ? json_decode( wp_unslash( (string) $_POST['links'] ), true ) : array();
    $res   = WS_CRUD::set_location_links( is_array( $pairs ) ? $pairs : array() );
    if ( empty( $res['ok'] ) ) {
        wp_send_json_error( array( 'msg' => ! empty( $res['error'] ) ? $res['error'] : __( 'No se pudieron guardar las conexiones.', 'workshop' ) ) );
    }
    ws_log_audit( 'location_links_save', 'location', 0, array( 'links' => $res['count'] ) );
    wp_send_json_success( array( 'count' => $res['count'] ) );
}

/* ---------------- Stock ---------------- */

/**
 * Etiqueta para la pila de deshacer de un movimiento de producto: busca el
 * nombre y arma "Entrada de Coca-Cola × 5" legible para el usuario.
 */
function ws_undo_movement_label( $type, $product_id, $qty ) {
    global $wpdb;
    $name = $wpdb->get_var( $wpdb->prepare(
        'SELECT name FROM ' . ws_table_name( 'products' ) . ' WHERE id=%d', (int) $product_id
    ) );
    return sprintf( '%s de %s × %s', ucfirst( $type ), $name ? $name : '#' . (int) $product_id, number_format_i18n( (float) $qty, 2 ) );
}

add_action( 'wp_ajax_ws_stock_move', 'ws_ajax_stock_move' );
function ws_ajax_stock_move() {
    $type = sanitize_key( $_POST['type'] ?? '' );
    $map  = array(
        'entrada' => 'stock_entry',
        'salida'  => 'stock_exit',
        'baja'    => 'stock_writeoff',
    );
    if ( ! isset( $map[ $type ] ) ) {
        wp_send_json_error( array( 'msg' => __( 'Tipo de movimiento inválido.', 'workshop' ) ) );
    }
    ws_guard( $map[ $type ] );

    $product_id  = (int) ( $_POST['product_id'] ?? 0 );
    $combo_id    = (int) ( $_POST['combo_id'] ?? 0 );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $qty         = (float) ( $_POST['qty'] ?? 0 );
    $ref         = sanitize_text_field( $_POST['reference'] ?? '' );
    $note        = sanitize_text_field( $_POST['note'] ?? '' );
    // Solo permite mover stock en ubicaciones asignadas al trabajador.
    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }
    $before      = WS_Stock::undo_snapshot();

    // Combo: se mueven sus COMPONENTES (cada producto × cantidad), igual que
    // en el asistente. El movimiento queda registrado por componente.
    if ( $combo_id > 0 ) {
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $result = ( 'entrada' === $type )
            ? WS_Combos::increase_in_tx( $combo_id, $location_id, $qty, $type, $ref, $note )
            : WS_Combos::decrease_in_tx( $combo_id, $location_id, $qty, $type, $ref, $note );
        if ( is_wp_error( $result ) ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
        }
        $wpdb->query( 'COMMIT' );
        $cname = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . ws_table_name( 'combos' ) . ' WHERE id=%d', $combo_id ) );
        WS_Stock::undo_push( $location_id, sprintf( '%s de combo %s × %s', ucfirst( $type ), $cname ? $cname : '#' . $combo_id, number_format_i18n( $qty, 2 ) ), $before, WS_Stock::undo_snapshot() );
        ws_log_audit( 'stock_' . $type, 'combo', $combo_id, array( 'location' => $location_id, 'qty' => $qty ) );
        wp_send_json_success( array( 'qty' => WS_Combos::stock( $combo_id, $location_id ) ) );
    }

    if ( 'entrada' === $type ) {
        $result = WS_Stock::increase( $product_id, $location_id, $qty, $type, $ref, $note );
    } else {
        $result = WS_Stock::decrease( $product_id, $location_id, $qty, $type, $ref, $note );
    }
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    WS_Stock::undo_push( $location_id, ws_undo_movement_label( $type, $product_id, $qty ), $before, WS_Stock::undo_snapshot() );
    ws_log_audit( 'stock_' . $type, 'movement', $product_id, array( 'location' => $location_id, 'qty' => $qty ) );
    wp_send_json_success( array( 'qty' => WS_Stock::qty( $product_id, $location_id ) ) );
}

add_action( 'wp_ajax_ws_stock_transfer', 'ws_ajax_stock_transfer' );
function ws_ajax_stock_transfer() {
    ws_guard( 'stock_transfer' );
    $product_id = (int) ( $_POST['product_id'] ?? 0 );
    $combo_id   = (int) ( $_POST['combo_id'] ?? 0 );
    $from       = (int) ( $_POST['from_location'] ?? 0 );
    $to         = (int) ( $_POST['to_location'] ?? 0 );
    $qty        = (float) ( $_POST['qty'] ?? 0 );
    $note       = sanitize_text_field( $_POST['note'] ?? '' );
    // Solo permite transferir entre ubicaciones asignadas al trabajador.
    $loc_ids    = ws_user_location_ids();
    if ( ! $from || ! in_array( $from, $loc_ids, true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación de origen inválida.', 'workshop' ) ) );
    }
    if ( ! $to || ! in_array( $to, $loc_ids, true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación de destino inválida.', 'workshop' ) ) );
    }
    $before     = WS_Stock::undo_snapshot();
    // Combo: transfiere cada componente (qty × N) de forma atómica.
    $result = ( $combo_id > 0 )
        ? WS_Combos::transfer( $combo_id, $from, $to, $qty, '', $note )
        : WS_Stock::transfer( $product_id, $from, $to, $qty, '', $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    global $wpdb;
    if ( $combo_id > 0 ) {
        $cname = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . ws_table_name( 'combos' ) . ' WHERE id=%d', $combo_id ) );
        $label = 'Transferencia de combo ' . ( $cname ? $cname : '#' . $combo_id ) . ' × ' . number_format_i18n( $qty, 2 );
    } else {
        $label = 'Transferencia de ' . ws_undo_movement_label( 'transferencia', $product_id, $qty );
    }
    WS_Stock::undo_push( $from, $label, $before, WS_Stock::undo_snapshot() );
    ws_log_audit( 'stock_transfer', $combo_id > 0 ? 'combo' : 'movement', $combo_id > 0 ? $combo_id : $product_id, array( 'from' => $from, 'to' => $to, 'qty' => $qty ) );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_stock_clean', 'ws_ajax_stock_clean' );
function ws_ajax_stock_clean() {
    ws_guard( 'stock_writeoff' );
    $kind        = sanitize_key( $_POST['kind'] ?? '' );
    $id          = (int) ( $_POST['id'] ?? 0 );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    if ( ! in_array( $kind, array( 'product', 'combo' ), true ) || ! $id || ! $location_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }
    $allowed = array_map( fn( $l ) => (int) $l->id, ws_user_locations() );
    if ( ! in_array( $location_id, $allowed, true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación no permitida.', 'workshop' ) ) );
    }
    global $wpdb;
    $stock_t = ws_table_name( 'stock' );
    $before  = WS_Stock::undo_snapshot();
    // Las ubicaciones CONECTADAS por stock compartido DIRIGIDO (los
    // centros/superiores) también se limpian: un borrado es un movimiento más
    // y debe verse reflejado en toda la conexión (igual que entrada/salida/venta).
    $clean_locations = array_merge( array( $location_id ), WS_Stock::linked_location_ids( $location_id ) );
    $clean_locations = array_values( array_unique( array_map( 'intval', $clean_locations ) ) );
    $deleted = 0;
    if ( 'combo' === $kind ) {
        // El stock de un combo se DERIVA de sus productos: limpiarlo elimina
        // el registro de stock de TODOS sus componentes en cada ubicación
        // (la elegida y sus conectadas).
        foreach ( WS_Combos::items( $id ) as $it ) {
            foreach ( $clean_locations as $clean_loc ) {
                $res = $wpdb->delete( $stock_t, array(
                    'product_id'  => (int) $it->product_id,
                    'location_id' => $clean_loc,
                ) );
                $deleted += (int) $res;
            }
        }
    } else {
        foreach ( $clean_locations as $clean_loc ) {
            $deleted += (int) $wpdb->delete( $stock_t, array(
                'product_id'  => $id,
                'location_id' => $clean_loc,
            ) );
        }
    }
    if ( 'combo' === $kind ) {
        $name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . ws_table_name( 'combos' ) . ' WHERE id=%d', $id ) );
        $label = 'Limpieza de combo ' . ( $name ? $name : '#' . $id );
    } else {
        $name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . ws_table_name( 'products' ) . ' WHERE id=%d', $id ) );
        $label = 'Limpieza de ' . ( $name ? $name : '#' . $id );
    }
    if ( count( $clean_locations ) > 1 ) {
        $label .= sprintf( ' (%d ubicaciones conectadas)', count( $clean_locations ) - 1 );
    }
    WS_Stock::undo_push( $location_id, $label, $before, WS_Stock::undo_snapshot() );
    ws_log_audit( 'stock_clean', $kind, $id, array( 'location_id' => $location_id, 'deleted' => $deleted ) );
    wp_send_json_success( array( 'deleted' => $deleted ) );
}

/**
 * Revierte un movimiento del historial: aplica la operación INVERSA (una
 * entrada se descuenta, una salida/baja/venta se repone, una transferencia se
 * devuelve al origen) y lo marca como revertido. Los movimientos de combos se
 * registran por componente, así que se revierten TODOS los componentes de esa
 * operación (mismo combo, tipo, ubicación, referencia y nota, sin revertir).
 * El movimiento queda marcado (reverted_at) y se registra un nuevo movimiento
 * tipo 'revert' enlazado (revert_of) para dejar trazabilidad.
 */
add_action( 'wp_ajax_ws_movement_revert', 'ws_ajax_movement_revert' );
function ws_ajax_movement_revert() {
    ws_guard( 'stock_writeoff' );
    $id = (int) ( $_POST['id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( array( 'msg' => __( 'Movimiento inválido.', 'workshop' ) ) );
    }
    global $wpdb;
    $mv_t    = ws_table_name( 'movements' );
    $movement = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$mv_t} WHERE id=%d", $id ) );
    if ( ! $movement ) {
        wp_send_json_error( array( 'msg' => __( 'Movimiento no encontrado.', 'workshop' ) ) );
    }
    $loc_ids = ws_user_location_ids();
    $owns    = in_array( (int) $movement->location_id, $loc_ids, true )
        || ( (int) $movement->dest_location_id && in_array( (int) $movement->dest_location_id, $loc_ids, true ) );
    if ( ! $owns ) {
        wp_send_json_error( array( 'msg' => __( 'No tienes acceso a este movimiento.', 'workshop' ) ) );
    }
    if ( $movement->reverted_at ) {
        wp_send_json_error( array( 'msg' => __( 'Este movimiento ya fue revertido.', 'workshop' ) ) );
    }
    $type = (string) $movement->type;
    if ( 'revert' === $type || ! in_array( $type, array( 'entrada', 'salida', 'baja', 'venta', 'pedido', 'transferencia' ), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Este tipo de movimiento no se puede revertir.', 'workshop' ) ) );
    }

    // Grupo de la operación: un movimiento de combo genera UNA fila por
    // componente; se revierten todas juntas (mismo combo, tipo, ubicación,
    // referencia y nota, y creadas en la misma operación). Para productos,
    // solo la fila.
    $group = array();
    if ( (int) $movement->combo_id > 0 ) {
        $group = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$mv_t}
             WHERE combo_id=%d AND type=%s AND location_id=%d AND reference=%s AND note=%s
               AND reverted_at IS NULL",
            (int) $movement->combo_id, $type, (int) $movement->location_id,
            $movement->reference, $movement->note
        ) );
    }
    if ( empty( $group ) ) {
        $group = array( $movement );
    }

    $before  = WS_Stock::undo_snapshot();
    $note    = sprintf( __( 'Revertido de #%d', 'workshop' ), $id );
    $ref     = (string) $movement->reference;
    $user_id = get_current_user_id();

    $wpdb->query( 'START TRANSACTION' );
    foreach ( $group as $mv ) {
        $pid = (int) $mv->product_id;
        $loc = (int) $mv->location_id;
        $qty = (float) $mv->qty;
        if ( 'transferencia' === $type ) {
            // Se devuelve del destino al origen (como el movimiento inverso).
            $result = WS_Stock::transfer_in_tx( $pid, (int) $mv->dest_location_id, $loc, $qty, $ref, $note, $user_id, (int) $mv->combo_id, (int) $mv->id );
        } elseif ( 'entrada' === $type ) {
            // La entrada se descuenta (reposición inversa).
            $result = WS_Stock::decrease_in_tx( $pid, $loc, $qty, 'revert', $ref, $note, $user_id, (int) $mv->combo_id, (int) $mv->id );
        } else {
            // Salida / baja / venta / pedido: se repone el stock.
            $result = WS_Stock::increase_in_tx( $pid, $loc, $qty, 'revert', $ref, $note, $user_id, (int) $mv->combo_id, (int) $mv->id );
        }
        if ( is_wp_error( $result ) ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
        }
        $wpdb->update( $mv_t, array(
            'reverted_at' => current_time( 'mysql' ),
            'reverted_by' => $user_id,
        ), array( 'id' => (int) $mv->id ) );
    }
    $wpdb->query( 'COMMIT' );

    WS_Stock::undo_push( (int) $movement->location_id, 'Reversión de #' . $id . ' (' . $type . ')', $before, WS_Stock::undo_snapshot() );
    ws_log_audit( 'stock_revert', 'movement', $id, array( 'type' => $type, 'rows' => count( $group ) ) );
    wp_send_json_success( array( 'reverted' => count( $group ) ) );
}

/**
 * Pila de deshacer/rehacer: lista las operaciones recientes del negocio
 * (en las ubicaciones del usuario) para el panel de Movimientos.
 */
add_action( 'wp_ajax_ws_undo_list', 'ws_ajax_undo_list' );
function ws_ajax_undo_list() {
    ws_guard( 'stock_writeoff' );
    global $wpdb;
    $loc_ids = ws_user_location_ids();
    if ( empty( $loc_ids ) ) {
        wp_send_json_success( array( 'undo' => array() ) );
    }
    $ph      = implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) );
    $rows    = $wpdb->get_results( $wpdb->prepare(
        "SELECT u.id, u.location_id, u.label, u.undone, u.user_id, u.created_at, l.name AS location_name
         FROM " . ws_table_name( 'undo_stack' ) . " u
         LEFT JOIN " . ws_table_name( 'locations' ) . " l ON l.id = u.location_id
         WHERE u.location_id IN ({$ph})
         ORDER BY u.id DESC LIMIT 20",
        ...$loc_ids
    ) );
    $out = array();
    foreach ( $rows as $r ) {
        $out[] = array(
            'id'            => (int) $r->id,
            'location_id'   => (int) $r->location_id,
            'location_name' => $r->location_name ?? '',
            'label'         => $r->label,
            'undone'        => (int) $r->undone,
            'user_name'     => get_the_author_meta( 'display_name', (int) $r->user_id ) ?: '—',
            'date'          => mysql2date( 'd/m/Y H:i', $r->created_at ),
        );
    }
    wp_send_json_success( array( 'undo' => $out ) );
}

/**
 * Deshacer: restaura el stock al estado ANTERIOR a la última operación
 * (y la marca como deshecha). Solo opera sobre la última no deshecha.
 */
add_action( 'wp_ajax_ws_undo', 'ws_ajax_undo' );
function ws_ajax_undo() {
    ws_guard( 'stock_writeoff' );
    $loc_ids = ws_user_location_ids();
    if ( empty( $loc_ids ) ) {
        wp_send_json_error( array( 'msg' => __( 'No hay cambios para deshacer.', 'workshop' ) ) );
    }
    $ph      = implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) );
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . ws_table_name( 'undo_stack' ) . "
         WHERE location_id IN ({$ph}) AND undone = 0
         ORDER BY id DESC LIMIT 1",
        ...$loc_ids
    ) );
    if ( ! $row ) {
        wp_send_json_error( array( 'msg' => __( 'No hay cambios para deshacer.', 'workshop' ) ) );
    }
    $before = json_decode( $row->before_data, true );
    if ( ! is_array( $before ) ) {
        wp_send_json_error( array( 'msg' => __( 'No se pudo restaurar el estado anterior.', 'workshop' ) ) );
    }
    WS_Stock::undo_apply( $before );
    $wpdb->update( ws_table_name( 'undo_stack' ), array( 'undone' => 1 ), array( 'id' => (int) $row->id ) );
    ws_log_audit( 'stock_undo', 'movement', 0, array( 'undo_id' => (int) $row->id, 'label' => $row->label ) );
    wp_send_json_success( array( 'label' => $row->label, 'id' => (int) $row->id ) );
}

/**
 * Rehacer: restaura el stock al estado POSTERIOR a la última operación
 * deshecha (solo si hay un cambio deshecho pendiente de rehacer).
 */
add_action( 'wp_ajax_ws_redo', 'ws_ajax_redo' );
function ws_ajax_redo() {
    ws_guard( 'stock_writeoff' );
    $loc_ids = ws_user_location_ids();
    if ( empty( $loc_ids ) ) {
        wp_send_json_error( array( 'msg' => __( 'No hay cambios para rehacer.', 'workshop' ) ) );
    }
    $ph      = implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) );
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . ws_table_name( 'undo_stack' ) . "
         WHERE location_id IN ({$ph}) AND undone = 1
         ORDER BY id DESC LIMIT 1",
        ...$loc_ids
    ) );
    if ( ! $row ) {
        wp_send_json_error( array( 'msg' => __( 'No hay cambios para rehacer.', 'workshop' ) ) );
    }
    $after = json_decode( $row->after_data, true );
    if ( ! is_array( $after ) ) {
        wp_send_json_error( array( 'msg' => __( 'No se pudo rehacer el cambio.', 'workshop' ) ) );
    }
    WS_Stock::undo_apply( $after );
    $wpdb->update( ws_table_name( 'undo_stack' ), array( 'undone' => 0 ), array( 'id' => (int) $row->id ) );
    ws_log_audit( 'stock_redo', 'movement', 0, array( 'undo_id' => (int) $row->id, 'label' => $row->label ) );
    wp_send_json_success( array( 'label' => $row->label, 'id' => (int) $row->id ) );
}

add_action( 'wp_ajax_ws_store_toggle', 'ws_ajax_store_toggle' );
function ws_ajax_store_toggle() {
    ws_guard( 'products_edit' );
    $kind        = sanitize_key( $_POST['kind'] ?? '' );
    $id          = (int) ( $_POST['id'] ?? 0 );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $channel     = sanitize_key( $_POST['channel'] ?? 'store' );
    $visible     = ! empty( $_POST['visible'] );
    if ( ! in_array( $kind, array( 'product', 'combo' ), true ) || ! $id || ! $location_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }
    if ( ! in_array( $channel, array( 'store', 'pos' ), true ) ) {
        $channel = 'store';
    }
    global $wpdb;
    // La visibilidad es POR UBICACIÓN Y CANAL (cada PV tiene su tienda y su
    // POS): se guarda un override en ws_store_visibility para (entidad,
    // ubicación, canal) sin tocar el flag global. Mostrar/ocultar en un PV
    // no afecta a los otros ni al otro canal.
    $table  = ws_table_name( 'store_visibility' );
    $exists = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE entity_type=%s AND entity_id=%d AND location_id=%d AND channel=%s",
        $kind, $id, $location_id, $channel
    ) );
    if ( $exists ) {
        $res = $wpdb->update( $table, array( 'visible' => $visible ? 1 : 0 ), array( 'id' => $exists ) );
    } else {
        $res = $wpdb->insert( $table, array(
            'entity_type' => $kind,
            'entity_id'   => $id,
            'location_id' => $location_id,
            'channel'     => $channel,
            'visible'     => $visible ? 1 : 0,
        ) );
    }
    // false = error real; 0 = sin cambios (el valor ya era el pedido), NO error.
    if ( false === $res ) {
        wp_send_json_error( array( 'msg' => __( 'No se pudo guardar la visibilidad (¿falta la tabla store_visibility?). Recarga la página para migrar la base de datos.', 'workshop' ) ) );
    }
    ws_log_audit( 'store_visibility', $kind, $id, array( 'location_id' => $location_id, 'channel' => $channel, 'store_visible' => $visible ) );
    wp_send_json_success( array( 'store_visible' => $visible ) );
}

add_action( 'wp_ajax_ws_stock_batch_move', 'ws_ajax_stock_batch_move' );
function ws_ajax_stock_batch_move() {
    $type      = sanitize_text_field( $_POST['type'] ?? '' );
    $direction = sanitize_key( $_POST['direction'] ?? '' );
    $map  = array(
        'entrada' => 'stock_entry',
        'salida'  => 'stock_exit',
        'baja'    => 'stock_writeoff',
    );
    if ( isset( $map[ $type ] ) ) {
        ws_guard( $map[ $type ] );
    } elseif ( '' !== $type ) {
        // Tipo personalizado: la dirección decide si aumenta o disminuye stock.
        if ( ! in_array( $direction, array( 'entrada', 'salida' ), true ) ) {
            wp_send_json_error( array( 'msg' => __( 'Dirección de movimiento inválida.', 'workshop' ) ) );
        }
        ws_guard( 'entrada' === $direction ? 'stock_entry' : 'stock_exit' );
    } else {
        wp_send_json_error( array( 'msg' => __( 'Tipo de movimiento inválido.', 'workshop' ) ) );
    }

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $items       = isset( $_POST['items'] ) ? (array) json_decode( wp_unslash( $_POST['items'] ), true ) : array();
    $ref         = sanitize_text_field( $_POST['reference'] ?? '' );
    $note        = sanitize_text_field( $_POST['note'] ?? '' );
    // Solo permite movimientos en ubicaciones asignadas al trabajador.
    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }
    $before      = WS_Stock::undo_snapshot();

    $result = WS_Stock::batch_move( $type, $location_id, $items, $ref, $note, 0, $direction );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    WS_Stock::undo_push( $location_id, ucfirst( $type ) . ' múltiple (' . (int) $result . ' ítems)', $before, WS_Stock::undo_snapshot() );
    ws_log_audit( 'stock_' . $type, 'movement', 0, array( 'location' => $location_id, 'direction' => $direction, 'items' => $result ) );
    wp_send_json_success( array( 'count' => $result ) );
}

/**
 * Venta múltiple registrada desde stock (asistente de Stock): descuenta stock
 * de varios productos y crea UNA venta POS con todos los items (caja no
 * abierta, register_id = 0). Igual que ws_movement_venta pero en lote, para
 * registrar 100 productos de una sola vez.
 */
add_action( 'wp_ajax_ws_stock_batch_venta', 'ws_ajax_stock_batch_venta' );
function ws_ajax_stock_batch_venta() {
    ws_guard( 'stock_exit', 'pos_sell' );

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $seller_id   = (int) ( $_POST['seller_id'] ?? 0 );
    $items       = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : array();
    $ref         = sanitize_text_field( $_POST['reference'] ?? '' );
    $note        = sanitize_text_field( $_POST['note'] ?? '' );

    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }
    if ( ! is_array( $items ) || empty( $items ) ) {
        wp_send_json_error( array( 'msg' => __( 'Selecciona al menos un producto.', 'workshop' ) ) );
    }
    if ( ! $seller_id ) {
        $seller_id = get_current_user_id();
    }

    global $wpdb;
    $sale_items = array();
    $currency   = '€';
    $total      = 0.0;

    $before_stock = WS_Stock::undo_snapshot();
    $wpdb->query( 'START TRANSACTION' );
    foreach ( $items as $it ) {
        $pid = (int) ( $it['product_id'] ?? 0 );
        $cid = (int) ( $it['combo_id'] ?? 0 );
        $qty = (float) ( $it['qty'] ?? 0 );
        if ( ( ! $pid && ! $cid ) || $qty <= 0 ) {
            continue;
        }

        // Venta de un COMBO: se descuentan sus componentes (cada uno × cantidad).
        if ( $cid > 0 ) {
            $c = WS_Combos::get( $cid );
            if ( ! $c ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( array( 'msg' => sprintf( __( 'Combo #%d no encontrado.', 'workshop' ), $cid ) ) );
            }
            $price = (float) ( $it['price'] ?? 0 );
            if ( $price <= 0 ) {
                $price = WS_Combos::price( $c );
            }
            $currency = $c->currency;

            $stock_res = WS_Combos::decrease_in_tx( $cid, $location_id, $qty, 'venta', $ref ? $ref : 'Venta desde stock', $note, $seller_id );
            if ( is_wp_error( $stock_res ) ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( array( 'msg' => $stock_res->get_error_message() ) );
            }

            $subtotal = round( $qty * $price, 2 );
            $total    += $subtotal;
            $sale_items[] = array(
                'product_id'   => 0,
                'combo_id'     => $cid,
                'product_name' => $c->name,
                'qty'          => $qty,
                'price'        => $price,
                'cost_price'   => 0,
                'discount'     => 0,
                'subtotal'     => $subtotal,
            );
            continue;
        }

        $p = $wpdb->get_row( $wpdb->prepare( "SELECT name, currency, sale_price, cost_price FROM " . ws_table_name( 'products' ) . " WHERE id=%d", $pid ) );
        if ( ! $p ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'msg' => sprintf( __( 'Producto #%d no encontrado.', 'workshop' ), $pid ) ) );
        }
        $price = (float) ( $it['price'] ?? 0 );
        if ( $price <= 0 ) {
            $price = (float) $p->sale_price;
        }
        $currency = $p->currency;

        $stock_res = WS_Stock::decrease_in_tx( $pid, $location_id, $qty, 'venta', $ref ? $ref : 'Venta desde stock', $note, $seller_id );
        if ( is_wp_error( $stock_res ) ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'msg' => $stock_res->get_error_message() ) );
        }

        $subtotal = round( $qty * $price, 2 );
        $total    += $subtotal;
        $sale_items[] = array(
            'product_id'   => $pid,
            'product_name' => $p->name,
            'qty'          => $qty,
            'price'        => $price,
            'cost_price'   => (float) $p->cost_price,
            'discount'     => 0,
            'subtotal'     => $subtotal,
        );
    }
    if ( empty( $sale_items ) ) {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( array( 'msg' => __( 'Selecciona al menos un producto.', 'workshop' ) ) );
    }

    $total = round( $total, 2 );
    $data  = array(
        'location_id'     => $location_id,
        'seller_id'       => $seller_id,
        'currency'        => $currency,
        'subtotal'        => $total,
        'discount'        => 0,
        'total'           => $total,
        'payment_method'  => 'cash',
        'cash_amount'     => $total,
        'transfer_amount' => 0,
        'transfer_number' => '',
        'status'          => 'completed',
        'register_id'     => 0, // Sin caja: la venta no depende del cierre de caja.
        'client_ref'      => '',
        'items'           => $sale_items,
    );
    $sale_id = WS_POS::save_sale( $data );
    if ( ! $sale_id ) {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( array( 'msg' => __( 'No se pudo guardar la venta.', 'workshop' ) ) );
    }
    $wpdb->query( 'COMMIT' );

    WS_Stock::undo_push( $location_id, 'Venta múltiple (' . count( $sale_items ) . ' ítems)', $before_stock, WS_Stock::undo_snapshot() );
    ws_log_audit( 'stock_venta', 'movement', 0, array( 'location' => $location_id, 'items' => count( $sale_items ), 'sale_id' => $sale_id ) );
    wp_send_json_success( array( 'sale_id' => $sale_id, 'count' => count( $sale_items ) ) );
}

add_action( 'wp_ajax_ws_stock_batch_transfer', 'ws_ajax_stock_batch_transfer' );
function ws_ajax_stock_batch_transfer() {
    ws_guard( 'stock_transfer' );
    $from = (int) ( $_POST['from_location'] ?? 0 );
    $to   = (int) ( $_POST['to_location'] ?? 0 );
    $items = isset( $_POST['items'] ) ? (array) json_decode( wp_unslash( $_POST['items'] ), true ) : array();
    $note  = sanitize_text_field( $_POST['note'] ?? '' );
    $before = WS_Stock::undo_snapshot();

    $result = WS_Stock::batch_transfer( $from, $to, $items, '', $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    WS_Stock::undo_push( $from, 'Transferencia múltiple (' . (int) $result . ' ítems)', $before, WS_Stock::undo_snapshot() );
    ws_log_audit( 'stock_transfer', 'movement', 0, array( 'from' => $from, 'to' => $to, 'items' => $result ) );
    wp_send_json_success( array( 'count' => $result ) );
}

/* ---------------- Movimiento libre / personalizado ---------------- */

/**
 * Registra un movimiento de stock de TIPO PERSONALIZADO (entrada o salida)
 * con su descripción: el tipo es un texto libre (p.ej. "merma", "ajuste",
 * "devolución de cliente") que queda en el historial como tal. La dirección
 * (entrada/salida) decide si el stock aumenta o disminuye.
 */
add_action( 'wp_ajax_ws_movement_add', 'ws_ajax_movement_add' );
function ws_ajax_movement_add() {
    $direction = sanitize_key( $_POST['direction'] ?? '' );
    if ( ! in_array( $direction, array( 'entrada', 'salida' ), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Dirección de movimiento inválida.', 'workshop' ) ) );
    }
    ws_guard( 'entrada' === $direction ? 'stock_entry' : 'stock_exit' );

    $type  = sanitize_text_field( $_POST['type'] ?? '' );
    $type  = mb_substr( trim( $type ), 0, 30 );
    if ( '' === $type ) {
        $type = 'entrada' === $direction ? 'entrada' : 'salida';
    }

    $product_id  = (int) ( $_POST['product_id'] ?? 0 );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $qty         = (float) ( $_POST['qty'] ?? 0 );
    $ref         = sanitize_text_field( $_POST['reference'] ?? '' );
    $note        = sanitize_text_field( $_POST['note'] ?? '' );

    if ( ! $product_id || ! in_array( $location_id, ws_user_location_ids(), true ) || $qty <= 0 ) {
        wp_send_json_error( array( 'msg' => __( 'Datos incompletos.', 'workshop' ) ) );
    }

    $before = WS_Stock::undo_snapshot();
    $result = ( 'entrada' === $direction )
        ? WS_Stock::increase( $product_id, $location_id, $qty, $type, $ref, $note )
        : WS_Stock::decrease( $product_id, $location_id, $qty, $type, $ref, $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    WS_Stock::undo_push( $location_id, ws_undo_movement_label( $type, $product_id, $qty ), $before, WS_Stock::undo_snapshot() );
    ws_log_audit( 'movement_' . $type, 'movement', $product_id, array( 'location' => $location_id, 'qty' => $qty, 'direction' => $direction ) );
    wp_send_json_success( array( 'qty' => WS_Stock::qty( $product_id, $location_id ) ) );
}

/* ---------------- Venta registrada desde stock (movimientos) ---------------- */

/**
 * Movimiento de tipo "venta": el almacenero registra la venta desde el stock
 * (Movimientos) y la app la guarda TANTO en el historial normal (tipo 'venta')
 * como en las ventas POS, indicando el PV y el vendedor. NO exige caja abierta
 * (register_id = 0): el cuadre se hace por stock, no por el cierre de caja.
 */
add_action( 'wp_ajax_ws_movement_venta', 'ws_ajax_movement_venta' );
function ws_ajax_movement_venta() {
    // Nonce + permiso: venta desde stock está permitida con pos_sell o con
    // stock_exit (el almacenero puede registrar la venta sin caja abierta).
    ws_guard( 'stock_exit', 'pos_sell' );

    $product_id  = (int) ( $_POST['product_id'] ?? 0 );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $seller_id   = (int) ( $_POST['seller_id'] ?? 0 );
    $qty         = (float) ( $_POST['qty'] ?? 0 );
    $price       = (float) ( $_POST['price'] ?? 0 );
    $ref         = sanitize_text_field( $_POST['reference'] ?? '' );
    $note        = sanitize_text_field( $_POST['note'] ?? '' );

    if ( ! $product_id || ! $location_id || $qty <= 0 ) {
        wp_send_json_error( array( 'msg' => __( 'Datos incompletos.', 'workshop' ) ) );
    }
    if ( ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }
    if ( ! $seller_id ) {
        $seller_id = get_current_user_id();
    }

    global $wpdb;
    $p = $wpdb->get_row( $wpdb->prepare( "SELECT name, currency, sale_price, cost_price FROM " . ws_table_name( 'products' ) . " WHERE id=%d", $product_id ) );
    if ( ! $p ) {
        wp_send_json_error( array( 'msg' => __( 'Producto no encontrado.', 'workshop' ) ) );
    }
    if ( $price <= 0 ) {
        $price = (float) $p->sale_price;
    }

    $before_stock = WS_Stock::undo_snapshot();
    $wpdb->query( 'START TRANSACTION' );
    $stock_res = WS_Stock::decrease_in_tx(
        $product_id, $location_id, $qty, 'venta',
        $ref ? $ref : 'Venta desde stock',
        $note, $seller_id
    );
    if ( is_wp_error( $stock_res ) ) {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( array( 'msg' => $stock_res->get_error_message() ) );
    }

    $subtotal = round( $qty * $price, 2 );
    $data = array(
        'location_id'     => $location_id,
        'seller_id'       => $seller_id,
        'currency'        => $p->currency,
        'subtotal'        => $subtotal,
        'discount'        => 0,
        'total'           => $subtotal,
        'payment_method'  => 'cash',
        'cash_amount'     => $subtotal,
        'transfer_amount' => 0,
        'transfer_number' => '',
        'status'          => 'completed',
        'register_id'     => 0, // Sin caja: la venta no depende del cierre de caja.
        'client_ref'      => '',
        'items'           => array( array(
            'product_id'   => $product_id,
            'product_name' => $p->name,
            'qty'          => $qty,
            'price'        => $price,
            'cost_price'   => (float) $p->cost_price,
            'discount'     => 0,
            'subtotal'     => $subtotal,
        ) ),
    );
    $sale_id = WS_POS::save_sale( $data );
    if ( ! $sale_id ) {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( array( 'msg' => __( 'No se pudo guardar la venta.', 'workshop' ) ) );
    }
    $wpdb->query( 'COMMIT' );

    WS_Stock::undo_push( $location_id, 'Venta de ' . ( $p->name ? $p->name : '#' . $product_id ) . ' × ' . number_format_i18n( $qty, 2 ), $before_stock, WS_Stock::undo_snapshot() );
    ws_log_audit( 'stock_venta', 'movement', $product_id, array( 'location' => $location_id, 'qty' => $qty, 'sale_id' => $sale_id ) );
    wp_send_json_success( array( 'qty' => WS_Stock::qty( $product_id, $location_id ), 'sale_id' => $sale_id ) );
}

/* ---------------- Stock list ---------------- */

/**
 * Mapea filas de stock a la respuesta JSON, añadiendo el stock del GRUPO por
 * producto (stock compartido por línea): total sumando las ubicaciones de la
 * línea (padres + hijos) + desglose por ubicación para el tooltip.
 */
function ws_stock_rows_map( $rows, $group = array() ) {
    $out = array();
    foreach ( $rows as $r ) {
        $g = $group[ $r->product_id . ':' . $r->location_id ] ?? null;
        $out[] = array(
            'product_id'    => (int) $r->product_id,
            'location_id'   => (int) $r->location_id,
            'location_name' => $r->location_name ?? '',
            'location_type' => $r->location_type ?? '',
            'location_description' => (string) ( $r->location_description ?? '' ),
            'name'          => $r->name,
            'barcode'       => $r->barcode,
            'image'         => $r->image,
            'gallery'       => $r->gallery ? WS_CRUD::product_gallery( $r ) : array(),
            'qty'           => (float) $r->qty,
            'min_stock'     => (float) $r->min_stock,
            'sale_price'    => (float) $r->sale_price,
            'currency'      => $r->currency,
            'store_visible' => (int) ( $r->store_visible ?? 1 ),
            'pos_visible'   => 1,
            'group_total'   => $g ? (float) $g['total'] : (float) $r->qty,
            'group_parts'   => $g ? $g['parts'] : array( array(
                'id'   => (int) $r->location_id,
                'name' => (string) ( $r->location_name ?? '' ),
                'qty'  => (float) $r->qty,
            ) ),
        );
    }
    return $out;
}

/**
 * Aplica los overrides de visibilidad POR UBICACIÓN Y CANAL a filas ya
 * mapeadas por ws_stock_rows_map (cada fila tiene product_id + location_id).
 * Consulta en lote ws_store_visibility y reescribe store_visible (canal
 * 'store') y pos_visible (canal 'pos'); las filas sin override conservan el
 * valor por defecto que ya trae el mapeo.
 */
function ws_apply_store_visibility_overrides( &$rows ) {
    global $wpdb;
    if ( empty( $rows ) ) {
        return;
    }
    $sv_t    = ws_table_name( 'store_visibility' );
    $sv_ids  = array_values( array_unique( array_map( fn( $r ) => (int) $r['product_id'], $rows ) ) );
    $sv_locs = array_values( array_unique( array_map( fn( $r ) => (int) $r['location_id'], $rows ) ) );
    $sv_map  = array();
    if ( $sv_ids && $sv_locs && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sv_t ) ) === $sv_t ) {
        $id_ph   = implode( ',', array_fill( 0, count( $sv_ids ), '%d' ) );
        $loc_ph  = implode( ',', array_fill( 0, count( $sv_locs ), '%d' ) );
        $sv_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_id, location_id, channel, visible FROM {$sv_t} WHERE entity_type='product' AND entity_id IN ({$id_ph}) AND location_id IN ({$loc_ph})",
            ...array_merge( $sv_ids, $sv_locs )
        ) );
        foreach ( $sv_rows as $svr ) {
            $channel = ( 'pos' === (string) $svr->channel ) ? 'pos' : 'store';
            $sv_map[ $channel . ':' . (int) $svr->entity_id . ':' . (int) $svr->location_id ] = (int) $svr->visible;
        }
    }
    foreach ( $rows as $i => $row ) {
        $key = (int) $row['product_id'] . ':' . (int) $row['location_id'];
        if ( array_key_exists( 'store:' . $key, $sv_map ) ) {
            $rows[ $i ]['store_visible'] = $sv_map[ 'store:' . $key ] ? 1 : 0;
        }
        if ( array_key_exists( 'pos:' . $key, $sv_map ) ) {
            $rows[ $i ]['pos_visible'] = $sv_map[ 'pos:' . $key ] ? 1 : 0;
        }
    }
}

/**
 * Combos como LISTA para el panel de stock: un combo por ubicación con su
 * stock derivado, componentes y visibilidad EFECTIVA por canal (store/pos).
 * Filtra por búsqueda (nombre) y por ubicación; con $low_only=true solo
 * devuelve combos SIN stock (qty derivada <= 0) en esa ubicación — el MISMO
 * criterio que "Solo stock bajo" aplica a los productos con min_stock 0.
 */
function ws_stock_combos_list( $loc_ids, $search = '', $low_only = false ) {
    $loc_names = array();
    foreach ( ws_user_locations() as $l ) {
        $loc_names[ (int) $l->id ] = $l->name;
    }
    $like   = '' !== $search ? mb_strtolower( $search ) : '';
    $combos = array();
    $seen   = array();
    foreach ( $loc_ids as $lid ) {
        foreach ( WS_Combos::catalog_rows( $lid ) as $c ) {
            if ( '' !== $like && false === mb_strpos( mb_strtolower( $c['name'] ), $like ) ) {
                continue;
            }
            if ( $low_only && (float) $c['qty'] > 0 ) {
                continue;
            }
            $cid = (int) $c['combo_id'];
            if ( ! isset( $seen[ $cid ] ) ) {
                $seen[ $cid ] = count( $combos );
                $combos[] = array(
                    'combo_id'      => $cid,
                    'product_id'    => (int) $c['id'], // id negativo = -combo_id
                    'name'          => $c['name'],
                    'image'         => $c['photo'],
                    'sale_price'    => (float) $c['price'],
                    'currency'      => $c['currency'],
                    'is_combo'      => 1,
                    'store_visible' => (int) ( $c['store_visible'] ?? 1 ),
                    'items'         => array_map( function ( $it ) {
                        return array(
                            'product_id' => (int) $it['product_id'],
                            'name'       => $it['name'],
                            'qty'        => (float) $it['qty'],
                        );
                    }, $c['items'] ),
                    'locs' => array(),
                );
            }
            $combos[ $seen[ $cid ] ]['locs'][] = array(
                'location_id'   => (int) $lid,
                'location_name' => $loc_names[ (int) $lid ] ?? '',
                'qty'           => (float) $c['qty'],
                'store_visible' => ws_store_visible( 'combo', $cid, $lid, 'store' ) ? 1 : 0,
                'pos_visible'   => ws_store_visible( 'combo', $cid, $lid, 'pos' ) ? 1 : 0,
            );
        }
    }
    return $combos;
}

add_action( 'wp_ajax_ws_stock_list', 'ws_ajax_stock_list' );
function ws_ajax_stock_list() {
    ws_guard( 'stock_view' );
    $location_id    = (int) ( $_POST['location_id'] ?? 0 );
    $search         = sanitize_text_field( $_POST['search'] ?? '' );
    $low_only       = ! empty( $_POST['low_only'] );
    $include_combos = ! empty( $_POST['include_combos'] );
    $allowed     = ws_user_locations();
    $allowed_ids = array_map( fn( $l ) => (int) $l->id, $allowed );
    $loc_ids = ( $location_id && in_array( $location_id, $allowed_ids, true ) )
        ? array( $location_id )
        : $allowed_ids;

    if ( $low_only ) {
        // "Solo stock bajo" con STOCK DEL GRUPO: el total de las ubicaciones
        // de la línea (padres + hijos, stock compartido) cuenta para el
        // mínimo, no el stock de cada ubicación. Pre-filtro SQL por ubicación
        // (el grupo bajo implica que cada ubicación del grupo está baja, así
        // que es un superconjunto) y luego el filtro definitivo por grupo en
        // PHP con paginación local.
        $pg   = ws_list_paging();
        $rows = WS_Stock::stock_rows( array(
            'location_ids' => $loc_ids,
            'search'       => $search,
            'low_stock'    => 1,
            'orderby'      => $pg['sort'],
            'order'        => $pg['dir'],
        ) );
        $group = WS_Stock::stock_group_info( $rows );
        $rows  = array_values( array_filter( $rows, function ( $r ) use ( $group ) {
            $g = $group[ $r->product_id . ':' . $r->location_id ] ?? null;
            return ( $g ? (float) $g['total'] : (float) $r->qty ) <= (float) $r->min_stock;
        } ) );
        $total       = count( $rows );
        $total_pages = max( 1, (int) ceil( $total / $pg['pageSize'] ) );
        $page        = min( $pg['page'], $total_pages );
        $slice       = array_slice( $rows, ( $page - 1 ) * $pg['pageSize'], $pg['pageSize'] );
        $rows_mapped = ws_stock_rows_map( $slice, $group );
        ws_apply_store_visibility_overrides( $rows_mapped );
        wp_send_json_success( array(
            'rows'     => $rows_mapped,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pg['pageSize'],
            // "Solo stock bajo" también filtra los combos (agotados en esa
            // ubicación): ya no desaparecen todos del tab de Combos.
            'combos'   => ws_stock_combos_list( $loc_ids, $search, true ),
        ) );
    }

    if ( $include_combos ) {
        // Asistente de movimientos: productos + combos activos (stock derivado
        // en la ubicación) en un solo listado, sin paginar (el cliente filtra).
        $rows  = WS_Stock::stock_rows( array(
            'location_ids' => $loc_ids,
            'search'       => $search,
        ) );
        $group = WS_Stock::stock_group_info( $rows );
        $out   = ws_stock_rows_map( $rows, $group );
        $loc   = $location_id ? $location_id : ( $allowed_ids ? $allowed_ids[0] : 0 );
        $loc_desc = '';
        foreach ( $allowed as $l ) {
            if ( (int) $l->id === (int) $loc ) {
                $loc_desc = (string) ( $l->description ?? '' );
                break;
            }
        }
        $like  = '' !== $search ? mb_strtolower( $search ) : '';
        foreach ( WS_Combos::catalog_rows( $loc ) as $c ) {
            if ( '' !== $like && false === mb_strpos( mb_strtolower( $c['name'] ), $like ) ) {
                continue;
            }
            $out[] = array(
                'product_id'    => (int) $c['id'],
                'combo_id'      => (int) $c['combo_id'],
                'location_id'   => $loc,
                'location_name' => '',
                'location_type' => '',
                'location_description' => $loc_desc,
                'name'          => $c['name'],
                'barcode'       => '',
                'image'         => $c['photo'],
                'qty'           => (float) $c['qty'],
                'min_stock'     => 0,
                'sale_price'    => (float) $c['price'],
                'currency'      => $c['currency'],
                'group_total'   => (float) $c['qty'],
                'group_parts'   => array(),
                'is_combo'      => 1,
            );
        }
        wp_send_json_success( array(
            'rows'     => $out,
            'total'    => count( $out ),
            'page'     => 1,
            'pageSize' => count( $out ),
        ) );
    }

    // Listado normal: productos reales paginados + combos activos como TARJETAS
    // (el combo es un producto que contiene varios productos: se muestra como un
    // solo card con su imagen, sus componentes y su stock por ubicación).
    $pg   = ws_list_paging();
    $rows = WS_Stock::stock_rows( array(
        'location_ids' => $loc_ids,
        'search'       => $search,
        'orderby'      => $pg['sort'],
        'order'        => $pg['dir'],
    ) );
    $group = WS_Stock::stock_group_info( $rows );
    $out   = ws_stock_rows_map( $rows, $group );

    $total       = count( $out );
    $total_pages = max( 1, (int) ceil( $total / $pg['pageSize'] ) );
    $page        = min( $pg['page'], $total_pages );
    $slice       = array_slice( $out, ( $page - 1 ) * $pg['pageSize'], $pg['pageSize'] );

    // Visibilidad EFECTIVA POR UBICACIÓN en las filas de esta página (los
    // overrides de ws_store_visibility mandan sobre el flag global).
    ws_apply_store_visibility_overrides( $slice );

    // Combos: un combo por ubicación con su stock DERIVADO (locs) y sus
    // componentes (items). Filtra por búsqueda y ubicación igual que los
    // productos (sin paginar, se agrupan por combo_id).
    $combos = ws_stock_combos_list( $loc_ids, $search );

    wp_send_json_success( array(
        'rows'     => $slice,
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pg['pageSize'],
        'combos'   => $combos,
    ) );
}

/* ---------------- Movimientos ---------------- */

add_action( 'wp_ajax_ws_movements_list', 'ws_ajax_movements_list' );
function ws_ajax_movements_list() {
    ws_guard( 'movements_view' );
    $type      = sanitize_key( $_POST['type'] ?? '' );
    $location  = (int) ( $_POST['location_id'] ?? 0 );
    $search    = sanitize_text_field( $_POST['search'] ?? '' );
    $scope     = sanitize_key( $_POST['scope'] ?? '' );
    if ( ! in_array( $scope, array( '', 'products', 'combos' ), true ) ) {
        $scope = '';
    }
    $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );
    $date_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ? $date_from : '';
    $date_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ? $date_to : '';
    if ( $date_from && $date_to && $date_from > $date_to ) {
        $tmp       = $date_from;
        $date_from = $date_to;
        $date_to   = $tmp;
    }
    $loc_ids   = array_map( fn( $l ) => (int) $l->id, ws_user_locations() );
    $loc_ids   = ( $location && in_array( $location, $loc_ids, true ) ) ? array( $location ) : $loc_ids;

    ws_send_list( 'movements', function ( $args ) use ( $type, $loc_ids, $search, $date_from, $date_to, $scope ) {
        $rows = WS_Stock::movements( array_merge( array(
            'type'         => $type,
            'location_ids' => $loc_ids,
            'search'       => $search,
            'scope'        => $scope,
            'from'         => $date_from,
            'to'           => $date_to,
        ), $args ) );
        $out = array();
        foreach ( $rows as $m ) {
            $reverted = (bool) $m->reverted_at;
            $out[] = array(
                'id'              => (int) $m->id,
                'type'            => $m->type,
                'product_name'    => $m->product_name,
                'combo_name'      => (string) ( $m->combo_name ?? '' ),
                'combo_id'        => (int) $m->combo_id,
                'location_name'   => $m->location_name ?? '',
                'dest_location_id'=> (int) $m->dest_location_id,
                'dest_name'       => $m->dest_name ?? '',
                'qty'             => (float) $m->qty,
                'reference'       => $m->reference,
                'note'            => (string) ( $m->note ?? '' ),
                'user_name'       => $m->user_name,
                'date'            => mysql2date( 'd/m/Y H:i', $m->created_at ),
                // Revertir: los movimientos de tipo conocido y no revertidos
                // (los 'revert' y los ya revertidos no se pueden volver a
                // revertir; los tipos personalizados no guardan dirección).
                'reverted'        => $reverted,
                'revertable'      => ! $reverted
                    && in_array( $m->type, array( 'entrada', 'salida', 'baja', 'venta', 'pedido', 'transferencia' ), true ),
                'revert_of'       => (int) $m->revert_of,
            );
        }
        return $out;
    }, function () use ( $type, $loc_ids, $search, $date_from, $date_to, $scope ) {
        return WS_Stock::count_movements( array(
            'type'         => $type,
            'location_ids' => $loc_ids,
            'search'       => $search,
            'scope'        => $scope,
            'from'         => $date_from,
            'to'           => $date_to,
        ) );
    }, array() );
}

/* ---------------- Pedidos ---------------- */

add_action( 'wp_ajax_ws_order_accept', 'ws_ajax_order_accept' );
function ws_ajax_order_accept() {
    ws_guard( 'orders_accept' );
    $id = (int) ( $_POST['id'] ?? 0 );
    $order = WS_Orders::get( $id );
    if ( ! $order || ! in_array( (int) $order->location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Pedido no disponible.', 'workshop' ) ) );
    }
    $result = WS_Orders::accept( $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_notify( 'order_accepted', $id );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_order_reject', 'ws_ajax_order_reject' );
function ws_ajax_order_reject() {
    ws_guard( 'orders_accept' );
    $id = (int) ( $_POST['id'] ?? 0 );
    $order = WS_Orders::get( $id );
    if ( ! $order || ! in_array( (int) $order->location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Pedido no disponible.', 'workshop' ) ) );
    }
    $result = WS_Orders::reject( $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_order_complete', 'ws_ajax_order_complete' );
function ws_ajax_order_complete() {
    ws_guard( 'orders_accept' );
    $id = (int) ( $_POST['id'] ?? 0 );
    $order = WS_Orders::get( $id );
    if ( ! $order || ! in_array( (int) $order->location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Pedido no disponible.', 'workshop' ) ) );
    }
    $result = WS_Orders::complete( $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_order_cancel', 'ws_ajax_order_cancel' );
function ws_ajax_order_cancel() {
    ws_guard( 'orders_accept' );
    $id = (int) ( $_POST['id'] ?? 0 );
    $order = WS_Orders::get( $id );
    if ( ! $order || ! in_array( (int) $order->location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Pedido no disponible.', 'workshop' ) ) );
    }
    $result = WS_Orders::cancel( $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    wp_send_json_success();
}

/* ---------------- Pedido público ---------------- */

add_action( 'wp_ajax_ws_order_list', 'ws_ajax_order_list' );
function ws_ajax_order_list() {
    ws_guard( 'orders_view' );
    $status    = sanitize_key( $_POST['status'] ?? '' );
    $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );
    $date_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ? $date_from : '';
    $date_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ? $date_to : '';
    if ( $date_from && $date_to && $date_from > $date_to ) {
        $tmp       = $date_from;
        $date_from = $date_to;
        $date_to   = $tmp;
    }
    $loc_ids = array_map( fn( $l ) => (int) $l->id, ws_user_locations() );

    ws_send_list( 'orders', function ( $args ) use ( $status, $date_from, $date_to, $loc_ids ) {
        $rows = WS_Orders::all( array_merge( array(
            'location_ids' => $loc_ids,
            'status'       => $status,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
        ), $args ) );
        $out = array();
        foreach ( $rows as $o ) {
            $out[] = array(
                'id'              => (int) $o->id,
                'number'          => $o->number,
                'location_name'   => $o->location_name,
                'customer_name'   => $o->customer_name,
                'customer_phone'  => $o->customer_phone,
                'customer_address'=> $o->customer_address,
                'subtotal'        => (float) $o->subtotal,
                'delivery_cost'   => (float) $o->delivery_cost,
                'delivery_currency' => $o->delivery_currency ? $o->delivery_currency : $o->currency,
                'total'           => (float) $o->total,
                'currency'        => $o->currency,
                'status'          => $o->status,
                'date'            => mysql2date( 'd/m/Y H:i', $o->created_at ),
            );
        }
        return $out;
    }, function () use ( $status, $date_from, $date_to, $loc_ids ) {
        return WS_Orders::count_all( array( 'location_ids' => $loc_ids, 'status' => $status, 'date_from' => $date_from, 'date_to' => $date_to ) );
    }, array() );
}

add_action( 'wp_ajax_ws_order_detail', 'ws_ajax_order_detail' );
function ws_ajax_order_detail() {
    ws_guard( 'orders_view' );
    $id    = (int) ( $_POST['id'] ?? 0 );
    $order = WS_Orders::get( $id );
    if ( ! $order ) {
        wp_send_json_error( array( 'msg' => __( 'Pedido no encontrado.', 'workshop' ) ) );
    }
    // Solo pedidos de las ubicaciones asignadas al trabajador.
    if ( ! in_array( (int) $order->location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Pedido no disponible.', 'workshop' ) ) );
    }
    $items = array();
    foreach ( WS_Orders::get_items( $id ) as $it ) {
        $items[] = array(
            'product_id'   => (int) $it->product_id,
            'product_name' => $it->product_name,
            'qty'          => (float) $it->qty,
            'price'        => (float) $it->price,
        );
    }
    $order->items = $items;
    wp_send_json_success( array( 'order' => $order ) );
}

add_action( 'wp_ajax_nopriv_ws_create_order', 'ws_ajax_create_order' );
add_action( 'wp_ajax_ws_create_order', 'ws_ajax_create_order' );
function ws_ajax_create_order() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    // Ítems: formato nuevo (JSON de {product_id, combo_id, qty}) o el clásico
    // items[product_id]=qty. Los combos llevan combo_id>0 (id negativo en la
    // tienda para no chocar con productos reales).
    $items = array();
    if ( isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) {
        $decoded = json_decode( wp_unslash( $_POST['items'] ), true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $raw ) {
                if ( ! is_array( $raw ) ) {
                    continue;
                }
                $pid = (int) ( $raw['product_id'] ?? 0 );
                $cid = (int) ( $raw['combo_id'] ?? 0 );
                $qty = (float) ( $raw['qty'] ?? 0 );
                if ( ( $pid || $cid ) && $qty > 0 ) {
                    $items[] = array( 'product_id' => $pid, 'combo_id' => $cid, 'qty' => $qty );
                }
            }
        }
    } elseif ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
        foreach ( $_POST['items'] as $pid => $qty ) {
            $pid = (int) $pid;
            $qty = (float) $qty;
            if ( $pid && $qty > 0 ) {
                $items[] = array( 'product_id' => $pid, 'combo_id' => 0, 'qty' => $qty );
            }
        }
    }
    $customer    = array(
        'name'    => sanitize_text_field( $_POST['customer_name'] ?? '' ),
        'phone'   => sanitize_text_field( $_POST['customer_phone'] ?? '' ),
        'address' => sanitize_text_field( $_POST['customer_address'] ?? '' ),
    );
    if ( empty( $customer['name'] ) || empty( $customer['phone'] ) ) {
        wp_send_json_error( array( 'msg' => __( 'Nombre y teléfono son obligatorios.', 'workshop' ) ) );
    }
    if ( empty( $items ) ) {
        wp_send_json_error( array( 'msg' => __( 'El pedido está vacío.', 'workshop' ) ) );
    }
    $order_id = WS_Orders::create( $location_id, $items, $customer );
    if ( is_wp_error( $order_id ) ) {
        wp_send_json_error( array( 'msg' => $order_id->get_error_message() ) );
    }
    $order = WS_Orders::get( $order_id );
    $loc   = WS_CRUD::get_location( $location_id );
    ws_notify( 'order_new', $order_id );
    // El cliente puede elegir entre varios números de WhatsApp (dropdown).
    $wa_override = sanitize_text_field( $_POST['whatsapp_number'] ?? '' );
    wp_send_json_success( array(
        'id'           => $order_id,
        'whatsapp_url' => $loc ? ws_whatsapp_order_url( $order, $loc, $wa_override ) : '',
    ) );
}

add_action( 'wp_ajax_nopriv_ws_public_order_status', 'ws_ajax_public_order_status' );
add_action( 'wp_ajax_ws_public_order_status', 'ws_ajax_public_order_status' );
function ws_ajax_public_order_status() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $number = sanitize_text_field( $_POST['number'] ?? '' );
    $phone  = sanitize_text_field( $_POST['phone'] ?? '' );
    if ( '' === $number || '' === $phone ) {
        wp_send_json_error( array( 'msg' => __( 'Número de pedido y teléfono son obligatorios.', 'workshop' ) ) );
    }
    // Normaliza ambos lados: sin espacios/guiones, mayúsculas para el número.
    $number_key = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $number ) );
    $phone_key  = preg_replace( '/[^0-9]/', '', $phone );

    global $wpdb;
    $table = ws_table_name( 'orders' );
    $order = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE REPLACE(REPLACE(REPLACE(number, '-', ''), ' ', ''), '(', '') = %s LIMIT 1",
        $number_key
    ) );
    if ( ! $order ) {
        wp_send_json_error( array( 'msg' => __( 'No encontramos un pedido con esos datos.', 'workshop' ) ) );
    }
    // El teléfono se compara normalizado en PHP para tolerar guiones/espacios
    // distintos a los que se guardaron al crear el pedido.
    if ( preg_replace( '/[^0-9]/', '', $order->customer_phone ) !== $phone_key ) {
        wp_send_json_error( array( 'msg' => __( 'No encontramos un pedido con esos datos.', 'workshop' ) ) );
    }
    $items = array();
    foreach ( WS_Orders::get_items( $order->id ) as $it ) {
        $items[] = array(
            'product_name' => $it->product_name,
            'qty'          => (float) $it->qty,
            'price'        => (float) $it->price,
        );
    }
    wp_send_json_success( array( 'order' => array(
        'number'       => $order->number,
        'status'       => $order->status,
        'status_label' => WS_Orders::status_label( $order->status ),
        'customer_name'=> $order->customer_name,
        'currency'     => $order->currency,
        'subtotal'     => (float) $order->subtotal,
        'delivery_cost'=> (float) $order->delivery_cost,
        'delivery_currency' => $order->delivery_currency ? $order->delivery_currency : $order->currency,
        'total'        => (float) $order->total,
        'date'         => mysql2date( 'd/m/Y H:i', $order->created_at ),
        'items'        => $items,
    ) ) );
}

add_action( 'wp_ajax_nopriv_ws_store_products', 'ws_ajax_store_products' );
add_action( 'wp_ajax_ws_store_products', 'ws_ajax_store_products' );
function ws_ajax_store_products() {
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $search      = sanitize_text_field( $_POST['search'] ?? '' );
    // Solo productos VISIBLES EN LA TIENDA DE ESTA UBICACIÓN (pueden existir
    // en el inventario y estar ocultos del catálogo público). La visibilidad
    // es POR UBICACIÓN: el override del ojito (ws_store_visibility) manda
    // sobre el flag global, igual que en store.php.
    $rows        = array_values( array_filter(
        WS_Stock::stock_rows( array( 'location_id' => $location_id, 'search' => $search ) ),
        function ( $r ) use ( $location_id ) {
            return ws_store_visible( 'product', (int) ( $r->product_id ?? 0 ), (int) $location_id, 'store' );
        }
    ) );
    $products    = array();
    foreach ( $rows as $r ) {
        $products[] = array(
            'id'           => (int) $r->product_id,
            'name'         => $r->name,
            'barcode'      => $r->barcode,
            'image'        => $r->image,
            'gallery'      => $r->gallery ? WS_CRUD::product_gallery( $r ) : array(),
            'description'  => $r->description ?? '',
            'price'        => (float) $r->sale_price,
            'transfer_pct' => (float) $r->transfer_pct,
            'currency'     => $r->currency,
            'show_equiv'   => (int) ( $r->show_equiv ?? 1 ),
            'qty'          => (float) $r->qty,
        );
    }
    wp_send_json_success( array( 'products' => $products ) );
}

/* ---------------- Turnos ---------------- */

add_action( 'wp_ajax_ws_shifts_list', 'ws_ajax_shifts_list' );
function ws_ajax_shifts_list() {
    ws_guard( 'shifts_view' );
    $start = sanitize_text_field( $_POST['start'] ?? '' );
    $end   = sanitize_text_field( $_POST['end'] ?? '' );
    $loc   = (int) ( $_POST['location_id'] ?? 0 );
    if ( ! $start || ! $end ) {
        wp_send_json_error( array( 'msg' => 'Rango inválido.' ) );
    }
    $allowed = array_map( fn( $l ) => (int) $l->id, ws_user_locations() );
    $rows    = WS_Shifts::for_range( $start, $end );
    $rows    = array_values( array_filter( $rows, fn( $s ) => in_array( (int) $s->location_id, $allowed, true ) ) );
    if ( $loc && ! in_array( $loc, $allowed, true ) ) {
        wp_send_json_success( array( 'shifts' => array() ) );
    }
    if ( $loc ) {
        $rows = array_values( array_filter( $rows, fn( $s ) => (int) $s->location_id === $loc ) );
    }
    $out = array();
    foreach ( $rows as $s ) {
        $out[] = array(
            'id'          => (int) $s->id,
            'title'       => $s->user_name . ' · ' . $s->location_name,
            'start'       => $s->shift_date . 'T' . $s->time_start,
            'end'         => $s->shift_date . 'T' . $s->time_end,
            'location_id' => (int) $s->location_id,
            'user_id'     => (int) $s->user_id,
            'shift_date'  => $s->shift_date,
            'time_start'  => $s->time_start,
            'time_end'    => $s->time_end,
            'note'        => $s->note,
        );
    }
    wp_send_json_success( array( 'shifts' => $out ) );
}

add_action( 'wp_ajax_ws_save_shift', 'ws_ajax_save_shift' );
function ws_ajax_save_shift() {
    ws_guard( 'shifts_manage' );
    $id = (int) ( $_POST['id'] ?? 0 );
    $result = WS_Shifts::save( $_POST, $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( $id ? 'shift_update' : 'shift_create', 'shift', $result );
    wp_send_json_success( array( 'id' => $result ) );
}

add_action( 'wp_ajax_ws_delete_shift', 'ws_ajax_delete_shift' );
function ws_ajax_delete_shift() {
    ws_guard( 'shifts_manage' );
    WS_Shifts::delete( (int) ( $_POST['id'] ?? 0 ) );
    ws_log_audit( 'shift_delete', 'shift', (int) ( $_POST['id'] ?? 0 ) );
    wp_send_json_success();
}

/* ---------------- Trabajadores ---------------- */

add_action( 'wp_ajax_ws_save_worker', 'ws_ajax_save_worker' );
function ws_ajax_save_worker() {
    ws_guard( 'workers_manage' );
    $user_id = (int) ( $_POST['user_id'] ?? 0 );
    $role    = sanitize_key( $_POST['role'] ?? '' );
    if ( ! in_array( $role, array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Rol inválido.', 'workshop' ) ) );
    }
    // Solo el administrador del sistema puede crear/asignar dueños.
    if ( 'ws_owner' === $role && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Solo el administrador del sitio puede asignar el rol de dueño.', 'workshop' ) ) );
    }
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        wp_send_json_error( array( 'msg' => __( 'Trabajador no encontrado.', 'workshop' ) ) );
    }
    // Límite de usuarios: solo cuenta si el usuario aún no es miembro del negocio.
    $has_biz_role = array_intersect( array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ), (array) $user->roles );
    if ( ! $has_biz_role ) {
        $limit = ws_plan_guard( 'users' );
        if ( is_wp_error( $limit ) ) {
            wp_send_json_error( array( 'msg' => $limit->get_error_message() ) );
        }
    }
    // Solo el administrador puede modificar el rol de un dueño.
    if ( ! current_user_can( 'manage_options' ) && in_array( 'ws_owner', (array) $user->roles, true ) ) {
        wp_send_json_error( array( 'msg' => __( 'No puedes modificar el rol de un dueño.', 'workshop' ) ) );
    }
    // Un trabajador solo puede operar sobre miembros de su negocio (o admin).
    if ( ! current_user_can( 'manage_options' ) ) {
        $biz  = ws_current_business();
        $ubiz = ws_user_business( $user_id );
        if ( (int) $ubiz->id !== (int) $biz->id ) {
            wp_send_json_error( array( 'msg' => __( 'El trabajador no pertenece a este negocio.', 'workshop' ) ) );
        }
    }
    // No dejar el negocio sin dueño al quitar el rol (solo aplica si el
    // usuario actual ES dueño y podría dejar vacío su propio negocio).
    if ( in_array( 'ws_owner', (array) $user->roles, true ) && 'ws_owner' !== $role ) {
        $owners = ws_business_owners_count( $user_id );
        if ( $owners <= 1 ) {
            wp_send_json_error( array( 'msg' => __( 'No puedes cambiar el rol del último dueño del negocio.', 'workshop' ) ) );
        }
    }
    foreach ( array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ) as $r ) {
        if ( $r !== $role && in_array( $r, (array) $user->roles, true ) ) {
            $user->remove_role( $r );
        }
    }
    $user->add_role( $role );
    update_user_meta( $user_id, 'ws_business_id', ws_current_business_id() );
    WS_CRUD::set_worker_locations( $user_id, (array) ( $_POST['locations'] ?? array() ) );
    ws_log_audit( 'worker_update', 'user', $user_id, array( 'role' => $role ) );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_save_worker_user', 'ws_ajax_save_worker_user' );
function ws_ajax_save_worker_user() {
    ws_guard( 'workers_manage' );
    $email    = sanitize_email( $_POST['email'] ?? '' );
    $username = sanitize_user( $_POST['username'] ?? '' );
    $pass     = (string) ( $_POST['password'] ?? '' );
    $role     = sanitize_key( $_POST['role'] ?? '' );
    $name     = sanitize_text_field( $_POST['display_name'] ?? '' );
    if ( ! in_array( $role, array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Rol inválido.', 'workshop' ) ) );
    }
    // Solo el administrador del sistema puede crear dueños.
    if ( 'ws_owner' === $role && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Solo el administrador del sitio puede crear dueños.', 'workshop' ) ) );
    }
    if ( empty( $username ) || empty( $email ) || empty( $pass ) ) {
        wp_send_json_error( array( 'msg' => __( 'Usuario, email y contraseña son obligatorios.', 'workshop' ) ) );
    }
    if ( username_exists( $username ) || email_exists( $email ) ) {
        wp_send_json_error( array( 'msg' => __( 'El usuario o email ya existe.', 'workshop' ) ) );
    }
    // Límite de usuarios del plan al crear un trabajador nuevo.
    $limit = ws_plan_guard( 'users' );
    if ( is_wp_error( $limit ) ) {
        wp_send_json_error( array( 'msg' => $limit->get_error_message() ) );
    }
    $user_id = wp_insert_user( array(
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => $pass,
        'display_name' => $name ? $name : $username,
        'role'         => $role,
    ) );
    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( array( 'msg' => $user_id->get_error_message() ) );
    }
    update_user_meta( $user_id, 'ws_business_id', ws_current_business_id() );
    WS_CRUD::set_worker_locations( $user_id, (array) ( $_POST['locations'] ?? array() ) );
    ws_log_audit( 'worker_create', 'user', $user_id, array( 'role' => $role ) );
    wp_send_json_success( array( 'id' => $user_id ) );
}

add_action( 'wp_ajax_ws_update_worker', 'ws_ajax_update_worker' );
function ws_ajax_update_worker() {
    ws_guard( 'workers_manage' );
    $user_id = (int) ( $_POST['user_id'] ?? 0 );
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        wp_send_json_error( array( 'msg' => __( 'Trabajador no encontrado.', 'workshop' ) ) );
    }
    // Un trabajador solo puede operar sobre miembros de su negocio (o admin).
    if ( ! current_user_can( 'manage_options' ) ) {
        $biz  = ws_current_business();
        $ubiz = ws_user_business( $user_id );
        if ( (int) $ubiz->id !== (int) $biz->id ) {
            wp_send_json_error( array( 'msg' => __( 'El trabajador no pertenece a este negocio.', 'workshop' ) ) );
        }
    }

    $role = sanitize_key( $_POST['role'] ?? '' );
    if ( ! in_array( $role, array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Rol inválido.', 'workshop' ) ) );
    }
    // Límite de usuarios: solo cuenta si el usuario aún no es miembro del negocio.
    $has_biz_role = array_intersect( array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ), (array) $user->roles );
    if ( ! $has_biz_role ) {
        $limit = ws_plan_guard( 'users' );
        if ( is_wp_error( $limit ) ) {
            wp_send_json_error( array( 'msg' => $limit->get_error_message() ) );
        }
    }
    // Solo el administrador del sistema puede asignar dueños.
    if ( 'ws_owner' === $role && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Solo el administrador del sitio puede asignar el rol de dueño.', 'workshop' ) ) );
    }
    // Un trabajador no puede modificar el rol de un dueño existente.
    if ( ! current_user_can( 'manage_options' ) && in_array( 'ws_owner', (array) $user->roles, true ) ) {
        wp_send_json_error( array( 'msg' => __( 'No puedes modificar el rol de un dueño.', 'workshop' ) ) );
    }

    $name  = sanitize_text_field( $_POST['display_name'] ?? '' );
    if ( '' === $name ) {
        wp_send_json_error( array( 'msg' => __( 'El nombre es obligatorio.', 'workshop' ) ) );
    }
    // Email opcional: la app móvil no lo envía al editar; solo se valida y
    // actualiza cuando viene relleno (la web sí lo incluye).
    $email = sanitize_email( $_POST['email'] ?? '' );
    if ( '' !== $email && ! is_email( $email ) ) {
        wp_send_json_error( array( 'msg' => __( 'Email inválido.', 'workshop' ) ) );
    }
    $existing = $email ? email_exists( $email ) : false;
    if ( $existing && (int) $existing !== $user_id ) {
        wp_send_json_error( array( 'msg' => __( 'Ese email ya está en uso.', 'workshop' ) ) );
    }

    // No dejar el negocio sin dueño al cambiar el rol (antes de persistir nada).
    if ( in_array( 'ws_owner', (array) $user->roles, true ) && 'ws_owner' !== $role ) {
        $owners = ws_business_owners_count( $user_id );
        if ( $owners <= 1 ) {
            wp_send_json_error( array( 'msg' => __( 'No puedes cambiar el rol del último dueño del negocio.', 'workshop' ) ) );
        }
    }

    $update = array(
        'ID'           => $user_id,
        'display_name' => $name,
    );
    if ( '' !== $email ) {
        $update['user_email'] = $email;
    }
    $pass = (string) ( $_POST['password'] ?? '' );
    if ( '' !== $pass ) {
        if ( strlen( $pass ) < 8 ) {
            wp_send_json_error( array( 'msg' => __( 'La contraseña debe tener al menos 8 caracteres.', 'workshop' ) ) );
        }
        $update['user_pass'] = $pass;
    }
    $result = wp_update_user( $update );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }

    foreach ( array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ) as $r ) {
        if ( $r !== $role && in_array( $r, (array) $user->roles, true ) ) {
            $user->remove_role( $r );
        }
    }
    $user->add_role( $role );
    update_user_meta( $user_id, 'ws_business_id', ws_current_business_id() );
    WS_CRUD::set_worker_locations( $user_id, (array) ( $_POST['locations'] ?? array() ) );
    ws_log_audit( 'worker_update', 'user', $user_id, array( 'role' => $role ) );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_delete_worker', 'ws_ajax_delete_worker' );
function ws_ajax_delete_worker() {
    ws_guard( 'workers_manage' );
    $user_id = (int) ( $_POST['user_id'] ?? 0 );
    if ( ! $user_id || $user_id === get_current_user_id() ) {
        wp_send_json_error( array( 'msg' => __( 'No puedes eliminar tu propia cuenta.', 'workshop' ) ) );
    }
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        wp_send_json_error( array( 'msg' => __( 'Trabajador no encontrado.', 'workshop' ) ) );
    }
    // Un trabajador solo puede eliminar a miembros de su negocio (o admin).
    if ( ! current_user_can( 'manage_options' ) ) {
        $biz  = ws_current_business();
        $ubiz = ws_user_business( $user_id );
        if ( (int) $ubiz->id !== (int) $biz->id ) {
            wp_send_json_error( array( 'msg' => __( 'El trabajador no pertenece a este negocio.', 'workshop' ) ) );
        }
    }
    // No permitir dejar el negocio sin dueño.
    if ( in_array( 'ws_owner', (array) $user->roles, true ) ) {
        $owners = ws_business_owners_count( $user_id );
        if ( $owners <= 1 ) {
            wp_send_json_error( array( 'msg' => __( 'No puedes eliminar al último dueño del negocio.', 'workshop' ) ) );
        }
    }
    // wp_delete_user puede fallar en algunos entornos; borrado SQL directo
    // (mismo patrón que el script de limpieza de datos de prueba).
    global $wpdb;
    $p = $wpdb->prefix;
    $wpdb->delete( ws_table_name( 'user_locations' ), array( 'user_id' => $user_id ) );
    $wpdb->delete( $p . 'usermeta', array( 'user_id' => $user_id ) );
    $wpdb->delete( $p . 'users', array( 'ID' => $user_id ) );
    clean_user_cache( $user );
    ws_log_audit( 'worker_delete', 'user', $user_id );
    wp_send_json_success();
}

/* ---------------- Sesiones de trabajo ---------------- */

// Cerrar la sesión de trabajo de un trabajador (el dueño desde el panel).
add_action( 'wp_ajax_ws_session_close', 'ws_ajax_session_close' );
function ws_ajax_session_close() {
    ws_guard( 'workers_manage' );
    $id = (int) ( $_POST['session_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    $session = WS_Sessions::get( $id );
    if ( ! $session ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión no encontrada.', 'workshop' ) ) );
    }
    // Solo cerrar sesiones de trabajadores del propio negocio.
    if ( ! current_user_can( 'manage_options' ) && ! ws_user_belongs_to_business( $session->user_id ) ) {
        wp_send_json_error( array( 'msg' => __( 'El trabajador no pertenece a este negocio.', 'workshop' ) ) );
    }
    $res = WS_Sessions::end( $id, get_current_user_id(), __( 'Cerrada por el encargado', 'workshop' ) );
    if ( is_wp_error( $res ) ) {
        wp_send_json_error( array( 'msg' => $res->get_error_message() ) );
    }
    ws_log_audit( 'session_close', 'work_session', $id, array( 'user_id' => (int) $session->user_id ) );
    wp_send_json_success();
}

// Deshabilitar/habilitar un trabajador: al deshabilitarlo se cierran sus
// sesiones abiertas y se expulsan sus sesiones de WordPress activas.
add_action( 'wp_ajax_ws_worker_set_disabled', 'ws_ajax_worker_set_disabled' );
function ws_ajax_worker_set_disabled() {
    ws_guard( 'workers_manage' );
    $user_id  = (int) ( $_POST['user_id'] ?? 0 );
    $disabled = ! empty( $_POST['disabled'] );
    if ( ! $user_id || $user_id === get_current_user_id() ) {
        wp_send_json_error( array( 'msg' => __( 'No puedes deshabilitar tu propia cuenta.', 'workshop' ) ) );
    }
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        wp_send_json_error( array( 'msg' => __( 'Trabajador no encontrado.', 'workshop' ) ) );
    }
    // Un trabajador solo puede operar sobre miembros de su negocio (o admin).
    if ( ! current_user_can( 'manage_options' ) && ! ws_user_belongs_to_business( $user_id ) ) {
        wp_send_json_error( array( 'msg' => __( 'El trabajador no pertenece a este negocio.', 'workshop' ) ) );
    }
    // No se deshabilitan dueños: solo trabajadores (almacenero/vendedor).
    if ( in_array( 'ws_owner', (array) $user->roles, true ) ) {
        wp_send_json_error( array( 'msg' => __( 'No puedes deshabilitar al dueño del negocio.', 'workshop' ) ) );
    }
    if ( $disabled ) {
        update_user_meta( $user_id, 'ws_disabled', 1 );
        WS_Sessions::close_all_open( $user_id, get_current_user_id(), __( 'Cuenta deshabilitada', 'workshop' ) );
        // Expulsa las sesiones de WordPress activas: no puede seguir en el panel.
        if ( class_exists( 'WP_Session_Tokens' ) ) {
            WP_Session_Tokens::get_instance( $user_id )->destroy_all();
        }
        ws_log_audit( 'worker_disable', 'user', $user_id );
    } else {
        delete_user_meta( $user_id, 'ws_disabled' );
        ws_log_audit( 'worker_enable', 'user', $user_id );
    }
    wp_send_json_success();
}

// Listado de trabajadores con paginación/orden server-side.
add_action( 'wp_ajax_ws_workers_list', 'ws_ajax_workers_list' );
function ws_ajax_workers_list() {
    ws_guard( 'workers_manage' );
    $search = sanitize_text_field( $_POST['search'] ?? '' );
    // Trabajadores con permisos: solo ven a los de sus mismas ubicaciones.
    $is_full = in_array( ws_user_role(), array( 'owner', '' ), true );
    $my_ids  = ws_user_location_ids();
    ws_send_list( 'workers', function ( $args ) use ( $search, $is_full, $my_ids ) {
        $all = array_filter( WS_CRUD::get_workers_matching( $search ), function ( $w ) use ( $is_full, $my_ids ) {
            if ( $is_full ) {
                return true;
            }
            $w_ids = array_map( 'intval', wp_list_pluck( WS_CRUD::get_user_locations( $w->ID ), 'id' ) );
            return ! empty( array_intersect( $w_ids, $my_ids ) );
        } );
        // Orden: display_name o user_email (el resto cae a display_name).
        $dir = ( ( $args['order'] ?? 'ASC' ) === 'DESC' ) ? -1 : 1;
        usort( $all, function ( $a, $b ) use ( $args, $dir ) {
            $va = (string) $a->display_name;
            $vb = (string) $b->display_name;
            if ( 'user_email' === ( $args['orderby'] ?? '' ) ) {
                $va = (string) $a->user_email;
                $vb = (string) $b->user_email;
            }
            return $dir * strcasecmp( $va, $vb );
        } );
        if ( ! empty( $args['limit'] ) ) {
            $all = array_slice( $all, (int) ( $args['offset'] ?? 0 ), (int) $args['limit'] );
        }
        $threshold = strtotime( '-30 days' );
        $out = array();
        foreach ( $all as $w ) {
            $last    = get_user_meta( $w->ID, 'ws_last_login', true );
            $last_ts = $last ? strtotime( $last ) : 0;
            $role    = '';
            foreach ( array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ) as $r ) {
                if ( in_array( $r, (array) $w->roles, true ) ) {
                    $role = $r;
                    break;
                }
            }
            $wlocs = WS_CRUD::get_user_locations( $w->ID );
            $out[] = array(
                'id'              => (int) $w->ID,
                'display_name'    => $w->display_name,
                'user_email'      => $w->user_email,
                'role'            => $role,
                'locations'       => array_map( fn( $l ) => array( 'id' => (int) $l->id, 'name' => $l->name ), $wlocs ),
                'is_disabled'     => ws_worker_disabled( $w->ID ) ? 1 : 0,
                'is_active'       => ( $last_ts && $last_ts >= $threshold ) ? 1 : 0,
                'last_login_text' => $last_ts ? wp_date( 'd/m/Y H:i', $last_ts ) : '',
            );
        }
        return $out;
    }, function () use ( $search, $is_full, $my_ids ) {
        if ( ! $is_full ) {
            return count( array_filter( WS_CRUD::get_workers_matching( $search ), function ( $w ) use ( $my_ids ) {
                $w_ids = array_map( 'intval', wp_list_pluck( WS_CRUD::get_user_locations( $w->ID ), 'id' ) );
                return ! empty( array_intersect( $w_ids, $my_ids ) );
            } ) );
        }
        return count( WS_CRUD::get_workers_matching( $search ) );
    }, array( 'search' => $search ) );
}

/* ---------------- Permisos y configuración ---------------- */

add_action( 'wp_ajax_ws_save_permissions', 'ws_ajax_save_permissions' );
function ws_ajax_save_permissions() {
    ws_guard( 'permissions_manage' );
    $raw = isset( $_POST['matrix'] ) ? $_POST['matrix'] : array();
    $all  = array_keys( WS_Capabilities::all_caps() );
    $roles = array( 'owner', 'storekeeper', 'seller' );
    if ( is_string( $raw ) ) {
        // Formato app (JSON): la app manda solo los módulos que tiene. Se
        // combina con la matriz existente para NO pisar los permisos web-only.
        $posted = (array) json_decode( wp_unslash( $raw ), true );
        $existing = WS_Capabilities::matrix();
        $merged = array();
        foreach ( $roles as $role ) {
            $merged[ $role ] = array();
            foreach ( $all as $cap ) {
                $merged[ $role ][ $cap ] = isset( $posted[ $role ][ $cap ] )
                    ? ! empty( $posted[ $role ][ $cap ] )
                    : ! empty( $existing[ $role ][ $cap ] );
            }
        }
        $matrix = $merged;
    } else {
        // Formato web (form): matriz completa, autoritativa.
        $matrix = (array) $raw;
    }
    WS_Capabilities::save_matrix( $matrix );
    ws_log_audit( 'permissions_update', 'settings', 0 );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_save_settings', 'ws_ajax_save_settings' );
function ws_ajax_save_settings() {
    ws_guard( 'settings_manage' );
    ws_save_biz_option( 'ws_currency', sanitize_text_field( $_POST['currency'] ?? '€' ) );
    ws_save_biz_option( 'ws_currencies', sanitize_text_field( $_POST['currencies'] ?? '' ) );
    // Tasas: array [ moneda => valor ], p. ej. [ 'USD' => 670 ].
    $rates = array();
    if ( isset( $_POST['rates'] ) && is_array( $_POST['rates'] ) ) {
        foreach ( $_POST['rates'] as $cur => $val ) {
            $cur = sanitize_text_field( $cur );
            $val = (float) $val;
            if ( '' !== $cur && $val > 0 ) {
                $rates[ $cur ] = round( $val, 6 );
            }
        }
    }
    ws_save_biz_option( 'ws_rates', $rates );
    $methods = isset( $_POST['payment_methods'] ) && is_array( $_POST['payment_methods'] )
        ? array_map( 'sanitize_text_field', $_POST['payment_methods'] )
        : array();
    ws_save_biz_option( 'ws_payment_methods', $methods );
    ws_save_biz_option( 'ws_whatsapp', sanitize_text_field( $_POST['whatsapp'] ?? '' ) );
    ws_log_audit( 'settings_update', 'settings', 0 );
    wp_send_json_success();
}

/* ---------------- Notificaciones ---------------- */

add_action( 'wp_ajax_ws_notifications_list', 'ws_ajax_notifications_list' );
function ws_ajax_notifications_list() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Debes iniciar sesión.', 'workshop' ) ) );
    }
    ws_generate_notifications();
    ws_notifications_cleanup();
    wp_send_json_success( array(
        'items'  => ws_notifications_for_user(),
        'unread' => ws_notifications_unread_count(),
    ) );
}

add_action( 'wp_ajax_ws_notifications_read', 'ws_ajax_notifications_read' );
function ws_ajax_notifications_read() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Debes iniciar sesión.', 'workshop' ) ) );
    }
    $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();
    $mark_all = isset( $_POST['all'] ) && '1' === $_POST['all'];
    if ( $mark_all ) {
        $ids = array(); // Array vacío marca todas como leídas
    }
    ws_notifications_mark_read( 0, $ids );
    ws_log_audit( 'notifications_read', 'notification', 0 );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_notifications_delete', 'ws_ajax_notifications_delete' );
function ws_ajax_notifications_delete() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Debes iniciar sesión.', 'workshop' ) ) );
    }
    $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();
    ws_notifications_delete( 0, $ids );
    ws_log_audit( 'notifications_delete', 'notification', 0 );
    wp_send_json_success();
}

/* ---------------- Mi cuenta ---------------- */

add_action( 'wp_ajax_ws_save_account', 'ws_ajax_save_account' );
function ws_ajax_save_account() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Debes iniciar sesión.', 'workshop' ) ) );
    }
    $user_id = (int) ( $_POST['id'] ?? 0 );
    if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso para esta acción.', 'workshop' ) ) );
    }
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        wp_send_json_error( array( 'msg' => __( 'Usuario no encontrado.', 'workshop' ) ) );
    }

    $update  = array( 'ID' => $user_id );
    $changed = false;

    $email = sanitize_email( $_POST['email'] ?? '' );
    if ( ! is_email( $email ) ) {
        wp_send_json_error( array( 'msg' => __( 'Email inválido.', 'workshop' ) ) );
    }
    if ( $email !== $user->user_email ) {
        $existing = email_exists( $email );
        if ( $existing && (int) $existing !== $user_id ) {
            wp_send_json_error( array( 'msg' => __( 'Ese email ya está en uso.', 'workshop' ) ) );
        }
        $update['user_email'] = $email;
        $changed = true;
    }

    $display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
    if ( '' !== $display_name && $display_name !== $user->display_name ) {
        $update['display_name'] = $display_name;
        $changed = true;
    }

    if ( $changed ) {
        $result = wp_update_user( $update );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
        }
        ws_log_audit( 'account_update', 'user', $user_id, array( 'fields' => array_keys( $update ) ) );
    }
    wp_send_json_success( array( 'msg' => __( 'Datos guardados.', 'workshop' ) ) );
}

add_action( 'wp_ajax_ws_change_password', 'ws_ajax_change_password' );
function ws_ajax_change_password() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'msg' => __( 'Debes iniciar sesión.', 'workshop' ) ) );
    }
    $user_id = (int) ( $_POST['id'] ?? 0 );
    if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso para esta acción.', 'workshop' ) ) );
    }
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        wp_send_json_error( array( 'msg' => __( 'Usuario no encontrado.', 'workshop' ) ) );
    }

    $current = (string) ( $_POST['current'] ?? '' );
    $new     = (string) ( $_POST['new'] ?? '' );
    $confirm = (string) ( $_POST['confirm'] ?? '' );

    if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
        wp_send_json_error( array( 'msg' => __( 'La contraseña actual no es correcta.', 'workshop' ) ) );
    }
    if ( strlen( $new ) < 8 ) {
        wp_send_json_error( array( 'msg' => __( 'La nueva contraseña debe tener al menos 8 caracteres.', 'workshop' ) ) );
    }
    if ( $new !== $confirm ) {
        wp_send_json_error( array( 'msg' => __( 'Las contraseñas no coinciden.', 'workshop' ) ) );
    }
    wp_set_password( $new, $user->ID );
    ws_log_audit( 'password_change', 'user', $user->ID );
    wp_send_json_success( array( 'msg' => __( 'Contraseña actualizada.', 'workshop' ) ) );
}

/* ---------------- Carrito AJAX ---------------- */

add_action( 'wp_ajax_ws_cart_get', 'ws_ajax_cart_get' );
add_action( 'wp_ajax_nopriv_ws_cart_get', 'ws_ajax_cart_get' );
function ws_ajax_cart_get() {
    $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $user_id = (int) ( $_POST['user_id'] ?? 0 );

    if ( ! $session_id || ! $location_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    $cart = WS_Cart::get_cart( $session_id, $location_id, $user_id );
    wp_send_json_success( array( 'data' => $cart ) );
}

add_action( 'wp_ajax_ws_cart_add', 'ws_ajax_cart_add' );
add_action( 'wp_ajax_nopriv_ws_cart_add', 'ws_ajax_cart_add' );
function ws_ajax_cart_add() {
    $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $product_id = (int) ( $_POST['product_id'] ?? 0 );
    $qty = (float) ( $_POST['qty'] ?? 1 );
    $user_id = (int) ( $_POST['user_id'] ?? 0 );

    if ( ! $session_id || ! $location_id || ! $product_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    $cart_id = WS_Cart::add_to_cart( $session_id, $location_id, $product_id, $qty, $user_id );
    wp_send_json_success( array( 'data' => array( 'cart_id' => $cart_id ) ) );
}

add_action( 'wp_ajax_ws_cart_update', 'ws_ajax_cart_update' );
add_action( 'wp_ajax_nopriv_ws_cart_update', 'ws_ajax_cart_update' );
function ws_ajax_cart_update() {
    $cart_id = (int) ( $_POST['cart_id'] ?? 0 );
    $qty = (float) ( $_POST['qty'] ?? 0 );

    if ( ! $cart_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    WS_Cart::update_cart_item( $cart_id, $qty );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_cart_remove', 'ws_ajax_cart_remove' );
add_action( 'wp_ajax_nopriv_ws_cart_remove', 'ws_ajax_cart_remove' );
function ws_ajax_cart_remove() {
    $cart_id = (int) ( $_POST['cart_id'] ?? 0 );

    if ( ! $cart_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    WS_Cart::remove_from_cart( $cart_id );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_cart_clear', 'ws_ajax_cart_clear' );
add_action( 'wp_ajax_nopriv_ws_cart_clear', 'ws_ajax_cart_clear' );
function ws_ajax_cart_clear() {
    $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );

    if ( ! $session_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    WS_Cart::clear_cart( $session_id, $location_id );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_cart_total', 'ws_ajax_cart_total' );
add_action( 'wp_ajax_nopriv_ws_cart_total', 'ws_ajax_cart_total' );
function ws_ajax_cart_total() {
    $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );

    if ( ! $session_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    $total = WS_Cart::get_cart_total( $session_id, $location_id );
    wp_send_json_success( array( 'data' => array( 'total' => $total ) ) );
}

add_action( 'wp_ajax_ws_cart_count', 'ws_ajax_cart_count' );
add_action( 'wp_ajax_nopriv_ws_cart_count', 'ws_ajax_cart_count' );
function ws_ajax_cart_count() {
    $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );

    if ( ! $session_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    $count = WS_Cart::get_cart_count( $session_id, $location_id );
    wp_send_json_success( array( 'data' => array( 'count' => $count ) ) );
}

add_action( 'wp_ajax_ws_cart_merge', 'ws_ajax_cart_merge' );
add_action( 'wp_ajax_nopriv_ws_cart_merge', 'ws_ajax_cart_merge' );
function ws_ajax_cart_merge() {
    $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
    $user_id = (int) ( $_POST['user_id'] ?? 0 );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );

    if ( ! $session_id || ! $user_id || ! $location_id ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    WS_Cart::merge_guest_cart( $session_id, $user_id, $location_id );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_get_location_by_slug', 'ws_ajax_get_location_by_slug' );
add_action( 'wp_ajax_nopriv_ws_get_location_by_slug', 'ws_ajax_get_location_by_slug' );
function ws_ajax_get_location_by_slug() {
    $slug = sanitize_text_field( $_POST['slug'] ?? '' );

    if ( ! $slug ) {
        wp_send_json_error( array( 'msg' => __( 'Slug inválido.', 'workshop' ) ) );
    }

    $location = WS_CRUD::get_location_by_slug( $slug );
    if ( ! $location ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación no encontrada.', 'workshop' ) ) );
    }

    wp_send_json_success( array( 'data' => $location ) );
}

/* ---------------- Valoraciones AJAX ---------------- */

add_action( 'wp_ajax_ws_reviews_get', 'ws_ajax_reviews_get' );
add_action( 'wp_ajax_nopriv_ws_reviews_get', 'ws_ajax_reviews_get' );
function ws_ajax_reviews_get() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }

    $product_id  = (int) ( $_POST['product_id'] ?? 0 );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $has_filters = '' !== sanitize_text_field( $_POST['search'] ?? '' )
        || '' !== sanitize_key( $_POST['status'] ?? '' )
        || (int) ( $_POST['rating'] ?? 0 ) > 0;

    // Modo público (tienda): reseñas aprobadas de la TIENDA (location_id) o,
    // compatibilidad con el modo antiguo, de un producto. El rating público es
    // el de la tienda (las estrellas valoran al negocio, no al producto).
    if ( ( $location_id || $product_id ) && ! $has_filters ) {
        $args = array(
            'approved'   => 1,
            'orderby'    => sanitize_key( $_POST['sort'] ?? 'created_at' ),
            'order'      => ( ( $_POST['dir'] ?? 'desc' ) === 'asc' ) ? 'ASC' : 'DESC',
            'limit'      => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 10,
            'offset'     => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
        );
        if ( $location_id ) {
            $args['location_id'] = $location_id;
        } else {
            $args['product_id'] = $product_id;
        }
        $reviews      = WS_Reviews::get_reviews( $args );
        // Las reseñas que llegan al JS se serializan a JSON en la respuesta;
        // se devuelven ya como arrays (wp_send_json_success los codifica).
        $review_rows  = array();
        foreach ( $reviews as $r ) {
            $review_rows[] = array(
                'id'            => (int) $r->id,
                'product_id'    => (int) $r->product_id,
                'location_id'   => (int) $r->location_id,
                'product_name'  => $r->product_name ?? '',
                'location_name' => $r->location_name ?? '',
                'customer_name' => $r->customer_name,
                'rating'        => (int) $r->rating,
                'title'         => $r->title ?? '',
                'comment'       => $r->comment ?? '',
                'created_at'    => mysql2date( 'Y-m-d H:i:s', $r->created_at ),
            );
        }
        $rating_stats = $location_id
            ? WS_Reviews::get_location_rating( $location_id )
            : WS_Reviews::get_product_rating( $product_id );

        // ¿Este visitante ya reseñó esta tienda? (anti-duplicados): se devuelve
        // para que la tienda oculte el formulario y muestre el aviso. Una
        // reseña RECHAZADA no cuenta: el autor puede reintentar y el servidor
        // reabre la misma fila como pendiente. Lógica idéntica a save.
        $already_reviewed = false;
        $uid = get_current_user_id();
        $dup = null;
        if ( $uid ) {
            $cid = (int) get_user_meta( $uid, 'ws_customer_id', true );
            if ( $cid ) {
                $dup = WS_Reviews::find_duplicate( $location_id, $product_id, (int) $cid, '' );
            }
            if ( ! $dup ) {
                $dup = WS_Reviews::find_duplicate( $location_id, $product_id, 0, 'u_' . (int) $uid );
            }
        } else {
            $chash = sanitize_key( $_POST['client_hash'] ?? '' );
            if ( '' === $chash ) {
                $chash = 'ip_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . '|' . (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
            }
            $dup = WS_Reviews::find_duplicate( $location_id, $product_id, 0, $chash );
        }
        if ( $dup ) {
            // Si la reseña anterior fue rechazada, el form vuelve a mostrarse
            // (el autor puede enviar una corregida, que reabre la misma fila).
            $already_reviewed = ( 'rejected' !== ( $dup->status ?? '' ) );
        }

        wp_send_json_success( array(
            'data'  => array(
                'data'              => $review_rows,
                'stats'             => $rating_stats,
                'already_reviewed'  => $already_reviewed,
            ),
        ) );
    }

    // Modo panel (módulo Valoraciones): listado con filtros.
    ws_guard( 'reviews_view' );

    $args = array(
        'search' => sanitize_text_field( $_POST['search'] ?? '' ),
        'status' => sanitize_key( $_POST['status'] ?? '' ),
        'payment_method' => sanitize_key( $_POST['payment_method'] ?? '' ),
        'rating' => (int) ( $_POST['rating'] ?? 0 ),
        'limit'  => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 20,
        'offset' => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
    );

    $total   = WS_Reviews::count_reviews( $args );
    $reviews = WS_Reviews::get_reviews( $args );

    $out = array();
    foreach ( $reviews as $r ) {
        $out[] = array(
            'id'                => (int) $r->id,
            'product_id'        => (int) $r->product_id,
            'location_id'       => (int) $r->location_id,
            'product_name'      => $r->product_name ?? '',
            'location_name'     => $r->location_name ?? '',
            'product_image'     => $r->product_image ?? '',
            'customer_id'       => (int) $r->customer_id,
            'customer_name'     => $r->customer_name,
            'rating'            => (int) $r->rating,
            'title'             => $r->title,
            'comment'           => $r->comment,
            'status'            => $r->status ? $r->status : ( $r->approved ? 'approved' : 'pending' ),
            'verified_purchase' => (int) $r->verified_purchase,
            'created_at'        => mysql2date( 'Y-m-d H:i:s', $r->created_at ),
        );
    }

    wp_send_json_success( array( 'data' => $out, 'total' => $total ) );
}

add_action( 'wp_ajax_ws_reviews_save', 'ws_ajax_reviews_save' );
add_action( 'wp_ajax_nopriv_ws_reviews_save', 'ws_ajax_reviews_save' );
function ws_ajax_reviews_save() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }

    $data = array(
        'product_id' => (int) ( $_POST['product_id'] ?? 0 ),
        'location_id' => (int) ( $_POST['location_id'] ?? 0 ),
        'customer_id' => (int) ( $_POST['customer_id'] ?? 0 ),
        'customer_name' => sanitize_text_field( $_POST['customer_name'] ?? '' ),
        'rating' => (int) ( $_POST['rating'] ?? 5 ),
        'title' => sanitize_text_field( $_POST['title'] ?? '' ),
        'comment' => sanitize_textarea_field( $_POST['comment'] ?? '' ),
    );

    // Las valoraciones públicas son de la TIENDA (location_id). Se admite
    // product_id solo para compatibilidad (modo antiguo de producto).
    if ( ( ! $data['location_id'] && ! $data['product_id'] ) || ! $data['customer_name'] ) {
        wp_send_json_error( array( 'msg' => __( 'Datos incompletos.', 'workshop' ) ) );
    }

    // Las reseñas nuevas entran como pendientes y se moderan en el panel.
    // Solo un moderador puede publicarlas ya aprobadas.
    $status = sanitize_key( $_POST['status'] ?? 'pending' );
    if ( ! in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ) {
        $status = 'pending';
    }
    if ( 'approved' === $status && ! ws_can( 'reviews_moderate' ) ) {
        $status = 'pending';
    }
    $data['status'] = $status;

    // Anti-duplicados: una persona = una reseña por tienda (o producto).
    // El usuario logueado se identifica por customer_id (solo si tiene ficha
    // de cliente real en ws_customers); el resto — clientes WP sin ficha y
    // visitantes anónimos — por un client_hash (el del navegador enviado por
    // theme.js, o uno estable por IP+UA si no llega).
    $uid = get_current_user_id();
    $cid = (int) $data['customer_id'];
    if ( ! $cid && $uid ) {
        // Un usuario de WordPress (cliente) con ficha en ws_customers.
        $cid = (int) get_user_meta( $uid, 'ws_customer_id', true );
        $data['customer_id'] = $cid;
    }
    if ( ! $cid ) {
        $client_hash = sanitize_key( $_POST['client_hash'] ?? '' );
        if ( '' === $client_hash ) {
            if ( $uid ) {
                // Cliente WP sin ficha: espacio de nombres propio, no colisiona
                // con los ids reales de ws_customers.
                $client_hash = 'u_' . $uid;
            } else {
                // Visitante anónimo sin JS/localStorage: hash estable por IP.
                $client_hash = 'ip_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . '|' . (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
            }
        }
        $data['client_hash'] = $client_hash;
    }

    $existing = WS_Reviews::find_duplicate(
        (int) $data['location_id'],
        (int) $data['product_id'],
        (int) $data['customer_id'],
        (string) ( $data['client_hash'] ?? '' )
    );
    if ( $existing ) {
        // Reseña rechazada: se reabre la MISMA fila como pendiente (el autor
        // corrige su opinión) en vez de crear otra. Pendiente/aprobada: la
        // persona ya votó; se bloquea para evitar puntuación infinita.
        if ( 'rejected' === $existing->status ) {
            $review_id = WS_Reviews::save_review( $data, (int) $existing->id );
            wp_send_json_success( array( 'data' => array( 'review_id' => $review_id, 'status' => 'pending', 'reopened' => true ) ) );
        }
        wp_send_json_error( array( 'msg' => __( 'Ya enviaste una reseña para esta tienda. Solo se permite una por persona.', 'workshop' ) ) );
    }

    $review_id = WS_Reviews::save_review( $data );
    wp_send_json_success( array( 'data' => array( 'review_id' => $review_id, 'status' => $status ) ) );
}

add_action( 'wp_ajax_ws_reviews_moderate', 'ws_ajax_reviews_moderate' );
function ws_ajax_reviews_moderate() {
    ws_guard( 'reviews_moderate' );

    $review_id = (int) ( $_POST['id'] ?? $_POST['review_id'] ?? 0 );
    $status    = sanitize_key( $_POST['status'] ?? '' );
    if ( ! $status ) {
        $action = sanitize_key( $_POST['action'] ?? '' );
        $status = ( 'approve' === $action ) ? 'approved' : ( ( 'reject' === $action ) ? 'rejected' : '' );
    }

    if ( ! $review_id || ! in_array( $status, array( 'approved', 'rejected' ), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Datos inválidos.', 'workshop' ) ) );
    }

    WS_Reviews::set_status( $review_id, $status );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_reviews_stats', 'ws_ajax_reviews_stats' );
function ws_ajax_reviews_stats() {
    ws_guard( 'reviews_view' );

    $stats = WS_Reviews::get_overall_stats();
    wp_send_json_success( array( 'data' => $stats ) );
}

add_action( 'wp_ajax_ws_reviews_delete', 'ws_ajax_reviews_delete' );
function ws_ajax_reviews_delete() {
    ws_guard( 'reviews_moderate' );

    $review_id = (int) ( $_POST['id'] ?? 0 );
    if ( ! $review_id ) {
        wp_send_json_error( array( 'msg' => __( 'ID inválido.', 'workshop' ) ) );
    }

    WS_Reviews::delete_review( $review_id );
    wp_send_json_success();
}

/* ---------------- CRM AJAX ---------------- */

add_action( 'wp_ajax_ws_customers_get', 'ws_ajax_customers_get' );
function ws_ajax_customers_get() {
    ws_guard( 'customers_view' );

    $args = array(
        'search' => sanitize_text_field( $_POST['search'] ?? '' ),
        'status' => sanitize_key( $_POST['status'] ?? '' ),
        'payment_method' => sanitize_key( $_POST['payment_method'] ?? '' ),
        'orderby' => sanitize_key( $_POST['sort'] ?? '' ),
        'order' => ( ( $_POST['dir'] ?? 'asc' ) === 'desc' ) ? 'DESC' : 'ASC',
        'limit' => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 10,
        'offset' => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
    );

    $customers = WS_CRM::get_customers( $args );
    $total = WS_CRM::count_customers( $args );

    $out = array();
    foreach ( $customers as $c ) {
        $out[] = array(
            'id'           => (int) $c->id,
            'name'         => $c->name,
            'email'        => $c->email,
            'phone'        => $c->phone,
            'address'      => $c->address,
            'city'         => $c->city,
            'province'     => $c->province,
            'postal_code'  => $c->postal_code,
            'notes'        => $c->notes,
            'points'       => (int) $c->loyalty_points,
            'total_spent'  => (float) $c->total_spent,
            'orders_count' => (int) $c->orders_count,
            'created_at'   => $c->created_at,
        );
    }

    wp_send_json_success( array( 'data' => $out, 'total' => $total ) );
}

add_action( 'wp_ajax_ws_customers_save', 'ws_ajax_customers_save' );
function ws_ajax_customers_save() {
    ws_guard( 'customers_create', 'customers_edit' );

    $id = (int) ( $_POST['id'] ?? 0 );
    $data = array(
        'name' => sanitize_text_field( $_POST['name'] ?? '' ),
        'email' => sanitize_email( $_POST['email'] ?? '' ),
        'phone' => sanitize_text_field( $_POST['phone'] ?? '' ),
        'address' => sanitize_textarea_field( $_POST['address'] ?? '' ),
        'city' => sanitize_text_field( $_POST['city'] ?? '' ),
        'province' => sanitize_text_field( $_POST['province'] ?? '' ),
        'postal_code' => sanitize_text_field( $_POST['postal_code'] ?? '' ),
        'notes' => sanitize_textarea_field( $_POST['notes'] ?? '' ),
    );

    if ( ! $data['name'] ) {
        wp_send_json_error( array( 'msg' => __( 'El nombre es obligatorio.', 'workshop' ) ) );
    }

    $customer_id = WS_CRM::save_customer( $data, $id );
    if ( ! $customer_id ) {
        wp_send_json_error( array( 'msg' => __( 'No se pudo guardar el cliente.', 'workshop' ) ) );
    }
    wp_send_json_success( array( 'data' => array( 'id' => $customer_id ) ) );
}

add_action( 'wp_ajax_ws_customers_delete', 'ws_ajax_customers_delete' );
function ws_ajax_customers_delete() {
    ws_guard( 'customers_delete' );

    $id = (int) ( $_POST['id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( array( 'msg' => __( 'ID inválido.', 'workshop' ) ) );
    }

    WS_CRM::delete_customer( $id );
    wp_send_json_success();
}

/* ---------------- POS AJAX ---------------- */

add_action( 'wp_ajax_ws_pos_sales_get', 'ws_ajax_pos_sales_get' );
function ws_ajax_pos_sales_get() {
    ws_guard( 'pos_view' );

    $allowed_ids = ws_user_location_ids();
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $location_id = ( $location_id && in_array( $location_id, $allowed_ids, true ) ) ? $location_id : 0;

    $args = array(
        'location_id' => $location_id,
        // Sin ubicación concreta: solo las permitidas del trabajador.
        'location_ids' => $allowed_ids,
        'seller_id' => (int) ( $_POST['seller_id'] ?? 0 ),
        'search' => sanitize_text_field( $_POST['search'] ?? '' ),
        'status' => sanitize_key( $_POST['status'] ?? '' ),
        'payment_method' => sanitize_key( $_POST['payment_method'] ?? '' ),
        'date_from' => sanitize_text_field( $_POST['date_from'] ?? '' ),
        'date_to' => sanitize_text_field( $_POST['date_to'] ?? '' ),
        'orderby' => sanitize_key( $_POST['sort'] ?? '' ),
        'order' => ( ( $_POST['dir'] ?? 'desc' ) === 'asc' ) ? 'ASC' : 'DESC',
        'limit' => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 20,
        'offset' => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
    );

    $sales = WS_POS::get_sales( $args );
    $total = WS_POS::count_sales( $args );

    $out = array();
    foreach ( $sales as $s ) {
        $out[] = array(
            'id'             => (int) $s->id,
            'number'         => $s->number,
            'location_id'    => (int) $s->location_id,
            'location_name'  => $s->location_name ?? '',
            'seller_id'      => (int) $s->seller_id,
            'seller_name'    => $s->seller_name ?? '',
            'customer_id'     => (int) $s->customer_id,
            'customer_name'   => $s->customer_name,
            'customer_doc'    => $s->customer_doc ?? '',
            'customer_phone'  => $s->customer_phone ?? '',
            'currency'        => $s->currency,
            'subtotal'        => (float) $s->subtotal,
            'discount'        => (float) $s->discount,
            'total'           => (float) $s->total,
            'payment_method'  => $s->payment_method,
            'cash_amount'     => (float) ( $s->cash_amount ?? 0 ),
            'transfer_amount' => (float) ( $s->transfer_amount ?? 0 ),
            'transfer_number' => $s->transfer_number ?? '',
            'status'          => $s->status,
            'created_at'      => mysql2date( 'Y-m-d H:i:s', $s->created_at ),
        );
    }

    wp_send_json_success( array( 'data' => $out, 'total' => $total ) );
}

add_action( 'wp_ajax_ws_pos_sale_items_get', 'ws_ajax_pos_sale_items_get' );
function ws_ajax_pos_sale_items_get() {
    ws_guard( 'pos_view' );

    $sale_id = (int) ( $_POST['sale_id'] ?? 0 );
    if ( ! $sale_id ) {
        wp_send_json_error( array( 'msg' => __( 'ID inválido.', 'workshop' ) ) );
    }

    // Solo ítems de ventas de las ubicaciones permitidas del trabajador.
    global $wpdb;
    $sale = $wpdb->get_row( $wpdb->prepare(
        "SELECT location_id FROM " . ws_table_name( 'pos_sales' ) . " WHERE id = %d",
        $sale_id
    ) );
    if ( ! $sale || ! in_array( (int) $sale->location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Venta no disponible.', 'workshop' ) ) );
    }

    $out = array();
    foreach ( WS_POS::get_sale_items( $sale_id ) as $it ) {
        $out[] = array(
            'id'           => (int) $it->id,
            'sale_id'      => (int) $it->sale_id,
            'product_id'   => (int) $it->product_id,
            'combo_id'     => (int) ( $it->combo_id ?? 0 ),
            'product_name' => $it->product_name,
            'qty'          => (float) $it->qty,
            'price'        => (float) $it->price,
            'discount'     => (float) $it->discount,
            'subtotal'     => (float) $it->subtotal,
        );
    }

    wp_send_json_success( array( 'data' => $out ) );
}

add_action( 'wp_ajax_ws_pos_sale_save', 'ws_ajax_pos_sale_save' );
function ws_ajax_pos_sale_save() {
    ws_guard( 'pos_sell' );

    $data = array(
        'location_id'     => (int) ( $_POST['location_id'] ?? 0 ),
        'seller_id'       => (int) ( $_POST['seller_id'] ?? 0 ),
        'customer_id'     => (int) ( $_POST['customer_id'] ?? 0 ),
        'customer_name'   => sanitize_text_field( $_POST['customer_name'] ?? '' ),
        'customer_doc'    => sanitize_text_field( $_POST['customer_doc'] ?? '' ),
        'customer_phone'  => sanitize_text_field( $_POST['customer_phone'] ?? '' ),
        'currency'        => sanitize_text_field( $_POST['currency'] ?? '€' ),
        'subtotal'        => (float) ( $_POST['subtotal'] ?? 0 ),
        'discount'        => (float) ( $_POST['discount'] ?? 0 ),
        'total'           => (float) ( $_POST['total'] ?? 0 ),
        'payment_method'  => sanitize_text_field( $_POST['payment_method'] ?? 'cash' ),
        'cash_amount'     => (float) ( $_POST['cash_amount'] ?? 0 ),
        'transfer_amount' => (float) ( $_POST['transfer_amount'] ?? 0 ),
        'transfer_number' => sanitize_text_field( $_POST['transfer_number'] ?? '' ),
        // Venta directa de POS: siempre queda completada, nunca pendiente.
        'status'          => 'completed',
        'register_id'     => (int) ( $_POST['register_id'] ?? 0 ),
        // Referencia única generada por el POS al guardar la venta offline:
        // hace la sincronización idempotente (si la respuesta se pierde y la
        // cola reintenta, no se duplica la venta ni el descuento de stock).
        'client_ref'      => sanitize_text_field( $_POST['client_ref'] ?? '' ),
        'items'           => isset( $_POST['items'] ) ? (array) json_decode( wp_unslash( $_POST['items'] ), true ) : array(),
    );

    // Venta sincronizada desde el modo offline del POS.
    $is_offline_sync = ! empty( $_POST['ws_offline_sync'] );

    // Solo puede vender en sus ubicaciones asignadas (aunque la venta sea
    // offline: si ya no trabaja allí, la cola la rechaza con error claro).
    if ( ! in_array( $data['location_id'], ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'No puedes vender en esta ubicación.', 'workshop' ) ) );
    }

    if ( ! $data['location_id'] || ! $data['seller_id'] ) {
        wp_send_json_error( array( 'msg' => __( 'Datos incompletos.', 'workshop' ) ) );
    }

    global $wpdb;

    // Venta offline ya sincronizada antes: devolver la existente sin volver a
    // descontar stock ni crear otra venta (idempotencia por client_ref).
    if ( $is_offline_sync && $data['client_ref'] ) {
        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . ws_table_name( 'pos_sales' ) . " WHERE client_ref=%s AND location_id=%d LIMIT 1",
            $data['client_ref'], $data['location_id']
        ) );
        if ( $existing_id ) {
            wp_send_json_success( array( 'sale_id' => $existing_id, 'duplicate' => true ) );
        }
    }

    // Caja POS: en línea la venta requiere una caja abierta en la ubicación.
    // Las ventas offline ya se cobraron con la caja abierta en su momento, así
    // que al sincronizar no se exige; si hay caja abierta ahora se asocian.
    $cash = WS_POS::get_open_cash( $data['location_id'] );
    if ( ! $is_offline_sync ) {
        if ( ! $cash ) {
            wp_send_json_error( array( 'msg' => __( 'Debes abrir la caja antes de vender.', 'workshop' ) ) );
        }
        $data['register_id'] = (int) $cash->id;
    } elseif ( $cash ) {
        $data['register_id'] = (int) $cash->id;
    }

    // Transferencia (sola o mixta): el nº de transferencia es obligatorio.
    if ( in_array( $data['payment_method'], array( 'transfer', 'both' ), true ) && '' === $data['transfer_number'] ) {
        wp_send_json_error( array( 'msg' => __( 'El número de transferencia es obligatorio.', 'workshop' ) ) );
    }

    // Descuento de stock atómico: cada ítem de la venta sale del inventario
    // (propaga el fraccionamiento: vender 1 jaba descuenta el saco). En línea
    // la venta se revierte si algún producto no tiene stock suficiente. Al
    // sincronizar una venta offline se descuenta lo disponible y se anota la
    // discrepancia (la venta se mantiene: ya fue cobrada al cliente).
    $discrepancies = array();
    $wpdb->query( 'START TRANSACTION' );
    foreach ( (array) $data['items'] as $it ) {
        $combo_id = (int) ( $it['combo_id'] ?? 0 );
        $pid = (int) ( $it['product_id'] ?? 0 );
        $qty = (float) ( $it['qty'] ?? 0 );
        if ( ( ! $pid && ! $combo_id ) || $qty <= 0 ) {
            continue;
        }
        $pname = sanitize_text_field( $it['product_name'] ?? '' );
        if ( '' === $pname ) {
            if ( $combo_id > 0 ) {
                $c = WS_Combos::get( $combo_id );
                $pname = $c ? $c->name : 'Combo #' . $combo_id;
            } else {
                $found = $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM " . ws_table_name( 'products' ) . " WHERE id=%d", $pid
                ) );
                $pname = $found ? $found : '#' . $pid;
            }
        }

        // Venta de un COMBO: se descuentan sus componentes (cada producto × qty).
        if ( $combo_id > 0 ) {
            if ( $is_offline_sync ) {
                foreach ( WS_Combos::expand( $combo_id, $qty ) as $cp ) {
                    $deducted_raw = WS_Stock::decrease_partial_in_tx(
                        $cp['product_id'], $data['location_id'], $cp['qty'], 'salida',
                        'Venta POS offline', 'Venta offline #' . substr( $data['client_ref'], 0, 8 ), get_current_user_id()
                    );
                    if ( abs( (float) $deducted_raw ) < $cp['qty'] ) {
                        $discrepancies[] = array(
                            'product'  => $pname . ' (' . $cp['product_id'] . ')',
                            'requested'=> $cp['qty'],
                            'deducted' => abs( (float) $deducted_raw ),
                            'missing'  => round( $cp['qty'] - abs( (float) $deducted_raw ), 2 ),
                            'fraction' => $deducted_raw < 0,
                        );
                    }
                }
            } else {
                $stock_res = WS_Combos::decrease_in_tx(
                    $combo_id, $data['location_id'], $qty, 'salida',
                    'Venta POS', 'Venta #pendiente', get_current_user_id()
                );
                if ( is_wp_error( $stock_res ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    wp_send_json_error( array( 'msg' => sprintf(
                        /* translators: %s: nombre del combo */
                        __( 'No hay stock suficiente para el combo «%s» (sus productos se usan en otros combos o ventas).', 'workshop' ),
                        $pname
                    ) ) );
                }
            }
            continue;
        }

        if ( $is_offline_sync ) {
            // Modo tolerante: descuenta lo disponible, anota la diferencia. Si
            // devuelve NEGATIVO, la unidad fraccionada relacionada está
            // agotada (inventario desbalanceado padre/hijo) y se reporta.
            $deducted_raw = WS_Stock::decrease_partial_in_tx(
                $pid, $data['location_id'], $qty, 'salida',
                'Venta POS offline', 'Venta offline #' . substr( $data['client_ref'], 0, 8 ), get_current_user_id()
            );
            $fraction_failed = $deducted_raw < 0;
            $deducted        = abs( (float) $deducted_raw );
            if ( $fraction_failed || $deducted < $qty ) {
                $discrepancies[] = array(
                    'product'  => $pname,
                    'requested'=> $qty,
                    'deducted' => $deducted,
                    'missing'  => $fraction_failed ? round( $qty, 2 ) : round( $qty - $deducted, 2 ),
                    'fraction' => $fraction_failed,
                );
            }
        } else {
            $stock_res = WS_Stock::decrease_in_tx(
                $pid, $data['location_id'], $qty, 'salida',
                'Venta POS', 'Venta #pendiente', get_current_user_id()
            );
            if ( is_wp_error( $stock_res ) ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( array( 'msg' => $stock_res->get_error_message() ) );
            }
        }
    }

    $sale_id = WS_POS::save_sale( $data );
    if ( ! $sale_id ) {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( array( 'msg' => __( 'No se pudo guardar la venta.', 'workshop' ) ) );
    }
    $wpdb->query( 'COMMIT' );

    // Fidelización y stats del cliente: puntos por € + total gastado.
    if ( $sale_id && $data['customer_id'] && 'completed' === $data['status'] ) {
        WS_CRM::update_customer_stats( $data['customer_id'], $data['total'] );
        if ( class_exists( 'WS_Loyalty' ) ) {
            WS_Loyalty::add_points_for_purchase( $data['customer_id'], $data['total'] );
        }
    }

    // Discrepancias de stock al sincronizar una venta offline: se avisa al
    // negocio (dueños y usuarios de la ubicación) para que regularice.
    if ( $is_offline_sync && ! empty( $discrepancies ) ) {
        $sale_number = $wpdb->get_var( $wpdb->prepare(
            "SELECT number FROM " . ws_table_name( 'pos_sales' ) . " WHERE id=%d", $sale_id
        ) );
        $parts = array();
        $more  = 0;
        foreach ( $discrepancies as $i => $d ) {
            if ( $i >= 3 ) {
                $more++;
                continue;
            }
            if ( ! empty( $d['fraction'] ) ) {
                $parts[] = $d['product'] . ': faltan unidades relacionadas (fraccionamiento)';
            } else {
                $parts[] = $d['product'] . ': pedido ' . number_format_i18n( $d['requested'], 2 ) . ', stock ' . number_format_i18n( $d['deducted'], 2 ) . ' (faltan ' . number_format_i18n( $d['missing'], 2 ) . ')';
            }
        }
        $msg = sprintf(
            /* translators: 1: número de venta, 2: lista de discrepancias */
            __( 'Venta %1$s sincronizada con stock insuficiente. %2$s', 'workshop' ),
            $sale_number ? $sale_number : '#' . $sale_id,
            implode( ' | ', $parts ) . ( $more ? ' | +' . $more . ' más' : '' )
        );
        if ( function_exists( 'ws_notify_location_users' ) ) {
            ws_notify_location_users(
                $data['location_id'],
                'stock_discrepancy',
                __( 'Discrepancia de stock en venta offline', 'workshop' ),
                $msg,
                'stock',
                'products_view',
                'stock_discrepancy_' . $sale_id
            );
        }
    }

    ws_log_audit( 'pos_sale_create', 'pos_sale', $sale_id, array( 'location' => $data['location_id'], 'total' => $data['total'], 'offline_sync' => $is_offline_sync, 'discrepancies' => count( $discrepancies ) ) );
    wp_send_json_success( array(
        'sale_id'        => $sale_id,
        'synced_offline' => $is_offline_sync,
        'discrepancies'  => $discrepancies,
    ) );
}

add_action( 'wp_ajax_ws_products_get', 'ws_ajax_products_get' );
function ws_ajax_products_get() {
    ws_guard( 'products_view' );

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $search      = sanitize_text_field( $_POST['search'] ?? '' );
    $limit       = isset( $_POST['limit'] ) ? min( 500, (int) $_POST['limit'] ) : 100;
    $offset      = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

    // Solo productos con stock en las ubicaciones permitidas del usuario.
    $allowed     = ws_user_locations();
    $allowed_ids = array_map( fn( $l ) => (int) $l->id, $allowed );
    $loc_ids = ( $location_id && in_array( $location_id, $allowed_ids, true ) )
        ? array( $location_id )
        : $allowed_ids;

    // Stock compartido DIRIGIDO: incluir las ubicaciones SUPERIORES (centro,
    // transitivo) para que el POS muestre productos con stock en la ubicación
    // seleccionada o en su centro. Un movimiento en la ubicación se aplica a
    // su centro (nunca a hermanos ni a hijos).
    $linked_ids = array();
    if ( $location_id ) {
        $linked_ids = WS_Stock::linked_location_ids( $location_id );
    }
    // Unir ubicaciones seleccionadas + vinculadas (solo las permitidas).
    $all_loc_ids = array_values( array_unique( array_merge( $loc_ids, array_intersect( $linked_ids, $allowed_ids ) ) ) );

    // Filtrar productos que tienen stock en la ubicación seleccionada
    // (o vinculadas). Sin limit/offset aquí: necesitamos todos los stocks del
    // grupo para calcular el total correcto. Se aplica paginación después.
    $stock_rows = WS_Stock::stock_rows( array(
        'location_ids' => $all_loc_ids,
        'search'       => $search,
        'active'       => 1,
    ) );

    // Calcular stock del grupo (stock compartido por línea de ubicaciones
    // conectadas). group_total es la suma de stock en TODAS las ubicaciones
    // del componente conexo del producto+ubicación.
    $group = WS_Stock::stock_group_info( $stock_rows );

    // POS: mostrar/ocultar POR UBICACIÓN (canal 'pos'), igual que la tienda
    // pero independiente. Lo oculto del POS no aparece en el catálogo del POS
    // de esa ubicación (sigue en el inventario y en la tienda si está visible).
    global $wpdb;
    $sv_pos_t = ws_table_name( 'store_visibility' );
    $pos_hidden = array();
    if ( $loc_ids && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sv_pos_t ) ) === $sv_pos_t ) {
        $sv_pos_ph = implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) );
        $sv_hidden = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_id, location_id FROM {$sv_pos_t} WHERE entity_type='product' AND channel='pos' AND visible=0 AND location_id IN ({$sv_pos_ph})",
            ...$loc_ids
        ) );
        foreach ( $sv_hidden as $h ) {
            $pos_hidden[ (int) $h->entity_id . ':' . (int) $h->location_id ] = true;
        }
    }

    // Moneda de la ubicación seleccionada: el POS cobra en la moneda del PV,
    // así que el precio de VENTA (y el costo, para la ganancia) se convierten
    // a esa moneda. Sin ubicación concreta se deja la moneda nativa.
    $loc_currency = ws_location_currency( $location_id );

    // Deduplicar por producto: usar la fila de la ubicación seleccionada
    // (si existe) o la primera encontrada. El stock efectivo es el total del
    // grupo (todas las ubicaciones conectadas). Solo se muestran productos con
    // stock disponible en el grupo.
    $seen_products = array();
    $out = array();
    foreach ( $stock_rows as $r ) {
        $pid = (int) $r->product_id;
        if ( ! $pid ) {
            continue;
        }
        // Si el producto ya fue procesado, saltar (deduplicar).
        if ( isset( $seen_products[ $pid ] ) ) {
            continue;
        }
        // POS visibility check: oculto en la ubicación seleccionada.
        $hide_key = $pid . ':' . (int) $r->location_id;
        if ( isset( $pos_hidden[ $hide_key ] ) ) {
            continue;
        }
        // Stock efectivo: total del grupo (todas las ubicaciones conectadas).
        $g = $group[ $pid . ':' . (int) $r->location_id ] ?? null;
        $effective_stock = $g ? (float) $g['total'] : (float) $r->qty;
        // Mostrar productos con stock > 0 en el grupo, O productos que existan
        // físicamente en la ubicación seleccionada (stock 0 pero registrado).
        // Esto asegura que si un producto se agotó en la ubicación, siga visible
        // en el POS para poder reordenarlo o ajustarlo.
        $in_selected_loc = (int) $r->location_id === $location_id;
        if ( $effective_stock <= 0 && ! $in_selected_loc ) {
            continue;
        }
        $seen_products[ $pid ] = true;

        $sale_price = (float) $r->sale_price;
        $cost_price = (float) $r->cost_price;
        $cur        = $r->currency;
        if ( $location_id && $cur && $cur !== $loc_currency ) {
            $sale_price = (float) ws_convert( $sale_price, $cur, $loc_currency );
            $cost_price = (float) ws_convert( $cost_price, $cur, $loc_currency );
            $cur        = $loc_currency;
        }
        $out[] = array(
            'id'          => $pid,
            'name'        => $r->name,
            'barcode'     => $r->barcode,
            'category'    => (string) ( $r->category ?? '' ),
            'image'       => $r->image,
            'gallery'     => $r->gallery ? WS_CRUD::product_gallery( $r ) : array(),
            'description' => $r->description ?? '',
            'sale_price'  => $sale_price,
            'cost_price'  => $cost_price,
            'transfer_pct'=> (float) $r->transfer_pct,
            'currency'    => $cur,
            'show_equiv'  => (int) ( $r->show_equiv ?? 1 ),
            'stock'       => $effective_stock,
            'is_combo'    => 0,
            'combo_id'    => 0,
        );
    }
    // Aplicar paginación DESPUÉS de la deduplicación y filtrado por grupo.
    $out = array_values( $out );
    $total = count( $out );
    if ( $offset > 0 || $limit < $total ) {
        $out = array_slice( $out, $offset, $limit );
    }

    // Combos activos (stock derivado de sus componentes) como ítems de catálogo.
    $combo_count = 0;
    if ( $location_id ) {
        foreach ( WS_Combos::catalog_rows( $location_id ) as $c ) {
            if ( $search && false === mb_stripos( $c['name'], $search ) ) {
                continue;
            }
            if ( ! ws_store_visible( 'combo', (int) $c['combo_id'], $location_id, 'pos' ) ) {
                continue;
            }
            if ( (float) $c['qty'] <= 0 ) {
                continue;
            }
            $cprice = (float) $c['price'];
            if ( $c['currency'] && $c['currency'] !== $loc_currency ) {
                $cprice = (float) ws_convert( $cprice, $c['currency'], $loc_currency );
            }
            $out[] = array(
                'id'          => $c['id'],
                'combo_id'    => $c['combo_id'],
                'name'        => $c['name'],
                'barcode'     => '',
                'category'    => 'Combo',
                'image'       => $c['photo'] ? ws_image_url( $c['photo'] ) : '',
                'description' => '',
                'sale_price'  => $cprice,
                'cost_price'  => 0,
                'transfer_pct'=> 0,
                'currency'    => $loc_currency,
                'show_equiv'  => 0,
                'stock'       => (float) $c['qty'],
                'is_combo'    => 1,
                'combo_id'    => $c['combo_id'],
                'combo_items' => $c['items'],
            );
            $combo_count++;
        }
    }

    wp_send_json_success( array( 'data' => $out, 'total' => $total + $combo_count ) );
}

add_action( 'wp_ajax_ws_pos_stats', 'ws_ajax_pos_stats' );
function ws_ajax_pos_stats() {
    ws_guard( 'pos_view' );

    $seller_id   = (int) ( $_POST['seller_id'] ?? 0 );
    $allowed_ids = ws_user_location_ids();
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $location_id = ( $location_id && in_array( $location_id, $allowed_ids, true ) ) ? $location_id : 0;
    $date_from   = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to     = sanitize_text_field( $_POST['date_to'] ?? '' );

    $stats = WS_POS::get_stats( array(
        'seller_id'   => $seller_id,
        'location_id' => $location_id,
        'location_ids'=> $allowed_ids,
        'date_from'   => $date_from,
        'date_to'     => $date_to,
    ) );

    wp_send_json_success( array( 'data' => $stats ) );
}

/* ---------------- Caja POS (apertura / cierre) ---------------- */

add_action( 'wp_ajax_ws_pos_cash_status', 'ws_ajax_pos_cash_status' );
function ws_ajax_pos_cash_status() {
    ws_guard( 'pos_sell', 'pos_view' );

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    if ( $location_id && ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }
    $cash = $location_id ? WS_POS::get_open_cash( $location_id ) : null;

    wp_send_json_success( array(
        'data' => array(
            'location_id' => $location_id,
            'open'        => (bool) $cash,
            'cash'        => $cash ? array(
                'id'             => (int) $cash->id,
                'opening_amount' => (float) $cash->opening_amount,
                'opening_note'   => $cash->opening_note,
                'opened_at'      => mysql2date( 'Y-m-d H:i:s', $cash->opened_at ),
                'seller_name'    => $cash->seller_name ?? '',
            ) : null,
        ),
    ) );
}

add_action( 'wp_ajax_ws_pos_cash_open', 'ws_ajax_pos_cash_open' );
function ws_ajax_pos_cash_open() {
    ws_guard( 'pos_sell' );

    $location_id    = (int) ( $_POST['location_id'] ?? 0 );
    $opening_amount = (float) ( $_POST['opening_amount'] ?? 0 );
    $note           = sanitize_text_field( $_POST['note'] ?? '' );

    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }

    $cash = WS_POS::open_cash( $location_id, $opening_amount, $note );
    if ( ! $cash ) {
        wp_send_json_error( array( 'msg' => __( 'No se pudo abrir la caja.', 'workshop' ) ) );
    }

    ws_log_audit( 'pos_cash_open', 'pos_cash', (int) $cash->id, array( 'location' => $location_id, 'amount' => $opening_amount ) );
    wp_send_json_success( array(
        'data' => array(
            'id'             => (int) $cash->id,
            'opening_amount' => (float) $cash->opening_amount,
            'opened_at'      => mysql2date( 'Y-m-d H:i:s', $cash->opened_at ),
        ),
    ) );
}

add_action( 'wp_ajax_ws_pos_cash_close', 'ws_ajax_pos_cash_close' );
function ws_ajax_pos_cash_close() {
    ws_guard( 'pos_sell' );

    $location_id    = (int) ( $_POST['location_id'] ?? 0 );
    $closing_amount = (float) ( $_POST['closing_amount'] ?? 0 );
    $note           = sanitize_text_field( $_POST['note'] ?? '' );
    $cuadre         = isset( $_POST['cuadre'] ) ? json_decode( wp_unslash( $_POST['cuadre'] ), true ) : array();

    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }

    $result = WS_POS::close_cash( $location_id, $closing_amount, $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }

    // Cuadre de inventario: conteo físico vs. stock virtual. Se guarda por
    // producto para auditar el cierre (sobrantes/faltantes detectados).
    $cuadre_summary = array( 'count' => 0, 'sobrante' => 0, 'faltante' => 0 );
    if ( is_array( $cuadre ) && ! empty( $cuadre ) && class_exists( 'WS_Stock' ) ) {
        global $wpdb;
        $counts_table = ws_table_name( 'pos_cash_counts' );
        $virtual = array();
        foreach ( WS_Stock::stock_rows( array( 'location_id' => $location_id, 'limit' => 500 ) ) as $r ) {
            $virtual[ (int) $r->product_id ] = array( 'name' => $r->name, 'qty' => (float) $r->qty );
        }
        $count = 0;
        foreach ( $cuadre as $pid => $phys ) {
            $pid = (int) $pid;
            $phys = (float) $phys;
            if ( ! $pid || ! isset( $virtual[ $pid ] ) ) {
                continue;
            }
            $virt = $virtual[ $pid ]['qty'];
            $diff = round( $phys - $virt, 2 );
            $wpdb->insert( $counts_table, array(
                'register_id'  => (int) $result['id'],
                'product_id'   => $pid,
                'product_name' => sanitize_text_field( $virtual[ $pid ]['name'] ),
                'virtual_qty'  => $virt,
                'physical_qty' => $phys,
                'diff'         => $diff,
            ), array( '%d', '%d', '%s', '%f', '%f', '%f' ) );
            if ( $diff > 0.004 ) {
                $cuadre_summary['sobrante']++;
            } elseif ( $diff < -0.004 ) {
                $cuadre_summary['faltante']++;
            }
            $count++;
        }
        $cuadre_summary['count'] = $count;
    }
    $result['cuadre'] = $cuadre_summary;

    ws_log_audit( 'pos_cash_close', 'pos_cash', (int) $result['id'], array( 'location' => $location_id, 'expected' => $result['expected'], 'actual' => $result['closing_amount'], 'cuadre' => $cuadre_summary ) );
    wp_send_json_success( array( 'data' => $result ) );
}

/**
 * Stock VIRTUAL de una ubicación para el cuadre del cierre de caja: devuelve
 * producto, nombre y stock actual que maneja la app para compararlo con el
 * conteo físico que ingresa el usuario al cerrar.
 */
add_action( 'wp_ajax_ws_pos_cash_stock', 'ws_ajax_pos_cash_stock' );
function ws_ajax_pos_cash_stock() {
    ws_guard( 'pos_sell' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }
    $out = array();
    foreach ( WS_Stock::stock_rows( array( 'location_id' => $location_id, 'limit' => 500 ) ) as $r ) {
        $out[] = array(
            'product_id' => (int) $r->product_id,
            'name'       => $r->name,
            'qty'        => (float) $r->qty,
        );
    }
    wp_send_json_success( array( 'data' => $out ) );
}

add_action( 'wp_ajax_ws_pos_cash_history', 'ws_ajax_pos_cash_history' );
function ws_ajax_pos_cash_history() {
    ws_guard( 'pos_view' );

    $allowed_ids = ws_user_location_ids();
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $location_id = ( $location_id && in_array( $location_id, $allowed_ids, true ) ) ? $location_id : 0;
    $status      = sanitize_key( $_POST['status'] ?? '' );
    $date_from   = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to     = sanitize_text_field( $_POST['date_to'] ?? '' );

    $args = array(
        'location_id' => $location_id,
        'location_ids' => $allowed_ids,
        'status'      => $status,
        'date_from'   => $date_from,
        'date_to'     => $date_to,
        'limit'       => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 50,
        'offset'      => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
    );

    $rows = WS_POS::cash_history( $args );
    $out = array();
    foreach ( $rows as $c ) {
        $out[] = array(
            'id'             => (int) $c->id,
            'location_id'    => (int) $c->location_id,
            'location_name'  => $c->location_name ?? '',
            'seller_name'    => $c->seller_name ?? '',
            'opening_amount' => (float) $c->opening_amount,
            'opening_note'   => $c->opening_note,
            'opened_at'      => mysql2date( 'Y-m-d H:i:s', $c->opened_at ),
            'closing_amount' => (float) $c->closing_amount,
            'closing_note'   => $c->closing_note,
            'closed_at'      => $c->closed_at ? mysql2date( 'Y-m-d H:i:s', $c->closed_at ) : '',
            'status'         => $c->status,
            'sales_total'    => (float) $c->sales_total,
            'expected'       => (float) $c->expected,
            'difference'     => (float) $c->difference,
        );
    }

    wp_send_json_success( array( 'data' => $out ) );
}

/**
 * Detalle del CUADRE de un cierre de caja: conteo físico ingresado al cerrar
 * vs. el stock virtual que manejaba la app, producto por producto. Sirve para
 * auditar un arqueo pasado (sobrantes/faltantes de inventario).
 */
add_action( 'wp_ajax_ws_pos_cash_counts_get', 'ws_ajax_pos_cash_counts_get' );
function ws_ajax_pos_cash_counts_get() {
    ws_guard( 'pos_view' );

    $register_id = (int) ( $_POST['register_id'] ?? 0 );
    if ( ! $register_id ) {
        wp_send_json_error( array( 'msg' => __( 'Cierre inválido.', 'workshop' ) ) );
    }

    // El cuadre de un cierre solo es visible para quien trabaja en esa
    // ubicación.
    global $wpdb;
    $cash = $wpdb->get_row( $wpdb->prepare(
        "SELECT location_id FROM " . ws_table_name( 'pos_cash' ) . " WHERE id = %d",
        $register_id
    ) );
    if ( ! $cash || ! in_array( (int) $cash->location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Cierre no disponible.', 'workshop' ) ) );
    }

    $table = ws_table_name( 'pos_cash_counts' );
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
        wp_send_json_success( array( 'data' => array() ) );
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT product_id, product_name, virtual_qty, physical_qty, diff
         FROM {$table} WHERE register_id = %d ORDER BY id ASC",
        $register_id
    ) );

    $out      = array();
    $sobrante = 0;
    $faltante = 0;
    foreach ( $rows as $r ) {
        $d = (float) $r->diff;
        if ( $d > 0.004 ) {
            $sobrante++;
        } elseif ( $d < -0.004 ) {
            $faltante++;
        }
        $out[] = array(
            'product_id'   => (int) $r->product_id,
            'product_name' => $r->product_name,
            'virtual_qty'  => (float) $r->virtual_qty,
            'physical_qty' => (float) $r->physical_qty,
            'diff'         => $d,
        );
    }

    wp_send_json_success( array(
        'data' => array(
            'register_id' => $register_id,
            'items'       => $out,
            'summary'     => array( 'count' => count( $out ), 'sobrante' => $sobrante, 'faltante' => $faltante ),
        ),
    ) );
}

/* ---------------- Cuadre de inventario independiente (sin caja) ---------------- */

/**
 * Stock VIRTUAL de una ubicación para el cuadre de inventario: devuelve todos
 * los productos con su stock actual según la app, para que el usuario ingrese
 * el conteo FÍSICO y compare. Permite stock_view (no exige caja abierta).
 */
add_action( 'wp_ajax_ws_stock_count_virtual', 'ws_ajax_stock_count_virtual' );
function ws_ajax_stock_count_virtual() {
    ws_guard( 'stock_count_view' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }
    $rows  = WS_Stock::stock_rows( array( 'location_id' => $location_id, 'limit' => 1000 ) );
    // Stock del GRUPO por producto (la propia + sus SUPERIORES/centro en el
    // grafo dirigido) para mostrar el contexto del pool junto al virtual.
    $group = WS_Stock::stock_group_info( $rows );

    // Excluir productos ocultos en POS (store_visibility channel='pos', visible=0).
    // Así el cuadre coincide con lo que se ve en la caja: si el admin ocultó un
    // producto del POS, no debe aparecer en el conteo físico.
    global $wpdb;
    $sv_t       = ws_table_name( 'store_visibility' );
    $pos_hidden = array();
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sv_t ) ) === $sv_t ) {
        $sv_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_id, location_id FROM {$sv_t} WHERE entity_type='product' AND channel='pos' AND visible=0 AND location_id=%d",
            $location_id
        ) );
        foreach ( $sv_rows as $h ) {
            $pos_hidden[ (int) $h->entity_id ] = true;
        }
    }

    $out = array();
    foreach ( $rows as $r ) {
        $pid = (int) $r->product_id;
        // Saltar productos ocultos en POS para esta ubicación.
        if ( isset( $pos_hidden[ $pid ] ) ) {
            continue;
        }
        $g = $group[ $r->product_id . ':' . $r->location_id ] ?? null;
        $out[] = array(
            'product_id'   => (int) $r->product_id,
            'name'         => $r->name,
            'barcode'      => $r->barcode,
            'qty'          => (float) $r->qty,
            'sale_price'   => (float) $r->sale_price,
            'currency'     => $r->currency ?? '',
            'group_total'  => $g ? (float) $g['total'] : (float) $r->qty,
            'group_parts'  => $g ? $g['parts'] : array( array(
                'id'   => (int) $r->location_id,
                'name' => (string) ( $r->location_name ?? '' ),
                'qty'  => (float) $r->qty,
            ) ),
        );
    }
    wp_send_json_success( array( 'data' => $out ) );
}

/**
 * Guarda un CUADRE de inventario independiente: el conteo físico ingresado por
 * el usuario vs. el stock virtual de la app, producto por producto. Si el
 * usuario lo pide (adjust = 1), la app CORRIGE el stock para que coincida con
 * lo físico (entrada/salida automática de ajuste por producto con diferencia).
 */
add_action( 'wp_ajax_ws_stock_count_save', 'ws_ajax_stock_count_save' );
function ws_ajax_stock_count_save() {
    ws_guard( 'stock_count_view' );

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $adjust      = ! empty( $_POST['adjust'] );
    $note        = sanitize_text_field( $_POST['note'] ?? '' );
    $items       = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : array();

    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }
    if ( ! is_array( $items ) || empty( $items ) ) {
        wp_send_json_error( array( 'msg' => __( 'No hay productos para cuadrar.', 'workshop' ) ) );
    }

    global $wpdb;
    $table = ws_table_name( 'stock_counts' );

    // Reconstruir el stock virtual actual (fuente de verdad del cuadre), con
    // el stock del GRUPO por producto (la propia + sus SUPERIORES/centro) para
    // guardarlo como contexto.
    $vrows  = WS_Stock::stock_rows( array( 'location_id' => $location_id, 'limit' => 1000 ) );
    $vgroup = WS_Stock::stock_group_info( $vrows );
    $virtual = array();
    foreach ( $vrows as $r ) {
        $g = $vgroup[ $r->product_id . ':' . $r->location_id ] ?? null;
        $virtual[ (int) $r->product_id ] = array(
            'name'         => $r->name,
            'qty'          => (float) $r->qty,
            'group_total'  => $g ? (float) $g['total'] : (float) $r->qty,
            'group_parts'  => $g ? $g['parts'] : null,
        );
    }

    $stored   = array();
    $cuadrados = 0;
    $sobrante  = 0;
    $faltante  = 0;
    $ajustados = 0;

    foreach ( $items as $it ) {
        $pid  = (int) ( $it['product_id'] ?? 0 );
        $phys = (float) ( $it['physical'] ?? 0 );
        if ( ! $pid || ! isset( $virtual[ $pid ] ) ) {
            continue;
        }
        $virt = $virtual[ $pid ]['qty'];
        $diff = round( $phys - $virt, 2 );
        $stored[] = array(
            'product_id'   => $pid,
            'name'         => $virtual[ $pid ]['name'],
            'virtual_qty'  => $virt,
            'physical_qty' => $phys,
            'diff'         => $diff,
            'group_total'  => $virtual[ $pid ]['group_total'],
            'group_parts'  => $virtual[ $pid ]['group_parts'],
        );
        if ( $diff > 0.004 ) {
            $sobrante++;
        } elseif ( $diff < -0.004 ) {
            $faltante++;
        } else {
            $cuadrados++;
        }

        // Ajuste automático: alinear el stock virtual al conteo físico. IMPORTANTE:
        // el ajuste del cuadre SOLO afecta a la ubicación contada (skip_linked
        // = true). El cuadre es un conteo físico puntual de UNA ubicación: el
        // centro tiene su propia realidad, que se determina con SU conteo. Si
        // el ajuste se propagara al centro, este absorbería la diferencia
        // (merma/sobrante local o transferencia no registrada) con el signo
        // equivocado. La conexión dirigida sí se refleja en el cuadre a través
        // del group_total (la propia + su centro) como contexto.
        if ( $adjust && abs( $diff ) > 0.004 ) {
            $ref = 'Cuadre #' . wp_generate_uuid4();
            $res = $diff > 0
                ? WS_Stock::increase( $pid, $location_id, $diff, 'ajuste', $ref, $note ? $note : 'Ajuste por cuadre de inventario', 0, true )
                : WS_Stock::decrease( $pid, $location_id, abs( $diff ), 'ajuste', $ref, $note ? $note : 'Ajuste por cuadre de inventario', 0, true );
            if ( ! is_wp_error( $res ) ) {
                $ajustados++;
            }
        }
    }

    $summary = sprintf( '%d cuadrados · %d sobrantes · %d faltantes', $cuadrados, $sobrante, $faltante );
    $wpdb->insert( $table, array(
        'location_id' => $location_id,
        'user_id'     => get_current_user_id(),
        'items'       => wp_json_encode( $stored ),
        'summary'     => $summary,
        'adjusted'    => $adjust ? 1 : 0,
        'note'        => $note,
    ), array( '%d', '%d', '%s', '%s', '%d', '%s' ) );

    ws_log_audit( 'stock_count', 'stock', $location_id, array(
        'total'    => count( $stored ),
        'summary'  => $summary,
        'adjusted' => $ajustados,
    ) );

    wp_send_json_success( array(
        'data' => array(
            'id'        => (int) $wpdb->insert_id,
            'summary'   => $summary,
            'cuadrados' => $cuadrados,
            'sobrante'  => $sobrante,
            'faltante'  => $faltante,
            'ajustados' => $ajustados,
            'adjusted'  => $adjust,
        ),
    ) );
}

/**
 * Historial de cuadres de inventario guardados (con su detalle), para auditar
 * cómo evoluciona el inventario físico vs. virtual en cada ubicación.
 */
add_action( 'wp_ajax_ws_stock_counts_list', 'ws_ajax_stock_counts_list' );
function ws_ajax_stock_counts_list() {
    ws_guard( 'stock_count_view' );

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $limit       = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 50;
    $offset      = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

    // Trabajadores: solo cuadres de sus ubicaciones asignadas.
    $allowed_ids = ws_user_location_ids();
    if ( $location_id && ! in_array( $location_id, $allowed_ids, true ) ) {
        wp_send_json_success( array( 'data' => array(), 'total' => 0 ) );
    }

    global $wpdb;
    $table = ws_table_name( 'stock_counts' );
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
        wp_send_json_success( array( 'data' => array(), 'total' => 0 ) );
    }

    $where  = array( '1=1' );
    $params = array();
    if ( $location_id ) {
        $where[]  = 'location_id = %d';
        $params[] = $location_id;
    } elseif ( $allowed_ids ) {
        $ph = implode( ',', array_fill( 0, count( $allowed_ids ), '%d' ) );
        $where[]  = "location_id IN ({$ph})";
        $params   = array_merge( $params, $allowed_ids );
    }
    $total = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ),
        $params
    ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
        array_merge( $params, array( $limit, $offset ) )
    ) );

    $loc_names = array();
    foreach ( ws_user_locations() as $l ) {
        $loc_names[ (int) $l->id ] = $l->name;
    }

    $out = array();
    foreach ( $rows as $r ) {
        $items = json_decode( (string) $r->items, true );
        $items = is_array( $items ) ? $items : array();
        $out[] = array(
            'id'          => (int) $r->id,
            'location_id' => (int) $r->location_id,
            'location_name' => $loc_names[ (int) $r->location_id ] ?? '',
            'summary'     => (string) $r->summary,
            'adjusted'    => (bool) $r->adjusted,
            'note'        => (string) $r->note,
            'created_at'  => mysql2date( 'Y-m-d H:i:s', $r->created_at ),
            'items'       => $items,
        );
    }

    wp_send_json_success( array( 'data' => $out, 'total' => $total ) );
}

/* ---------------- Cache Offline AJAX ---------------- */

add_action( 'wp_ajax_ws_cache_products', 'ws_ajax_cache_products' );
add_action( 'wp_ajax_nopriv_ws_cache_products', 'ws_ajax_cache_products' );
function ws_ajax_cache_products() {
    ws_guard( 'products_view' );

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    // Trabajadores: el catálogo cacheado se limita a sus ubicaciones (aunque
    // pidan location_id=0, que significa "todas las permitidas"). El dueño/
    // admin (o rol vacío) descarga TODOS los productos del negocio.
    $allowed = ws_user_location_ids();
    $is_full = in_array( ws_user_role(), array( 'owner', '' ), true );
    $loc_ids = $location_id ? array( $location_id ) : $allowed;
    $loc_ids = $is_full ? array() : array_values( array_intersect( $loc_ids, $allowed ) );
    $args = array(
        'location_id' => $location_id,
        'location_ids' => $loc_ids,
        'limit' => 1000,
        'active' => 1,
    );

    $products = WS_CRUD::get_products( $args );
    wp_send_json_success( array( 'data' => $products ) );
}

add_action( 'wp_ajax_ws_cache_customers', 'ws_ajax_cache_customers' );
function ws_ajax_cache_customers() {
    ws_guard( 'customers_view' );

    $args = array(
        'limit' => 1000,
    );

    $customers = WS_CRM::get_customers( $args );
    wp_send_json_success( array( 'data' => $customers ) );
}

add_action( 'wp_ajax_ws_cache_locations', 'ws_ajax_cache_locations' );
add_action( 'wp_ajax_nopriv_ws_cache_locations', 'ws_ajax_cache_locations' );
function ws_ajax_cache_locations() {
    ws_guard( 'locations_view' );

    // Trabajadores: solo descargan las ubicaciones de sus puntos asignados.
    $locations = ws_user_locations();
    wp_send_json_success( array( 'data' => $locations ) );
}

/* ---------------- Loyalty AJAX ---------------- */

add_action( 'wp_ajax_ws_loyalty_customers', 'ws_ajax_loyalty_customers' );
function ws_ajax_loyalty_customers() {
    ws_guard( 'loyalty_manage' );

    $args = array(
        'search' => sanitize_text_field( $_POST['search'] ?? '' ),
        'sort_by' => sanitize_text_field( $_POST['sort_by'] ?? 'points_desc' ),
        'limit' => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 20,
        'offset' => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
    );

    $customers = WS_Loyalty::get_customers_with_points( $args );
    $total = WS_Loyalty::count_customers_with_points( $args );

    wp_send_json_success( array( 'data' => $customers, 'total' => $total ) );
}

add_action( 'wp_ajax_ws_loyalty_stats', 'ws_ajax_loyalty_stats' );
function ws_ajax_loyalty_stats() {
    ws_guard( 'loyalty_manage' );

    $stats = WS_Loyalty::get_overall_stats();
    wp_send_json_success( array( 'data' => $stats ) );
}

add_action( 'wp_ajax_ws_loyalty_transactions', 'ws_ajax_loyalty_transactions' );
function ws_ajax_loyalty_transactions() {
    ws_guard( 'loyalty_manage' );

    $customer_id = (int) ( $_POST['customer_id'] ?? 0 );
    if ( ! $customer_id ) {
        wp_send_json_error( array( 'msg' => __( 'ID de cliente inválido.', 'workshop' ) ) );
    }

    $out = array();
    foreach ( WS_CRM::get_loyalty_transactions( $customer_id, 50 ) as $t ) {
        $out[] = array(
            'id'         => (int) $t->id,
            'customer_id'=> (int) $t->customer_id,
            'points'     => (int) $t->points,
            'type'       => $t->type,
            'reference'  => $t->reference,
            'order_id'   => (int) $t->order_id,
            'note'       => $t->note,
            'created_at' => mysql2date( 'Y-m-d H:i:s', $t->created_at ),
        );
    }

    wp_send_json_success( array( 'data' => $out ) );
}

add_action( 'wp_ajax_ws_loyalty_settings', 'ws_ajax_loyalty_settings' );
function ws_ajax_loyalty_settings() {
    ws_guard( 'loyalty_manage' );

    $settings = WS_Loyalty::get_settings();
    wp_send_json_success( array( 'data' => $settings ) );
}

add_action( 'wp_ajax_ws_loyalty_save_settings', 'ws_ajax_loyalty_save_settings' );
function ws_ajax_loyalty_save_settings() {
    ws_guard( 'loyalty_manage' );

    $settings = array(
        'points_per_euro' => (float) ( $_POST['points_per_euro'] ?? 1 ),
        'point_value' => (float) ( $_POST['point_value'] ?? 0.01 ),
        'silver_tier' => (int) ( $_POST['silver_tier'] ?? 100 ),
        'gold_tier' => (int) ( $_POST['gold_tier'] ?? 500 ),
    );

    WS_Loyalty::save_settings( $settings );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_loyalty_adjust_points', 'ws_ajax_loyalty_adjust_points' );
function ws_ajax_loyalty_adjust_points() {
    ws_guard( 'loyalty_manage' );

    $customer_id = (int) ( $_POST['customer_id'] ?? 0 );
    $points = (int) ( $_POST['points'] ?? 0 );
    $reason = sanitize_text_field( $_POST['reason'] ?? '' );

    if ( ! $customer_id || ! $reason ) {
        wp_send_json_error( array( 'msg' => __( 'Datos incompletos.', 'workshop' ) ) );
    }

    WS_Loyalty::adjust_points( $customer_id, $points, $reason );
    wp_send_json_success();
}

/* ---------------------------------------------------------------------
 * Anuncios del negocio (ShopUp → Anuncios)
 * ------------------------------------------------------------------ */

function ws_announcements_json() {
    if ( ! function_exists( 'ws_announcements_panel' ) ) {
        return array();
    }
    return array_map( static function ( $a ) {
        return array(
            'id'          => (int) $a->id,
            'title'       => (string) $a->title,
            'message'     => (string) $a->message,
            'type'        => (string) $a->type,
            'scope'       => (string) $a->scope,
            'pinned'      => (int) $a->pinned,
            'dismissible' => (int) ( $a->dismissible ?? 1 ),
            'pinned_until'=> (string) ( $a->pinned_until ?? '' ),
            'show_from'   => (string) ( $a->show_from ?? '' ),
            'show_until'  => (string) ( $a->show_until ?? '' ),
            'active'      => (int) $a->active,
            'date'        => mysql2date( 'd/m/Y H:i', $a->created_at ),
        );
    }, ws_announcements_panel() );
}

add_action( 'wp_ajax_ws_announcements_list', 'ws_ajax_announcements_list' );
add_action( 'wp_ajax_nopriv_ws_announcements_list', 'ws_ajax_announcements_list' );
function ws_ajax_announcements_list() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() || ! function_exists( 'ws_announcement_can' ) || ! ws_announcement_can() ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'workshop' ) ) );
    }
    wp_send_json_success( array( 'list' => ws_announcements_json() ) );
}

/* Permisos: matriz completa (roles × capacidades) para la app móvil. */
add_action( 'wp_ajax_ws_permissions_get', 'ws_ajax_permissions_get' );
add_action( 'wp_ajax_nopriv_ws_permissions_get', 'ws_ajax_permissions_get' );
function ws_ajax_permissions_get() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }
    if ( ! is_user_logged_in() || ! ws_can( 'permissions_manage' ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'workshop' ) ) );
    }
    $caps = array();
    if ( class_exists( 'WS_Capabilities' ) ) {
        $all = WS_Capabilities::all_caps();
        // Solo los módulos/acciones que la app móvil realmente implementa.
        foreach ( ws_app_caps() as $cap ) {
            $caps[ $cap ] = $all[ $cap ];
        }
    }
    $matrix_full = class_exists( 'WS_Capabilities' ) ? WS_Capabilities::matrix() : array();
    $matrix = array();
    foreach ( array_keys( $matrix_full ) as $role ) {
        $matrix[ $role ] = array();
        foreach ( ws_app_caps() as $cap ) {
            $matrix[ $role ][ $cap ] = ! empty( $matrix_full[ $role ][ $cap ] );
        }
    }
    $roles = array(
        'owner'        => ws_role_label( 'ws_owner' ),
        'storekeeper'  => ws_role_label( 'ws_storekeeper' ),
        'seller'       => ws_role_label( 'ws_seller' ),
    );
    wp_send_json_success( array(
        'data' => array(
            'caps'   => $caps,
            'matrix' => $matrix,
            'roles'  => $roles,
        ),
    ) );
}

add_action( 'wp_ajax_ws_announcement_save', 'ws_ajax_announcement_save' );
function ws_ajax_announcement_save() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    if ( ! function_exists( 'ws_announcement_can' ) || ! ws_announcement_can() ) {
        wp_send_json_error( array( 'msg' => __( 'No tienes permiso.', 'workshop' ) ) );
    }
    // Los anuncios globales del sitio solo los crea el admin.
    if ( 'site' === (string) ( $_POST['scope'] ?? '' ) && ! ws_announcement_can_site() ) {
        wp_send_json_error( array( 'msg' => __( 'No tienes permiso para anuncios globales.', 'workshop' ) ) );
    }
    // Al editar se respeta el alcance y negocio del anuncio original.
    $editing = (int) ( $_POST['id'] ?? 0 );
    if ( $editing && function_exists( 'ws_announcement_manage_can' ) ) {
        $ws_cur_ann = ws_announcement_get( $editing );
        if ( ! $ws_cur_ann || ! ws_announcement_manage_can( $ws_cur_ann ) ) {
            wp_send_json_error( array( 'msg' => __( 'No tienes permiso.', 'workshop' ) ) );
        }
    }
    $id = ws_announcement_save( $_POST, $editing );
    if ( ! $id ) {
        wp_send_json_error( array( 'msg' => __( 'El título es obligatorio.', 'workshop' ) ) );
    }
    ws_log_audit( 'announcement_save', 'announcement', $id );
    wp_send_json_success( array(
        'msg'  => $editing ? __( 'Anuncio actualizado.', 'workshop' ) : __( 'Anuncio enviado.', 'workshop' ),
        'list' => ws_announcements_json(),
    ) );
}

add_action( 'wp_ajax_ws_announcement_toggle', 'ws_ajax_announcement_toggle' );
function ws_ajax_announcement_toggle() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    if ( ! function_exists( 'ws_announcement_can' ) || ! ws_announcement_can() ) {
        wp_send_json_error( array( 'msg' => __( 'No tienes permiso.', 'workshop' ) ) );
    }
    $ann = ws_announcement_get( (int) ( $_POST['id'] ?? 0 ) );
    if ( ! $ann || ( 'site' !== (string) $ann->scope && (int) $ann->business_id !== ws_current_business_id() ) ) {
        wp_send_json_error( array( 'msg' => __( 'Anuncio no encontrado.', 'workshop' ) ) );
    }
    if ( ! ws_announcement_manage_can( $ann ) ) {
        wp_send_json_error( array( 'msg' => __( 'No tienes permiso.', 'workshop' ) ) );
    }
    $field = in_array( (string) ( $_POST['field'] ?? '' ), array( 'active', 'pinned' ), true ) ? sanitize_key( $_POST['field'] ) : 'active';
    ws_announcement_toggle( (int) $ann->id, $field );
    // Al ACTIVAR un anuncio que estaba inactivo, se entrega la notificación a
    // todos los usuarios del negocio (el broadcast inicial solo ocurre al crear).
    if ( 'active' === $field ) {
        $after = ws_announcement_get( (int) $ann->id );
        if ( $after && (int) $after->active === 1 ) {
            ws_announcement_broadcast( (int) $ann->id );
        }
    }
    ws_log_audit( 'announcement_toggle_' . $field, 'announcement', (int) $ann->id );
    wp_send_json_success( array( 'list' => ws_announcements_json() ) );
}

add_action( 'wp_ajax_ws_announcement_delete', 'ws_ajax_announcement_delete' );
function ws_ajax_announcement_delete() {
    if ( ! ws_mobile_auth_user() && ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    if ( ! function_exists( 'ws_announcement_can' ) || ! ws_announcement_can() ) {
        wp_send_json_error( array( 'msg' => __( 'No tienes permiso.', 'workshop' ) ) );
    }
    $ann = ws_announcement_get( (int) ( $_POST['id'] ?? 0 ) );
    if ( ! $ann || ( 'site' !== (string) $ann->scope && (int) $ann->business_id !== ws_current_business_id() ) ) {
        wp_send_json_error( array( 'msg' => __( 'Anuncio no encontrado.', 'workshop' ) ) );
    }
    if ( ! ws_announcement_manage_can( $ann ) ) {
        wp_send_json_error( array( 'msg' => __( 'No tienes permiso.', 'workshop' ) ) );
    }
    ws_announcement_delete( (int) $ann->id );
    ws_log_audit( 'announcement_delete', 'announcement', (int) $ann->id );
    wp_send_json_success( array( 'list' => ws_announcements_json() ) );
}
