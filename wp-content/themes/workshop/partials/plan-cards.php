<?php
/**
 * Cuadrícula de planes con solicitud de upgrade.
 *
 * Se usa en la página "Plan" del panel y en la pantalla de bloqueo del
 * negocio. Espera $data (resultado de ws_subscription_data()).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

// get_template_part() NO comparte el ámbito del llamador: el data se pasa por
// $args (o se recalcula). Fallback por si se incluye sin datos.
$data  = ( isset( $args['data'] ) && is_array( $args['data'] ) ) ? $args['data'] : ws_subscription_data();
$plans        = WS_Plans::active();
$cur_plan_id  = ! empty( $data['plan'] ) ? (int) $data['plan']->id : 0;
$can_request  = is_user_logged_in() && 'owner' === ws_user_role();
$pending      = ! empty( $data['upgrade_pending'] );
?>
<div class="ws-plans-grid">
    <?php foreach ( $plans as $p ) :
        $limits   = WS_Plans::limits( $p );
        $current  = (int) $p->id === $cur_plan_id;
        $popular  = 'pro' === $p->slug;
        $is_trial = (int) $p->is_trial === 1;
        ?>
        <div class="ws-plan-card<?php echo $popular ? ' is-popular' : ''; ?><?php echo $current ? ' is-current' : ''; ?>">
            <?php if ( $popular ) : ?>
                <span class="ws-plan-ribbon"><i class="fa-solid fa-fire"></i> <?php esc_html_e( 'Más popular', 'workshop' ); ?></span>
            <?php endif; ?>
            <?php if ( $is_trial ) : ?>
                <span class="ws-plan-ribbon ws-plan-ribbon-trial"><i class="fa-solid fa-gift"></i> <?php esc_html_e( 'Prueba gratis', 'workshop' ); ?></span>
            <?php endif; ?>
            <h4><?php echo esc_html( $p->name ); ?></h4>
            <p class="ws-plan-price">
                <?php echo esc_html( WS_Plans::format_price( $p ) ); ?>
                <small><?php echo esc_html( WS_Plans::duration_label( $p ) ); ?></small>
            </p>
            <?php if ( ! empty( $p->description ) ) : ?>
                <p class="ws-plan-desc"><?php echo esc_html( $p->description ); ?></p>
            <?php endif; ?>
            <ul class="ws-plan-features">
                <?php foreach ( $limits as $k => $v ) : ?>
                    <li><i class="fa-solid fa-check"></i>
                        <?php echo esc_html( sprintf(
                            /* translators: 1: etiqueta del límite, 2: cantidad o ∞ */
                            __( '%1$s: %2$s', 'workshop' ),
                            ucfirst( WS_Plans::limit_label( $k ) ),
                            $v > 0 ? number_format_i18n( $v ) : '∞'
                        ) ); ?>
                    </li>
                <?php endforeach; ?>
                <li>
                    <i class="fa-solid <?php echo WS_Plans::has_chatbot( $p ) ? 'fa-check' : 'fa-xmark'; ?>"></i>
                    <?php echo esc_html( WS_Plans::has_chatbot( $p )
                        ? __( 'Asistente chatbot en tu panel', 'workshop' )
                        : __( 'Sin chatbot (mejora con upgrade)', 'workshop' ) ); ?>
                </li>
            </ul>
            <?php if ( $current ) : ?>
                <span class="ws-btn ws-btn-secondary ws-btn-block" disabled><i class="fa-solid fa-check"></i> <?php esc_html_e( 'Plan actual', 'workshop' ); ?></span>
            <?php elseif ( $pending ) : ?>
                <span class="ws-btn ws-btn-secondary ws-btn-block" disabled><i class="fa-solid fa-hourglass-half"></i> <?php esc_html_e( 'Solicitud pendiente', 'workshop' ); ?></span>
            <?php elseif ( $is_trial ) : ?>
                <span class="ws-btn ws-btn-secondary ws-btn-block" disabled><?php esc_html_e( 'Solo para negocios nuevos', 'workshop' ); ?></span>
            <?php elseif ( $can_request ) : ?>
                <form method="post" class="ws-plan-request-form">
                    <?php wp_nonce_field( 'ws_plan_request', 'ws_nonce' ); ?>
                    <input type="hidden" name="ws_plan_request" value="request">
                    <input type="hidden" name="plan_id" value="<?php echo (int) $p->id; ?>">
                    <button class="ws-btn ws-btn-primary ws-btn-block" type="submit"><i class="fa-solid fa-arrow-up-right-dots"></i> <?php esc_html_e( 'Solicitar upgrade', 'workshop' ); ?></button>
                </form>
            <?php else : ?>
                <span class="ws-btn ws-btn-secondary ws-btn-block" disabled><?php esc_html_e( 'Disponible', 'workshop' ); ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<script>
(function () {
    var cards = document.querySelectorAll('.ws-plans-grid .ws-plan-card');
    if ('IntersectionObserver' in window && cards.length) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        cards.forEach(function (el) { io.observe(el); });
    } else {
        cards.forEach(function (el) { el.classList.add('is-visible'); });
    }
})();
</script>
