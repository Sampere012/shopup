<?php
/**
 * Panel: mi cuenta (credenciales y datos personales).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$ws_u  = wp_get_current_user();
$last  = get_user_meta( $ws_u->ID, 'ws_last_login', true );
$last_f = $last ? mysql2date( 'd/m/Y H:i', $last ) : __( 'Primera sesión', 'workshop' );
?>
<div x-data="wsAccount(<?php echo esc_attr( wp_json_encode( array(
    'id'           => (int) $ws_u->ID,
    'username'     => $ws_u->user_login,
    'display_name' => $ws_u->display_name,
    'email'        => $ws_u->user_email,
    'role'         => ws_role_label(),
    'last_login'   => $last_f,
) ) ); ?>)">

    <div class="ws-grid-2">
        <div class="ws-card">
            <h3 class="ws-card-title"><i class="fa-solid fa-user"></i> <?php esc_html_e( 'Mis datos', 'workshop' ); ?></h3>
            <form @submit.prevent="saveData" class="ws-form">
                <label class="ws-field">
                    <span><?php esc_html_e( 'Usuario', 'workshop' ); ?></span>
                    <input type="text" :value="username" disabled>
                </label>
                <label class="ws-field">
                    <span><?php esc_html_e( 'Nombre mostrado', 'workshop' ); ?></span>
                    <input type="text" x-model="display_name" required>
                </label>
                <label class="ws-field">
                    <span><?php esc_html_e( 'Email *', 'workshop' ); ?></span>
                    <input type="email" x-model="email" required>
                </label>
                <button class="ws-btn ws-btn-primary" type="submit" :disabled="busy"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar datos', 'workshop' ); ?></button>
            </form>
        </div>

        <div class="ws-card">
            <h3 class="ws-card-title"><i class="fa-solid fa-key"></i> <?php esc_html_e( 'Cambiar contraseña', 'workshop' ); ?></h3>
            <form @submit.prevent="savePassword" class="ws-form">
                <label class="ws-field">
                    <span><?php esc_html_e( 'Contraseña actual *', 'workshop' ); ?></span>
                    <input type="password" x-model="password.current" required autocomplete="current-password">
                </label>
                <label class="ws-field">
                    <span><?php esc_html_e( 'Nueva contraseña *', 'workshop' ); ?></span>
                    <input type="password" x-model="password.new" required minlength="8" autocomplete="new-password">
                </label>
                <label class="ws-field">
                    <span><?php esc_html_e( 'Repite la nueva contraseña *', 'workshop' ); ?></span>
                    <input type="password" x-model="password.confirm" required autocomplete="new-password">
                </label>
                <button class="ws-btn ws-btn-primary" type="submit" :disabled="busy"><i class="fa-solid fa-key"></i> <?php esc_html_e( 'Cambiar contraseña', 'workshop' ); ?></button>
            </form>
        </div>
    </div>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-circle-info"></i> <?php esc_html_e( 'Información de la cuenta', 'workshop' ); ?></h3>
        <div class="ws-info-grid">
            <div><span><?php esc_html_e( 'Rol', 'workshop' ); ?></span><strong x-text="role"></strong></div>
            <div><span><?php esc_html_e( 'Último acceso', 'workshop' ); ?></span><strong x-text="last_login"></strong></div>
        </div>
    </div>
</div>
