<?php
/**
 * Registro público de negocios (2 pasos).
 *
 * Paso 1: datos del negocio y del dueño. Paso 2: verificación del email con el
 * código de 6 dígitos enviado por SMTP. Al verificarlo se crea el negocio con
 * su prueba gratis y el usuario queda logueado en su panel.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="ws-auth-wrap">
    <div class="ws-auth-side">
        <span class="ws-auth-badge"><i class="fa-solid fa-gift"></i> <?php esc_html_e( '7 días gratis', 'workshop' ); ?></span>
        <h2><?php esc_html_e( 'Crea tu tienda gratis y empieza a vender hoy', 'workshop' ); ?></h2>
        <p><?php esc_html_e( 'Registra tu negocio, verifica tu correo con un código de 6 dígitos y en menos de 5 minutos tendrás tu tienda online, tu panel y tus puntos de venta.', 'workshop' ); ?></p>
        <div class="ws-auth-features">
            <div class="ws-auth-feature"><i class="fa-solid fa-store"></i><div><strong><?php esc_html_e( 'Tu tienda en el mercado', 'workshop' ); ?></strong><small><?php esc_html_e( 'Visible para todos al instante', 'workshop' ); ?></small></div></div>
            <div class="ws-auth-feature"><i class="fa-solid fa-palette"></i><div><strong><?php esc_html_e( 'Personaliza tu marca', 'workshop' ); ?></strong><small><?php esc_html_e( 'Logo, colores y portada', 'workshop' ); ?></small></div></div>
            <div class="ws-auth-feature"><i class="fa-solid fa-cash-register"></i><div><strong><?php esc_html_e( 'POS y pedidos', 'workshop' ); ?></strong><small><?php esc_html_e( 'Vende y controla tu stock', 'workshop' ); ?></small></div></div>
            <div class="ws-auth-feature"><i class="fa-solid fa-chart-line"></i><div><strong><?php esc_html_e( 'Reportes en vivo', 'workshop' ); ?></strong><small><?php esc_html_e( 'Decisiones con datos', 'workshop' ); ?></small></div></div>
        </div>
    </div>
    <div class="ws-auth-form-wrap">
        <div class="ws-auth-card ws-register-card" x-data="wsRegister()">
            <h1 class="ws-brand-name"><?php echo esc_html( ws_site_name() ); ?></h1>

            <!-- Paso 1: datos -->
            <template x-if="step === 1">
                <div>
                    <p class="ws-auth-sub"><?php esc_html_e( 'Crea tu negocio gratis', 'workshop' ); ?></p>
                    <div class="ws-alert ws-alert-error" x-show="error" x-cloak x-text="error"></div>
                    <form class="ws-form" @submit.prevent="submitStep1" x-ref="form1">
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Nombre del negocio', 'workshop' ); ?> *</span>
                            <input type="text" x-model="form.biz_name" @input="form.slug = form.slug || slugify(form.biz_name)" required>
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Dirección de tu tienda (URL)', 'workshop' ); ?> *</span>
                            <div class="ws-input-prefix">
                                <span class="ws-input-prefix-tag"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>/</span>
                                <input type="text" x-model="form.slug" @input="form.slug = slugify(form.slug)" placeholder="mi-negocio" required>
                            </div>
                            <small class="ws-muted"><?php esc_html_e( 'Solo letras, números y guiones. Ej: mi-tienda', 'workshop' ); ?></small>
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Tu nombre', 'workshop' ); ?> *</span>
                            <input type="text" x-model="form.owner_name" required>
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Email', 'workshop' ); ?> *</span>
                            <input type="email" x-model="form.email" @input="form.username = form.username || slugify(form.email.split('@')[0])" required>
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Teléfono / WhatsApp', 'workshop' ); ?></span>
                            <input type="text" x-model="form.phone" placeholder="+34 600 000 000">
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Usuario', 'workshop' ); ?> *</span>
                            <input type="text" x-model="form.username" required>
                        </label>
                        <label class="ws-field">
                            <span><?php esc_html_e( 'Contraseña', 'workshop' ); ?> *</span>
                            <input type="password" x-model="form.password" minlength="8" placeholder="<?php esc_attr_e( 'Mínimo 8 caracteres', 'workshop' ); ?>" required>
                        </label>
                        <button class="ws-btn ws-btn-primary ws-btn-block" type="submit" :disabled="busy">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span x-text="busy ? '<?php esc_attr_e( 'Enviando…', 'workshop' ); ?>' : '<?php esc_attr_e( 'Crear cuenta y enviar código', 'workshop' ); ?>'"></span>
                        </button>
                    </form>
                    <p class="ws-auth-switch">
                        <?php esc_html_e( '¿Ya tienes cuenta?', 'workshop' ); ?>
                        <a href="<?php echo esc_url( home_url( '/login/' ) ); ?>"><?php esc_html_e( 'Inicia sesión', 'workshop' ); ?></a>
                    </p>
                </div>
            </template>

            <!-- Paso 2: verificación -->
            <template x-if="step === 2">
                <div>
                    <p class="ws-auth-sub"><?php esc_html_e( 'Verifica tu correo', 'workshop' ); ?></p>
                    <p class="ws-muted" style="text-align:center">
                        <?php esc_html_e( 'Enviamos un código de 6 dígitos a', 'workshop' ); ?>
                        <strong x-text="form.email" class="ws-text-primary"></strong>
                    </p>
                    <div class="ws-alert ws-alert-error" x-show="error" x-cloak x-text="error"></div>
                    <div class="ws-otp">
                        <template x-for="(c, i) in otp" :key="i">
                            <input type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code"
                                   :ref="'d' + i"
                                   x-model="otp[i]"
                                   @input="onOtpInput($event, i)"
                                   @keydown.backspace="onOtpBack(i)"
                                   @paste="onOtpPaste($event, i)">
                        </template>
                    </div>
                    <button class="ws-btn ws-btn-primary ws-btn-block" type="button" @click="submitStep2()" :disabled="busy || !otpFilled()">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span x-text="busy ? '<?php esc_attr_e( 'Verificando…', 'workshop' ); ?>' : '<?php esc_attr_e( 'Verificar y crear mi negocio', 'workshop' ); ?>'"></span>
                    </button>
                    <p class="ws-auth-switch">
                        <span x-show="resendIn > 0"><i class="fa-solid fa-clock"></i> <?php esc_html_e( 'Reenviar en', 'workshop' ); ?> <strong x-text="resendIn"></strong>s</span>
                        <button type="button" class="ws-link-btn" @click="resend()" x-show="resendIn <= 0" x-cloak>
                            <i class="fa-solid fa-rotate-right"></i> <?php esc_html_e( 'Reenviar código', 'workshop' ); ?>
                        </button>
                    </p>
                    <p class="ws-auth-switch">
                        <button type="button" class="ws-link-btn" @click="step = 1; error = ''">
                            <i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Volver a los datos', 'workshop' ); ?>
                        </button>
                    </p>
                </div>
            </template>
        </div>
    </div>
</div>
<?php get_footer(); ?>
