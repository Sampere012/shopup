<?php
/**
 * wp-admin: Planes, Suscripciones y Correo SMTP.
 *
 * - Planes: alta/baja/edición de los planes de suscripción y sus límites.
 * - Suscripciones: estado de cada negocio, uso vs límites y aprobación o
 *   rechazo de las solicitudes de cambio de plan. Al aprobar, el negocio queda
 *   habilitado (deja de estar bloqueado) con el plan solicitado.
 * - Correo SMTP: credenciales del servidor de correo y prueba de envío.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Menú
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'ws_plans_admin_menu', 25 );
function ws_plans_admin_menu() {
    add_submenu_page( 'ws-permissions', __( 'Planes', 'workshop' ), __( 'Planes', 'workshop' ), 'manage_options', 'ws-plans', 'ws_admin_page_plans' );
    add_submenu_page( 'ws-permissions', __( 'Suscripciones', 'workshop' ), __( 'Suscripciones', 'workshop' ), 'manage_options', 'ws-subscriptions', 'ws_admin_page_subscriptions' );
    add_submenu_page( 'ws-permissions', __( 'Correo SMTP', 'workshop' ), __( 'Correo SMTP', 'workshop' ), 'manage_options', 'ws-smtp', 'ws_admin_page_smtp' );
}

/* -------------------------------------------------------------------------
 * Planes
 * ---------------------------------------------------------------------- */

function ws_admin_page_plans() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }
    $notice = '';
    if ( isset( $_POST['ws_plans_nonce'] ) && wp_verify_nonce( $_POST['ws_plans_nonce'], 'ws_save_plans' ) ) {
        $action = sanitize_key( $_POST['ws_action'] ?? '' );
        if ( 'save' === $action ) {
            $result = WS_Plans::save( $_POST, (int) ( $_POST['plan_id'] ?? 0 ) );
            if ( is_wp_error( $result ) ) {
                $notice = array( 'error', $result->get_error_message() );
            } else {
                $notice = array( 'success', __( 'Plan guardado.', 'workshop' ) );
            }
        } elseif ( 'delete' === $action ) {
            if ( WS_Plans::delete( (int) ( $_POST['plan_id'] ?? 0 ) ) ) {
                $notice = array( 'success', __( 'Plan eliminado.', 'workshop' ) );
            } else {
                $notice = array( 'error', __( 'No se pudo eliminar (¿hay negocios usándolo?).', 'workshop' ) );
            }
        } elseif ( 'seed' === $action ) {
            update_option( 'ws_plans_seeded', 0 );
            WS_Plans::seed_defaults();
            $notice = array( 'success', __( 'Planes por defecto creados.', 'workshop' ) );
        }
    }
    $plans = WS_Plans::all();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-money-alt" style="vertical-align:middle"></span> <?php esc_html_e( 'Planes de suscripción', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Cada plan define un precio y límites de cantidad (productos, usuarios, puntos de venta, almacenes y proveedores). 0 = sin límite. El plan con «Prueba gratis» activa la prueba de 7 días al registrarse.', 'workshop' ); ?></p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
        <?php endif; ?>

        <?php if ( empty( $plans ) ) : ?>
            <form method="post" style="margin:16px 0">
                <?php wp_nonce_field( 'ws_save_plans', 'ws_plans_nonce' ); ?>
                <input type="hidden" name="ws_action" value="seed">
                <button class="button button-primary"><?php esc_html_e( 'Crear planes por defecto', 'workshop' ); ?></button>
            </form>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Plan', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Precio', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Duración', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Límites', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $plans as $p ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $p->name ); ?></strong> <code><?php echo esc_html( $p->slug ); ?></code></td>
                        <td><?php echo esc_html( WS_Plans::format_price( $p ) ); ?></td>
                        <td><?php echo esc_html( WS_Plans::duration_label( $p ) ); ?></td>
                        <td>
                            <?php
                            $limits = WS_Plans::limits( $p );
                            $parts = array();
                            foreach ( $limits as $k => $v ) {
                                $parts[] = sprintf( '%s: %s', WS_Plans::limit_label( $k ), $v > 0 ? $v : '∞' );
                            }
                            echo esc_html( implode( ' · ', $parts ) );
                            ?>
                            <?php if ( WS_Plans::has_chatbot( $p ) ) : ?>
                                <br><span style="color:#3b82f6"><i class="fa-solid fa-robot"></i> <?php esc_html_e( 'Chatbot', 'workshop' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( (int) $p->is_trial ) : ?><span class="dashicons dashicons-megaphone" style="color:#7c3aed"></span> <?php esc_html_e( 'Prueba', 'workshop' ); ?><?php endif; ?>
                            <?php echo (int) $p->is_active ? '<span style="color:#00a32a">' . esc_html__( 'Activo', 'workshop' ) . '</span>' : '<span style="color:#d63638">' . esc_html__( 'Inactivo', 'workshop' ) . '</span>'; ?>
                        </td>
                        <td>
                            <button class="button button-small" onclick="document.getElementById('ws-plan-form-<?php echo (int) $p->id; ?>').classList.toggle('hidden')"><?php esc_html_e( 'Editar', 'workshop' ); ?></button>
                            <?php if ( 'legacy' !== $p->slug ) : ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar este plan?', 'workshop' ) ); ?>')">
                                    <?php wp_nonce_field( 'ws_save_plans', 'ws_plans_nonce' ); ?>
                                    <input type="hidden" name="ws_action" value="delete">
                                    <input type="hidden" name="plan_id" value="<?php echo (int) $p->id; ?>">
                                    <button class="button button-small button-link-delete"><?php esc_html_e( 'Eliminar', 'workshop' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr id="ws-plan-form-<?php echo (int) $p->id; ?>" class="hidden">
                        <td colspan="6">
                            <?php ws_plan_edit_form( $p ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e( 'Nuevo plan', 'workshop' ); ?></h2>
        <?php ws_plan_edit_form(); ?>
    </div>
    <style>
        .ws-plan-form { background:#fff; border:1px solid #c3c4c7; padding:8px 16px; margin:8px 0; }
        .ws-plan-form .form-table th { width: 200px; }
        .ws-plan-limits { display:flex; gap:10px; flex-wrap:wrap; }
        .ws-plan-limits label { background:#f6f7f7; border:1px solid #e2e4e7; border-radius:6px; padding:6px 10px; }
        .ws-plan-limits input { width:70px; }
    </style>
    <?php
}

function ws_plan_edit_form( $p = null ) {
    $limits = $p ? WS_Plans::limits( $p ) : array( 'products' => 0, 'users' => 0, 'pvs' => 0, 'warehouses' => 0, 'suppliers' => 0 );
    ?>
    <form method="post" class="ws-plan-form">
        <?php wp_nonce_field( 'ws_save_plans', 'ws_plans_nonce' ); ?>
        <input type="hidden" name="ws_action" value="save">
        <input type="hidden" name="plan_id" value="<?php echo $p ? (int) $p->id : 0; ?>">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label><?php esc_html_e( 'Nombre', 'workshop' ); ?> *</label></th>
                <td><input type="text" name="name" class="regular-text" value="<?php echo $p ? esc_attr( $p->name ) : ''; ?>" required></td>
            </tr>
            <tr>
                <th scope="row"><label><?php esc_html_e( 'Slug', 'workshop' ); ?></label></th>
                <td><input type="text" name="slug" class="regular-text" value="<?php echo $p ? esc_attr( $p->slug ) : ''; ?>" placeholder="ej: basic"></td>
            </tr>
            <tr>
                <th scope="row"><label><?php esc_html_e( 'Descripción', 'workshop' ); ?></label></th>
                <td><textarea name="description" class="large-text" rows="2"><?php echo $p ? esc_textarea( $p->description ) : ''; ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><label><?php esc_html_e( 'Precio', 'workshop' ); ?></label></th>
                <td>
                    <input type="number" step="0.01" min="0" name="price" value="<?php echo $p ? esc_attr( (float) $p->price ) : '0'; ?>">
                    <input type="text" name="currency" maxlength="10" value="<?php echo $p ? esc_attr( $p->currency ) : 'USD'; ?>" style="width:80px">
                </td>
            </tr>
            <tr>
                <th scope="row"><label><?php esc_html_e( 'Duración (días)', 'workshop' ); ?></label></th>
                <td><input type="number" min="0" name="duration_days" value="<?php echo $p ? esc_attr( (int) $p->duration_days ) : '30'; ?>">
                    <p class="description"><?php esc_html_e( '0 = sin caducidad. 30 = 1 mes.', 'workshop' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label><?php esc_html_e( 'Límites', 'workshop' ); ?></label></th>
                <td>
                    <div class="ws-plan-limits">
                        <?php
                        $labels = array(
                            'products'   => __( 'Productos', 'workshop' ),
                            'users'      => __( 'Usuarios', 'workshop' ),
                            'pvs'        => __( 'Puntos de venta', 'workshop' ),
                            'warehouses' => __( 'Almacenes', 'workshop' ),
                            'suppliers'  => __( 'Proveedores', 'workshop' ),
                        );
                        foreach ( $labels as $k => $label ) :
                            ?>
                            <label><?php echo esc_html( $label ); ?><br>
                                <input type="number" min="0" name="limit_<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (int) ( $limits[ $k ] ?? 0 ) ); ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e( '0 = sin límite.', 'workshop' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Opciones', 'workshop' ); ?></th>
                <td>
                    <label><input type="checkbox" name="has_chatbot" value="1" <?php checked( $p && WS_Plans::has_chatbot( $p ), 1 ); ?>> <i class="fa-solid fa-robot"></i> <?php esc_html_e( 'Incluye el asistente (chatbot) del sitio para su panel', 'workshop' ); ?></label><br>
                    <label><input type="checkbox" name="is_trial" value="1" <?php checked( $p && (int) $p->is_trial, 1 ); ?>> <?php esc_html_e( 'Es la prueba gratis (se asigna al registrarse)', 'workshop' ); ?></label><br>
                    <label><input type="checkbox" name="is_active" value="1" <?php checked( ! $p || (int) $p->is_active, 1 ); ?>> <?php esc_html_e( 'Activo (visible en el front)', 'workshop' ); ?></label>
                </td>
            </tr>
        </table>
        <?php submit_button( $p ? __( 'Guardar plan', 'workshop' ) : __( 'Crear plan', 'workshop' ), 'primary', 'submit', false ); ?>
    </form>
    <?php
}

/* -------------------------------------------------------------------------
 * Suscripciones
 * ---------------------------------------------------------------------- */

function ws_admin_page_subscriptions() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }
    $notice = '';
    if ( isset( $_POST['ws_subs_nonce'] ) && wp_verify_nonce( $_POST['ws_subs_nonce'], 'ws_subs_actions' ) ) {
        $action = sanitize_key( $_POST['ws_action'] ?? '' );
        $biz_id = (int) ( $_POST['biz_id'] ?? 0 );
        if ( $biz_id && 'approve' === $action ) {
            $sub = WS_Subscriptions::get( $biz_id );
            if ( $sub && $sub->upgrade_plan_id && 'pending' === $sub->upgrade_status ) {
                WS_Subscriptions::apply_plan( $biz_id, (int) $sub->upgrade_plan_id, 'active' );
                $notice = array( 'success', __( 'Solicitud aprobada: el negocio quedó habilitado con el plan solicitado.', 'workshop' ) );
            } else {
                $notice = array( 'error', __( 'No hay solicitud pendiente para ese negocio.', 'workshop' ) );
            }
        } elseif ( $biz_id && 'reject' === $action ) {
            global $wpdb;
            $wpdb->update( WS_Subscriptions::table(), array(
                'upgrade_status'    => 'rejected',
                'upgrade_decided_at' => current_time( 'mysql' ),
                'updated_at'        => current_time( 'mysql' ),
            ), array( 'business_id' => $biz_id ) );
            $notice = array( 'success', __( 'Solicitud rechazada.', 'workshop' ) );
        } elseif ( $biz_id && 'apply' === $action ) {
            $plan_id = (int) ( $_POST['plan_id'] ?? 0 );
            if ( $plan_id && WS_Subscriptions::apply_plan( $biz_id, $plan_id, 'active' ) ) {
                $notice = array( 'success', __( 'Plan aplicado al negocio.', 'workshop' ) );
            } else {
                $notice = array( 'error', __( 'No se pudo aplicar el plan.', 'workshop' ) );
            }
        } elseif ( $biz_id && 'block' === $action ) {
            global $wpdb;
            $wpdb->update( WS_Subscriptions::table(), array( 'status' => 'suspended', 'updated_at' => current_time( 'mysql' ) ), array( 'business_id' => $biz_id ) );
            $notice = array( 'success', __( 'Negocio suspendido (bloqueado).', 'workshop' ) );
        } elseif ( $biz_id && 'unblock' === $action ) {
            $sub = WS_Subscriptions::get( $biz_id );
            $plan_id = $sub && $sub->plan_id ? (int) $sub->plan_id : 0;
            $trial = WS_Plans::trial_plan();
            WS_Subscriptions::apply_plan( $biz_id, $plan_id ? $plan_id : ( $trial ? (int) $trial->id : 0 ), 'active' );
            $notice = array( 'success', __( 'Negocio habilitado.', 'workshop' ) );
        } elseif ( 'trial_days' === $action ) {
            update_option( 'ws_trial_days', max( 1, (int) ( $_POST['trial_days'] ?? 7 ) ) );
            $notice = array( 'success', __( 'Duración de la prueba guardada.', 'workshop' ) );
        }
    }

    $businesses = class_exists( 'WS_Business' ) ? WS_Business::all() : array();
    $plans      = WS_Plans::all();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-shield-alt" style="vertical-align:middle"></span> <?php esc_html_e( 'Suscripciones de los negocios', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Cuando la prueba o el plan vencen, o se supera un límite, el negocio se bloquea solo (desaparece del mercado y nadie entra a su panel). El dueño ve el botón «Upgrade» y puede solicitar otro plan. Aprueba la solicitud aquí para habilitarlo de nuevo.', 'workshop' ); ?></p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
        <?php endif; ?>

        <form method="post" style="margin:14px 0; background:#fff; border:1px solid #c3c4c7; padding:10px 14px; display:inline-block">
            <?php wp_nonce_field( 'ws_subs_actions', 'ws_subs_nonce' ); ?>
            <input type="hidden" name="ws_action" value="trial_days">
            <label for="ws-trial-days"><?php esc_html_e( 'Duración de la prueba gratis (días):', 'workshop' ); ?></label>
            <input id="ws-trial-days" type="number" min="1" name="trial_days" value="<?php echo esc_attr( ws_trial_days() ); ?>" style="width:70px">
            <button class="button"><?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
        </form>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Negocio', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Plan', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Vence', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Uso / Límite', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Solicitud', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $businesses as $b ) :
                    $sub  = WS_Subscriptions::ensure( $b );
                    WS_Subscriptions::refresh( $b, $sub );
                    $plan = $sub && $sub->plan_id ? WS_Plans::get( $sub->plan_id ) : null;
                    $usage = ws_business_usage( $b );
                    $limits = $plan ? WS_Plans::limits( $plan ) : array();
                    $lock  = WS_Subscriptions::lock_reason( $b );
                    $ends  = 'trial' === ( $sub->status ?? '' ) ? ( $sub->trial_ends_at ?? '' ) : ( $sub->plan_ends_at ?? '' );
                    $status_class = 'expired' === ( $sub->status ?? '' ) || $lock ? 'red' : ( 'active' === ( $sub->status ?? '' ) ? 'green' : 'orange' );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $b->name ); ?></strong>
                            <br><code><?php echo esc_html( WS_Business::is_default( $b ) ? home_url( '/' ) : home_url( '/' . $b->slug . '/' ) ); ?></code>
                        </td>
                        <td><?php echo $plan ? esc_html( $plan->name ) : '—'; ?></td>
                        <td>
                            <span style="color:<?php echo 'green' === $status_class ? '#00a32a' : ( 'red' === $status_class ? '#d63638' : '#dba617' ); ?>">
                                <?php echo esc_html( ws_status_label( $sub->status ?? 'trial' ) ); ?>
                            </span>
                            <?php if ( $lock ) : ?><br><em style="color:#d63638"><?php echo esc_html( $lock['title'] ); ?></em><?php endif; ?>
                        </td>
                        <td><?php echo $ends ? esc_html( mysql2date( 'd/m/Y', $ends ) ) : '—'; ?></td>
                        <td style="font-size:12px">
                            <?php
                            foreach ( array( 'products', 'users', 'pvs', 'warehouses', 'suppliers' ) as $k ) {
                                $lim = (int) ( $limits[ $k ] ?? 0 );
                                $use = (int) ( $usage[ $k ] ?? 0 );
                                $cls = $lim > 0 && $use > $lim ? ' style="color:#d63638;font-weight:700"' : '';
                                printf( '<div%s>%s: %d/%s</div>', $cls, esc_html( ucfirst( WS_Plans::limit_label( $k ) ) ), $use, $lim > 0 ? $lim : '∞' );
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ( $sub && 'pending' === $sub->upgrade_status && $sub->upgrade_plan_id ) :
                                $up_plan = WS_Plans::get( $sub->upgrade_plan_id );
                                ?>
                                <strong><?php echo $up_plan ? esc_html( $up_plan->name ) : '#' . (int) $sub->upgrade_plan_id; ?></strong>
                                <br><small><?php echo esc_html( __( 'Solicitado:', 'workshop' ) . ' ' . ( $sub->upgrade_requested_at ? mysql2date( 'd/m/Y H:i', $sub->upgrade_requested_at ) : '' ) ); ?></small>
                                <form method="post" style="margin-top:6px">
                                    <?php wp_nonce_field( 'ws_subs_actions', 'ws_subs_nonce' ); ?>
                                    <input type="hidden" name="ws_action" value="approve">
                                    <input type="hidden" name="biz_id" value="<?php echo (int) $b->id; ?>">
                                    <button class="button button-primary button-small"><?php esc_html_e( 'Aprobar', 'workshop' ); ?></button>
                                </form>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field( 'ws_subs_actions', 'ws_subs_nonce' ); ?>
                                    <input type="hidden" name="ws_action" value="reject">
                                    <input type="hidden" name="biz_id" value="<?php echo (int) $b->id; ?>">
                                    <button class="button button-small"><?php esc_html_e( 'Rechazar', 'workshop' ); ?></button>
                                </form>
                            <?php elseif ( $sub && 'rejected' === $sub->upgrade_status ) : ?>
                                <em><?php esc_html_e( 'Rechazada', 'workshop' ); ?></em>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" style="margin-bottom:6px">
                                <?php wp_nonce_field( 'ws_subs_actions', 'ws_subs_nonce' ); ?>
                                <input type="hidden" name="ws_action" value="apply">
                                <input type="hidden" name="biz_id" value="<?php echo (int) $b->id; ?>">
                                <select name="plan_id" style="max-width:110px">
                                    <?php foreach ( $plans as $p ) : ?>
                                        <option value="<?php echo (int) $p->id; ?>" <?php selected( $plan && (int) $plan->id === (int) $p->id ); ?>><?php echo esc_html( $p->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="button button-small"><?php esc_html_e( 'Aplicar', 'workshop' ); ?></button>
                            </form>
                            <?php if ( 'suspended' === ( $sub->status ?? '' ) ) : ?>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field( 'ws_subs_actions', 'ws_subs_nonce' ); ?>
                                    <input type="hidden" name="ws_action" value="unblock">
                                    <input type="hidden" name="biz_id" value="<?php echo (int) $b->id; ?>">
                                    <button class="button button-small"><?php esc_html_e( 'Habilitar', 'workshop' ); ?></button>
                                </form>
                            <?php else : ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( '¿Bloquear este negocio?', 'workshop' ) ); ?>')">
                                    <?php wp_nonce_field( 'ws_subs_actions', 'ws_subs_nonce' ); ?>
                                    <input type="hidden" name="ws_action" value="block">
                                    <input type="hidden" name="biz_id" value="<?php echo (int) $b->id; ?>">
                                    <button class="button button-small button-link-delete"><?php esc_html_e( 'Bloquear', 'workshop' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Correo SMTP
 * ---------------------------------------------------------------------- */

function ws_admin_page_smtp() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }
    $saved  = false;
    $notice = '';
    if ( isset( $_POST['ws_smtp_nonce'] ) && wp_verify_nonce( $_POST['ws_smtp_nonce'], 'ws_save_smtp' ) ) {
        $action = sanitize_key( $_POST['ws_action'] ?? '' );
        if ( 'save' === $action ) {
            update_option( 'ws_smtp_settings', array(
                'enabled'    => ! empty( $_POST['enabled'] ) ? 1 : 0,
                'host'       => sanitize_text_field( $_POST['host'] ?? '' ),
                'port'       => max( 1, (int) ( $_POST['port'] ?? 587 ) ),
                'use_tls'    => ! empty( $_POST['use_tls'] ) ? 1 : 0,
                'user'       => sanitize_text_field( $_POST['user'] ?? '' ),
                'password'   => (string) ( $_POST['password'] ?? '' ),
                'from_email' => sanitize_email( $_POST['from_email'] ?? '' ),
                'from_name'  => sanitize_text_field( $_POST['from_name'] ?? '' ),
            ) );
            $saved = true;
        } elseif ( 'test' === $action ) {
            $to   = sanitize_email( $_POST['test_to'] ?? get_option( 'admin_email' ) );
            $sent = ws_send_mail( $to, __( 'Prueba de correo SMTP', 'workshop' ), '<p>' . esc_html__( 'Si recibes este correo, la configuración SMTP funciona correctamente.', 'workshop' ) . '</p>' );
            $notice = $sent
                ? array( 'success', sprintf( __( 'Correo de prueba enviado a %s.', 'workshop' ), $to ) )
                : array( 'error', __( 'No se pudo enviar el correo de prueba. Revisa host, puerto y credenciales.', 'workshop' ) );
        }
    }
    $s = ws_smtp_settings();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-email-alt" style="vertical-align:middle"></span> <?php esc_html_e( 'Correo SMTP', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Configura el servidor SMTP usado por todos los correos del sitio: verificación por código de 6 dígitos (registro de negocios), avisos de pedidos, solicitudes de plan, etc.', 'workshop' ); ?></p>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración SMTP guardada.', 'workshop' ); ?></p></div>
        <?php endif; ?>
        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'ws_save_smtp', 'ws_smtp_nonce' ); ?>
            <input type="hidden" name="ws_action" value="save">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Habilitado', 'workshop' ); ?></label></th>
                    <td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Usar SMTP para enviar el correo', 'workshop' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Servidor (host)', 'workshop' ); ?></label></th>
                    <td><input type="text" name="host" class="regular-text" value="<?php echo esc_attr( $s['host'] ); ?>" placeholder="smtp.gmail.com"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Puerto', 'workshop' ); ?></label></th>
                    <td><input type="number" name="port" min="1" max="65535" value="<?php echo esc_attr( (int) $s['port'] ); ?>">
                        <span class="description"><?php esc_html_e( '587 (TLS) o 465 (SSL).', 'workshop' ); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Usar TLS', 'workshop' ); ?></label></th>
                    <td><label><input type="checkbox" name="use_tls" value="1" <?php checked( ! empty( $s['use_tls'] ) ); ?>> <?php esc_html_e( 'Cifrado STARTTLS', 'workshop' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Usuario', 'workshop' ); ?></label></th>
                    <td><input type="text" name="user" class="regular-text" value="<?php echo esc_attr( $s['user'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Contraseña / App password', 'workshop' ); ?></label></th>
                    <td><input type="password" name="password" class="regular-text" value="<?php echo esc_attr( $s['password'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Remitente (email)', 'workshop' ); ?></label></th>
                    <td><input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Remitente (nombre)', 'workshop' ); ?></label></th>
                    <td><input type="text" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td>
                </tr>
            </table>
            <?php submit_button( __( 'Guardar configuración SMTP', 'workshop' ), 'primary', 'submit', false ); ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Enviar correo de prueba', 'workshop' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'ws_save_smtp', 'ws_smtp_nonce' ); ?>
            <input type="hidden" name="ws_action" value="test">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label><?php esc_html_e( 'Para', 'workshop' ); ?></label></th>
                    <td><input type="email" name="test_to" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
                </tr>
            </table>
            <?php submit_button( __( 'Enviar prueba', 'workshop' ), 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}
