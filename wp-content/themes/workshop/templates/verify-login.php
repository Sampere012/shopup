<?php
/**
 * Verificación de correo al iniciar sesión (web), igual que la app móvil.
 *
 * El login POST crea la sesión PHP y redirige a /login/verify/ con un código
 * opaco en la URL (hash de la sesión; no revela identidad del usuario).
 * La pantalla pide el código de 6 dígitos enviado al buzón; al verificar:
 *  - se marca ws_email_verified_at,
 *  - se guarda ws_login_verified_<sid> en la sesión,
 *  - se reanuda la redirección original (redirect_to y el destino del rol).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$data = isset( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : array();
$email     = (string) ( $data['email'] ?? '' );
$mask      = (string) ( $data['email_mask'] ?? '' );
$can_resend = ! empty( $data['can_resend'] );
$error     = (string) ( $data['error'] ?? '' );
if ( '' === $mask ) {
    $mask = $email;
}
get_header();
?>
<main class="ws-login-page" style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:24px">
    <div style="width:100%;max-width:420px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(15,23,42,.08)">
        <h1 style="margin:0 0 8px;font-size:20px"><?php esc_html_e( 'Verifica tu correo', 'workshop' ); ?></h1>
        <p style="margin:0 0 16px;color:#64748b;font-size:14px">
            <?php echo esc_html( sprintf( __( 'Te enviamos un código de 6 dígitos a %s. Introdúcelo para continuar con tu sesión.', 'workshop' ), $mask ) ); ?>
        </p>
        <?php if ( '' !== $error ) : ?>
            <div style="margin-bottom:12px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#b91c1c;font-size:13px">
                <?php echo esc_html( $error ); ?>
            </div>
        <?php endif; ?>
        <form method="post" action="">
            <?php wp_nonce_field( 'ws_login_verify', 'ws_verify_nonce' ); ?>
            <input type="hidden" name="ws_verify" value="1">
            <input type="hidden" name="ws_v_token" value="<?php echo esc_attr( (string) ( $data['token'] ?? '' ) ); ?>">
            <input type="hidden" name="ws_v_redirect" value="<?php echo esc_attr( (string) ( $data['redirect'] ?? '' ) ); ?>">
            <label for="ws-code" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px"><?php esc_html_e( 'Código', 'workshop' ); ?></label>
            <input id="ws-code" name="ws_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required
                   style="width:100%;padding:12px;font-size:22px;letter-spacing:10px;text-align:center;border:1px solid #cbd5e1;border-radius:10px;box-sizing:border-box">
            <button type="submit" class="ws-btn ws-btn-primary" style="width:100%;margin-top:14px;justify-content:center">
                <?php esc_html_e( 'Verificar y continuar', 'workshop' ); ?>
            </button>
        </form>
        <?php if ( $can_resend ) : ?>
            <form method="post" action="" style="margin-top:10px">
                <?php wp_nonce_field( 'ws_login_verify', 'ws_verify_nonce' ); ?>
                <input type="hidden" name="ws_resend" value="1">
                <input type="hidden" name="ws_v_token" value="<?php echo esc_attr( (string) ( $data['token'] ?? '' ) ); ?>">
                <input type="hidden" name="ws_v_redirect" value="<?php echo esc_attr( (string) ( $data['redirect'] ?? '' ) ); ?>">
                <button type="submit" class="ws-btn ws-btn-secondary" style="width:100%;justify-content:center">
                    <?php esc_html_e( 'Reenviar código', 'workshop' ); ?>
                </button>
            </form>
        <?php endif; ?>
        <p style="margin:14px 0 0;color:#94a3b8;font-size:12px;text-align:center">
            <?php esc_html_e( 'El código caduca en 15 minutos. Revisa también la carpeta de spam.', 'workshop' ); ?>
        </p>
    </div>
</main>
<?php
get_footer();
