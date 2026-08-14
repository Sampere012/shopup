<?php
/**
 * Anuncios (ShopUp → Anuncios).
 *
 * Dos alcances:
 *  - 'business' (dueño del negocio): mensajes y notificaciones ancladas para
 *    TODOS los usuarios de SU negocio (dueños, almaceneros y vendedores). El
 *    badge y los banners solo se ven dentro de ese negocio.
 *  - 'site' (admin del sistema): notificaciones para todos los usuarios de
 *    negocio de TODOS los negocios; si están ancladas, el banner aparece en
 *    todos los paneles y en la portada (landing) de cada negocio.
 *
 * Cada anuncio activo:
 *  - Se entrega como notificación a cada destinatario (campana + el asistente
 *    lo muestra como mensaje normal del chat automáticamente).
 *  - Si está anclado (pinned), aparece como banner destacado en el panel.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

function ws_announcement_normalize_datetime( $value, $fallback = null ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return $fallback;
    }
    if ( preg_match( '/^\d+$/', $value ) ) {
        return gmdate( 'Y-m-d H:i:s', (int) $value );
    }
    $ts = strtotime( $value );
    if ( false === $ts ) {
        return $fallback;
    }
    return gmdate( 'Y-m-d H:i:s', $ts );
}

function ws_announcement_is_visible( $ann ) {
    if ( ! $ann ) {
        return false;
    }
    if ( ! (int) ( $ann->active ?? 0 ) ) {
        return false;
    }
    $now = current_time( 'mysql' );
    if ( ! empty( $ann->show_from ) && $now < $ann->show_from ) {
        return false;
    }
    if ( ! empty( $ann->show_until ) && $now > $ann->show_until ) {
        return false;
    }
    if ( ! empty( $ann->pinned_until ) && ! empty( $ann->pinned ) ) {
        if ( $ann->pinned_until < $now ) {
            return false;
        }
    }
    return true;
}

function ws_announcement_can_close( $ann ) {
    if ( ! $ann ) {
        return true;
    }
    // El admin del sistema siempre puede cerrar (y es quien crea los del sitio).
    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }
    // Solo el CREADOR del anuncio puede cerrar su banner: los trabajadores y
    // otros dueños no pueden quitarlo de la vista, lo cierra quien lo envió.
    if ( (int) ( $ann->created_by ?? 0 ) !== get_current_user_id() ) {
        return false;
    }
    // El creador tampoco si marcó el anuncio fijo como no cerrable.
    if ( ! empty( $ann->pinned ) && (int) ( $ann->dismissible ?? 1 ) === 0 ) {
        return false;
    }
    return true;
}

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
 * ¿Puede el usuario crear anuncios de alcance global (todo el sitio)?
 * Solo el administrador del sistema.
 */
function ws_announcement_can_site() {
    return current_user_can( 'manage_options' );
}

/**
 * ¿Puede el usuario gestionar un anuncio concreto? El admin puede con todos;
 * el dueño solo con los de su negocio (nunca con los globales del sitio).
 */
function ws_announcement_manage_can( $ann ) {
    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }
    if ( $ann && 'site' === (string) $ann->scope ) {
        return false;
    }
    return 'owner' === ws_user_role();
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
 * Anuncios del negocio (alcance 'business'): solo los que el dueño envió a
 * su propio negocio. Anclados primero.
 */
function ws_announcements_for_business( $biz_id = 0 ) {
    global $wpdb;
    if ( ! $biz_id ) {
        $biz_id = ws_current_business_id();
    }
    return $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . ws_announcements_table() . " WHERE business_id=%d AND scope='business' ORDER BY pinned DESC, id DESC",
        (int) $biz_id
    ) );
}

/**
 * Anuncios globales del sitio (alcance 'site'), creados por el admin.
 */
function ws_announcements_site() {
    global $wpdb;
    return $wpdb->get_results(
        'SELECT * FROM ' . ws_announcements_table() . " WHERE scope='site' ORDER BY pinned DESC, id DESC"
    );
}

/**
 * Anuncios que ve el panel de Anuncios (todos los usuarios con acceso):
 * los de su negocio actual más los globales del sitio. Los globales solo los
 * gestiona el admin (el dueño los ve como información).
 */
function ws_announcements_panel( $biz_id = 0 ) {
    global $wpdb;
    if ( ! $biz_id ) {
        $biz_id = ws_current_business_id();
    }
    return $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . ws_announcements_table() . ' WHERE business_id=%d OR scope=%s ORDER BY pinned DESC, id DESC',
        (int) $biz_id, 'site'
    ) );
}

/**
 * Anuncios anclados y activos (banners): los del negocio actual más los
 * globales del sitio, que se ven en todos los paneles.
 */
function ws_announcements_pinned( $biz_id = 0 ) {
    global $wpdb;
    if ( ! $biz_id ) {
        $biz_id = ws_current_business_id();
    }
    return $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . ws_announcements_table() . " WHERE active=1 AND pinned=1 AND ( business_id=%d OR scope='site' ) ORDER BY (scope='site') DESC, id DESC",
        (int) $biz_id
    ) );
}

/**
 * Crea o actualiza un anuncio. Al crearlo (activo) lo envía a todos los
 * destinatarios como notificación.
 */
function ws_announcement_save( $data, $id = 0 ) {
    global $wpdb;
    $table = ws_announcements_table();

    $scope = in_array( (string) ( $data['scope'] ?? '' ), array( 'business', 'site' ), true ) ? sanitize_key( $data['scope'] ) : 'business';
    if ( 'site' === $scope && ! ws_announcement_can_site() ) {
        $scope = 'business';
    }

    $pinned = ! empty( $data['pinned'] ) ? 1 : 0;
    $dismissible = ! array_key_exists( 'dismissible', $data ) ? 1 : ( ! empty( $data['dismissible'] ) ? 1 : 0 );
    $pinned_until = $pinned ? ws_announcement_normalize_datetime( (string) ( $data['pinned_until'] ?? '' ) ) : null;
    if ( $pinned && ! $pinned_until && isset( $data['pinned_days'] ) ) {
        $days = max( 1, (int) $data['pinned_days'] );
        $pinned_until = gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
    }
    $show_from = ws_announcement_normalize_datetime( (string) ( $data['show_from'] ?? '' ) );
    $show_until = ws_announcement_normalize_datetime( (string) ( $data['show_until'] ?? '' ) );

    $row = array(
        'scope'        => $scope,
        'business_id'  => 'site' === $scope ? 0 : (int) ( $data['business_id'] ?? ws_current_business_id() ),
        'title'        => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
        'message'      => sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ),
        'type'         => in_array( (string) ( $data['type'] ?? '' ), array( 'info', 'success', 'warning', 'danger' ), true ) ? sanitize_key( $data['type'] ) : 'info',
        'pinned'       => $pinned,
        'dismissible'  => $dismissible,
        'pinned_until' => $pinned_until,
        'show_from'    => $show_from,
        'show_until'   => $show_until,
        'active'       => array_key_exists( 'active', $data ) ? (int) ( bool ) $data['active'] : 1,
    );
    if ( '' === trim( $row['title'] ) ) {
        return 0;
    }

    if ( $id ) {
        $current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $id ) );
        if ( ! $current || ! ws_announcement_manage_can( $current ) ) {
            return 0;
        }
        $row['scope'] = ws_announcement_can_site() ? $row['scope'] : 'business';
        $row['business_id'] = 'site' === $row['scope'] ? 0 : (int) $current->business_id;
        if ( ! array_key_exists( 'active', $data ) ) {
            $row['active'] = (int) $current->active;
        }
        $wpdb->update( $table, $row, array( 'id' => (int) $id ), array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d' ), array( '%d' ) );
        return (int) $id;
    }

    $row['created_by'] = get_current_user_id();
    $wpdb->insert( $table, $row, array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d' ) );
    $new_id = (int) $wpdb->insert_id;
    if ( $new_id && ! empty( $row['active'] ) ) {
        ws_announcement_broadcast( $new_id );
    }
    return $new_id;
}

/**
 * Envía el anuncio como notificación a todos los destinatarios
 * (ref_key announcement_{id}): la campana lo muestra y el asistente lo
 * convierte en mensaje normal del chat automáticamente.
 */
function ws_announcement_broadcast( $id ) {
    $ann = ws_announcement_get( $id );
    if ( ! $ann || ! function_exists( 'ws_notification_add' ) ) {
        return;
    }
    $link  = ( 'site' === (string) $ann->scope )
        ? home_url( '/' )
        : ws_panel_url( 'owner', 'anuncios', ( (int) $ann->business_id && class_exists( 'WS_Business' ) ) ? WS_Business::get( (int) $ann->business_id ) : null );
    $title = (string) $ann->title;
    $msg   = mb_substr( (string) $ann->message, 0, 240 );
    if ( 'site' === (string) $ann->scope ) {
        // Global del sitio: a todos los usuarios de negocio de TODOS los negocios.
        foreach ( ws_announcement_all_business_users() as $uid ) {
            ws_notification_add( $uid, 'announcement', $title, $msg, $link, 'announcement_' . (int) $id, ws_user_business( $uid ) );
        }
        return;
    }
    foreach ( ws_announcement_business_users( (int) $ann->business_id ) as $uid ) {
        ws_notification_add( $uid, 'announcement', $title, $msg, $link, 'announcement_' . (int) $id, ws_user_business( $uid ) );
    }
}

/**
 * Todos los usuarios de negocio del sitio (dueños, almaceneros y vendedores)
 * para los anuncios globales del admin.
 */
function ws_announcement_all_business_users() {
    $users = get_users( array(
        'role__in' => array( 'ws_owner', 'ws_storekeeper', 'ws_seller' ),
        'fields'   => 'ID',
    ) );
    return array_values( array_unique( array_map( 'intval', $users ) ) );
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
