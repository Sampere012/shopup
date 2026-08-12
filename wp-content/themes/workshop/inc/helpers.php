<?php
/**
 * Helpers generales.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nombre del rol de negocio del usuario actual (o '' si es admin/editor).
 */
function ws_user_role( $user_id = 0 ) {
    if ( $user_id ) {
        $user = get_user_by( 'id', $user_id );
    } else {
        $user = wp_get_current_user();
    }
    if ( ! $user || ! $user->exists() ) {
        return '';
    }
    $map = array(
        'ws_owner'      => 'owner',
        'ws_storekeeper' => 'storekeeper',
        'ws_seller'     => 'seller',
    );
    foreach ( $map as $role => $slug ) {
        if ( in_array( $role, (array) $user->roles, true ) ) {
            return $slug;
        }
    }
    return '';
}

function ws_role_label( $role = null ) {
    if ( null === $role ) {
        $role = ws_user_role();
    }
    $map = array(
        'owner'       => __( 'Dueño del negocio', 'workshop' ),
        'storekeeper' => __( 'Almacenero', 'workshop' ),
        'seller'      => __( 'Vendedor/PV', 'workshop' ),
        'admin'       => __( 'Admin del sitio', 'workshop' ),
    );
    return isset( $map[ $role ] ) ? $map[ $role ] : __( 'Visitante', 'workshop' );
}

function ws_can( $cap ) {
    return WS_Capabilities::can( $cap );
}

/**
 * URL del panel principal según rol.
 */
function ws_dashboard_url() {
    $role = ws_user_role();
    if ( ! $role ) {
        if ( current_user_can( 'manage_options' ) ) {
            // Esquema de la petición (https si toca): la BD guarda http pero
            // el sitio se sirve por https, y un admin logueado que pase por
            // /login/ o wp-admin no debe rebotar http↔https (bucle).
            return ws_login_scheme_url( admin_url() );
        }
        return ws_business_home();
    }
    return ws_panel_url( $role );
}

/**
 * URL del registro público de negocios.
 */
function ws_register_url() {
    return home_url( '/registro/' );
}

function ws_is_panel() {
    $page = ws_current_page();
    return in_array( $page, array( 'dashboard', 'products', 'locations', 'suppliers', 'stock', 'movements', 'orders', 'shifts', 'workers', 'permissions', 'reports', 'settings', 'account', 'appearance', 'anuncios' ), true );
}

/**
 * Página actual del router (query var ws_page).
 */
function ws_current_page() {
    return (string) get_query_var( 'ws_page', 'dashboard' );
}

/**
 * Impresión segura de variables en templates.
 */
function ws_e( $value ) {
    echo esc_html( $value );
}

/**
 * Formatea un número como moneda.
 */
function ws_money( $amount, $currency = null ) {
    if ( null === $currency ) {
        $currency = ws_currency_symbol();
    }
    $amount = (float) $amount;
    return number_format_i18n( $amount, 2 ) . ' ' . $currency;
}

/**
 * Abrevia números grandes para el mercado (ej: 120000 -> "120 mil", 2500000 -> "2,5 M").
 * Números menores a 1000 se muestran tal cual.
 */
function ws_compact_number( $number ) {
    $number   = (float) $number;
    $abs      = abs( $number );
    $suffixes = array( ' mil', ' M', ' mil M' );
    if ( $abs < 1000 ) {
        return number_format_i18n( $number, 0 );
    }
    $v   = $number;
    $abs = $abs;
    $i   = 0;
    while ( $abs >= 1000 && $i < count( $suffixes ) ) {
        $v   = $v / 1000;
        $abs = $abs / 1000;
        $i++;
    }
    $v = round( $v, 1 );
    // El redondeo puede subir de rango (999.999 -> 1 M); ajusta sufijo.
    if ( $v >= 1000 && $i < count( $suffixes ) ) {
        $v = $v / 1000;
        $i++;
    }
    return rtrim( rtrim( number_format_i18n( $v, 1 ), '0' ), ',.' ) . $suffixes[ $i - 1 ];
}

function ws_currency_symbol( $location_id = 0 ) {
    if ( $location_id ) {
        $loc = WS_CRUD::get_location( $location_id );
        if ( $loc && ! empty( $loc->currency ) ) {
            return $loc->currency;
        }
    }
    return ws_biz_option( 'ws_currency', '€' );
}

/**
 * Monedas configuradas en Ajustes (separadas por coma), p. ej. "USD, CUP".
 * Devuelve un array único; si no hay configuración, solo la moneda por defecto.
 */
function ws_currencies() {
    $raw  = (string) ws_biz_option( 'ws_currencies', '' );
    $list = array();
    foreach ( explode( ',', $raw ) as $c ) {
        $c = trim( $c );
        if ( '' !== $c ) {
            $list[] = $c;
        }
    }
    $list = array_values( array_unique( $list ) );
    if ( empty( $list ) ) {
        $list[] = ws_currency_symbol();
    }
    return $list;
}

/**
 * Tasas de cambio respecto a la moneda por defecto: [ 'USD' => 670 ] significa
 * 1 USD = 670 unidades de la moneda por defecto (p. ej. CUP).
 */
function ws_exchange_rates() {
    $rates = ws_biz_option( 'ws_rates', array() );
    return is_array( $rates ) ? $rates : array();
}

/**
 * Convierte un monto entre dos monedas usando la moneda por defecto como pivote.
 */
function ws_convert( $amount, $from, $to ) {
    $amount = (float) $amount;
    $from   = (string) $from;
    $to     = (string) $to;
    if ( ! $from || ! $to || $from === $to ) {
        return $amount;
    }
    $rates = ws_exchange_rates();
    $base  = ws_currency_symbol();
    // Paso 1: monto a la moneda por defecto.
    if ( $from === $base ) {
        $in_base = $amount;
    } else {
        $rate    = isset( $rates[ $from ] ) ? (float) $rates[ $from ] : 0;
        $in_base = $rate > 0 ? $amount * $rate : $amount;
    }
    // Paso 2: de la moneda por defecto a la destino.
    if ( $to === $base ) {
        return $in_base;
    }
    $rate_to = isset( $rates[ $to ] ) ? (float) $rates[ $to ] : 0;
    return $rate_to > 0 ? $in_base / $rate_to : $in_base;
}

/**
 * Moneda con la que se registra la venta en una ubicación (la de la ubicación
 * o la moneda por defecto si la ubicación no tiene una definida).
 */
function ws_location_currency( $location_id = 0 ) {
    if ( $location_id ) {
        $loc = WS_CRUD::get_location( $location_id );
        if ( $loc && ! empty( $loc->currency ) ) {
            return $loc->currency;
        }
    }
    return ws_currency_symbol();
}

function ws_payment_methods( $location_id = 0 ) {
    if ( $location_id ) {
        $loc = WS_CRUD::get_location( $location_id );
        if ( $loc && ! empty( $loc->payment_methods ) ) {
            $decoded = is_string( $loc->payment_methods ) ? json_decode( $loc->payment_methods, true ) : $loc->payment_methods;
            return is_array( $decoded ) ? $decoded : array();
        }
    }
    $default = ws_biz_option( 'ws_payment_methods', array() );
    return is_array( $default ) ? $default : array();
}

/**
 * Ubicaciones a las que tiene acceso el usuario actual.
 * El dueño y admin ven todas; almacenero y vendedor solo sus asignaciones.
 */
function ws_user_locations( $user_id = 0 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    $role = ws_user_role( $user_id );
    if ( 'owner' === $role || '' === $role ) {
        return WS_CRUD::get_locations();
    }
    return WS_CRUD::get_user_locations( $user_id );
}

/**
 * IDs de las ubicaciones a las que tiene acceso el usuario actual.
 */
function ws_user_location_ids( $user_id = 0 ) {
    return array_map( fn( $l ) => (int) $l->id, ws_user_locations( $user_id ) );
}

function ws_log_audit( $action, $entity_type, $entity_id, $detail = '' ) {
    global $wpdb;
    $wpdb->insert(
        ws_table_name( 'audit' ),
        array(
            'user_id'     => get_current_user_id(),
            'action'      => $action,
            'entity_type' => $entity_type,
            'entity_id'   => (int) $entity_id,
            'detail'      => maybe_serialize( $detail ),
            'ip'          => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'created_at'  => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
    );
}

/**
 * Números de WhatsApp que atienden pedidos (pueden ser varios separados por
 * coma en Ajustes o por ubicación). Primero los de la ubicación, luego global.
 */
function ws_whatsapp_numbers( $location_id = 0 ) {
    $raw = '';
    if ( $location_id ) {
        $loc = WS_CRUD::get_location( $location_id );
        if ( $loc && ! empty( $loc->whatsapp ) ) {
            $raw = $loc->whatsapp;
        }
    }
    if ( '' === $raw ) {
        $raw = (string) ws_biz_option( 'ws_whatsapp', '' );
    }
    $numbers = array();
    foreach ( explode( ',', $raw ) as $n ) {
        $n = preg_replace( '/[^0-9]/', '', trim( $n ) );
        if ( '' !== $n ) {
            $numbers[] = $n;
        }
    }
    return array_values( array_unique( $numbers ) );
}

/**
 * Primer número de WhatsApp para pedidos (compatibilidad).
 */
function ws_whatsapp_number( $location_id = 0 ) {
    $numbers = ws_whatsapp_numbers( $location_id );
    return $numbers ? $numbers[0] : '';
}

/**
 * Número de WhatsApp del administrador de WordPress, el que recibe las
 * solicitudes de plan (upgrade) que envían los negocios.
 *
 * Se configura en wp-admin → Usuarios → Tu perfil (campo «Número de
 * WhatsApp»), guardado en user meta `ws_whatsapp`. Orden de prioridad:
 *  1. WhatsApp del admin principal (opción admin_email).
 *  2. WhatsApp de cualquier administrador (manage_options) que lo tenga.
 *  3. Opción global Ajustes → WhatsApp (ws_whatsapp), retrocompatibilidad.
 */
function ws_admin_whatsapp_number() {
    $number = '';

    $admin_email = get_option( 'admin_email' );
    $admin       = $admin_email ? get_user_by( 'email', $admin_email ) : null;
    if ( $admin ) {
        $number = (string) get_user_meta( $admin->ID, 'ws_whatsapp', true );
    }

    if ( '' === $number ) {
        $admins = get_users( array(
            'capability' => 'manage_options',
            'number'     => 50,
            'fields'     => 'ID',
        ) );
        foreach ( (array) $admins as $uid ) {
            $n = (string) get_user_meta( (int) $uid, 'ws_whatsapp', true );
            if ( '' !== $n ) {
                $number = $n;
                break;
            }
        }
    }

    if ( '' === $number ) {
        $number = (string) ws_biz_option( 'ws_whatsapp', '' );
    }

    return preg_replace( '/[^0-9]/', '', $number );
}

/**
 * Total de un pedido en transferencia: aplica el % de transferencia
 * de cada producto (precio * (1 + pct/100)) + domicilio.
 */
function ws_order_transfer_total( $order ) {
    $transfer_subtotal = 0.0;
    foreach ( WS_Orders::get_items( $order->id ) as $it ) {
        $product = WS_CRUD::get_product( $it->product_id );
        $pct     = $product ? (float) $product->transfer_pct : 0.0;
        $transfer_subtotal += (float) $it->price * ( 1 + $pct / 100 ) * (float) $it->qty;
    }
    return $transfer_subtotal + (float) $order->delivery_cost;
}

/**
 * URL wa.me con el detalle de un pedido listo para enviar.
 * $number_override permite elegir entre varios números de WhatsApp (dropdown).
 */
function ws_whatsapp_order_url( $order, $location, $number_override = '' ) {
    $number = $number_override ? preg_replace( '/[^0-9]/', '', $number_override ) : ws_whatsapp_number( $location->id );
    if ( ! $number ) {
        return '';
    }
    $transfer_total = ws_order_transfer_total( $order );
    $items          = WS_Orders::get_items( $order->id );

    $lines   = array();
    $lines[] = sprintf( '🛒 *NUEVO PEDIDO %s*', $order->number );
    $lines[] = '📍 ' . $location->name;
    $lines[] = '';
    foreach ( $items as $it ) {
        $lines[] = sprintf( '• %s x%s — %s', $it->product_name, $it->qty, ws_money( $it->price * $it->qty, $order->currency ) );
    }
    $lines[] = '';
    $lines[] = sprintf( 'Subtotal: %s', ws_money( $order->subtotal, $order->currency ) );
    if ( (float) $order->delivery_cost > 0 ) {
        $lines[] = sprintf( 'Domicilio: %s', ws_money( $order->delivery_cost, $order->currency ) );
    }
    $lines[] = sprintf( '💵 *Total en efectivo: %s*', ws_money( $order->total, $order->currency ) );
    if ( abs( $transfer_total - (float) $order->total ) > 0.001 ) {
        $lines[] = sprintf( '💳 *Total en transferencia: %s*', ws_money( $transfer_total, $order->currency ) );
    }
    $lines[] = '';
    $lines[] = '👤 ' . $order->customer_name;
    $lines[] = '📞 ' . $order->customer_phone;
    if ( $order->customer_address ) {
        $lines[] = '🏠 ' . $order->customer_address;
    }
    // Número que atiende los pedidos: el elegido en el checkout (si hay
    // varios) o el de la ubicación/global. El wa.me va al mismo número.
    if ( $number_override ) {
        $raw = $number_override;
    } else {
        $raw = isset( $location->whatsapp ) && $location->whatsapp ? $location->whatsapp : '';
    }
    $lines[] = '';
    $lines[] = '📲 Pedidos al: ' . ( $raw ? $raw : '+' . $number );
    $msg = implode( "\n", $lines );
    return 'https://wa.me/' . $number . '?text=' . rawurlencode( $msg );
}

/**
 * URL wa.me hacia el WhatsApp del administrador con el detalle de una
 * solicitud de plan (upgrade). Devuelve '' si no hay número configurado.
 * El número usado es el configurado por el admin en wp-admin → Usuarios →
 * Tu perfil (campo WhatsApp); si no, el global de Ajustes (ws_whatsapp).
 */
function ws_plan_request_wa_url( $plan, $biz = null ) {
    $number = ws_admin_whatsapp_number();
    if ( ! $number || ! $plan ) {
        return '';
    }
    if ( ! $biz ) {
        $biz = ws_current_business();
    }
    $name = $biz && ! empty( $biz->name ) ? $biz->name : ( $biz ? '#' . $biz->id : '' );
    $msg  = sprintf(
        __( "Hola, soy %s. Acabo de solicitar el plan %s (%s). Quedo a la espera de su confirmación.", 'workshop' ),
        $name,
        $plan->name,
        WS_Plans::format_price( $plan )
    );
    return 'https://wa.me/' . $number . '?text=' . rawurlencode( $msg );
}

/**
 * HTML de un select de ubicaciones con el valor seleccionado.
 */
function ws_locations_select( $name, $selected = 0, $attr = '' ) {
    $options = '';
    foreach ( ws_user_locations() as $loc ) {
        $sel   = ( (int) $selected === (int) $loc->id ) ? ' selected' : '';
        $label = $loc->name . ( 'pv' === $loc->type ? ' (PV)' : ' (Almacén)' );
        $options .= sprintf( '<option value="%d"%s>%s</option>', (int) $loc->id, $sel, esc_html( $label ) );
    }
    printf( '<select name="%s" %s><option value="">%s</option>%s</select>',
        esc_attr( $name ), $attr, esc_html__( '— Seleccionar —', 'workshop' ), $options );
}
