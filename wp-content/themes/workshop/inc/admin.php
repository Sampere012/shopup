<?php
/**
 * Integración con wp-admin: página de permisos por rol de negocio.
 *
 * La matriz de permisos de los roles dueño/almacenero/vendedor se gestiona
 * aquí (solo administradores del sitio). Esta página sustituye al módulo
 * "Permisos" del panel del dueño, que quedó restringido al admin.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'ws_admin_menu' );
function ws_admin_menu() {
    add_menu_page(
        __( 'ShopUp', 'workshop' ),
        __( 'ShopUp', 'workshop' ),
        'manage_options',
        'ws-permissions',
        'ws_admin_page_permissions',
        'dashicons-store',
        4
    );
}

function ws_admin_page_permissions() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $saved = false;
    $biz_id = (int) ( $_POST['biz_id'] ?? $_GET['biz_id'] ?? 0 );
    if ( isset( $_POST['ws_permissions_nonce'] ) && wp_verify_nonce( $_POST['ws_permissions_nonce'], 'ws_save_permissions_admin' ) ) {
        $matrix = isset( $_POST['matrix'] ) && is_array( $_POST['matrix'] ) ? $_POST['matrix'] : array();
        WS_Capabilities::save_matrix( $matrix, $biz_id );
        if ( function_exists( 'ws_log_audit' ) ) {
            ws_log_audit( 'permissions_update', 'settings', $biz_id );
        }
        $saved = true;
    }

    $businesses = class_exists( 'WS_Business' ) ? WS_Business::all() : array();
    if ( ! $biz_id ) {
        $biz_id = ws_current_business_id();
    }
    $matrix = WS_Capabilities::matrix( $biz_id );
    $caps   = WS_Capabilities::all_caps();
    $roles  = array(
        'owner'       => __( 'Dueño', 'workshop' ),
        'storekeeper' => __( 'Almacenero', 'workshop' ),
        'seller'      => __( 'Vendedor', 'workshop' ),
    );
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-shield-alt" style="vertical-align:middle"></span> <?php esc_html_e( 'Permisos de los roles del negocio', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Define qué puede hacer cada rol (dueño, almacenero y vendedor) en el panel de ShopUp.', 'workshop' ); ?></p>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Permisos guardados.', 'workshop' ); ?></p></div>
        <?php endif; ?>

        <?php if ( count( $businesses ) > 1 ) : ?>
            <form method="get" action="" style="margin:12px 0">
                <input type="hidden" name="page" value="ws-permissions">
                <label for="ws-perm-biz"><?php esc_html_e( 'Negocio:', 'workshop' ); ?></label>
                <select name="biz_id" id="ws-perm-biz" onchange="this.form.submit()">
                    <?php foreach ( $businesses as $b ) : ?>
                        <option value="<?php echo (int) $b->id; ?>" <?php selected( $biz_id, (int) $b->id ); ?>><?php echo esc_html( $b->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="button"><?php esc_html_e( 'Ir', 'workshop' ); ?></button></noscript>
            </form>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'ws_save_permissions_admin', 'ws_permissions_nonce' ); ?>
            <input type="hidden" name="biz_id" value="<?php echo (int) $biz_id; ?>">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Módulo / Permiso', 'workshop' ); ?></th>
                        <?php foreach ( $roles as $key => $label ) : ?>
                            <th style="text-align:center"><?php echo esc_html( $label ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $caps as $cap => $label ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $label ); ?></strong><br><code><?php echo esc_html( $cap ); ?></code></td>
                            <?php foreach ( array_keys( $roles ) as $rkey ) : ?>
                                <td style="text-align:center">
                                    <?php
                                    $checked = ! empty( $matrix[ $rkey ][ $cap ] );
                                    if ( 'permissions_manage' === $cap ) {
                                        // Exclusiva del administrador del sistema.
                                        echo '<span class="dashicons dashicons-lock" title="' . esc_attr__( 'Solo el administrador del sistema', 'workshop' ) . '"></span>';
                                    } else {
                                        ?>
                                        <input type="checkbox" name="matrix[<?php echo esc_attr( $rkey ); ?>][<?php echo esc_attr( $cap ); ?>]" value="1" <?php checked( $checked ); ?>>
                                        <?php
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button( __( 'Guardar permisos', 'workshop' ) ); ?>
        </form>
    </div>
    <?php
}

add_action( 'admin_menu', 'ws_marketplace_admin_menu', 20 );
function ws_marketplace_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Mercado', 'workshop' ),
        __( 'Mercado', 'workshop' ),
        'manage_options',
        'ws-marketplace',
        'ws_admin_page_marketplace'
    );
}

function ws_admin_page_marketplace() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
    }

    $saved = false;
    if ( isset( $_POST['ws_marketplace_nonce'] ) && wp_verify_nonce( $_POST['ws_marketplace_nonce'], 'ws_save_marketplace' ) ) {
        $cur             = get_option( 'ws_marketplace_theme', array() );
        $cur             = is_array( $cur ) ? $cur : array();
        $cur['name']        = sanitize_text_field( $_POST['name'] ?? '' );
        $cur['logo']        = esc_url_raw( (string) ( $_POST['logo'] ?? '' ) );
        $cur['description'] = sanitize_textarea_field( $_POST['description'] ?? '' );
        $cur['primary']     = sanitize_hex_color( (string) ( $_POST['primary'] ?? '' ) );
        $cur['accent']      = sanitize_hex_color( (string) ( $_POST['accent'] ?? '' ) );
        $cur['hero_badge']  = sanitize_text_field( $_POST['hero_badge'] ?? '' );
        $cur['hero_title']  = sanitize_text_field( $_POST['hero_title'] ?? '' );
        $cur['hero_sub']    = sanitize_text_field( $_POST['hero_sub'] ?? '' );
        $cur['hero_bg']     = esc_url_raw( (string) ( $_POST['hero_bg'] ?? '' ) );
        $cur['hero_gradient'] = wp_strip_all_tags( (string) ( $_POST['hero_gradient'] ?? '' ) );
        $cur['footer_text'] = sanitize_text_field( $_POST['footer_text'] ?? '' );
        // Los bloques de contenido solo se actualizan si el editor se envió en
        // el formulario (marcador ws_mp_sections_edited). Así un guardado sin
        // bloques no borra los ya existentes por accidente.
        if ( isset( $_POST['ws_mp_sections_edited'] ) && '1' === $_POST['ws_mp_sections_edited'] ) {
            $sections = array();
            $contents = isset( $_POST['section_content'] ) && is_array( $_POST['section_content'] ) ? $_POST['section_content'] : array();
            foreach ( (array) ( $_POST['section_title'] ?? array() ) as $i => $t ) {
                $title   = sanitize_text_field( $t );
                $content = trim( (string) ( $contents[ $i ] ?? '' ) );
                if ( '' !== $title || '' !== $content ) {
                    $sections[] = array( 'title' => $title, 'content' => $content );
                }
            }
            $cur['sections'] = $sections;
        }
        update_option( 'ws_marketplace_theme', $cur );
        $saved = true;
    }

    $theme = ws_marketplace_theme();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-store" style="vertical-align:middle"></span> <?php esc_html_e( 'Índice del mercado', 'workshop' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Esta es la página principal del sitio (la raíz). Aquí aparecen todos los negocios que tienen acceso al mercado; el cliente elige uno y entra en su tienda. Cada negocio mantiene su propia portada, logo y colores, que configura su dueño.', 'workshop' ); ?>
            <?php echo ' <a target="_blank" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Ver el mercado', 'workshop' ) . ' <span class="dashicons dashicons-external" style="font-size:16px;line-height:inherit"></span></a>'; ?>
        </p>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración del mercado guardada.', 'workshop' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'ws_save_marketplace', 'ws_marketplace_nonce' ); ?>
            <input type="hidden" name="ws_mp_sections_edited" value="1">

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-id" style="margin-right:6px"></span><?php esc_html_e( 'Identidad', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Nombre del mercado', 'workshop' ); ?></label></th>
                        <td>
                            <input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $theme['name'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'blogname' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Se muestra en la cabecera y el pie del índice.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Logo (URL)', 'workshop' ); ?></label></th>
                        <td>
                            <input type="url" name="logo" class="regular-text" value="<?php echo esc_attr( $theme['logo'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Pega la URL de una imagen (o súbela en Medios y copia su enlace).', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Descripción', 'workshop' ); ?></label></th>
                        <td>
                            <textarea name="description" class="large-text" rows="2"><?php echo esc_textarea( $theme['description'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Frase corta que aparece bajo el titular de la portada.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Pie de página', 'workshop' ); ?></label></th>
                        <td>
                            <input type="text" name="footer_text" class="regular-text" value="<?php echo esc_attr( $theme['footer_text'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Texto del pie del índice (deja vacío para el texto por defecto).', 'workshop' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-art" style="margin-right:6px"></span><?php esc_html_e( 'Colores', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Color primario', 'workshop' ); ?></label></th>
                        <td><input type="color" name="primary" value="<?php echo esc_attr( $theme['primary'] ); ?>"> <span class="description" style="vertical-align:middle"><?php esc_html_e( 'Botones y detalles principales del índice.', 'workshop' ); ?></span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Color de acento', 'workshop' ); ?></label></th>
                        <td><input type="color" name="accent" value="<?php echo esc_attr( $theme['accent'] ); ?>"> <span class="description" style="vertical-align:middle"><?php esc_html_e( 'Destacados, estrellas y acentos.', 'workshop' ); ?></span></td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-cover-image" style="margin-right:6px"></span><?php esc_html_e( 'Portada (hero)', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Etiqueta (badge)', 'workshop' ); ?></label></th>
                        <td>
                            <input type="text" name="hero_badge" class="regular-text" value="<?php echo esc_attr( $theme['hero_badge'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Palabra o frase pequeña sobre el titular, ej. "Mercado local".', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Título', 'workshop' ); ?></label></th>
                        <td><input type="text" name="hero_title" class="regular-text" value="<?php echo esc_attr( $theme['hero_title'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Subtítulo', 'workshop' ); ?></label></th>
                        <td><input type="text" name="hero_sub" class="regular-text" value="<?php echo esc_attr( $theme['hero_sub'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Imagen de fondo (URL)', 'workshop' ); ?></label></th>
                        <td>
                            <input type="url" name="hero_bg" class="regular-text" value="<?php echo esc_attr( $theme['hero_bg'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Opcional: imagen que se muestra detrás del titular.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Gradiente CSS', 'workshop' ); ?></label></th>
                        <td>
                            <input type="text" name="hero_gradient" class="regular-text" value="<?php echo esc_attr( $theme['hero_gradient'] ); ?>" placeholder="linear-gradient(...)">
                            <p class="description"><?php esc_html_e( 'Opcional: degradado de fondo si no usas imagen.', 'workshop' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-editor-ul" style="margin-right:6px"></span><?php esc_html_e( 'Contenido extra', 'workshop' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Bloques opcionales que se muestran debajo de la lista de negocios: cómo funciona, avisos, enlaces o promociones.', 'workshop' ); ?></p>
                <div id="ws-mp-sections">
                    <?php if ( empty( $theme['sections'] ) ) : ?>
                        <p class="description" id="ws-mp-sections-empty"><?php esc_html_e( 'Aún no hay bloques. Usa "Añadir bloque" para crear el primero.', 'workshop' ); ?></p>
                    <?php endif; ?>
                    <?php foreach ( (array) $theme['sections'] as $i => $s ) : ?>
                        <div class="ws-mp-section" data-ws-mp-index="<?php echo (int) $i; ?>">
                            <div class="ws-mp-section-head">
                                <span class="ws-mp-section-title"><?php echo esc_html( sprintf( __( 'Bloque %d', 'workshop' ), (int) $i + 1 ) ); ?></span>
                                <button type="button" class="button button-link-delete" onclick="this.closest('.ws-mp-section').remove()"><?php esc_html_e( 'Quitar', 'workshop' ); ?></button>
                            </div>
                            <label class="ws-mp-field">
                                <span><?php esc_html_e( 'Título', 'workshop' ); ?></span>
                                <input type="text" name="section_title[]" class="regular-text" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php esc_attr_e( 'Ej: ¿Cómo funciona?', 'workshop' ); ?>">
                            </label>
                            <label class="ws-mp-field">
                                <span><?php esc_html_e( 'Contenido', 'workshop' ); ?></span>
                                <textarea name="section_content[]" class="large-text" rows="4" placeholder="<?php esc_attr_e( 'Texto o HTML de este bloque.', 'workshop' ); ?>"><?php echo esc_textarea( $s['content'] ); ?></textarea>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p>
                    <button type="button" class="button" id="ws-mp-add-section"><?php esc_html_e( 'Añadir bloque', 'workshop' ); ?></button>
                </p>
            </div>

            <?php submit_button( __( 'Guardar mercado', 'workshop' ) ); ?>
        </form>
    </div>
    <style>
        .ws-mp-admin-group { background: #fff; border: 1px solid #c3c4c7; padding: 8px 20px 16px; margin: 0 0 18px; border-radius: 6px; }
        .ws-mp-admin-group h2 { font-size: 15px; padding-top: 12px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; }
        .ws-mp-section { border: 1px solid #c3c4c7; padding: 12px 14px; margin: 10px 0; background: #f8f9fa; border-radius: 6px; }
        .ws-mp-section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .ws-mp-section-title { font-weight: 600; color: #2c3338; }
        .ws-mp-field { display: block; margin: 8px 0; }
        .ws-mp-field > span { display: block; font-weight: 600; margin-bottom: 4px; color: #50575e; }
    </style>
    <script>
        (function () {
            var box = document.getElementById('ws-mp-sections');
            var btn = document.getElementById('ws-mp-add-section');
            if (!box || !btn) { return; }
            btn.addEventListener('click', function () {
                var empty = document.getElementById('ws-mp-sections-empty');
                if (empty) { empty.remove(); }
                var n = box.querySelectorAll('.ws-mp-section').length + 1;
                var d = document.createElement('div');
                d.className = 'ws-mp-section';
                d.innerHTML =
                    '<div class="ws-mp-section-head">'
                    + '<span class="ws-mp-section-title"><?php echo esc_js( __( 'Bloque', 'workshop' ) ); ?> ' + n + '</span>'
                    + '<button type="button" class="button button-link-delete" onclick="this.closest(\'.ws-mp-section\').remove()"><?php echo esc_js( __( 'Quitar', 'workshop' ) ); ?></button>'
                    + '</div>'
                    + '<label class="ws-mp-field"><span><?php echo esc_js( __( 'Título', 'workshop' ) ); ?></span>'
                    + '<input type="text" name="section_title[]" class="regular-text" placeholder="<?php echo esc_js( __( 'Ej: ¿Cómo funciona?', 'workshop' ) ); ?>"></label>'
                    + '<label class="ws-mp-field"><span><?php echo esc_js( __( 'Contenido', 'workshop' ) ); ?></span>'
                    + '<textarea name="section_content[]" class="large-text" rows="4" placeholder="<?php echo esc_js( __( 'Texto o HTML de este bloque.', 'workshop' ) ); ?>"></textarea></label>';
                box.appendChild(d);
                d.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        })();
    </script>
    <?php
}
