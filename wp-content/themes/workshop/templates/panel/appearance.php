<?php
/**
 * Panel: apariencia del sitio (logo, nombre, colores, favicon, portada, pie, CSS).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$theme      = ws_site_theme();
$can_site   = ws_can( 'site_manage' );
$can_layout = ws_can( 'layout_manage' ) || $can_site;
?>
<div x-data="wsAppearance(<?php echo esc_attr( wp_json_encode( array(
    'canSite'   => $can_site,
    'canLayout' => $can_layout,
    'name'      => ws_site_name(),
    'logo'      => ws_site_logo(),
    'favicon'   => $theme['favicon'],
    'primary'   => $theme['primary'],
    'accent'    => $theme['accent'],
    'hero_badge' => $theme['hero_badge'],
    'hero_title' => $theme['hero_title'],
    'hero_sub'   => $theme['hero_sub'],
    'hero_bg'    => $theme['hero_bg'],
    'hero_gradient' => $theme['hero_gradient'],
    'footer_text' => $theme['footer_text'],
    'defaults'   => array(
        'name'  => get_option( 'blogname' ),
        'logo'  => '',
        'favicon' => '',
        'primary' => '#4f46e5',
        'accent'  => '#f59e0b',
        'hero_badge' => '',
        'hero_title' => '',
        'hero_sub'   => '',
        'hero_bg'    => '',
        'hero_gradient' => '',
        'footer_text' => '',
    ),
) ) ); ?>)">

    <div class="ws-toolbar">
        <div>
            <h3 class="ws-card-title" style="margin:0"><i class="fa-solid fa-palette"></i> <?php esc_html_e( 'Apariencia del sitio', 'workshop' ); ?></h3>
            <p class="ws-muted" style="margin:6px 0 0"><?php esc_html_e( 'Los cambios se aplican en tiempo real en esta página. Usa «Guardar» para publicarlos en todo el sitio.', 'workshop' ); ?></p>
        </div>
        <div class="ws-toolbar-actions">
            <label class="ws-check" style="margin-right:6px"><input type="checkbox" x-model="live"><span><?php esc_html_e( 'Vista previa en vivo', 'workshop' ); ?></span></label>
            <button class="ws-btn ws-btn-secondary" @click="reset()"><i class="fa-solid fa-rotate-left"></i> <?php esc_html_e( 'Restablecer', 'workshop' ); ?></button>
            <button class="ws-btn ws-btn-primary" @click="save()" :disabled="busy"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
        </div>
    </div>

    <div class="ws-appearance-grid">
        <div class="ws-appearance-fields">

            <template x-if="canSite">
            <div class="ws-card">
                <h3 class="ws-card-title"><i class="fa-solid fa-id-badge"></i> <?php esc_html_e( 'Identidad y marca', 'workshop' ); ?></h3>
                <div class="ws-form-grid">
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Nombre del negocio', 'workshop' ); ?></span>
                        <input type="text" x-model="name" placeholder="Mi negocio">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Logo (URL de imagen)', 'workshop' ); ?></span>
                        <input type="url" x-model="logo" placeholder="https://…/logo.png">
                        <small class="ws-muted"><?php esc_html_e( 'Se muestra en la barra de navegación, el pie y el panel.', 'workshop' ); ?></small>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Favicon (URL)', 'workshop' ); ?></span>
                        <input type="url" x-model="favicon" placeholder="https://…/favicon.png">
                    </label>
                </div>
            </div>
            </template>

            <template x-if="canSite">
            <div class="ws-card">
                <h3 class="ws-card-title"><i class="fa-solid fa-droplet"></i> <?php esc_html_e( 'Paleta de colores', 'workshop' ); ?></h3>
                <div class="ws-form-grid">
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Color principal', 'workshop' ); ?></span>
                        <div class="ws-color-row">
                            <input type="color" x-model="primary">
                            <input type="text" x-model="primary" class="ws-color-hex">
                        </div>
                    </label>
                    <label class="ws-field">
                        <span><?php esc_html_e( 'Color de acento', 'workshop' ); ?></span>
                        <div class="ws-color-row">
                            <input type="color" x-model="accent">
                            <input type="text" x-model="accent" class="ws-color-hex">
                        </div>
                    </label>
                    <div class="ws-span-2" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                        <p class="ws-muted" style="margin:0"><?php esc_html_e( 'El color principal se aplica a botones, enlaces, sidebar y acentos del sitio. El acento se usa en detalles y etiquetas.', 'workshop' ); ?></p>
                        <button type="button" class="ws-btn ws-btn-secondary ws-btn-sm" @click="detectPalette()" :disabled="!logo || busy">
                            <i class="fa-solid fa-wand-sparkles"></i> <?php esc_html_e( 'Detectar paleta del logo', 'workshop' ); ?>
                        </button>
                    </div>
                </div>
            </div>
            </template>

            <template x-if="canLayout">
            <div class="ws-card">
                <h3 class="ws-card-title"><i class="fa-solid fa-panorama"></i> <?php esc_html_e( 'Portada y pie de página', 'workshop' ); ?></h3>
                <div class="ws-form-grid">
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Etiqueta de portada (badge)', 'workshop' ); ?></span>
                        <input type="text" x-model="hero_badge" placeholder="Pedidos y stock en tiempo real">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Título de portada', 'workshop' ); ?></span>
                        <input type="text" x-model="hero_title" placeholder="Tu negocio, multi-tienda">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Subtítulo de portada', 'workshop' ); ?></span>
                        <input type="text" x-model="hero_sub" placeholder="Elige tu punto de venta…">
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Imagen de fondo del hero (URL)', 'workshop' ); ?></span>
                        <input type="url" x-model="hero_bg" placeholder="https://…/portada.jpg">
                        <small class="ws-muted"><?php esc_html_e( 'Si pones una imagen, se usa como fondo del hero del sitio. Si está vacía, se usa el gradiente.', 'workshop' ); ?></small>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Gradiente del hero (CSS)', 'workshop' ); ?></span>
                        <input type="text" x-model="hero_gradient" placeholder="linear-gradient(160deg, #171b3a, #4f46e5)">
                        <small class="ws-muted"><?php esc_html_e( 'Solo se usa si no hay imagen de fondo. Ej.: radial-gradient(circle, #312e81, #171b3a)', 'workshop' ); ?></small>
                    </label>
                    <label class="ws-field ws-span-2">
                        <span><?php esc_html_e( 'Descripción del pie de página', 'workshop' ); ?></span>
                        <input type="text" x-model="footer_text" placeholder="Multi-tienda conectada…">
                    </label>
                </div>
            </div>
            </template>

        </div>

        <div class="ws-appearance-preview">
            <div class="ws-card">
                <h3 class="ws-card-title"><i class="fa-solid fa-eye"></i> <?php esc_html_e( 'Vista previa', 'workshop' ); ?></h3>

                <div class="ws-pv-nav" :style="{ borderColor: primary + '55' }">
                    <template x-if="logo">
                        <img class="ws-pv-logo" :src="logo" alt="logo">
                    </template>
                    <template x-if="!logo">
                        <span class="ws-pv-logo ws-pv-logo-ic" :style="{ background: 'linear-gradient(135deg, ' + primary + ', ' + primary + 'aa)' }"><i class="fa-solid fa-store"></i></span>
                    </template>
                    <strong x-text="name || 'Mi negocio'" style="font-family:var(--ws-font-display)"></strong>
                </div>

                <div class="ws-pv-hero" :class="hero_bg ? 'ws-has-bg' : ''" :style="pvHeroStyle()">
                    <span class="ws-pv-badge"><i class="fa-solid fa-bolt"></i> <span x-text="hero_badge || 'Pedidos y stock en tiempo real'"></span></span>
                    <h4 x-text="hero_title || 'Tu negocio, multi-tienda'"></h4>
                    <p x-text="hero_sub || 'Elige tu punto de venta para ver productos y realizar pedidos.'"></p>
                </div>

                <div class="ws-pv-row">
                    <button class="ws-btn ws-btn-primary ws-btn-sm"><i class="fa-solid fa-cart-shopping"></i> <?php esc_html_e( 'Botón principal', 'workshop' ); ?></button>
                    <span class="ws-chip" :style="{ background: accent + '22', color: accent }"><i class="fa-solid fa-money-bill"></i> <?php esc_html_e( 'Acento', 'workshop' ); ?></span>
                </div>

                <div class="ws-pv-footer" :style="{ borderTop: '2px solid ' + accent }">
                    <strong x-text="name || 'Mi negocio'" style="font-family:var(--ws-font-display)"></strong>
                    <p x-text="footer_text || 'Multi-tienda conectada: pedidos, stock en tiempo real y control para tu negocio.'"></p>
                </div>
            </div>
        </div>
    </div>
</div>
