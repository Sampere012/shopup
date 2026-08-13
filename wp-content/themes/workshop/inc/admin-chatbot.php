<?php
/**
 * Administración del asistente (chatbot) desde wp-admin.
 *
 * El administrador del sitio gestiona el bot sin tocar código:
 *  - Conocimiento: preguntas/respuestas (patrones + respuesta + enlace) que
 *    el bot responde ANTES que sus intenciones internas. Añadir/editar/borrar.
 *  - Mensajes: textos del widget (bienvenidas, fallback, upsell del plan…).
 *  - Comportamiento: mostrar/ocultar en público y en el panel.
 *  - Analítica: qué intenciones se usan más (mejora continua).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'ws_chatbot_admin_menu', 30 );
function ws_chatbot_admin_menu() {
    add_submenu_page(
        'ws-permissions',
        __( 'Asistente', 'workshop' ),
        __( 'Asistente', 'workshop' ),
        'manage_options',
        'ws-chatbot',
        'ws_admin_page_chatbot'
    );
}

/** Página principal con pestañas. */
function ws_admin_page_chatbot() {
    $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'knowledge';
    if ( ! in_array( $tab, array( 'knowledge', 'learn', 'messages', 'behavior', 'stats' ), true ) ) {
        $tab = 'knowledge';
    }

    // Guardado
    if ( isset( $_POST['ws_chatbot_nonce'] ) && wp_verify_nonce( $_POST['ws_chatbot_nonce'], 'ws_chatbot_save' ) ) {
        if ( 'knowledge' === $tab ) {
            ws_chatbot_save_knowledge( $_POST );
        } elseif ( 'learn' === $tab ) {
            ws_chatbot_save_learn( $_POST );
        } elseif ( 'messages' === $tab ) {
            ws_chatbot_save_messages( $_POST );
        } elseif ( 'behavior' === $tab ) {
            ws_chatbot_save_behavior( $_POST );
        } elseif ( 'stats' === $tab && ! empty( $_POST['ws_chatbot_reset_stats'] ) ) {
            delete_option( 'ws_chatbot_stats' );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analítica restablecida.', 'workshop' ) . '</p></div>';
        }
    }

    $tabs = array(
        'knowledge' => __( 'Conocimiento', 'workshop' ),
        'learn'     => __( 'Aprender', 'workshop' ),
        'messages'  => __( 'Mensajes', 'workshop' ),
        'behavior'  => __( 'Comportamiento', 'workshop' ),
        'stats'     => __( 'Analítica', 'workshop' ),
    );

    $kb = get_option( 'ws_chatbot_knowledge', ws_chatbot_default_knowledge() );
    $kb = is_array( $kb ) ? $kb : array();
    ?>
    <div class="wrap ws-admin-chatbot">
        <h1><span class="dashicons dashicons-format-chat" style="color:#4f46e5"></span> <?php esc_html_e( 'Asistente (chatbot del sitio)', 'workshop' ); ?></h1>
        <p class="description"><?php esc_html_e( 'El bot responde primero con esta base de conocimiento, luego con sus intenciones por rol. Edítalo aquí sin tocar código.', 'workshop' ); ?></p>

        <nav class="nav-tab-wrapper" style="margin:14px 0">
            <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ws-chatbot&tab=' . $key ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ( 'knowledge' === $tab ) : ?>
            <?php ws_chatbot_admin_knowledge( $kb ); ?>
        <?php elseif ( 'learn' === $tab ) : ?>
            <?php ws_chatbot_admin_learn(); ?>
        <?php elseif ( 'messages' === $tab ) : ?>
            <?php ws_chatbot_admin_messages_form(); ?>
        <?php elseif ( 'behavior' === $tab ) : ?>
            <?php ws_chatbot_admin_behavior_form(); ?>
        <?php else : ?>
            <?php ws_chatbot_admin_stats(); ?>
        <?php endif; ?>
    </div>
    <style>
        .ws-admin-chatbot .ws-kb-table { border-collapse: collapse; width: 100%; background: #fff; }
        .ws-admin-chatbot .ws-kb-table th, .ws-admin-chatbot .ws-kb-table td { border: 1px solid #dcdcde; padding: 8px 10px; text-align: left; vertical-align: top; }
        .ws-admin-chatbot .ws-kb-table th { background: #f6f7f7; }
        .ws-admin-chatbot .ws-kb-patterns { color: #6b7280; font-size: .86em; }
        .ws-admin-chatbot .ws-kb-item-form { background: #fff; border: 1px solid #dcdcde; padding: 14px; margin-top: 14px; max-width: 780px; }
        .ws-admin-chatbot .ws-kb-item-form label { display: block; font-weight: 600; margin: 10px 0 4px; }
        .ws-admin-chatbot .ws-kb-item-form input[type=text], .ws-admin-chatbot .ws-kb-item-form textarea, .ws-admin-chatbot .ws-kb-item-form select { width: 100%; max-width: 520px; }
        .ws-admin-chatbot .ws-kb-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
        .ws-admin-chatbot .ws-kb-help { color: #787c82; font-size: .85em; font-weight: 400; margin-top: 2px; }
        .ws-admin-chatbot .ws-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 14px 0; }
        .ws-admin-chatbot .ws-stat-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px; text-align: center; }
        .ws-admin-chatbot .ws-stat-card strong { display: block; font-size: 1.6em; color: #4f46e5; }
        .ws-admin-chatbot .ws-stat-card span { color: #6b7280; font-size: .85em; }
    </style>
    <?php
}

/* -------------------------------------------------------------------------
 * Pestaña Conocimiento
 * ---------------------------------------------------------------------- */

function ws_chatbot_save_knowledge( $post ) {
    $action = sanitize_key( $post['ws_kb_action'] ?? '' );
    $kb     = get_option( 'ws_chatbot_knowledge', ws_chatbot_default_knowledge() );
    $kb     = is_array( $kb ) ? $kb : array();

    if ( 'delete' === $action ) {
        $id  = sanitize_key( $post['ws_kb_id'] ?? '' );
        $kb  = array_values( array_filter( $kb, function ( $it ) use ( $id ) {
            return (string) ( $it['id'] ?? '' ) !== $id;
        } ) );
        update_option( 'ws_chatbot_knowledge', $kb );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Pregunta eliminada.', 'workshop' ) . '</p></div>';
        return;
    }

    if ( 'toggle' === $action ) {
        $id = sanitize_key( $post['ws_kb_id'] ?? '' );
        foreach ( $kb as &$it ) {
            if ( (string) ( $it['id'] ?? '' ) === $id ) {
                $it['active'] = empty( $it['active'] ) ? 1 : 0;
                break;
            }
        }
        unset( $it );
        update_option( 'ws_chatbot_knowledge', $kb );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Estado actualizado.', 'workshop' ) . '</p></div>';
        return;
    }

    $patterns = array();
    foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $post['ws_kb_patterns'] ?? '' ) ) as $line ) {
        $line = trim( $line );
        if ( '' !== $line ) {
            $patterns[] = $line;
        }
    }
    if ( empty( $patterns ) || '' === trim( (string) ( $post['ws_kb_answer'] ?? '' ) ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Faltan patrones o la respuesta.', 'workshop' ) . '</p></div>';
        return;
    }

    $item = array(
        'id'          => sanitize_key( $post['ws_kb_id'] ?? '' ),
        'patterns'    => array_slice( $patterns, 0, 30 ),
        'answer'      => sanitize_textarea_field( $post['ws_kb_answer'] ),
        'link_target' => sanitize_key( $post['ws_kb_link_target'] ?? '' ),
        'link_label'  => sanitize_text_field( $post['ws_kb_link_label'] ?? '' ),
        'link_icon'   => sanitize_text_field( $post['ws_kb_link_icon'] ?? '' ),
        'active'      => ! empty( $post['ws_kb_active'] ) ? 1 : 0,
    );

    if ( '' === $item['id'] || 'new' === $item['id'] ) {
        $item['id'] = 'kb-' . substr( md5( wp_json_encode( $item ) . uniqid( '', true ) ), 0, 8 );
        $kb[] = $item;
    } else {
        foreach ( $kb as &$it ) {
            if ( (string) ( $it['id'] ?? '' ) === $item['id'] ) {
                $it = $item;
                break;
            }
        }
        unset( $it );
    }
    update_option( 'ws_chatbot_knowledge', $kb );
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Conocimiento guardado.', 'workshop' ) . '</p></div>';
}

/* -------------------------------------------------------------------------
 * Pestaña Aprender: preguntas que el bot no supo responder y el admin puede
 * enseñarle (se convierten en conocimiento con la respuesta que escriba).
 * ---------------------------------------------------------------------- */

function ws_chatbot_save_learn( $post ) {
    $list = ws_chatbot_learnings();
    $action = sanitize_key( $post['ws_learn_action'] ?? '' );
    $idx    = (int) ( $post['ws_learn_idx'] ?? -1 );
    if ( $idx < 0 || $idx >= count( $list ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Elemento no encontrado.', 'workshop' ) . '</p></div>';
        return;
    }
    $question = (string) ( $list[ $idx ]['q'] ?? '' );

    if ( 'ignore' === $action ) {
        array_splice( $list, $idx, 1 );
        update_option( 'ws_chatbot_learnings', $list );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Pregunta descartada.', 'workshop' ) . '</p></div>';
        return;
    }

    $answer = sanitize_textarea_field( $post['ws_learn_answer'] ?? '' );
    if ( 'learn' === $action && ws_chatbot_learn_item( $question, $answer ) ) {
        array_splice( $list, $idx, 1 );
        update_option( 'ws_chatbot_learnings', $list );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '¡El bot aprendió esta respuesta! Ya está en el Conocimiento.', 'workshop' ) . '</p></div>';
        return;
    }
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Escribe la respuesta para enseñarle al bot.', 'workshop' ) . '</p></div>';
}

function ws_chatbot_admin_learn() {
    $list = ws_chatbot_learnings();
    if ( empty( $list ) ) {
        echo '<div class="card" style="max-width:640px"><p>🎓 ' . esc_html__( 'No hay preguntas pendientes por aprender. Cuando el bot no sepa responder algo, la pregunta aparecerá aquí y podrás enseñarle la respuesta.', 'workshop' ) . '</p></div>';
        return;
    }
    $total = 0;
    foreach ( $list as $l ) {
        $total += (int) ( $l['count'] ?? 0 );
    }
    ?>
    <div class="card" style="max-width:860px">
        <p><strong>🎓 Aprender</strong> — <?php echo esc_html( sprintf( __( '%d preguntas que el bot no supo responder (%d veces en total). Escríbeles la respuesta y el bot las aprende al instante.', 'workshop' ), count( $list ), $total ) ); ?></p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:38px">#</th>
                    <th><?php esc_html_e( 'Pregunta del usuario', 'workshop' ); ?></th>
                    <th style="width:70px"><?php esc_html_e( 'Veces', 'workshop' ); ?></th>
                    <th style="width:120px"><?php esc_html_e( 'Última vez', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $list as $i => $l ) : ?>
                <tr>
                    <td><?php echo (int) $i + 1; ?></td>
                    <td style="vertical-align:top">
                        <strong><?php echo esc_html( (string) ( $l['q'] ?? '' ) ); ?></strong>
                        <form method="post" style="margin:6px 0 0">
                            <?php wp_nonce_field( 'ws_chatbot_save', 'ws_chatbot_nonce' ); ?>
                            <input type="hidden" name="ws_learn_idx" value="<?php echo (int) $i; ?>">
                            <textarea name="ws_learn_answer" rows="2" style="width:100%" placeholder="<?php esc_attr_e( 'Escribe la respuesta que el bot debe dar…', 'workshop' ); ?>"></textarea>
                            <p style="margin:6px 0 0">
                                <button class="button button-primary" name="ws_learn_action" value="learn">✓ <?php esc_html_e( 'Enseñar y aprender', 'workshop' ); ?></button>
                                <button class="button" name="ws_learn_action" value="ignore"><?php esc_html_e( 'Descartar', 'workshop' ); ?></button>
                            </p>
                        </form>
                    </td>
                    <td style="text-align:center"><span class="ws-kb-help"><?php echo (int) ( $l['count'] ?? 0 ); ?>×</span></td>
                    <td style="font-size:12px;color:#646970"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( (string) ( $l['t'] ?? '' ) ) ?: time() ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="ws-kb-help"><?php esc_html_e( 'Cada pregunta aprendida se agrega automáticamente al Conocimiento (pestaña anterior) y podrás editarla o quitarla desde ahí.', 'workshop' ); ?></p>
    </div>
    <?php
}

function ws_chatbot_admin_knowledge( $kb ) {
    $editing = false;
    if ( isset( $_GET['edit'] ) ) { // Solo lectura de la URL para editar
        $eid = sanitize_key( $_GET['edit'] );
        foreach ( $kb as $it ) {
            if ( (string) ( $it['id'] ?? '' ) === $eid ) {
                $editing = $it;
                break;
            }
        }
    }
    ?>
    <table class="ws-kb-table">
        <thead>
            <tr>
                <th style="width:34px"><?php esc_html_e( 'Activo', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Preguntas (patrones)', 'workshop' ); ?></th>
                <th><?php esc_html_e( 'Respuesta', 'workshop' ); ?></th>
                <th style="width:120px"><?php esc_html_e( 'Enlace', 'workshop' ); ?></th>
                <th style="width:120px"><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $kb ) ) : ?>
            <tr><td colspan="5"><em><?php esc_html_e( 'Aún no hay preguntas configuradas. Añade la primera abajo.', 'workshop' ); ?></em></td></tr>
        <?php endif; ?>
        <?php foreach ( $kb as $it ) : ?>
            <tr>
                <td>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'ws_chatbot_save', 'ws_chatbot_nonce' ); ?>
                        <input type="hidden" name="ws_kb_action" value="toggle">
                        <input type="hidden" name="ws_kb_id" value="<?php echo esc_attr( $it['id'] ); ?>">
                        <button type="submit" class="button button-small" title="<?php echo empty( $it['active'] ) ? esc_attr__( 'Activar', 'workshop' ) : esc_attr__( 'Desactivar', 'workshop' ); ?>">
                            <?php echo empty( $it['active'] ) ? '❌' : '✅'; ?>
                        </button>
                    </form>
                </td>
                <td class="ws-kb-patterns"><?php echo esc_html( implode( ' · ', array_slice( (array) $it['patterns'], 0, 4 ) ) . ( count( (array) $it['patterns'] ) > 4 ? ' …' : '' ) ); ?></td>
                <td><?php echo esc_html( wp_trim_words( (string) $it['answer'], 22 ) ); ?></td>
                <td><?php echo $it['link_target'] ? '<code>' . esc_html( $it['link_target'] ) . '</code>' : '—'; ?></td>
                <td>
                    <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=ws-chatbot&tab=knowledge&edit=' . $it['id'] ) ); ?>"><?php esc_html_e( 'Editar', 'workshop' ); ?></a>
                    <form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar esta pregunta?', 'workshop' ) ); ?>');">
                        <?php wp_nonce_field( 'ws_chatbot_save', 'ws_chatbot_nonce' ); ?>
                        <input type="hidden" name="ws_kb_action" value="delete">
                        <input type="hidden" name="ws_kb_id" value="<?php echo esc_attr( $it['id'] ); ?>">
                        <button type="submit" class="button button-small"><?php esc_html_e( 'Borrar', 'workshop' ); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php ws_chatbot_admin_kb_form( $editing ); ?>
    <?php
}

function ws_chatbot_admin_kb_form( $editing ) {
    $targets = array(
        ''                   => __( '— Sin enlace —', 'workshop' ),
        'register'           => __( 'Registro de negocio', 'workshop' ),
        'stores'             => __( 'Tiendas del mercado', 'workshop' ),
        'ayuda'              => __( 'Página de Ayuda', 'workshop' ),
        'contacto'           => __( 'Página de Contacto', 'workshop' ),
        'market'             => __( 'Portada del mercado', 'workshop' ),
        'panel:dashboard'    => __( 'Panel: Inicio', 'workshop' ),
        'panel:products'     => __( 'Panel: Productos', 'workshop' ),
        'panel:orders'       => __( 'Panel: Pedidos', 'workshop' ),
        'panel:stock'        => __( 'Panel: Stock', 'workshop' ),
        'panel:pos'          => __( 'Panel: Vender (POS)', 'workshop' ),
        'panel:pos-sales'    => __( 'Panel: Ventas POS', 'workshop' ),
        'panel:customers'    => __( 'Panel: Clientes', 'workshop' ),
        'panel:workers'      => __( 'Panel: Trabajadores', 'workshop' ),
        'panel:reports'      => __( 'Panel: Reportes', 'workshop' ),
        'panel:loyalty'      => __( 'Panel: Fidelización', 'workshop' ),
        'panel:reviews'      => __( 'Panel: Valoraciones', 'workshop' ),
        'panel:appearance'   => __( 'Panel: Tu sitio', 'workshop' ),
        'panel:plan'         => __( 'Panel: Mi plan', 'workshop' ),
    );
    $item = $editing ? $editing : array(
        'id' => 'new', 'patterns' => array(), 'answer' => '',
        'link_target' => '', 'link_label' => '', 'link_icon' => '', 'active' => 1,
    );
    ?>
    <form method="post" class="ws-kb-item-form">
        <?php wp_nonce_field( 'ws_chatbot_save', 'ws_chatbot_nonce' ); ?>
        <input type="hidden" name="ws_kb_action" value="save">
        <input type="hidden" name="ws_kb_id" value="<?php echo esc_attr( $item['id'] ); ?>">
        <h2><?php echo $editing ? esc_html__( 'Editar pregunta', 'workshop' ) : esc_html__( 'Añadir nueva pregunta', 'workshop' ); ?></h2>

        <label><?php esc_html_e( 'Patrones (frases que activan esta respuesta)', 'workshop' ); ?></label>
        <textarea name="ws_kb_patterns" rows="3" placeholder="<?php esc_attr_e( 'Una frase por línea. Ej:&#10;como comprar&#10;hacer un pedido', 'workshop' ); ?>"><?php echo esc_textarea( implode( "\n", (array) $item['patterns'] ) ); ?></textarea>
        <p class="ws-kb-help"><?php esc_html_e( 'El bot responde con esta respuesta si el usuario escribe cualquiera de estas frases (coincidencia parcial).', 'workshop' ); ?></p>

        <label><?php esc_html_e( 'Respuesta del bot', 'workshop' ); ?></label>
        <textarea name="ws_kb_answer" rows="3" style="width:100%"><?php echo esc_textarea( (string) $item['answer'] ); ?></textarea>

        <div class="ws-kb-grid">
            <div>
                <label><?php esc_html_e( 'Enlace (opcional)', 'workshop' ); ?></label>
                <select name="ws_kb_link_target">
                    <?php foreach ( $targets as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $item['link_target'], $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php esc_html_e( 'Texto del botón', 'workshop' ); ?></label>
                <input type="text" name="ws_kb_link_label" value="<?php echo esc_attr( $item['link_label'] ); ?>" placeholder="<?php esc_attr_e( 'Ej: Ver tiendas', 'workshop' ); ?>">
                <p class="ws-kb-help"><?php esc_html_e( 'Icono FontAwesome (opcional): fa-store, fa-arrow-pointer…', 'workshop' ); ?></p>
                <input type="text" name="ws_kb_link_icon" value="<?php echo esc_attr( $item['link_icon'] ); ?>" placeholder="fa-arrow-pointer">
            </div>
        </div>

        <p>
            <label><input type="checkbox" name="ws_kb_active" value="1" <?php checked( ! empty( $item['active'] ), 1 ); ?>> <?php esc_html_e( 'Activa', 'workshop' ); ?></label>
        </p>
        <p>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar pregunta', 'workshop' ); ?></button>
            <?php if ( $editing ) : ?>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ws-chatbot&tab=knowledge' ) ); ?>"><?php esc_html_e( 'Cancelar', 'workshop' ); ?></a>
            <?php endif; ?>
        </p>
    </form>
    <?php
}

/* -------------------------------------------------------------------------
 * Pestaña Mensajes
 * ---------------------------------------------------------------------- */

function ws_chatbot_save_messages( $post ) {
    $keys = array(
        'title', 'subtitle', 'placeholder', 'typing', 'open',
        'welcomePublic', 'welcomeGuest', 'welcomePanel', 'welcomeNewUser',
        'welcomeLocked', 'lockedBody', 'goPlan', 'atajosTitle', 'noAtajos',
        'productHint', 'stockHint', 'ordersHint', 'registerHook', 'fallback',
        'storeTeaser',
    );
    $messages = array();
    foreach ( $keys as $k ) {
        $v = sanitize_textarea_field( $post[ 'ws_msg_' . $k ] ?? '' );
        if ( '' !== $v ) {
            $messages[ $k ] = $v;
        }
    }
    $opt = get_option( 'ws_chatbot_config', array() );
    $opt = is_array( $opt ) ? $opt : array();
    $opt['messages'] = $messages;
    update_option( 'ws_chatbot_config', $opt );
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mensajes guardados.', 'workshop' ) . '</p></div>';
}

function ws_chatbot_admin_messages_form() {
    $admin  = ws_chatbot_admin_settings();
    $msgs   = array_merge( ws_chatbot_strings(), $admin['messages'] );
    $fields = array(
        'title'          => __( 'Título del widget', 'workshop' ),
        'subtitle'       => __( 'Subtítulo (estado)', 'workshop' ),
        'placeholder'    => __( 'Placeholder del campo', 'workshop' ),
        'welcomePublic'  => __( 'Bienvenida pública', 'workshop' ),
        'welcomeGuest'   => __( 'Bienvenida visitante sin sesión', 'workshop' ),
        'welcomePanel'   => __( 'Bienvenida en el panel', 'workshop' ),
        'welcomeNewUser' => __( 'Bienvenida cliente con sesión', 'workshop' ),
        'welcomeLocked'  => __( 'Plan sin chatbot (título)', 'workshop' ),
        'lockedBody'     => __( 'Plan sin chatbot (texto)', 'workshop' ),
        'goPlan'         => __( 'Botón "ver planes" (upsell)', 'workshop' ),
        'atajosTitle'    => __( 'Título de atajos', 'workshop' ),
        'noAtajos'       => __( 'Intro de atajos', 'workshop' ),
        'registerHook'   => __( 'Frase de conversión al registro', 'workshop' ),
        'fallback'       => __( 'Respuesta cuando no entiende', 'workshop' ),
        'storeTeaser'    => __( 'Sugerencia dentro de una tienda', 'workshop' ),
    );
    ?>
    <form method="post" style="max-width:720px;background:#fff;border:1px solid #dcdcde;padding:16px">
        <?php wp_nonce_field( 'ws_chatbot_save', 'ws_chatbot_nonce' ); ?>
        <?php foreach ( $fields as $key => $label ) : ?>
            <p>
                <label for="msg-<?php echo esc_attr( $key ); ?>" style="font-weight:600"><?php echo esc_html( $label ); ?></label><br>
                <textarea id="msg-<?php echo esc_attr( $key ); ?>" name="ws_msg_<?php echo esc_attr( $key ); ?>" rows="2" style="width:100%;margin-top:4px"><?php echo esc_textarea( $msgs[ $key ] ?? '' ); ?></textarea>
            </p>
        <?php endforeach; ?>
        <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar mensajes', 'workshop' ); ?></button></p>
    </form>
    <?php
}

/* -------------------------------------------------------------------------
 * Pestaña Comportamiento
 * ---------------------------------------------------------------------- */

function ws_chatbot_save_behavior( $post ) {
    $opt = get_option( 'ws_chatbot_config', array() );
    $opt = is_array( $opt ) ? $opt : array();
    $opt['enabled_public'] = ! empty( $post['ws_enabled_public'] ) ? 1 : 0;
    $opt['enabled_panel']  = ! empty( $post['ws_enabled_panel'] ) ? 1 : 0;
    $opt['llm_key']        = sanitize_text_field( $post['ws_llm_key'] ?? '' );
    $opt['llm_model']      = sanitize_text_field( $post['ws_llm_model'] ?? 'openrouter/auto' );
    $opt['llm_provider']   = in_array( (string) ( $post['ws_llm_provider'] ?? '' ), array( 'openrouter', 'groq', 'custom' ), true ) ? (string) $post['ws_llm_provider'] : 'openrouter';
    $opt['llm_base_url']   = esc_url_raw( trim( (string) ( $post['ws_llm_base_url'] ?? '' ) ) );
    update_option( 'ws_chatbot_config', $opt );
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Comportamiento guardado.', 'workshop' ) . '</p></div>';
}

function ws_chatbot_admin_behavior_form() {
    $admin = ws_chatbot_admin_settings();
    ?>
    <form method="post" style="max-width:720px;background:#fff;border:1px solid #dcdcde;padding:16px">
        <?php wp_nonce_field( 'ws_chatbot_save', 'ws_chatbot_nonce' ); ?>
        <p>
            <label style="font-weight:600">
                <input type="checkbox" name="ws_enabled_public" value="1" <?php checked( $admin['enabled_public'], 1 ); ?>>
                <?php esc_html_e( 'Mostrar el asistente en el sitio público (visitantes y clientes)', 'workshop' ); ?>
            </label>
        </p>
        <p>
            <label style="font-weight:600">
                <input type="checkbox" name="ws_enabled_panel" value="1" <?php checked( $admin['enabled_panel'], 1 ); ?>>
                <?php esc_html_e( 'Mostrar el asistente en el panel de negocio (si el plan lo incluye; si no, muestra el aviso de mejora)', 'workshop' ); ?>
            </label>
        </p>
        <p class="description"><?php esc_html_e( 'Recuerda: en el panel el bot asiste solo si el plan del negocio incluye chatbot (Planes → checkbox "Incluye el asistente"). Visitantes y nuevos usuarios siempre reciben asistencia.', 'workshop' ); ?></p>

        <hr style="margin:18px 0">
        <h2><?php esc_html_e( 'IA opcional (conversación libre)', 'workshop' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Cuando el bot no entiende una frase, puede responder con un modelo de IA. La clave se guarda solo en el servidor (nunca llega al navegador) y se consume por interacción no resuelta.', 'workshop' ); ?></p>
        <p>
            <label for="ws-llm-provider" style="font-weight:600"><?php esc_html_e( 'Proveedor de IA', 'workshop' ); ?></label><br>
            <select id="ws-llm-provider" name="ws_llm_provider" style="max-width:420px;margin-top:4px">
                <option value="openrouter" <?php selected( $admin['llm_provider'], 'openrouter' ); ?>><?php esc_html_e( 'OpenRouter (openrouter.ai)', 'workshop' ); ?></option>
                <option value="groq" <?php selected( $admin['llm_provider'], 'groq' ); ?>><?php esc_html_e( 'Groq (groq.com — gratis y rápido)', 'workshop' ); ?></option>
                <option value="custom" <?php selected( $admin['llm_provider'], 'custom' ); ?>><?php esc_html_e( 'Otro (compatible con OpenAI)', 'workshop' ); ?></option>
            </select>
        </p>
        <p>
            <label for="ws-llm-key" style="font-weight:600"><?php esc_html_e( 'Clave del proveedor (API key)', 'workshop' ); ?></label><br>
            <input type="password" id="ws-llm-key" name="ws_llm_key" value="<?php echo esc_attr( $admin['llm_key'] ); ?>" style="width:100%;max-width:420px;margin-top:4px" autocomplete="off" placeholder="sk-…">
            <span class="ws-kb-help"><?php esc_html_e( 'OpenRouter: openrouter.ai → API Keys · Groq: console.groq.com → API Keys · Otro: la clave del proveedor que uses. Sin clave, el bot usa solo sus respuestas internas.', 'workshop' ); ?></span>
        </p>
        <p id="ws-llm-base-wrap"<?php echo 'custom' === $admin['llm_provider'] ? '' : ' style="display:none"'; ?>>
            <label for="ws-llm-base" style="font-weight:600"><?php esc_html_e( 'URL base del proveedor (OpenAI-compatible)', 'workshop' ); ?></label><br>
            <input type="url" id="ws-llm-base" name="ws_llm_base_url" value="<?php echo esc_attr( $admin['llm_base_url'] ); ?>" style="width:100%;max-width:420px;margin-top:4px" placeholder="https://api.tuproveedor.com/v1">
            <span class="ws-kb-help"><?php esc_html_e( 'Ej. https://api.tuproveedor.com/v1 (se agrega /chat/completions automáticamente).', 'workshop' ); ?></span>
        </p>
        <p>
            <label for="ws-llm-model" style="font-weight:600"><?php esc_html_e( 'Modelo', 'workshop' ); ?></label><br>
            <input type="text" id="ws-llm-model" name="ws_llm_model" value="<?php echo esc_attr( $admin['llm_model'] ); ?>" style="width:100%;max-width:420px;margin-top:4px" list="ws-llm-models" placeholder="openrouter/auto">
            <datalist id="ws-llm-models">
                <option value="openrouter/auto"><?php esc_html_e( 'OpenRouter: auto', 'workshop' ); ?></option>
                <option value="meta-llama/llama-3.3-70b-instruct"><?php esc_html_e( 'OpenRouter: Llama 3.3 70B', 'workshop' ); ?></option>
                <option value="openai/gpt-4o-mini"><?php esc_html_e( 'OpenRouter: GPT-4o mini', 'workshop' ); ?></option>
                <option value="llama-3.3-70b-versatile"><?php esc_html_e( 'Groq: Llama 3.3 70B', 'workshop' ); ?></option>
                <option value="llama-3.1-8b-instant"><?php esc_html_e( 'Groq: Llama 3.1 8B (rápido)', 'workshop' ); ?></option>
                <option value="llama3-70b-8192"><?php esc_html_e( 'Groq: Llama3 70B', 'workshop' ); ?></option>
                <option value="llama3-8b-8192"><?php esc_html_e( 'Groq: Llama3 8B', 'workshop' ); ?></option>
            </datalist>
        </p>
        <script>
        (function () {
            var prov = document.getElementById('ws-llm-provider');
            var base = document.getElementById('ws-llm-base-wrap');
            var model = document.getElementById('ws-llm-model');
            if (!prov || !base || !model) { return; }
            // Default de modelo por proveedor: evita mandar un modelo de
            // OpenRouter (ej. openrouter/auto) a Groq u otro proveedor.
            var defaults = {
                openrouter: 'openrouter/auto',
                groq: 'llama-3.3-70b-versatile',
                custom: ''
            };
            var isOtherProviderModel = function (m) {
                m = (m || '').trim().toLowerCase();
                return m.indexOf('openrouter/') === 0 || m.indexOf('meta-llama/') === 0 ||
                       m.indexOf('openai/') === 0 || m.indexOf('anthropic/') === 0 ||
                       m.indexOf('llama-3.3-70b-versatile') === 0 || m.indexOf('llama-3.1-8b-instant') === 0 ||
                       m.indexOf('llama3-70b-8192') === 0 || m.indexOf('llama3-8b-8192') === 0;
            };
            prov.addEventListener('change', function () {
                base.style.display = prov.value === 'custom' ? '' : 'none';
                var d = defaults[prov.value];
                if (d !== undefined && isOtherProviderModel(model.value)) {
                    model.value = d;
                }
            });
            // Validación amable de la URL base (custom): exige esquema http(s).
            var form = prov.closest('form');
            if (form) {
                form.addEventListener('submit', function (e) {
                    var url = document.getElementById('ws-llm-base');
                    if (prov.value === 'custom' && url && url.value.trim() !== '' &&
                        url.value.indexOf('http://') !== 0 && url.value.indexOf('https://') !== 0) {
                        e.preventDefault();
                        alert('La URL base debe empezar con http:// o https://');
                        url.focus();
                    }
                });
            }
        })();
        </script>
        <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar comportamiento', 'workshop' ); ?></button></p>
    </form>
    <?php
}

/* -------------------------------------------------------------------------
 * Pestaña Analítica
 * ---------------------------------------------------------------------- */

function ws_chatbot_admin_stats() {
    $log = get_option( 'ws_chatbot_stats', array() );
    $log = is_array( $log ) ? $log : array();
    $total = (int) ( $log['_total'] ?? 0 );
    $last  = (string) ( $log['_last'] ?? '' );

    $rows = array();
    foreach ( $log as $key => $count ) {
        if ( 0 === strpos( (string) $key, '_' ) ) {
            continue;
        }
        $rows[ $key ] = (int) $count;
    }
    arsort( $rows );
    $fallback = (int) ( $log['public:fallback'] ?? 0 ) + (int) ( $log['panel:fallback'] ?? 0 );
    $flows    = 0;
    foreach ( $rows as $key => $count ) {
        if ( 0 === strpos( (string) $key, 'action:' ) || 0 === strpos( (string) $key, 'llm:' ) ) {
            $flows += (int) $count;
        }
    }
    ?>
    <div class="ws-stats-grid">
        <div class="ws-stat-card"><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong><span><?php esc_html_e( 'Interacciones totales', 'workshop' ); ?></span></div>
        <div class="ws-stat-card"><strong><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></strong><span><?php esc_html_e( 'Intenciones distintas', 'workshop' ); ?></span></div>
        <div class="ws-stat-card"><strong><?php echo esc_html( number_format_i18n( $fallback ) ); ?></strong><span><?php esc_html_e( 'Sin respuesta (fallback)', 'workshop' ); ?></span></div>
        <div class="ws-stat-card"><strong><?php echo esc_html( number_format_i18n( $flows ) ); ?></strong><span><?php esc_html_e( 'Acciones/IA ejecutadas', 'workshop' ); ?></span></div>
        <div class="ws-stat-card"><strong><?php echo esc_html( $last ? mysql2date( 'd/m/Y H:i', $last ) : '—' ); ?></strong><span><?php esc_html_e( 'Última interacción', 'workshop' ); ?></span></div>
    </div>
    <?php if ( $fallback > 0 ) : ?>
        <p class="ws-kb-help"><?php esc_html_e( 'Las frases que caen en "fallback" son oportunidades de entrenamiento: conviértelas en preguntas de la pestaña Conocimiento (o activa la IA en Comportamiento) para que el bot las resuelva.', 'workshop' ); ?></p>
    <?php endif; ?>

    <table class="ws-kb-table" style="max-width:720px">
        <thead><tr><th><?php esc_html_e( 'Intención', 'workshop' ); ?></th><th style="width:120px"><?php esc_html_e( 'Usos', 'workshop' ); ?></th></tr></thead>
        <tbody>
        <?php if ( empty( $rows ) ) : ?>
            <tr><td colspan="2"><em><?php esc_html_e( 'Aún no hay interacciones registradas.', 'workshop' ); ?></em></td></tr>
        <?php endif; ?>
        <?php foreach ( $rows as $key => $count ) : ?>
            <tr><td><code><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top:14px" onsubmit="return confirm('<?php echo esc_js( __( '¿Restablecer toda la analítica?', 'workshop' ) ); ?>');">
        <?php wp_nonce_field( 'ws_chatbot_save', 'ws_chatbot_nonce' ); ?>
        <button type="submit" name="ws_chatbot_reset_stats" value="1" class="button"><?php esc_html_e( 'Restablecer analítica', 'workshop' ); ?></button>
    </form>
    <?php
}
