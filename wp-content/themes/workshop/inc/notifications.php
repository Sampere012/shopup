<?php
/**
 * Notificaciones del navbar para usuarios logueados.
 *
 * Genera alertas útiles según el rol (vendedor -> almacenero -> dueño ->
 * admin del sitio) y las guarda por usuario en ws_notifications:
 * - Stock bajo / agotado (por ubicación).
 * - Pedidos pendientes.
 * - Nuevos pedidos (evento).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tabla de notificaciones.
 */
function ws_notifications_table( $biz = null ) {
    return ws_table_name( 'notifications', $biz );
}

/**
 * Inserta una notificación para un usuario.
 */
function ws_notification_add( $user_id, $type, $title, $message, $link = '', $ref_key = '', $biz = null ) {
    global $wpdb;
    if ( ! (int) $user_id || '' === $title ) {
        return 0;
    }
    $wpdb->insert(
        ws_notifications_table( $biz ),
        array(
            'user_id'    => (int) $user_id,
            'type'       => sanitize_key( $type ),
            'title'      => sanitize_text_field( $title ),
            'message'    => sanitize_text_field( $message ),
            'link'       => esc_url_raw( $link ),
            'ref_key'    => sanitize_key( $ref_key ),
            'is_read'    => 0,
            'created_at' => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
    );
    return (int) $wpdb->insert_id;
}

/**
 * Crea (o actualiza el mensaje) una notificación deduplicada por ref_key.
 * Si $active es false, marca como leídas las existentes de esa ref (la
 * condición dejó de existir, p. ej. el stock se repuso).
 */
function ws_notification_sync( $user_id, $type, $title, $message, $link = '', $ref_key = '', $active = true, $biz = null ) {
    global $wpdb;
    $table = ws_notifications_table( $biz );
    if ( $active ) {
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id=%d AND ref_key=%s AND is_read=0 ORDER BY id DESC LIMIT 1",
            $user_id, $ref_key
        ) );
        if ( $existing ) {
            $wpdb->update(
                $table,
                array( 'title' => sanitize_text_field( $title ), 'message' => sanitize_text_field( $message ), 'link' => esc_url_raw( $link ), 'created_at' => current_time( 'mysql' ) ),
                array( 'id' => (int) $existing )
            );
            return (int) $existing;
        }
        return ws_notification_add( $user_id, $type, $title, $message, $link, $ref_key, $biz );
    }
    $wpdb->update(
        $table,
        array( 'is_read' => 1 ),
        array( 'user_id' => (int) $user_id, 'ref_key' => $ref_key )
    );
    return 0;
}

/**
 * Notificación diaria (una por ref_key por día): actualiza la fila existente
 * sin importar si ya fue leída, y solo crea una nueva si no existe ninguna con
 * ese ref_key. Evita que el resumen diario se "re-spawnee" como no leída cada
 * vez que se marca leída (a diferencia de ws_notification_sync).
 */
function ws_notification_daily( $user_id, $type, $title, $message, $link = '', $ref_key = '', $biz = null ) {
    global $wpdb;
    $table = ws_notifications_table( $biz );
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id=%d AND ref_key=%s ORDER BY id DESC LIMIT 1",
        $user_id, $ref_key
    ) );
    if ( $existing ) {
        $wpdb->update(
            $table,
            array( 'title' => sanitize_text_field( $title ), 'message' => sanitize_text_field( $message ), 'link' => esc_url_raw( $link ), 'created_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $existing )
        );
        return (int) $existing;
    }
    return ws_notification_add( $user_id, $type, $title, $message, $link, $ref_key, $biz );
}

/**
 * Genera las alertas derivadas del estado actual (stock bajo/agotado,
 * pedidos pendientes) para un usuario, según sus permisos y ubicaciones.
 * Las condiciones resueltas se marcan como leídas automáticamente.
 */
function ws_generate_notifications( $user_id = 0 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return;
    }
    // Los administradores del sistema sin negocio asignado no reciben alertas
    // de negocio: esas pertenecen a cada negocio y se ven en su propio panel.
    if ( user_can( $user_id, 'manage_options' ) && ! ws_user_role( $user_id ) ) {
        return;
    }

    // Avisos de suscripción (prueba por vencer, plan vencido, límite superado).
    if ( function_exists( 'ws_subscription_notify' ) ) {
        ws_subscription_notify( $user_id );
    }
    global $wpdb;
    // Tablas por negocio: el prefijo fijo (wp_ws_) solo existe para el negocio
    // por defecto; los demás usan wp_ws_{slug}_ws_*.
    $t_stock       = ws_table_name( 'stock' );
    $t_products    = ws_table_name( 'products' );
    $t_locations   = ws_table_name( 'locations' );
    $t_orders      = ws_table_name( 'orders' );
    $t_movements   = ws_table_name( 'movements' );
    $t_order_items = ws_table_name( 'order_items' );
    $t_suppliers   = ws_table_name( 'suppliers' );
    $loc_ids = array_map( fn( $l ) => (int) $l->id, ws_user_locations( $user_id ) );
    if ( empty( $loc_ids ) ) {
        return;
    }
    $ph = implode( ',', array_fill( 0, count( $loc_ids ), '%d' ) );
    $role = ws_user_role( $user_id );
    // Link al panel correspondiente; el admin del sistema va a wp-admin.
    $panel = fn( $page ) => $role ? ws_panel_url( $role, $page ) : ( user_can( $user_id, 'manage_options' ) ? admin_url() : ws_dashboard_url() );

    // --- Stock bajo / agotado por ubicación (stock_view) ---
    if ( WS_Capabilities::can( 'stock_view', $user_id ) ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.location_id, l.name AS loc_name,
                    SUM( CASE WHEN s.qty <= p.min_stock AND s.qty > 0 THEN 1 ELSE 0 END ) AS low_qty,
                    SUM( CASE WHEN s.qty <= 0 THEN 1 ELSE 0 END ) AS out_qty
             FROM {$t_stock} s
             INNER JOIN {$t_products} p ON p.id = s.product_id
             LEFT JOIN {$t_locations} l ON l.id = s.location_id
             WHERE s.location_id IN ({$ph})
             GROUP BY s.location_id, l.name",
            ...$loc_ids
        ) );
        $seen_refs = array();
        foreach ( $rows as $r ) {
            $loc_name = $r->loc_name ? $r->loc_name : '#' . (int) $r->location_id;
            $low_ref = 'low_stock_' . (int) $r->location_id;
            $out_ref = 'out_stock_' . (int) $r->location_id;
            $seen_refs[] = $low_ref;
            $seen_refs[] = $out_ref;
            ws_notification_sync(
                $user_id, 'low_stock',
                __( 'Stock bajo', 'workshop' ),
                sprintf( __( '%d producto(s) bajo el mínimo en %s', 'workshop' ), (int) $r->low_qty, $loc_name ),
                $panel( 'stock' ), $low_ref, (int) $r->low_qty > 0
            );
            ws_notification_sync(
                $user_id, 'out_stock',
                __( 'Producto agotado', 'workshop' ),
                sprintf( __( '%d producto(s) sin stock en %s', 'workshop' ), (int) $r->out_qty, $loc_name ),
                $panel( 'stock' ), $out_ref, (int) $r->out_qty > 0
            );
        }
        // Resolver refs de ubicaciones que ya no aplican: si no hay filas
        // (p. ej. se eliminó todo el stock) se marcan leídas todas las de
        // stock bajo/agotado; si hay filas, las que no están en $seen_refs.
        if ( $seen_refs ) {
            $refs_ph = implode( ',', array_fill( 0, count( $seen_refs ), '%s' ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . ws_notifications_table() . " SET is_read=1
                 WHERE user_id=%d AND is_read=0 AND (ref_key LIKE 'low_stock_%' OR ref_key LIKE 'out_stock_%')
                 AND ref_key NOT IN ({$refs_ph})",
                array_merge( array( $user_id ), $seen_refs )
            ) );
        } else {
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . ws_notifications_table() . " SET is_read=1
                 WHERE user_id=%d AND is_read=0 AND (ref_key LIKE 'low_stock_%' OR ref_key LIKE 'out_stock_%')",
                $user_id
            ) );
        }
    }

    // --- Pedidos pendientes (orders_view) ---
    if ( WS_Capabilities::can( 'orders_view', $user_id ) ) {
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_orders} WHERE location_id IN ({$ph}) AND status='pending'",
            ...$loc_ids
        ) );
        ws_notification_sync(
            $user_id, 'pending_orders',
            __( 'Pedidos pendientes', 'workshop' ),
            sprintf( _n( 'Hay %d pedido esperando atención', 'Hay %d pedidos esperando atención', $pending, 'workshop' ), $pending ),
            $panel( 'orders' ), 'pending_orders', $pending > 0
        );
    }

    // --- Resumen del día (dueño / admin): ventas y movimientos recientes. ---
    if ( WS_Capabilities::can( 'reports_view', $user_id ) ) {
        // current_time('Ymd') usa la zona horaria del sitio, igual que
        // CURDATE()/current_time('mysql'), para no duplicar el ref_key del día.
        $today_key = 'day_' . current_time( 'Ymd' );

        // Ventas del día: pedidos aceptados (completados o aceptados) de hoy.
        $sales_today = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM {$t_orders}
             WHERE location_id IN ({$ph}) AND status IN ('accepted','completed') AND DATE(created_at) = CURDATE()",
            ...$loc_ids
        ) );
        $orders_today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_orders}
             WHERE location_id IN ({$ph}) AND status IN ('accepted','completed') AND DATE(created_at) = CURDATE()",
            ...$loc_ids
        ) );
        if ( $sales_today > 0 ) {
            ws_notification_daily(
                $user_id, 'sales_today',
                __( 'Ventas del día', 'workshop' ),
                sprintf( __( '%s en %d pedido(s) de hoy', 'workshop' ), ws_money( $sales_today, ws_currency_symbol() ), $orders_today ),
                $panel( 'reports' ), 'sales_today_' . $today_key
            );
        }

        // Movimientos de stock en las últimas 24 h.
        $moves_24h = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_movements}
             WHERE location_id IN ({$ph}) AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            ...$loc_ids
        ) );
        if ( $moves_24h > 0 ) {
            ws_notification_daily(
                $user_id, 'recent_movements',
                __( 'Movimientos de stock recientes', 'workshop' ),
                sprintf( _n( '%d movimiento de stock en las últimas 24 h', '%d movimientos de stock en las últimas 24 h', $moves_24h, 'workshop' ), $moves_24h ),
                $panel( 'movements' ), 'moves_24h_' . $today_key
            );
        }

        // --- Top de la semana (producto más vendido + proveedor) ---
        // Clave semanal ISO (año-semana) para que se genere una por semana,
        // igual que el resumen diario con ws_notification_daily.
        $week_key = 'week_' . current_time( 'o' ) . '_W' . current_time( 'W' );
        // Formatea cantidades: hasta 2 decimales quitando ceros finales
        // (25, 2.5, 2.50 -> "25", "2.5") para no redondear kilos/medidas.
        // Se recorta el separador decimal según el locale ('.' o ',').
        $fmt_units = fn( $n ) => rtrim( rtrim( number_format_i18n( (float) $n, 2 ), '0' ), '.,' );

        // Producto más vendido de la semana (por unidades, pedidos
        // aceptados/completados en las ubicaciones del usuario).
        $top_product = $wpdb->get_row( $wpdb->prepare(
            "SELECT oi.product_name, SUM(oi.qty) AS units
             FROM {$t_order_items} oi
             INNER JOIN {$t_orders} o ON o.id = oi.order_id
             WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed')
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY oi.product_id, oi.product_name
             ORDER BY units DESC, oi.product_name ASC
             LIMIT 1",
            ...$loc_ids
        ) );
        if ( $top_product && (float) $top_product->units > 0 ) {
            ws_notification_daily(
                $user_id, 'top_product',
                __( 'Producto top de la semana', 'workshop' ),
                sprintf(
                    __( '«%s» — %s unidad(es) esta semana', 'workshop' ),
                    $top_product->product_name,
                    $fmt_units( $top_product->units )
                ),
                $panel( 'reports' ), 'top_product_' . $week_key
            );
        }

        // Proveedor con más unidades vendidas esta semana.
        $top_supplier = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.name AS supplier_name, SUM(oi.qty) AS units
             FROM {$t_order_items} oi
             INNER JOIN {$t_orders} o ON o.id = oi.order_id
             INNER JOIN {$t_products} p ON p.id = oi.product_id
             INNER JOIN {$t_suppliers} s ON s.id = p.supplier_id
             WHERE o.location_id IN ({$ph}) AND o.status IN ('accepted','completed')
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY s.id, s.name
             ORDER BY units DESC, s.name ASC
             LIMIT 1",
            ...$loc_ids
        ) );
        if ( $top_supplier && (float) $top_supplier->units > 0 ) {
            ws_notification_daily(
                $user_id, 'top_supplier',
                __( 'Proveedor más vendido', 'workshop' ),
                sprintf(
                    __( '«%s» — %s unidad(es) esta semana', 'workshop' ),
                    $top_supplier->supplier_name,
                    $fmt_units( $top_supplier->units )
                ),
                $panel( 'reports' ), 'top_supplier_' . $week_key
            );
        }
    }
}

/**
 * Notificaciones recientes de un usuario (nuevas primero).
 */
function ws_notifications_for_user( $user_id = 0, $limit = 40 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return array();
    }
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . ws_notifications_table() . " WHERE user_id=%d ORDER BY is_read ASC, id DESC LIMIT %d",
        $user_id, max( 1, (int) $limit )
    ) );
    $out = array();
    foreach ( $rows as $r ) {
        $out[] = array(
            'id'       => (int) $r->id,
            'type'     => $r->type,
            'title'    => $r->title,
            'message'  => $r->message,
            'link'     => $r->link,
            'is_read'  => (int) $r->is_read,
            'date'     => mysql2date( 'd/m/Y H:i', $r->created_at ),
            'time'     => human_time_diff( strtotime( $r->created_at ), current_time( 'timestamp' ) ) . ' ' . __( 'atrás', 'workshop' ),
        );
    }
    return $out;
}

/**
 * Conteo de no leídas de un usuario.
 */
function ws_notifications_unread_count( $user_id = 0 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return 0;
    }
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . ws_notifications_table() . " WHERE user_id=%d AND is_read=0",
        $user_id
    ) );
}

/**
 * Marca como leídas: una, varias o todas (ids vacío = todas).
 */
function ws_notifications_mark_read( $user_id = 0, $ids = array() ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return;
    }
    global $wpdb;
    $table = ws_notifications_table();
    if ( empty( $ids ) ) {
        $wpdb->update( $table, array( 'is_read' => 1 ), array( 'user_id' => $user_id ) );
        return;
    }
    $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
    if ( empty( $ids ) ) {
        return;
    }
    $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET is_read=1 WHERE user_id=%d AND id IN ({$ph})",
        array_merge( array( $user_id ), $ids )
    ) );
}

/**
 * Elimina notificaciones de un usuario (ids vacío = todas).
 */
function ws_notifications_delete( $user_id = 0, $ids = array() ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return;
    }
    global $wpdb;
    $table = ws_notifications_table();
    if ( empty( $ids ) ) {
        $wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
        return;
    }
    $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
    if ( empty( $ids ) ) {
        return;
    }
    $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE user_id=%d AND id IN ({$ph})",
        array_merge( array( $user_id ), $ids )
    ) );
}

/**
 * Envía una notificación por evento a los usuarios relevantes de una ubicación
 * (solo dueños del mismo negocio y trabajadores asignados a esa ubicación con
 * el permiso indicado; los administradores del sistema no reciben alertas de
 * negocio — se ven en el panel de cada negocio, y el correo de nuevo pedido
 * va aparte). Cada alerta se guarda en la tabla de notificaciones del negocio
 * del usuario, para que le llegue en su propio panel.
 * Si se pasa $ref_key se deduplica por usuario: no se inserta una segunda
 * notificación no leída con la misma ref (evita "fantasmas" por doble envío).
 */
function ws_notify_location_users( $location_id, $type, $title, $message, $link_page, $cap, $ref_key = '' ) {
    $user_ids = array();
    // Dueños del negocio de la ubicación. La ubicación pertenece al negocio
    // del contexto de la petición (su tabla). Para el negocio por defecto se
    // incluyen también los dueños legacy (sin ws_business_id).
    $biz_id = ws_current_business_id();
    if ( WS_Business::is_default_id( $biz_id ) ) {
        foreach ( get_users( array( 'role' => 'ws_owner', 'fields' => 'ID', 'meta_key' => 'ws_business_id', 'meta_compare' => 'NOT EXISTS' ) ) as $uid ) {
            $user_ids[] = (int) $uid;
        }
    }
    foreach ( get_users( array( 'role' => 'ws_owner', 'fields' => 'ID', 'meta_key' => 'ws_business_id', 'meta_value' => $biz_id ) ) as $uid ) {
        $user_ids[] = (int) $uid;
    }
    // Usuarios asignados a la ubicación con el permiso.
    global $wpdb;
    $assigned = $wpdb->get_col( $wpdb->prepare(
        'SELECT user_id FROM ' . ws_table_name( 'user_locations' ) . ' WHERE location_id=%d',
        (int) $location_id
    ) );
    foreach ( array_unique( array_map( 'intval', $assigned ) ) as $uid ) {
        if ( $uid && WS_Capabilities::can( $cap, $uid ) ) {
            $user_ids[] = $uid;
        }
    }
    foreach ( array_unique( $user_ids ) as $uid ) {
        $user_biz = ws_user_business( $uid );
        $role     = ws_user_role( $uid );
        $link     = $role ? ws_panel_url( $role, $link_page, $user_biz ) : '';
        $ref      = $ref_key ? $ref_key : $type . '_' . $location_id . '_' . time();
        if ( $ref_key ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . ws_notifications_table( $user_biz ) . " WHERE user_id=%d AND ref_key=%s AND is_read=0 LIMIT 1",
                $uid, $ref
            ) );
            if ( $exists ) {
                continue;
            }
        }
        ws_notification_add( $uid, $type, $title, $message, $link, $ref, $user_biz );
    }
}

/**
 * Limpia notificaciones "fantasma" del usuario: eventos de pedido cuyo pedido
 * ya no está pendiente (aceptado, cancelado, rechazado, completado) o que ya
 * no existe, se marcan como leídas automáticamente. También elimina
 * notificaciones leídas con más de 30 días.
 */
function ws_notifications_cleanup( $user_id = 0 ) {
    global $wpdb;
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return;
    }
    $table = ws_notifications_table();
    $orders_table = $wpdb->prefix . 'ws_orders';

    // 1) Eventos de pedido sin leer: marcar leídos los que ya son irrelevantes.
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, type, ref_key, title FROM {$table}
         WHERE user_id=%d AND is_read=0 AND type IN ('new_order','order_accepted')",
        $user_id
    ) );

    $numbers = array();
    foreach ( $rows as $r ) {
        if ( preg_match( '/WS-[A-Z0-9]+/', $r->title, $m ) ) {
            $numbers[ $m[0] ] = true;
        }
    }

    $existing = array(); // number => status
    if ( $numbers ) {
        $ph = implode( ',', array_fill( 0, count( $numbers ), '%s' ) );
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT number, status FROM {$orders_table} WHERE number IN ({$ph})",
            array_keys( $numbers )
        ) );
        foreach ( $orders as $o ) {
            $existing[ $o->number ] = $o->status;
        }
    }

    $to_read = array();
    foreach ( $rows as $r ) {
        if ( preg_match( '/WS-[A-Z0-9]+/', $r->title, $m ) ) {
            $number  = $m[0];
            $status  = isset( $existing[ $number ] ) ? $existing[ $number ] : '';
            $missing = ! isset( $existing[ $number ] );
            if ( 'new_order' === $r->type ) {
                // Fantasma: el pedido no existe o ya no está pendiente.
                if ( $missing || 'pending' !== $status ) {
                    $to_read[] = (int) $r->id;
                }
            } elseif ( 'order_accepted' === $r->type && $missing ) {
                $to_read[] = (int) $r->id;
            }
        }
    }
    if ( $to_read ) {
        $ph = implode( ',', array_fill( 0, count( $to_read ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET is_read=1 WHERE id IN ({$ph})",
            $to_read
        ) );
    }

    // 2) Higiene: borra notificaciones leídas con más de 30 días.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE user_id=%d AND is_read=1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        $user_id
    ) );
}

/**
 * Notificaciones simples (mantenido para compatibilidad).
 *
 * Por ahora: log interno + notificaciones por usuario + hooks para
 * integraciones externas (Pusher, push, correo). Real-time queda vía hook.
 */
function ws_notify( $event, $entity_id, $extra = array() ) {
    // Log de notificaciones recientes (mantenido para compatibilidad).
    $log = get_option( 'ws_notifications', array() );
    $log[] = array(
        'event'     => $event,
        'entity_id' => $entity_id,
        'extra'     => $extra,
        'time'      => time(),
    );
    $log = array_slice( $log, -50 );
    update_option( 'ws_notifications', $log );

    // Hooks para que integraciones externas (Pusher, push, correo) se enganchen.
    do_action( 'ws_notify', $event, $entity_id, $extra );

    // Nuevo pedido: notifica a dueño/admin/encargados y envía correo.
    if ( 'order_new' === $event ) {
        $order = WS_Orders::get( $entity_id );
        if ( $order ) {
            $location = WS_CRUD::get_location( $order->location_id );
            if ( $location ) {
                $subject = sprintf( __( 'Nuevo pedido %s en %s', 'workshop' ), $order->number, $location->name );
                wp_mail( get_option( 'admin_email' ), $subject, sprintf( __( 'Pedido %s por %s. Total: %s', 'workshop' ), $order->number, $order->customer_name, ws_money( $order->total, $order->currency ) ) );
                ws_notify_location_users(
                    $order->location_id,
                    'new_order',
                    sprintf( __( 'Nuevo pedido %s', 'workshop' ), $order->number ),
                    sprintf( __( '%s · %s · %s', 'workshop' ), $location->name, $order->customer_name, ws_money( $order->total, $order->currency ) ),
                    'orders',
                    'orders_accept',
                    'new_order_' . (int) $order->id
                );
            }
        }
    }
    // Pedido aceptado: informa a los encargados de la ubicación.
    if ( 'order_accepted' === $event ) {
        $order = WS_Orders::get( $entity_id );
        if ( $order ) {
            ws_notify_location_users(
                $order->location_id,
                'order_accepted',
                sprintf( __( 'Pedido %s aceptado', 'workshop' ), $order->number ),
                __( 'Se descontó el stock automáticamente.', 'workshop' ),
                'orders',
                'orders_view',
                'order_accepted_' . (int) $order->id
            );
        }
    }
}
