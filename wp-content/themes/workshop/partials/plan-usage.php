<?php
/**
 * Barras de uso vs límites del plan actual.
 *
 * Espera $data (resultado de ws_subscription_data()).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

// get_template_part() NO comparte el ámbito del llamador: el data se pasa por
// $args (o se recalcula). Fallback por si se incluye sin datos.
$data  = ( isset( $args['data'] ) && is_array( $args['data'] ) ) ? $args['data'] : ws_subscription_data();
$limits = ! empty( $data['limits'] ) ? $data['limits'] : array();
$usage  = ! empty( $data['usage'] ) ? $data['usage'] : array();
?>
<div class="ws-usage-grid">
    <?php foreach ( WS_Plans::LIMIT_KEYS as $k ) :
        $lim = (int) ( $limits[ $k ] ?? 0 );
        $use = (int) ( $usage[ $k ] ?? 0 );
        $pct = $lim > 0 ? (int) min( 100, round( $use / $lim * 100 ) ) : 0;
        $over = $lim > 0 && $use > $lim;
        $cls  = $over ? ' is-over' : ( $pct >= 80 ? ' is-warn' : '' );
        ?>
        <div class="ws-usage-item<?php echo esc_attr( $cls ); ?>">
            <div class="ws-usage-head">
                <span class="ws-usage-label"><i class="fa-solid <?php echo esc_attr( WS_Plans::limit_icon( $k ) ); ?>"></i>
                    <?php echo esc_html( ucfirst( WS_Plans::limit_label( $k ) ) ); ?>
                </span>
                <span class="ws-usage-count">
                    <?php echo esc_html( number_format_i18n( $use ) ); ?> / <?php echo $lim > 0 ? esc_html( number_format_i18n( $lim ) ) : '∞'; ?>
                    <?php if ( $over ) : ?>
                        <i class="fa-solid fa-triangle-exclamation ws-text-danger" title="<?php esc_attr_e( 'Límite superado', 'workshop' ); ?>"></i>
                    <?php endif; ?>
                </span>
            </div>
            <div class="ws-usage-track">
                <div class="ws-usage-fill" style="width:<?php echo $lim > 0 ? esc_attr( (string) $pct ) : '0'; ?>%"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
