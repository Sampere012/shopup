<?php
/**
 * Handler de la ruta /login/verify/ (ws_public=login-verify).
 *
 * - Con sesión y reto pendiente: pinta la pantalla del código.
 * - Con sesión YA verificada (o reto desactivado): redirige al destino.
 * - Sin sesión: vuela a /login/.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$public = get_query_var( 'ws_public' );
if ( 'login-verify' !== $public ) {
    status_header( 404 );
    include WS_PATH . 'templates/404.php';
    exit;
}
if ( is_user_logged_in() ) {
    $user_id = get_current_user_id();
    $pending = function_exists( 'ws_login_challenge_pending' ) ? ws_login_challenge_pending() : false;
    if ( ! $pending ) {
        $redirect = (string) ( $_GET['redirect_to'] ?? '' );
        if ( user_can( $user_id, 'manage_options' ) ) {
            wp_safe_redirect( admin_url() );
            exit;
        }
        $role = ws_user_role( $user_id );
        if ( $role ) {
            wp_safe_redirect( ws_panel_url( $role ) );
            exit;
        }
        wp_safe_redirect( ws_business_home() );
        exit;
    }
    // Reto pendiente: pinta la pantalla del código (plantilla del tema).
    $u         = wp_get_current_user();
    $email     = (string) $u->user_email;
    $args_data = array(
        'email'      => $email,
        'email_mask' => function_exists( 'ws_mask_email' ) ? ws_mask_email( $email ) : $email,
        'can_resend' => true,
        'token'      => '',
        'redirect'   => (string) ( $_GET['redirect_to'] ?? '' ),
        'error'      => (string) ( $_GET['ws_verify_error'] ?? '' ),
    );
    status_header( 200 );
    include WS_PATH . 'templates/verify-login.php';
    exit;
}
// Sin sesión: al login.
wp_safe_redirect( ws_login_scheme_url( home_url( '/login/' ) ) );
exit;
