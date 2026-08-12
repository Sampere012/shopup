<?php
/**
 * Anuncios del negocio (ShopUp → Anuncios).
 *
 * El dueño (o el administrador del sistema) crea mensajes y notificaciones
 * ancladas para TODOS los usuarios de su negocio (dueños, almaceneros y
 * vendedores). Cada anuncio activo:
 *  - Se entrega como notificación a cada usuario (campana + el asistente lo
 *    muestra como mensaje normal del chat automáticamente).
 *  - Si está anclado (pinned), aparece como banner destacado en el panel.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tabla de anuncios (global, aislada por business_id).
 */
function ws_announcements_table() {
    global $wpdb;
    return $wpdb->prefix . WS_TABLE_PREFIX . 'announcements';
}

/**
 * ¿Puede el usuario gestionar anuncios? Solo el dueño del negocio y el
 * administrador del sistema.
 */
function ws_announcement_can( $user_id = 0 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( user_can( $user_id, 'manage_options' ) ) {
        return true;
    }
    return 'owner' === ws_user_role( $user_id );
}

/**
 * Usuarios del negocio: dueños + trabajadores (almaceneros/vendedores)
 * asignados al negocio. Para el negocio por defecto se incluyen también los
 * dueños legacy (sin ws_business_id).
 */
function ws_announcement_business_users( $biz_id ) {
    $biz_id = (int) $biz_id;
    $args   = array(
        'role__in' => array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ),
        'fields'   => 'ID',
    );
    $users = array();
    if ( class_exists( 'WS_Business' ) && WS_Business::is_default_id( $biz_id ) ) {
        $legacy = get_users( array(
            'role__in'     => array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ),
            'fields'       => 'ID',
            'meta_key'     => 'ws_business_id',
            'meta_compare' => 'NOT EXISTS',
        ) );
        $with   = get_users( array_merge( $args, array( 'meta_key' => 'ws_business_id', 'meta_value' => $biz_id ) ) );
        $users  = array_merge( $legacy, $with );
    } else {
        $users = get_users( array_merge( $args, array( 'meta_key' => 'ws_business_id', 'meta_value' => $biz_id ) ) );
    }
    return array_values( array_unique( array_map( 'intval', $users ) ) );
}

/**
 * Lee un anuncio por id.
 */
function ws_announcement_get( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ws_announcements_table() . ' WHERE id=%d', (int) $id ) );
}

/**
 * Anuncios de un negocio (anclados primero).
 */
function ws_announcements_for_business( $biz_id = 0 ) {
    global $wpdb;
    if ( ! $biz_id ) {
        $biz_id = ws_current_business_id();
    }
    return $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . ws_announcements_table() . ' WHERE business_id=%d ORDER BY pinned DESC, id DESC',
        (int) $biz_id
    ) );
}

/**
 * Anuncios anclados y activos de un negocio (banners del panel).
 */
function ws_announcements_pinned( $biz_id = 0 ) {
    global $wpdb;
    if ( ! $biz_id ) {
        $biz_id = ws_current_business_id();
    }
    return $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . ws_announcements_table() . ' WHERE business_id=%d AND active=1 AND pinned=1 ORDER BY id DESC',
        (int) $biz_id
    ) );
}

/**
 * Crea o actualiza un anuncio. Al crearlo (activo) lo envía a todos los
 * usuarios del negocio como notificación.
 */
function ws_announcement_save( $data, $id = 0 ) {
    global $wpdb;
    $table = ws_announcements_table();

    $row = array(
        'business_id' => (int) ( $data['business_id'] ?? ws_current_business_id() ),
        'title'       => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
        'message'     => sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ),
        'type'        => in_array( (string) ( $data['type'] ?? '' ), array( 'info', 'success', 'warning', 'danger' ), true ) ? sanitize_key( $data['type'] ) : 'info',
        'pinned'      => ! empty( $data['pinned'] ) ? 1 : 0,
        'active'      => array_key_exists( 'active', $data ) ? (int) (bool) $data['active'] : 1,
    );
    if ( '' === trim( $row['title'] ) ) {
        return 0;
    }

    if ( $id ) {
        // Al editar nunca se cambia el negocio del anuncio: solo el dueño del
        // negocio al que pertenece puede modificarlo (el negocio del formulario
        // es el actual). Si no existe o es de otro negocio, se rechaza.
        $current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $id ) );
        if ( ! $current || (int) $current->business_id !== (int) $row['business_id'] ) {
            return 0;
        }
        $row['business_id'] = (int) $current->business_id;
        // El formulario no envía 'active' al editar: conserva el estado actual
        // para no reactivar un anuncio que estaba desactivado.
        if ( ! array_key_exists( 'active', $data ) ) {
            $row['active'] = (int) $current->active;
        }
        $wpdb->update( $table, $row, array( 'id' => (int) $id ), array( '%d', '%s', '%s', '%s', '%d', '%d' ), array( '%d' ) );
        return (int) $id;
    }

    $row['created_by'] = get_current_user_id();
    $wpdb->insert( $table, $row, array( '%d', '%s', '%s', '%s', '%d', '%d', '%d' ) );
    $new_id = (int) $wpdb->insert_id;
    if ( $new_id && ! empty( $row['active'] ) ) {
        ws_announcement_broadcast( $new_id );
    }
    return $new_id;
}

/**
 * Envía el anuncio como notificación a todos los usuarios del negocio
 * (ref_key announcement_{id}): la campana lo muestra y el asistente lo
 * convierte en mensaje normal del chat automáticamente.
 */
function ws_announcement_broadcast( $id ) {
    $ann = ws_announcement_get( $id );
    if ( ! $ann || ! function_exists( 'ws_notification_add' ) ) {
        return;
    }
    $biz   = ( (int) $ann->business_id && class_exists( 'WS_Business' ) ) ? WS_Business::get( (int) $ann->business_id ) : null;
    $link  = ws_panel_url( 'owner', 'anuncios', $biz );
    $title = (string) $ann->title;
    foreach ( ws_announcement_business_users( (int) $ann->business_id ) as $uid ) {
        $user_biz = ws_user_business( $uid );
        ws_notification_add( $uid, 'announcement', $title, mb_substr( (string) $ann->message, 0, 240 ), $link, 'announcement_' . (int) $id, $user_biz );
    }
}

/**
 * Cambia un campo booleano (active o pinned) de un anuncio.
 */
function ws_announcement_toggle( $id, $field ) {
    global $wpdb;
    $field = in_array( $field, array( 'active', 'pinned' ), true ) ? $field : 'active';
    return (bool) $wpdb->query( $wpdb->prepare(
        'UPDATE ' . ws_announcements_table() . " SET {$field} = 1 - {$field} WHERE id=%d",
        (int) $id
    ) );
}

/**
 * Elimina un anuncio.
 */
function ws_announcement_delete( $id ) {
    global $wpdb;
    return (bool) $wpdb->delete( ws_announcements_table(), array( 'id' => (int) $id ), array( '%d' ) );
}
