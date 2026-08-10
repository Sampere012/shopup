<?php
/**
 * Negocio bloqueado (prueba/plan vencido o límite superado).
 *
 * Se muestra en lugar de la portada, las tiendas y el panel. El dueño ve el
 * estado y la cuadrícula de planes con el botón "Upgrade"; los trabajadores y
 * visitantes ven un aviso de pausa. El administrador del sitio ve un resumen
 * con enlace a wp-admin.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$biz   = ws_current_business();
$data  = ws_subscription_data( $biz );
$lock  = ! empty( $data['lock'] ) ? $data['lock'] : array( 'title' => __( 'Negocio en pausa', 'workshop' ), 'message' => __( 'Este negocio está temporalmente no disponible.', 'workshop' ) );
$role  = ws_user_role();
$owner = ( 'owner' === $role );
$is_admin = current_user_can( 'manage_options' );

get_header();
?>
<div class="ws-locked">
    <div class="ws-locked-card">
        <span class="ws-locked-icon"><i class="fa-solid fa-lock"></i></span>
        <span class="ws-locked-badge"><?php esc_html_e( 'Negocio en pausa', 'workshop' ); ?></span>
        <h1><?php echo esc_html( $lock['title'] ); ?></h1>
        <p class="ws-locked-msg"><?php echo esc_html( $lock['message'] ); ?></p>

        <?php if ( $is_admin ) : ?>
            <p class="ws-muted">
                <?php esc_html_e( 'Estás viendo esto como administrador del sitio.', 'workshop' ); ?>
                <a class="ws-link" href="<?php echo esc_url( admin_url( 'admin.php?page=ws-subscriptions' ) ); ?>"><?php esc_html_e( 'Gestionar suscripciones', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></a>
            </p>
        <?php elseif ( $owner ) : ?>
            <div class="ws-locked-actions">
                <a class="ws-btn ws-btn-primary" href="#ws-upgrade">
                    <i class="fa-solid fa-arrow-up-right-dots"></i> <?php esc_html_e( 'Solicitar upgrade', 'workshop' ); ?>
                </a>
            </div>
        <?php elseif ( $role ) : ?>
            <p class="ws-muted"><?php esc_html_e( 'Si eres parte del equipo, contacta al dueño del negocio para reactivarlo.', 'workshop' ); ?></p>
        <?php else : ?>
            <p class="ws-muted"><?php esc_html_e( 'Vuelve pronto. Mientras tanto, puedes explorar otros negocios.', 'workshop' ); ?></p>
        <?php endif; ?>

        <?php if ( $owner && ! $is_admin ) : ?>
            <div class="ws-locked-upgrade" id="ws-upgrade">
                <h2><i class="fa-solid fa-arrow-up-right-dots"></i> <?php esc_html_e( 'Solicita un upgrade y reactiva tu negocio', 'workshop' ); ?></h2>

                <?php if ( ! empty( $data['sub'] ) && 'pending' === $data['sub']->upgrade_status ) : ?>
                    <div class="ws-alert ws-alert-info">
                        <i class="fa-solid fa-hourglass-half"></i>
                        <?php esc_html_e( 'Ya enviaste una solicitud. El administrador la aprobará y tu negocio quedará habilitado automáticamente.', 'workshop' ); ?>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field( 'ws_plan_request', 'ws_nonce' ); ?>
                            <input type="hidden" name="ws_plan_request" value="cancel">
                            <button class="ws-btn ws-btn-secondary ws-btn-sm" type="submit" style="margin-left:8px"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></button>
                        </form>
                    </div>
                <?php elseif ( ! empty( $data['sub'] ) && 'rejected' === $data['sub']->upgrade_status ) : ?>
                    <div class="ws-alert ws-alert-warning">
                        <i class="fa-solid fa-xmark"></i>
                        <?php esc_html_e( 'Tu última solicitud fue rechazada. Puedes solicitar otro plan.', 'workshop' ); ?>
                    </div>
                <?php endif; ?>

                <div class="ws-locked-usage">
                    <h3><?php esc_html_e( 'Tu uso actual', 'workshop' ); ?></h3>
                    <?php get_template_part( 'partials/plan-usage', null, array( 'data' => $data ) ); ?>
                </div>

                <div class="ws-locked-plans">
                    <?php get_template_part( 'partials/plan-cards', null, array( 'data' => $data ) ); ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="ws-locked-back">
            <a class="ws-btn ws-btn-secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
                <i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Volver al mercado', 'workshop' ); ?>
            </a>
            <?php if ( ! is_user_logged_in() ) : ?>
                <a class="ws-link" href="<?php echo esc_url( home_url( '/login/' ) ); ?>"><?php esc_html_e( 'Iniciar sesión', 'workshop' ); ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
