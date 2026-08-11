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
 * assets/js/ws-assistant.js; aquí se calcula la configuración por rol/plan y
 * se localiza la variable WSBOT. El historial de intenciones se registra en
 * la opción ws_chatbot_stats para alimentar la mejora continua.
 *
 * NOTA: los assets se llaman ws-assistant.css/.js (no chatbot.*) porque el
 * WAF de InfinityFree bloquea con 403 cualquier archivo estático cuyo nombre
 * contenga "chatbot" (rompía el widget en producción).
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
    wp_enqueue_style( 'ws-chatbot', WS_URL . 'assets/css/ws-assistant.css', array(), WS_VERSION );
    wp_enqueue_script( 'ws-chatbot', WS_URL . 'assets/js/ws-assistant.js', array(), WS_VERSION, true );
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
        'locSlug' => (string) get_query_var( 'ws_location' ),
        'chatbot' => $chatbot,
        'planName'=> $plan_name,
        'home'    => $home,
        'userId'  => get_current_user_id(),
        // WhatsApp del admin del sitio (derivación a humano cuando el bot
        // no resuelve). Se pasa al widget solo para construir el enlace.
        'whatsapp'=> ws_admin_whatsapp_number(),
        // IA opcional (OpenRouter vía proxy PHP): el widget solo sabe si está
        // activa y qué modelo usar; la clave nunca viaja al navegador.
        'llm'     => array(
            'enabled' => '' !== trim( (string) $admin['llm_key'] ),
            'model'   => (string) $admin['llm_model'],
        ),
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
        'guides'    => ws_chatbot_role_guides(),
        'trackUrl'  => admin_url( 'admin-ajax.php' ) . '?action=ws_chatbot_track',
        'apiUrl'    => admin_url( 'admin-ajax.php' ),
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
 * Guías paso a paso por rol para los módulos del panel.
 *
 * Respuestas por defecto específicas: cada módulo se explica según el rol
 * (dueño, almacenero o vendedor) con pasos concretos y su URL. Se entregan
 * al widget como C.guides y el bot las muestra cuando el usuario pide una
 * guía, manual o "cómo se usa" un módulo.
 */
function ws_chatbot_role_guides() {
    $role = ws_user_role();
    if ( ! $role ) {
        return array();
    }
    $biz  = ws_current_business();
    $url  = function ( $page ) use ( $role, $biz ) {
        return ws_panel_url( $role, $page, $biz );
    };

    $guides = array(
        'products' => array(
            'id'    => 'products',
            'label' => __( 'Productos', 'workshop' ),
            'icon'  => 'fa-boxes-stacked',
            'roles' => array( 'owner', 'storekeeper', 'seller' ),
            'url'   => $url( 'products' ),
            'intro_role' => array(
                'owner'       => 'Como dueño gestionas todo el catálogo: creas productos, ajustas precios y eliminas lo que ya no vendes.',
                'storekeeper' => 'Como almacenero mantienes el catálogo al día: creas productos y actualizas precios.',
                'seller'      => 'Como vendedor consultas el catálogo y su disponibilidad para orientar a tus clientes.',
            ),
            'steps' => array(
                'Ve a Productos y pulsa "Nuevo producto".',
                'Completa el nombre y el precio de venta (costo y stock mínimo son opcionales).',
                'Al guardar, el producto queda en tu tienda y en el POS con stock 0 hasta que entres existencias.',
                'Usa la lupa para buscar, el lápiz para editar y el botón de borrar (confirma el aviso) para quitarlo.',
            ),
        ),
        'stock' => array(
            'id'    => 'stock',
            'label' => __( 'Stock', 'workshop' ),
            'icon'  => 'fa-warehouse',
            'roles' => array( 'owner', 'storekeeper' ),
            'url'   => $url( 'stock' ),
            'intro_role' => array(
                'owner'       => 'Como dueño controlas entradas, salidas y transferencias entre tus tiendas.',
                'storekeeper' => 'Como almacenero registras entradas, salidas y transferencias de la mercancía.',
            ),
            'steps' => array(
                'En Stock pulsa "Nueva entrada" para recibir mercancía: elige producto, tienda y cantidad.',
                'La entrada queda registrada y el inventario se actualiza al instante.',
                'Las salidas se usan para mermas o bajas; las transferencias mueven stock entre sucursales.',
                'Mira el aviso de "stock bajo" para reponer antes de quedarte sin producto.',
            ),
        ),
        'orders' => array(
            'id'    => 'orders',
            'label' => __( 'Pedidos', 'workshop' ),
            'icon'  => 'fa-cart-shopping',
            'roles' => array( 'owner', 'storekeeper', 'seller' ),
            'url'   => $url( 'orders' ),
            'intro_role' => array(
                'owner'       => 'Como dueño aceptas, completas o cancelas los pedidos de tus clientes.',
                'storekeeper' => 'Como almacenero ves los pedidos para preparar la mercancía; quien tenga permiso los acepta.',
                'seller'      => 'Como vendedor puedes ver y aceptar los pedidos de tus clientes para agilizar la venta.',
            ),
            'steps' => array(
                'Abre Pedidos: verás las solicitudes pendientes de tus clientes.',
                'Entra a cada pedido para ver cliente, dirección y productos.',
                'Aceptar descuenta el stock automáticamente; Completa cuando entregues; Cancela si no hay disponibilidad.',
                'El cliente puede seguir el estado desde la tienda.',
            ),
        ),
        'pos' => array(
            'id'    => 'pos',
            'label' => __( 'Vender (POS)', 'workshop' ),
            'icon'  => 'fa-cash-register',
            'roles' => array( 'owner', 'seller' ),
            'url'   => $url( 'pos' ),
            'intro_role' => array(
                'owner' => 'Como dueño vendes en caja y supervisas lo que cobra tu equipo.',
                'seller' => 'Como vendedor este es tu módulo: abres caja y cobras a los clientes.',
            ),
            'steps' => array(
                'Ve a Vender (POS) y pulsa "Abrir caja" indicando el efectivo inicial.',
                'Con la caja abierta, busca el producto y agrega la cantidad.',
                'Elige efectivo, transferencia o mixto; si pagan por transferencia, pide el número.',
                'Confirma la venta: el stock se descuenta al momento.',
                'Al cerrar el turno, arquea la caja desde Ventas POS.',
            ),
        ),
        'customers' => array(
            'id'    => 'customers',
            'label' => __( 'Clientes (CRM)', 'workshop' ),
            'icon'  => 'fa-users',
            'roles' => array( 'owner', 'seller' ),
            'url'   => $url( 'customers' ),
            'intro_role' => array(
                'owner' => 'Como dueño gestionas la base de clientes, sus datos y sus puntos.',
                'seller' => 'Como vendedor creas clientes al momento de vender para llevar su historial.',
            ),
            'steps' => array(
                'En Clientes pulsa "Nuevo cliente" y completa nombre y teléfono.',
                'Cada venta o pedido puede asociarse a un cliente.',
                'En la ficha del cliente ves su historial, total gastado y puntos acumulados.',
            ),
        ),
        'reports' => array(
            'id'    => 'reports',
            'label' => __( 'Reportes', 'workshop' ),
            'icon'  => 'fa-chart-line',
            'roles' => array( 'owner', 'storekeeper' ),
            'url'   => $url( 'reports' ),
            'intro_role' => array(
                'owner'       => 'Como dueño ves ventas, ganancias y lo más vendido por periodo.',
                'storekeeper' => 'Como almacenero revisas movimientos y existencias por periodo.',
            ),
            'steps' => array(
                'Abre Reportes y elige el periodo que quieras consultar.',
                'Si tienes varias tiendas, filtra por la que te interese.',
                'Mira ventas, ganancia, movimientos de stock y productos más vendidos.',
                'Exporta la información si la necesitas para tu contabilidad.',
            ),
        ),
        'workers' => array(
            'id'    => 'workers',
            'label' => __( 'Trabajadores', 'workshop' ),
            'icon'  => 'fa-user-gear',
            'roles' => array( 'owner' ),
            'url'   => $url( 'workers' ),
            'intro_role' => array(
                'owner' => 'Como dueño invitas a tu equipo y eliges qué puede hacer cada uno.',
            ),
            'steps' => array(
                'Ve a Trabajadores y pulsa "Invitar".',
                'Completa los datos y elige el rol: vendedor (cobra), almacenero (stock) o dueño (todo).',
                'Asigna las tiendas donde podrá trabajar.',
                'Cada rol ve solo lo que necesita para su tarea.',
            ),
        ),
        'plan' => array(
            'id'    => 'plan',
            'label' => __( 'Mi plan', 'workshop' ),
            'icon'  => 'fa-crown',
            'roles' => array( 'owner' ),
            'url'   => $url( 'plan' ),
            'intro_role' => array(
                'owner' => 'Como dueño ves tu plan, sus límites y cómo mejorarlo.',
            ),
            'steps' => array(
                'Abre Mi plan para ver lo que incluye tu suscripción.',
                'Revisa los límites (productos, tiendas, funciones).',
                'Si necesitas más, solicita la mejora desde ahí.',
            ),
        ),
        'appearance' => array(
            'id'    => 'appearance',
            'label' => __( 'Tu sitio (logo, colores)', 'workshop' ),
            'icon'  => 'fa-palette',
            'roles' => array( 'owner' ),
            'url'   => $url( 'appearance' ),
            'intro_role' => array(
                'owner' => 'Como dueño personalizas la tienda de tu negocio.',
            ),
            'steps' => array(
                'Ve a Tu sitio (apariencia).',
                'Sube el logo de tu negocio y elige los colores.',
                'Guarda: se aplica a tu tienda pública.',
            ),
        ),
    );

    $out = array();
    foreach ( $guides as $g ) {
        if ( ! in_array( $role, (array) $g['roles'], true ) ) {
            continue;
        }
        $out[] = array(
            'id'    => $g['id'],
            'label' => $g['label'],
            'icon'  => $g['icon'],
            'url'   => $g['url'],
            // Intro específica del rol actual (el resto del mapa no viaja al JS).
            'intro' => (string) ( $g['intro_role'][ $role ] ?? '' ),
            'steps' => $g['steps'],
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
        // IA opcional: clave de OpenRouter + modelo. La clave NUNCA sale del
        // servidor; el widget solo pregunta al proxy PHP ws_chatbot_llm.
        'llm_key'        => '',
        'llm_model'      => 'openrouter/auto',
    );
    $opt = get_option( 'ws_chatbot_config', array() );
    $opt = is_array( $opt ) ? $opt : array();
    $out = array(
        'enabled_public' => isset( $opt['enabled_public'] ) ? (int) $opt['enabled_public'] : $defaults['enabled_public'],
        'enabled_panel'  => isset( $opt['enabled_panel'] ) ? (int) $opt['enabled_panel'] : $defaults['enabled_panel'],
        'messages'       => isset( $opt['messages'] ) && is_array( $opt['messages'] ) ? $opt['messages'] : array(),
        'llm_key'        => isset( $opt['llm_key'] ) ? (string) $opt['llm_key'] : '',
        'llm_model'      => ! empty( $opt['llm_model'] ) ? (string) $opt['llm_model'] : $defaults['llm_model'],
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
        array(
            'id' => 'que-es-shopup',
            'patterns' => array( 'que es esta pagina', 'que hace esta pagina', 'que es shopup', 'como funciona esta pagina', 'en que consiste', 'que ofrecen' ),
            'answer'   => 'Este es un mercado de tiendas locales: los negocios publican sus productos y tú compras directo en cada tienda. Si tienes algo que vender, puedes montar tu negocio gratis y gestionar pedidos, stock y ventas desde su panel.',
            'link_target' => 'market',
            'link_label'  => 'Ver el mercado',
            'link_icon'   => 'fa-store-alt',
            'active'   => 1,
        ),
        array(
            'id' => 'devoluciones-cambios',
            'patterns' => array( 'devolucion', 'devolución', 'cambio de producto', 'garantia', 'garantía', 'reembolso', 'quitar el pedido', 'cancelar mi pedido' ),
            'answer'   => 'Las devoluciones y cambios se gestionan directamente con cada tienda. Escríbeles por su página de contacto o usa la página de Contacto del sitio y te orientamos.',
            'link_target' => 'contacto',
            'link_label'  => 'Contactar',
            'link_icon'   => 'fa-envelope',
            'active'   => 1,
        ),
        array(
            'id' => 'envios-entrega',
            'patterns' => array( 'envio', 'envío', 'entrega', 'demora', 'cuando llega', 'reparto', 'a domicilio' ),
            'answer'   => 'Cada tienda define sus opciones de entrega y su costo al hacer el pedido. Entra a la tienda, elige tus productos y verás las opciones disponibles antes de confirmar.',
            'link_target' => 'stores',
            'link_label'  => 'Ver tiendas',
            'link_icon'   => 'fa-truck-fast',
            'active'   => 1,
        ),
        array(
            'id' => 'cuenta-acceso',
            'patterns' => array( 'olvide mi contrasena', 'olvidé mi contraseña', 'cambiar contrasena', 'cambiar contraseña', 'mi cuenta', 'recuperar cuenta', 'entrar a mi cuenta' ),
            'answer'   => 'Para entrar usa tu usuario y contraseña en la página de acceso. Si no recuerdas tu contraseña, puedes restablecerla desde el acceso; y si tienes problemas, escríbenos por Contacto.',
            'link_target' => 'login',
            'link_label'  => 'Ir al acceso',
            'link_icon'   => 'fa-right-to-bracket',
            'active'   => 1,
        ),
        array(
            'id' => 'variantes-producto',
            'patterns' => array( 'tallas', 'colores', 'variantes', 'variedad', 'mismo producto en', 'version del producto' ),
            'answer'   => 'Si un producto tiene variantes (tallas, colores), se indican en su ficha de la tienda. Pregúntale directamente a la tienda si no la ves.',
            'link_target' => 'stores',
            'link_label'  => 'Ver tiendas',
            'link_icon'   => 'fa-shapes',
            'active'   => 1,
        ),
        array(
            'id' => 'multi-ubicacion',
            'patterns' => array( 'sucursal', 'otra tienda', 'mas de una tienda', 'varias tiendas', 'almacen', 'transferir stock', 'transferencia entre tiendas', 'mover productos entre' ),
            'answer'   => 'Un negocio puede tener varias sucursales o almacenes, cada uno con su propio stock. En tu panel, el módulo Stock permite transferir productos entre ubicaciones.',
            'link_target' => 'panel:stock',
            'link_label'  => 'Gestionar stock',
            'link_icon'   => 'fa-arrows-rotate',
            'active'   => 1,
        ),
        array(
            'id' => 'integracion-datos',
            'patterns' => array( 'api', 'erp', 'integrar', 'integracion', 'exportar datos', 'importar productos', 'conectar con otro sistema' ),
            'answer'   => 'Desde el panel puedes importar y exportar productos, y ver reportes exportables. No hay integraciones externas (API/ERP) por ahora; si necesitas algo específico, escríbenos por Contacto.',
            'link_target' => 'panel:products',
            'link_label'  => 'Mis productos',
            'link_icon'   => 'fa-file-import',
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
        case 'login':    return $home . 'login/';
        case 'market':   return $home;
    }
    return $home;
}

/* -------------------------------------------------------------------------
 * Datos en vivo: búsqueda de productos y resumen del negocio
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_chatbot_search', 'ws_ajax_chatbot_search' );
add_action( 'wp_ajax_nopriv_ws_chatbot_search', 'ws_ajax_chatbot_search' );
function ws_ajax_chatbot_search() {
    global $wpdb;
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $q = sanitize_text_field( $_POST['q'] ?? '' );
    if ( mb_strlen( $q ) < 2 ) {
        wp_send_json_error( array( 'msg' => __( 'Escribe al menos 2 letras para buscar.', 'workshop' ) ) );
    }

    $biz   = ws_current_business();
    $role  = ws_user_role();
    $limit = 6;
    $out   = array();

    if ( $role ) {
        // Usuario de negocio: busca en sus ubicaciones (con o sin stock).
        $panel_url = ws_panel_url( $role, 'products', $biz );
        foreach ( array_slice( ws_user_location_ids(), 0, 5 ) as $lid ) {
            foreach ( WS_Stock::stock_rows( array( 'location_id' => $lid, 'search' => $q, 'limit' => 4 ) ) as $r ) {
                $out[] = array(
                    'id'          => (int) $r->product_id,
                    'location_id' => (int) $r->location_id,
                    'name'        => (string) $r->name,
                    'price'       => (float) $r->sale_price,
                    'price_text'  => number_format_i18n( (float) $r->sale_price, 2 ) . ' ' . ( $r->currency ? $r->currency : '€' ),
                    'stock_text'  => (float) $r->qty > 0 ? sprintf( __( 'Stock: %s', 'workshop' ), number_format_i18n( (float) $r->qty, 2 ) ) : __( 'Agotado', 'workshop' ),
                    'where'       => (string) $r->location_name,
                    'url'         => $panel_url,
                    'in_stock'    => (float) $r->qty > 0,
                );
                if ( count( $out ) >= $limit ) {
                    break 2;
                }
            }
        }
    } else {
        // Visitante: se usa el contexto de la PÁGINA que envía el front-end
        // (biz + loc), porque la petición AJAX no conserva los query vars y
        // así la búsqueda funciona también en negocios con slug propio.
        $biz_slug = sanitize_title( $_POST['biz'] ?? '' );
        $loc_slug = sanitize_text_field( $_POST['loc'] ?? '' );
        $target   = $biz_slug ? WS_Business::get_by_slug( $biz_slug ) : $biz;
        $suffix   = $target ? ws_biz_table_suffix( $target ) : '';
        $loc_t    = ws_table_for( $suffix, 'locations' );
        $stock_t  = ws_table_for( $suffix, 'stock' );
        $prod_t   = ws_table_for( $suffix, 'products' );

        $locs = array();
        if ( $loc_slug ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$loc_t} WHERE slug=%s LIMIT 1", $loc_slug ) );
            if ( $row ) {
                $locs[] = $row;
            }
        }
        if ( empty( $locs ) ) {
            $locs = $wpdb->get_results( "SELECT * FROM {$loc_t} WHERE type='pv' ORDER BY name ASC LIMIT 6" );
        }
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        foreach ( $locs as $loc ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT s.qty, p.name, p.sale_price, p.currency FROM {$stock_t} s
                 INNER JOIN {$prod_t} p ON p.id = s.product_id
                 WHERE s.location_id=%d AND p.name LIKE %s AND s.qty>0
                 ORDER BY p.name ASC LIMIT 3",
                (int) $loc->id, $like
            ) );
            foreach ( $rows as $r ) {
                $out[] = array(
                    'name'       => (string) $r->name,
                    'price_text' => number_format_i18n( (float) $r->sale_price, 2 ) . ' ' . ( $r->currency ? $r->currency : '€' ),
                    'stock_text' => sprintf( __( 'Stock: %s', 'workshop' ), number_format_i18n( (float) $r->qty, 2 ) ),
                    'where'      => (string) $loc->name,
                    'url'        => ws_store_url( $loc, $target ),
                    'in_stock'   => true,
                );
                if ( count( $out ) >= $limit ) {
                    break 2;
                }
            }
        }
    }
    wp_send_json_success( array( 'products' => $out, 'q' => $q ) );
}

add_action( 'wp_ajax_ws_chatbot_summary', 'ws_ajax_chatbot_summary' );
function ws_ajax_chatbot_summary() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $role = ws_user_role();
    if ( ! $role ) {
        wp_send_json_error( array( 'msg' => __( 'El resumen del día está disponible para negocios.', 'workshop' ) ) );
    }

    $biz     = ws_current_business();
    $loc_ids = ws_user_location_ids();
    if ( empty( $loc_ids ) ) {
        wp_send_json_success( array( 'summary' => null ) );
    }

    global $wpdb;
    $ph   = implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) );
    $args = array_map( 'intval', $loc_ids );
    $ot   = ws_table_name( 'orders' );
    $pt   = ws_table_name( 'pos_sales' );
    $st   = ws_table_name( 'stock' );
    $prt  = ws_table_name( 'products' );

    $sales_today  = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$ot} WHERE location_id IN ({$ph}) AND status IN ('accepted','completed') AND DATE(created_at)=CURDATE()", ...$args
    ) );
    $sales_today += (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$pt} WHERE location_id IN ({$ph}) AND status='completed' AND DATE(created_at)=CURDATE()", ...$args
    ) );
    $pending = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$ot} WHERE location_id IN ({$ph}) AND status='pending'", ...$args
    ) );
    $low_stock = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$st} s INNER JOIN {$prt} p ON p.id=s.product_id
         WHERE s.location_id IN ({$ph}) AND p.min_stock>0 AND s.qty<=p.min_stock", ...$args
    ) );
    $cash = WS_POS::get_open_cash( (int) $loc_ids[0] );

    wp_send_json_success( array( 'summary' => array(
        'sales_today' => $sales_today,
        'sales_text'  => number_format_i18n( $sales_today, 2 ) . ' ' . ws_currency_symbol(),
        'pending'     => $pending,
        'low_stock'   => $low_stock,
        'cash_open'   => (bool) $cash,
        'urls'        => array(
            'orders'   => ws_panel_url( $role, 'orders', $biz ),
            'stock'    => ws_panel_url( $role, 'stock', $biz ),
            'pos'      => ws_panel_url( $role, 'pos', $biz ),
            'posSales' => ws_panel_url( $role, 'pos-sales', $biz ),
        ),
    ) ) );
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

/* -------------------------------------------------------------------------
 * Acciones desde el chat (Fase 2): contexto para los formularios guiados
 * ---------------------------------------------------------------------- */

/**
 * Contexto mínimo para ejecutar acciones en el panel: id del usuario, moneda
 * y sus ubicaciones con el estado de caja (para reponer stock o vender POS).
 */
add_action( 'wp_ajax_ws_chatbot_meta', 'ws_ajax_chatbot_meta' );
function ws_ajax_chatbot_meta() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $role = ws_user_role();
    if ( ! $role ) {
        wp_send_json_error( array( 'msg' => __( 'Acciones disponibles solo para negocios.', 'workshop' ) ) );
    }
    $locs = array();
    foreach ( ws_user_locations() as $l ) {
        $locs[] = array(
            'id'        => (int) $l->id,
            'name'      => (string) $l->name,
            'open_cash' => (bool) WS_POS::get_open_cash( (int) $l->id ),
        );
    }
    wp_send_json_success( array(
        'user_id'   => get_current_user_id(),
        'currency'  => ws_currency_symbol(),
        'locations' => $locs,
    ) );
}

/**
 * Productos más vendidos del negocio (POS + pedidos) para recomendar en el chat.
 */
add_action( 'wp_ajax_ws_chatbot_top', 'ws_ajax_chatbot_top' );
function ws_ajax_chatbot_top() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $role = ws_user_role();
    if ( ! $role ) {
        wp_send_json_error( array( 'msg' => __( 'Disponible solo para negocios.', 'workshop' ) ) );
    }
    $loc_ids = ws_user_location_ids();
    if ( empty( $loc_ids ) ) {
        wp_send_json_success( array( 'products' => array() ) );
    }

    global $wpdb;
    $ph   = implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) );
    $args = array_map( 'intval', $loc_ids );
    $ps_t = ws_table_name( 'pos_sales' );
    $pi_t = ws_table_name( 'pos_sale_items' );
    $o_t  = ws_table_name( 'orders' );
    $oi_t = ws_table_name( 'order_items' );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT product_id, product_name, SUM(qty) AS qty, SUM(qty*price) AS total FROM (
            SELECT pi.product_id, pi.product_name, pi.qty, pi.price
            FROM {$pi_t} pi INNER JOIN {$ps_t} ps ON ps.id = pi.sale_id
            WHERE ps.location_id IN ({$ph}) AND ps.status = 'completed'
            UNION ALL
            SELECT oi.product_id, oi.product_name, oi.qty, oi.price
            FROM {$oi_t} oi INNER JOIN {$o_t} o ON o.id = oi.order_id
            WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed')
        ) t GROUP BY product_id, product_name ORDER BY qty DESC LIMIT 5",
        ...$args
    ) );

    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[] = array(
            'name'  => (string) $r->product_name,
            'qty'   => (float) $r->qty,
            'total' => (float) $r->total,
        );
    }
    wp_send_json_success( array( 'products' => $out, 'currency' => ws_currency_symbol() ) );
}

/* -------------------------------------------------------------------------
 * IA opcional (Fase 3): proxy a OpenRouter desde PHP.
 *
 * El motor Node.js que se barajó no es viable en hosting compartido
 * (InfinityFree), así que el "cerebro" es un endpoint PHP del propio tema:
 * el admin pega su clave de OpenRouter en wp-admin > Asistente >
 * Comportamiento, y cuando el bot no entiende una frase la deriva a la IA
 * manteniendo el historial breve de la conversación.
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_chatbot_llm', 'ws_ajax_chatbot_llm' );
add_action( 'wp_ajax_nopriv_ws_chatbot_llm', 'ws_ajax_chatbot_llm' );
function ws_ajax_chatbot_llm() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $admin = ws_chatbot_admin_settings();
    $key   = trim( (string) $admin['llm_key'] );
    if ( '' === $key ) {
        wp_send_json_error( array( 'msg' => __( 'La IA no está configurada.', 'workshop' ) ) );
    }
    // El nonce es público (el widget corre para visitantes): la clave del admin
    // no puede exponerse a spam. Límite sencillo por IP: 60 llamadas / 10 min.
    // Se respeta la IP real si el sitio va detrás de Cloudflare u otro proxy
    // (si no, REMOTE_ADDR sería la IP del proxy y todos compartirían el límite).
    $ip = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
    if ( '' === $ip && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $ip = trim( (string) explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
    }
    if ( '' === $ip ) {
        $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
    }
    $rk = 'ws_llm_' . md5( $ip );
    $n  = (int) get_transient( $rk );
    if ( $n >= 60 ) {
        wp_send_json_error( array( 'msg' => __( 'Has alcanzado el límite de consultas por un momento. Inténtalo luego.', 'workshop' ) ) );
    }
    set_transient( $rk, $n + 1, 10 * MINUTE_IN_SECONDS );

    $text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
    if ( '' === trim( $text ) ) {
        wp_send_json_error( array( 'msg' => __( 'Escribe algo para consultar.', 'workshop' ) ) );
    }

    // Historial breve (solo user/assistant, acotado) para dar contexto.
    $history = array();
    foreach ( (array) json_decode( wp_unslash( $_POST['history'] ?? '[]' ), true ) as $m ) {
        $role    = ( 'assistant' === ( $m['role'] ?? '' ) ) ? 'assistant' : 'user';
        $content = (string) ( $m['content'] ?? '' );
        if ( '' !== trim( $content ) ) {
            $history[] = array( 'role' => $role, 'content' => mb_substr( $content, 0, 600 ) );
        }
    }
    $history = array_slice( $history, -6 );

    $system = 'Eres el asistente virtual de ShopUp, un mercado de tiendas locales donde cada negocio tiene su propia tienda online y panel de gestión. ' .
        'Los VISITANTES buscan productos, quieren saber cómo comprar, cómo seguir un pedido, devoluciones y envíos; invítalos a registrarse gratis para montar su negocio. ' .
        'Los NEGOCIOS gestionan en su panel: productos, stock (entradas/salidas/transferencias), pedidos (aceptar/rechazar), ventas POS con caja, clientes (CRM), trabajadores con roles, reportes y planes. ' .
        'El bot puede crear/editar/eliminar productos, reponer stock, aceptar pedidos, crear clientes y registrar ventas si el usuario lo pide. ' .
        'Responde en español, breve y con tono amable (algún emoji). Si te piden algo fuera de estas capacidades, sugiere contactar soporte por la página de Contacto o WhatsApp.';

    $body = array(
        'model'       => (string) $admin['llm_model'],
        'messages'    => array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $history, array( array( 'role' => 'user', 'content' => mb_substr( $text, 0, 1000 ) ) ) ),
        'max_tokens'  => 400,
        'temperature' => 0.5,
    );

    $resp = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
        'timeout' => 25,
        'headers' => array(
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( $body ),
    ) );
    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( array( 'msg' => __( 'La IA no está disponible ahora.', 'workshop' ) ) );
    }
    $code = (int) wp_remote_retrieve_response_code( $resp );
    $json = json_decode( wp_remote_retrieve_body( $resp ), true );
    $text = trim( (string) ( $json['choices'][0]['message']['content'] ?? '' ) );
    if ( $code >= 400 || '' === $text ) {
        $err = (string) ( $json['error']['message'] ?? __( 'La IA no respondió.', 'workshop' ) );
        wp_send_json_error( array( 'msg' => mb_substr( $err, 0, 200 ) ) );
    }

    $log  = get_option( 'ws_chatbot_stats', array() );
    $log  = is_array( $log ) ? $log : array();
    $log['llm:used'] = (int) ( $log['llm:used'] ?? 0 ) + 1;
    update_option( 'ws_chatbot_stats', $log );

    wp_send_json_success( array( 'text' => $text ) );
}