<?php
/**
 * Router del tema.
 *
 * Rutas de paneles (roles), con prefijo de negocio opcional:
 *   /{negocio}/panel/{owner|storekeeper|seller}/...
 *   /{negocio}/panel/{owner|storekeeper|seller}/{page}/
 *   /panel/{owner|storekeeper|seller}/...            (negocio por defecto)
 *
 * Tienda pública por PV:
 *   /{negocio}/tienda/{slug}/
 *   /{negocio}/tienda/{slug}/pedido/confirmado/{order_id}/
 *   /tienda/{slug}/...
 *
 * Login:
 *   /{negocio}/login
 *   /{negocio}/logout
 *
 * Página principal de cada negocio (index.php):
 *   /{negocio}/                                  (landing del negocio)
 *   /                                            (mercado / landing por defecto)
 *
 * Nuevos módulos:
 *   /{negocio}/clientes/, /{negocio}/pos/, /{negocio}/pos/ventas/,
 *   /{negocio}/valoraciones/, /{negocio}/fidelizacion/
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'ws_rewrite_rules' );
function ws_rewrite_rules() {
    $pages = 'dashboard|products|locations|stock|movements|orders|shifts|workers|permissions|reports|settings|account|appearance|customers|reviews|loyalty|pos|pos-sales|plan|anuncios|expenses';

    add_rewrite_tag( '%ws_page%', '(' . $pages . ')' );
    add_rewrite_tag( '%ws_loc%', '([^/]+)' );
    add_rewrite_tag( '%ws_id%', '([0-9]+)' );
    add_rewrite_tag( '%ws_biz%', '([^/]+)' );

    $biz = '(?:([^/]+)/)?';

    add_rewrite_rule( '^' . $biz . 'panel/(owner|storekeeper|seller)/([^/]+)?/?$', 'index.php?ws_biz=$matches[1]&ws_role=$matches[2]&ws_page=$matches[3]', 'top' );
    add_rewrite_rule( '^' . $biz . 'panel/(owner|storekeeper|seller)?/?$', 'index.php?ws_biz=$matches[1]&ws_role=$matches[2]&ws_page=dashboard', 'top' );

    add_rewrite_rule( '^' . $biz . 'tienda/([^/]+)/pedido/([0-9]+)?/?$', 'index.php?ws_biz=$matches[1]&ws_loc=$matches[2]&ws_public=order&ws_id=$matches[3]', 'top' );
    add_rewrite_rule( '^' . $biz . 'tienda/([^/]+)?/?$', 'index.php?ws_biz=$matches[1]&ws_loc=$matches[2]&ws_public=store', 'top' );

    add_rewrite_rule( '^' . $biz . 'login/?$', 'index.php?ws_biz=$matches[1]&ws_public=login', 'top' );
    add_rewrite_rule( '^' . $biz . 'logout/?$', 'index.php?ws_biz=$matches[1]&ws_public=logout', 'top' );

    // Registro público de negocios (solo en la raíz, sin prefijo de negocio).
    add_rewrite_rule( '^registro/?$', 'index.php?ws_public=register', 'top' );

    // Manifest PWA servido por PHP (evita que el archivo estático se sirva
    // como HTML en algunos hosts y rompa la instalación de la app).
    add_rewrite_rule( '^manifest\.json/?$', 'index.php?ws_public=manifest', 'top' );

    // Directorio de tiendas del mercado: /marketplace/ (antes de la landing
    // genérica de negocio, que se comería el slug).
    add_rewrite_rule( '^marketplace/?$', 'index.php?ws_public=stores', 'top' );

    // Páginas estáticas: ayuda, contacto y acerca de nosotros.
    add_rewrite_rule( '^' . $biz . 'ayuda/?$', 'index.php?ws_biz=$matches[1]&ws_public=ayuda', 'top' );
    add_rewrite_rule( '^' . $biz . 'contacto/?$', 'index.php?ws_biz=$matches[1]&ws_public=contacto', 'top' );
    add_rewrite_rule( '^' . $biz . 'acerca/?$', 'index.php?ws_biz=$matches[1]&ws_public=acerca', 'top' );

    // Nuevas rutas para módulos adicionales
    add_rewrite_rule( '^' . $biz . 'clientes/([0-9]+)/?$', 'index.php?ws_biz=$matches[1]&ws_page=customers&ws_id=$matches[2]', 'top' );
    add_rewrite_rule( '^' . $biz . 'clientes/?$', 'index.php?ws_biz=$matches[1]&ws_page=customers', 'top' );

    add_rewrite_rule( '^' . $biz . 'pos/ventas/([0-9]+)/?$', 'index.php?ws_biz=$matches[1]&ws_page=pos-sales&ws_id=$matches[2]', 'top' );
    add_rewrite_rule( '^' . $biz . 'pos/ventas/?$', 'index.php?ws_biz=$matches[1]&ws_page=pos-sales', 'top' );
    add_rewrite_rule( '^' . $biz . 'pos/?$', 'index.php?ws_biz=$matches[1]&ws_page=pos', 'top' );

    add_rewrite_rule( '^' . $biz . 'valoraciones/?$', 'index.php?ws_biz=$matches[1]&ws_page=reviews', 'top' );
    add_rewrite_rule( '^' . $biz . 'fidelizacion/?$', 'index.php?ws_biz=$matches[1]&ws_page=loyalty', 'top' );

    // Landing de cada negocio (página principal): /{slug}/
    add_rewrite_rule( '^([^/]+)/?$', 'index.php?ws_biz=$matches[1]&ws_biz_home=1', 'top' );
}

/**
 * Auto-flush de rewrite rules cuando cambian (una vez por versión).
 *
 * El tema añade rutas nuevas con frecuencia (marketplace, módulos…); este
 * gancho regenera las reglas la primera vez que se visita el sitio tras
 * cambiar $version, evitando 404 por reglas desactualizadas. Para forzar un
 * nuevo flush, sube el valor de $version (p. ej. con la fecha).
 */
add_action( 'init', 'ws_maybe_flush_rewrite_rules', 20 );
function ws_maybe_flush_rewrite_rules() {
    $version = '2026-08-13-panel-suppliers-tab';
    if ( get_option( 'ws_rewrite_rules_version' ) !== $version ) {
        update_option( 'ws_rewrite_rules_version', $version );
        ws_flush_rewrite_rules();
    }
}

add_filter( 'query_vars', 'ws_query_vars' );
function ws_query_vars( $vars ) {
    $vars[] = 'ws_role';
    $vars[] = 'ws_page';
    $vars[] = 'ws_loc';
    $vars[] = 'ws_public';
    $vars[] = 'ws_id';
    $vars[] = 'ws_biz';
    $vars[] = 'ws_biz_home';
    return $vars;
}

add_action( 'template_redirect', 'ws_router' );
function ws_router() {
    // Los usuarios logueados (dueño/trabajador) pueden navegar el mercado y
    // las páginas públicas de cualquier negocio (landing y tienda) sin ser
    // redirigidos. El acceso a los paneles de OTROS negocios y a login/registro
    // se protege en ws_handle_panel() y ws_handle_public() respectivamente.

    // Contenido nativo de WordPress: Páginas, Entradas, archivos y búsqueda
    // creados desde wp-admin se renderizan con el maquetado del tema (cabecera,
    // menú y pie propios). El admin edita textos y fotos sin tocar código.
    if ( is_singular() || ( is_home() && ! is_front_page() ) || is_archive() || is_search() ) {
        ws_render_wp_content();
        exit;
    }

    $public = get_query_var( 'ws_public' );
    if ( $public ) {
        ws_handle_public( $public );
        return;
    }
    $role = get_query_var( 'ws_role' );
    if ( $role ) {
        ws_handle_panel( $role );
        return;
    }
    // Rutas cortas de los módulos nuevos: sin rol en la URL se redirige al
    // panel correspondiente del usuario (o al login si no hay sesión).
    $short = get_query_var( 'ws_page' );
    if ( $short && in_array( $short, array( 'customers', 'reviews', 'loyalty', 'pos', 'pos-sales' ), true ) ) {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( ws_business_url( get_query_var( 'ws_biz' ) ) . 'login/' );
            exit;
        }
        // El admin del sistema tampoco entra por las rutas cortas: wp-admin.
        if ( current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( ws_login_scheme_url( admin_url() ) );
            exit;
        }
        $role = ws_user_role();
        if ( ! $role ) {
            wp_safe_redirect( ws_business_home() );
            exit;
        }
        wp_safe_redirect( ws_panel_url( $role, $short ) );
        exit;
    }
    // Landing de negocio: /{slug}/
    if ( get_query_var( 'ws_biz_home' ) ) {
        $biz = ws_current_business();
        if ( ! $biz || empty( $biz->slug ) || ! (int) $biz->active ) {
            // El catch-all del tema captura las URLs de un solo segmento; si
            // existe una Página o Entrada de WordPress con ese slug (creada
            // desde wp-admin), se renderiza con la plantilla del tema.
            if ( ws_render_wp_content_by_url() ) {
                exit;
            }
            status_header( 404 );
            include WS_PATH . 'templates/404.php';
            exit;
        }
        // Negocio bloqueado (plan vencido o límite superado): su URL muestra
        // la pantalla de pausa en vez de la portada.
        if ( WS_Subscriptions::is_locked( $biz ) ) {
            include WS_PATH . 'templates/business-locked.php';
            exit;
        }
        include WS_PATH . 'templates/landing.php';
        exit;
    }
    // Portada: el índice de la raíz es SIEMPRE el mercado de negocios.
    // (El negocio por defecto no tiene landing propia en la raíz: su portada
    // era el índice y ahora lo ocupa el mercado con todos los negocios.)
    if ( is_front_page() ) {
        include WS_PATH . 'templates/marketplace.php';
        exit;
    }
}

/**
 * Renderiza contenido nativo de WordPress (páginas, entradas, archivos y
 * búsqueda) con la plantilla del tema. Devuelve true si se mostró algo.
 */
function ws_render_wp_content() {
    if ( is_singular() ) {
        if ( is_page() || 'page' === get_post_type() ) {
            include WS_PATH . 'templates/page.php';
        } else {
            include WS_PATH . 'templates/single.php';
        }
        return true;
    }
    if ( is_search() ) {
        include WS_PATH . 'templates/search.php';
        return true;
    }
    if ( is_archive() ) {
        include WS_PATH . 'templates/archive.php';
        return true;
    }
    if ( is_home() && ! is_front_page() ) {
        include WS_PATH . 'templates/index.php';
        return true;
    }
    return false;
}

/**
 * Resuelve una URL de un solo segmento capturada por el catch-all del tema:
 * si WordPress tiene una Página o Entrada publicada con ese slug, la renderiza
 * con la plantilla del tema y devuelve true.
 */
function ws_render_wp_content_by_url() {
    $slug = (string) get_query_var( 'ws_biz' );
    if ( '' === $slug ) {
        return false;
    }

    $post_id = url_to_postid( home_url( add_query_arg( array() ) ) );
    if ( ! $post_id ) {
        $page = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );
        $post_id = $page ? (int) $page->ID : 0;
    }
    if ( ! $post_id ) {
        $by_name = get_posts( array(
            'name'           => $slug,
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );
        $post_id = ! empty( $by_name ) ? (int) $by_name[0] : 0;
    }
    if ( ! $post_id ) {
        return false;
    }

    $post = get_post( $post_id );
    if ( ! $post || 'publish' !== $post->post_status ) {
        return false;
    }
    $GLOBALS['post'] = $post;
    $GLOBALS['wp_query']->is_singular = true;
    $GLOBALS['wp_query']->is_page     = ( 'page' === $post->post_type );
    $GLOBALS['wp_query']->is_single   = ( 'post' === $post->post_type );
    setup_postdata( $post );

    if ( 'page' === $post->post_type ) {
        include WS_PATH . 'templates/page.php';
    } else {
        include WS_PATH . 'templates/single.php';
    }
    return true;
}

function ws_handle_public( $public ) {
    if ( 'login' === $public ) {
        if ( is_user_logged_in() ) {
            wp_safe_redirect( ws_dashboard_url() );
            exit;
        }
        include WS_PATH . 'templates/login.php';
        exit;
    }
    if ( 'logout' === $public ) {
        wp_logout();
        wp_safe_redirect( ws_business_url( get_query_var( 'ws_biz' ) ) . 'login/' );
        exit;
    }
    if ( 'register' === $public ) {
        if ( is_user_logged_in() ) {
            wp_safe_redirect( ws_dashboard_url() );
            exit;
        }
        include WS_PATH . 'templates/register.php';
        exit;
    }
    if ( 'manifest' === $public ) {
        // Manifest PWA generado dinámicamente con el Content-Type correcto.
        status_header( 200 );
        header( 'Content-Type: application/manifest+json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        echo wp_json_encode( ws_pwa_manifest_data() );
        exit;
    }
    if ( 'stores' === $public ) {
        // Directorio de tiendas: /marketplace/
        include WS_PATH . 'templates/marketplace-stores.php';
        exit;
    }
    if ( in_array( $public, array( 'ayuda', 'contacto', 'acerca' ), true ) ) {
        // Páginas estáticas editables (ShopUp → Páginas y pie).
        include WS_PATH . 'templates/static-page.php';
        exit;
    }
    if ( 'store' === $public || 'order' === $public ) {
        $biz = ws_current_business();
        // Negocio bloqueado (plan vencido o límite superado): la tienda queda
        // inhabilitada y se muestra la pantalla de negocio no disponible.
        if ( $biz && WS_Subscriptions::is_locked( $biz ) ) {
            include WS_PATH . 'templates/business-locked.php';
            exit;
        }
        $slug = get_query_var( 'ws_loc' );
        $location = WS_CRUD::get_location_by_slug( $slug );
        if ( ! $location || ! $location->active ) {
            status_header( 404 );
            include WS_PATH . 'templates/404.php';
            exit;
        }
        set_query_var( 'ws_location', $location );
        if ( 'order' === $public ) {
            $order = WS_Orders::get( (int) get_query_var( 'ws_id' ) );
            if ( ! $order || (int) $order->location_id !== (int) $location->id ) {
                status_header( 404 );
                include WS_PATH . 'templates/404.php';
                exit;
            }
            set_query_var( 'ws_order', $order );
            include WS_PATH . 'templates/store-order.php';
        } else {
            include WS_PATH . 'templates/store.php';
        }
        exit;
    }
    include WS_PATH . 'templates/404.php';
    exit;
}

function ws_handle_panel( $role ) {
    if ( ! is_user_logged_in() ) {
        wp_safe_redirect( ws_business_url( get_query_var( 'ws_biz' ) ) . 'login/' );
        exit;
    }
    // El admin del sistema NO participa en negocios: se le redirige a su panel
    // administrativo de WordPress (igual que dueños y trabajadores van al
    // suyo). No puede entrar a ningún panel de negocio, ni siquiera para
    // probar: su lugar es wp-admin.
    if ( current_user_can( 'manage_options' ) ) {
        wp_safe_redirect( ws_login_scheme_url( admin_url() ) );
        exit;
    }
    $user_role = ws_user_role();
    if ( ! $user_role ) {
        wp_safe_redirect( ws_business_home() );
        exit;
    }
    // Solo puedes ver tu propio panel.
    $allowed = array( 'owner', 'storekeeper', 'seller' );
    if ( ! in_array( $user_role, $allowed, true ) ) {
        wp_safe_redirect( ws_business_home() );
        exit;
    }
    if ( $user_role !== $role ) {
        wp_safe_redirect( ws_panel_url( $user_role ) );
        exit;
    }

    // Aislamiento por negocio: un trabajador solo accede a su propio negocio.
    $user_biz = ws_user_business();
    $url_biz  = ws_current_business();
    if ( (int) $user_biz->id !== (int) $url_biz->id ) {
        wp_safe_redirect( ws_panel_url( $user_role, '', $user_biz ) );
        exit;
    }
    // Los negocios con slug deben usar su URL con prefijo.
    if ( ! WS_Business::is_default( $user_biz ) && '' === (string) get_query_var( 'ws_biz' ) ) {
        wp_safe_redirect( ws_panel_url( $user_role, ws_current_page(), $user_biz ) );
        exit;
    }

    $page = ws_current_page();
    if ( ! in_array( $page, array( 'dashboard', 'products', 'locations', 'stock', 'movements', 'orders', 'shifts', 'workers', 'permissions', 'reports', 'settings', 'account', 'appearance', 'customers', 'reviews', 'loyalty', 'pos', 'pos-sales', 'plan', 'anuncios', 'expenses' ), true ) ) {
        $page = 'dashboard';
    }
    // Guardas de permisos por página.
    $cap_guard = array(
        'products'    => 'products_view',
        'locations'   => 'locations_view',
        'stock'       => 'stock_view',
        'movements'   => 'movements_view',
        'orders'      => 'orders_view',
        'shifts'      => 'shifts_view',
        'workers'     => 'workers_manage',
        'permissions' => 'permissions_manage',
        'reports'     => 'reports_view',
        'settings'    => 'settings_manage',
        'appearance'  => array( 'site_manage', 'layout_manage' ),
        'customers'   => 'customers_view',
        'reviews'     => 'reviews_view',
        'loyalty'     => 'loyalty_manage',
        'pos'         => 'pos_sell',
        'pos-sales'   => 'pos_view',
        'plan'        => array(),
        // Anuncios del negocio: solo el dueño (o el admin del sistema).
        'anuncios'    => array( 'settings_manage', 'workers_manage' ),
        // Control de gastos (módulo del panel).
        'expenses'    => 'expenses_manage',
    );
    if ( isset( $cap_guard[ $page ] ) ) {
        $need = (array) $cap_guard[ $page ];
        // Sin capacidades requeridas (p. ej. la página Plan) = acceso libre.
        $ok   = empty( $need );
        foreach ( $need as $cap ) {
            if ( ws_can( $cap ) ) {
                $ok = true;
                break;
            }
        }
        if ( ! $ok ) {
            $page = 'dashboard';
        }
    }

    // Negocio bloqueado (plan vencido o límite superado): nadie (dueño ni
    // trabajadores) entra al panel. Se muestra la pantalla de pausa con el
    // botón Upgrade. (El admin del sistema nunca llega aquí: se redirige a
    // wp-admin al principio.)
    $biz = ws_current_business();
    if ( $biz && WS_Subscriptions::is_locked( $biz ) ) {
        ws_subscription_notify();
        include WS_PATH . 'templates/business-locked.php';
        exit;
    }

    set_query_var( 'ws_page', $page );
    include WS_PATH . 'templates/panel.php';
    exit;
}

/**
 * URLs helper.
 */
function ws_panel_url( $role = null, $page = '', $biz = null ) {
    $role = $role ? $role : ws_user_role();
    $biz  = $biz ? $biz : ws_current_business();
    $slug = ( $biz && ! empty( $biz->slug ) ) ? $biz->slug . '/' : '';
    $path = $slug . 'panel/' . $role;
    if ( $page ) {
        $path .= '/' . $page;
    }
    return home_url( '/' . trailingslashit( $path ) );
}

function ws_store_url( $location, $biz = null ) {
    $slug = is_object( $location ) ? $location->slug : $location;
    $biz  = $biz ? $biz : ws_current_business();
    $bslug = ( $biz && ! empty( $biz->slug ) ) ? $biz->slug . '/' : '';
    return home_url( '/' . $bslug . 'tienda/' . trailingslashit( $slug ) );
}
