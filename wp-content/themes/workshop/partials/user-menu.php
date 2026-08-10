<?php
/**
 * Menú de usuario (avatar + desplegable) para usuarios logueados.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
    return;
}

$ws_user       = wp_get_current_user();
$ws_role_slug  = ws_user_role();
$ws_role_label = $ws_role_slug
    ? ws_role_label( $ws_role_slug )
    : ( current_user_can( 'manage_options' ) ? __( 'Admin del sitio', 'workshop' ) : ws_role_label() );

$ws_settings_url = '';
if ( current_user_can( 'manage_options' ) ) {
    $ws_settings_url = admin_url( 'options-general.php' );
} elseif ( $ws_role_slug && ws_can( 'settings_manage' ) ) {
    $ws_settings_url = ws_panel_url( $ws_role_slug, 'settings' );
}
// Logout dentro de la URL del propio negocio (los trabajadores solo pueden
// operar en su negocio; /logout/ sin prefijo quedaría bloqueado).
$ws_logout_url = trailingslashit( ws_business_home() ) . 'logout/';
?>
<div class="ws-user-menu" x-data="{
    open: false,
    logout() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: '<?php echo esc_js( __( '¿Cerrar sesión?', 'workshop' ) ); ?>',
                text: '<?php echo esc_js( __( 'Tu sesión se cerrará y volverás a la pantalla de inicio.', 'workshop' ) ); ?>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<?php echo esc_js( __( 'Sí, cerrar sesión', 'workshop' ) ); ?>',
                cancelButtonText: '<?php echo esc_js( __( 'Cancelar', 'workshop' ) ); ?>',
                confirmButtonColor: '#dc2626'
            }).then(r => { if (r.isConfirmed) window.location.href = '<?php echo esc_url( $ws_logout_url ); ?>'; });
        } else if (confirm('<?php echo esc_js( __( '¿Cerrar sesión?', 'workshop' ) ); ?>')) {
            window.location.href = '<?php echo esc_url( $ws_logout_url ); ?>';
        }
    }
}">
    <button class="ws-user-menu-toggle" type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="true">
        <span class="ws-avatar ws-avatar-sm"><?php echo esc_html( strtoupper( substr( $ws_user->display_name, 0, 1 ) ) ); ?></span>
        <span class="ws-user-menu-name ws-hide-sm"><?php echo esc_html( $ws_user->display_name ); ?></span>
        <i class="fa-solid fa-chevron-down ws-user-menu-caret"></i>
    </button>
    <div class="ws-user-menu-dropdown" x-show="open" @click.away="open = false" x-cloak>
        <div class="ws-user-menu-head">
            <span class="ws-avatar"><?php echo esc_html( strtoupper( substr( $ws_user->display_name, 0, 1 ) ) ); ?></span>
            <div>
                <strong><?php echo esc_html( $ws_user->display_name ); ?></strong>
                <small><?php echo esc_html( $ws_role_label ); ?></small>
            </div>
        </div>
        <?php if ( $ws_role_slug ) : ?>
            <a class="ws-user-menu-link" href="<?php echo esc_url( ws_dashboard_url() ); ?>"><i class="fa-solid fa-gauge-high"></i> <span><?php esc_html_e( 'Mi panel', 'workshop' ); ?></span></a>
        <?php endif; ?>
        <a class="ws-user-menu-link" href="<?php echo esc_url( $ws_role_slug ? ws_panel_url( $ws_role_slug, 'account' ) : admin_url( 'profile.php' ) ); ?>"><i class="fa-solid fa-user"></i> <span><?php esc_html_e( 'Mi cuenta', 'workshop' ) ; ?></span></a>
        <?php if ( $ws_settings_url ) : ?>
            <a class="ws-user-menu-link" href="<?php echo esc_url( $ws_settings_url ); ?>"><i class="fa-solid fa-gear"></i> <span><?php esc_html_e( 'Configurar', 'workshop' ); ?></span></a>
        <?php endif; ?>
        <a class="ws-user-menu-link ws-user-menu-logout" href="<?php echo esc_url( $ws_logout_url ); ?>" @click.prevent="logout"><i class="fa-solid fa-right-from-bracket"></i> <span><?php esc_html_e( 'Cerrar sesión', 'workshop' ); ?></span></a>
    </div>
</div>
