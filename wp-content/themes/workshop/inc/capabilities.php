<?php
/**
 * Matriz de permisos por módulo.
 *
 * Permisos disponibles (claves):
 * - products_view / products_create / products_edit / products_delete / products_bulk
 * - suppliers_view / suppliers_manage
 * - locations_view / locations_manage
 * - stock_view / stock_entry / stock_exit / stock_writeoff / stock_transfer
 * - movements_view
 * - orders_view / orders_accept / orders_manage
 * - shifts_view / shifts_manage
 * - workers_manage
 * - permissions_manage
 * - reports_view
 * - settings_manage
 * - site_manage     (apariencia del sitio: logo, colores, CSS)
 * - layout_manage   (portada y pie de página)
 * - store_public  (URL pública de PV)
 * - customers_view / customers_create / customers_edit / customers_delete / customers_manage (CRM)
 * - reviews_view / reviews_moderate (valoraciones de productos)
 * - loyalty_manage (programa de fidelización)
 * - products_fraction (fraccionamiento de productos padre/hijo)
 * - pos_sell (sistema POS para vendedores)
 * - pos_view (ver ventas POS)
 * - stock_count_view / locations_links_view / pos_cash_view / workers_view
 *
 * La matriz por defecto se puede ajustar por rol desde el panel del Dueño.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

class WS_Capabilities {

    const DEFAULTS = array(
        'owner' => array(
            'products_view' => true, 'products_create' => true, 'products_edit' => true,
            'products_delete' => true, 'products_bulk' => true,
            'suppliers_view' => true, 'suppliers_manage' => true,
            'locations_view' => true, 'locations_manage' => true,
            'stock_view' => true, 'stock_entry' => true, 'stock_exit' => true,
            'stock_writeoff' => true, 'stock_transfer' => true,
            'stock_count_view' => true,
            'movements_view' => true,
            'orders_view' => true, 'orders_accept' => true, 'orders_manage' => true,
            'shifts_view' => true, 'shifts_manage' => true,
            'workers_manage' => true,
            'workers_view' => true,
            'permissions_manage' => true,
            'reports_view' => true,
            'settings_manage' => true,
            'site_manage' => true,
            'layout_manage' => true,
            'customers_view' => true, 'customers_create' => true, 'customers_edit' => true,
            'customers_delete' => true, 'customers_manage' => true,
            'reviews_view' => true, 'reviews_moderate' => true,
            // Fidelización DESMARCADA por defecto en negocios nuevos: el módulo
            // no aparece ni para el negocio ni para sus trabajadores hasta que
            // el dueño lo active desde Permisos.
            'loyalty_manage' => false,
            'products_fraction' => true,
            'pos_sell' => true, 'pos_view' => true,
            'pos_cash_view' => true, 'locations_links_view' => true,
            // Módulos nuevos: categorías en árbol y control de gastos.
            'categories_manage' => true,
            'expenses_manage' => true,
        ),
        'storekeeper' => array(
            'products_view' => true, 'products_create' => false, 'products_edit' => false,
            'products_delete' => false, 'products_bulk' => false,
            'suppliers_view' => true, 'suppliers_manage' => false,
            'locations_view' => true, 'locations_manage' => false,
            'stock_view' => true, 'stock_entry' => true, 'stock_exit' => true,
            'stock_writeoff' => true, 'stock_transfer' => true,
            'stock_count_view' => true,
            'movements_view' => true,
            'orders_view' => false, 'orders_accept' => false, 'orders_manage' => false,
            'shifts_view' => true, 'shifts_manage' => false,
            'workers_manage' => false,
            'workers_view' => true,
            'permissions_manage' => false,
            'reports_view' => false,
            'settings_manage' => false,
            'site_manage' => false,
            'layout_manage' => false,
            'customers_view' => true, 'customers_create' => false, 'customers_edit' => false,
            'customers_delete' => false, 'customers_manage' => false,
            'reviews_view' => false, 'reviews_moderate' => false,
            'loyalty_manage' => false,
            'products_fraction' => false,
            'pos_sell' => false, 'pos_view' => false,
            'pos_cash_view' => false, 'locations_links_view' => false,
            'categories_manage' => false,
            'expenses_manage' => false,
        ),
        'seller' => array(
            'products_view' => true, 'products_create' => false, 'products_edit' => false,
            'products_delete' => false, 'products_bulk' => false,
            'suppliers_view' => false, 'suppliers_manage' => false,
            'locations_view' => true, 'locations_manage' => false,
            'stock_view' => true, 'stock_entry' => false, 'stock_exit' => true,
            'stock_writeoff' => false, 'stock_transfer' => false,
            'stock_count_view' => false,
            'movements_view' => true,
            'orders_view' => true, 'orders_accept' => true, 'orders_manage' => false,
            'shifts_view' => true, 'shifts_manage' => false,
            'workers_manage' => false,
            'workers_view' => false,
            'permissions_manage' => false,
            'reports_view' => false,
            'settings_manage' => false,
            'site_manage' => false,
            'layout_manage' => false,
            'customers_view' => true, 'customers_create' => true, 'customers_edit' => true,
            'customers_delete' => false, 'customers_manage' => false,
            'reviews_view' => true, 'reviews_moderate' => false,
            'loyalty_manage' => false,
            'products_fraction' => false,
            'pos_sell' => true, 'pos_view' => true,
            'pos_cash_view' => true, 'locations_links_view' => false,
            'categories_manage' => false,
            'expenses_manage' => false,
        ),
    );

    public static function all_caps() {
        return array(
            'products_view'    => __( 'Ver productos', 'workshop' ),
            'products_create'  => __( 'Crear productos', 'workshop' ),
            'products_edit'    => __( 'Editar productos', 'workshop' ),
            'products_delete'  => __( 'Eliminar productos', 'workshop' ),
            'products_bulk'    => __( 'Carga masiva / CSV', 'workshop' ),
            'suppliers_view'   => __( 'Ver proveedores', 'workshop' ),
            'suppliers_manage' => __( 'Gestionar proveedores', 'workshop' ),
            'locations_view'   => __( 'Ver ubicaciones', 'workshop' ),
            'locations_manage' => __( 'Gestionar ubicaciones', 'workshop' ),
            'stock_view'       => __( 'Ver stock', 'workshop' ),
            'stock_entry'      => __( 'Entradas de stock', 'workshop' ),
            'stock_exit'       => __( 'Salidas de stock', 'workshop' ),
            'stock_writeoff'   => __( 'Bajas de stock', 'workshop' ),
            'stock_transfer'   => __( 'Transferencias', 'workshop' ),
            'movements_view'   => __( 'Ver historial de movimientos', 'workshop' ),
            'orders_view'      => __( 'Ver pedidos', 'workshop' ),
            'orders_accept'    => __( 'Aceptar/rechazar pedidos', 'workshop' ),
            'orders_manage'    => __( 'Gestionar pedidos (todos)', 'workshop' ),
            'shifts_view'      => __( 'Ver turnos', 'workshop' ),
            'shifts_manage'    => __( 'Gestionar turnos', 'workshop' ),
            'workers_manage'   => __( 'Gestionar trabajadores', 'workshop' ),
            'permissions_manage' => __( 'Gestionar permisos', 'workshop' ),
            'reports_view'     => __( 'Ver reportes', 'workshop' ),
            'settings_manage'  => __( 'Gestionar configuración', 'workshop' ),
            'site_manage'      => __( 'Editar sitio (logo, colores, favicon, CSS)', 'workshop' ),
            'layout_manage'    => __( 'Ajustar layout (portada y pie de página)', 'workshop' ),
            'customers_view'   => __( 'Ver clientes (CRM)', 'workshop' ),
            'customers_create' => __( 'Crear clientes', 'workshop' ),
            'customers_edit'   => __( 'Editar clientes', 'workshop' ),
            'customers_delete' => __( 'Eliminar clientes', 'workshop' ),
            'customers_manage' => __( 'Gestionar clientes (CRM completo)', 'workshop' ),
            'reviews_view'     => __( 'Ver valoraciones', 'workshop' ),
            'reviews_moderate' => __( 'Moderar valoraciones', 'workshop' ),
            'loyalty_manage'   => __( 'Gestionar fidelización', 'workshop' ),
            'products_fraction' => __( 'Fraccionamiento de productos (padre/hijo)', 'workshop' ),
            'pos_sell'         => __( 'Vender en POS', 'workshop' ),
            'pos_view'         => __( 'Ver ventas POS', 'workshop' ),
            'stock_count_view' => __( 'Ver cuadre e historial de cuadre', 'workshop' ),
            'locations_links_view' => __( 'Ver conexión entre ubicaciones', 'workshop' ),
            'pos_cash_view'    => __( 'Ver caja y arqueos POS', 'workshop' ),
            'workers_view'     => __( 'Ver trabajadores', 'workshop' ),
            'categories_manage' => __( 'Gestionar categorías de productos (árbol)', 'workshop' ),
            'expenses_manage'  => __( 'Gestionar gastos (control de gastos)', 'workshop' ),
        );
    }

    public static function matrix( $biz_id = 0 ) {
        $biz_id  = $biz_id ? (int) $biz_id : ws_current_business_id();
        $stored  = ws_biz_option_for( 'ws_permissions_matrix', array(), $biz_id );
        $defaults = self::DEFAULTS;
        foreach ( $defaults as $role => $caps ) {
            if ( isset( $stored[ $role ] ) && is_array( $stored[ $role ] ) ) {
                $defaults[ $role ] = wp_parse_args( $stored[ $role ], $caps );
            }
        }
        $defaults['owner']['permissions_manage'] = true;
        return $defaults;
    }

    public static function can( $cap, $user_id = 0 ) {
        $user_id = $user_id ? $user_id : get_current_user_id();
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return false;
        }
        if ( in_array( 'administrator', (array) $user->roles, true ) ) {
            return true;
        }
        $role_slug = '';
        $map = array(
            'ws_owner'       => 'owner',
            'ws_storekeeper' => 'storekeeper',
            'ws_seller'      => 'seller',
        );
        foreach ( $map as $wp_role => $slug ) {
            if ( in_array( $wp_role, (array) $user->roles, true ) ) {
                $role_slug = $slug;
                break;
            }
        }
        if ( ! $role_slug ) {
            return false;
        }
        $matrix = self::matrix( ws_current_business_id() );
        return ! empty( $matrix[ $role_slug ][ $cap ] );
    }

    /**
     * Persiste la matriz de permisos.
     */
    public static function save_matrix( $matrix, $biz_id = 0 ) {
        $biz_id = $biz_id ? (int) $biz_id : ws_current_business_id();
        $clean = array();
        $all   = array_keys( self::all_caps() );
        $roles = array( 'owner', 'storekeeper', 'seller' );
        foreach ( $roles as $role ) {
            $clean[ $role ] = array();
            foreach ( $all as $cap ) {
                $clean[ $role ][ $cap ] = ! empty( $matrix[ $role ][ $cap ] );
            }
        }
        ws_save_biz_option_for( 'ws_permissions_matrix', $clean, $biz_id );
        return $clean;
    }
}

function ws_default_permissions() {
    WS_Capabilities::save_matrix( WS_Capabilities::DEFAULTS );
}
