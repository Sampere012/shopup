<?php
/**
 * Endpoints AJAX del tema.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Helper: nonce + permiso.
 */
function ws_guard( $cap, $fallback = '' ) {
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
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
    $search = sanitize_text_field( $_POST['search'] ?? '' );
    ws_send_list( 'products', function ( $args ) use ( $search ) {
        $rows = WS_CRUD::get_products( array_merge( array( 'search' => $search ), $args ) );
        $out = array();
        foreach ( $rows as $p ) {
            $out[] = array(
                'id'           => (int) $p->id,
                'name'         => $p->name,
                'barcode'      => $p->barcode,
                'description'  => $p->description,
                'image'        => $p->image,
                'cost_price'   => (float) $p->cost_price,
                'sale_price'   => (float) $p->sale_price,
                'transfer_pct' => (float) $p->transfer_pct,
                'currency'     => $p->currency,
                'show_equiv'   => (int) ( $p->show_equiv ?? 1 ),
                'supplier_id'  => (int) $p->supplier_id,
                'supplier_name'=> $p->supplier_name,
                'min_stock'    => (float) $p->min_stock,
                'fraction_parent' => (int) ( $p->fraction_parent ?? 0 ),
                'fraction_qty'    => (float) ( $p->fraction_qty ?? 0 ),
                'active'       => (int) $p->active,
            );
        }
        return $out;
    }, function () use ( $search ) {
        return WS_CRUD::count_products( array( 'search' => $search ) );
    }, array( 'search' => $search ) );
}

add_action( 'wp_ajax_ws_locations_list', 'ws_ajax_locations_list' );
function ws_ajax_locations_list() {
    ws_guard( 'locations_view' );
    $search = sanitize_text_field( $_POST['search'] ?? '' );
    ws_send_list( 'locations', function ( $args ) use ( $search ) {
        $rows = WS_CRUD::get_locations( '', array_merge( array( 'search' => $search ), $args ) );
        $out = array();
        foreach ( $rows as $l ) {
            $methods = is_string( $l->payment_methods ) ? json_decode( $l->payment_methods, true ) : $l->payment_methods;
            $out[] = array(
                'id'              => (int) $l->id,
                'type'            => $l->type,
                'name'            => $l->name,
                'slug'            => $l->slug,
                'address'         => $l->address,
                'photo'           => $l->photo,
                'currency'        => $l->currency,
                'payment_methods' => is_array( $methods ) ? $methods : array(),
                'whatsapp'        => $l->whatsapp,
                'delivery_cost'   => (float) $l->delivery_cost,
                'active'          => (int) $l->active,
            );
        }
        return $out;
    }, function () use ( $search ) {
        return WS_CRUD::count_locations( '', array( 'search' => $search ) );
    }, array( 'search' => $search ) );
}

add_action( 'wp_ajax_ws_my_locations', 'ws_ajax_my_locations' );
function ws_ajax_my_locations() {
    ws_guard( 'locations_view' );
    $out = array();
    foreach ( ws_user_locations() as $l ) {
        $out[] = array(
            'id'    => (int) $l->id,
            'name'  => $l->name,
            'slug'  => $l->slug,
            'type'  => $l->type,
            'active'=> (int) $l->active,
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
    wp_send_json_success( array( 'id' => $result ) );
}

add_action( 'wp_ajax_ws_delete_product', 'ws_ajax_delete_product' );
function ws_ajax_delete_product() {
    ws_guard( 'products_delete' );
    $id = (int) ( $_POST['id'] ?? 0 );
    WS_CRUD::delete_product( $id );
    ws_log_audit( 'product_delete', 'product', $id );
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

/* ---------------- Stock ---------------- */

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
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $qty         = (float) ( $_POST['qty'] ?? 0 );
    $ref         = sanitize_text_field( $_POST['reference'] ?? '' );
    $note        = sanitize_text_field( $_POST['note'] ?? '' );

    if ( 'entrada' === $type ) {
        $result = WS_Stock::increase( $product_id, $location_id, $qty, $type, $ref, $note );
    } else {
        $result = WS_Stock::decrease( $product_id, $location_id, $qty, $type, $ref, $note );
    }
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( 'stock_' . $type, 'movement', $product_id, array( 'location' => $location_id, 'qty' => $qty ) );
    wp_send_json_success( array( 'qty' => WS_Stock::qty( $product_id, $location_id ) ) );
}

add_action( 'wp_ajax_ws_stock_transfer', 'ws_ajax_stock_transfer' );
function ws_ajax_stock_transfer() {
    ws_guard( 'stock_transfer' );
    $product_id = (int) ( $_POST['product_id'] ?? 0 );
    $from       = (int) ( $_POST['from_location'] ?? 0 );
    $to         = (int) ( $_POST['to_location'] ?? 0 );
    $qty        = (float) ( $_POST['qty'] ?? 0 );
    $note       = sanitize_text_field( $_POST['note'] ?? '' );
    $result     = WS_Stock::transfer( $product_id, $from, $to, $qty, '', $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( 'stock_transfer', 'movement', $product_id, array( 'from' => $from, 'to' => $to, 'qty' => $qty ) );
    wp_send_json_success();
}

add_action( 'wp_ajax_ws_stock_batch_move', 'ws_ajax_stock_batch_move' );
function ws_ajax_stock_batch_move() {
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

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $items       = isset( $_POST['items'] ) ? (array) json_decode( wp_unslash( $_POST['items'] ), true ) : array();
    $ref         = sanitize_text_field( $_POST['reference'] ?? '' );
    $note        = sanitize_text_field( $_POST['note'] ?? '' );

    $result = WS_Stock::batch_move( $type, $location_id, $items, $ref, $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( 'stock_' . $type, 'movement', 0, array( 'location' => $location_id, 'items' => $result ) );
    wp_send_json_success( array( 'count' => $result ) );
}

add_action( 'wp_ajax_ws_stock_batch_transfer', 'ws_ajax_stock_batch_transfer' );
function ws_ajax_stock_batch_transfer() {
    ws_guard( 'stock_transfer' );
    $from = (int) ( $_POST['from_location'] ?? 0 );
    $to   = (int) ( $_POST['to_location'] ?? 0 );
    $items = isset( $_POST['items'] ) ? (array) json_decode( wp_unslash( $_POST['items'] ), true ) : array();
    $note  = sanitize_text_field( $_POST['note'] ?? '' );

    $result = WS_Stock::batch_transfer( $from, $to, $items, '', $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }
    ws_log_audit( 'stock_transfer', 'movement', 0, array( 'from' => $from, 'to' => $to, 'items' => $result ) );
    wp_send_json_success( array( 'count' => $result ) );
}

/* ---------------- Stock list ---------------- */

add_action( 'wp_ajax_ws_stock_list', 'ws_ajax_stock_list' );
function ws_ajax_stock_list() {
    ws_guard( 'stock_view' );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $search      = sanitize_text_field( $_POST['search'] ?? '' );
    $low_only    = ! empty( $_POST['low_only'] );
    $allowed     = ws_user_locations();
    $allowed_ids = array_map( fn( $l ) => (int) $l->id, $allowed );
    $loc_ids = ( $location_id && in_array( $location_id, $allowed_ids, true ) )
        ? array( $location_id )
        : $allowed_ids;

    ws_send_list( 'rows', function ( $args ) use ( $loc_ids, $search, $low_only ) {
        $rows = WS_Stock::stock_rows( array_merge( array(
            'location_ids' => $loc_ids,
            'search'       => $search,
            'low_stock'    => $low_only,
        ), $args ) );
        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array(
                'product_id'    => (int) $r->product_id,
                'location_id'   => (int) $r->location_id,
                'location_name' => $r->location_name ?? '',
                'location_type' => $r->location_type ?? '',
                'name'          => $r->name,
                'barcode'       => $r->barcode,
                'image'         => $r->image,
                'qty'           => (float) $r->qty,
                'min_stock'     => (float) $r->min_stock,
                'sale_price'    => (float) $r->sale_price,
                'currency'      => $r->currency,
            );
        }
        return $out;
    }, function () use ( $loc_ids, $search, $low_only ) {
        return WS_Stock::count_stock_rows( array(
            'location_ids' => $loc_ids,
            'search'       => $search,
            'low_stock'    => $low_only,
        ) );
    }, array() );
}

/* ---------------- Movimientos ---------------- */

add_action( 'wp_ajax_ws_movements_list', 'ws_ajax_movements_list' );
function ws_ajax_movements_list() {
    ws_guard( 'movements_view' );
    $type      = sanitize_key( $_POST['type'] ?? '' );
    $location  = (int) ( $_POST['location_id'] ?? 0 );
    $search    = sanitize_text_field( $_POST['search'] ?? '' );
    $loc_ids   = array_map( fn( $l ) => (int) $l->id, ws_user_locations() );
    $loc_ids   = ( $location && in_array( $location, $loc_ids, true ) ) ? array( $location ) : $loc_ids;

    ws_send_list( 'movements', function ( $args ) use ( $type, $loc_ids, $search ) {
        $rows = WS_Stock::movements( array_merge( array(
            'type'         => $type,
            'location_ids' => $loc_ids,
            'search'       => $search,
        ), $args ) );
        $out = array();
        foreach ( $rows as $m ) {
            $out[] = array(
                'id'              => (int) $m->id,
                'type'            => $m->type,
                'product_name'    => $m->product_name,
                'location_name'   => $m->location_name ?? '',
                'dest_location_id'=> (int) $m->dest_location_id,
                'dest_name'       => $m->dest_name ?? '',
                'qty'             => (float) $m->qty,
                'reference'       => $m->reference,
                'user_name'       => $m->user_name,
                'date'            => mysql2date( 'd/m/Y H:i', $m->created_at ),
            );
        }
        return $out;
    }, function () use ( $type, $loc_ids, $search ) {
        return WS_Stock::count_movements( array(
            'type'         => $type,
            'location_ids' => $loc_ids,
            'search'       => $search,
        ) );
    }, array() );
}

/* ---------------- Pedidos ---------------- */

add_action( 'wp_ajax_ws_order_accept', 'ws_ajax_order_accept' );
function ws_ajax_order_accept() {
    ws_guard( 'orders_accept' );
    $id = (int) ( $_POST['id'] ?? 0 );
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
    $status  = sanitize_key( $_POST['status'] ?? '' );
    $loc_ids = array_map( fn( $l ) => (int) $l->id, ws_user_locations() );

    ws_send_list( 'orders', function ( $args ) use ( $status, $loc_ids ) {
        $rows = WS_Orders::all( array_merge( array(
            'location_ids' => $loc_ids,
            'status'       => $status,
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
                'total'           => (float) $o->total,
                'currency'        => $o->currency,
                'status'          => $o->status,
                'date'            => mysql2date( 'd/m/Y H:i', $o->created_at ),
            );
        }
        return $out;
    }, function () use ( $status, $loc_ids ) {
        return WS_Orders::count_all( array( 'location_ids' => $loc_ids, 'status' => $status ) );
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
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión expirada.', 'workshop' ) ) );
    }
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $items       = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? array_map( 'absint', $_POST['items'] ) : array();
    $customer    = array(
        'name'    => sanitize_text_field( $_POST['customer_name'] ?? '' ),
        'phone'   => sanitize_text_field( $_POST['customer_phone'] ?? '' ),
        'address' => sanitize_text_field( $_POST['customer_address'] ?? '' ),
    );
    if ( empty( $customer['name'] ) || empty( $customer['phone'] ) ) {
        wp_send_json_error( array( 'msg' => __( 'Nombre y teléfono son obligatorios.', 'workshop' ) ) );
    }
    $items = array_filter( $items, fn( $qty ) => $qty > 0 );
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
    $rows        = WS_Stock::stock_rows( array( 'location_id' => $location_id, 'search' => $search ) );
    $products    = array();
    foreach ( $rows as $r ) {
        $products[] = array(
            'id'           => (int) $r->product_id,
            'name'         => $r->name,
            'barcode'      => $r->barcode,
            'image'        => $r->image,
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
    $email = sanitize_email( $_POST['email'] ?? '' );
    if ( '' === $name || ! is_email( $email ) ) {
        wp_send_json_error( array( 'msg' => __( 'Nombre y email válidos son obligatorios.', 'workshop' ) ) );
    }
    $existing = email_exists( $email );
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
        'user_email'   => $email,
    );
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

/* ---------------- Permisos y configuración ---------------- */

add_action( 'wp_ajax_ws_save_permissions', 'ws_ajax_save_permissions' );
function ws_ajax_save_permissions() {
    ws_guard( 'permissions_manage' );
    $matrix = isset( $_POST['matrix'] ) ? (array) json_decode( wp_unslash( $_POST['matrix'] ), true ) : array();
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
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
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
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
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
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
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
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
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
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
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
    if ( ! check_ajax_referer( 'ws_nonce', 'ws_nonce', false ) ) {
        wp_send_json_error( array( 'msg' => __( 'Sesión inválida.', 'workshop' ) ) );
    }

    $product_id = (int) ( $_POST['product_id'] ?? 0 );
    $has_filters = '' !== sanitize_text_field( $_POST['search'] ?? '' )
        || '' !== sanitize_key( $_POST['status'] ?? '' )
        || (int) ( $_POST['rating'] ?? 0 ) > 0;

    // Modo público (tienda): reseñas aprobadas de un producto + rating.
    if ( $product_id && ! $has_filters ) {
        $args = array(
            'product_id' => $product_id,
            'approved'   => 1,
            'orderby'    => sanitize_key( $_POST['sort'] ?? 'created_at' ),
            'order'      => ( ( $_POST['dir'] ?? 'desc' ) === 'asc' ) ? 'ASC' : 'DESC',
            'limit'      => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 10,
            'offset'     => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
        );
        $reviews      = WS_Reviews::get_reviews( $args );
        $rating_stats = WS_Reviews::get_product_rating( $product_id );

        wp_send_json_success( array(
            'data'  => array(
                'data'  => $reviews,
                'stats' => $rating_stats,
            ),
        ) );
    }

    // Modo panel (módulo Valoraciones): listado con filtros.
    ws_guard( 'reviews_view' );

    $args = array(
        'search' => sanitize_text_field( $_POST['search'] ?? '' ),
        'status' => sanitize_key( $_POST['status'] ?? '' ),
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
            'product_name'      => $r->product_name,
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
        'customer_id' => (int) ( $_POST['customer_id'] ?? 0 ),
        'customer_name' => sanitize_text_field( $_POST['customer_name'] ?? '' ),
        'rating' => (int) ( $_POST['rating'] ?? 5 ),
        'title' => sanitize_text_field( $_POST['title'] ?? '' ),
        'comment' => sanitize_textarea_field( $_POST['comment'] ?? '' ),
    );

    if ( ! $data['product_id'] || ! $data['customer_name'] ) {
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

    $args = array(
        'location_id' => (int) ( $_POST['location_id'] ?? 0 ),
        'seller_id' => (int) ( $_POST['seller_id'] ?? 0 ),
        'search' => sanitize_text_field( $_POST['search'] ?? '' ),
        'status' => sanitize_key( $_POST['status'] ?? '' ),
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

    $out = array();
    foreach ( WS_POS::get_sale_items( $sale_id ) as $it ) {
        $out[] = array(
            'id'           => (int) $it->id,
            'sale_id'      => (int) $it->sale_id,
            'product_id'   => (int) $it->product_id,
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
        'status'          => sanitize_text_field( $_POST['status'] ?? 'completed' ),
        'register_id'     => (int) ( $_POST['register_id'] ?? 0 ),
        'items'           => $_POST['items'] ?? array(),
    );

    if ( ! $data['location_id'] || ! $data['seller_id'] ) {
        wp_send_json_error( array( 'msg' => __( 'Datos incompletos.', 'workshop' ) ) );
    }

    // Caja POS: la venta requiere una caja abierta en la ubicación.
    $cash = WS_POS::get_open_cash( $data['location_id'] );
    if ( ! $cash ) {
        wp_send_json_error( array( 'msg' => __( 'Debes abrir la caja antes de vender.', 'workshop' ) ) );
    }
    $data['register_id'] = (int) $cash->id;

    // Transferencia (sola o mixta): el nº de transferencia es obligatorio.
    if ( in_array( $data['payment_method'], array( 'transfer', 'both' ), true ) && '' === $data['transfer_number'] ) {
        wp_send_json_error( array( 'msg' => __( 'El número de transferencia es obligatorio.', 'workshop' ) ) );
    }

    // Descuento de stock atómico: cada ítem de la venta sale del inventario
    // (propaga el fraccionamiento: vender 1 jaba descuenta el saco). La venta
    // se revierte si algún producto no tiene stock suficiente.
    global $wpdb;
    $wpdb->query( 'START TRANSACTION' );
    foreach ( (array) $data['items'] as $it ) {
        $pid = (int) ( $it['product_id'] ?? 0 );
        $qty = (float) ( $it['qty'] ?? 0 );
        if ( ! $pid || $qty <= 0 ) {
            continue;
        }
        $stock_res = WS_Stock::decrease_in_tx(
            $pid, $data['location_id'], $qty, 'salida',
            'Venta POS', 'Venta #pendiente', get_current_user_id()
        );
        if ( is_wp_error( $stock_res ) ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'msg' => $stock_res->get_error_message() ) );
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

    ws_log_audit( 'pos_sale_create', 'pos_sale', $sale_id, array( 'location' => $data['location_id'], 'total' => $data['total'] ) );
    wp_send_json_success( array( 'data' => array( 'sale_id' => $sale_id ) ) );
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

    // Filtrar productos que tienen stock en la ubicación seleccionada
    $stock_rows = WS_Stock::stock_rows( array(
        'location_ids' => $loc_ids,
        'search'       => $search,
        'limit'        => $limit,
        'offset'       => $offset,
    ) );

    $out = array();
    foreach ( $stock_rows as $r ) {
        $out[] = array(
            'id'          => (int) $r->product_id,
            'name'        => $r->name,
            'barcode'     => $r->barcode,
            'image'       => $r->image,
            'description' => $r->description ?? '',
            'sale_price'  => (float) $r->sale_price,
            'transfer_pct'=> (float) $r->transfer_pct,
            'currency'    => $r->currency,
            'show_equiv'  => (int) ( $r->show_equiv ?? 1 ),
            'stock'       => (float) $r->qty,
        );
    }

    $total = WS_Stock::count_stock_rows( array(
        'location_ids' => $loc_ids,
        'search'       => $search,
    ) );

    wp_send_json_success( array( 'data' => $out, 'total' => $total ) );
}

add_action( 'wp_ajax_ws_pos_stats', 'ws_ajax_pos_stats' );
function ws_ajax_pos_stats() {
    ws_guard( 'pos_view' );

    $seller_id   = (int) ( $_POST['seller_id'] ?? 0 );
    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $date_from   = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to     = sanitize_text_field( $_POST['date_to'] ?? '' );

    $stats = WS_POS::get_stats( array(
        'seller_id'   => $seller_id,
        'location_id' => $location_id,
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

    if ( ! $location_id || ! in_array( $location_id, ws_user_location_ids(), true ) ) {
        wp_send_json_error( array( 'msg' => __( 'Ubicación inválida.', 'workshop' ) ) );
    }

    $result = WS_POS::close_cash( $location_id, $closing_amount, $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
    }

    ws_log_audit( 'pos_cash_close', 'pos_cash', (int) $result['id'], array( 'location' => $location_id, 'expected' => $result['expected'], 'actual' => $result['closing_amount'] ) );
    wp_send_json_success( array( 'data' => $result ) );
}

add_action( 'wp_ajax_ws_pos_cash_history', 'ws_ajax_pos_cash_history' );
function ws_ajax_pos_cash_history() {
    ws_guard( 'pos_view' );

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $status      = sanitize_key( $_POST['status'] ?? '' );
    $date_from   = sanitize_text_field( $_POST['date_from'] ?? '' );
    $date_to     = sanitize_text_field( $_POST['date_to'] ?? '' );

    $args = array(
        'location_id' => $location_id,
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

/* ---------------- Cache Offline AJAX ---------------- */

add_action( 'wp_ajax_ws_cache_products', 'ws_ajax_cache_products' );
add_action( 'wp_ajax_nopriv_ws_cache_products', 'ws_ajax_cache_products' );
function ws_ajax_cache_products() {
    ws_guard( 'products_view' );

    $location_id = (int) ( $_POST['location_id'] ?? 0 );
    $args = array(
        'location_id' => $location_id,
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

    $locations = WS_CRUD::get_locations( array( 'limit' => 100 ) );
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
