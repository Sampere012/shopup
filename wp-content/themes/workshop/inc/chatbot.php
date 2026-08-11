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
/**
 * Conocimiento de contexto de toda la app: se suma SIEMPRE a la base editable
 * del administrador (no se puede editar desde wp-admin, pero cubre cualquier
 * pregunta sobre el funcionamiento del sitio, el panel, los planes, el modo
 * offline, la seguridad y los reportes programados).
 */
function ws_chatbot_knowledge_extras() {
    $p = function ( $id, $patterns, $answer, $target = '', $label = '', $icon = '' ) {
        return array(
            'id'          => $id,
            'patterns'    => $patterns,
            'answer'      => $answer,
            'link_target' => $target,
            'link_label'  => $label,
            'link_icon'   => $icon,
            'active'      => 1,
        );
    };
    $P = 'panel:'; // atajo: enlaces al panel

    return array(
        // ---------- Cuenta y acceso ----------
        $p( 'cuenta-registrarse', array( 'como me registro', 'registrarme', 'crear cuenta', 'hacer una cuenta', 'registrarse gratis', 'crear una cuenta' ), 'Crear tu cuenta es gratis y no necesitas tarjeta: pulsa "Registrarse", llena nombre, correo y contraseña, y en minutos tendrás tu espacio para montar tu negocio.', 'register', 'Crear cuenta', 'fa-user-plus' ),
        $p( 'cuenta-login', array( 'como entro a mi cuenta', 'iniciar sesion', 'entrar a mi panel', 'acceder a mi cuenta', 'como me logueo', 'iniciar sesión' ), 'Entra con tu correo y contraseña desde el botón "Entrar". Si eres de un negocio, el sistema te lleva directo a tu panel según tu rol (dueño, almacenero o vendedor).', 'login', 'Entrar', 'fa-right-to-bracket' ),
        $p( 'cuenta-password', array( 'olvide mi contrasena', 'recuperar contrasena', 'cambiar contrasena', 'resetear password', 'olvide la clave' ), 'En la pantalla de entrada pulsa "¿Olvidaste tu contraseña?". Te enviaremos un enlace a tu correo para restablecerla en minutos.', 'login', 'Recuperar', 'fa-key' ),
        $p( 'cuenta-perfil', array( 'editar mi perfil', 'cambiar mi nombre de usuario', 'cambiar mi correo', 'mi perfil', 'datos de mi cuenta' ), 'Tu perfil (nombre, correo y contraseña) se edita desde el panel en Ajustes/Perfil. Los dueños también pueden invitar y gestionar trabajadores ahí.', $P . 'workers', 'Mi perfil', 'fa-user' ),

        // ---------- Planes y pagos ----------
        $p( 'plan-gratis', array( 'es gratis', 'tiene costo', 'cuanto cuesta registrarse', 'plan gratis', 'prueba gratis', 'dias de prueba' ), 'El sitio se usa gratis con una prueba de 7 días para nuevos negocios: montas tu tienda, subes productos y vendes sin pagar nada. Después eliges el plan que mejor te venga.', 'register', 'Empezar gratis', 'fa-gift' ),
        $p( 'plan-planes', array( 'que planes hay', 'planes disponibles', 'cual es el mejor plan', 'planes y precios', 'ver planes', 'tarifas' ), 'Tenemos planes pensados para cada tamaño de negocio: desde el plan inicial hasta el plan Pro con más productos, más tiendas (ubicaciones) y más trabajadores. El panel > Plan te muestra las opciones y el tuyo actual.', $P . 'plan', 'Ver planes', 'fa-crown' ),
        $p( 'plan-cambiar', array( 'subir de plan', 'cambiar de plan', 'upgrade de plan', 'ampliar mi plan', 'mejorar mi plan' ), 'Desde el panel > Plan eliges el nuevo plan y sigues el pago. Los cambios se aplican al momento y tus productos y ventas no se tocan.', $P . 'plan', 'Mi plan', 'fa-arrow-up' ),
        $p( 'plan-limites', array( 'limite de productos', 'me dice que llegue al limite', 'plan lleno', 'no puedo crear mas productos', 'limites del plan' ), 'Cada plan tiene un tope de productos, ubicaciones y trabajadores. Si el panel te avisa del límite, sube de plan o elimina lo que ya no uses. El asistente respeta esos límites en todo lo que crea por ti.', $P . 'plan', 'Mi plan', 'fa-gauge-high' ),
        $p( 'plan-pago', array( 'como pago', 'metodos de pago del plan', 'pagar el plan', 'factura', 'recibo de pago' ), 'El pago del plan se gestiona desde el panel > Plan, donde verás los métodos disponibles para tu zona. Las ventas de tu tienda se cobran aparte, directo con tus clientes.', $P . 'plan', 'Pagar plan', 'fa-credit-card' ),
        $p( 'plan-moneda', array( 'que moneda se usa', 'moneda del sitio', 'cup', 'pesos', 'precios en' ), 'Cada tienda define su moneda (por ejemplo CUP o CUC). Los precios que ves en el marketplace son los que cada negocio configuró en su catálogo.', $P . 'products', 'Ver precios', 'fa-coins' ),

        // ---------- Marketplace y compras ----------
        $p( 'compra-buscar', array( 'buscar un producto', 'buscar en el mercado', 'como busco', 'encontrar un producto', 'buscador de productos' ), 'Usa el buscador del marketplace: escribe el nombre del producto o de la tienda y filtra por tienda o precio. Cada producto te muestra su precio y si hay stock.', '', 'Buscar', 'fa-magnifying-glass' ),
        $p( 'compra-carrito', array( 'agregar al carrito', 'como agrego al carrito', 'mi carrito', 'quitar del carrito', 'ver mi carrito' ), 'En la página de cada tienda pulsa "Añadir al carrito" en los productos. El carrito (botón flotante) agrupa todo; desde ahí confirmas el pedido con tus datos y te llega por WhatsApp.', '', 'Ver carrito', 'fa-cart-shopping' ),
        $p( 'compra-checkout', array( 'confirmar pedido', 'finalizar compra', 'checkout', 'como hago el pedido', 'enviar pedido' ), 'Al finalizar tu compra escribes tu nombre, teléfono y dirección (si aplica). El pedido llega directo a la tienda, que lo acepta y te contacta por WhatsApp para coordinar entrega o recogida.', '', 'Comprar', 'fa-bag-shopping' ),
        $p( 'compra-whatsapp', array( 'pedido por whatsapp', 'whatsapp de la tienda', 'escribir por whatsapp', 'contactar tienda por whatsapp' ), 'Cada tienda muestra su WhatsApp en su página: puedes escribirles directo para dudas, encargos o coordinar la entrega. El pedido formal se hace desde el carrito para que quede registrado.', '', 'Ver tiendas', 'fa-whatsapp' ),
        $p( 'compra-recoger', array( 'recoger en tienda', 'pickup', 'pasar a buscar', 'retirar pedido' ), 'La mayoría de las tiendas ofrece recogida: coordina con la tienda (por WhatsApp) el horario y el punto de recogida después de confirmar tu pedido.', '', 'Ver tiendas', 'fa-store' ),
        $p( 'compra-agotado', array( 'producto agotado', 'sin stock', 'no hay stock', 'producto agotado cuando reponen' ), 'Si un producto está agotado, puedes preguntar a la tienda por WhatsApp cuándo lo reponen. Los negocios reciben aviso automático cuando su stock queda bajo.', '', 'Ver tiendas', 'fa-circle-exclamation' ),
        $p( 'compra-resenas', array( 'dejar una resena', 'valorar tienda', 'opiniones', 'calificar una compra', 'estrellas' ), 'Puedes valorar a una tienda desde su página o después de recibir tu pedido. Tus reseñas ayudan a otros clientes a decidir y a la tienda a mejorar.', '', 'Ver tiendas', 'fa-star' ),

        // ---------- Negocio: tienda y catálogo ----------
        $p( 'negocio-crear-tienda', array( 'crear mi tienda', 'montar mi negocio', 'abrir mi tienda', 'crear mi negocio online', 'tener mi tienda' ), 'Crea tu cuenta gratis, y desde tu panel > Tienda configuras nombre, logo, descripción, horario y ubicación. Sube tus productos y tu tienda queda visible en el marketplace al instante.', $P . 'settings', 'Mi tienda', 'fa-store' ),
        $p( 'negocio-apariencia', array( 'cambiar el logo', 'foto de mi tienda', 'cambiar la imagen de portada', 'apariencia de mi tienda', 'personalizar mi tienda' ), 'Desde el panel > Tienda editas el logo, la imagen de portada, la descripción y los colores de tu tienda. Los cambios se ven al momento en tu página pública.', $P . 'settings', 'Personalizar', 'fa-palette' ),
        $p( 'negocio-horario', array( 'poner horario', 'horario de mi tienda', 'cambiar horario', 'horas de apertura' ), 'Configura tu horario en el panel > Tienda: los clientes lo ven en tu página y saben cuándo pueden recoger o recibir sus pedidos.', $P . 'settings', 'Horario', 'fa-clock' ),
        $p( 'negocio-categorias', array( 'crear categoria', 'categorias de productos', 'organizar productos por categoria', 'agregar categoria' ), 'Agrupa tus productos por categorías desde el panel > Productos. Así tu catálogo queda ordenado y es más fácil de encontrar para tus clientes.', $P . 'products', 'Categorías', 'fa-tags' ),
        $p( 'negocio-sku', array( 'codigo de barras', 'sku', 'codigo interno de producto', 'identificar producto por codigo' ), 'Cada producto puede tener un código (SKU) para identificarlo rápido en el POS y en las búsquedas. Se asigna al crear o editar el producto.', $P . 'products', 'Productos', 'fa-barcode' ),
        $p( 'negocio-fraccion', array( 'producto madre', 'fraccionamiento', 'vender por fracciones', 'producto padre e hijo', 'medios y cuartos' ), 'Puedes crear productos madre que se venden por fracciones (por ejemplo 1 unidad = 2 medios). Al vender fracciones, el stock del padre se descuenta automáticamente para que siempre cuadre.', $P . 'products', 'Productos', 'fa-scissors' ),
        $p( 'negocio-importar', array( 'importar productos', 'subir muchos productos', 'cargar productos en lote', 'importar catalogo', 'exportar productos' ), 'Para subir muchos productos a la vez usa la importación del panel > Productos (archivo con tus datos). También puedes exportar tu catálogo para respaldo.', $P . 'products', 'Importar', 'fa-file-import' ),
        $p( 'negocio-transferir', array( 'transferir stock', 'pasar stock entre tiendas', 'mover productos de tienda', 'transferencia entre ubicaciones' ), 'Si tienes varias tiendas, mueve stock entre ellas desde el panel > Stock > Transferencia. La mercancía sale de una ubicación y entra en otra al instante.', $P . 'stock', 'Stock', 'fa-arrows-left-right' ),
        $p( 'negocio-multi-tienda', array( 'varias tiendas', 'otra ubicacion', 'agregar otra tienda', 'sucursales', 'tengo dos tiendas' ), 'Tu plan puede incluir varias ubicaciones (tiendas). Desde el panel creas la nueva ubicación y asignas productos y stock a cada una. El marketplace las muestra por separado.', $P . 'locations', 'Ubicaciones', 'fa-location-dot' ),

        // ---------- Ventas y caja ----------
        $p( 'pos-cerrar-caja', array( 'cerrar caja', 'arqueo de caja', 'cuadre de caja', 'terminar la jornada', 'contar la caja' ), 'Al cerrar la caja el POS te pide el efectivo final y hace el arqueo: verás cuánto vendiste, cuánto entró en efectivo y si hay diferencias.', $P . 'pos', 'POS', 'fa-cash-register' ),
        $p( 'pos-transferencia', array( 'cobrar por transferencia', 'venta con tarjeta', 'pago movil', 'cobrar por envio', 'venta transferida' ), 'En el POS puedes cobrar en efectivo o por transferencia: registra el monto y el número de referencia, y la venta queda completa con su historial.', $P . 'pos', 'Cobrar', 'fa-mobile-screen' ),
        $p( 'pos-historial', array( 'historial de ventas', 'ventas anteriores', 'ver todas mis ventas', 'reporte de ventas del pos' ), 'Todas tus ventas del POS quedan en el panel > Reportes o en Ventas, con detalle por día, producto y forma de pago. También puedes pedirme un reporte aquí mismo.', $P . 'reports', 'Reportes', 'fa-clock-rotate-left' ),
        $p( 'pos-devolver', array( 'devolver una venta', 'anular venta', 'reembolso de venta', 'venta erronea' ), 'Si te equivocas en una venta del POS, puedes anularla y el stock se repone automáticamente. Hazlo desde el historial de ventas o contacta con soporte si necesitas ayuda.', $P . 'pos', 'POS', 'fa-rotate-left' ),

        // ---------- Clientes y fidelidad ----------
        $p( 'cli-crm', array( 'mis clientes', 'gestionar clientes', 'lista de clientes', 'agregar clientes', 'crm' ), 'El panel > Clientes guarda a quienes te compran: nombre, teléfono y su historial. Desde ahí puedes crear clientes, buscarlos y hasta ofrecerles promociones.', $P . 'customers', 'Clientes', 'fa-users' ),
        $p( 'cli-loyalty', array( 'puntos de clientes', 'programa de fidelidad', 'puntos por compra', 'clientes frecuentes' ), 'Puedes premiar a tus clientes frecuentes con puntos o descuentos por sus compras repetidas. Gestiona el programa desde el panel > Clientes.', $P . 'customers', 'Clientes', 'fa-heart' ),
        $p( 'cli-historial', array( 'historial de compras del cliente', 'cuanto le compre este cliente', 'compras de un cliente' ), 'Cada cliente guarda su historial de compras (POS y pedidos). Ábrelo desde la lista de clientes para ver sus favoritos y totales.', $P . 'customers', 'Clientes', 'fa-receipt' ),

        // ---------- Equipo y roles ----------
        $p( 'team-invitar', array( 'invitar trabajador', 'agregar empleado', 'dar acceso a mi equipo', 'invitar a alguien a mi negocio' ), 'Desde el panel > Trabajadores el dueño invita a su equipo por correo y asigna el rol: vendedor (vende y cobra), almacenero (gestiona stock) o dueño. Cada uno ve solo lo que le corresponde.', $P . 'workers', 'Equipo', 'fa-user-plus' ),
        $p( 'team-roles', array( 'que puede hacer un vendedor', 'que puede hacer un almacenero', 'roles y permisos', 'permisos del equipo', 'que ve cada rol' ), 'El vendedor vende en el POS y ve pedidos y clientes; el almacenero gestiona entradas y salidas de stock; el dueño lo administra todo (productos, precios, equipo, reportes y plan).', $P . 'workers', 'Roles', 'fa-user-gear' ),
        $p( 'team-quitar', array( 'quitar trabajador', 'eliminar empleado', 'revocar acceso', 'despedir a alguien del panel' ), 'Desde el panel > Trabajadores puedes quitar a cualquier miembro del equipo; su acceso se revoca al momento y no vuelve a entrar al panel.', $P . 'workers', 'Equipo', 'fa-user-minus' ),
        $p( 'team-activity', array( 'que hace mi equipo', 'actividad de mis trabajadores', 'reporte del equipo', 'mis empleados trabajaron' ), 'Puedo decirte la última actividad de cada miembro de tu equipo y avisarte quién lleva días sin entrar. Pídeme un "reporte del equipo" o programa uno diario.', '', 'Reporte equipo', 'fa-users' ),

        // ---------- Reportes y datos ----------
        $p( 'rep-tipos', array( 'que reportes hay', 'tipos de reportes', 'reportes disponibles', 'que estadisticas puedo ver' ), 'Puedo generarte: ventas (hoy, 7 o 30 días), stock bajo, pedidos pendientes, actividad del equipo, seguridad e intentos de acceso, y un resumen completo del negocio. Pídemelos cuando quieras o prográmalos.', '', 'Ver reportes', 'fa-chart-line' ),
        $p( 'rep-programar', array( 'programar reporte', 'reporte automatico', 'reporte diario', 'recibir reporte programado', 'tarea en segundo plano' ), 'Puedo programar reportes para que te lleguen solos: ahora mismo, en X horas, hoy a una hora, mañana o cada día a una hora fija. Tú solo dime qué reporte y cuándo: "programa un reporte de ventas mañana a las 9".', '', 'Programar', 'fa-clock' ),
        $p( 'rep-datos', array( 'que datos tienes de mi negocio', 'que informacion manejas', 'que sabe el bot de mi negocio', 'datos de mi tienda' ), 'Tengo en tiempo real tus productos, stock (incluido bajo stock), pedidos pendientes, ventas del POS, clientes, equipo y actividad, caja abierta, notificaciones y tu plan. Todo de tu negocio, nada inventado.', '', 'Mis datos', 'fa-database' ),

        // ---------- Offline y PWA ----------
        $p( 'pwa-instalar', array( 'instalar la app', 'usar sin internet', 'modo offline', 'trabajar sin conexion', 'descargar la app' ), 'El sitio es una app instalable (PWA): desde el navegador del móvil puedes añadirla a la pantalla de inicio. En el panel puedes seguir operando con poca o ninguna conexión: las ventas quedan en cola y se sincronizan solas al reconectar.', '', 'Instalar', 'fa-mobile-screen-button' ),
        $p( 'pwa-sync', array( 'sincronizar ventas offline', 'ventas pendientes de sincronizar', 'cola offline', 'cuando se sincroniza' ), 'Lo que haces sin conexión (ventas, entradas) se guarda localmente y se envía automáticamente al reconectar. El panel te muestra cuántas acciones quedan pendientes de sincronizar.', '', 'Ver panel', 'fa-rotate' ),

        // ---------- Seguridad ----------
        $p( 'seg-login-fail', array( 'intentos fallidos de login', 'alguien intenta entrar', 'acceso no autorizado', 'seguridad de mi cuenta' ), 'Llevo un registro de los intentos de acceso fallidos. Si detecto varios seguidos desde la misma dirección, te aviso al instante y lo incluyo en el reporte de seguridad diario.', '', 'Seguridad', 'fa-shield-halved' ),
        $p( 'seg-contrasena', array( 'contrasena segura', 'como protejo mi cuenta', 'recomendacion de seguridad', 'cambiar clave por seguridad' ), 'Usa una contraseña larga y única, no la compartas con nadie, y revisa periódicamente quién tiene acceso a tu panel en Trabajadores. Yo te aviso de cualquier intento raro.', $P . 'workers', 'Equipo', 'fa-lock' ),

        // ---------- Errores comunes ----------
        $p( 'err-no-veo-tienda', array( 'no veo mi tienda en el marketplace', 'mi tienda no aparece', 'donde esta mi tienda', 'no encuentro mi negocio' ), 'Si no ves tu tienda en el marketplace, revisa que esté publicada (panel > Tienda > estado) y que tenga al menos un producto activo. Los cambios son visibles al instante.', $P . 'settings', 'Mi tienda', 'fa-eye-slash' ),
        $p( 'err-stock-no-cuadra', array( 'el stock no cuadra', 'inventario incorrecto', 'me falta stock', 'stock diferente al real', 'discrepancia de stock' ), 'El stock se descuenta solo con cada venta (POS o pedido aceptado) y con las entradas/salidas. Si crees que hay una discrepancia, haz un ajuste manual desde el panel > Stock y revisa el historial de movimientos.', $P . 'stock', 'Stock', 'fa-scale-balanced' ),
        $p( 'err-no-llega-pedido', array( 'no me llego el pedido', 'pedido no confirmado', 'mi pedido no aparece', 'cuando llega mi pedido' ), 'Tras confirmar tu pedido, la tienda te contacta por WhatsApp para coordinar la entrega. Si no te han escrito, escríbeles tú o espera su confirmación: el pedido queda en estado "pendiente" hasta que lo acepten.', '', 'Seguimiento', 'fa-truck' ),
        $p( 'err-caja-cerrada', array( 'la caja esta cerrada', 'no puedo vender', 'abrir la caja para vender', 'caja cerrada pos' ), 'Para vender en el POS la caja debe estar abierta. Ábrela desde el módulo Vender con el efectivo inicial; al cerrarla se hace el arqueo.', $P . 'pos', 'Abrir caja', 'fa-cash-register' ),

        // ---------- El propio asistente ----------
        $p( 'bot-que-puede', array( 'que puedes hacer', 'que sabe hacer el bot', 'que me puedes ayudar', 'tus funciones', 'capacidades del asistente' ), 'Puedo: crear, editar y eliminar productos (varios a la vez), reponer stock, aceptar o rechazar pedidos, crear clientes, registrar ventas en el POS, buscar productos, darte reportes de ventas/stock/equipo/seguridad, programar reportes para más tarde o cada día, y responderte las dudas frecuentes. Solo dime qué necesitas.', '', 'Ver atajos', 'fa-robot' ),
        $p( 'bot-bulk', array( 'crear varios productos a la vez', 'productos en lote', 'borrar varios productos', 'editar varios productos', 'crear muchos productos' ), 'Para tareas en lote escríbeme los nombres separados por comas o por líneas. Por ejemplo: "crea los productos: Harina 1kg, Azúcar 1kg, Sal" y los creo todos con el mismo precio.', '', 'Probar', 'fa-layer-group' ),
    );
}

function ws_chatbot_knowledge() {
    $kb = get_option( 'ws_chatbot_knowledge', null );
    if ( null === $kb ) {
        // Primera vez: sembrar los ítems por defecto (el admin puede editarlos).
        $kb = ws_chatbot_default_knowledge();
        update_option( 'ws_chatbot_knowledge', $kb );
    }
    $kb  = is_array( $kb ) ? $kb : array();
    // Conocimiento de contexto de toda la app (no editable, siempre disponible):
    // se combina con lo guardado sin pisar las ediciones del administrador.
    $kb  = array_merge( $kb, ws_chatbot_knowledge_extras() );
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
 * Normaliza texto para emparejar FAQs: minúsculas, sin tildes ni signos de
 * puntuación. El JS aplica la misma normalización antes de comparar, así el
 * usuario puede escribir con o sin tildes.
 */
function ws_chatbot_norm_text( $text ) {
    $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
    $text = mb_strtolower( trim( $text ), 'UTF-8' );
    $text = strtr( $text, array(
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ) );
    $text = preg_replace( '/[¿?¡!.,;:()"\'“”‘’\-–—\/]+/u', ' ', $text );
    return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Patrones de emparejamiento para una pregunta del FAQ: la pregunta
 * normalizada y una variante sin la palabra interrogativa inicial.
 */
function ws_chatbot_faq_patterns( $q ) {
    $norm     = ws_chatbot_norm_text( $q );
    $patterns = array( $norm );
    $reduced  = (string) preg_replace( '/^(como|que|cual|cuales|cual es|cuando|donde|cuanto|cuantos|puedo|hay|existe|me puedes|quiero saber|se puede)\s+/u', '', $norm );
    if ( '' !== $reduced && $reduced !== $norm ) {
        $patterns[] = $reduced;
    }
    return array_values( array_unique( $patterns ) );
}

/**
 * Convierte las FAQs de la página de Ayuda (editables en wp-admin) en ítems
 * de conocimiento del asistente: una entrada por tema con sus preguntas como
 * chips navegables, y una entrada por pregunta que responde directamente.
 */
function ws_chatbot_faq_knowledge() {
    $pages = ws_site_pages();
    $faqs  = (array) ( $pages['faqs'] ?? array() );
    $ayuda = ws_chatbot_resolve_link( 'ayuda' );
    $out   = array();
    $ti    = 0;

    // Índice: "faq", "preguntas frecuentes", "dudas" → lista los temas como
    // chips para que las FAQs sean descubribles sin conocer las preguntas.
    $topic_names = array();
    foreach ( $faqs as $topic ) {
        if ( is_array( $topic ) && '' !== trim( (string) ( $topic['topic'] ?? '' ) ) ) {
            $topic_names[] = trim( (string) $topic['topic'] );
        }
    }
    if ( ! empty( $topic_names ) ) {
        $index_chips = array();
        foreach ( $topic_names as $tname ) {
            $index_chips[] = array( 'label' => $tname, 'send' => $tname );
        }
        $out[] = array(
            'id'       => 'faq-indice',
            'patterns' => array( 'faq', 'preguntas frecuentes', 'dudas frecuentes', 'preguntas y respuestas', 'tengo dudas', 'lista de preguntas' ),
            'answer'   => __( 'Estas son las secciones de ayuda disponibles. Toca una para ver sus preguntas:', 'workshop' ),
            'chip'     => array(
                'label' => __( 'Ver en Ayuda', 'workshop' ),
                'url'   => $ayuda,
                'icon'  => 'fa-circle-question',
            ),
            'chips'    => $index_chips,
        );
    }

    foreach ( $faqs as $topic ) {
        if ( ! is_array( $topic ) ) {
            continue;
        }
        $tname = trim( (string) ( $topic['topic'] ?? '' ) );
        if ( '' === $tname ) {
            continue;
        }
        $items = array();
        foreach ( (array) ( $topic['items'] ?? array() ) as $it ) {
            if ( ! is_array( $it ) ) {
                continue;
            }
            $q = trim( (string) ( $it['q'] ?? '' ) );
            $a = trim( (string) ( $it['a'] ?? '' ) );
            if ( '' === $q || '' === $a ) {
                continue;
            }
            $items[] = array( 'q' => $q, 'a' => $a );
        }
        if ( empty( $items ) ) {
            continue;
        }

        // Ítem por tema: responde con sus preguntas como chips navegables.
        $tkey = sanitize_key( $tname );
        $tpat = array( ws_chatbot_norm_text( $tname ) );
        foreach ( preg_split( '/\s+/u', ws_chatbot_norm_text( $tname ) ) as $w ) {
            if ( mb_strlen( $w ) < 5 ) {
                continue;
            }
            $tpat[] = $w;
            $stem   = $w;
            if ( 'es' === substr( $stem, -2 ) && mb_strlen( $stem ) > 5 ) {
                $stem = substr( $stem, 0, -2 );
            } elseif ( 's' === substr( $stem, -1 ) && mb_strlen( $stem ) > 5 ) {
                $stem = substr( $stem, 0, -1 );
            }
            if ( $stem !== $w ) {
                $tpat[] = $stem;
            }
        }
        $question_chips = array();
        foreach ( $items as $it ) {
            $question_chips[] = array( 'label' => $it['q'], 'send' => $it['q'] );
        }
        $out[] = array(
            'id'       => 'faq-tema-' . $tkey . '-' . ( ++$ti ),
            'patterns' => array_values( array_unique( $tpat ) ),
            'answer'   => sprintf( __( 'Estas son las dudas más frecuentes sobre «%s». Toca una para ver la respuesta:', 'workshop' ), $tname ),
            'chip'     => array(
                'label' => __( 'Ver en Ayuda', 'workshop' ),
                'url'   => $ayuda,
                'icon'  => 'fa-circle-question',
            ),
            'chips'    => $question_chips,
        );

        // Ítem por pregunta: responde con la respuesta guardada en el FAQ.
        $qi = 0;
        foreach ( $items as $it ) {
            $out[] = array(
                'id'       => 'faq-' . $tkey . '-' . ( ++$qi ),
                'patterns' => ws_chatbot_faq_patterns( $it['q'] ),
                // Conserva saltos de línea (el bubble usa white-space: pre-line).
                'answer'   => trim( (string) preg_replace( '/[ \t]+/u', ' ', (string) wp_strip_all_tags( $it['a'] ) ) ),
                'chip'     => array(
                    'label' => __( 'Ver en Ayuda', 'workshop' ),
                    'url'   => $ayuda,
                    'icon'  => 'fa-circle-question',
                ),
            );
        }
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
    // FAQs de la página de Ayuda: conocimiento vivo y editable desde wp-admin.
    foreach ( ws_chatbot_faq_knowledge() as $item ) {
        $out[] = array(
            'id'       => sanitize_key( $item['id'] ),
            'patterns' => array_values( array_filter( array_map( 'trim', (array) $item['patterns'] ) ) ),
            'answer'   => (string) ( $item['answer'] ?? '' ),
            'chip'     => ! empty( $item['chip']['url'] ) ? $item['chip'] : null,
            'chips'    => ! empty( $item['chips'] ) ? $item['chips'] : null,
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
 * Contexto de la app, reportes, tareas programadas y seguridad
 * ---------------------------------------------------------------------- */

/**
 * Contexto en vivo de la app para el asistente: sitio, usuario, negocio y
 * navegación. Se usa para enriquecer el prompt de la IA y los fallbacks.
 */
function ws_chatbot_app_context() {
    global $wpdb;
    $role  = ws_user_role();
    $biz   = ws_current_business();
    $suf   = ws_biz_table_suffix( $biz );
    $T     = function ( $t ) use ( $suf ) { return ws_table_for( $suf, $t ); };
    $cur   = ws_currency_symbol();
    $out   = array(
        'site'    => array(
            'name'  => ws_site_name(),
            'url'   => home_url(),
            'currency' => $cur,
        ),
        'user'    => array(
            'id'   => get_current_user_id(),
            'role' => $role,
            'role_label' => ws_role_label( $role ),
        ),
        'nav'     => ws_chatbot_context(),
        'actions' => array( 'crear/editar/eliminar productos (bulk)', 'reponer stock', 'aceptar/rechazar pedidos', 'crear clientes', 'registrar venta POS', 'buscar productos', 'reportes (ventas/stock/pedidos/equipo/seguridad/resumen)', 'programar reportes', 'guias paso a paso', 'responder preguntas frecuentes' ),
        'reports' => array( 'sales', 'stock', 'orders', 'workers', 'security', 'summary' ),
    );

    if ( ! $role ) {
        return $out; // Visitante: solo contexto del sitio.
    }

    // Snapshot del negocio del usuario actual (tablas con scope del negocio).
    $products  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('products')}" );
    $pending   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$T('orders')} WHERE status = %s", 'pending' ) );
    $today     = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
    $sale_row  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM {$T('pos_sales')} WHERE created_at >= %s AND status = 'completed'", $today ) );
    $low_stock = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('stock')} st JOIN {$T('products')} p ON p.id = st.product_id WHERE st.qty > 0 AND st.qty <= p.min_stock" );
    $agotados  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('stock')} st WHERE st.qty <= 0" );
    $customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('customers')}" );
    $workers   = count( ws_chatbot_team_members() );
    $cash_open = false;
    foreach ( ws_user_locations() as $l ) {
        if ( WS_POS::get_open_cash( (int) $l->id ) ) { $cash_open = true; break; }
    }

    $out['business'] = array(
        'id'            => ws_current_business_id(),
        'name'          => (string) ( $biz->name ?? ws_site_name() ),
        'plan'          => (string) ( $biz->plan_name ?? '' ),
        'products'      => $products,
        'low_stock'     => $low_stock,
        'agotados'      => $agotados,
        'pending_orders'=> $pending,
        'sales_today'   => (int) $sale_row->c,
        'sales_today_total' => (float) $sale_row->t,
        'customers'     => $customers,
        'workers'       => $workers,
        'cash_open'     => $cash_open,
        'currency'      => $cur,
        'unread_notifications' => ws_notifications_unread_count(),
    );
    return $out;
}

/**
 * Versión de texto compacta del contexto (para el prompt de la IA).
 */
function ws_chatbot_context_text() {
    $c = ws_chatbot_app_context();
    $lines = array( 'Sitio: ' . $c['site']['name'] . ' (' . $c['site']['url'] . ')' );
    $lines[] = 'Usuario: ' . ( $c['user']['role_label'] ? $c['user']['role_label'] : 'visitante' );
    if ( isset( $c['business'] ) ) {
        $b = $c['business'];
        $lines[] = sprintf( 'Negocio: %s (plan %s) — %d productos, %d con stock bajo, %d agotados, %d pedidos pendientes, %d ventas hoy por %s %s, %d clientes, %d trabajadores, caja %s.' ,
            $b['name'], $b['plan'], $b['products'], $b['low_stock'], $b['agotados'], $b['pending_orders'], $b['sales_today'], number_format_i18n( $b['sales_today_total'], 2 ), $b['currency'], $b['customers'], $b['workers'], $b['cash_open'] ? 'abierta' : 'cerrada' );
    }
    $lines[] = 'El asistente puede ejecutar: ' . implode( '; ', $c['actions'] );
    return implode( ' | ', $lines );
}

/**
 * Equipo del negocio actual (dueños, almaceneros y vendedores).
 */
function ws_chatbot_team_members() {
    $biz_id = ws_current_business_id();
    $args   = array(
        'role__in' => array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ),
        'fields'   => 'all',
    );
    if ( WS_Business::is_default_id( $biz_id ) ) {
        // Dueños legacy (sin ws_business_id) + todos los roles del negocio por defecto.
        $legacy = get_users( array( 'role__in' => array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ), 'fields' => 'all', 'meta_key' => 'ws_business_id', 'meta_compare' => 'NOT EXISTS' ) );
        $with   = get_users( array_merge( $args, array( 'meta_key' => 'ws_business_id', 'meta_value' => $biz_id ) ) );
        return array_merge( $legacy, $with );
    }
    return get_users( array_merge( $args, array( 'meta_key' => 'ws_business_id', 'meta_value' => $biz_id ) ) );
}

/**
 * Construye un reporte del negocio actual. Devuelve array( 'title', 'text' ).
 */
function ws_chatbot_build_report( $type, $days = 1 ) {
    global $wpdb;
    $type  = in_array( $type, array( 'sales', 'stock', 'orders', 'workers', 'security', 'summary' ), true ) ? $type : 'summary';
    $days  = max( 1, (int) $days );
    $biz   = ws_current_business();
    $suf   = ws_biz_table_suffix( $biz );
    $T     = function ( $t ) use ( $suf ) { return ws_table_for( $suf, $t ); };
    $cur   = ws_currency_symbol();
    $nl    = "\n";

    if ( 'sales' === $type || 'summary' === $type ) {
        $since = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - ( $days - 1 ) * DAY_IN_SECONDS );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM {$T('pos_sales')} WHERE created_at >= %s AND status = 'completed'", $since ) );
        $sales = sprintf( 'Ventas (%d día%s): %d · %s %s', $days, $days > 1 ? 's' : '', (int) $row->c, number_format_i18n( (float) $row->t, 2 ), $cur );
    }
    if ( 'stock' === $type || 'summary' === $type ) {
        $products  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('products')}" );
        $low       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('stock')} st JOIN {$T('products')} p ON p.id = st.product_id WHERE st.qty > 0 AND st.qty <= p.min_stock" );
        $agotados  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('stock')} st WHERE st.qty <= 0" );
        $stock     = sprintf( 'Stock: %d productos · %d bajo stock · %d agotados', $products, $low, $agotados );
    }
    if ( 'orders' === $type || 'summary' === $type ) {
        $pend   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$T('orders')} WHERE status = %s", 'pending' ) );
        $totrow = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$T('orders')} WHERE status = %s", 'pending' ) );
        $orders = sprintf( 'Pedidos pendientes: %d · %s %s', $pend, number_format_i18n( (float) $totrow, 2 ), $cur );
    }
    if ( 'workers' === $type || 'summary' === $type ) {
        $ws_lines = array();
        $inactive = 0;
        $threshold = time() - 2 * DAY_IN_SECONDS;
        foreach ( ws_chatbot_team_members() as $u ) {
            $act = (string) get_user_meta( $u->ID, 'ws_last_activity', true );
            $act = $act ? strtotime( $act ) : ( ( $last = get_user_meta( $u->ID, 'ws_last_login', true ) ) ? strtotime( $last ) : 0 );
            $label = ws_role_label( ws_user_role( $u->ID ) );
            if ( ! $act || $act < $threshold ) {
                $inactive++;
                $ws_lines[] = '• ' . $u->display_name . ' (' . $label . ') — sin actividad reciente ⚠️';
            } else {
                $ws_lines[] = '• ' . $u->display_name . ' (' . $label . ') — activo hoy';
            }
        }
        $workers = 'Equipo (' . count( ws_chatbot_team_members() ) . ' miembros):' . $nl . implode( $nl, array_slice( $ws_lines, 0, 12 ) ) . ( $inactive ? $nl . '⚠️ ' . $inactive . ' miembro(s) sin actividad en 2+ días.' : '' );
    }
    if ( 'security' === $type || 'summary' === $type ) {
        $sec = array_slice( (array) get_option( 'ws_security_log', array() ), -5 );
        if ( empty( $sec ) ) {
            $security = 'Seguridad: sin eventos recientes ✅';
        } else {
            $sec_lines = array();
            foreach ( $sec as $e ) {
                $sec_lines[] = '• ' . ( $e['time'] ? wp_date( 'd/m H:i', (int) $e['time'] ) : '?' ) . ' — ' . $e['event'] . ( ! empty( $e['detail'] ) ? ' (' . $e['detail'] . ')' : '' );
            }
            $security = 'Seguridad:' . $nl . implode( $nl, $sec_lines );
        }
    }

    switch ( $type ) {
        case 'sales':   $text = $sales; break;
        case 'stock':   $text = $stock; break;
        case 'orders':  $text = $orders; break;
        case 'workers': $text = $workers; break;
        case 'security':$text = $security; break;
        default:
            $text = $sales . $nl . $stock . $nl . $orders . $nl . $workers . $nl . $security;
    }
    $titles = array( 'sales' => 'Reporte de ventas', 'stock' => 'Reporte de stock', 'orders' => 'Reporte de pedidos', 'workers' => 'Reporte del equipo', 'security' => 'Reporte de seguridad', 'summary' => 'Resumen del negocio' );
    return array( 'title' => $titles[ $type ], 'text' => $text );
}

/**
 * Interpreta expresiones de tiempo en español → array(at, recurring).
 */
function ws_chatbot_parse_when( $when ) {
    $w   = ws_chatbot_norm_text( $when );
    $now = current_time( 'timestamp' );
    if ( false !== strpos( $w, 'ahora' ) || 'ya' === $w ) {
        return array( 'at' => $now + 30, 'recurring' => false );
    }
    if ( preg_match( '/en (\d+)\s*(hora|h|minuto|min|dia|d)/', $w, $m ) ) {
        $n = (int) $m[1];
        $u = $m[2];
        $secs = ( 'hora' === $u || 'h' === $u ) ? HOUR_IN_SECONDS : ( ( 'dia' === $u || 'd' === $u ) ? DAY_IN_SECONDS : MINUTE_IN_SECONDS );
        return array( 'at' => $now + $n * $secs, 'recurring' => false );
    }
    if ( false !== strpos( $w, 'manana' ) ) {
        $h = 8; $mi = 0;
        if ( preg_match( '/(\d{1,2})(?::(\d{2}))?/', $w, $m ) ) { $h = (int) $m[1]; $mi = (int) ( $m[2] ?? 0 ); }
        return array( 'at' => mktime( $h, $mi, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ) + 1, (int) gmdate( 'Y', $now ) ), 'recurring' => false );
    }
    if ( false !== strpos( $w, 'hoy' ) ) {
        $h = 20; $mi = 0;
        if ( preg_match( '/(\d{1,2})(?::(\d{2}))?/', $w, $m ) ) { $h = (int) $m[1]; $mi = (int) ( $m[2] ?? 0 ); }
        $at = mktime( $h, $mi, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );
        if ( $at < $now ) { $at += DAY_IN_SECONDS; }
        return array( 'at' => $at, 'recurring' => false );
    }
    if ( false !== strpos( $w, 'cada dia' ) || false !== strpos( $w, 'diario' ) || false !== strpos( $w, 'todos los dias' ) ) {
        $h = 8; $mi = 0;
        if ( preg_match( '/(\d{1,2})(?::(\d{2}))?/', $w, $m ) ) { $h = (int) $m[1]; $mi = (int) ( $m[2] ?? 0 ); }
        $at = mktime( $h, $mi, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );
        if ( $at < $now ) { $at += DAY_IN_SECONDS; }
        return array( 'at' => $at, 'recurring' => true );
    }
    return false;
}

/**
 * Inserta una notificación para un usuario (la entrega el chat en vivo).
 */
function ws_chatbot_notify_user( $user_id, $title, $message, $ref_key = '' ) {
    global $wpdb;
    if ( ! $user_id ) { return; }
    $wpdb->insert(
        $wpdb->prefix . WS_TABLE_PREFIX . 'notifications',
        array(
            'user_id'    => (int) $user_id,
            'type'       => 'chatbot',
            'title'      => mb_substr( (string) $title, 0, 240 ),
            'message'    => mb_substr( (string) $message, 0, 240 ),
            'link'       => '',
            'ref_key'    => mb_substr( (string) $ref_key, 0, 110 ),
            'is_read'    => 0,
            'created_at' => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
    );
}

/**
 * Ejecuta las tareas programadas vencidas (corre cuando un usuario del
 * negocio está en línea; el contexto de negocio ya está resuelto).
 */
add_action( 'init', 'ws_chatbot_run_tasks', 9 );
function ws_chatbot_run_tasks() {
    if ( get_transient( 'ws_tasks_lock' ) ) { return; }
    $uid = get_current_user_id();
    $role = ws_user_role( $uid );
    if ( ! $uid || ! $role ) { return; }
    $tasks = get_option( 'ws_chatbot_tasks', array() );
    if ( ! is_array( $tasks ) || empty( $tasks ) ) { return; }
    set_transient( 'ws_tasks_lock', 1, 60 );
    $biz_id = ws_current_business_id();
    $now    = current_time( 'timestamp' );
    $ran    = 0;
    foreach ( $tasks as $i => $t ) {
        if ( $ran >= 5 ) { break; }
        if ( (int) ( $t['biz_id'] ?? 0 ) !== $biz_id || 'done' === ( $t['status'] ?? '' ) || (int) $t['at'] > $now ) {
            continue;
        }
        $result = ws_chatbot_build_report( (string) ( $t['type'] ?? 'summary' ), 1 );
        $tasks[ $i ]['last_result'] = $result['text'];
        $tasks[ $i ]['last_run']    = current_time( 'mysql' );
        ws_chatbot_notify_user( (int) $t['user_id'], '🤖 ' . $result['title'], $result['text'], 'chatbot_task_' . $t['id'] );
        if ( ! empty( $t['recurring'] ) ) {
            $tasks[ $i ]['at'] = (int) $t['at'] + DAY_IN_SECONDS;
            // Si quedó muy atrasada, la fija para dentro de 5 min (evita ráfaga
            // de catch-up cuando el dueño no visita durante días).
            if ( (int) $tasks[ $i ]['at'] <= $now ) { $tasks[ $i ]['at'] = $now + 300; }
        } else {
            $tasks[ $i ]['status'] = 'done';
        }
        $ran++;
    }
    update_option( 'ws_chatbot_tasks', array_values( $tasks ) );
}

/**
 * Reporte bajo demanda.
 */
add_action( 'wp_ajax_ws_chatbot_report', 'ws_ajax_chatbot_report' );
function ws_ajax_chatbot_report() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    if ( ! ws_user_role() ) {
        wp_send_json_error( array( 'msg' => __( 'Solo para negocios.', 'workshop' ) ) );
    }
    $type = sanitize_key( $_POST['type'] ?? 'summary' );
    $days = max( 1, min( 90, (int) ( $_POST['days'] ?? 1 ) ) );
    $r = ws_chatbot_build_report( $type, $days );
    wp_send_json_success( array( 'title' => $r['title'], 'text' => $r['text'] ) );
}

/**
 * Programa un reporte (tarea en segundo plano).
 */
add_action( 'wp_ajax_ws_chatbot_schedule', 'ws_ajax_chatbot_schedule' );
function ws_ajax_chatbot_schedule() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $role = ws_user_role();
    if ( ! $role ) {
        wp_send_json_error( array( 'msg' => __( 'Solo para negocios.', 'workshop' ) ) );
    }
    $type     = sanitize_key( $_POST['type'] ?? 'summary' );
    if ( ! in_array( $type, array( 'sales', 'stock', 'orders', 'workers', 'security', 'summary' ), true ) ) {
        $type = 'summary';
    }
    $when      = sanitize_text_field( wp_unslash( $_POST['when'] ?? '' ) );
    $recurring = ! empty( $_POST['recurring'] );
    $parsed    = ws_chatbot_parse_when( $when );
    if ( ! $parsed ) {
        wp_send_json_error( array( 'msg' => __( 'No entendí la fecha. Ejemplos: "en 2 horas", "mañana a las 09:00", "cada día a las 08:00".', 'workshop' ) ) );
    }
    $recurring = $recurring || ! empty( $parsed['recurring'] );
    $tasks = get_option( 'ws_chatbot_tasks', array() );
    $tasks = is_array( $tasks ) ? $tasks : array();
    $id = 't' . time() . '_' . wp_rand( 100, 999 );
    $tasks[] = array(
        'id'       => $id,
        'user_id'  => get_current_user_id(),
        'biz_id'   => ws_current_business_id(),
        'type'     => $type,
        'at'       => (int) $parsed['at'],
        'recurring'=> $recurring,
        'status'   => 'pending',
        'last_result' => '',
        'created_at' => current_time( 'mysql' ),
    );
    // Primera vez: asegura el resumen diario automático del negocio.
    ws_chatbot_ensure_daily( $tasks );
    // Poda tareas completadas de hace más de 7 días (evita crecer sin límite).
    $tasks = array_values( array_filter( $tasks, function ( $t ) {
        if ( 'done' !== ( $t['status'] ?? '' ) ) { return true; }
        $ts = strtotime( (string) ( $t['created_at'] ?? '' ) );
        return ! $ts || ( time() - $ts < 7 * DAY_IN_SECONDS );
    } ) );
    update_option( 'ws_chatbot_tasks', $tasks );
    wp_send_json_success( array(
        'when_label' => wp_date( 'd/m H:i', (int) $parsed['at'] ) . ( $recurring ? ' (cada día)' : '' ),
        'recurring'  => $recurring,
    ) );
}

/**
 * Añade (una sola vez) el resumen diario automático del negocio: reporta cada
 * mañana ventas, stock bajo, pedidos, actividad del equipo y seguridad.
 */
function ws_chatbot_ensure_daily( &$tasks ) {
    $biz_id = ws_current_business_id();
    foreach ( $tasks as $t ) {
        if ( (int) ( $t['biz_id'] ?? 0 ) === $biz_id && ! empty( $t['recurring'] ) && 'daily' === ( $t['kind'] ?? '' ) ) {
            return; // Ya existe el resumen diario.
        }
    }
    $parsed = ws_chatbot_parse_when( 'cada dia a las 08:00' );
    $tasks[] = array(
        'id'       => 'daily_' . $biz_id . '_' . time(),
        'user_id'  => get_current_user_id(),
        'biz_id'   => $biz_id,
        'type'     => 'summary',
        'kind'     => 'daily',
        'at'       => (int) $parsed['at'],
        'recurring'=> true,
        'status'   => 'pending',
        'last_result' => '',
        'created_at' => current_time( 'mysql' ),
    );
}

/**
 * Lista las tareas/reportes programados del negocio actual.
 */
add_action( 'wp_ajax_ws_chatbot_tasks', 'ws_ajax_chatbot_tasks' );
function ws_ajax_chatbot_tasks() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    if ( ! ws_user_role() ) {
        wp_send_json_error( array( 'msg' => __( 'Solo para negocios.', 'workshop' ) ) );
    }
    $biz_id = ws_current_business_id();
    $labels = array( 'sales' => 'Ventas', 'stock' => 'Stock', 'orders' => 'Pedidos', 'workers' => 'Equipo', 'security' => 'Seguridad', 'summary' => 'Resumen diario' );
    $out = array();
    foreach ( (array) get_option( 'ws_chatbot_tasks', array() ) as $t ) {
        if ( (int) ( $t['biz_id'] ?? 0 ) !== $biz_id ) { continue; }
        $out[] = array(
            'id'          => (string) $t['id'],
            'type'        => (string) ( $t['type'] ?? 'summary' ),
            'label'       => $labels[ $t['type'] ?? 'summary' ] ?? 'Resumen',
            'when_label'  => wp_date( 'd/m H:i', (int) $t['at'] ),
            'recurring'   => ! empty( $t['recurring'] ),
            'status'      => (string) ( $t['status'] ?? 'pending' ),
            'last_result' => (string) ( $t['last_result'] ?? '' ),
        );
    }
    wp_send_json_success( array( 'tasks' => $out ) );
}

/**
 * Log de seguridad: guarda intentos de acceso fallidos y eventos raros.
 */
function ws_security_log( $event, $detail = '' ) {
    $log = get_option( 'ws_security_log', array() );
    $log = is_array( $log ) ? $log : array();
    $ip = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
    if ( '' === $ip && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $ip = trim( (string) explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
    }
    if ( '' === $ip ) { $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); }
    $log[] = array(
        'time'   => time(),
        'ip'     => $ip,
        'event'  => mb_substr( (string) $event, 0, 60 ),
        'detail' => mb_substr( (string) $detail, 0, 120 ),
    );
    update_option( 'ws_security_log', array_slice( $log, -60 ) );
    return $ip;
}

/**
 * Intento de acceso fallido: lo registra y, si se repite desde la misma IP,
 * avisa a los dueños de negocio al instante (posible fuerza bruta).
 */
add_action( 'wp_login_failed', 'ws_chatbot_login_failed', 10, 1 );
function ws_chatbot_login_failed( $username ) {
    $ip = ws_security_log( 'Intento de acceso fallido', sanitize_user( $username ) );
    $rk = 'ws_sec_' . md5( $ip );
    $n  = (int) get_transient( $rk ) + 1;
    set_transient( $rk, $n, 10 * MINUTE_IN_SECONDS );
    if ( $n < 5 ) { return; }
    if ( get_transient( 'ws_sec_alert_' . md5( $ip ) ) ) { return; } // 1 alerta/hora por IP
    set_transient( 'ws_sec_alert_' . md5( $ip ), 1, HOUR_IN_SECONDS );
    $msg = sprintf( __( 'Se detectaron %d intentos de acceso fallidos desde la IP %s. Revisa la seguridad de tus cuentas.', 'workshop' ), $n, $ip );
    foreach ( get_users( array( 'role' => 'ws_owner', 'fields' => 'ID' ) ) as $uid ) {
        ws_chatbot_notify_user( (int) $uid, '⚠️ Posible intento de intrusión', $msg, 'sec_alert_' . md5( $ip ) );
    }
}

/**
 * Rastrea la última actividad de los trabajadores (para el reporte de equipo).
 */
add_action( 'init', 'ws_chatbot_track_activity', 20 );
function ws_chatbot_track_activity() {
    $uid = get_current_user_id();
    if ( ! $uid || ! ws_user_role( $uid ) ) { return; }
    if ( get_transient( 'ws_act_' . $uid ) ) { return; }
    set_transient( 'ws_act_' . $uid, 1, 5 * MINUTE_IN_SECONDS );
    update_user_meta( $uid, 'ws_last_activity', current_time( 'mysql' ) );
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
        'Responde en español, breve y con tono amable (algún emoji). Si te piden algo fuera de estas capacidades, sugiere contactar soporte por la página de Contacto o WhatsApp. ' .
        'DATOS EN VIVO DEL USUARIO (úsalos para responder con datos reales del negocio, nunca inventes cifras): ' . ws_chatbot_context_text();

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