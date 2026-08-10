<?php
/**
 * Login front-end.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$ws_error = ! empty( $_GET['ws_login_error'] ) || ! empty( $_COOKIE['ws_login_error'] );
if ( $ws_error ) {
    setcookie( 'ws_login_error', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
}

get_header();
?>
<div class="ws-auth-wrap">
    <div class="ws-auth-side">
        <h2><?php esc_html_e( 'Gestiona tu negocio desde un solo lugar', 'workshop' ); ?></h2>
        <p><?php esc_html_e( 'Pedidos por tienda, stock en tiempo real, turnos y reportes. Todo sincronizado entre tu almacén y puntos de venta.', 'workshop' ); ?></p>
        <div class="ws-auth-features">
            <div class="ws-auth-feature"><i class="fa-solid fa-bolt"></i><div><strong><?php esc_html_e( 'Stock en vivo', 'workshop' ); ?></strong><small><?php esc_html_e( 'Siempre actualizado', 'workshop' ); ?></small></div></div>
            <div class="ws-auth-feature"><i class="fa-solid fa-receipt"></i><div><strong><?php esc_html_e( 'Pedidos', 'workshop' ); ?></strong><small><?php esc_html_e( 'Acepta y despacha', 'workshop' ); ?></small></div></div>
            <div class="ws-auth-feature"><i class="fa-solid fa-calendar-days"></i><div><strong><?php esc_html_e( 'Turnos', 'workshop' ); ?></strong><small><?php esc_html_e( 'Calendario de equipo', 'workshop' ); ?></small></div></div>
            <div class="ws-auth-feature"><i class="fa-solid fa-chart-pie"></i><div><strong><?php esc_html_e( 'Reportes', 'workshop' ); ?></strong><small><?php esc_html_e( 'Decisiones con datos', 'workshop' ); ?></small></div></div>
        </div>
    </div>
    <div class="ws-auth-form-wrap">
        <div class="ws-auth-card">
            <h1 class="ws-brand-name"><?php echo esc_html( ws_site_name() ); ?></h1>
            <p class="ws-auth-sub"><?php esc_html_e( 'Inicia sesión en tu panel', 'workshop' ); ?></p>

            <?php if ( $ws_error ) : ?>
                <div class="ws-alert ws-alert-error"><?php esc_html_e( 'Usuario o contraseña incorrectos.', 'workshop' ); ?></div>
            <?php endif; ?>

            <form method="post" class="ws-form ws-auth-form">
                <?php wp_nonce_field( 'ws_login', 'ws_nonce' ); ?>
                <input type="hidden" name="ws_login" value="1">
                <label class="ws-field">
                    <span><?php esc_html_e( 'Usuario o email', 'workshop' ); ?></span>
                    <input type="text" name="ws_user" required autofocus>
                </label>
                <label class="ws-field">
                    <span><?php esc_html_e( 'Contraseña', 'workshop' ); ?></span>
                    <input type="password" name="ws_pass" required>
                </label>
                <label class="ws-check">
                    <input type="checkbox" name="ws_remember" value="1">
                    <span><?php esc_html_e( 'Recordarme', 'workshop' ); ?></span>
                </label>
                <button class="ws-btn ws-btn-primary ws-btn-block" type="submit">
                    <i class="fa-solid fa-right-to-bracket"></i> <?php esc_html_e( 'Iniciar sesión', 'workshop' ); ?>
                </button>
            </form>
            <div class="ws-auth-divider"><span><?php esc_html_e( '¿Eres dueño de un negocio?', 'workshop' ); ?></span></div>
            <a class="ws-btn ws-btn-accent ws-btn-block ws-login-register-cta" href="<?php echo esc_url( ws_register_url() ); ?>">
                <i class="fa-solid fa-gift"></i> <?php esc_html_e( 'Crea tu tienda gratis · 7 días de prueba', 'workshop' ); ?>
            </a>
            <a class="ws-auth-back" href="<?php echo esc_url( ws_business_home() ); ?>"><i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Volver', 'workshop' ); ?></a>
        </div>
    </div>
</div>
<?php get_footer(); ?>
