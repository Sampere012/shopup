<?php
/**
 * Multi-negocio (multi-tenant).
 *
 * Cada negocio tiene su propio slug de URL (/negocio/panel/..., /negocio/tienda/...,
 * /negocio/... como página principal), su propio conjunto de tablas
 * (wp_ws_{slug}_*) y sus propias opciones de configuración, apariencia y
 * permisos. El administrador de WordPress gestiona los negocios, sus datos de
 * Cloudinary y el acceso al mercado (marketplace) desde wp-admin.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Business {

    const RESERVED_SLUGS = array(
        'panel', 'tienda', 'login', 'logout', 'registro', 'clientes', 'pos', 'valoraciones',
        'fidelizacion', 'marketplace', 'ayuda', 'contacto', 'acerca', 'wp-admin', 'admin', 'index', 'feed', 'wp-cron', 'xmlrpc',
    );

    /**
     * Tabla global de negocios (no tiene scope por negocio).
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . WS_TABLE_PREFIX . 'businesses';
    }

    public static function all( $args = array() ) {
        global $wpdb;
        $where = array( '1=1' );
        if ( isset( $args['active'] ) ) {
            $where[] = $wpdb->prepare( 'active = %d', (int) $args['active'] );
        }
        if ( ! empty( $args['marketplace'] ) ) {
            $where[] = 'marketplace_enabled = 1';
        }
        return $wpdb->get_results(
            'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id ASC'
        );
    }

    public static function get( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
    }

    public static function get_by_slug( $slug ) {
        global $wpdb;
        $slug = sanitize_title( (string) $slug );
        if ( '' === $slug ) {
            return self::default_business();
        }
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s', $slug ) );
    }

    /**
     * Negocio por defecto (id 1, slug ''): conserva las tablas wp_ws_* y las
     * URLs actuales sin prefijo. Se crea solo si la tabla existe y está vacía.
     */
    public static function default_business() {
        global $wpdb;
        static $def = false;
        if ( false !== $def ) {
            return $def;
        }
        // Guarda anti-reentrada: si durante la resolución algo vuelve a pedir
        // el negocio por defecto (p. ej. filtros de opciones del tema), se
        // devuelve un objeto mínimo en vez de recurrir sin fin.
        static $resolving = false;
        if ( $resolving ) {
            return (object) array( 'id' => 1, 'slug' => '', 'name' => 'ShopUp', 'active' => 1, 'marketplace_enabled' => 0 );
        }
        $resolving = true;
        $table = self::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $def = null;
            $resolving = false;
            return $def;
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d', 1 ) );
        if ( ! $row ) {
            // blogname leído sin disparar filtros del tema (ws_site_blogname
            // resuelve el negocio actual y aquí aún no existe ninguno).
            $blogname = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'blogname' ) );
            $ok = $wpdb->insert( $table, array(
                'name'   => $blogname ? $blogname : 'ShopUp',
                'slug'   => '',
                'active' => 1,
            ) );
            $row = $ok ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d', $wpdb->insert_id ) ) : null;
        }
        $resolving = false;
        $def = $row;
        return $def;
    }

    public static function is_default( $biz ) {
        return $biz && (int) $biz->id === 1 && '' === (string) $biz->slug;
    }

    public static function is_default_id( $id ) {
        return (int) $id === 1;
    }

    /**
     * Negocios visibles en el mercado (activos + acceso concedido).
     *
     * Incluye al negocio por defecto si el administrador le concede acceso al
     * mercado: su URL es la raíz del sitio (/). El orden es estable por id;
     * marketplace_ranked() aplica el ranking.
     */
    public static function marketplace() {
        $rows = self::all( array( 'active' => 1, 'marketplace' => 1 ) );
        // Un negocio con la prueba vencida o un límite superado desaparece
        // automáticamente del mercado hasta que el admin apruebe su upgrade.
        return array_values( array_filter( $rows, function ( $b ) {
            return ! WS_Subscriptions::is_locked( $b );
        } ) );
    }

    /**
     * Negocios del mercado ordenados por "mejor primero".
     *
     * Puntuación de calidad para el ranking: productos activos, puntos de
     * venta activos, valoraciones aprobadas y pedidos realizados (ventas).
     * A igual puntuación, el negocio más antiguo (menor id) aparece primero.
     * Así el índice posiciona primero los mercados con más actividad, como
     * Amazon/eBay, y alimenta el directorio de tiendas (/marketplace/).
     */
    public static function marketplace_ranked() {
        global $wpdb;
        $bizs = self::marketplace();
        $rows = array();
        foreach ( $bizs as $b ) {
            $loc_table = ws_table_name( 'locations', $b );
            $pro_table = ws_table_name( 'products', $b );
            $rev_table = ws_table_name( 'reviews', $b );
            $ord_table = ws_table_name( 'orders', $b );
            $products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pro_table} WHERE active=1" );
            $pvs      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$loc_table} WHERE type='pv' AND active=1" );
            $reviews  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rev_table} WHERE approved=1" );
            $avg_rate = (float) $wpdb->get_var( "SELECT AVG(rating) FROM {$rev_table} WHERE approved=1" );
            // Pedidos válidos (no cancelados ni rechazados) y su facturación.
            $orders  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ord_table} WHERE status NOT IN ('cancelled','rejected')" );
            $revenue = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total),0) FROM {$ord_table} WHERE status NOT IN ('cancelled','rejected')" );
            $score    = ( $products * 2 ) + ( $pvs * 3 ) + ( $reviews * 2 ) + ( $orders * 3 );
            $b->ws_products = $products;
            $b->ws_pvs      = $pvs;
            $b->ws_reviews  = $reviews;
            $b->ws_rating   = $reviews ? round( $avg_rate, 1 ) : 0.0;
            $b->ws_orders   = $orders;
            $b->ws_revenue  = $revenue;
            $b->ws_score    = $score;
            $rows[] = $b;
        }
        usort( $rows, static function ( $a, $b ) {
            if ( $a->ws_score !== $b->ws_score ) {
                return $a->ws_score < $b->ws_score ? 1 : -1;
            }
            return (int) $a->id - (int) $b->id;
        } );
        return $rows;
    }

    public static function slug_taken( $slug, $exclude_id = 0 ) {
        global $wpdb;
        $sql = $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE slug = %s', $slug );
        if ( $exclude_id ) {
            $sql .= $wpdb->prepare( ' AND id <> %d', $exclude_id );
        }
        return (bool) $wpdb->get_var( $sql );
    }

    public static function create( $data ) {
        global $wpdb;
        $name = sanitize_text_field( $data['name'] ?? '' );
        $slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
        if ( '' === $name ) {
            return new WP_Error( 'name', __( 'El nombre es obligatorio.', 'workshop' ) );
        }
        if ( '' === $slug ) {
            return new WP_Error( 'slug', __( 'El slug es obligatorio.', 'workshop' ) );
        }
        if ( in_array( $slug, self::RESERVED_SLUGS, true ) || self::slug_taken( $slug ) ) {
            return new WP_Error( 'slug', __( 'Ese slug ya está en uso.', 'workshop' ) );
        }
        $fields = array(
            'name'                => $name,
            'slug'                => $slug,
            'description'         => sanitize_textarea_field( $data['description'] ?? '' ),
            'logo'                => esc_url_raw( $data['logo'] ?? '' ),
            'active'              => isset( $data['active'] ) ? (int) filter_var( $data['active'], FILTER_VALIDATE_BOOLEAN ) : 1,
            'marketplace_enabled' => isset( $data['marketplace_enabled'] ) ? (int) filter_var( $data['marketplace_enabled'], FILTER_VALIDATE_BOOLEAN ) : 0,
            'cloud_name'          => sanitize_text_field( $data['cloud_name'] ?? '' ),
            'cloud_api_key'       => sanitize_text_field( $data['cloud_api_key'] ?? '' ),
            'cloud_api_secret'    => sanitize_text_field( $data['cloud_api_secret'] ?? '' ),
            'cloud_upload_preset' => sanitize_text_field( $data['cloud_upload_preset'] ?? '' ),
            'cloud_folder'        => sanitize_text_field( $data['cloud_folder'] ?? '' ),
        );
        $wpdb->insert( self::table(), $fields );
        $id = (int) $wpdb->insert_id;
        if ( $id ) {
            ws_create_business_tables( $slug );
        }
        return $id;
    }

    public static function update( $id, $data ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) {
            return new WP_Error( 'id', __( 'Negocio inválido.', 'workshop' ) );
        }
        $name = sanitize_text_field( $data['name'] ?? '' );
        if ( '' === $name ) {
            return new WP_Error( 'name', __( 'El nombre es obligatorio.', 'workshop' ) );
        }
        $old = self::get( $id );
        if ( ! $old ) {
            return new WP_Error( 'id', __( 'Negocio no encontrado.', 'workshop' ) );
        }
        $fields = array(
            'name'                => $name,
            'description'         => sanitize_textarea_field( $data['description'] ?? '' ),
            'logo'                => esc_url_raw( $data['logo'] ?? '' ),
            'active'              => isset( $data['active'] ) ? (int) filter_var( $data['active'], FILTER_VALIDATE_BOOLEAN ) : 1,
            'marketplace_enabled' => isset( $data['marketplace_enabled'] ) ? (int) filter_var( $data['marketplace_enabled'], FILTER_VALIDATE_BOOLEAN ) : 0,
            'cloud_name'          => sanitize_text_field( $data['cloud_name'] ?? '' ),
            'cloud_api_key'       => sanitize_text_field( $data['cloud_api_key'] ?? '' ),
            'cloud_api_secret'    => sanitize_text_field( $data['cloud_api_secret'] ?? '' ),
            'cloud_upload_preset' => sanitize_text_field( $data['cloud_upload_preset'] ?? '' ),
            'cloud_folder'        => sanitize_text_field( $data['cloud_folder'] ?? '' ),
        );
        // El slug se puede cambiar en cualquier negocio (incluido el por
        // defecto). Si se deja vacío en el por defecto, conserva la URL raíz.
        $slug = sanitize_title( (string) ( $data['slug'] ?? $old->slug ) );
        if ( '' !== $slug && $slug !== $old->slug ) {
            if ( in_array( $slug, self::RESERVED_SLUGS, true ) || self::slug_taken( $slug, $id ) ) {
                return new WP_Error( 'slug', __( 'Ese slug ya está en uso.', 'workshop' ) );
            }
            // Mueve las tablas del negocio al nuevo prefijo (incluye el negocio
            // por defecto: wp_ws_* → wp_ws_{nuevo}_ws_*).
            ws_rename_business_tables( $old->slug, $slug );
            $fields['slug'] = $slug;
        }
        $wpdb->update( self::table(), $fields, array( 'id' => $id ) );
        return $id;
    }

    public static function delete( $id ) {
        global $wpdb;
        $id   = (int) $id;
        $biz  = self::get( $id );
        if ( ! $biz ) {
            return false;
        }
        if ( self::is_default( $biz ) ) {
            return false;
        }
        $wpdb->delete( self::table(), array( 'id' => $id ) );
        if ( $biz->slug ) {
            ws_drop_business_tables( $biz->slug );
            // Limpia asignaciones de trabajadores y opciones aisladas.
            $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'ws_business_id', 'meta_value' => $id ) );
            foreach ( array( 'ws_currency', 'ws_currencies', 'ws_rates', 'ws_rates_updated', 'ws_payment_methods', 'ws_whatsapp', 'ws_site_theme', 'ws_permissions_matrix', 'ws_loyalty_settings' ) as $key ) {
                delete_option( $key . '_' . $id );
            }
        }
        return true;
    }

    /**
     * ¿El negocio tiene Cloudinary configurado?
     */
    public static function has_cloudinary( $biz = null ) {
        $biz = $biz ?: ws_current_business();
        return $biz && '' !== trim( (string) ( $biz->cloud_name ?? '' ) );
    }
}

/* -------------------------------------------------------------------------
 * Mercado (índice global de la raíz del sitio, configurado por el admin)
 * ---------------------------------------------------------------------- */

/**
 * ¿Se está renderizando el índice del mercado (raíz del sitio)?
 */
function ws_is_marketplace() {
    return ! empty( $GLOBALS['ws_marketplace'] );
}

/**
 * Opciones del índice del mercado, configuradas por el administrador de
 * WordPress en wp-admin. Son independientes de la apariencia de cada negocio:
 * el dueño solo controla la portada de SU negocio; el admin controla la raíz.
 */
function ws_marketplace_theme() {
    $defaults = array(
        'name'          => '',
        'logo'          => '',
        'description'   => '',
        'primary'       => '#4f46e5',
        'accent'        => '#f59e0b',
        'hero_badge'    => __( 'Mercado de negocios', 'workshop' ),
        'hero_title'    => __( 'Elige tu tienda', 'workshop' ),
        'hero_sub'      => __( 'Descubre los negocios disponibles y entra en sus puntos de venta.', 'workshop' ),
        'hero_bg'       => '',
        'hero_gradient' => '',
        'footer_text'   => '',
        'sections'      => array(),
    );
    $saved  = get_option( 'ws_marketplace_theme', array() );
    $saved  = is_array( $saved ) ? $saved : array();
    $sections = array();
    foreach ( (array) ( $saved['sections'] ?? array() ) as $s ) {
        if ( is_array( $s ) ) {
            $title   = sanitize_text_field( $s['title'] ?? '' );
            $content = trim( (string) ( $s['content'] ?? '' ) );
            if ( '' !== $title || '' !== $content ) {
                $sections[] = array( 'title' => $title, 'content' => $content );
            }
        }
    }
    $saved['sections'] = $sections;
    return wp_parse_args( $saved, $defaults );
}

/**
 * URL del directorio de tiendas del mercado (/marketplace/).
 *
 * Página dedicada que muestra TODOS los negocios del mercado con su ranking
 * por recomendación, ventas, valoraciones e interacción.
 */
function ws_marketplace_stores_url() {
    return home_url( '/marketplace/' );
}

/**
 * Sufijo seguro para nombres de tabla de un negocio.
 *
 * El slug de URL puede contener guiones (p. ej. "mi-negocio"), pero los
 * nombres de tabla de MySQL no: se convierten a guiones bajos
 * (wp_ws_mi_negocio_*).
 */
function ws_biz_table_suffix( $biz_or_slug ) {
    $slug = is_object( $biz_or_slug ) ? (string) ( $biz_or_slug->slug ?? '' ) : (string) $biz_or_slug;
    return str_replace( '-', '_', sanitize_title( $slug ) );
}

/**
 * Nombre real de tabla para un sufijo y una entidad.
 *
 * El negocio por defecto (sufijo '') usa wp_ws_{entidad}; los demás usan
 * wp_ws_{sufijo}_ws_{entidad} (coincide con ws_create_business_tables, cuyo
 * plantilla {prefix}ws_{entidad} se alimenta con el prefijo wp_ws_{sufijo}_).
 */
function ws_table_for( $suffix, $t ) {
    global $wpdb;
    return $wpdb->prefix . WS_TABLE_PREFIX . ( '' !== $suffix ? $suffix . '_ws_' : '' ) . $t;
}

/**
 * Nombre de tabla con scope del negocio actual.
 * El negocio por defecto (slug '') mantiene el prefijo wp_ws_; los demás
 * usan wp_ws_{sufijo}_ws_ (sufijo = slug con guiones convertidos a _).
 */
function ws_table_name( $t, $biz = null ) {
    if ( null === $biz ) {
        $biz = ws_current_business();
    }
    $suffix = '';
    if ( $biz && ! WS_Business::is_default( $biz ) && ! empty( $biz->slug ) ) {
        $suffix = ws_biz_table_suffix( $biz );
    }
    return ws_table_for( $suffix, $t );
}

/**
 * Negocio en el contexto actual de la petición.
 *
 * Resolución (en orden):
 *  1. Query var ws_biz (URL: /{slug}/...).
 *  2. AJAX: ws_biz enviado por el front-end (solo válido para admins; los
 *     usuarios de negocio siempre usan su propio negocio asignado).
 *  3. Negocio asignado al usuario logueado (user meta ws_business_id).
 *  4. Negocio por defecto.
 */
function ws_current_business() {
    static $biz = null;
    if ( null !== $biz ) {
        return $biz;
    }
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        $biz = WS_Business::default_business();
        return $biz;
    }
    $slug = (string) get_query_var( 'ws_biz' );
    if ( '' !== $slug ) {
        $b = WS_Business::get_by_slug( $slug );
        if ( $b ) {
            $biz = $b;
            return $biz;
        }
    }
    if ( wp_doing_ajax() ) {
        $pslug = sanitize_title( (string) ( $_POST['ws_biz'] ?? '' ) );
        if ( '' !== $pslug ) {
            // El AJAX del frontend adjunta ws_biz (slug del negocio de la URL)
            // a TODAS las peticiones. Para el admin siempre se respeta; para
            // un visitante sin sesión también (la reseña/carrito de una tienda
            // de /negocio/tienda/… debe ir a la tabla de ESE negocio, no a la
            // del negocio por defecto). Los usuarios con rol de negocio usan
            // su propio negocio (user meta) y nunca el slug ajeno.
            $is_admin = current_user_can( 'manage_options' );
            $has_role = ( '' !== (string) ws_user_role() );
            if ( $is_admin || ! $has_role ) {
                $b = WS_Business::get_by_slug( $pslug );
                if ( $b ) {
                    $biz = $b;
                    return $biz;
                }
            }
        }
    }
    $user_id = get_current_user_id();
    if ( $user_id ) {
        $bid = (int) get_user_meta( $user_id, 'ws_business_id', true );
        if ( $bid ) {
            $b = WS_Business::get( $bid );
            if ( $b ) {
                $biz = $b;
                return $biz;
            }
        }
    }
    $biz = WS_Business::default_business();
    return $biz;
}

function ws_current_business_id() {
    $b = ws_current_business();
    return $b ? (int) $b->id : 1;
}

/**
 * Negocio al que pertenece un usuario (asignación o por defecto).
 */
function ws_user_business( $user_id = 0 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id ) {
        $bid = (int) get_user_meta( $user_id, 'ws_business_id', true );
        if ( $bid ) {
            $b = WS_Business::get( $bid );
            if ( $b ) {
                return $b;
            }
        }
    }
    return WS_Business::default_business();
}

/**
 * ¿El usuario actual puede operar en el negocio dado? (roles de negocio)
 */
function ws_user_belongs_to_business( $user_id = 0, $biz = null ) {
    $user_biz = ws_user_business( $user_id );
    $biz      = $biz ?: ws_current_business();
    return (int) $user_biz->id === (int) $biz->id;
}

/**
 * Nº de dueños del negocio actual (incluye legacy del negocio por defecto).
 * Opcionalmente excluye a un usuario (para las guardas de "último dueño").
 */
function ws_business_owners_count( $exclude_user_id = 0 ) {
    $biz_id = ws_current_business_id();
    $ids    = array();
    if ( WS_Business::is_default_id( $biz_id ) ) {
        $ids = array_merge( $ids, (array) get_users( array(
            'role'        => 'ws_owner',
            'fields'      => 'ID',
            'meta_key'    => 'ws_business_id',
            'meta_compare' => 'NOT EXISTS',
        ) ) );
    }
    $ids = array_merge( $ids, (array) get_users( array(
        'role'       => 'ws_owner',
        'fields'     => 'ID',
        'meta_key'   => 'ws_business_id',
        'meta_value' => $biz_id,
    ) ) );
    $ids = array_unique( array_map( 'intval', $ids ) );
    if ( $exclude_user_id ) {
        $ids = array_values( array_diff( $ids, array( (int) $exclude_user_id ) ) );
    }
    return count( $ids );
}

/**
 * Home de un negocio (con o sin slug).
 */
function ws_business_home( $biz = null ) {
    $biz  = $biz ?: ws_current_business();
    $slug = ( $biz && ! empty( $biz->slug ) ) ? $biz->slug . '/' : '';
    return home_url( '/' . $slug );
}

function ws_business_url( $slug ) {
    $slug = sanitize_title( (string) $slug );
    return home_url( '/' . ( $slug ? $slug . '/' : '' ) );
}

/**
 * Opciones por negocio: para el negocio por defecto se usan las opciones
 * globales actuales (retrocompatibilidad); los demás guardan {key}_{id}.
 */
function ws_biz_option_for( $key, $default, $biz_id = 0 ) {
    $biz_id = $biz_id ? (int) $biz_id : ws_current_business_id();
    if ( WS_Business::is_default_id( $biz_id ) ) {
        return get_option( $key, $default );
    }
    $scoped = get_option( $key . '_' . $biz_id, null );
    return null !== $scoped ? $scoped : get_option( $key, $default );
}

function ws_save_biz_option_for( $key, $value, $biz_id = 0 ) {
    $biz_id = $biz_id ? (int) $biz_id : ws_current_business_id();
    if ( WS_Business::is_default_id( $biz_id ) ) {
        update_option( $key, $value );
    } else {
        update_option( $key . '_' . $biz_id, $value );
    }
}

function ws_biz_option( $key, $default = false ) {
    return ws_biz_option_for( $key, $default );
}

function ws_save_biz_option( $key, $value ) {
    ws_save_biz_option_for( $key, $value );
}

/**
 * Crea las tablas de datos de un negocio (wp_ws_{suffix}_*).
 */
function ws_create_business_tables( $slug ) {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix . WS_TABLE_PREFIX . ws_biz_table_suffix( $slug ) . '_';
    $skip = array_merge( array( 'businesses' ), ws_global_tables() );
    foreach ( ws_db_tables() as $key => $sql ) {
        if ( in_array( $key, $skip, true ) ) {
            continue;
        }
        $sql = str_replace( '{prefix}', $prefix, $sql );
        $sql = str_replace( '{charset}', $charset_collate, $sql );
        dbDelta( $sql );
    }
}

function ws_drop_business_tables( $slug ) {
    global $wpdb;
    $suffix = ws_biz_table_suffix( $slug );
    $skip = array_merge( array( 'businesses' ), ws_global_tables() );
    foreach ( array_keys( ws_db_tables() ) as $key ) {
        if ( in_array( $key, $skip, true ) ) {
            continue;
        }
        $wpdb->query( 'DROP TABLE IF EXISTS ' . ws_table_for( $suffix, $key ) );
    }
}

/**
 * Renombra las tablas de un negocio al cambiar su slug.
 *
 * Soporta también el negocio por defecto (slug '' → wp_ws_*): al darle un
 * slug por primera vez sus tablas pasan a wp_ws_{sufijo}_ws_*, y al dejarlo
 * vacío se revierte. Solo renombra tablas que existen.
 */
function ws_rename_business_tables( $old_slug, $new_slug ) {
    global $wpdb;
    $old_suffix = ws_biz_table_suffix( $old_slug );
    $new_suffix = ws_biz_table_suffix( $new_slug );
    $skip = array_merge( array( 'businesses' ), ws_global_tables() );
    foreach ( array_keys( ws_db_tables() ) as $key ) {
        if ( in_array( $key, $skip, true ) ) {
            continue;
        }
        $from = ws_table_for( $old_suffix, $key );
        $to   = ws_table_for( $new_suffix, $key );
        if ( $from === $to ) {
            continue;
        }
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $from ) ) === $from ) {
            $wpdb->query( "RENAME TABLE {$from} TO {$to}" );
        }
    }
}

/* -------------------------------------------------------------------------
 * Cloudinary
 * ---------------------------------------------------------------------- */

/**
 * Sube un archivo de imagen a Cloudinary del negocio.
 * Devuelve la URL segura, o null si el negocio no tiene Cloudinary o falla.
 */
function ws_cloudinary_upload( $file_path, $biz = null ) {
    $biz = $biz ?: ws_current_business();
    if ( ! $biz || '' === trim( (string) ( $biz->cloud_name ?? '' ) ) ) {
        return null;
    }
    if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        return null;
    }
    $cloud_name = trim( (string) $biz->cloud_name );
    $folder     = trim( (string) ( $biz->cloud_folder ?? '' ) );
    $preset     = trim( (string) ( $biz->cloud_upload_preset ?? '' ) );
    $api_key    = trim( (string) ( $biz->cloud_api_key ?? '' ) );
    $secret     = trim( (string) ( $biz->cloud_api_secret ?? '' ) );

    $endpoint = 'https://api.cloudinary.com/v1_1/' . rawurlencode( $cloud_name ) . '/image/upload';
    $body     = array( 'file' => class_exists( 'CURLFile' ) ? new CURLFile( $file_path ) : $file_path );
    if ( '' !== $folder ) {
        $body['folder'] = $folder;
    }
    if ( '' !== $secret ) {
        $ts           = time();
        $to_sign      = 'folder=' . $folder . '&timestamp=' . $ts;
        $body['timestamp'] = $ts;
        $body['signature'] = sha1( $to_sign . $secret );
        if ( '' !== $api_key ) {
            $body['api_key'] = $api_key;
        }
    } elseif ( '' !== $preset ) {
        $body['upload_preset'] = $preset;
    } else {
        return null;
    }

    $response = wp_remote_post( $endpoint, array(
        'timeout' => 60,
        'body'    => $body,
    ) );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return null;
    }
    $json = json_decode( wp_remote_retrieve_body( $response ), true );
    return ! empty( $json['secure_url'] ) ? $json['secure_url'] : null;
}

/* -------------------------------------------------------------------------
 * Subida de imágenes (panel)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_upload_image', 'ws_ajax_upload_image' );
function ws_ajax_upload_image() {
    ws_guard( 'products_edit', 'site_manage' );

    if ( empty( $_FILES['file'] ) || empty( $_FILES['file']['tmp_name'] ) ) {
        wp_send_json_error( array( 'msg' => __( 'No se recibió ningún archivo.', 'workshop' ) ) );
    }
    $file = $_FILES['file'];
    if ( is_uploaded_file( $file['tmp_name'] ) && filesize( $file['tmp_name'] ) < 1 ) {
        wp_send_json_error( array( 'msg' => __( 'El archivo está vacío.', 'workshop' ) ) );
    }
    $allowed = array(
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/avif' => 'avif',
    );
    $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $file['tmp_name'] ) : ( $file['type'] ?? '' );
    if ( ! isset( $allowed[ $mime ] ) ) {
        wp_send_json_error( array( 'msg' => __( 'El archivo debe ser una imagen (JPG, PNG, GIF, WebP, SVG o AVIF).', 'workshop' ) ) );
    }

    // Cloudinary si el negocio lo tiene configurado.
    $url = ws_cloudinary_upload( $file['tmp_name'] );
    if ( $url ) {
        ws_log_audit( 'image_upload', 'image', 0, array( 'source' => 'cloudinary' ) );
        wp_send_json_success( array( 'url' => $url, 'source' => 'cloudinary' ) );
    }

    // Fallback: librería de medios de WordPress.
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    $att_id = media_handle_upload( 'file', 0 );
    if ( is_wp_error( $att_id ) ) {
        wp_send_json_error( array( 'msg' => $att_id->get_error_message() ) );
    }
    $src = wp_get_attachment_url( $att_id );
    ws_log_audit( 'image_upload', 'image', $att_id, array( 'source' => 'media' ) );
    wp_send_json_success( array( 'url' => $src, 'source' => 'media' ) );
}

/* -------------------------------------------------------------------------
 * wp-admin: página de gestión de negocios
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'ws_business_admin_menu', 20 );
function ws_business_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Negocios', 'workshop' ),
        __( 'Negocios', 'workshop' ),
        'manage_options',
        'ws-businesses',
        'ws_admin_page_businesses'
    );
}

function ws_admin_page_businesses() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $notice = '';
    if ( isset( $_POST['ws_biz_nonce'] ) && wp_verify_nonce( $_POST['ws_biz_nonce'], 'ws_manage_businesses' ) ) {
        $action = sanitize_key( $_POST['ws_action'] ?? '' );
        if ( 'create' === $action ) {
            $result = WS_Business::create( $_POST );
            if ( is_wp_error( $result ) ) {
                $notice = array( 'error', $result->get_error_message() );
            } else {
                $notice = array( 'success', __( 'Negocio creado.', 'workshop' ) );
            }
        } elseif ( 'update' === $action ) {
            $id     = (int) ( $_POST['biz_id'] ?? 0 );
            $result = WS_Business::update( $id, $_POST );
            if ( is_wp_error( $result ) ) {
                $notice = array( 'error', $result->get_error_message() );
            } else {
                $notice = array( 'success', __( 'Negocio actualizado.', 'workshop' ) );
            }
        } elseif ( 'delete' === $action ) {
            $id = (int) ( $_POST['biz_id'] ?? 0 );
            if ( WS_Business::delete( $id ) ) {
                $notice = array( 'success', __( 'Negocio eliminado.', 'workshop' ) );
            } else {
                $notice = array( 'error', __( 'No se pudo eliminar el negocio.', 'workshop' ) );
            }
        }
    }

    $businesses = WS_Business::all();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-store" style="vertical-align:middle"></span> <?php esc_html_e( 'Negocios', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Cada negocio tiene su propio acceso por URL (slug), sus tablas, trabajadores, permisos y Cloudinary. El administrador decide cuáles aparecen en el mercado.', 'workshop' ); ?></p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Negocio', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Slug / URL', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Mercado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Cloudinary', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $businesses as $b ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $b->name ); ?></strong>
                            <?php if ( $b->description ) : ?><br><small><?php echo esc_html( $b->description ); ?></small><?php endif; ?>
                        </td>
                        <td>
                            <code><?php echo esc_html( WS_Business::is_default( $b ) ? home_url( '/' ) : home_url( '/' . $b->slug . '/' ) ); ?></code>
                            <?php if ( ! WS_Business::is_default( $b ) ) : ?>
                                <br><a target="_blank" href="<?php echo esc_url( ws_business_url( $b->slug ) ); ?>"><?php esc_html_e( 'Visitar', 'workshop' ); ?> <span class="dashicons dashicons-external"></span></a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $b->active ? '<span class="dashicons dashicons-yes" style="color:#00a32a"></span>' : '<span class="dashicons dashicons-no" style="color:#d63638"></span>'; ?></td>
                        <td><?php echo $b->marketplace_enabled ? '<span class="dashicons dashicons-yes" style="color:#00a32a"></span>' : '—'; ?></td>
                        <td><?php echo '' !== trim( (string) $b->cloud_name ) ? esc_html( $b->cloud_name ) : '—'; ?></td>
                        <td>
                            <button class="button button-small" onclick="document.getElementById('ws-biz-form-<?php echo (int) $b->id; ?>').classList.toggle('hidden')"><?php esc_html_e( 'Editar', 'workshop' ); ?></button>
                            <?php if ( ! WS_Business::is_default( $b ) ) : ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar este negocio y todos sus datos?', 'workshop' ) ); ?>')">
                                    <?php wp_nonce_field( 'ws_manage_businesses', 'ws_biz_nonce' ); ?>
                                    <input type="hidden" name="ws_action" value="delete">
                                    <input type="hidden" name="biz_id" value="<?php echo (int) $b->id; ?>">
                                    <button class="button button-small button-link-delete"><?php esc_html_e( 'Eliminar', 'workshop' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr id="ws-biz-form-<?php echo (int) $b->id; ?>" class="hidden">
                        <td colspan="6">
                            <form method="post" class="ws-admin-biz-form">
                                <?php wp_nonce_field( 'ws_manage_businesses', 'ws_biz_nonce' ); ?>
                                <input type="hidden" name="ws_action" value="update">
                                <input type="hidden" name="biz_id" value="<?php echo (int) $b->id; ?>">
                                <table class="form-table" role="presentation">
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Nombre', 'workshop' ); ?> *</label></th>
                                        <td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $b->name ); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Slug (URL)', 'workshop' ); ?> *</label></th>
                                        <td>
                                            <input type="text" name="slug" class="regular-text" value="<?php echo esc_attr( $b->slug ); ?>" placeholder="ej: mi-negocio">
                                            <?php if ( WS_Business::is_default( $b ) ) : ?>
                                                <p class="description" style="margin-top:4px">
                                                    <?php esc_html_e( 'Si lo dejas vacío, el negocio usa la URL raíz del sitio (/). Si pones un slug, su URL pasa a ser /slug/ y sus tablas wp_ws_* se renombran.', 'workshop' ); ?>
                                                </p>
                                            <?php else : ?>
                                                <p class="description" style="margin-top:4px">
                                                    <?php esc_html_e( 'URL de acceso:', 'workshop' ); ?>
                                                    <code><?php echo esc_html( home_url( '/' . $b->slug . '/' ) ); ?></code>
                                                </p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Descripción', 'workshop' ); ?></label></th>
                                        <td><textarea name="description" class="large-text" rows="2"><?php echo esc_textarea( $b->description ); ?></textarea></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Logo (URL)', 'workshop' ); ?></label></th>
                                        <td><input type="url" name="logo" class="regular-text" value="<?php echo esc_attr( $b->logo ); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Activo', 'workshop' ); ?></label></th>
                                        <td><label><input type="checkbox" name="active" value="1" <?php checked( (int) $b->active, 1 ); ?>> <?php esc_html_e( 'El negocio está activo', 'workshop' ); ?></label></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Acceso al mercado', 'workshop' ); ?></label></th>
                                        <td><label><input type="checkbox" name="marketplace_enabled" value="1" <?php checked( (int) $b->marketplace_enabled, 1 ); ?>> <?php esc_html_e( 'Mostrar sus tiendas en el mercado', 'workshop' ); ?></label></td>
                                    </tr>
                                    <tr>
                                        <th scope="row" colspan="2"><strong><?php esc_html_e( 'Cloudinary (opcional)', 'workshop' ); ?></strong></th>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Cloud name', 'workshop' ); ?></label></th>
                                        <td><input type="text" name="cloud_name" class="regular-text" value="<?php echo esc_attr( $b->cloud_name ); ?>" placeholder="ej: my-cloud"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'API key', 'workshop' ); ?></label></th>
                                        <td><input type="text" name="cloud_api_key" class="regular-text" value="<?php echo esc_attr( $b->cloud_api_key ); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'API secret', 'workshop' ); ?></label></th>
                                        <td><input type="text" name="cloud_api_secret" class="regular-text" value="<?php echo esc_attr( $b->cloud_api_secret ); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Upload preset (unsigned)', 'workshop' ); ?></label></th>
                                        <td><input type="text" name="cloud_upload_preset" class="regular-text" value="<?php echo esc_attr( $b->cloud_upload_preset ); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label><?php esc_html_e( 'Carpeta (folder)', 'workshop' ); ?></label></th>
                                        <td><input type="text" name="cloud_folder" class="regular-text" value="<?php echo esc_attr( $b->cloud_folder ); ?>"></td>
                                    </tr>
                                </table>
                                <?php submit_button( __( 'Guardar negocio', 'workshop' ), 'primary', 'submit', false ); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e( 'Nuevo negocio', 'workshop' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'ws_manage_businesses', 'ws_biz_nonce' ); ?>
            <input type="hidden" name="ws_action" value="create">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ws-new-name"><?php esc_html_e( 'Nombre', 'workshop' ); ?> *</label></th>
                    <td><input id="ws-new-name" type="text" name="name" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ws-new-slug"><?php esc_html_e( 'Slug (URL)', 'workshop' ); ?> *</label></th>
                    <td><input id="ws-new-slug" type="text" name="slug" class="regular-text" required placeholder="ej: mi-negocio"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Acceso al mercado', 'workshop' ); ?></label></th>
                    <td><label><input type="checkbox" name="marketplace_enabled" value="1"> <?php esc_html_e( 'Mostrar sus tiendas en el mercado', 'workshop' ); ?></label></td>
                </tr>
            </table>
            <?php submit_button( __( 'Crear negocio', 'workshop' ) ); ?>
        </form>

        <hr>
        <p>
            <strong><?php esc_html_e( 'Nota sobre dueños:', 'workshop' ); ?></strong>
            <?php esc_html_e( 'Solo el administrador del sitio puede crear o asignar el rol "Dueño del negocio". Entra al panel del negocio (URL con su slug) como administrador y usa el módulo Trabajadores.', 'workshop' ); ?>
        </p>
    </div>
    <style>
        .ws-admin-biz-form { background: #fff; border: 1px solid #c3c4c7; padding: 8px 16px; margin: 8px 0; }
        .ws-admin-biz-form .form-table th { width: 220px; }
    </style>
    <?php
}
