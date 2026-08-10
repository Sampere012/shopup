<?php
/**
 * Panel: configuración general del negocio.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$currency        = get_option( 'ws_currency', '€' );
$currencies      = get_option( 'ws_currencies', '' );
$rates           = ws_exchange_rates();
$rates_updated   = get_option( 'ws_rates_updated', '' );
$payment_methods = (array) get_option( 'ws_payment_methods', array( 'Efectivo', 'Tarjeta', 'Transferencia' ) );
$whatsapp        = get_option( 'ws_whatsapp', '' );
?>
<div x-data="wsSettings(<?php echo esc_attr( wp_json_encode( array(
    'currency'        => $currency,
    'currencies'      => $currencies,
    'rates'           => $rates,
    'rates_updated'   => $rates_updated,
    'payment_methods' => $payment_methods,
    'whatsapp'        => $whatsapp,
) ) ); ?>)" x-init="initRates()">

    <div class="ws-grid-2">
        <div class="ws-card">
            <h3 class="ws-card-title"><i class="fa-solid fa-coins"></i> <?php esc_html_e( 'Monedas del negocio', 'workshop' ); ?></h3>
            <p class="ws-muted"><?php esc_html_e( 'Escribe las monedas separadas por coma. La primera es la moneda por defecto al crear un producto. Ejemplo: USD, CUP', 'workshop' ); ?></p>
            <form @submit.prevent="save" class="ws-form">
                <label class="ws-field">
                    <span><?php esc_html_e( 'Monedas (separadas por coma)', 'workshop' ); ?></span>
                    <input type="text" x-model="currencies" placeholder="USD, CUP" @input="syncCurrencies()">
                </label>
                <label class="ws-field">
                    <span><?php esc_html_e( 'Moneda por defecto', 'workshop' ); ?></span>
                    <select x-model="currency">
                        <template x-for="c in currencyList" :key="c"><option :value="c" x-text="c"></option></template>
                    </select>
                </label>
                <button class="ws-btn ws-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
            </form>
        </div>

        <div class="ws-card">
            <h3 class="ws-card-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> <?php esc_html_e( 'Tasa de cambio', 'workshop' ); ?></h3>
            <p class="ws-muted"><?php esc_html_e( 'Equivalencia de cada moneda respecto a la moneda por defecto. Ejemplo: 1 USD = 670 CUP', 'workshop' ); ?></p>
            <form @submit.prevent="save" class="ws-form">
                <template x-for="c in currencyList" :key="'rate-' + c">
                    <label class="ws-field" x-show="c !== currency">
                        <span x-text="'1 ' + c + ' = ? ' + currency"></span>
                        <input type="number" step="0.000001" min="0" x-model.number="rates[c]" :placeholder="'1 ' + c + ' = ' + currency">
                    </label>
                </template>
                <button type="button" class="ws-btn ws-btn-secondary" @click="fetchRate()" :disabled="rateBusy">
                    <i class="fa-solid fa-cloud-arrow-down" :class="rateBusy ? 'fa-spin' : ''"></i>
                    <span x-text="rateBusy ? 'Consultando El Toque…' : 'Actualizar tasa desde El Toque'"></span>
                </button>
                <p class="ws-muted" x-show="rates_updated"><?php esc_html_e( 'Última actualización:', 'workshop' ); ?> <strong x-text="rates_updated"></strong></p>
                <button class="ws-btn ws-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
            </form>
        </div>

        <div class="ws-card">
            <h3 class="ws-card-title"><i class="fa-brands fa-whatsapp"></i> <?php esc_html_e( 'WhatsApp para pedidos', 'workshop' ); ?></h3>
            <p class="ws-muted"><?php esc_html_e( 'Números que atienden pedidos. Sepáralos por coma para mostrar un desplegable al cliente. Cada tienda puede tener los suyos.', 'workshop' ); ?></p>
            <form @submit.prevent="save" class="ws-form">
                <label class="ws-field">
                    <span><?php esc_html_e( 'Números (separados por coma)', 'workshop' ); ?></span>
                    <input type="text" x-model="whatsapp" placeholder="+58 412 123 4567, +58 416 987 6543">
                </label>
                <button class="ws-btn ws-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
            </form>
        </div>

        <div class="ws-card">
            <h3 class="ws-card-title"><i class="fa-solid fa-credit-card"></i> <?php esc_html_e( 'Métodos de pago', 'workshop' ); ?></h3>
            <p class="ws-muted"><?php esc_html_e( 'Se usan como opciones por defecto en las tiendas.', 'workshop' ); ?></p>
            <form @submit.prevent="save" class="ws-form">
                <div class="ws-check-group">
                    <template x-for="(m, i) in ['Efectivo','Tarjeta','Transferencia','Pago móvil','Cheque','Otro']" :key="m">
                        <label class="ws-check"><input type="checkbox" :value="m" x-model="payment_methods"><span x-text="m"></span></label>
                    </template>
                </div>
                <button class="ws-btn ws-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
            </form>
        </div>
    </div>

    <div class="ws-card">
        <h3 class="ws-card-title"><i class="fa-solid fa-circle-info"></i> <?php esc_html_e( 'Sobre este sistema', 'workshop' ); ?></h3>
        <p class="ws-muted">
            <?php esc_html_e( 'Tienda virtual multiubicación. Los pedidos realizados en la tienda pública de cada PV llegan al panel del vendedor, quien los acepta o rechaza; al aceptar, el stock se descuenta de forma atómica y queda registro en el historial.', 'workshop' ); ?>
        </p>
        <!-- <p class="ws-muted">WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?> · PHP <?php echo esc_html( PHP_VERSION ); ?></p> -->
    </div>
</div>
