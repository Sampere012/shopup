<?php
/**
 * Planes de suscripción y límites por negocio.
 *
 * Modelo: cada negocio tiene UNA suscripción (tabla global wp_ws_subscriptions)
 * que apunta a un plan (tabla global wp_ws_plans). Los planes definen un precio
 * y límites de cantidad (productos, usuarios, puntos de venta, almacenes y
 * proveedores). Hay una prueba gratis por defecto de 7 días (plan is_trial).
 *
 * Cuando la prueba/plan vence o se supera un límite, el negocio queda
 * BLOQUEADO: no aparece en el marketplace, su portada y tiendas muestran un
 * aviso y nadie (dueño ni trabajadores) puede entrar al panel. El dueño ve una
 * pantalla con botón "Upgrade" que muestra los planes para solicitar un plan
 * mejor; el administrador aprueba o rechaza la solicitud desde wp-admin y solo
 * entonces el negocio queda habilitado de nuevo.
 *
 * El negocio por defecto (id 1, el del sitio) usa un plan interno "legacy"
 * ilimitado y nunca se bloquea.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Planes
 * ---------------------------------------------------------------------- */

class WS_Plans {

    /** Claves de límites disponibles (en este orden de presentación). */
    const LIMIT_KEYS = array( 'products', 'users', 'pvs', 'warehouses', 'suppliers' );

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . WS_TABLE_PREFIX . 'plans';
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
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s', sanitize_title( $slug ) ) );
    }

    public static function all( $active_only = false ) {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::table();
        if ( $active_only ) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, price ASC, id ASC';
        return $wpdb->get_results( $sql );
    }

    /** Planes visibles en el front (activos, sin el legacy interno). */
    public static function active() {
        $out = array();
        foreach ( self::all( true ) as $p ) {
            if ( 'legacy' === $p->slug ) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    /** Plan de prueba gratis (is_trial=1, activo). */
    public static function trial_plan() {
        foreach ( self::all( true ) as $p ) {
            if ( (int) $p->is_trial === 1 ) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Límites de un plan (array clave => int). 0 = sin límite.
     */
    public static function limits( $plan ) {
        $defaults = array();
        foreach ( self::LIMIT_KEYS as $k ) {
            $defaults[ $k ] = 0;
        }
        if ( ! $plan ) {
            return $defaults;
        }
        $raw = is_object( $plan ) ? ( $plan->limits ?? '' ) : ( $plan['limits'] ?? '' );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        if ( ! is_array( $decoded ) ) {
            return $defaults;
        }
        foreach ( $defaults as $k => $v ) {
            if ( isset( $decoded[ $k ] ) ) {
                $defaults[ $k ] = max( 0, (int) $decoded[ $k ] );
            }
        }
        return $defaults;
    }

    public static function limit_label( $key ) {
        $labels = array(
            'products'   => __( 'productos', 'workshop' ),
            'users'      => __( 'usuarios', 'workshop' ),
            'pvs'        => __( 'puntos de venta', 'workshop' ),
            'warehouses' => __( 'almacenes', 'workshop' ),
            'suppliers'  => __( 'proveedores', 'workshop' ),
        );
        return $labels[ $key ] ?? $key;
    }

    public static function limit_icon( $key ) {
        $icons = array(
            'products'   => 'fa-boxes-stacked',
            'users'      => 'fa-users',
            'pvs'        => 'fa-store',
            'warehouses' => 'fa-warehouse',
            'suppliers'  => 'fa-truck-field',
        );
        return $icons[ $key ] ?? 'fa-circle-check';
    }

    public static function format_price( $plan ) {
        $currency = ( $plan->currency ?? 'USD' ) ?: 'USD';
        $price    = (float) ( $plan->price ?? 0 );
        return number_format_i18n( $price, 2 ) . ' ' . $currency;
    }

    public static function duration_label( $plan ) {
        $days = (int) ( $plan->duration_days ?? 0 );
        if ( $days <= 0 ) {
            return __( 'Sin caducidad', 'workshop' );
        }
        if ( $days % 30 === 0 ) {
            $months = (int) ( $days / 30 );
            return sprintf( _n( '%d mes', '%d meses', $months, 'workshop' ), $months );
        }
        if ( $days % 7 === 0 ) {
            $weeks = (int) ( $days / 7 );
            return sprintf( _n( '%d semana', '%d semanas', $weeks, 'workshop' ), $weeks );
        }
        return sprintf( _n( '%d día', '%d días', $days, 'workshop' ), $days );
    }

    /**
     * Funciones que incluye el plan (atributos booleanos, no límites de
     * cantidad). Por ahora solo el chatbot del sitio.
     */
    public static function features( $plan ) {
        return array(
            'chatbot' => self::has_chatbot( $plan ),
        );
    }

    /** ¿El plan incluye el asistente (chatbot) del sitio? */
    public static function has_chatbot( $plan ) {
        return $plan && (int) ( $plan->has_chatbot ?? 0 ) === 1;
    }

    /**
     * Crea/actualiza un plan.
     */
    public static function save( $data, $id = 0 ) {
        $name = sanitize_text_field( $data['name'] ?? '' );
        if ( '' === $name ) {
            return new WP_Error( 'name', __( 'El nombre es obligatorio.', 'workshop' ) );
        }
        $slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
        if ( '' === $slug ) {
            $slug = sanitize_title( $name );
        }
        global $wpdb;
        $taken = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE slug = %s', $slug ) );
        if ( $taken && (int) $taken !== (int) $id ) {
            return new WP_Error( 'slug', __( 'Ese slug ya está en uso.', 'workshop' ) );
        }
        $limits = array();
        foreach ( self::LIMIT_KEYS as $k ) {
            $limits[ $k ] = isset( $data['limit_' . $k ] ) ? max( 0, (int) $data['limit_' . $k ] ) : 0;
        }
        $fields = array(
            'slug'          => $slug,
            'name'          => $name,
            'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
            'price'         => (float) ( $data['price'] ?? 0 ),
            'currency'      => sanitize_text_field( $data['currency'] ?? 'USD' ),
            'duration_days' => max( 0, (int) ( $data['duration_days'] ?? 30 ) ),
            'limits'        => wp_json_encode( $limits ),
            'has_chatbot'   => isset( $data['has_chatbot'] ) ? 1 : 0,
            'is_trial'      => isset( $data['is_trial'] ) ? 1 : 0,
            'is_active'     => isset( $data['is_active'] ) ? 1 : 0,
            'sort_order'    => (int) ( $data['sort_order'] ?? 0 ),
        );
        // El plan legacy nunca se desactiva ni se muestra.
        if ( 'legacy' === $slug ) {
            $fields['is_active'] = 0;
            $fields['has_chatbot'] = 1;
        }
        if ( $id ) {
            $wpdb->update( self::table(), $fields, array( 'id' => $id ) );
        } else {
            $wpdb->insert( self::table(), $fields );
            $id = (int) $wpdb->insert_id;
        }
        return $id;
    }

    public static function delete( $id ) {
        global $wpdb;
        $id = (int) $id;
        $plan = self::get( $id );
        if ( ! $plan || 'legacy' === $plan->slug ) {
            return false;
        }
        // No borrar si hay negocios activos con él.
        $subs = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . WS_Subscriptions::table() . ' WHERE plan_id = %d AND status IN (\'trial\',\'active\')',
            $id
        ) );
        if ( $subs ) {
            return false;
        }
        return (bool) $wpdb->delete( self::table(), array( 'id' => $id ) );
    }

    /**
     * Siembra los planes por defecto (solo la primera vez o si faltan).
     */
    public static function seed_defaults() {
        if ( get_option( 'ws_plans_seeded' ) ) {
            return;
        }
        global $wpdb;
        $table = self::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
            update_option( 'ws_plans_seeded', 1 );
            return;
        }
        $seed = array(
            array(
                'slug' => 'free-trial', 'name' => __( 'Prueba gratis', 'workshop' ),
                'description' => __( '7 días gratis para probar tu tienda sin límites de funciones. Al vencer, elige un plan.', 'workshop' ),
                'price' => 0, 'currency' => 'USD', 'duration_days' => (int) get_option( 'ws_trial_days', 7 ),
                'limits' => array( 'products' => 25, 'users' => 3, 'pvs' => 2, 'warehouses' => 1, 'suppliers' => 5 ),
                'has_chatbot' => 1, 'is_trial' => 1, 'is_active' => 1, 'sort_order' => 1,
            ),
            array(
                'slug' => 'basic', 'name' => __( 'Básico', 'workshop' ),
                'description' => __( 'Para negocios pequeños que empiezan a crecer.', 'workshop' ),
                'price' => 12.99, 'currency' => 'USD', 'duration_days' => 30,
                'limits' => array( 'products' => 100, 'users' => 5, 'pvs' => 3, 'warehouses' => 2, 'suppliers' => 20 ),
                'has_chatbot' => 0, 'is_trial' => 0, 'is_active' => 1, 'sort_order' => 2,
            ),
            array(
                'slug' => 'pro', 'name' => __( 'Pro', 'workshop' ),
                'description' => __( 'El plan más popular para negocios en pleno crecimiento.', 'workshop' ),
                'price' => 29.99, 'currency' => 'USD', 'duration_days' => 30,
                'limits' => array( 'products' => 500, 'users' => 15, 'pvs' => 10, 'warehouses' => 5, 'suppliers' => 100 ),
                'has_chatbot' => 1, 'is_trial' => 0, 'is_active' => 1, 'sort_order' => 3,
            ),
            array(
                'slug' => 'premium', 'name' => __( 'Premium', 'workshop' ),
                'description' => __( 'Todo lo que tu empresa necesita, sin preocuparte por los límites.', 'workshop' ),
                'price' => 59.99, 'currency' => 'USD', 'duration_days' => 30,
                'limits' => array( 'products' => 2000, 'users' => 50, 'pvs' => 30, 'warehouses' => 15, 'suppliers' => 500 ),
                'has_chatbot' => 1, 'is_trial' => 0, 'is_active' => 1, 'sort_order' => 4,
            ),
            array(
                'slug' => 'legacy', 'name' => __( 'Ilimitado (legacy)', 'workshop' ),
                'description' => __( 'Plan interno del negocio principal del sitio.', 'workshop' ),
                'price' => 0, 'currency' => 'USD', 'duration_days' => 0,
                'limits' => array( 'products' => 0, 'users' => 0, 'pvs' => 0, 'warehouses' => 0, 'suppliers' => 0 ),
                'has_chatbot' => 1, 'is_trial' => 0, 'is_active' => 0, 'sort_order' => 99,
            ),
        );
        foreach ( $seed as $plan ) {
            $wpdb->insert( $table, array(
                'slug'          => $plan['slug'],
                'name'          => $plan['name'],
                'description'   => $plan['description'],
                'price'         => $plan['price'],
                'currency'      => $plan['currency'],
                'duration_days' => $plan['duration_days'],
                'limits'        => wp_json_encode( $plan['limits'] ),
                'has_chatbot'   => $plan['has_chatbot'],
                'is_trial'      => $plan['is_trial'],
                'is_active'     => $plan['is_active'],
                'sort_order'    => $plan['sort_order'],
            ) );
        }
        update_option( 'ws_plans_seeded', 1 );
    }
}

function ws_plans_seed_defaults() {
    WS_Plans::seed_defaults();
}

/* -------------------------------------------------------------------------
 * Suscripciones por negocio
 * ---------------------------------------------------------------------- */

class WS_Subscriptions {

    const STATUS_LABELS = array(
        'trial'    => 'Prueba gratis',
        'active'   => 'Activo',
        'expired'  => 'Vencido',
        'suspended' => 'Suspendido',
    );

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . WS_TABLE_PREFIX . 'subscriptions';
    }

    public static function get( $biz_id ) {
        global $wpdb;
        $biz_id = (int) $biz_id;
        if ( ! $biz_id ) {
            return null;
        }
        $table = self::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE business_id = %d', $biz_id ) );
    }

    /**
     * Garantiza la fila de suscripción de un negocio:
     * - Negocio por defecto: plan legacy activo (nunca se bloquea).
     * - Cualquier otro negocio sin fila: prueba gratis (plan free-trial).
     */
    public static function ensure( $biz ) {
        if ( ! $biz ) {
            return null;
        }
        $sub = self::get( $biz->id );
        if ( $sub ) {
            return $sub;
        }
        global $wpdb;
        $table = self::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }
        if ( WS_Business::is_default( $biz ) ) {
            $legacy = WS_Plans::get_by_slug( 'legacy' );
            $wpdb->insert( $table, array(
                'business_id' => (int) $biz->id,
                'plan_id'     => $legacy ? (int) $legacy->id : 0,
                'status'      => 'active',
                'plan_started_at' => current_time( 'mysql' ),
            ) );
            return self::get( $biz->id );
        }
        $trial = WS_Plans::trial_plan();
        $days  = ws_trial_days();
        // Fechas SIEMPRE en UTC (gmdate + time()); las comparaciones usan
        // strtotime( ... . ' UTC' ) para no depender de la zona del servidor.
        $now_utc = gmdate( 'Y-m-d H:i:s', time() );
        $wpdb->insert( $table, array(
            'business_id'    => (int) $biz->id,
            'plan_id'        => $trial ? (int) $trial->id : 0,
            'status'         => 'trial',
            'trial_started_at' => $now_utc,
            'trial_ends_at'  => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
        ) );
        return self::get( $biz->id );
    }

    /**
     * Refresca el estado de la suscripción según fechas (persiste cambios):
     * prueba vencida -> expired, plan vencido -> expired.
     */
    public static function refresh( $biz, $sub = null ) {
        if ( ! $biz || WS_Business::is_default( $biz ) ) {
            return;
        }
        $sub = $sub ? $sub : self::get( $biz->id );
        if ( ! $sub ) {
            self::ensure( $biz );
            return;
        }
        global $wpdb;
        $now = time();
        // Prueba o plan vencidos (fechas SIEMPRE en UTC).
        $expired = ( 'trial' === $sub->status && $sub->trial_ends_at && strtotime( $sub->trial_ends_at . ' UTC' ) <= $now )
            || ( 'active' === $sub->status && $sub->plan_ends_at && strtotime( $sub->plan_ends_at . ' UTC' ) <= $now );
        if ( $expired ) {
            $wpdb->update( self::table(), array( 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $sub->id ) );
            $sub->status = 'expired';
            // En el momento exacto de vencer, se cierra la sesión de TODOS los
            // usuarios del negocio (dueños y trabajadores) en cualquier dispositivo.
            ws_logout_business_users( $biz );
        }
    }

    /**
     * Razón por la que un negocio está bloqueado, o null si puede operar.
     * El negocio por defecto nunca se bloquea.
     */
    public static function lock_reason( $biz ) {
        if ( ! $biz || WS_Business::is_default( $biz ) ) {
            return null;
        }
        $sub = self::ensure( $biz );
        self::refresh( $biz, $sub );
        if ( ! $sub ) {
            return null;
        }
        if ( 'suspended' === $sub->status ) {
            return array(
                'key'     => 'suspended',
                'title'   => __( 'Tu negocio está suspendido', 'workshop' ),
                'message' => __( 'El administrador suspendió tu negocio. Contacta con soporte para resolverlo.', 'workshop' ),
                'is_limit' => false,
            );
        }
        if ( 'expired' === $sub->status ) {
            $was_trial = $sub->plan_id && WS_Plans::get( $sub->plan_id ) && (int) WS_Plans::get( $sub->plan_id )->is_trial === 1;
            return array(
                'key'     => 'expired',
                'title'   => $was_trial ? __( 'Tu prueba gratis ha terminado', 'workshop' ) : __( 'Tu plan ha vencido', 'workshop' ),
                'message' => $was_trial
                    ? __( 'Se acabó tu semana gratis. Elige un plan para reactivar tu negocio y tus tiendas.', 'workshop' )
                    : __( 'Tu suscripción venció. Renueva o elige otro plan para seguir operando.', 'workshop' ),
                'is_limit' => false,
            );
        }
        // Límites superados (trial o plan activo).
        $plan    = $sub->plan_id ? WS_Plans::get( $sub->plan_id ) : null;
        $limits  = WS_Plans::limits( $plan );
        $usage   = ws_business_usage( $biz );
        foreach ( WS_Plans::LIMIT_KEYS as $k ) {
            $limit = (int) ( $limits[ $k ] ?? 0 );
            if ( $limit > 0 && (int) $usage[ $k ] > $limit ) {
                return array(
                    'key'      => 'limit_' . $k,
                    'title'    => sprintf( __( 'Alcanzaste el límite de %s', 'workshop' ), WS_Plans::limit_label( $k ) ),
                    'message'  => sprintf(
                        __( 'Tu plan permite %1$d %2$s y actualmente tienes %3$d. Haz upgrade para ampliar tu capacidad.', 'workshop' ),
                        $limit, WS_Plans::limit_label( $k ), (int) $usage[ $k ]
                    ),
                    'is_limit' => true,
                    'limit'    => $limit,
                    'used'     => (int) $usage[ $k ],
                    'type'     => $k,
                );
            }
        }
        return null;
    }

    public static function is_locked( $biz ) {
        return null !== self::lock_reason( $biz );
    }

    /**
     * Solicita un cambio de plan (upgrade). Solo dueños de ese negocio.
     */
    public static function request_upgrade( $biz_id, $plan_id ) {
        global $wpdb;
        $biz_id = (int) $biz_id;
        $plan   = WS_Plans::get( (int) $plan_id );
        if ( ! $plan || (int) $plan->is_active !== 1 || 'legacy' === $plan->slug ) {
            return new WP_Error( 'plan', __( 'Plan inválido.', 'workshop' ) );
        }
        $sub = self::get( $biz_id );
        if ( ! $sub ) {
            $biz = WS_Business::get( $biz_id );
            $sub = $biz ? self::ensure( $biz ) : null;
        }
        if ( ! $sub ) {
            return new WP_Error( 'sub', __( 'No existe una suscripción para este negocio.', 'workshop' ) );
        }
        if ( 'pending' === $sub->upgrade_status ) {
            return new WP_Error( 'pending', __( 'Ya tienes una solicitud pendiente de revisión.', 'workshop' ) );
        }
        $wpdb->update( self::table(), array(
            'upgrade_plan_id'      => (int) $plan->id,
            'upgrade_status'       => 'pending',
            'upgrade_requested_at' => current_time( 'mysql' ),
            'updated_at'           => current_time( 'mysql' ),
        ), array( 'id' => $sub->id ) );

        // Avisa al administrador del sitio por correo.
        $biz  = WS_Business::get( $biz_id );
        $name = $biz ? $biz->name : '#' . $biz_id;
        ws_send_mail(
            get_option( 'admin_email' ),
            sprintf( __( '[%s] Solicitud de cambio de plan', 'workshop' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) ),
            sprintf(
                __( 'El negocio «%1$s» solicitó el plan «%2$s» (%3$s). Aprueba o rechaza la solicitud desde wp-admin → ShopUp → Suscripciones.', 'workshop' ),
                $name, $plan->name, WS_Plans::format_price( $plan )
            )
        );
        return true;
    }

    public static function cancel_request( $biz_id ) {
        global $wpdb;
        $sub = self::get( (int) $biz_id );
        if ( ! $sub ) {
            return false;
        }
        return (bool) $wpdb->update( self::table(), array(
            'upgrade_plan_id'  => 0,
            'upgrade_status'   => 'none',
            'upgrade_requested_at' => null,
            'updated_at'       => current_time( 'mysql' ),
        ), array( 'id' => $sub->id ) );
    }

    /**
     * Aplica un plan a la suscripción (aprobación admin o cambio manual).
     * $ends_at_days: duración en días (0 = sin caducidad).
     */
    public static function apply_plan( $biz_id, $plan_id, $status = 'active' ) {
        global $wpdb;
        $plan = WS_Plans::get( (int) $plan_id );
        if ( ! $plan ) {
            return false;
        }
        $days = (int) $plan->duration_days;
        $now  = current_time( 'mysql' );
        $now_utc = gmdate( 'Y-m-d H:i:s', time() );
        $wpdb->update( self::table(), array(
            'plan_id'         => (int) $plan->id,
            'status'          => in_array( $status, array( 'active', 'trial', 'expired', 'suspended' ), true ) ? $status : 'active',
            'trial_started_at' => null,
            'trial_ends_at'   => null,
            'plan_started_at' => $now,
            'plan_ends_at'    => $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null,
            'upgrade_plan_id' => 0,
            'upgrade_status'  => 'none',
            'upgrade_requested_at' => null,
            'upgrade_decided_at'   => $now,
            'updated_at'      => $now,
        ), array( 'business_id' => (int) $biz_id ) );
        // Notifica a los dueños del negocio.
        $biz = WS_Business::get( (int) $biz_id );
        if ( $biz ) {
            foreach ( ws_business_owner_ids( $biz ) as $uid ) {
                ws_notification_add(
                    $uid,
                    'plan',
                    __( '¡Tu plan fue actualizado!', 'workshop' ),
                    sprintf( __( 'Tu negocio ahora tiene el plan %s. Ya puedes operar con normalidad.', 'workshop' ), $plan->name ),
                    ws_panel_url( 'owner', 'plan', $biz ),
                    'plan_approved_' . (int) $biz_id,
                    $biz
                );
            }
        }
        return true;
    }
}

/** Días de prueba gratis (configurable desde wp-admin). */
function ws_trial_days() {
    return max( 1, (int) get_option( 'ws_trial_days', 7 ) );
}

/**
 * Migración: los negocios que ya existían antes del sistema de suscripciones
 * reciben el plan legacy (ilimitado) para no bloquearlos por sorpresa. Los
 * negocios nuevos (registro público) siempre arrancan con la prueba gratis.
 * Se ejecuta una sola vez durante la instalación diferida (setup.php).
 */
function ws_migrate_existing_subscriptions() {
    if ( get_option( 'ws_subs_migrated' ) ) {
        return;
    }
    global $wpdb;
    $table = WS_Subscriptions::table();
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return;
    }
    $legacy = WS_Plans::get_by_slug( 'legacy' );
    $done   = 0;
    foreach ( WS_Business::all() as $biz ) {
        if ( WS_Subscriptions::get( $biz->id ) ) {
            continue;
        }
        $wpdb->insert( $table, array(
            'business_id'     => (int) $biz->id,
            'plan_id'         => $legacy ? (int) $legacy->id : 0,
            'status'          => 'active',
            'plan_started_at' => current_time( 'mysql' ),
            'upgrade_status'  => 'none',
        ) );
        $done++;
    }
    if ( $done > 0 && function_exists( 'ws_send_mail' ) ) {
        ws_send_mail(
            get_option( 'admin_email' ),
            sprintf( __( '[%s] Suscripciones migradas', 'workshop' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) ),
            sprintf(
                __( 'Se asignó el plan ilimitado a %1$d negocio(s) existente(s) al activar el sistema de suscripciones. Gestiona sus planes en wp-admin → ShopUp → Suscripciones.', 'workshop' ),
                $done
            )
        );
    }
    update_option( 'ws_subs_migrated', 1 );
}

/**
 * Cierra la sesión de TODOS los usuarios de un negocio (dueños y trabajadores)
 * en cualquier dispositivo, destruyendo sus sesiones de WordPress.
 * Se invoca en el momento exacto en que la suscripción del negocio vence.
 */
function ws_logout_business_users( $biz ) {
    if ( ! $biz || WS_Business::is_default( $biz ) ) {
        return;
    }
    if ( ! class_exists( 'WP_Session_Tokens' ) ) {
        return;
    }
    $ids = get_users( array(
        'role__in'   => array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ),
        'fields'     => 'ID',
        'meta_key'   => 'ws_business_id',
        'meta_value' => (int) $biz->id,
    ) );
    foreach ( array_map( 'intval', $ids ) as $uid ) {
        WP_Session_Tokens::get_instance( $uid )->destroy_all();
    }
}

/** IDs de los dueños de un negocio. */
function ws_business_owner_ids( $biz ) {
    $biz_id = (int) $biz->id;
    $ids = array();
    if ( WS_Business::is_default( $biz ) ) {
        foreach ( get_users( array( 'role' => 'ws_owner', 'fields' => 'ID', 'meta_key' => 'ws_business_id', 'meta_compare' => 'NOT EXISTS' ) ) as $uid ) {
            $ids[] = (int) $uid;
        }
    }
    foreach ( get_users( array( 'role' => 'ws_owner', 'fields' => 'ID', 'meta_key' => 'ws_business_id', 'meta_value' => $biz_id ) ) as $uid ) {
        $ids[] = (int) $uid;
    }
    return array_values( array_unique( $ids ) );
}

/**
 * Uso actual del negocio por tipo de límite.
 */
function ws_business_usage( $biz = null ) {
    global $wpdb;
    $biz  = $biz ? $biz : ws_current_business();
    $out  = array( 'products' => 0, 'users' => 0, 'pvs' => 0, 'warehouses' => 0, 'suppliers' => 0 );
    if ( ! $biz ) {
        return $out;
    }
    $pro_table = ws_table_name( 'products', $biz );
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pro_table ) ) === $pro_table ) {
        $out['products'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pro_table} WHERE active = 1" );
        $sup_table = ws_table_name( 'suppliers', $biz );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sup_table ) ) === $sup_table ) {
            $out['suppliers'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sup_table}" );
        }
    }
    $loc_table = ws_table_name( 'locations', $biz );
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $loc_table ) ) === $loc_table ) {
        $out['pvs'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$loc_table} WHERE type = 'pv' AND active = 1" );
        $out['warehouses'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$loc_table} WHERE type <> 'pv' AND active = 1" );
    }
    $out['users'] = ws_biz_users_count( $biz );
    return $out;
}

/**
 * Estado de un límite del plan (productos, usuarios, PV, almacenes…).
 *
 * Sirve para que las plantillas muestren la restricción de forma palpable:
 * cuánto se usa, cuánto queda y si ya se alcanzó el máximo (no se puede crear).
 * Devuelve array con limit, used, remaining, pct y full.
 */
function ws_plan_limit_status( $type, $biz = null ) {
    $data  = ws_subscription_data( $biz );
    $limit = (int) ( $data['limits'][ $type ] ?? 0 );
    $used  = (int) ( $data['usage'][ $type ] ?? 0 );
    return array(
        'limit'     => $limit,
        'used'      => $used,
        'remaining' => $limit > 0 ? max( 0, $limit - $used ) : PHP_INT_MAX,
        'pct'       => $limit > 0 ? min( 100, (int) round( $used / $limit * 100 ) ) : 0,
        'full'      => $limit > 0 && $used >= $limit,
    );
}

function ws_biz_users_count( $biz ) {
    // Solo cuentan los trabajadores (almaceneros y vendedores). El dueño del
    // negocio NO se incluye en el límite de usuarios del plan: no es un
    // trabajador, sino quien administra el negocio.
    $roles  = array( 'ws_storekeeper', 'ws_seller' );
    $ids    = array();
    if ( WS_Business::is_default( $biz ) ) {
        foreach ( get_users( array( 'role__in' => $roles, 'fields' => 'ID', 'meta_key' => 'ws_business_id', 'meta_compare' => 'NOT EXISTS' ) ) as $uid ) {
            $ids[] = (int) $uid;
        }
    }
    foreach ( get_users( array( 'role__in' => $roles, 'fields' => 'ID', 'meta_key' => 'ws_business_id', 'meta_value' => (int) $biz->id ) ) as $uid ) {
        $ids[] = (int) $uid;
    }
    return count( array_unique( $ids ) );
}

/**
 * Datos normalizados de la suscripción para las plantillas.
 */
function ws_subscription_data( $biz = null ) {
    $biz   = $biz ? $biz : ws_current_business();
    $sub   = WS_Subscriptions::ensure( $biz );
    WS_Subscriptions::refresh( $biz, $sub );
    $plan  = ( $sub && $sub->plan_id ) ? WS_Plans::get( $sub->plan_id ) : WS_Plans::trial_plan();
    $usage = ws_business_usage( $biz );
    $lock  = WS_Subscriptions::lock_reason( $biz );

    $trial_days_left = 0;
    if ( $sub && 'trial' === $sub->status && $sub->trial_ends_at ) {
        $trial_days_left = max( 0, (int) ceil( ( strtotime( $sub->trial_ends_at . ' UTC' ) - time() ) / DAY_IN_SECONDS ) );
    }
    $plan_days_left = 0;
    if ( $sub && 'active' === $sub->status && $sub->plan_ends_at ) {
        $plan_days_left = max( 0, (int) ceil( ( strtotime( $sub->plan_ends_at . ' UTC' ) - time() ) / DAY_IN_SECONDS ) );
    }

    $upgrade_plan = null;
    if ( $sub && $sub->upgrade_plan_id ) {
        $upgrade_plan = WS_Plans::get( $sub->upgrade_plan_id );
    }

    return array(
        'sub'            => $sub,
        'plan'           => $plan,
        'usage'          => $usage,
        'limits'         => $plan ? WS_Plans::limits( $plan ) : array(),
        'lock'           => $lock,
        'locked'         => null !== $lock,
        'status'         => $sub ? $sub->status : 'trial',
        'status_label'   => $sub ? ws_status_label( $sub->status ) : __( 'Prueba gratis', 'workshop' ),
        'is_trial'       => $sub && 'trial' === $sub->status,
        'is_active'      => $sub && 'active' === $sub->status,
        'trial_days_left' => $trial_days_left,
        'plan_days_left'  => $plan_days_left,
        'upgrade_pending' => $sub && 'pending' === $sub->upgrade_status,
        'upgrade_plan'   => $upgrade_plan,
    );
}

function ws_status_label( $status ) {
    $labels = array(
        'trial'    => __( 'Prueba gratis', 'workshop' ),
        'active'   => __( 'Activo', 'workshop' ),
        'expired'  => __( 'Vencido', 'workshop' ),
        'suspended' => __( 'Suspendido', 'workshop' ),
    );
    return $labels[ $status ] ?? $status;
}

/**
 * ¿El plan actual del negocio incluye el chatbot del sitio?
 * Sirve para decidir si el asistente se activa dentro del panel de negocio.
 */
function ws_biz_has_chatbot( $biz = null ) {
    $biz  = $biz ? $biz : ws_current_business();
    $data = ws_subscription_data( $biz );
    return WS_Plans::has_chatbot( $data['plan'] );
}

/* -------------------------------------------------------------------------
 * Guarda de límites al crear recursos (productos, usuarios, PV, almacenes…)
 * ---------------------------------------------------------------------- */

/**
 * Comprueba el límite de un tipo antes de crear un recurso.
 * Devuelve true o un WP_Error. Los administradores del sitio no se limitan.
 */
function ws_plan_guard( $type ) {
    if ( ! in_array( $type, WS_Plans::LIMIT_KEYS, true ) ) {
        return true;
    }
    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }
    $biz   = ws_current_business();
    $data  = ws_subscription_data( $biz );
    $limit = (int) ( $data['limits'][ $type ] ?? 0 );
    if ( $limit > 0 && (int) ( $data['usage'][ $type ] ?? 0 ) >= $limit ) {
        return new WP_Error(
            'plan_limit',
            sprintf(
                __( 'Alcanzaste el límite de %1$s de tu plan (%2$d). Elimina algunos o haz upgrade para ampliarlo.', 'workshop' ),
                WS_Plans::limit_label( $type ),
                $limit
            )
        );
    }
    return true;
}

/* -------------------------------------------------------------------------
 * Solicitud de upgrade (formulario server-side + AJAX)
 * ---------------------------------------------------------------------- */

add_action( 'init', 'ws_handle_plan_request_post' );
function ws_handle_plan_request_post() {
    if ( empty( $_POST['ws_plan_request'] ) || empty( $_POST['ws_nonce'] ) || ! wp_verify_nonce( $_POST['ws_nonce'], 'ws_plan_request' ) ) {
        return;
    }
    if ( ! is_user_logged_in() || 'owner' !== ws_user_role() ) {
        wp_safe_redirect( ws_business_home() );
        exit;
    }
    $biz    = ws_current_business();
    $action = sanitize_key( $_POST['ws_plan_request'] );
    $plan_id = (int) ( $_POST['plan_id'] ?? 0 );
    if ( 'request' === $action && $plan_id ) {
        $result = WS_Subscriptions::request_upgrade( $biz->id, $plan_id );
        $msg    = is_wp_error( $result ) ? 'error=' . rawurlencode( $result->get_error_message() ) : 'ok=requested';
    } elseif ( 'cancel' === $action ) {
        WS_Subscriptions::cancel_request( $biz->id );
        $msg = 'ok=cancelled';
    } else {
        $msg = 'error=' . rawurlencode( __( 'Acción inválida.', 'workshop' ) );
    }
    wp_safe_redirect( add_query_arg( 'ws_plan_msg', $msg, ws_panel_url( 'owner', 'plan', $biz ) ) );
    exit;
}

add_action( 'wp_ajax_ws_plan_request', 'ws_ajax_plan_request' );
function ws_ajax_plan_request() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) || ! is_user_logged_in() || 'owner' !== ws_user_role() ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'workshop' ) ) );
    }
    $biz = ws_current_business();
    if ( WS_Business::is_default( $biz ) ) {
        wp_send_json_error( array( 'msg' => __( 'Este negocio no necesita plan.', 'workshop' ) ) );
    }
    $result = WS_Subscriptions::request_upgrade( $biz->id, (int) ( $_POST['plan_id'] ?? 0 ) );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_plan_cancel_request', 'ws_ajax_plan_cancel_request' );
function ws_ajax_plan_cancel_request() {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) || ! is_user_logged_in() || 'owner' !== ws_user_role() ) {
        wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'workshop' ) ) );
    }
    $biz = ws_current_business();
    WS_Subscriptions::cancel_request( $biz->id );
    wp_send_json_success();
}

/* -------------------------------------------------------------------------
 * Avisos de suscripción (notificaciones)
 * ---------------------------------------------------------------------- */

/**
 * Genera avisos de suscripción para los dueños del negocio actual:
 * prueba a punto de vencer, plan vencido, límite superado.
 * Se invoca junto a ws_generate_notifications (poll del navbar y logins).
 */
function ws_subscription_notify( $user_id = 0 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return;
    }
    $biz = ws_user_business( $user_id );
    if ( WS_Business::is_default( $biz ) ) {
        return;
    }
    $data = ws_subscription_data( $biz );
    if ( empty( $data['sub'] ) ) {
        return;
    }
    $sub    = $data['sub'];
    $panel  = ws_panel_url( 'owner', 'plan', $biz );
    $daykey = current_time( 'Ymd' );

    if ( 'trial' === $sub->status ) {
        $left = $data['trial_days_left'];
        if ( $left <= 3 && $left > 0 ) {
            ws_notification_daily(
                $user_id, 'trial_ending',
                __( 'Tu prueba gratis termina pronto', 'workshop' ),
                sprintf( _n( 'Te queda %d día de prueba. Elige un plan para no interrumpir tu negocio.', 'Te quedan %d días de prueba. Elige un plan para no interrumpir tu negocio.', $left, 'workshop' ), $left ),
                $panel, 'trial_ending_' . $daykey, $biz
            );
        } elseif ( $left <= 0 ) {
            ws_notification_daily(
                $user_id, 'trial_expired',
                __( 'Tu prueba gratis terminó', 'workshop' ),
                __( 'Tu negocio quedó en pausa. Elige un plan para reactivarlo.', 'workshop' ),
                $panel, 'trial_expired', $biz
            );
        }
    } elseif ( 'active' === $sub->status && $data['plan_days_left'] > 0 && $data['plan_days_left'] <= 3 ) {
        ws_notification_daily(
            $user_id, 'plan_ending',
            __( 'Tu plan vence pronto', 'workshop' ),
            sprintf( _n( 'Tu plan vence en %d día. Renueva para no interrumpir tu negocio.', 'Tu plan vence en %d días. Renueva para no interrumpir tu negocio.', $data['plan_days_left'], 'workshop' ), $data['plan_days_left'] ),
            $panel, 'plan_ending_' . $daykey, $biz
        );
    }

    // Límite superado (bloqueo por capacidad).
    if ( ! empty( $data['lock'] ) && ! empty( $data['lock']['is_limit'] ) ) {
        ws_notification_daily(
            $user_id, 'plan_limit',
            $data['lock']['title'],
            $data['lock']['message'],
            $panel, 'plan_limit_' . ( $data['lock']['type'] ?? 'x' ), $biz
        );
    } elseif ( ! empty( $data['lock'] ) && 'expired' === $data['lock']['key'] ) {
        ws_notification_daily(
            $user_id, 'plan_expired',
            $data['lock']['title'],
            $data['lock']['message'],
            $panel, 'plan_expired', $biz
        );
    }
}
