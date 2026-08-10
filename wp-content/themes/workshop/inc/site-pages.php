<?php
/**
 * Páginas de información y enlaces del pie (editables desde wp-admin).
 *
 * El administrador de WordPress edita el contenido de las páginas estáticas
 * (Ayuda, Contacto y Acerca de nosotros) y las columnas/enlaces del pie
 * (tienda, soporte, enlaces útiles y redes sociales). Todo se guarda en la
 * opción global ws_site_pages y se aplica en todo el front-end.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

define( 'WS_SITE_PAGES_KEY', 'ws_site_pages' );

/**
 * Valores por defecto de las páginas e información del pie.
 *
 * Estas páginas quedan listas y bien presentadas desde el primer momento; el
 * administrador las edita después desde wp-admin (ShopUp → Páginas y pie).
 */
function ws_site_pages_defaults() {
    return array(
        'help'    => array(
            'title'   => __( 'Ayuda', 'workshop' ),
            'content' => ''
                . '<p>Te ayudamos a sacarle el máximo partido al mercado. Estas son las respuestas a las dudas más frecuentes.</p>'
                . '<h2>¿Cómo hago un pedido?</h2>'
                . '<p>Entra en el directorio de tiendas, elige tu tienda favorita y explora sus productos. Añade lo que quieras al carrito, escribe tus datos y confirma el pedido. Te llegará el detalle por WhatsApp.</p>'
                . '<h2>¿Cómo hago el seguimiento de mi pedido?</h2>'
                . '<p>Tras confirmar tu pedido recibirás un número. La tienda te contacta por WhatsApp para coordinar la entrega o la recogida.</p>'
                . '<h2>¿Qué métodos de pago aceptáis?</h2>'
                . '<ul><li>Efectivo al recibir el pedido.</li><li>Transferencia bancaria.</li><li>Otros métodos que la tienda indique al confirmar.</li></ul>'
                . '<h2>¿Hay envío a domicilio?</h2>'
                . '<p>Depende de cada tienda. En la página de la tienda verás si ofrece reparto y cuál es su zona de cobertura.</p>'
                . '<h2>¿Puedo devolver un producto?</h2>'
                . '<p>Consulta con la tienda donde compraste. Cada negocio gestiona sus devoluciones de forma directa.</p>'
                . '<h2>¿Necesitas más ayuda?</h2>'
                . '<p>Visita la página de <a href="' . esc_url( home_url( '/contacto/' ) ) . '">Contacto</a> y te responderemos lo antes posible.</p>',
        ),
        'contact' => array(
            'title'   => __( 'Contacto', 'workshop' ),
            'content' => ''
                . '<p>¿Tienes dudas, sugerencias o quieres aparecer en el mercado? Estamos aquí para ayudarte. Elige la vía que prefieras.</p>',
            'email'   => '',
            'phone'   => '',
            'address' => '',
        ),
        'about'   => array(
            'title'   => __( 'Acerca de nosotros', 'workshop' ),
            'content' => ''
                . '<p>Somos una plataforma que conecta negocios locales con sus clientes. Queremos que cada tienda, por pequeña que sea, tenga su espacio en el mercado digital.</p>'
                . '<h2>Nuestra misión</h2>'
                . '<p>Ayudar a los negocios a vender online sin complicaciones: pedidos por WhatsApp, stock en tiempo real y todo el control desde un solo panel.</p>'
                . '<h2>¿Por qué elegirnos?</h2>'
                . '<ul><li>Tu tienda visible para todos los clientes.</li><li>Pedidos directos por WhatsApp, sin intermediarios.</li><li>Stock y ventas sincronizados al instante.</li><li>Empiezas gratis, sin tarjeta.</li></ul>'
                . '<h2>¿Tienes un negocio?</h2>'
                . '<p>Crea tu cuenta, personaliza tu tienda y empieza a vender. <a href="' . esc_url( ws_register_url() ) . '">Únete gratis</a>.</p>',
        ),
        // Columnas del pie: cada una con su título y una lista de enlaces
        // {label,url}. El admin puede añadir/quitar columnas y enlaces.
        'columns' => array(
            array(
                'title' => __( 'Tienda', 'workshop' ),
                'links' => array(
                    array( 'label' => __( 'Tiendas', 'workshop' ), 'url' => home_url( '/marketplace/' ) ),
                ),
            ),
            array(
                'title' => __( 'Soporte', 'workshop' ),
                'links' => array(
                    array( 'label' => __( 'Ayuda', 'workshop' ), 'url' => home_url( '/ayuda/' ) ),
                    array( 'label' => __( 'Contacto', 'workshop' ), 'url' => home_url( '/contacto/' ) ),
                ),
            ),
            array(
                'title' => __( 'Enlaces útiles', 'workshop' ),
                'links' => array(
                    array( 'label' => __( 'Acerca de nosotros', 'workshop' ), 'url' => home_url( '/acerca/' ) ),
                ),
            ),
        ),
        // Redes sociales del pie: lista {label,url}.
        'socials' => array(),
    );
}

/**
 * Siembra la opción con los valores por defecto la primera vez.
 *
 * Al activar el tema (o si aún no existe la opción), se guarda el contenido
 * listo de las páginas y las columnas del pie. Así las páginas se ven bien
 * desde el primer momento y el admin las edita después.
 */
add_action( 'init', 'ws_site_pages_seed', 5 );
function ws_site_pages_seed() {
    if ( false === get_option( WS_SITE_PAGES_KEY, false ) ) {
        update_option( WS_SITE_PAGES_KEY, ws_site_pages_defaults() );
    }
}

/**
 * Opciones actuales de páginas y pie, combinadas con los valores por defecto.
 */
function ws_site_pages() {
    $defaults = ws_site_pages_defaults();
    $saved    = get_option( WS_SITE_PAGES_KEY, array() );
    $saved    = is_array( $saved ) ? $saved : array();

    // Sanitiza las sub-páginas (help/contact/about).
    foreach ( array( 'help', 'contact', 'about' ) as $key ) {
        $cur          = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();
        $defaults[ $key ] = array_merge( $defaults[ $key ], $cur );
    }

    // Columnas limpias.
    $columns = array();
    foreach ( (array) ( $saved['columns'] ?? array() ) as $col ) {
        if ( ! is_array( $col ) || '' === trim( (string) ( $col['title'] ?? '' ) ) ) {
            continue;
        }
        $links = array();
        foreach ( (array) ( $col['links'] ?? array() ) as $l ) {
            if ( is_array( $l ) && '' !== trim( (string) ( $l['label'] ?? '' ) ) ) {
                $links[] = array(
                    'label' => trim( (string) $l['label'] ),
                    'url'   => trim( (string) ( $l['url'] ?? '' ) ),
                );
            }
        }
        $columns[] = array( 'title' => trim( $col['title'] ), 'links' => $links );
    }
    if ( empty( $columns ) ) {
        $columns = $defaults['columns'];
    }
    $defaults['columns'] = $columns;

    // Redes sociales.
    $socials = array();
    foreach ( (array) ( $saved['socials'] ?? array() ) as $s ) {
        if ( is_array( $s ) && '' !== trim( (string) ( $s['label'] ?? '' ) ) ) {
            $socials[] = array(
                'label' => trim( $s['label'] ),
                'url'   => trim( (string) ( $s['url'] ?? '' ) ),
            );
        }
    }
    $defaults['socials'] = $socials;

    return $defaults;
}

/**
 * Campos de texto de las páginas (cacheadas para uso repetido en el pie).
 */
function ws_site_page( $page, $field ) {
    $pages = ws_site_pages();
    return isset( $pages[ $page ][ $field ] ) ? (string) $pages[ $page ][ $field ] : '';
}

/* -------------------------------------------------------------------------
 * wp-admin: página de edición de páginas y enlaces del pie
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'ws_site_pages_admin_menu', 20 );
function ws_site_pages_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Páginas y pie', 'workshop' ),
        __( 'Páginas y pie', 'workshop' ),
        'manage_options',
        'ws-site-pages',
        'ws_admin_page_site_pages'
    );
}

function ws_admin_page_site_pages() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $saved = false;
    if ( isset( $_POST['ws_site_pages_nonce'] ) && wp_verify_nonce( $_POST['ws_site_pages_nonce'], 'ws_save_site_pages' ) ) {
        $cur = array();

        foreach ( array( 'help', 'about' ) as $key ) {
            $cur[ $key ] = array(
                'title'   => sanitize_text_field( $_POST[ $key ]['title'] ?? '' ),
                'content' => wp_kses_post( (string) ( $_POST[ $key ]['content'] ?? '' ) ),
            );
        }
        $cur['contact'] = array(
            'title'   => sanitize_text_field( $_POST['contact']['title'] ?? '' ),
            'content' => wp_kses_post( (string) ( $_POST['contact']['content'] ?? '' ) ),
            'email'   => sanitize_email( (string) ( $_POST['contact']['email'] ?? '' ) ),
            'phone'   => sanitize_text_field( (string) ( $_POST['contact']['phone'] ?? '' ) ),
            'address' => sanitize_text_field( (string) ( $_POST['contact']['address'] ?? '' ) ),
        );

        // Columnas del pie.
        $columns = array();
        foreach ( (array) ( $_POST['col_title'] ?? array() ) as $i => $t ) {
            $title = sanitize_text_field( $t );
            if ( '' === trim( $title ) ) {
                continue;
            }
            $links = array();
            foreach ( (array) ( $_POST['link_label'][ $i ] ?? array() ) as $j => $label ) {
                $label = trim( sanitize_text_field( $label ) );
                $url   = trim( esc_url_raw( (string) ( $_POST['link_url'][ $i ][ $j ] ?? '' ) ) );
                if ( '' !== $label ) {
                    $links[] = array( 'label' => $label, 'url' => $url );
                }
            }
            $columns[] = array( 'title' => $title, 'links' => $links );
        }
        $cur['columns'] = $columns;

        // Redes sociales.
        $socials = array();
        foreach ( (array) ( $_POST['social_label'] ?? array() ) as $i => $label ) {
            $label = trim( sanitize_text_field( $label ) );
            $url   = trim( esc_url_raw( (string) ( $_POST['social_url'][ $i ] ?? '' ) ) );
            if ( '' !== $label ) {
                $socials[] = array( 'label' => $label, 'url' => $url );
            }
        }
        $cur['socials'] = $socials;

        update_option( WS_SITE_PAGES_KEY, $cur );
        $saved = true;
    }

    $pages = ws_site_pages();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-edit-page" style="vertical-align:middle"></span> <?php esc_html_e( 'Páginas y enlaces del pie', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Edita el contenido de las páginas Ayuda, Contacto y Acerca de nosotros, así como las columnas y enlaces del pie de página (tiendas, soporte, enlaces útiles y redes sociales). Todo se aplica al instante en el sitio.', 'workshop' ); ?></p>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cambios guardados.', 'workshop' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'ws_save_site_pages', 'ws_site_pages_nonce' ); ?>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-welcome-learn-more" style="margin-right:6px"></span><?php esc_html_e( 'Página de Ayuda', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Título', 'workshop' ); ?></label></th>
                        <td><input type="text" name="help[title]" class="regular-text" value="<?php echo esc_attr( $pages['help']['title'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Contenido', 'workshop' ); ?></label></th>
                        <td><textarea name="help[content]" class="large-text" rows="8"><?php echo esc_textarea( $pages['help']['content'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Puedes usar HTML básico (párrafos, listas, enlaces).', 'workshop' ); ?></p></td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-email-alt" style="margin-right:6px"></span><?php esc_html_e( 'Página de Contacto', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Título', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[title]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['title'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Contenido', 'workshop' ); ?></label></th>
                        <td><textarea name="contact[content]" class="large-text" rows="8"><?php echo esc_textarea( $pages['contact']['content'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Texto o HTML de bienvenida o indicaciones.', 'workshop' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Correo', 'workshop' ); ?></label></th>
                        <td><input type="email" name="contact[email]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['email'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Teléfono', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[phone]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['phone'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Dirección', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[address]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['address'] ); ?>"></td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-info" style="margin-right:6px"></span><?php esc_html_e( 'Página Acerca de nosotros', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Título', 'workshop' ); ?></label></th>
                        <td><input type="text" name="about[title]" class="regular-text" value="<?php echo esc_attr( $pages['about']['title'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Contenido', 'workshop' ); ?></label></th>
                        <td><textarea name="about[content]" class="large-text" rows="8"><?php echo esc_textarea( $pages['about']['content'] ); ?></textarea></td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-menu-alt" style="margin-right:6px"></span><?php esc_html_e( 'Columnas del pie', 'workshop' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Cada columna tiene un título y una lista de enlaces (nombre + URL).', 'workshop' ); ?></p>
                <div id="ws-sp-columns">
                    <?php foreach ( (array) $pages['columns'] as $ci => $col ) : ?>
                        <div class="ws-sp-col" data-i="<?php echo (int) $ci; ?>">
                            <div class="ws-sp-col-head">
                                <strong><?php echo esc_html( $col['title'] ); ?></strong>
                                <button type="button" class="button button-link-delete" onclick="this.closest('.ws-sp-col').remove();ws_sp_sync();"><?php esc_html_e( 'Quitar columna', 'workshop' ); ?></button>
                            </div>
                            <label class="ws-sp-field">
                                <span><?php esc_html_e( 'Título', 'workshop' ); ?></span>
                                <input type="text" name="col_title[]" class="regular-text" value="<?php echo esc_attr( $col['title'] ); ?>">
                            </label>
                            <div class="ws-sp-links" data-holder>
                                <?php foreach ( (array) $col['links'] as $li => $lk ) : ?>
                                    <div class="ws-sp-link-row">
                                        <input type="text" name="link_label[<?php echo (int) $ci; ?>][]" class="regular-text" placeholder="<?php esc_attr_e( 'Nombre', 'workshop' ); ?>" value="<?php echo esc_attr( $lk['label'] ); ?>">
                                        <input type="url" name="link_url[<?php echo (int) $ci; ?>][]" class="regular-text" placeholder="https://" value="<?php echo esc_attr( $lk['url'] ); ?>">
                                        <button type="button" class="button button-link-delete" onclick="this.closest('.ws-sp-link-row').remove()"><?php esc_html_e( '✕', 'workshop' ); ?></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p><button type="button" class="button" onclick="ws_sp_add_link(this)"><?php esc_html_e( 'Añadir enlace', 'workshop' ); ?></button></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" id="ws-sp-add-col"><?php esc_html_e( 'Añadir columna', 'workshop' ); ?></button></p>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-share" style="margin-right:6px"></span><?php esc_html_e( 'Redes sociales', 'workshop' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Enlaces a tus redes (Facebook, Instagram, WhatsApp, YouTube, etc.).', 'workshop' ); ?></p>
                <div id="ws-sp-socials">
                    <?php foreach ( (array) $pages['socials'] as $i => $so ) : ?>
                        <div class="ws-sp-link-row">
                            <input type="text" name="social_label[]" class="regular-text" placeholder="<?php esc_attr_e( 'Nombre (ej. Instagram)', 'workshop' ); ?>" value="<?php echo esc_attr( $so['label'] ); ?>">
                            <input type="url" name="social_url[]" class="regular-text" placeholder="https://" value="<?php echo esc_attr( $so['url'] ); ?>">
                            <button type="button" class="button button-link-delete" onclick="this.closest('.ws-sp-link-row').remove()"><?php esc_html_e( '✕', 'workshop' ); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" onclick="ws_sp_add_social()"><?php esc_html_e( 'Añadir red social', 'workshop' ); ?></button></p>
            </div>

            <?php submit_button( __( 'Guardar páginas y pie', 'workshop' ) ); ?>
        </form>
    </div>
    <style>
        .ws-mp-admin-group { background: #fff; border: 1px solid #c3c4c7; padding: 8px 20px 16px; margin: 0 0 18px; border-radius: 6px; }
        .ws-mp-admin-group h2 { font-size: 15px; padding-top: 12px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; }
        .ws-sp-col { border: 1px solid #c3c4c7; padding: 12px 14px; margin: 10px 0; background: #f8f9fa; border-radius: 6px; }
        .ws-sp-col-head { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .ws-sp-link-row { display: flex; gap: 6px; margin: 6px 0; }
        .ws-sp-link-row .regular-text:first-child { width: 180px; }
        .ws-sp-link-row .regular-text:nth-child(2) { flex: 1; }
        .ws-sp-field { display: block; margin: 8px 0; }
        .ws-sp-field > span { display: block; font-weight: 600; margin-bottom: 4px; color: #50575e; }
    </style>
    <script>
        (function () {
            var colBox = document.getElementById('ws-sp-columns');
            var colBtn = document.getElementById('ws-sp-add-col');
            if (colBox && colBtn) {
                colBtn.addEventListener('click', function () {
                    var n = colBox.querySelectorAll('.ws-sp-col').length;
                    var d = document.createElement('div');
                    d.className = 'ws-sp-col';
                    d.innerHTML =
                        '<div class="ws-sp-col-head"><strong><?php echo esc_js( __( 'Nueva columna', 'workshop' ) ); ?></strong>'
                        + '<button type="button" class="button button-link-delete" onclick="this.closest(\'.ws-sp-col\').remove();ws_sp_sync();"><?php echo esc_js( __( 'Quitar columna', 'workshop' ) ); ?></button></div>'
                        + '<label class="ws-sp-field"><span><?php echo esc_js( __( 'Título', 'workshop' ) ); ?></span>'
                        + '<input type="text" name="col_title[]" class="regular-text"></label>'
                        + '<div class="ws-sp-links" data-holder></div>'
                        + '<p><button type="button" class="button" onclick="ws_sp_add_link(this)"><?php echo esc_js( __( 'Añadir enlace', 'workshop' ) ); ?></button></p>';
                    colBox.appendChild(d);
                    ws_sp_sync();
                });
            }
            ws_sp_sync();
            if (colBox) colBox.addEventListener('input', function (e) {
                if (e.target && e.target.name && e.target.name.startsWith('col_title')) ws_sp_sync();
            });
        })();
        function ws_sp_sync() {
            // Reindexa los name de los enlaces según el índice de cada columna.
            var cols = document.querySelectorAll('#ws-sp-columns .ws-sp-col');
            cols.forEach(function (col, i) {
                col.querySelectorAll('input[name^="col_title"]').forEach(function (inp) { inp.name = 'col_title[' + i + ']'; });
                var links = col.querySelectorAll('.ws-sp-link-row input');
                links.forEach(function (inp) {
                    if (inp.name.indexOf('label') !== -1) inp.name = 'link_label[' + i + '][]';
                    else if (inp.name.indexOf('url') !== -1) inp.name = 'link_url[' + i + '][]';
                });
            });
        }
        function ws_sp_add_link(btn) {
            var holder = btn.closest('.ws-sp-col').querySelector('[data-holder]') || btn.previousElementSibling;
            if (!holder) return;
            var row = document.createElement('div');
            row.className = 'ws-sp-link-row';
            row.innerHTML = '<input type="text" class="regular-text" placeholder="<?php echo esc_js( __( 'Nombre', 'workshop' ) ); ?>" value="">'
                + '<input type="url" class="regular-text" placeholder="https://" value="">'
                + '<button type="button" class="button button-link-delete" onclick="this.closest(\'.ws-sp-link-row\').remove()"><?php echo esc_js( __( '✕', 'workshop' ) ); ?></button>';
            holder.appendChild(row);
            ws_sp_sync();
        }
        function ws_sp_add_social() {
            var box = document.getElementById('ws-sp-socials');
            if (!box) return;
            var row = document.createElement('div');
            row.className = 'ws-sp-link-row';
            row.innerHTML = '<input type="text" name="social_label[]" class="regular-text" placeholder="<?php echo esc_js( __( 'Nombre', 'workshop' ) ); ?>" value="">'
                + '<input type="url" name="social_url[]" class="regular-text" placeholder="https://" value="">'
                + '<button type="button" class="button button-link-delete" onclick="this.closest(\'.ws-sp-link-row\').remove()"><?php echo esc_js( __( '✕', 'workshop' ) ); ?></button>';
            box.appendChild(row);
        }
    </script>
    <?php
}