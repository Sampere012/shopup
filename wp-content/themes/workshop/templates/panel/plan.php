<?php
/**
 * Panel: Plan y suscripción del negocio.
 *
 * Muestra el plan actual, los días restantes de prueba/plan, el uso frente a
 * los límites y los planes disponibles para solicitar un upgrade (que el
 * administrador aprueba desde wp-admin).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$data = ws_subscription_data();
$msg  = sanitize_key( (string) ( $_GET['ws_plan_msg'] ?? '' ) );

// Estados de la solicitud del formulario server-side.
$plan_msg = array();
if ( 'ok=requested' === $msg ) {
    $plan_msg = array( 'success', __( 'Solicitud enviada. El administrador la revisará y habilitará tu plan.', 'workshop' ) );
} elseif ( 'ok=cancelled' === $msg ) {
    $plan_msg = array( 'success', __( 'Solicitud cancelada.', 'workshop' ) );
} elseif ( 0 === strpos( $msg, 'error=' ) ) {
    $plan_msg = array( 'error', urldecode( substr( $msg, 6 ) ) );
}
?>
<div class="ws-plan-page">
    <div class="ws-toolbar">
        <div>
            <h3 class="ws-card-title" style="margin:0"><i class="fa-solid fa-crown"></i> <?php esc_html_e( 'Plan y suscripción', 'workshop' ); ?></h3>
            <p class="ws-muted" style="margin:6px 0 0"><?php esc_html_e( 'Tu plan define la capacidad de tu negocio. Si necesitas más, solicita un upgrade y el administrador lo aprobará.', 'workshop' ); ?></p>
        </div>
    </div>

    <?php if ( $plan_msg ) : ?>
        <div class="ws-alert ws-alert-<?php echo 'error' === $plan_msg[0] ? 'error' : 'success'; ?>"><?php echo esc_html( $plan_msg[1] ); ?></div>
    <?php endif; ?>

    <?php if ( ! empty( $data['lock'] ) ) : ?>
        <div class="ws-alert ws-alert-error">
            <i class="fa-solid fa-lock"></i>
            <strong><?php echo esc_html( $data['lock']['title'] ); ?>.</strong>
            <?php echo esc_html( $data['lock']['message'] ); ?>
        </div>
    <?php elseif ( $data['upgrade_pending'] && ! empty( $data['upgrade_plan'] ) ) : ?>
        <div class="ws-alert ws-alert-info">
            <i class="fa-solid fa-hourglass-half"></i>
            <?php echo esc_html( sprintf(
                __( 'Tienes una solicitud pendiente para el plan %s. El administrador la revisará y habilitará tu negocio cuando la apruebe.', 'workshop' ),
                $data['upgrade_plan']->name
            ) ); ?>
            <form method="post" style="display:inline">
                <?php wp_nonce_field( 'ws_plan_request', 'ws_nonce' ); ?>
                <input type="hidden" name="ws_plan_request" value="cancel">
                <button class="ws-btn ws-btn-secondary ws-btn-sm" type="submit" style="margin-left:8px"><?php esc_html_e( 'Cancelar solicitud', 'workshop' ); ?></button>
            </form>
        </div>
    <?php elseif ( ! empty( $data['sub'] ) && 'rejected' === $data['sub']->upgrade_status ) : ?>
        <div class="ws-alert ws-alert-warning">
            <i class="fa-solid fa-xmark"></i>
            <?php esc_html_e( 'Tu última solicitud de plan fue rechazada. Puedes solicitar otro plan.', 'workshop' ); ?>
        </div>
    <?php endif; ?>

    <div class="ws-plan-current">
        <div class="ws-plan-current-card">
            <span class="ws-plan-current-icon"><i class="fa-solid <?php echo $data['is_trial'] ? 'fa-gift' : 'fa-crown'; ?>"></i></span>
            <div>
                <small class="ws-muted"><?php esc_html_e( 'Plan actual', 'workshop' ); ?></small>
                <h3><?php echo esc_html( ! empty( $data['plan'] ) ? $data['plan']->name : __( '—', 'workshop' ) ); ?></h3>
                <p class="ws-muted" style="margin:4px 0 0">
                    <?php if ( $data['is_trial'] ) : ?>
                        <?php echo esc_html( sprintf(
                            _n( 'Te queda %d día de prueba gratis', 'Te quedan %d días de prueba gratis', $data['trial_days_left'], 'workshop' ),
                            $data['trial_days_left']
                        ) ); ?>
                    <?php elseif ( $data['is_active'] && $data['plan_days_left'] > 0 ) : ?>
                        <?php echo esc_html( sprintf(
                            _n( 'Tu plan vence en %d día', 'Tu plan vence en %d días', $data['plan_days_left'], 'workshop' ),
                            $data['plan_days_left']
                        ) ); ?>
                    <?php else : ?>
                        <?php echo esc_html( $data['status_label'] ); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="ws-plan-current-meta">
            <div><strong><?php echo esc_html( ! empty( $data['plan'] ) ? WS_Plans::format_price( $data['plan'] ) : '—' ); ?></strong><small><?php esc_html_e( 'Precio', 'workshop' ); ?></small></div>
            <div><strong><?php echo esc_html( ! empty( $data['plan'] ) ? WS_Plans::duration_label( $data['plan'] ) : '—' ); ?></strong><small><?php esc_html_e( 'Duración', 'workshop' ); ?></small></div>
            <div><strong><?php echo esc_html( $data['status_label'] ); ?></strong><small><?php esc_html_e( 'Estado', 'workshop' ); ?></small></div>
        </div>
    </div>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-chart-simple"></i> <?php esc_html_e( 'Uso de tu plan', 'workshop' ); ?></h3>
        <p class="ws-muted" style="margin-top:6px"><?php esc_html_e( 'Si superas un límite, tu negocio queda en pausa hasta que liberes recursos o subas de plan.', 'workshop' ); ?></p>
        <?php get_template_part( 'partials/plan-usage', null, array( 'data' => $data ) ); ?>
    </div>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-arrow-up-right-dots"></i> <?php esc_html_e( 'Cambiar de plan', 'workshop' ); ?></h3>
        <p class="ws-muted" style="margin-top:6px"><?php esc_html_e( 'Elige el plan que necesitas y envía la solicitud. El administrador la aprobará y tu negocio quedará habilitado.', 'workshop' ); ?></p>
        <?php get_template_part( 'partials/plan-cards', null, array( 'data' => $data ) ); ?>
    </div>
</div>
