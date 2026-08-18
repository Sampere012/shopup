<?php
/**
 * Integración con wp-admin: página de permisos por rol de negocio.
 *
 * La matriz de permisos de los roles dueño/almacenero/vendedor se gestiona
 * aquí (solo administradores del sitio). Esta página sustituye al módulo
 * "Permisos" del panel del dueño, que quedó restringido al admin.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'ws_admin_menu' );
function ws_admin_menu() {
    add_menu_page(
        __( 'ShopUp', 'workshop' ),
        __( 'ShopUp', 'workshop' ),
        'manage_options',
        'ws-permissions',
        'ws_admin_page_permissions',
        'dashicons-store',
        4
    );
}

function ws_admin_page_permissions() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $saved = false;
    $biz_id = (int) ( $_POST['biz_id'] ?? $_GET['biz_id'] ?? 0 );
    if ( isset( $_POST['ws_permissions_nonce'] ) && wp_verify_nonce( $_POST['ws_permissions_nonce'], 'ws_save_permissions_admin' ) ) {
        $matrix = isset( $_POST['matrix'] ) && is_array( $_POST['matrix'] ) ? $_POST['matrix'] : array();
        WS_Capabilities::save_matrix( $matrix, $biz_id );
        if ( function_exists( 'ws_log_audit' ) ) {
            ws_log_audit( 'permissions_update', 'settings', $biz_id );
        }
        $saved = true;
    }

    $businesses = class_exists( 'WS_Business' ) ? WS_Business::all() : array();
    if ( ! $biz_id ) {
        $biz_id = ws_current_business_id();
    }
    $matrix = WS_Capabilities::matrix( $biz_id );
    $caps   = WS_Capabilities::all_caps();
    $roles  = array(
        'owner'       => __( 'Dueño', 'workshop' ),
        'storekeeper' => __( 'Almacenero', 'workshop' ),
        'seller'      => __( 'Vendedor', 'workshop' ),
    );
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-shield-alt" style="vertical-align:middle"></span> <?php esc_html_e( 'Permisos de los roles del negocio', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Define qué puede hacer cada rol (dueño, almacenero y vendedor) en el panel de ShopUp.', 'workshop' ); ?></p>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Permisos guardados.', 'workshop' ); ?></p></div>
        <?php endif; ?>

        <?php if ( count( $businesses ) > 1 ) : ?>
            <form method="get" action="" style="margin:12px 0">
                <input type="hidden" name="page" value="ws-permissions">
                <label for="ws-perm-biz"><?php esc_html_e( 'Negocio:', 'workshop' ); ?></label>
                <select name="biz_id" id="ws-perm-biz" onchange="this.form.submit()">
                    <?php foreach ( $businesses as $b ) : ?>
                        <option value="<?php echo (int) $b->id; ?>" <?php selected( $biz_id, (int) $b->id ); ?>><?php echo esc_html( $b->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="button"><?php esc_html_e( 'Ir', 'workshop' ); ?></button></noscript>
            </form>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'ws_save_permissions_admin', 'ws_permissions_nonce' ); ?>
            <input type="hidden" name="biz_id" value="<?php echo (int) $biz_id; ?>">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Módulo / Permiso', 'workshop' ); ?></th>
                        <?php foreach ( $roles as $key => $label ) : ?>
                            <th style="text-align:center"><?php echo esc_html( $label ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $caps as $cap => $label ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $label ); ?></strong><br><code><?php echo esc_html( $cap ); ?></code></td>
                            <?php foreach ( array_keys( $roles ) as $rkey ) : ?>
                                <td style="text-align:center">
                                    <?php
                                    $checked = ! empty( $matrix[ $rkey ][ $cap ] );
                                    if ( 'permissions_manage' === $cap ) {
                                        // Exclusiva del administrador del sistema.
                                        echo '<span class="dashicons dashicons-lock" title="' . esc_attr__( 'Solo el administrador del sistema', 'workshop' ) . '"></span>';
                                    } else {
                                        ?>
                                        <input type="checkbox" name="matrix[<?php echo esc_attr( $rkey ); ?>][<?php echo esc_attr( $cap ); ?>]" value="1" <?php checked( $checked ); ?>>
                                        <?php
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button( __( 'Guardar permisos', 'workshop' ) ); ?>
        </form>
    </div>
    <?php
}

add_action( 'admin_menu', 'ws_announcements_admin_menu', 20 );
function ws_announcements_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Anuncios', 'workshop' ),
        __( 'Anuncios', 'workshop' ),
        'manage_options',
        'ws-announcements',
        'ws_admin_page_announcements'
    );
}

function ws_admin_page_announcements() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $notice = '';
    if ( isset( $_POST['ws_announcement_nonce'] ) && wp_verify_nonce( $_POST['ws_announcement_nonce'], 'ws_manage_announcements' ) ) {
        $ann_action = sanitize_key( (string) ( $_POST['ws_ann_action'] ?? '' ) );
        if ( 'toggle' === $ann_action && function_exists( 'ws_announcement_toggle' ) ) {
            $ann_field = in_array( (string) ( $_POST['ann_field'] ?? '' ), array( 'pinned', 'active' ), true ) ? sanitize_key( $_POST['ann_field'] ) : 'active';
            ws_announcement_toggle( (int) ( $_POST['ann_id'] ?? 0 ), $ann_field );
            $notice = array( 'success', __( 'Anuncio actualizado.', 'workshop' ) );
        } elseif ( 'delete' === $ann_action && function_exists( 'ws_announcement_delete' ) ) {
            ws_announcement_delete( (int) ( $_POST['ann_id'] ?? 0 ) );
            $notice = array( 'success', __( 'Anuncio eliminado.', 'workshop' ) );
        } else {
            $payload = array(
                'title'       => $_POST['title'] ?? '',
                'message'     => $_POST['message'] ?? '',
                'type'        => sanitize_key( $_POST['type'] ?? 'info' ),
                'scope'       => sanitize_key( $_POST['scope'] ?? 'business' ),
                'business_id' => (int) ( $_POST['business_id'] ?? ws_current_business_id() ),
                'pinned'      => ! empty( $_POST['pinned'] ) ? 1 : 0,
                'dismissible' => ! empty( $_POST['dismissible'] ) ? 1 : 0,
                'pinned_days' => (int) ( $_POST['pinned_days'] ?? 7 ),
                'show_from'   => $_POST['show_from'] ?? '',
                'show_until'  => $_POST['show_until'] ?? '',
                'active'      => ! empty( $_POST['active'] ) ? 1 : 1,
            );
            $id = ws_announcement_save( $payload, (int) ( $_POST['ann_id'] ?? 0 ) );
            if ( $id ) {
                $notice = array( 'success', __( 'Anuncio guardado correctamente.', 'workshop' ) );
            } else {
                $notice = array( 'error', __( 'El título es obligatorio para guardar el anuncio.', 'workshop' ) );
            }
        }
    }

    $rows = function_exists( 'ws_announcements_site' ) ? ws_announcements_site() : array();
    $businesses = class_exists( 'WS_Business' ) ? WS_Business::all() : array();
    $ann_types = array(
        'info'    => array( __( 'Información', 'workshop' ), 'info' ),
        'success' => array( __( 'Éxito', 'workshop' ), 'success' ),
        'warning' => array( __( 'Aviso', 'workshop' ), 'warning' ),
        'danger'  => array( __( 'Urgente', 'workshop' ), 'danger' ),
    );
    $businesses_map = array();
    foreach ( (array) $businesses as $b ) {
        $businesses_map[ (int) $b->id ] = (string) $b->name;
    }
    ?>
    <div class="wrap ws-ann-admin-page">
        <h1><span class="dashicons dashicons-megaphone" style="vertical-align:middle"></span> <?php esc_html_e( 'Anuncios del sitio', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Crea avisos para un negocio concreto o para todos los negocios y la landing, con fechas de activación, anclaje y caducidad.', 'workshop' ); ?></p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
        <?php endif; ?>

        <div class="card" style="margin:20px 0;padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:960px;">
            <h2 style="margin-top:0"><?php esc_html_e( 'Crear anuncio', 'workshop' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'ws_manage_announcements', 'ws_announcement_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ws-ann-title"><?php esc_html_e( 'Título', 'workshop' ); ?> *</label></th>
                        <td><input id="ws-ann-title" type="text" name="title" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws-ann-message"><?php esc_html_e( 'Mensaje', 'workshop' ); ?></label></th>
                        <td><textarea id="ws-ann-message" name="message" rows="4" class="large-text"></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws-ann-type"><?php esc_html_e( 'Tipo', 'workshop' ); ?></label></th>
                        <td>
                            <select id="ws-ann-type" name="type">
                                <option value="info"><?php esc_html_e( 'Información', 'workshop' ); ?></option>
                                <option value="success"><?php esc_html_e( 'Éxito', 'workshop' ); ?></option>
                                <option value="warning"><?php esc_html_e( 'Aviso', 'workshop' ); ?></option>
                                <option value="danger"><?php esc_html_e( 'Urgente', 'workshop' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws-ann-scope"><?php esc_html_e( 'Destino', 'workshop' ); ?></label></th>
                        <td>
                            <select id="ws-ann-scope" name="scope">
                                <option value="business"><?php esc_html_e( 'Un negocio concreto', 'workshop' ); ?></option>
                                <option value="site"><?php esc_html_e( 'Todo el sitio / landing', 'workshop' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws-ann-business"><?php esc_html_e( 'Negocio', 'workshop' ); ?></label></th>
                        <td>
                            <select id="ws-ann-business" name="business_id">
                                <?php foreach ( $businesses as $b ) : ?>
                                    <option value="<?php echo (int) $b->id; ?>"><?php echo esc_html( $b->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Fijar', 'workshop' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="pinned" value="1"> <?php esc_html_e( 'Mostrar como banner fijo en el panel o landing', 'workshop' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws-ann-pinned-days"><?php esc_html_e( 'Duración fija (días)', 'workshop' ); ?></label></th>
                        <td><input id="ws-ann-pinned-days" type="number" name="pinned_days" min="1" max="365" value="7" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws-ann-show-from"><?php esc_html_e( 'Mostrar desde', 'workshop' ); ?></label></th>
                        <td><input id="ws-ann-show-from" type="datetime-local" name="show_from" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ws-ann-show-until"><?php esc_html_e( 'Mostrar hasta', 'workshop' ); ?></label></th>
                        <td><input id="ws-ann-show-until" type="datetime-local" name="show_until" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cierre', 'workshop' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="dismissible" value="1" checked> <?php esc_html_e( 'Los usuarios pueden cerrarlo', 'workshop' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Si lo desmarcas, solo el admin podrá cerrarlo en los banners fijos.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Activo', 'workshop' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="active" value="1" checked> <?php esc_html_e( 'Activar inmediatamente', 'workshop' ); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Guardar anuncio', 'workshop' ) ); ?>
            </form>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <div class="ws-ann-empty">
                <span class="dashicons dashicons-megaphone"></span>
                <h2><?php esc_html_e( 'Todavía no hay anuncios del sitio.', 'workshop' ); ?></h2>
                <p><?php esc_html_e( 'Cuando crees un anuncio con destino "Todo el sitio / landing" aparecerá aquí con su programación, su fijado y sus acciones.', 'workshop' ); ?></p>
            </div>
        <?php else : ?>
            <h2><?php esc_html_e( 'Anuncios del sitio', 'workshop' ); ?> <span class="ws-ann-count"><?php echo count( $rows ); ?></span></h2>
            <table class="widefat striped ws-ann-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Título', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Tipo', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Destino', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Fijado', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Activo', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Vigencia', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Creado por', 'workshop' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $a ) :
                        $a_type    = $ann_types[ $a->type ][0] ?? $a->type;
                        $a_type_cl = isset( $ann_types[ $a->type ] ) ? $a->type : 'info';
                        $a_biz     = (int) $a->business_id;
                        $a_biz_name = 'site' === (string) $a->scope
                            ? __( 'Todo el sitio', 'workshop' )
                            : ( $a_biz && isset( $businesses_map[ $a_biz ] ) ? $businesses_map[ $a_biz ] : '#' . $a_biz );
                        $a_by_user = $a->created_by ? get_userdata( (int) $a->created_by ) : null;
                        $a_by      = $a_by_user ? $a_by_user->display_name : ( $a->created_by ? '#' . (int) $a->created_by : '—' );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $a->title ); ?></strong>
                                <div class="ws-ann-msg"><?php echo esc_html( wp_trim_words( (string) $a->message, 12, '…' ) ); ?></div>
                            </td>
                            <td><span class="ws-ann-badge ws-ann-badge-<?php echo esc_attr( $a_type_cl ); ?>"><?php echo esc_html( $a_type ); ?></span></td>
                            <td><?php echo esc_html( $a_biz_name ); ?></td>
                            <td>
                                <?php if ( ! empty( $a->pinned ) ) : ?>
                                    <span class="dashicons dashicons-admin-post ws-ann-ico-pin" title="<?php echo esc_attr( $a->pinned_until ? sprintf( __( 'Fijado hasta %s', 'workshop' ), $a->pinned_until ) : __( 'Fijado', 'workshop' ) ); ?>"></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-minus ws-ann-ico-off"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $a->active ) ) : ?>
                                    <span class="dashicons dashicons-visibility ws-ann-ico-on" title="<?php esc_attr_e( 'Visible', 'workshop' ); ?>"></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-hidden ws-ann-ico-off" title="<?php esc_attr_e( 'Oculto', 'workshop' ); ?>"></span>
                                <?php endif; ?>
                            </td>
                            <td class="ws-ann-range">
                                <?php echo esc_html( $a->show_from ? sprintf( __( 'Desde %s', 'workshop' ), $a->show_from ) : __( 'Desde: —', 'workshop' ) ); ?>
                                <br>
                                <?php echo esc_html( $a->show_until ? sprintf( __( 'Hasta %s', 'workshop' ), $a->show_until ) : __( 'Hasta: —', 'workshop' ) ); ?>
                                <?php if ( ! empty( $a->pinned_until ) ) : ?>
                                    <br><em><?php echo esc_html( sprintf( __( 'Fijo hasta %s', 'workshop' ), $a->pinned_until ) ); ?></em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $a_by ); ?></td>
                            <td class="ws-ann-actions">
                                <form method="post" class="ws-ann-inline">
                                    <?php wp_nonce_field( 'ws_manage_announcements', 'ws_announcement_nonce' ); ?>
                                    <input type="hidden" name="ws_ann_action" value="toggle">
                                    <input type="hidden" name="ann_id" value="<?php echo (int) $a->id; ?>">
                                    <input type="hidden" name="ann_field" value="pinned">
                                    <button class="button ws-ann-btn" title="<?php echo ! empty( $a->pinned ) ? esc_attr__( 'Desfijar', 'workshop' ) : esc_attr__( 'Fijar como banner', 'workshop' ); ?>">
                                        <span class="dashicons <?php echo ! empty( $a->pinned ) ? 'dashicons-admin-post' : 'dashicons-megaphone'; ?>"></span>
                                    </button>
                                </form>
                                <form method="post" class="ws-ann-inline">
                                    <?php wp_nonce_field( 'ws_manage_announcements', 'ws_announcement_nonce' ); ?>
                                    <input type="hidden" name="ws_ann_action" value="toggle">
                                    <input type="hidden" name="ann_id" value="<?php echo (int) $a->id; ?>">
                                    <input type="hidden" name="ann_field" value="active">
                                    <button class="button ws-ann-btn" title="<?php echo ! empty( $a->active ) ? esc_attr__( 'Desactivar', 'workshop' ) : esc_attr__( 'Activar', 'workshop' ); ?>">
                                        <span class="dashicons <?php echo ! empty( $a->active ) ? 'dashicons-visibility' : 'dashicons-hidden'; ?>"></span>
                                    </button>
                                </form>
                                <form method="post" class="ws-ann-inline" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar este anuncio?', 'workshop' ) ); ?>');">
                                    <?php wp_nonce_field( 'ws_manage_announcements', 'ws_announcement_nonce' ); ?>
                                    <input type="hidden" name="ws_ann_action" value="delete">
                                    <input type="hidden" name="ann_id" value="<?php echo (int) $a->id; ?>">
                                    <button class="button ws-ann-btn ws-ann-btn-danger" title="<?php esc_attr_e( 'Eliminar', 'workshop' ); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <style>
        .ws-ann-admin-page .ws-ann-empty{max-width:960px;margin:20px 0;padding:44px 24px;text-align:center;background:#fff;border:1px solid #dcdcde;border-radius:8px}
        .ws-ann-admin-page .ws-ann-empty .dashicons{font-size:44px;width:44px;height:44px;color:#2271b1}
        .ws-ann-admin-page .ws-ann-empty h2{margin:14px 0 6px;font-size:20px}
        .ws-ann-admin-page .ws-ann-empty p{margin:0;color:#646970}
        .ws-ann-admin-page .ws-ann-count{display:inline-block;margin-left:6px;padding:1px 10px;border-radius:10px;background:#2271b1;color:#fff;font-size:12px;vertical-align:middle}
        .ws-ann-admin-page .ws-ann-table{margin-top:12px}
        .ws-ann-table .ws-ann-msg{color:#646970;font-weight:400;font-size:12px}
        .ws-ann-badge{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;line-height:1.7;color:#fff}
        .ws-ann-badge-info{background:#2271b1}.ws-ann-badge-success{background:#00a32a}.ws-ann-badge-warning{background:#dba617}.ws-ann-badge-danger{background:#d63638}
        .ws-ann-table .ws-ann-ico-pin{color:#2271b1}.ws-ann-table .ws-ann-ico-on{color:#00a32a}.ws-ann-table .ws-ann-ico-off{color:#a7aaad}
        .ws-ann-table .ws-ann-range{font-size:12px;color:#50575e}
        .ws-ann-inline{display:inline-block;margin:0 2px 0 0}
        .ws-ann-btn{width:30px;height:30px;padding:0!important;display:inline-flex;align-items:center;justify-content:center}
        .ws-ann-btn .dashicons{font-size:16px;width:16px;height:16px}
        .ws-ann-btn-danger .dashicons{color:#d63638}
    </style>
    <?php
}

add_action( 'admin_menu', 'ws_marketplace_admin_menu', 20 );
function ws_marketplace_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Mercado', 'workshop' ),
        __( 'Mercado', 'workshop' ),
        'manage_options',
        'ws-marketplace',
        'ws_admin_page_marketplace'
    );
}

add_action( 'admin_menu', 'ws_security_admin_menu', 21 );
function ws_security_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Sesión y seguridad', 'workshop' ),
        __( 'Sesión y seguridad', 'workshop' ),
        'manage_options',
        'ws-security',
        'ws_admin_page_security'
    );
}

/**
 * Sesión y seguridad: cuánto dura la sesión de los usuarios.
 *
 * Los usuarios trabajan con la app nativa de Android (la app guarda la cola
 * de sincronización offline) y no deben perder la sesión a mitad de jornada
 * ni al cerrar el navegador: la cookie de acceso se mantiene vigente estos
 * días (aplica al panel y a la tienda).
 * Aquí también se explica el validador de correos del acceso/registro.
 */
function ws_admin_page_security() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $saved = false;
    if ( isset( $_POST['ws_security_nonce'] ) && wp_verify_nonce( $_POST['ws_security_nonce'], 'ws_save_security' ) ) {
        $days = max( 1, min( 365, (int) ( $_POST['session_days'] ?? 30 ) ) );
        update_option( 'ws_session_expiration_days', $days );
        if ( function_exists( 'ws_log_audit' ) ) {
            ws_log_audit( 'security_settings_update', 'settings', $days );
        }
        $saved = true;
    }

    $days = max( 1, min( 365, (int) get_option( 'ws_session_expiration_days', 30 ) ) );
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-lock" style="vertical-align:middle"></span> <?php esc_html_e( 'Sesión y seguridad', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Los usuarios de los negocios trabajan online y offline (la aplicación guarda la cola de sincronización). Para que nadie pierda la sesión a mitad de jornada, la cookie de acceso dura los días que configures aquí. Si el usuario marca «Recuérdame», dura el triple.', 'workshop' ); ?></p>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración de sesión guardada.', 'workshop' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'ws_save_security', 'ws_security_nonce' ); ?>
            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-clock" style="margin-right:6px"></span><?php esc_html_e( 'Duración de la sesión', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ws-session-days"><?php esc_html_e( 'Días de sesión', 'workshop' ); ?></label></th>
                        <td>
                            <input type="number" id="ws-session-days" name="session_days" min="1" max="365" value="<?php echo (int) $days; ?>" class="small-text"> <?php esc_html_e( 'días (1–365)', 'workshop' ); ?>
                            <p class="description"><?php esc_html_e( 'Por defecto 30 días; con «Recuérdame» en el acceso, 90. Los cambios aplican a los próximos inicios de sesión.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php submit_button( __( 'Guardar sesión', 'workshop' ) ); ?>
        </form>

        <div class="ws-mp-admin-group">
            <h2><span class="dashicons dashicons-email-alt" style="margin-right:6px"></span><?php esc_html_e( 'Validador de correos en el acceso', 'workshop' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'El acceso y el registro exigen un correo con formato válido y bloquean los dominios desechables (mailinator, 10minutemail, tempmail…), para más seguridad. La lista es ampliable desde el código (filtro ws_disposable_email_domains).', 'workshop' ); ?>
            </p>
        </div>
    </div>
    <?php
}

function ws_admin_page_marketplace() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $saved = false;
    if ( isset( $_POST['ws_marketplace_nonce'] ) && wp_verify_nonce( $_POST['ws_marketplace_nonce'], 'ws_save_marketplace' ) ) {
        $cur             = get_option( 'ws_marketplace_theme', array() );
        $cur             = is_array( $cur ) ? $cur : array();
        $cur['name']        = sanitize_text_field( $_POST['name'] ?? '' );
        $cur['logo']        = esc_url_raw( (string) ( $_POST['logo'] ?? '' ) );
        $cur['description'] = sanitize_textarea_field( $_POST['description'] ?? '' );
        $cur['primary']     = sanitize_hex_color( (string) ( $_POST['primary'] ?? '' ) );
        $cur['accent']      = sanitize_hex_color( (string) ( $_POST['accent'] ?? '' ) );
        $cur['hero_badge']  = sanitize_text_field( $_POST['hero_badge'] ?? '' );
        $cur['hero_title']  = sanitize_text_field( $_POST['hero_title'] ?? '' );
        $cur['hero_sub']    = sanitize_text_field( $_POST['hero_sub'] ?? '' );
        $cur['hero_bg']     = esc_url_raw( (string) ( $_POST['hero_bg'] ?? '' ) );
        $cur['hero_gradient'] = wp_strip_all_tags( (string) ( $_POST['hero_gradient'] ?? '' ) );
        $cur['footer_text'] = sanitize_text_field( $_POST['footer_text'] ?? '' );
        // Los bloques de contenido solo se actualizan si el editor se envió en
        // el formulario (marcador ws_mp_sections_edited). Así un guardado sin
        // bloques no borra los ya existentes por accidente.
        if ( isset( $_POST['ws_mp_sections_edited'] ) && '1' === $_POST['ws_mp_sections_edited'] ) {
            $sections = array();
            $contents = isset( $_POST['section_content'] ) && is_array( $_POST['section_content'] ) ? $_POST['section_content'] : array();
            foreach ( (array) ( $_POST['section_title'] ?? array() ) as $i => $t ) {
                $title   = sanitize_text_field( $t );
                $content = trim( (string) ( $contents[ $i ] ?? '' ) );
                if ( '' !== $title || '' !== $content ) {
                    $sections[] = array( 'title' => $title, 'content' => $content );
                }
            }
            $cur['sections'] = $sections;
        }
        update_option( 'ws_marketplace_theme', $cur );
        $saved = true;
    }

    $theme = ws_marketplace_theme();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-store" style="vertical-align:middle"></span> <?php esc_html_e( 'Índice del mercado', 'workshop' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Esta es la página principal del sitio (la raíz). Aquí aparecen todos los negocios que tienen acceso al mercado; el cliente elige uno y entra en su tienda. Cada negocio mantiene su propia portada, logo y colores, que configura su dueño.', 'workshop' ); ?>
            <?php echo ' <a target="_blank" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Ver el mercado', 'workshop' ) . ' <span class="dashicons dashicons-external" style="font-size:16px;line-height:inherit"></span></a>'; ?>
        </p>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración del mercado guardada.', 'workshop' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'ws_save_marketplace', 'ws_marketplace_nonce' ); ?>
            <input type="hidden" name="ws_mp_sections_edited" value="1">

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-id" style="margin-right:6px"></span><?php esc_html_e( 'Identidad', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Nombre del mercado', 'workshop' ); ?></label></th>
                        <td>
                            <input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $theme['name'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'blogname' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Se muestra en la cabecera y el pie del índice.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Logo (URL)', 'workshop' ); ?></label></th>
                        <td>
                            <input type="url" name="logo" class="regular-text" value="<?php echo esc_attr( $theme['logo'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Pega la URL de una imagen (o súbela en Medios y copia su enlace).', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Descripción', 'workshop' ); ?></label></th>
                        <td>
                            <textarea name="description" class="large-text" rows="2"><?php echo esc_textarea( $theme['description'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Frase corta que aparece bajo el titular de la portada.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Pie de página', 'workshop' ); ?></label></th>
                        <td>
                            <input type="text" name="footer_text" class="regular-text" value="<?php echo esc_attr( $theme['footer_text'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Texto del pie del índice (deja vacío para el texto por defecto).', 'workshop' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-art" style="margin-right:6px"></span><?php esc_html_e( 'Colores', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Color primario', 'workshop' ); ?></label></th>
                        <td><input type="color" name="primary" value="<?php echo esc_attr( $theme['primary'] ); ?>"> <span class="description" style="vertical-align:middle"><?php esc_html_e( 'Botones y detalles principales del índice.', 'workshop' ); ?></span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Color de acento', 'workshop' ); ?></label></th>
                        <td><input type="color" name="accent" value="<?php echo esc_attr( $theme['accent'] ); ?>"> <span class="description" style="vertical-align:middle"><?php esc_html_e( 'Destacados, estrellas y acentos.', 'workshop' ); ?></span></td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-cover-image" style="margin-right:6px"></span><?php esc_html_e( 'Portada (hero)', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Etiqueta (badge)', 'workshop' ); ?></label></th>
                        <td>
                            <input type="text" name="hero_badge" class="regular-text" value="<?php echo esc_attr( $theme['hero_badge'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Palabra o frase pequeña sobre el titular, ej. "Mercado local".', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Título', 'workshop' ); ?></label></th>
                        <td><input type="text" name="hero_title" class="regular-text" value="<?php echo esc_attr( $theme['hero_title'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Subtítulo', 'workshop' ); ?></label></th>
                        <td><input type="text" name="hero_sub" class="regular-text" value="<?php echo esc_attr( $theme['hero_sub'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Imagen de fondo (URL)', 'workshop' ); ?></label></th>
                        <td>
                            <input type="url" name="hero_bg" class="regular-text" value="<?php echo esc_attr( $theme['hero_bg'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Opcional: imagen que se muestra detrás del titular.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Gradiente CSS', 'workshop' ); ?></label></th>
                        <td>
                            <input type="text" name="hero_gradient" class="regular-text" value="<?php echo esc_attr( $theme['hero_gradient'] ); ?>" placeholder="linear-gradient(...)">
                            <p class="description"><?php esc_html_e( 'Opcional: degradado de fondo si no usas imagen.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-editor-ul" style="margin-right:6px"></span><?php esc_html_e( 'Contenido extra', 'workshop' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Bloques opcionales que se muestran debajo de la lista de negocios: cómo funciona, avisos, enlaces o promociones.', 'workshop' ); ?></p>
                <div id="ws-mp-sections">
                    <?php if ( empty( $theme['sections'] ) ) : ?>
                        <p class="description" id="ws-mp-sections-empty"><?php esc_html_e( 'Aún no hay bloques. Usa "Añadir bloque" para crear el primero.', 'workshop' ); ?></p>
                    <?php endif; ?>
                    <?php foreach ( (array) $theme['sections'] as $i => $s ) : ?>
                        <div class="ws-mp-section" data-ws-mp-index="<?php echo (int) $i; ?>">
                            <div class="ws-mp-section-head">
                                <span class="ws-mp-section-title"><?php echo esc_html( sprintf( __( 'Bloque %d', 'workshop' ), (int) $i + 1 ) ); ?></span>
                                <button type="button" class="button button-link-delete" onclick="this.closest('.ws-mp-section').remove()"><?php esc_html_e( 'Quitar', 'workshop' ); ?></button>
                            </div>
                            <label class="ws-mp-field">
                                <span><?php esc_html_e( 'Título', 'workshop' ); ?></span>
                                <input type="text" name="section_title[]" class="regular-text" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php esc_attr_e( 'Ej: ¿Cómo funciona?', 'workshop' ); ?>">
                            </label>
                            <label class="ws-mp-field">
                                <span><?php esc_html_e( 'Contenido', 'workshop' ); ?></span>
                                <textarea name="section_content[]" class="large-text" rows="4" placeholder="<?php esc_attr_e( 'Texto o HTML de este bloque.', 'workshop' ); ?>"><?php echo esc_textarea( $s['content'] ); ?></textarea>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p>
                    <button type="button" class="button" id="ws-mp-add-section"><?php esc_html_e( 'Añadir bloque', 'workshop' ); ?></button>
                </p>
            </div>

            <?php submit_button( __( 'Guardar mercado', 'workshop' ) ); ?>
        </form>
    </div>
    <style>
        .ws-mp-admin-group { background: #fff; border: 1px solid #c3c4c7; padding: 8px 20px 16px; margin: 0 0 18px; border-radius: 6px; }
        .ws-mp-admin-group h2 { font-size: 15px; padding-top: 12px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; }
        .ws-mp-section { border: 1px solid #c3c4c7; padding: 12px 14px; margin: 10px 0; background: #f8f9fa; border-radius: 6px; }
        .ws-mp-section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .ws-mp-section-title { font-weight: 600; color: #2c3338; }
        .ws-mp-field { display: block; margin: 8px 0; }
        .ws-mp-field > span { display: block; font-weight: 600; margin-bottom: 4px; color: #50575e; }
    </style>
    <script>
        (function () {
            var box = document.getElementById('ws-mp-sections');
            var btn = document.getElementById('ws-mp-add-section');
            if (!box || !btn) { return; }
            btn.addEventListener('click', function () {
                var empty = document.getElementById('ws-mp-sections-empty');
                if (empty) { empty.remove(); }
                var n = box.querySelectorAll('.ws-mp-section').length + 1;
                var d = document.createElement('div');
                d.className = 'ws-mp-section';
                d.innerHTML =
                    '<div class="ws-mp-section-head">'
                    + '<span class="ws-mp-section-title"><?php echo esc_js( __( 'Bloque', 'workshop' ) ); ?> ' + n + '</span>'
                    + '<button type="button" class="button button-link-delete" onclick="this.closest(\'.ws-mp-section\').remove()"><?php echo esc_js( __( 'Quitar', 'workshop' ) ); ?></button>'
                    + '</div>'
                    + '<label class="ws-mp-field"><span><?php echo esc_js( __( 'Título', 'workshop' ) ); ?></span>'
                    + '<input type="text" name="section_title[]" class="regular-text" placeholder="<?php echo esc_js( __( 'Ej: ¿Cómo funciona?', 'workshop' ) ); ?>"></label>'
                    + '<label class="ws-mp-field"><span><?php echo esc_js( __( 'Contenido', 'workshop' ) ); ?></span>'
                    + '<textarea name="section_content[]" class="large-text" rows="4" placeholder="<?php echo esc_js( __( 'Texto o HTML de este bloque.', 'workshop' ) ); ?>"></textarea></label>';
                box.appendChild(d);
                d.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Contenido del sitio: la plantilla está conectada con WordPress
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'ws_site_content_admin_menu', 25 );
function ws_site_content_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Contenido del sitio', 'workshop' ),
        __( 'Contenido del sitio', 'workshop' ),
        'manage_options',
        'ws-site-content',
        'ws_admin_page_site_content'
    );
}

function ws_admin_page_site_content() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }
    $cards = array(
        array(
            'icon'  => 'dashicons-admin-page',
            'title' => __( 'Páginas', 'workshop' ),
            'desc'  => __( 'Crea páginas del sitio (promociones, términos, políticas…) con el editor de WordPress: textos, fotos y bloques. Se publican en tu dominio con el diseño de la plantilla.', 'workshop' ),
            'url'   => admin_url( 'edit.php?post_type=page' ),
            'label' => __( 'Editar páginas', 'workshop' ),
        ),
        array(
            'icon'  => 'dashicons-admin-post',
            'title' => __( 'Entradas (blog)', 'workshop' ),
            'desc'  => __( 'Escribe noticias o anuncios del sitio. Para mostrar el listado crea una página y asígnala en Ajustes → Portada como «página de entradas».', 'workshop' ),
            'url'   => admin_url( 'edit.php' ),
            'label' => __( 'Editar entradas', 'workshop' ),
        ),
        array(
            'icon'  => 'dashicons-menu',
            'title' => __( 'Menús', 'workshop' ),
            'desc'  => __( 'El menú superior (navbar) de la plantilla se edita aquí. Asigna un menú a la ubicación «Menú principal» y añade tus páginas o enlaces personalizados.', 'workshop' ),
            'url'   => admin_url( 'nav-menus.php' ),
            'label' => __( 'Editar menús', 'workshop' ),
        ),
        array(
            'icon'  => 'dashicons-edit-page',
            'title' => __( 'Páginas y pie (Ayuda, Contacto, Acerca)', 'workshop' ),
            'desc'  => __( 'Edita los textos de las páginas Ayuda, Contacto y Acerca de nosotros, las preguntas frecuentes, las columnas del pie y las redes sociales.', 'workshop' ),
            'url'   => admin_url( 'admin.php?page=ws-site-pages' ),
            'label' => __( 'Abrir Páginas y pie', 'workshop' ),
        ),
        array(
            'icon'  => 'dashicons-admin-customizer',
            'title' => __( 'Apariencia del sitio', 'workshop' ),
            'desc'  => __( 'Logo, colores y textos del índice (mercado). El logo y colores de cada negocio los configura su dueño desde el panel.', 'workshop' ),
            'url'   => admin_url( 'customize.php' ),
            'label' => __( 'Personalizar', 'workshop' ),
        ),
    );
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-layout" style="vertical-align:middle"></span> <?php esc_html_e( 'Contenido del sitio', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Tu plantilla está conectada con WordPress: crea y edita páginas, entradas, menús e imágenes con las herramientas normales de WordPress y todo se publica en el sitio con el diseño de la plantilla. No necesitas saber de código ni instalar plugins.', 'workshop' ); ?></p>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-top:18px">
            <?php foreach ( $cards as $card ) : ?>
                <div class="ws-mp-admin-group" style="display:flex;flex-direction:column;gap:8px;margin:0">
                    <h2 style="border:0;padding:0;margin:0;display:flex;align-items:center;gap:8px">
                        <span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" style="color:#4f46e5"></span>
                        <?php echo esc_html( $card['title'] ); ?>
                    </h2>
                    <p style="margin:0;color:#50575e;flex:1"><?php echo esc_html( $card['desc'] ); ?></p>
                    <a class="button button-primary" href="<?php echo esc_url( $card['url'] ); ?>"><?php echo esc_html( $card['label'] ); ?> <span class="dashicons dashicons-external" style="font-size:14px;line-height:inherit;margin-left:2px"></span></a>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="ws-mp-admin-group" style="margin-top:18px">
            <h2><span class="dashicons dashicons-info" style="margin-right:6px"></span><?php esc_html_e( 'Cómo publicar contenido', 'workshop' ); ?></h2>
            <ol style="margin:0;padding-left:20px;line-height:1.9">
                <li><?php esc_html_e( 'Crea una página en Páginas, escribe el texto, añade fotos desde la biblioteca de medios y pulsa Publicar.', 'workshop' ); ?></li>
                <li><?php esc_html_e( 'Añádela al menú superior desde Menús (ubicación «Menú principal») o enlázala desde el pie en Páginas y pie.', 'workshop' ); ?></li>
                <li><?php esc_html_e( 'Para un blog: crea una página «Blog» y asígnala en Ajustes → Portada → Página de entradas.', 'workshop' ); ?></li>
            </ol>
        </div>
    </div>
    <?php
}
