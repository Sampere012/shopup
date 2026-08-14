<?php
/**
 * Agente del asistente con herramientas (function calling estilo MCP).
 *
 * El LLM (Groq / OpenRouter / cualquier API compatible con OpenAI) recibe un
 * esquema de HERRAMIENTAS generado en vivo desde la base de datos del negocio
 * actual. Así el bot puede:
 *  - Consultar datos reales del negocio (productos, stock, pedidos, clientes,
 *    categorías, gastos, utilidad…) sin que haya que configurar nada nuevo:
 *    las tablas/columnas se descubren con SHOW TABLES / SHOW COLUMNS.
 *  - Crear, editar o eliminar productos, categorías y gastos (siempre con el
 *    permiso real del rol, vía las mismas funciones del panel).
 *  - Avisar al usuario con una notificación (campana + chat) tras cada acción.
 *
 * El modelo trabaja en bucle "agente": primero consulta, luego refina con los
 * datos obtenidos y al final devuelve el resultado. Todo ocurre en el servidor
 * (PHP → LLM → PHP), la clave del proveedor nunca sale del servidor.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * ¿El usuario actual puede usar las herramientas del agente?
 * Solo miembros de un negocio (dueño, almacenero, vendedor); visitantes y el
 * admin del sistema responden sin herramientas (consulta simple).
 */
function ws_chatbot_tools_available() {
    $role = ws_user_role();
    if ( ! $role || current_user_can( 'manage_options' ) ) {
        return false;
    }
    return true;
}

/**
 * Tablas del negocio actual con scope, permitidas para el agente.
 * Devuelve array( 'entidad' => 'nombre_real_de_tabla' ). Solo las entidades
 * del núcleo; las columnas se leen en vivo con SHOW COLUMNS (así las nuevas
 * columnas que se agreguen se descubren solas).
 */
function ws_chatbot_biz_tables() {
    global $wpdb;
    static $cache = null;
    if ( null !== $cache ) {
        return $cache;
    }
    $biz    = ws_current_business();
    $prefix = $wpdb->prefix . WS_TABLE_PREFIX;
    $like   = $prefix . '%';
    $suffix = ws_biz_table_suffix( $biz );
    if ( '' !== $suffix ) {
        $like = $prefix . $suffix . '_ws_%';
    }
    $existing = array();
    foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $t ) {
        // Clave corta: wp_ws_{sufijo}_ws_{entidad} → entidad (o wp_ws_{entidad} → entidad).
        if ( '' !== $suffix ) {
            $key = substr( $t, strlen( $prefix . $suffix . '_ws_' ) );
        } else {
            $key = substr( $t, strlen( $prefix ) );
        }
        $existing[ $key ] = $t;
    }
    $allowed = array(
        'products', 'categories', 'expenses', 'stock', 'orders', 'customers',
        'locations', 'suppliers', 'pos_sales', 'movements', 'reviews', 'loyalty',
    );
    $out = array();
    foreach ( $allowed as $key ) {
        if ( isset( $existing[ $key ] ) ) {
            $out[ $key ] = $existing[ $key ];
        }
    }
    $cache = $out;
    return $out;
}

/**
 * Columnas reales de una tabla del negocio (en vivo, para que el agente sepa
 * qué campos tiene y los nuevos se descubran automáticamente).
 */
function ws_chatbot_biz_columns( $table ) {
    global $wpdb;
    $tables = ws_chatbot_biz_tables();
    if ( ! isset( $tables[ $table ] ) ) {
        return array();
    }
    $cols = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $tables[ $table ] );
    $out  = array();
    foreach ( (array) $cols as $c ) {
        $out[] = array(
            'field' => (string) $c->Field,
            'type'  => (string) $c->Type,
        );
    }
    return $out;
}

/**
 * Esquema de herramientas (function calling) para el LLM.
 */
function ws_chatbot_tools_schema() {
    $tables = array_keys( ws_chatbot_biz_tables() );
    if ( empty( $tables ) ) {
        return array();
    }
    $empty_object = (object) array();
    $json = array(
        'type'                 => 'object',
        'properties'           => $empty_object,
        'additionalProperties' => false,
    );

    $tools = array(
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'biz_snapshot',
                'description' => 'Devuelve los números clave EN VIVO del negocio actual: productos totales, con stock bajo y agotados, vencidos y por vencer (próximos 7 días), pedidos pendientes, ventas y monto de hoy, clientes, trabajadores, categorías, gastos y utilidad del mes actual, y si la caja está abierta.',
                'parameters'  => array(
                    'type'                 => 'object',
                    'properties'           => (object) array(),
                    'additionalProperties' => false,
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'biz_categories',
                'description' => 'Devuelve las categorías y subcategorías del negocio en árbol, con su ruta "Padre / Hijo" y el número de productos activos de cada una.',
                'parameters'  => array(
                    'type'                 => 'object',
                    'properties'           => (object) array(),
                    'additionalProperties' => false,
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'biz_expenses',
                'description' => 'Lista los gastos del negocio actual (hasta 15) con su total; opcionalmente filtra por mes/año y por categoría de gasto.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'year'     => array( 'type' => 'integer', 'description' => 'Año (ej. 2026). Si no se indica, mes actual.' ),
                        'month'    => array( 'type' => 'integer', 'description' => 'Mes 1-12. Si no se indica, mes actual.' ),
                        'category' => array( 'type' => 'string', 'description' => 'Filtrar por categoría de gasto (opcional).' ),
                    ),
                    'required' => array(),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'biz_utility',
                'description' => 'Calcula la utilidad del negocio: ingresos (pedidos aceptados/completados + ventas POS completadas) MENOS los gastos, del mes indicado o del mes actual.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'year'  => array( 'type' => 'integer', 'description' => 'Año (ej. 2026). Si no se indica, mes actual.' ),
                        'month' => array( 'type' => 'integer', 'description' => 'Mes 1-12. Si no se indica, mes actual.' ),
                    ),
                    'required' => array(),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'biz_data',
                'description' => 'Consulta una tabla del negocio y devuelve filas con TODOS los campos reales (se descubren solos). Tablas disponibles: ' . implode( ', ', $tables ) . '.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'table' => array( 'type' => 'string', 'description' => 'Nombre de la tabla a consultar. Debe ser una de las disponibles.' ),
                        'order' => array( 'type' => 'string', 'description' => 'Orden de filas: campo o "campo desc" (ej. "id desc", "name asc", "created_at desc"). Opcional.' ),
                        'limit' => array( 'type' => 'integer', 'description' => 'Máximo de filas 1-15 (por defecto 10).' ),
                    ),
                    'required' => array( 'table' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'expense_create',
                'description' => 'Registra un GASTO nuevo del negocio. Se le notifica al usuario. Utiliza el permiso real del rol (solo si tiene gastos).',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'concept'      => array( 'type' => 'string', 'description' => 'Concepto del gasto (obligatorio).' ),
                        'amount'       => array( 'type' => 'number', 'description' => 'Monto del gasto mayor que 0.' ),
                        'category'     => array( 'type' => 'string', 'description' => 'Categoría del gasto (opcional).' ),
                        'note'         => array( 'type' => 'string', 'description' => 'Nota u observación (opcional).' ),
                        'expense_date' => array( 'type' => 'string', 'description' => 'Fecha YYYY-MM-DD (opcional; por defecto hoy).' ),
                    ),
                    'required' => array( 'concept', 'amount' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'expense_update',
                'description' => 'Edita un GASTO existente (id obligatorio). Se le notifica al usuario.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'           => array( 'type' => 'integer', 'description' => 'ID del gasto a editar.' ),
                        'concept'      => array( 'type' => 'string' ),
                        'amount'       => array( 'type' => 'number' ),
                        'category'     => array( 'type' => 'string' ),
                        'note'         => array( 'type' => 'string' ),
                        'expense_date' => array( 'type' => 'string' ),
                    ),
                    'required' => array( 'id' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'expense_delete',
                'description' => 'Elimina un GASTO existente (id obligatorio). Se le notifica al usuario.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id' => array( 'type' => 'integer', 'description' => 'ID del gasto a eliminar.' ),
                    ),
                    'required' => array( 'id' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'category_create',
                'description' => 'Crea una categoría (o subcategoría con parent_id) del negocio. Se le notifica al usuario.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'name'      => array( 'type' => 'string', 'description' => 'Nombre de la categoría (obligatorio).' ),
                        'parent_id' => array( 'type' => 'integer', 'description' => 'ID de la categoría padre para crear una SUBCATEGORÍA (0 = raíz).' ),
                    ),
                    'required' => array( 'name' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'category_update',
                'description' => 'Edita una categoría existente (id obligatorio). Se le notifica al usuario.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'        => array( 'type' => 'integer', 'description' => 'ID de la categoría a editar.' ),
                        'name'      => array( 'type' => 'string' ),
                        'parent_id' => array( 'type' => 'integer' ),
                    ),
                    'required' => array( 'id' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'category_delete',
                'description' => 'Elimina una categoría y TODA su rama de subcategorías (los productos pasan a la categoría padre). Se le notifica al usuario.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id' => array( 'type' => 'integer', 'description' => 'ID de la categoría a eliminar.' ),
                    ),
                    'required' => array( 'id' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'product_create',
                'description' => 'Crea un PRODUCTO en el negocio. Se le notifica al usuario. Solo si el rol puede crear productos.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'name'        => array( 'type' => 'string', 'description' => 'Nombre del producto (obligatorio).' ),
                        'sale_price'  => array( 'type' => 'number', 'description' => 'Precio de venta.' ),
                        'cost_price'  => array( 'type' => 'number', 'description' => 'Precio de costo (opcional).' ),
                        'category_id' => array( 'type' => 'integer', 'description' => 'ID de categoría (opcional; usa biz_categories para saberlos).' ),
                        'min_stock'   => array( 'type' => 'number', 'description' => 'Stock mínimo para alertas (opcional).' ),
                        'description' => array( 'type' => 'string', 'description' => 'Descripción (opcional).' ),
                        'supplier_id' => array( 'type' => 'integer', 'description' => 'ID de proveedor (opcional).' ),
                    ),
                    'required' => array( 'name' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'product_update',
                'description' => 'Edita un PRODUCTO existente (id obligatorio; solo envía los campos a cambiar). Se le notifica al usuario.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'          => array( 'type' => 'integer', 'description' => 'ID del producto a editar.' ),
                        'name'        => array( 'type' => 'string' ),
                        'sale_price'  => array( 'type' => 'number' ),
                        'cost_price'  => array( 'type' => 'number' ),
                        'category_id' => array( 'type' => 'integer' ),
                        'min_stock'   => array( 'type' => 'number' ),
                        'active'      => array( 'type' => 'boolean', 'description' => 'true activo, false inactivo (oculto).' ),
                    ),
                    'required' => array( 'id' ),
                ),
            ),
        ),
        array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'product_delete',
                'description' => 'Elimina un PRODUCTO existente (id obligatorio). Se le notifica al usuario.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id' => array( 'type' => 'integer', 'description' => 'ID del producto a eliminar.' ),
                    ),
                    'required' => array( 'id' ),
                ),
            ),
        ),
    );

    return $tools;
}

/**
 * Notifica al usuario actual tras una acción del agente.
 */
function ws_chatbot_tools_notify( $title, $message, $ref_key = '' ) {
    if ( function_exists( 'ws_chatbot_notify_user' ) ) {
        ws_chatbot_notify_user( get_current_user_id(), $title, $message, $ref_key );
    }
}

/**
 * Ejecuta una herramienta del agente (solo lectura o mutación guardada por
 * permisos). Devuelve un array con 'ok' y 'result' (o 'error').
 */
function ws_chatbot_tool_execute( $name, $args ) {
    $args = is_array( $args ) ? $args : array();
    $role = ws_user_role();
    if ( ! $role ) {
        return array( 'error' => 'No tienes un negocio asociado para esta consulta.' );
    }
    $cur = ws_currency_symbol();

    switch ( $name ) {

        case 'biz_snapshot':
            return array( 'ok' => true, 'result' => ws_chatbot_tools_snapshot() );

        case 'biz_categories':
            return array( 'ok' => true, 'result' => ws_chatbot_tools_categories() );

        case 'biz_expenses':
            return array( 'ok' => true, 'result' => ws_chatbot_tools_expenses( $args ) );

        case 'biz_utility':
            return array( 'ok' => true, 'result' => ws_chatbot_tools_utility( $args ) );

        case 'biz_data':
            return ws_chatbot_tools_data( $args );

        /* ---------------- Gastos (permiso expenses_manage) ---------------- */

        case 'expense_create':
        case 'expense_update':
        case 'expense_delete':
            if ( ! ws_can( 'expenses_manage' ) ) {
                return array( 'error' => 'No tienes permiso para gestionar gastos.' );
            }
            if ( 'expense_delete' === $name ) {
                $id = (int) ( $args['id'] ?? 0 );
                if ( ! $id ) {
                    return array( 'error' => 'Falta el id del gasto.' );
                }
                WS_Expenses::delete( $id );
                if ( function_exists( 'ws_log_audit' ) ) {
                    ws_log_audit( 'expense_delete', 'expense', $id );
                }
                ws_chatbot_tools_notify( 'Gasto eliminado', 'Se eliminó el gasto #' . $id . '.' );
                return array( 'ok' => true, 'result' => array( 'message' => 'Gasto #' . $id . ' eliminado.', 'deleted_id' => $id ) );
            }
            $id = (int) ( $args['id'] ?? 0 );
            $data = array(
                'concept'      => (string) ( $args['concept'] ?? '' ),
                'amount'       => (float) ( $args['amount'] ?? 0 ),
                'category'     => (string) ( $args['category'] ?? '' ),
                'note'         => (string) ( $args['note'] ?? '' ),
                'expense_date' => (string) ( $args['expense_date'] ?? '' ),
            );
            if ( isset( $args['location_id'] ) ) {
                $data['location_id'] = (int) $args['location_id'];
            }
            $result = WS_Expenses::save( $data, $id );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            if ( function_exists( 'ws_log_audit' ) ) {
                ws_log_audit( $id ? 'expense_update' : 'expense_create', 'expense', (int) $result, array( 'concept' => $data['concept'] ) );
            }
            ws_chatbot_tools_notify(
                $id ? 'Gasto actualizado' : 'Gasto registrado',
                ( $id ? 'Se actualizó' : 'Se registró' ) . ' el gasto «' . mb_substr( $data['concept'], 0, 60 ) . '» por ' . number_format_i18n( $data['amount'], 2 ) . ' ' . $cur . '.'
            );
            return array( 'ok' => true, 'result' => array( 'message' => 'Gasto guardado con id ' . (int) $result . '.', 'id' => (int) $result ) );

        /* ---------------- Categorías (permiso categories_manage) ---------------- */

        case 'category_create':
        case 'category_update':
        case 'category_delete':
            if ( ! ws_can( 'categories_manage' ) ) {
                return array( 'error' => 'No tienes permiso para gestionar categorías.' );
            }
            if ( 'category_delete' === $name ) {
                $id = (int) ( $args['id'] ?? 0 );
                if ( ! $id ) {
                    return array( 'error' => 'Falta el id de la categoría.' );
                }
                WS_Categories::delete( $id );
                if ( function_exists( 'ws_log_audit' ) ) {
                    ws_log_audit( 'category_delete', 'category', $id );
                }
                ws_chatbot_tools_notify( 'Categoría eliminada', 'Se eliminó la categoría #' . $id . ' y sus subcategorías.' );
                return array( 'ok' => true, 'result' => array( 'message' => 'Categoría #' . $id . ' eliminada.', 'deleted_id' => $id ) );
            }
            $id = (int) ( $args['id'] ?? 0 );
            $result = WS_Categories::save( $args, $id );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            if ( function_exists( 'ws_log_audit' ) ) {
                ws_log_audit( $id ? 'category_update' : 'category_create', 'category', (int) $result, array( 'name' => (string) ( $args['name'] ?? '' ) ) );
            }
            ws_chatbot_tools_notify(
                $id ? 'Categoría actualizada' : 'Categoría creada',
                ( $id ? 'Se actualizó' : 'Se creó' ) . ' la categoría «' . mb_substr( (string) ( $args['name'] ?? '' ), 0, 60 ) . '».'
            );
            return array( 'ok' => true, 'result' => array( 'message' => 'Categoría guardada con id ' . (int) $result . '.', 'id' => (int) $result ) );

        /* ---------------- Productos (permisos de productos) ---------------- */

        case 'product_create':
            if ( ! ws_can( 'products_create', 'products_edit' ) ) {
                return array( 'error' => 'No tienes permiso para crear productos.' );
            }
            $result = WS_CRUD::save_product( $args, 0 );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            if ( function_exists( 'ws_log_audit' ) ) {
                ws_log_audit( 'product_create', 'product', (int) $result, array( 'name' => (string) ( $args['name'] ?? '' ) ) );
            }
            ws_chatbot_tools_notify( 'Producto creado', 'Se creó «' . mb_substr( (string) ( $args['name'] ?? '' ), 0, 60 ) . '».' );
            return array( 'ok' => true, 'result' => array( 'message' => 'Producto creado con id ' . (int) $result . '.', 'id' => (int) $result ) );

        case 'product_update':
            if ( ! ws_can( 'products_edit' ) ) {
                return array( 'error' => 'No tienes permiso para editar productos.' );
            }
            $id = (int) ( $args['id'] ?? 0 );
            if ( ! $id ) {
                return array( 'error' => 'Falta el id del producto.' );
            }
            unset( $args['id'] );
            $result = WS_CRUD::save_product( $args, $id );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            if ( function_exists( 'ws_log_audit' ) ) {
                ws_log_audit( 'product_update', 'product', (int) $result );
            }
            ws_chatbot_tools_notify( 'Producto actualizado', 'Se actualizó el producto #' . (int) $result . '.' );
            return array( 'ok' => true, 'result' => array( 'message' => 'Producto #' . (int) $result . ' actualizado.', 'id' => (int) $result ) );

        case 'product_delete':
            if ( ! ws_can( 'products_delete' ) ) {
                return array( 'error' => 'No tienes permiso para eliminar productos.' );
            }
            $id = (int) ( $args['id'] ?? 0 );
            if ( ! $id ) {
                return array( 'error' => 'Falta el id del producto.' );
            }
            WS_CRUD::delete_product( $id );
            if ( function_exists( 'ws_log_audit' ) ) {
                ws_log_audit( 'product_delete', 'product', $id );
            }
            ws_chatbot_tools_notify( 'Producto eliminado', 'Se eliminó el producto #' . $id . '.' );
            return array( 'ok' => true, 'result' => array( 'message' => 'Producto #' . $id . ' eliminado.', 'deleted_id' => $id ) );
    }

    return array( 'error' => 'Herramienta desconocida: ' . sanitize_key( $name ) );
}

/**
 * Números clave del negocio (snapshot).
 */
function ws_chatbot_tools_snapshot() {
    global $wpdb;
    $biz  = ws_current_business();
    $suf  = ws_biz_table_suffix( $biz );
    $T    = function ( $t ) use ( $suf ) { return ws_table_for( $suf, $t ); };
    $cur  = ws_currency_symbol();

    $products  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('products')}" );
    $categories = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('categories')}" );
    $pending   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$T('orders')} WHERE status = %s", 'pending' ) );
    // Stock bajo con el STOCK DEL GRUPO CONECTADO (stock compartido).
    $low_stock = WS_Stock::count_low_stock_group_rows( array(), true );
    $agotados  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('stock')} st WHERE st.qty <= 0" );
    $today     = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
    $soon      = gmdate( 'Y-m-d', current_time( 'timestamp' ) + 7 * DAY_IN_SECONDS );
    $vexpired  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$T('products')} WHERE active=1 AND expiry_date IS NOT NULL AND expiry_date < %s", $today ) );
    $vexpiring = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$T('products')} WHERE active=1 AND expiry_date IS NOT NULL AND expiry_date BETWEEN %s AND %s", $today, $soon ) );
    $customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T('customers')}" );
    $today     = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
    $sale_row  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM {$T('pos_sales')} WHERE created_at >= %s AND status = 'completed'", $today ) );
    $workers   = count( ws_chatbot_team_members() );

    $cash_open = false;
    foreach ( ws_user_locations() as $l ) {
        if ( WS_POS::get_open_cash( (int) $l->id ) ) {
            $cash_open = true;
            break;
        }
    }

    $month   = ws_chatbot_tools_utility( array() );
    $expense = ws_chatbot_tools_expenses( array() );

    return array(
        'business_name'       => (string) ( $biz->name ?? ws_site_name() ),
        'products'            => $products,
        'categories'          => $categories,
        'products_low_stock'  => $low_stock,
        'products_out'        => $agotados,
        'products_expired'    => $vexpired,
        'products_expiring'   => $vexpiring,
        'pending_orders'      => $pending,
        'sales_today'         => (int) $sale_row->c,
        'sales_today_total'   => (float) $sale_row->t,
        'customers'           => $customers,
        'workers'             => $workers,
        'cash_open'           => $cash_open,
        'currency'            => $cur,
        'expenses_this_month' => (float) ( $expense['total'] ?? 0 ),
        'utility_this_month'  => (float) ( $month['utility'] ?? 0 ),
        'income_this_month'   => (float) ( $month['income'] ?? 0 ),
    );
}

/**
 * Categorías en árbol del negocio.
 */
function ws_chatbot_tools_categories() {
    $tree = array();
    foreach ( WS_Categories::all() as $c ) {
        $tree[] = array(
            'id'         => (int) $c->id,
            'parent_id'  => (int) $c->parent_id,
            'name'       => (string) $c->name,
            'path'       => WS_Categories::path_text( (int) $c->id ),
            'products'   => (int) WS_Categories::products_count( (int) $c->id ),
            'active'     => (int) $c->active,
        );
    }
    return array( 'categories' => $tree );
}

/**
 * Gastos del negocio (filtros mes/año/categoría) + totales.
 */
function ws_chatbot_tools_expenses( $args = array() ) {
    $year  = (int) ( $args['year'] ?? 0 );
    $month = (int) ( $args['month'] ?? 0 );
    $cat   = sanitize_text_field( $args['category'] ?? '' );
    $rows  = array();
    foreach ( WS_Expenses::all( $year, $month ) as $e ) {
        if ( '' !== $cat && stripos( (string) $e->category, $cat ) === false ) {
            continue;
        }
        $rows[] = array(
            'id'            => (int) $e->id,
            'concept'       => (string) $e->concept,
            'amount'        => (float) $e->amount,
            'category'      => (string) $e->category,
            'location_name' => (string) WS_Expenses::location_name( $e ),
            'date'          => gmdate( 'Y-m-d', strtotime( $e->expense_date ) ),
        );
        if ( count( $rows ) >= 15 ) {
            break;
        }
    }
    $total = 0.0;
    foreach ( $rows as $r ) {
        $total += $r['amount'];
    }
    return array(
        'expenses' => $rows,
        'total'    => $total,
        'currency' => ws_currency_symbol(),
        'month'    => $month ? $month : (int) gmdate( 'n' ),
        'year'     => $year ? $year : (int) gmdate( 'Y' ),
    );
}

/**
 * Utilidad del negocio (ingresos - gastos) de un mes.
 */
function ws_chatbot_tools_utility( $args = array() ) {
    $year  = (int) ( $args['year'] ?? 0 );
    $month = (int) ( $args['month'] ?? 0 );
    if ( ! $year ) {
        $year = (int) gmdate( 'Y' );
    }
    if ( ! $month ) {
        $month = (int) gmdate( 'n' );
    }
    $s = WS_Expenses::month_summary( $year, $month );
    return array(
        'year'     => $s['year'],
        'month'    => $s['month'],
        'income'   => (float) $s['income'],
        'expenses' => (float) $s['expenses'],
        'utility'  => (float) $s['utility'],
        'currency' => (string) $s['currency'],
    );
}

/**
 * Consulta genérica de una tabla del negocio (SOLO LECTURA). La tabla se
 * valida contra el esquema real del negocio y las columnas se leen en vivo.
 */
function ws_chatbot_tools_data( $args = array() ) {
    global $wpdb;
    $table = sanitize_key( (string) ( $args['table'] ?? '' ) );
    $tables = ws_chatbot_biz_tables();
    if ( '' === $table || ! isset( $tables[ $table ] ) ) {
        return array( 'error' => 'Tabla no disponible. Usa una de: ' . implode( ', ', array_keys( $tables ) ) . '.' );
    }
    $order = (string) ( $args['order'] ?? 'id desc' );
    $order = trim( preg_replace( '/[^a-zA-Z0-9_\s]/', '', $order ) );
    if ( '' === $order ) {
        $order = 'id desc';
    }
    if ( ! preg_match( '/^[a-zA-Z0-9_]+\s+(asc|desc)$/i', $order ) ) {
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $order ) ) {
            $order .= ' asc';
        } else {
            $order = 'id desc';
        }
    }
    $limit = max( 1, min( 15, (int) ( $args['limit'] ?? 10 ) ) );

    $cols = ws_chatbot_biz_columns( $table );
    if ( empty( $cols ) ) {
        return array( 'error' => 'La tabla no tiene columnas disponibles.' );
    }
    $field = strtok( $order, ' ' );
    $valid = array();
    foreach ( $cols as $c ) {
        $valid[] = strtolower( (string) $c['field'] );
    }
    if ( ! in_array( strtolower( $field ), $valid, true ) ) {
        $order = 'id desc';
    }

    $sql  = 'SELECT * FROM ' . $tables[ $table ] . ' ORDER BY ' . $order . ' LIMIT ' . $limit;
    $rows = (array) $wpdb->get_results( $sql, ARRAY_A );
    // Solo valores planos: nada de objetos/binarios en la respuesta del LLM.
    $out = array();
    foreach ( $rows as $r ) {
        $flat = array();
        foreach ( $r as $k => $v ) {
            if ( is_scalar( $v ) ) {
                $flat[ $k ] = $v;
            }
        }
        $out[] = $flat;
    }
    return array(
        'table'  => $table,
        'columns' => array_map( static function ( $c ) { return $c['field']; }, $cols ),
        'rows'   => $out,
        'count'  => count( $out ),
    );
}

/**
 * Bucle agente: envía el prompt + herramientas al LLM, ejecuta las llamadas a
 * herramientas que el modelo pida (máx. $max_rounds) y devuelve el texto final.
 *
 * $messages: historial previo (user/assistant) con la pregunta ya añadida.
 * Devuelve array( 'text' => ..., 'calls' => int, 'steps' => int ).
 */
function ws_chatbot_llm_agent( $messages, $system ) {
    $admin     = ws_chatbot_admin_settings();
    $provider  = (string) ( $admin['llm_provider'] ?? 'openrouter' );
    $url       = ws_chatbot_llm_url( $provider, $admin['llm_base_url'] ?? '' );
    $key       = trim( (string) $admin['llm_key'] );
    if ( '' === $url || '' === $key ) {
        return array( 'text' => '', 'calls' => 0, 'steps' => 0, 'error' => 'no-config' );
    }

    $provider_label = 'custom' === $provider ? 'IA personalizada' : ( 'groq' === $provider ? 'Groq' : 'OpenRouter' );
    $tools = ws_chatbot_tools_schema();

    $full = array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $messages );
    $steps = 0;
    $calls = 0;
    $max_rounds = 5;

    for ( $round = 0; $round < $max_rounds; $round++ ) {
        $steps++;
        $body = array(
            'model'       => (string) $admin['llm_model'],
            'messages'    => $full,
            'max_tokens'  => 900,
            'temperature' => 0.5,
        );
        if ( ! empty( $tools ) ) {
            $body['tools']      = $tools;
            $body['tool_choice'] = 'auto';
        }

        $resp = wp_remote_post( $url, array(
            'timeout' => 35,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $resp ) ) {
            if ( function_exists( 'ws_log_error' ) ) {
                ws_log_error( 'LLM (' . $provider_label . ') no disponible: ' . $resp->get_error_message() );
            }
            return array( 'text' => '', 'calls' => $calls, 'steps' => $steps, 'error' => 'unavailable' );
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code >= 400 || ! isset( $json['choices'][0]['message'] ) ) {
            $err = (string) ( $json['error']['message'] ?? 'La IA no respondió.' );
            if ( function_exists( 'ws_log_error' ) ) {
                ws_log_error( 'LLM (' . $provider_label . ') HTTP ' . $code . ': ' . mb_substr( $err, 0, 200 ) );
            }
            return array( 'text' => '', 'calls' => $calls, 'steps' => $steps, 'error' => 'http-' . $code, 'detail' => mb_substr( $err, 0, 200 ) );
        }

        $msg        = $json['choices'][0]['message'];
        $content    = (string) ( $msg['content'] ?? '' );
        $tool_calls = $msg['tool_calls'] ?? array();

        if ( empty( $tool_calls ) || ! is_array( $tool_calls ) ) {
            return array(
                'text'  => '' !== trim( $content ) ? trim( $content ) : 'Listo.',
                'calls' => $calls,
                'steps' => $steps,
            );
        }

        // Ejecuta las herramientas pedidas y alimenta el historial.
        $assistant_msg = array( 'role' => 'assistant', 'content' => $content, 'tool_calls' => array() );
        foreach ( $tool_calls as $tc ) {
            $assistant_msg['tool_calls'][] = array(
                'id'       => (string) ( $tc['id'] ?? '' ),
                'type'     => 'function',
                'function' => array(
                    'name'      => (string) ( $tc['function']['name'] ?? '' ),
                    'arguments' => (string) ( $tc['function']['arguments'] ?? '{}' ),
                ),
            );
        }
        $full[] = $assistant_msg;

        foreach ( $tool_calls as $tc ) {
            $calls++;
            $tname = (string) ( $tc['function']['name'] ?? '' );
            $targs = json_decode( (string) ( $tc['function']['arguments'] ?? '{}' ), true );
            if ( ! is_array( $targs ) ) {
                $targs = array();
            }
            $result = ws_chatbot_tool_execute( $tname, $targs );
            // Para el modelo solo texto: el objeto se serializa a JSON.
            $full[] = array(
                'role'         => 'tool',
                'tool_call_id' => (string) ( $tc['id'] ?? '' ),
                'content'      => wp_json_encode( $result ),
            );
        }
    }

    return array( 'text' => 'He procesado la consulta pero necesito más datos para completarla. ¿Me ayudas con más detalle?', 'calls' => $calls, 'steps' => $steps );
}

/* -------------------------------------------------------------------------
 * AJAX del agente: datos de gastos, utilidad y categorías para el widget
 * (funcionan también sin LLM: el bot responde con datos reales en vivo).
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_ws_chatbot_expenses', 'ws_ajax_chatbot_expenses' );
function ws_ajax_chatbot_expenses() {
    ws_guard( 'expenses_manage' );
    wp_send_json_success( ws_chatbot_tools_expenses( array(
        'year'  => (int) ( $_POST['year'] ?? 0 ),
        'month' => (int) ( $_POST['month'] ?? 0 ),
    ) ) );
}

add_action( 'wp_ajax_ws_chatbot_utility', 'ws_ajax_chatbot_utility' );
function ws_ajax_chatbot_utility() {
    ws_guard( 'expenses_manage', 'reports_view' );
    $year  = (int) ( $_POST['year'] ?? 0 );
    $month = (int) ( $_POST['month'] ?? 0 );
    $util  = ws_chatbot_tools_utility( array( 'year' => $year, 'month' => $month ) );

    // Hoy: ingresos, gastos y utilidad del día.
    global $wpdb;
    $biz  = ws_current_business();
    $suf  = ws_biz_table_suffix( $biz );
    $T    = function ( $t ) use ( $suf ) { return ws_table_for( $suf, $t ); };
    $today = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
    $pos  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$T('pos_sales')} WHERE created_at >= %s AND status = 'completed'", $today ) );
    $ord  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$T('orders')} WHERE created_at >= %s AND status IN ('accepted','completed')", $today ) );
    $exp  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$T('expenses')} WHERE expense_date >= %s AND expense_date < %s", $today, gmdate( 'Y-m-d 23:59:59', current_time( 'timestamp' ) ) ) );

    wp_send_json_success( array(
        'month'  => $util,
        'today'  => array(
            'income'   => $pos + $ord,
            'expenses' => $exp,
            'utility'  => ( $pos + $ord ) - $exp,
            'currency' => ws_currency_symbol(),
        ),
    ) );
}

add_action( 'wp_ajax_ws_chatbot_categories', 'ws_ajax_chatbot_categories' );
function ws_ajax_chatbot_categories() {
    ws_guard( 'categories_manage', 'products_view' );
    wp_send_json_success( array(
        'categories' => ws_chatbot_tools_categories()['categories'],
        'payload'    => class_exists( 'WS_Categories' ) ? ws_categories_payload() : array(),
    ) );
}

/* -------------------------------------------------------------------------
 * Conocimiento EN VIVO del negocio para el widget (gastos, utilidad,
 * categorías y resumen): responde con datos reales aunque el LLM no esté
 * configurado, y ofrece chips que ejecutan las acciones del widget.
 * ---------------------------------------------------------------------- */

function ws_chatbot_business_live_knowledge() {
    $role = ws_user_role();
    if ( ! $role ) {
        return array();
    }
    $can_exp  = ws_can( 'expenses_manage' );
    $can_cat  = ws_can( 'categories_manage' );
    $can_rep  = ws_can( 'reports_view' );
    $items    = array();

    if ( $can_exp || $can_rep ) {
        $items[] = array(
            'id'       => 'biz-gastos',
            'patterns' => array(
                'donde veo mis gastos', 'donde registro gastos', 'como registro un gasto',
                'como agrego un gasto', 'para que sirve el modulo de gastos',
                'como se usa el modulo de gastos', 'explicame el modulo de gastos',
            ),
            'answer'   => __( 'Tu módulo de Gastos registra cada desembolso con su fecha y categoría, y calcula la utilidad (ingresos − gastos) del mes. Escríbeme "ver gastos" para listarlos en vivo, "utilidad del mes" para el cálculo, o ve directo al módulo:', 'workshop' ),
            'chips'    => array(
                array( 'label' => __( 'Ver gastos', 'workshop' ), 'send' => 'ver gastos', 'icon' => 'fa-receipt' ),
                array( 'label' => __( 'Utilidad del mes', 'workshop' ), 'send' => 'utilidad del mes', 'icon' => 'fa-chart-pie' ),
            ),
        );
    }

    if ( $can_cat ) {
        $items[] = array(
            'id'       => 'biz-categorias',
            'patterns' => array(
                'donde veo mis categorias', 'como agrego una subcategoria', 'como creo una categoria',
                'para que sirven las categorias', 'que son las subcategorias', 'como organizo mi catalogo',
            ),
            'answer'   => __( 'Las categorías organizan tu catálogo en árbol: una categoría puede tener subcategorías (Padre / Hijo). Escríbeme "ver categorías" para mostrarte el árbol real con los productos de cada una, o ve al módulo:', 'workshop' ),
            'chips'    => array(
                array( 'label' => __( 'Ver categorías', 'workshop' ), 'send' => 'ver categorias', 'icon' => 'fa-sitemap' ),
            ),
        );
    }

    return $items;
}