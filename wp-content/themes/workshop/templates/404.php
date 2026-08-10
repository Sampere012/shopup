<?php
/**
 * 404.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="ws-auth-wrap">
    <div class="ws-auth-form-wrap">
        <div class="ws-auth-card ws-404-card">
            <div class="ws-404-code">404</div>
            <h1><?php esc_html_e( 'Página no encontrada', 'workshop' ); ?></h1>
            <p class="ws-auth-sub"><?php esc_html_e( 'Lo sentimos, la página que buscas no existe o fue movida. Verifica la dirección o vuelve al inicio.', 'workshop' ); ?></p>
            <a class="ws-btn ws-btn-primary" href="<?php echo esc_url( ws_business_home() ); ?>">
                <i class="fa-solid fa-house"></i> <?php esc_html_e( 'Ir al inicio', 'workshop' ); ?>
            </a>
            <?php if ( is_user_logged_in() ) : ?>
                <a class="ws-auth-back" href="<?php echo esc_url( ws_dashboard_url() ); ?>"><i class="fa-solid fa-gauge-high"></i> <?php esc_html_e( 'Ir a mi panel', 'workshop' ); ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
