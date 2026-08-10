<?php
/**
 * Pie del tema.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$ws_role   = get_query_var( 'ws_role', '' );
$ws_public = get_query_var( 'ws_public', '' );

// En el panel y en login/registro el pie no se muestra (mismo criterio que el
// topbar): el formulario ocupa todo el protagonismo. wp_footer() y el cierre
// de la página se emiten igualmente, como exige WordPress.
$ws_hide_footer = ! empty( $ws_role ) || in_array( $ws_public, array( 'login', 'register' ), true );
if ( ! $ws_hide_footer ) :
?>
<footer class="ws-footer">
    <div class="ws-container">
        <?php
        $ws_sp_pages = ws_site_pages();
        $ws_contact  = $ws_sp_pages['contact'];
        $ws_c_email  = trim( (string) ( $ws_contact['email'] ?? '' ) );
        $ws_c_phone  = trim( (string) ( $ws_contact['phone'] ?? '' ) );
        $ws_c_wa     = trim( (string) ( $ws_contact['whatsapp'] ?? '' ) );
        if ( $ws_c_email || $ws_c_phone || $ws_c_wa ) :
        ?>
        <div class="ws-footer-help">
            <div class="ws-footer-help-text">
                <strong><i class="fa-solid fa-headset"></i> <?php esc_html_e( '¿Necesitas ayuda?', 'workshop' ); ?></strong>
                <span><?php esc_html_e( 'Nuestro equipo está listo para resolver tus dudas.', 'workshop' ); ?></span>
            </div>
            <div class="ws-footer-help-actions">
                <?php if ( $ws_c_wa ) : ?>
                    <a class="ws-btn ws-btn-wa ws-btn-sm" href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', $ws_c_wa ) ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> <?php esc_html_e( 'WhatsApp', 'workshop' ); ?></a>
                <?php endif; ?>
                <?php if ( $ws_c_email ) : ?>
                    <a class="ws-btn ws-btn-light ws-btn-sm" href="mailto:<?php echo esc_attr( $ws_c_email ); ?>"><i class="fa-solid fa-envelope"></i> <?php esc_html_e( 'Correo', 'workshop' ); ?></a>
                <?php endif; ?>
                <?php if ( $ws_c_phone ) : ?>
                    <a class="ws-btn ws-btn-light ws-btn-sm" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $ws_c_phone ) ); ?>"><i class="fa-solid fa-phone"></i> <?php esc_html_e( 'Teléfono', 'workshop' ); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="ws-footer-grid">
            <div class="ws-footer-col">
                <a class="ws-brand" href="<?php echo esc_url( ws_business_home() ); ?>">
                    <?php $ws_logo = ws_site_logo(); ?>
                    <img class="ws-brand-img" src="<?php echo ws_site_logo_src(); ?>" alt="<?php echo esc_attr( ws_site_name() ); ?>" style="<?php echo $ws_logo ? '' : 'display:none'; ?>">
                    <i class="fa-solid fa-store ws-brand-icon" style="<?php echo $ws_logo ? 'display:none' : ''; ?>"></i>
                    <span class="ws-brand-name"><?php echo esc_html( ws_site_name() ); ?></span>
                </a>
                <p class="ws-footer-desc"><?php echo esc_html( ws_site_footer_text() ); ?></p>
            </div>
            <?php
            $ws_footer_cols = $ws_sp_pages['columns'];
            ?>
            <?php foreach ( $ws_footer_cols as $ws_fc ) : ?>
                <div class="ws-footer-col">
                    <h4><?php echo esc_html( $ws_fc['title'] ); ?></h4>
                    <ul>
                        <?php foreach ( $ws_fc['links'] as $ws_fl ) : ?>
                            <li><a href="<?php echo esc_url( $ws_fl['url'] ); ?>"><?php echo esc_html( $ws_fl['label'] ); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
            <?php if ( ! empty( $ws_sp_pages['socials'] ) ) : ?>
                <div class="ws-footer-col">
                    <h4><?php esc_html_e( 'Síguenos', 'workshop' ); ?></h4>
                    <ul class="ws-footer-socials">
                        <?php foreach ( $ws_sp_pages['socials'] as $ws_social ) : ?>
                            <li>
                                <a class="ws-footer-social" href="<?php echo esc_url( $ws_social['url'] ); ?>" target="_blank" rel="noopener">
                                    <i class="fa-brands fa-<?php echo esc_attr( sanitize_title( strtolower( $ws_social['label'] ) ) ); ?>"></i>
                                    <span><?php echo esc_html( $ws_social['label'] ); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <div class="ws-footer-bottom">
            <span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <span class="ws-brand-name"><?php echo esc_html( ws_site_name() ); ?></span></span>
            <span><?php esc_html_e( 'Hecho con', 'workshop' ); ?> <i class="fa-solid fa-heart" style="color:#f43f5e"></i> Filipenses 4:13</span>
        </div>
    </div>
</footer>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
