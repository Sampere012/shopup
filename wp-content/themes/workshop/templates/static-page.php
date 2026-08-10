<?php
/**
 * Página estática (Ayuda, Contacto o Acerca de nosotros).
 *
 * El contenido se edita desde wp-admin (ShopUp → Páginas y pie). La ruta
 * (hola, /contacto/ o /acerca/) decide qué página se muestra vía ws_public.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$ws_page   = (string) get_query_var( 'ws_public', 'help' );
$ws_pages  = ws_site_pages();
$ws_map    = array( 'ayuda' => 'help', 'contacto' => 'contact', 'acerca' => 'about' );
$ws_key    = isset( $ws_map[ $ws_page ] ) ? $ws_map[ $ws_page ] : 'help';
$ws_cur    = $ws_pages[ $ws_key ];

get_header();
?>
<div class="ws-landing ws-static-page">
    <section class="ws-static-hero<?php echo ws_site_hero_has_bg() ? ' ws-has-bg' : ''; ?>" style="<?php echo esc_attr( ws_site_hero_bg_style() ); ?>">
        <div class="ws-container ws-static-hero-inner">
            <span class="ws-hero-badge"><i class="fa-solid <?php echo 'contact' === $ws_key ? 'fa-envelope' : ( 'about' === $ws_key ? 'fa-users' : 'fa-circle-question' ); ?>"></i>
                <?php echo esc_html( $ws_cur['title'] ); ?>
            </span>
            <?php if ( 'contact' === $ws_key ) : ?>
                <h1><?php echo esc_html( $ws_cur['title'] ); ?></h1>
            <?php else : ?>
                <h1><?php echo esc_html( $ws_cur['title'] ); ?></h1>
            <?php endif; ?>
        </div>
    </section>

    <main class="ws-container">
        <div class="ws-static-body">
            <?php if ( 'contact' === $ws_key ) : ?>
                <div class="ws-static-content">
                    <?php if ( '' !== trim( $ws_cur['content'] ) ) : ?>
                        <?php echo wp_kses_post( $ws_cur['content'] ); ?>
                    <?php else : ?>
                        <p><?php esc_html_e( '¿Tienes dudas o quieres ponerte en contacto con nosotros? Usa cualquiera de estas vías y te responderemos lo antes posible.', 'workshop' ); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ( '' !== trim( $ws_cur['email'] ) || '' !== trim( $ws_cur['phone'] ) || '' !== trim( $ws_cur['address'] ) ) : ?>
                    <div class="ws-contact-cards">
                        <?php if ( '' !== trim( $ws_cur['email'] ) ) : ?>
                            <div class="ws-contact-card">
                                <span class="ws-contact-ico"><i class="fa-solid fa-envelope"></i></span>
                                <h3><?php esc_html_e( 'Correo', 'workshop' ); ?></h3>
                                <a href="mailto:<?php echo esc_attr( $ws_cur['email'] ); ?>"><?php echo esc_html( $ws_cur['email'] ); ?></a>
                            </div>
                        <?php endif; ?>
                        <?php if ( '' !== trim( $ws_cur['phone'] ) ) : ?>
                            <div class="ws-contact-card">
                                <span class="ws-contact-ico"><i class="fa-solid fa-phone"></i></span>
                                <h3><?php esc_html_e( 'Teléfono', 'workshop' ); ?></h3>
                                <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $ws_cur['phone'] ) ); ?>"><?php echo esc_html( $ws_cur['phone'] ); ?></a>
                            </div>
                        <?php endif; ?>
                        <?php if ( '' !== trim( $ws_cur['address'] ) ) : ?>
                            <div class="ws-contact-card">
                                <span class="ws-contact-ico"><i class="fa-solid fa-location-dot"></i></span>
                                <h3><?php esc_html_e( 'Dirección', 'workshop' ); ?></h3>
                                <span><?php echo esc_html( $ws_cur['address'] ); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="ws-static-content">
                    <?php if ( '' !== trim( $ws_cur['content'] ) ) : ?>
                        <?php echo wp_kses_post( $ws_cur['content'] ); ?>
                    <?php else : ?>
                        <?php if ( 'help' === $ws_key ) : ?>
                            <p><?php esc_html_e( 'En esta sección podrás resolver tus dudas sobre cómo comprar, ver tu pedido, devoluciones y más.', 'workshop' ); ?></p>
                        <?php else : ?>
                            <p><?php esc_html_e( 'Descubre quiénes somos, qué hacemos y cómo este mercado conecta negocios y clientes.', 'workshop' ); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php get_footer(); ?>