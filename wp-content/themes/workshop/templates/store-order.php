<?php
/**
 * Confirmación de pedido en la tienda pública.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$location = get_query_var( 'ws_location' );
$order    = get_query_var( 'ws_order' );
$items    = WS_Orders::get_items( $order->id );
$whatsapp = ws_whatsapp_order_url( $order, $location );
$rate_badge = ws_rate_badge();
$wa_numbers = ws_whatsapp_numbers( $location->id );

get_header();
?>
<div class="ws-container ws-order-done">
    <div class="ws-order-done-card">
        <i class="fa-solid fa-circle-check ws-success-icon"></i>
        <h1><?php esc_html_e( '¡Pedido recibido!', 'workshop' ); ?></h1>
        <p class="ws-order-number"><?php echo esc_html( $order->number ); ?></p>
        <p class="ws-order-status">
            <span class="ws-badge ws-badge-<?php echo esc_attr( $order->status ); ?>"><?php echo esc_html( WS_Orders::status_label( $order->status ) ); ?></span>
        </p>
        <?php if ( 'pending' === $order->status ) : ?>
            <p><?php esc_html_e( 'Tu pedido está pendiente de confirmación por el punto de venta. Te notificarán por teléfono.', 'workshop' ); ?></p>
        <?php elseif ( 'accepted' === $order->status ) : ?>
            <p><?php esc_html_e( 'Tu pedido fue aceptado. Estamos preparándolo.', 'workshop' ); ?></p>
        <?php elseif ( 'completed' === $order->status ) : ?>
            <p><?php esc_html_e( 'Tu pedido fue completado. ¡Gracias por tu compra!', 'workshop' ); ?></p>
        <?php elseif ( 'rejected' === $order->status ) : ?>
            <p><?php esc_html_e( 'Tu pedido fue rechazado. Contáctanos por teléfono para más información.', 'workshop' ); ?></p>
        <?php elseif ( 'cancelled' === $order->status ) : ?>
            <p><?php esc_html_e( 'Tu pedido fue cancelado.', 'workshop' ); ?></p>
        <?php endif; ?>

        <div class="ws-order-summary">
            <div><span><?php esc_html_e( 'Tienda', 'workshop' ); ?></span><strong><?php echo esc_html( $location->name ); ?></strong></div>
            <div><span><?php esc_html_e( 'Cliente', 'workshop' ); ?></span><strong><?php echo esc_html( $order->customer_name ); ?></strong></div>
            <div><span><?php esc_html_e( 'Teléfono', 'workshop' ); ?></span><strong><?php echo esc_html( $order->customer_phone ); ?></strong></div>
            <?php if ( $order->customer_address ) : ?>
                <div><span><?php esc_html_e( 'Dirección', 'workshop' ); ?></span><strong><?php echo esc_html( $order->customer_address ); ?></strong></div>
            <?php endif; ?>
            <table class="ws-table">
                <thead><tr><th><?php esc_html_e( 'Producto', 'workshop' ); ?></th><th>Cant.</th><th><?php esc_html_e( 'Precio', 'workshop' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $items as $item ) : ?>
                    <tr>
                        <td><?php echo esc_html( $item->product_name ); ?></td>
                        <td><?php echo esc_html( $item->qty ); ?></td>
                        <td><?php echo ws_money( $item->price * $item->qty, $order->currency ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php $transfer_total = ws_order_transfer_total( $order ); ?>
            <div class="ws-summary-total">
                <div><span><?php esc_html_e( 'Subtotal', 'workshop' ); ?></span><strong><?php echo ws_money( $order->subtotal, $order->currency ); ?></strong></div>
                <div><span><?php esc_html_e( 'Domicilio', 'workshop' ); ?></span><strong><?php echo ws_money( $order->delivery_cost, $order->delivery_currency ? $order->delivery_currency : $order->currency ); ?></strong></div>
                <div class="ws-total"><span><?php esc_html_e( 'Total en efectivo', 'workshop' ); ?></span><strong><?php echo ws_money( $order->total, $order->currency ); ?></strong></div>
                <?php if ( abs( $transfer_total - (float) $order->total ) > 0.001 ) : ?>
                    <div class="ws-total ws-total-transfer"><span><?php esc_html_e( 'Total en transferencia', 'workshop' ); ?></span><strong><?php echo ws_money( $transfer_total, $order->currency ); ?></strong></div>
                <?php endif; ?>
            </div>
            <?php if ( $rate_badge ) : ?>
                <p class="ws-muted ws-attend-note"><i class="fa-solid fa-arrow-right-arrow-left"></i> <?php echo esc_html( $rate_badge ); ?></p>
            <?php endif; ?>
            <?php $wa_raw = $wa_numbers ? implode( ' · ', $wa_numbers ) : ( $location->whatsapp ?? '' ); ?>
            <?php if ( $wa_raw ) : ?>
                <p class="ws-muted ws-attend-note"><i class="fa-brands fa-whatsapp"></i> <?php echo esc_html( sprintf( __( 'Tu pedido lo atiende: %s', 'workshop' ), $wa_raw ) ); ?></p>
            <?php endif; ?>
        </div>

        <?php if ( $whatsapp ) : ?>
            <a class="ws-btn ws-btn-success ws-btn-block" href="<?php echo esc_url( $whatsapp ); ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-whatsapp"></i> <?php esc_html_e( 'Enviar pedido por WhatsApp', 'workshop' ); ?>
            </a>
        <?php endif; ?>
        <a class="ws-btn ws-btn-primary ws-btn-block" href="<?php echo esc_url( ws_store_url( $location ) ); ?>">
            <i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Seguir comprando', 'workshop' ); ?>
        </a>
    </div>
</div>
<?php get_footer(); ?>
