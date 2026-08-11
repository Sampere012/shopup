<?php
/**
 * Chatbot del sitio (asistente por rol y plan).
 *
 * Un único widget que se comporta según quién lo usa:
 *
 * - Visitante / cliente (sin sesión o rol público): SIEMPRE disponible.
 *   Da la bienvenida, sugiere atajos (marketplace, tiendas, ayuda, contacto)
 *   y, si no hay cuenta, convierte al registro de negocio.
 * - Negocio con su panel (dueño / almacenero / vendedor): el asistente se
 *   activa SOLO si el plan del negocio incluye chatbot (has_chatbot). Si el
 *   plan no lo incluye, el widget muestra un aviso de upgrade con la URL de
 *   la página de planes (nunca asiste en el panel).
 * - Administrador del sitio: siempre ve el asistente de panel.
 *
 * La interacción (intenciones, atajos y proactividad) vive en
 * assets/js/chatbot.js; aquí se calcula la configuración por rol/plan y se
 * localiza la variable WSBOT. El historial de intenciones se registra en la
 * opción ws_chatbot_stats para alimentar la mejora continua.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'ws_chatbot_assets' );
function ws_chatbot_assets() {
    if ( is_admin() ) {
        return;
    }
    $conf = ws_chatbot_config();
    if ( ! $conf['show'] ) {
        return;
    }
    wp_enqueue_style( 'ws-chatbot', WS_URL . 'assets/css/chatbot.css', array(), WS_VERSION );
    wp_enqueue_script( 'ws-chatbot', WS_URL . 'assets/js/chatbot.js', array(), WS_VERSION, true );
    wp_localize_script( 'ws-chatbot', 'WSBOT', $conf );
}

/**
 * Conexión al sistema de mensajes (usa el mismo AJAX del tema).
 */
function ws_chatbot_config() {
    $in_panel = '' !== (string) get_query_var( 'ws_role' );
    $role     = ws_user_role();
    $is_admin = current_user_can( 'manage_options' );
    $logged   = is_user_logged_in();

    $biz      = ws_current_business();
    $home     = ws_business_home( $biz );
    $slug     = ( $biz && ! empty( $biz->slug ) ) ? $biz->slug : '';

    // Plan del negocio actual: la activación del panel depende de has_chatbot.
    $chatbot   = false;
    $plan_name = '';
    if ( $role ) {
        $data      = ws_subscription_data( $biz );
        $chatbot   = WS_Plans::has_chatbot( $data['plan'] );
        $plan_name = ! empty( $data['plan'] ) ? $data['plan']->name : '';
    } elseif ( $is_admin && $in_panel ) {
        $chatbot = true;
    }

    // Rol efectivo para construir URLs del panel (admin sin rol = owner).
    $eff_role = $role ? $role : 'owner';

    // Visibilidad configurable desde wp-admin (Asistente > Comportamiento).
    // Por defecto el bot se muestra en público y en el panel; en el panel, si
    // el plan incluye chatbot asiste y si no emite el aviso de upgrade.
    $admin = ws_chatbot_admin_settings();
    $show  = $in_panel ? (bool) $admin['enabled_panel'] : (bool) $admin['enabled_public'];

    $ctx = array(
        'inPanel' => $in_panel,
        'role'    => $is_admin && ! $role ? 'admin' : $role,
        'logged'  => $logged,
        'bizName' => ws_site_name(),
        'bizSlug' => $slug,
        'chatbot' => $chatbot,
        'planName'=> $plan_name,
        'home'    => $home,
    );

    $shortcuts = array(
        'public'  => ws_chatbot_public_shortcuts(),
        'panel'   => ws_chatbot_panel_shortcuts(),
    );

    return array_merge( $ctx, array(
        'show'      => $show,
        'context'   => ws_chatbot_context(),
        'urls'      => array(
            'register' => home_url( '/registro/' ),
            'login'    => $home . 'login/',
            'stores'   => ws_marketplace_stores_url(),
            'marketPlace' => $home,
            'ayuda'    => $home . 'ayuda/',
            'contacto' => $home . 'contacto/',
            'plan'     => ws_panel_url( $eff_role, 'plan', $biz ),
        ),
        'shortcuts' => $shortcuts,
        'strings'   => array_merge( ws_chatbot_strings(), $admin['messages'] ),
        'knowledge' => ws_chatbot_knowledge_config(),
        'trackUrl'  => admin_url( 'admin-ajax.php' ) . '?action=ws_chatbot_track',
        'nonce'     => wp_create_nonce( 'ws_nonce' ),
    ) );
}

/**
 * Página/contexto actual (tienda, panel, marketplace, landing, registro...).
 */
function ws_chatbot_context() {
    $public = get_query_var( 'ws_public' );
    if ( $public ) {
        if ( in_array( $public, array( 'ayuda', 'contacto', 'acerca' ), true ) ) {
            return 'static:' . $public;
        }
        return 'public:' . $public;
    }
    $page = get_query_var( 'ws_page' );
    if ( $page ) {
        return 'panel:' . $page;
    }
    if ( get_query_var( 'ws_biz_home' ) ) {
        return 'landing';
    }
    if ( get_query_var( 'ws_loc' ) ) {
        return 'store';
    }
    if ( is_front_page() ) {
        return 'marketplace';
    }
    return 'other';
}

/**
 * Atajos para visitantes y clientes (público).
 */
function ws_chatbot_public_shortcuts() {
    $biz = ws_current_business();
    $out = array();

    $loc = get_query_var( 'ws_location' );
    if ( $loc ) {
        $out['tienda'] = array(
            'label' => __( 'Esta tienda', 'workshop' ),
            'url'   => ws_store_url( $loc, $biz ),
            'icon'  => 'fa-store',
        );
    }
    $out['marketplace'] = array(
        'label' => __( 'Todas las tiendas', 'workshop' ),
        'url'   => ws_marketplace_stores_url(),
        'icon'  => 'fa-store-alt',
    );
    $out['ayuda'] = array(
        'label' => __( 'Ayuda', 'workshop' ),
        'url'   => ws_business_home( $biz ) . 'ayuda/',
        'icon'  => 'fa-circle-question',
    );
    $out['contacto'] = array(
        'label' => __( 'Contacto', 'workshop' ),
        'url'   => ws_business_home( $biz ) . 'contacto/',
        'icon'  => 'fa-envelope',
    );
    return $out;
}

/**
 * Atajos del panel según permisos del usuario. Colapsan "dónde hago X".
 */
function ws_chatbot_panel_shortcuts() {
    $biz  = ws_current_business();
    $user = wp_get_current_user();
    if ( ! $user || ! $user->exists() ) {
        return array();
    }

    $items = array(
        'dashboard'   => array( __( 'Inicio / dashboard', 'workshop' ), 'fa-gauge-high', 'dashboard', '' ),
        'productNew'  => array( __( 'Crear producto', 'workshop' ), 'fa-plus', 'products', 'products_create' ),
        'products'    => array( __( 'Ver productos', 'workshop' ), 'fa-boxes-stacked', 'products', 'products_view' ),
        'orders'      => array( __( 'Pedidos', 'workshop' ), 'fa-cart-shopping', 'orders', 'orders_view' ),
        'stock'       => array( __( 'Stock', 'workshop' ), 'fa-warehouse', 'stock', 'stock_view' ),
        'customers'   => array( __( 'Clientes (CRM)', 'workshop' ), 'fa-users', 'customers', 'customers_view' ),
        'pos'         => array( __( 'Vender (POS)', 'workshop' ), 'fa-cash-register', 'pos', 'pos_sell' ),
        'posSales'    => array( __( 'Ventas POS', 'workshop' ), 'fa-receipt', 'pos-sales', 'pos_view' ),
        'suppliers'   => array( __( 'Proveedores', 'workshop' ), 'fa-truck-field', 'suppliers', 'suppliers_view' ),
        'reports'     => array( __( 'Reportes', 'workshop' ), 'fa-chart-line', 'reports', 'reports_view' ),
        'workers'     => array( __( 'Trabajadores', 'workshop' ), 'fa-user-gear', 'workers', 'workers_manage' ),
        'loyalty'     => array( __( 'Fidelización', 'workshop' ), 'fa-gift', 'loyalty', 'loyalty_manage' ),
        'reviews'     => array( __( 'Valoraciones', 'workshop' ), 'fa-star', 'reviews', 'reviews_view' ),
        'appearance'  => array( __( 'Tu sitio (logo, colores)', 'workshop' ), 'fa-palette', 'appearance', array( 'site_manage', 'layout_manage' ) ),
        'plan'        => array( __( 'Mi plan / upgrade', 'workshop' ), 'fa-crown', 'plan', '' ),
    );

    $out = array();
    foreach ( $items as $id => $it ) {
        list( $label, $icon, $page, $caps ) = $it;
        $caps  = (array) $caps;
        $allow = empty( $caps );
        foreach ( $caps as $cap ) {
            if ( WS_Capabilities::can( $cap ) ) {
                $allow = true;
                break;
            }
        }
        if ( ! $allow ) {
            continue;
        }
        $role = ws_user_role();
        if ( ! $role ) {
            $role = 'owner';
        }
        $out[ $id ] = array(
            'label' => $label,
            'url'   => ws_panel_url( $role, $page, $biz ),
            'icon'  => $icon,
        );
    }
    return $out;
}

/**
 * Mensajes del widget (español). Se mantienen centralizados para poder
 * traducirlos o ajustarlos sin tocar el JS.
 */
function ws_chatbot_strings() {
    return array(
        'title'          => __( 'Asistente', 'workshop' ),
        'subtitle'       => __( 'online', 'workshop' ),
        'placeholder'    => __( 'Escribe tu pregunta…', 'workshop' ),
        'typing'         => __( 'Escribiendo…', 'workshop' ),
        'open'           => __( 'Abrir chat', 'workshop' ),
        'welcomePublic'  => __( '¡Hola! 👋 Soy tu asistente. ¿Qué estás buscando hoy?', 'workshop' ),
        'welcomeGuest'   => __( '¡Hola! 👋 ¿Exploras o quieres montar tu propio negocio? Te oriento en lo que necesites.', 'workshop' ),
        'welcomePanel'   => __( '¡Hola! 👋 Soy tu asistente del panel. Dime qué quieres hacer (crear producto, pedidos, stock, reportes…) y te llevo.', 'workshop' ),
        'welcomeLocked'  => __( 'El asistente no está incluido en tu plan actual 😕', 'workshop' ),
        'lockedBody'     => __( 'Actívalo desde la página de planes y trabaja tu negocio con ayuda en tiempo real en tu panel.', 'workshop' ),
        'goPlan'         => __( 'Ver planes y activarlo', 'workshop' ),
        'atajosTitle'    => __( 'Estos son los accesos directos:', 'workshop' ),
        'noAtajos'       => __( 'Aquí tienes las opciones más usadas:', 'workshop' ),
        'productHint'    => __( 'Te llevo directo al formulario para crear tu producto.', 'workshop' ),
        'stockHint'      => __( 'Aquí gestionas entradas, salidas y transferencias de tu inventario.', 'workshop' ),
        'ordersHint'     => __( 'Revisa, acepta y gestiona tus pedidos.', 'workshop' ),
        'registerHook'   => __( 'Y si tienes algo que vender, montar tu negocio aquí toma menos de 5 minutos 😉', 'workshop' ),
        'fallback'       => __( 'Aún estoy aprendiendo esa respuesta 😅. Te dejo los accesos directos mientras tanto.', 'workshop' ),
        'storeTeaser'    => __( '¿Estás en una tienda? Pregúntame por productos, cómo comprar o segui un pedido.', 'workshop' ),
        'welcomeNewUser' => __( '¡Hola! Bienvenido. Te ayudo a encontrar todo lo que necesitas en el sitio.', 'workshop' ),
    );
}

/* -------------------------------------------------------------------------
 * Configuración del administrador (wp-admin > Asistente)
 * ---------------------------------------------------------------------- */

/**
 * Ajustes del admin (mensajes personalizados + comportamiento).
 */
function ws_chatbot_admin_settings() {
    $defaults = array(
        'enabled_public' => 1,
        'enabled_panel'  => 1,
        'messages'       => array(),
    );
    $opt = get_option( 'ws_chatbot_config', array() );
    $opt = is_array( $opt ) ? $opt : array();
    $out = array(
        'enabled_public' => isset( $opt['enabled_public'] ) ? (int) $opt['enabled_public'] : $defaults['enabled_public'],
        'enabled_panel'  => isset( $opt['enabled_panel'] ) ? (int) $opt['enabled_panel'] : $defaults['enabled_panel'],
        'messages'       => isset( $opt['messages'] ) && is_array( $opt['messages'] ) ? $opt['messages'] : array(),
    );
    return $out;
}

/* -------------------------------------------------------------------------
 * Base de conocimiento (preguntas/respuestas editables por el admin)
 * ---------------------------------------------------------------------- */

/**
 * Conocimiento por defecto: cubre lo esencial para visitantes y negocios.
 * El administrador puede editar, desactivar o borrar cada ítem desde wp-admin.
 */
function ws_chatbot_default_knowledge() {
    return array(
        array(
            'id' => 'crear-producto',
            'patterns' => array( 'crear producto', 'nuevo producto', 'agregar producto', 'agregar un producto', 'crear un producto', 'doy de alta un producto', 'añadir producto', 'anadir producto' ),
            'answer'   => 'En tu panel ve a Productos y pulsa "Nuevo producto". Llena nombre, precio, stock inicial y categoría, y con "Guardar" quedará listo para vender en tu tienda. Te llevo directo:',
            'link_target' => 'panel:products',
            'link_label'  => 'Crear producto',
            'link_icon'   => 'fa-plus',
            'active'   => 1,
        ),
        array(
            'id' => 'abrir-caja-pos',
            'patterns' => array( 'abrir caja', 'caja pos', 'empezar a vender', 'cobrar', 'como vendo en el pos', 'punto de venta', 'vender en el pos' ),
            'answer'   => 'Abre el módulo Vender (POS) y pulsa "Abrir caja" indicando el efectivo inicial. Con la caja abierta puedes cobrar al contado o por transferencia, y cada venta descuenta el stock al instante.',
            'link_target' => 'panel:pos',
            'link_label'  => 'Ir al POS',
            'link_icon'   => 'fa-cash-register',
            'active'   => 1,
        ),
        array(
            'id' => 'entrada-stock',
            'patterns' => array( 'entrada de stock', 'agregar stock', 'reponer inventario', 'subir existencias', 'como entro mercancia', 'entrar mercancia', 'stock' ),
            'answer'   => 'En Stock pulsa "Nueva entrada", elige el producto y la cantidad; la entrada queda registrada y el inventario se actualiza al momento. Las salidas y transferencias se hacen desde el mismo módulo.',
            'link_target' => 'panel:stock',
            'link_label'  => 'Gestionar stock',
            'link_icon'   => 'fa-warehouse',
            'active'   => 1,
        ),
        array(
            'id' => 'gestionar-pedidos',
            'patterns' => array( 'revisar pedidos', 'aceptar pedido', 'mis pedidos', 'gestionar pedidos', 'pedido nuevo', 'ordenes', 'ordenes de mi tienda' ),
            'answer'   => 'En Pedidos verás las solicitudes de tus clientes. Ábrelas para aceptar, completar o cancelar; al aceptar una, el stock se descuenta automáticamente.',
            'link_target' => 'panel:orders',
            'link_label'  => 'Ver pedidos',
            'link_icon'   => 'fa-cart-shopping',
            'active'   => 1,
        ),
        array(
            'id' => 'crear-negocio',
            'patterns' => array( 'crear mi negocio', 'registrarme', 'montar tienda', 'abrir mi tienda', 'empezar a vender', 'cuenta gratis', 'registrar negocio', 'crear cuenta' ),
            'answer'   => 'Es gratis empezar: crea tu cuenta y en menos de 5 minutos tendrás tu tienda online lista para vender con pedidos, POS y stock. Crea tu negocio aquí:',
            'link_target' => 'register',
            'link_label'  => 'Crear mi negocio',
            'link_icon'   => 'fa-rocket',
            'active'   => 1,
        ),
        array(
            'id' => 'como-comprar',
            'patterns' => array( 'como comprar', 'comprar en una tienda', 'hacer un pedido', 'quiero comprar', 'comprar' ),
            'answer'   => 'Entra a cualquier tienda del mercado, elige tus productos y haz el pedido; el negocio lo recibe y lo gestiona. ¿Te llevo a las tiendas?',
            'link_target' => 'stores',
            'link_label'  => 'Ver tiendas',
            'link_icon'   => 'fa-store',
            'active'   => 1,
        ),
        array(
            'id' => 'seguir-pedido',
            'patterns' => array( 'donde esta mi pedido', 'seguir mi pedido', 'estado de mi pedido', 'rastrear pedido', 'seguimiento', 'mi compra' ),
            'answer'   => 'Puedes consultar el estado de tu pedido en la tienda donde lo hiciste, usando tu número de pedido en la opción de seguimiento.',
            'link_target' => 'stores',
            'link_label'  => 'Ir a las tiendas',
            'link_icon'   => 'fa-truck-fast',
            'active'   => 1,
        ),
        array(
            'id' => 'agregar-trabajador',
            'patterns' => array( 'agregar trabajador', 'invitar empleado', 'nuevo empleado', 'dar permisos', 'crear usuario', 'agregar empleado' ),
            'answer'   => 'En Trabajadores pulsa "Invitar" y asigna el rol (vendedor, almacenero o dueño) junto con las ubicaciones; cada rol ve solo lo que necesita para su tarea.',
            'link_target' => 'panel:workers',
            'link_label'  => 'Trabajadores',
            'link_icon'   => 'fa-user-gear',
            'active'   => 1,
        ),
        array(
            'id' => 'mi-plan',
            'patterns' => array( 'mi plan', 'que incluye mi plan', 'mejorar plan', 'upgrade', 'precios', 'planes', 'cuanto cuesta' ),
            'answer'   => 'Tu plan define los límites y funciones del negocio. Desde "Mi plan" ves lo que incluyes y puedes solicitar una mejora cuando lo necesites.',
            'link_target' => 'panel:plan',
            'link_label'  => 'Ver mi plan',
            'link_icon'   => 'fa-crown',
            'active'   => 1,
        ),
        array(
            'id' => 'reportes-negocio',
            'patterns' => array( 'ver reportes', 'ventas del mes', 'ganancias', 'estadisticas', 'facturacion', 'reportes' ),
            'answer'   => 'En Reportes verás ventas, movimientos y los productos más vendidos por periodo. Puedes filtrar por fechas y exportar la información.',
            'link_target' => 'panel:reports',
            'link_label'  => 'Ver reportes',
            'link_icon'   => 'fa-chart-line',
            'active'   => 1,
        ),
        array(
            'id' => 'contacto-soporte',
            'patterns' => array( 'contactar soporte', 'escribir al admin', 'problema con la pagina', 'reportar error', 'whatsapp', 'soporte', 'ayuda por favor' ),
            'answer'   => 'Ve a la página de Contacto y envíanos tu consulta; el equipo del sitio te atiende. También puedes revisar la sección de Ayuda con preguntas frecuentes.',
            'link_target' => 'contacto',
            'link_label'  => 'Contacto',
            'link_icon'   => 'fa-envelope',
            'active'   => 1,
        ),
    );
}

/**
 * Conocimiento activo: los ítems del admin, o los seeds si aún no configuró.
 */
function ws_chatbot_knowledge() {
    $kb = get_option( 'ws_chatbot_knowledge', null );
    if ( null === $kb ) {
        // Primera vez: sembrar los ítems por defecto (el admin puede editarlos).
        $kb = ws_chatbot_default_knowledge();
        update_option( 'ws_chatbot_knowledge', $kb );
    }
    $kb  = is_array( $kb ) ? $kb : array();
    $out = array();
    foreach ( $kb as $item ) {
        if ( empty( $item['active'] ) ) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * Convierte la base de conocimiento a la forma que entiende el widget JS,
 * resolviendo los enlaces según el rol/negocio del usuario actual.
 */
function ws_chatbot_knowledge_config() {
    $out       = array();
    $has_role  = (bool) ws_user_role();
    foreach ( ws_chatbot_knowledge() as $item ) {
        // Los ítems que enlazan al panel (requieren sesión de negocio) se
        // ocultan a visitantes: el bot público orienta a comprar o registrarse.
        if ( ! $has_role && 0 === strpos( (string) ( $item['link_target'] ?? '' ), 'panel:' ) ) {
            continue;
        }
        $link = '';
        if ( ! empty( $item['link_target'] ) ) {
            $link = ws_chatbot_resolve_link( $item['link_target'] );
        }
        $out[] = array(
            'id'      => sanitize_key( $item['id'] ),
            'patterns'=> array_map( 'trim', (array) $item['patterns'] ),
            'answer'  => (string) ( $item['answer'] ?? '' ),
            'chip'    => $link ? array(
                'label' => (string) ( $item['link_label'] ?? __( 'Ir', 'workshop' ) ),
                'url'   => $link,
                'icon'  => (string) ( $item['link_icon'] ?? 'fa-arrow-pointer' ),
            ) : null,
        );
    }
    return $out;
}

/**
 * Resuelve un target de enlace (clave semántica) a una URL real según el
 * contexto (rol y negocio del usuario).
 */
function ws_chatbot_resolve_link( $target ) {
    $biz  = ws_current_business();
    $home = ws_business_home( $biz );
    if ( 0 === strpos( (string) $target, 'panel:' ) ) {
        $role = ws_user_role();
        if ( ! $role ) {
            $role = 'owner';
        }
        $page = substr( (string) $target, 6 );
        return ws_panel_url( $role, $page, $biz );
    }
    switch ( (string) $target ) {
        case 'register': return home_url( '/registro/' );
        case 'stores':   return ws_marketplace_stores_url();
        case 'ayuda':    return $home . 'ayuda/';
        case 'contacto': return $home . 'contacto/';
        case 'market':   return $home;
    }
    return $home;
}

/* -------------------------------------------------------------------------
 * Analítica mínima: qué intenciones se usan y dónde (mejora continua)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_chatbot_track', 'ws_ajax_chatbot_track' );
add_action( 'wp_ajax_nopriv_ws_chatbot_track', 'ws_ajax_chatbot_track' );
function ws_ajax_chatbot_track() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'workshop' ) ) );
    }
    $intent = sanitize_key( (string) ( $_POST['intent'] ?? '' ) );
    $mode   = sanitize_key( (string) ( $_POST['mode'] ?? 'public' ) );
    if ( '' === $intent ) {
        wp_send_json_error( array( 'msg' => __( 'Intención inválida.', 'workshop' ) ) );
    }
    $key  = $mode . ':' . $intent;
    $log  = get_option( 'ws_chatbot_stats', array() );
    $log  = is_array( $log ) ? $log : array();
    $log[ $key ]        = (int) ( $log[ $key ] ?? 0 ) + 1;
    $log['_total']      = (int) ( $log['_total'] ?? 0 ) + 1;
    $log['_last']       = current_time( 'mysql' );
    update_option( 'ws_chatbot_stats', $log );
    wp_send_json_success();
}