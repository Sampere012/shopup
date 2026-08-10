<?php
/**
 * Roles personalizados del tema.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

function ws_register_roles() {
    // Dueño del negocio: gestiona todo el negocio.
    if ( ! get_role( 'ws_owner' ) ) {
        add_role(
            'ws_owner',
            __( 'Dueño del negocio', 'workshop' ),
            array( 'read' => true )
        );
    }
    // Almacenero: entradas, salidas, bajas y transferencias.
    if ( ! get_role( 'ws_storekeeper' ) ) {
        add_role(
            'ws_storekeeper',
            __( 'Almacenero', 'workshop' ),
            array( 'read' => true )
        );
    }
    // Vendedor/PV: ventas y pedidos de su PV.
    if ( ! get_role( 'ws_seller' ) ) {
        add_role(
            'ws_seller',
            __( 'Vendedor/PV', 'workshop' ),
            array( 'read' => true )
        );
    }
}
