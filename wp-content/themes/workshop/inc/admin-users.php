<?php
/**
 * wp-admin: página de accesos (usuarios ↔ negocio).
 *
 * Permite al administrador del sitio crear usuarios y asociar cada uno a un
 * negocio (y su rol), de forma que su acceso de panel sea la URL de ese
 * negocio: /{slug}/panel/{rol}/ (o /panel/{rol}/ para el negocio por defecto).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Perfil de usuario: número de WhatsApp del administrador (planes/soporte)
 * ---------------------------------------------------------------------- */

add_action( 'show_user_profile', 'ws_user_whatsapp_profile_field' );
add_action( 'edit_user_profile', 'ws_user_whatsapp_profile_field' );
function ws_user_whatsapp_profile_field( $user ) {
    // Solo para administradores del sitio: su número recibe las solicitudes
    // de plan que envían los negocios por WhatsApp.
    if ( ! user_can( $user, 'manage_options' ) ) {
        return;
    }
    $value = get_user_meta( $user->ID, 'ws_whatsapp', true );
    ?>
    <h2><?php esc_html_e( 'WhatsApp (ShopUp)', 'workshop' ); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="ws_whatsapp"><?php esc_html_e( 'Número de WhatsApp', 'workshop' ); ?></label></th>
            <td>
                <input type="text" id="ws_whatsapp" name="ws_whatsapp" class="regular-text" value="<?php echo esc_attr( $value ); ?>" placeholder="+58 412 123 4567">
                <p class="description"><?php esc_html_e( 'Aquí recibirás por WhatsApp las solicitudes de cambio de plan que envían los negocios (botón «Confirmar por WhatsApp» del panel). Si lo dejas vacío se usará el número de Ajustes → WhatsApp del negocio por defecto.', 'workshop' ); ?></p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'ws_user_whatsapp_profile_save' );
add_action( 'edit_user_profile_update', 'ws_user_whatsapp_profile_save' );
function ws_user_whatsapp_profile_save( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    update_user_meta( (int) $user_id, 'ws_whatsapp', sanitize_text_field( $_POST['ws_whatsapp'] ?? '' ) );
}

add_action( 'admin_menu', 'ws_users_admin_menu', 20 );
function ws_users_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Accesos (usuarios)', 'workshop' ),
        __( 'Accesos', 'workshop' ),
        'manage_options',
        'ws-users',
        'ws_admin_page_users'
    );
}

/**
 * Rol de negocio (ws_*) que puede tener un usuario en esta página.
 */
function ws_users_allowed_roles() {
    return array(
        'ws_owner'       => __( 'Dueño del negocio', 'workshop' ),
        'ws_storekeeper' => __( 'Almacenero', 'workshop' ),
        'ws_seller'      => __( 'Vendedor/PV', 'workshop' ),
    );
}

/**
 * Nº de dueños de un negocio (incluye legacy del negocio por defecto).
 */
function ws_admin_owners_count( $biz_id, $exclude_user_id = 0 ) {
    $ids = get_users( array(
        'role'       => 'ws_owner',
        'fields'     => 'ID',
        'meta_key'   => 'ws_business_id',
        'meta_value' => (int) $biz_id,
    ) );
    $ids = array_map( 'intval', $ids );
    if ( WS_Business::is_default_id( (int) $biz_id ) ) {
        $ids = array_merge( $ids, get_users( array(
            'role'         => 'ws_owner',
            'fields'       => 'ID',
            'meta_key'     => 'ws_business_id',
            'meta_compare' => 'NOT EXISTS',
        ) ) );
    }
    $ids = array_unique( array_map( 'intval', $ids ) );
    if ( $exclude_user_id ) {
        $ids = array_values( array_diff( $ids, array( (int) $exclude_user_id ) ) );
    }
    return count( $ids );
}

/**
 * Asigna rol de negocio y negocio a un usuario existente.
 */
function ws_admin_set_user_business( $user_id, $biz_id, $role ) {
    $user = get_user_by( 'id', (int) $user_id );
    if ( ! $user ) {
        return new WP_Error( 'user', __( 'Usuario no encontrado.', 'workshop' ) );
    }
    $biz = WS_Business::get( (int) $biz_id );
    if ( ! $biz ) {
        return new WP_Error( 'biz', __( 'Negocio no encontrado.', 'workshop' ) );
    }
    if ( ! isset( ws_users_allowed_roles()[ $role ] ) ) {
        return new WP_Error( 'role', __( 'Rol inválido.', 'workshop' ) );
    }
    // No dejar el negocio sin dueño.
    if ( in_array( 'ws_owner', (array) $user->roles, true ) && 'ws_owner' !== $role ) {
        if ( ws_admin_owners_count( (int) $biz_id, $user_id ) <= 1 ) {
            return new WP_Error( 'owner', __( 'No puedes quitar el rol de dueño al último dueño del negocio.', 'workshop' ) );
        }
    }
    foreach ( array_keys( ws_users_allowed_roles() ) as $r ) {
        if ( $r !== $role && in_array( $r, (array) $user->roles, true ) ) {
            $user->remove_role( $r );
        }
    }
    $user->add_role( $role );
    update_user_meta( $user_id, 'ws_business_id', (int) $biz_id );
    return $user_id;
}

/**
 * Crea un usuario nuevo y lo asocia a un negocio con su rol.
 */
function ws_admin_create_user( $data ) {
    $username = sanitize_user( $data['username'] ?? '' );
    $email    = sanitize_email( $data['email'] ?? '' );
    $pass     = (string) ( $data['password'] ?? '' );
    $name     = sanitize_text_field( $data['display_name'] ?? '' );
    $role     = sanitize_key( $data['role'] ?? '' );
    $biz_id   = (int) ( $data['biz_id'] ?? 0 );

    if ( empty( $username ) || empty( $email ) || empty( $pass ) ) {
        return new WP_Error( 'required', __( 'Usuario, email y contraseña son obligatorios.', 'workshop' ) );
    }
    if ( username_exists( $username ) || email_exists( $email ) ) {
        return new WP_Error( 'exists', __( 'El usuario o email ya existe.', 'workshop' ) );
    }
    if ( strlen( $pass ) < 8 ) {
        return new WP_Error( 'pass', __( 'La contraseña debe tener al menos 8 caracteres.', 'workshop' ) );
    }
    if ( ! isset( ws_users_allowed_roles()[ $role ] ) ) {
        return new WP_Error( 'role', __( 'Rol inválido.', 'workshop' ) );
    }
    if ( ! WS_Business::get( $biz_id ) ) {
        return new WP_Error( 'biz', __( 'Negocio no encontrado.', 'workshop' ) );
    }
    $user_id = wp_insert_user( array(
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => $pass,
        'display_name' => $name ? $name : $username,
        'role'         => $role,
    ) );
    if ( is_wp_error( $user_id ) ) {
        return $user_id;
    }
    update_user_meta( $user_id, 'ws_business_id', $biz_id );
    return $user_id;
}

/**
 * URL de acceso al panel de un usuario según rol y negocio.
 */
function ws_users_access_url( $role, $biz ) {
    $map = array(
        'ws_owner'       => 'owner',
        'ws_storekeeper' => 'storekeeper',
        'ws_seller'      => 'seller',
    );
    if ( ! isset( $map[ $role ] ) || ! $biz ) {
        return '';
    }
    return ws_panel_url( $map[ $role ], '', $biz );
}

function ws_admin_page_users() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $notice = '';
    if ( isset( $_POST['ws_user_nonce'] ) && wp_verify_nonce( $_POST['ws_user_nonce'], 'ws_manage_users_admin' ) ) {
        $action = sanitize_key( $_POST['ws_action'] ?? '' );
        if ( 'create' === $action ) {
            $result = ws_admin_create_user( $_POST );
            $notice = is_wp_error( $result )
                ? array( 'error', $result->get_error_message() )
                : array( 'success', __( 'Usuario creado y asociado a su negocio.', 'workshop' ) );
        } elseif ( 'assign' === $action ) {
            $result = ws_admin_set_user_business( (int) ( $_POST['user_id'] ?? 0 ), (int) ( $_POST['biz_id'] ?? 0 ), sanitize_key( $_POST['role'] ?? '' ) );
            $notice = is_wp_error( $result )
                ? array( 'error', $result->get_error_message() )
                : array( 'success', __( 'Acceso actualizado.', 'workshop' ) );
        }
    }

    $businesses = WS_Business::all();
    $roles      = ws_users_allowed_roles();
    $users      = get_users( array(
        'role__in' => array_keys( $roles ),
        'orderby'  => 'display_name',
        'order'    => 'ASC',
    ) );

    $biz_id = (int) ( $_GET['biz_id'] ?? 0 );
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-admin-users" style="vertical-align:middle"></span> <?php esc_html_e( 'Accesos de los negocios', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Asigna cada usuario a un negocio y a un rol. Su URL de acceso será la del negocio: /{slug}/panel/{rol}/. Crea primero el negocio en la página Negocios si no existe.', 'workshop' ); ?></p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Usuario', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Rol', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Negocio', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'URL de acceso', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Guardar', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $users ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'Aún no hay usuarios con rol de negocio.', 'workshop' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $users as $u ) : ?>
                    <?php
                    $user_biz_id = (int) get_user_meta( $u->ID, 'ws_business_id', true );
                    $user_biz    = $user_biz_id ? WS_Business::get( $user_biz_id ) : WS_Business::default_business();
                    $user_role   = '';
                    foreach ( array_keys( $roles ) as $r ) {
                        if ( in_array( $r, (array) $u->roles, true ) ) {
                            $user_role = $r;
                            break;
                        }
                    }
                    $access = ws_users_access_url( $user_role, $user_biz );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $u->display_name ); ?></strong><br>
                            <small><a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php echo esc_html( $u->user_login ); ?></a></small>
                        </td>
                        <td><?php echo esc_html( $roles[ $user_role ] ?? '—' ); ?></td>
                        <td>
                            <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <?php wp_nonce_field( 'ws_manage_users_admin', 'ws_user_nonce' ); ?>
                                <input type="hidden" name="ws_action" value="assign">
                                <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                                <select name="biz_id" style="max-width:200px">
                                    <?php foreach ( $businesses as $b ) : ?>
                                        <option value="<?php echo (int) $b->id; ?>" <?php selected( $user_biz_id, (int) $b->id ); ?>><?php echo esc_html( $b->name ); ?><?php echo '' !== (string) $b->slug ? ' — /' . esc_html( $b->slug ) . '/' : ' — /'; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="role" style="max-width:180px">
                                    <?php foreach ( $roles as $rkey => $rlabel ) : ?>
                                        <option value="<?php echo esc_attr( $rkey ); ?>" <?php selected( $user_role, $rkey ); ?>><?php echo esc_html( $rlabel ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="button button-small"><?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
                            </form>
                        </td>
                        <td><?php echo $access ? '<code>' . esc_html( $access ) . '</code>' : '—'; ?></td>
                        <td>
                            <?php if ( $access ) : ?>
                                <a class="button button-small" target="_blank" href="<?php echo esc_url( $access ); ?>"><?php esc_html_e( 'Visitar', 'workshop' ); ?> <span class="dashicons dashicons-external" style="vertical-align:middle;font-size:14px"></span></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 style="margin-top:28px"><?php esc_html_e( 'Nuevo usuario', 'workshop' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'ws_manage_users_admin', 'ws_user_nonce' ); ?>
            <input type="hidden" name="ws_action" value="create">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ws-user-name"><?php esc_html_e( 'Nombre (visible)', 'workshop' ); ?></label></th>
                    <td><input id="ws-user-name" type="text" name="display_name" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ws-user-username"><?php esc_html_e( 'Usuario (login)', 'workshop' ); ?> *</label></th>
                    <td><input id="ws-user-username" type="text" name="username" class="regular-text" required autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ws-user-email"><?php esc_html_e( 'Email', 'workshop' ); ?> *</label></th>
                    <td><input id="ws-user-email" type="email" name="email" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ws-user-pass"><?php esc_html_e( 'Contraseña', 'workshop' ); ?> *</label></th>
                    <td><input id="ws-user-pass" type="password" name="password" class="regular-text" required autocomplete="new-password"><p class="description"><?php esc_html_e( 'Mínimo 8 caracteres.', 'workshop' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ws-user-role"><?php esc_html_e( 'Rol', 'workshop' ); ?> *</label></th>
                    <td>
                        <select id="ws-user-role" name="role">
                            <?php foreach ( $roles as $rkey => $rlabel ) : ?>
                                <option value="<?php echo esc_attr( $rkey ); ?>"><?php echo esc_html( $rlabel ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ws-user-biz"><?php esc_html_e( 'Negocio (URL de acceso)', 'workshop' ); ?> *</label></th>
                    <td>
                        <select id="ws-user-biz" name="biz_id">
                            <?php foreach ( $businesses as $b ) : ?>
                                <option value="<?php echo (int) $b->id; ?>" <?php selected( $biz_id, (int) $b->id ); ?>><?php echo esc_html( $b->name ); ?><?php echo '' !== (string) $b->slug ? ' — /' . esc_html( $b->slug ) . '/' : ' — /'; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'El usuario entrará por /{slug}/panel/{rol}/ para este negocio.', 'workshop' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Crear usuario y dar acceso', 'workshop' ) ); ?>
        </form>

        <hr>
        <p class="description">
            <?php esc_html_e( 'Los usuarios con rol de negocio no pueden entrar a wp-admin: son redirigidos a su panel. Los administradores del sitio pueden entrar a cualquier panel desde su URL.', 'workshop' ); ?>
        </p>
    </div>
    <?php
}
