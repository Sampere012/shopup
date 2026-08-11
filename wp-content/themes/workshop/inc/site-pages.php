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
define( 'WS_SITE_PAGES_VERSION', '3' );

/**
 * Valores por defecto de las páginas e información del pie.
 *
 * Estas páginas quedan listas y bien presentadas desde el primer momento; el
 * administrador las edita después desde wp-admin (ShopUp → Páginas y pie).
 */
function ws_site_pages_defaults() {
    return array(
        'help'    => array(
            'title'    => __( 'Ayuda', 'workshop' ),
            'badge'    => __( 'Centro de ayuda', 'workshop' ),
            'subtitle' => __( 'Resolvemos tus dudas para que compres con total confianza.', 'workshop' ),
            'content'  => ''
                . '<p>Te ayudamos a sacarle el máximo partido al mercado. Aquí tienes las respuestas a las dudas más frecuentes; si no encuentras la tuya, escríbenos y te responderemos lo antes posible.</p>',
        ),
        'contact' => array(
            'title'    => __( 'Contacto', 'workshop' ),
            'badge'    => __( 'Estamos para ayudarte', 'workshop' ),
            'subtitle' => __( '¿Tienes dudas, sugerencias o quieres aparecer en el mercado? Elige la vía que prefieras y te responderemos lo antes posible.', 'workshop' ),
            'content'  => '',
            'email'    => '',
            'phone'    => '',
            'whatsapp' => '',
            'address'  => '',
            'hours'    => '',
        ),
        'about'   => array(
            'title'    => __( 'Acerca de nosotros', 'workshop' ),
            'badge'    => __( 'Quiénes somos', 'workshop' ),
            'subtitle' => __( 'Conectamos negocios locales con sus clientes para que comprar cerca sea tan fácil como hacerlo online.', 'workshop' ),
            'content'  => ''
                . '<p>Somos una plataforma que conecta negocios locales con sus clientes. Queremos que cada tienda, por pequeña que sea, tenga su espacio en el mercado digital.</p>'
                . '<h2>Nuestra misión</h2>'
                . '<p>Ayudar a los negocios a vender online sin complicaciones: pedidos por WhatsApp, stock en tiempo real y todo el control desde un solo panel.</p>'
                . '<h2>¿Por qué elegirnos?</h2>'
                . '<ul><li>Tu tienda visible para todos los clientes.</li><li>Pedidos directos por WhatsApp, sin intermediarios.</li><li>Stock y ventas sincronizados al instante.</li><li>Empiezas gratis, sin tarjeta.</li></ul>',
        ),
        // Preguntas frecuentes de la página de Ayuda: lista de temas, cada uno
        // con sus preguntas y respuestas. El admin las gestiona desde wp-admin
        // (ShopUp → Páginas y pie → Preguntas frecuentes).
        'faqs'    => array(
            array(
                'topic' => __( 'Pedidos y compras', 'workshop' ),
                'items' => array(
                    array(
                        'q' => __( '¿Cómo hago un pedido?', 'workshop' ),
                        'a' => __( 'Entra en el directorio de tiendas, elige tu tienda favorita y explora sus productos. Añade lo que quieras al carrito, escribe tus datos y confirma el pedido. Te llegará el detalle por WhatsApp.', 'workshop' ),
                    ),
                    array(
                        'q' => __( '¿Cómo hago el seguimiento de mi pedido?', 'workshop' ),
                        'a' => __( 'Tras confirmar tu pedido recibirás un número de seguimiento. La tienda te contacta por WhatsApp para coordinar la entrega o la recogida.', 'workshop' ),
                    ),
                    array(
                        'q' => __( '¿Qué métodos de pago aceptáis?', 'workshop' ),
                        'a' => __( 'Efectivo al recibir el pedido, transferencia bancaria u otros métodos que la tienda indique al confirmar tu pedido.', 'workshop' ),
                    ),
                ),
            ),
            array(
                'topic' => __( 'Envíos y devoluciones', 'workshop' ),
                'items' => array(
                    array(
                        'q' => __( '¿Hay envío a domicilio?', 'workshop' ),
                        'a' => __( 'Depende de cada tienda. En la página de la tienda verás si ofrece reparto y cuál es su zona de cobertura, así como el coste del envío.', 'workshop' ),
                    ),
                    array(
                        'q' => __( '¿Puedo devolver un producto?', 'workshop' ),
                        'a' => __( 'Consulta con la tienda donde compraste. Cada negocio gestiona sus devoluciones de forma directa y te indicará cómo proceder.', 'workshop' ),
                    ),
                ),
            ),
            array(
                'topic' => __( 'Tu cuenta y tu tienda', 'workshop' ),
                'items' => array(
                    array(
                        'q' => __( '¿Cómo creo mi tienda?', 'workshop' ),
                        'a' => __( 'Crea tu cuenta gratis desde el marketplace, personaliza tu tienda y empieza a vender. No necesitas tarjeta para empezar.', 'workshop' ),
                    ),
                    array(
                        'q' => __( '¿Cómo contacto con el soporte?', 'workshop' ),
                        'a' => __( 'Usa la página de Contacto o el formulario de WhatsApp y te responderemos lo antes posible.', 'workshop' ),
                    ),
                ),
            ),
        ),
        // Garantías de confianza: tarjetas que se muestran bajo el contenido de
        // las páginas estáticas para transmitir seguridad (icono FA + título + texto).
        'trust'   => array(
            array(
                'icon'  => 'fa-shield-halved',
                'title' => __( 'Compra segura', 'workshop' ),
                'text'  => __( 'Haces el pedido directo con la tienda y el detalle te llega por WhatsApp, sin intermediarios.', 'workshop' ),
            ),
            array(
                'icon'  => 'fa-bolt',
                'title' => __( 'Respuesta rápida', 'workshop' ),
                'text'  => __( 'La tienda confirma tu pedido enseguida y coordina la entrega o la recogida.', 'workshop' ),
            ),
            array(
                'icon'  => 'fa-handshake',
                'title' => __( 'Apoyas el comercio local', 'workshop' ),
                'text'  => __( 'Cada compra ayuda a crecer a un negocio de tu comunidad.', 'workshop' ),
            ),
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
 * Migración ligera: completa las claves nuevas (badge, subtítulo, WhatsApp,
 * horario y garantías de confianza) en instalaciones que ya tenían la opción
 * guardada con el esquema anterior, conservando lo que el admin haya editado.
 */
add_action( 'init', 'ws_site_pages_migrate', 6 );
function ws_site_pages_migrate() {
    if ( get_option( 'ws_site_pages_version' ) === WS_SITE_PAGES_VERSION ) {
        return;
    }
    $defaults = ws_site_pages_defaults();
    $saved    = get_option( WS_SITE_PAGES_KEY, array() );
    $saved    = is_array( $saved ) ? $saved : array();
    $cur      = $saved;

    foreach ( array( 'help', 'contact', 'about' ) as $key ) {
        $cur[ $key ] = array_merge( $defaults[ $key ], (array) ( $cur[ $key ] ?? array() ) );
    }
    if ( empty( $cur['trust'] ) ) {
        $cur['trust'] = $defaults['trust'];
    }

    // v3: la página de Ayuda pasa a ser un FAQ por temas. Solo se reemplaza el
    // contenido si aún conserva el HTML antiguo de preguntas (marcadores), para
    // no pisar un intro que el admin haya personalizado. También se elimina el
    // bloque "¿Necesitas más ayuda?" que duplicaba el enlace a Contacto (el
    // panel lateral ya enlaza).
    $help_old = (string) ( $cur['help']['content'] ?? '' );
    if ( false !== strpos( $help_old, '¿Cómo hago un pedido?' ) || false !== strpos( $help_old, '¿Necesitas más ayuda?' ) ) {
        $cur['help']['content'] = $defaults['help']['content'];
    }
    // v3: Acerca de nosotros: se elimina la sección "¿Tienes un negocio?" con
    // el enlace "Únete gratis" del contenido; queda el card lateral "Crear mi
    // tienda" como única vía de registro. Si el regex no matchea (contenido
    // personalizado), se conserva el texto del admin.
    $about_old = (string) ( $cur['about']['content'] ?? '' );
    $about_new = preg_replace(
        '/<h2[^>]*>\s*¿Tienes un negocio\?.*?<\/p>/isu',
        '',
        $about_old
    );
    if ( null !== $about_new && trim( $about_new ) !== trim( $about_old ) ) {
        $cur['about']['content'] = trim( $about_new );
    } else {
        $cur['about']['content'] = $about_old;
    }
    // v3: FAQs por temas.
    if ( empty( $cur['faqs'] ) ) {
        $cur['faqs'] = $defaults['faqs'];
    }

    update_option( WS_SITE_PAGES_KEY, $cur );
    update_option( 'ws_site_pages_version', WS_SITE_PAGES_VERSION );
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

    // Garantías de confianza (icono + título + texto).
    $trust = array();
    foreach ( (array) ( $saved['trust'] ?? array() ) as $t ) {
        if ( is_array( $t ) && '' !== trim( (string) ( $t['title'] ?? '' ) ) ) {
            $trust[] = array(
                'icon'  => trim( (string) ( $t['icon'] ?? '' ) ),
                'title' => trim( (string) $t['title'] ),
                'text'  => trim( (string) ( $t['text'] ?? '' ) ),
            );
        }
    }
    $defaults['trust'] = $trust;

    // Preguntas frecuentes: lista de temas, cada uno con sus preguntas.
    $faqs = array();
    foreach ( (array) ( $saved['faqs'] ?? array() ) as $topic ) {
        if ( ! is_array( $topic ) || '' === trim( (string) ( $topic['topic'] ?? '' ) ) ) {
            continue;
        }
        $items = array();
        foreach ( (array) ( $topic['items'] ?? array() ) as $it ) {
            if ( is_array( $it ) && '' !== trim( (string) ( $it['q'] ?? '' ) ) ) {
                $items[] = array(
                    'q' => trim( (string) $it['q'] ),
                    'a' => trim( (string) ( $it['a'] ?? '' ) ),
                );
            }
        }
        if ( ! empty( $items ) ) {
            $faqs[] = array( 'topic' => trim( $topic['topic'] ), 'items' => $items );
        }
    }
    if ( ! array_key_exists( 'faqs', $saved ) ) {
        $faqs = $defaults['faqs'];
    }
    $defaults['faqs'] = $faqs;

    return $defaults;
}

/**
 * Normaliza texto de una pregunta/tema para comparar: minúsculas, sin tildes
 * ni signos de puntuación (solo letras y números).
 */
function ws_faq_norm( $text ) {
    $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
    $text = mb_strtolower( trim( $text ), 'UTF-8' );
    $text = strtr(
        $text,
        array(
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i',
            'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
        )
    );
    return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
}

/**
 * Todas las FAQs disponibles: las editables del administrador + la biblioteca
 * grande (600). Se fusionan por tema y se deduplican por pregunta normalizada:
 * la versión del administrador gana; los temas/preguntas de la biblioteca que
 * el admin no tenga se agregan. El editor de wp-admin solo muestra las FAQs
 * guardadas, así no se vuelve inmanejable.
 */
function ws_site_faqs_all() {
    // Memoización por request: se llama en el render de Ayuda, el pie y el
    // conocimiento del chat; con 600+ ítems no conviene reconstruir.
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }

    $pages = ws_site_pages();
    $faqs  = (array) ( $pages['faqs'] ?? array() );

    if ( ! function_exists( 'ws_faq_big_library' ) ) {
        $cached = $faqs;
        return $cached;
    }

    // Índice por tema normalizado, con preguntas vistas para deduplicar.
    $merged = array();
    foreach ( $faqs as $topic ) {
        if ( ! is_array( $topic ) || '' === trim( (string) ( $topic['topic'] ?? '' ) ) ) {
            continue;
        }
        $tname = trim( (string) $topic['topic'] );
        $tkey  = ws_faq_norm( $tname );
        if ( '' === $tkey ) {
            continue;
        }
        // Evita colisión: si dos nombres distintos normalizan igual, mantén
        // ambos como temas separados (clave compuesta).
        $ukey = $tkey;
        if ( isset( $merged[ $ukey ] ) && $merged[ $ukey ]['topic'] !== $tname ) {
            $ukey = $tkey . '|' . ws_faq_norm( $tname ) . '|' . md5( $tname );
        }
        if ( ! isset( $merged[ $ukey ] ) ) {
            $merged[ $ukey ] = array( 'topic' => $tname, 'items' => array(), 'seen' => array() );
        }
        foreach ( (array) ( $topic['items'] ?? array() ) as $it ) {
            if ( ! is_array( $it ) || '' === trim( (string) ( $it['q'] ?? '' ) ) ) {
                continue;
            }
            $qkey = ws_faq_norm( $it['q'] );
            if ( '' === $qkey || isset( $merged[ $ukey ]['seen'][ $qkey ] ) ) {
                continue;
            }
            $merged[ $ukey ]['seen'][ $qkey ] = true;
            $merged[ $ukey ]['items'][]        = array(
                'q' => trim( (string) $it['q'] ),
                'a' => trim( (string) ( $it['a'] ?? '' ) ),
            );
        }
    }

    foreach ( ws_faq_big_library() as $topic ) {
        if ( ! is_array( $topic ) || '' === trim( (string) ( $topic['topic'] ?? '' ) ) ) {
            continue;
        }
        $tname = trim( (string) $topic['topic'] );
        $tkey  = ws_faq_norm( $tname );
        if ( '' === $tkey ) {
            continue;
        }
        $ukey = $tkey;
        foreach ( array_keys( $merged ) as $mk ) {
            if ( $mk === $tkey && $merged[ $mk ]['topic'] !== $tname ) {
                $ukey = $tkey . '|' . ws_faq_norm( $tname ) . '|' . md5( $tname );
                break;
            }
            if ( 0 === strpos( $mk, $tkey . '|' ) && $merged[ $mk ]['topic'] === $tname ) {
                $ukey = $mk;
                break;
            }
        }
        if ( ! isset( $merged[ $ukey ] ) ) {
            $merged[ $ukey ] = array( 'topic' => $tname, 'items' => array(), 'seen' => array() );
        }
        foreach ( (array) ( $topic['items'] ?? array() ) as $it ) {
            if ( ! is_array( $it ) || '' === trim( (string) ( $it['q'] ?? '' ) ) ) {
                continue;
            }
            $qkey = ws_faq_norm( $it['q'] );
            if ( '' === $qkey || isset( $merged[ $ukey ]['seen'][ $qkey ] ) ) {
                continue;
            }
            $merged[ $ukey ]['seen'][ $qkey ] = true;
            $merged[ $ukey ]['items'][]        = array(
                'q' => trim( (string) $it['q'] ),
                'a' => trim( (string) ( $it['a'] ?? '' ) ),
            );
        }
    }

    $out = array();
    foreach ( $merged as $t ) {
        if ( ! empty( $t['items'] ) ) {
            $out[] = array( 'topic' => $t['topic'], 'items' => $t['items'] );
        }
    }
    $cached = $out;
    return $cached;
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
                'title'    => sanitize_text_field( $_POST[ $key ]['title'] ?? '' ),
                'badge'    => sanitize_text_field( $_POST[ $key ]['badge'] ?? '' ),
                'subtitle' => sanitize_text_field( $_POST[ $key ]['subtitle'] ?? '' ),
                'content'  => wp_kses_post( (string) ( $_POST[ $key ]['content'] ?? '' ) ),
            );
        }
        $cur['contact'] = array(
            'title'    => sanitize_text_field( $_POST['contact']['title'] ?? '' ),
            'badge'    => sanitize_text_field( $_POST['contact']['badge'] ?? '' ),
            'subtitle' => sanitize_text_field( $_POST['contact']['subtitle'] ?? '' ),
            'content'  => wp_kses_post( (string) ( $_POST['contact']['content'] ?? '' ) ),
            'email'    => sanitize_email( (string) ( $_POST['contact']['email'] ?? '' ) ),
            'phone'    => sanitize_text_field( (string) ( $_POST['contact']['phone'] ?? '' ) ),
            'whatsapp' => sanitize_text_field( (string) ( $_POST['contact']['whatsapp'] ?? '' ) ),
            'address'  => sanitize_text_field( (string) ( $_POST['contact']['address'] ?? '' ) ),
            'hours'    => sanitize_text_field( (string) ( $_POST['contact']['hours'] ?? '' ) ),
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

        // Garantías de confianza.
        $trust = array();
        foreach ( (array) ( $_POST['trust_icon'] ?? array() ) as $i => $icon ) {
            $title = trim( sanitize_text_field( (string) ( $_POST['trust_title'][ $i ] ?? '' ) ) );
            if ( '' !== $title ) {
                $trust[] = array(
                    'icon'  => trim( sanitize_text_field( (string) $icon ) ),
                    'title' => $title,
                    'text'  => trim( sanitize_text_field( (string) ( $_POST['trust_text'][ $i ] ?? '' ) ) ),
                );
            }
        }
        $cur['trust'] = $trust;

        // Preguntas frecuentes: cada tema con sus preguntas y respuestas.
        $faqs = array();
        foreach ( (array) ( $_POST['faq_topic'] ?? array() ) as $ti => $topic ) {
            $topic = trim( sanitize_text_field( $topic ) );
            if ( '' === $topic ) {
                continue;
            }
            $items = array();
            foreach ( (array) ( $_POST['faq_q'][ $ti ] ?? array() ) as $ji => $q ) {
                $q = trim( sanitize_text_field( $q ) );
                $a = trim( wp_kses_post( (string) ( $_POST['faq_a'][ $ti ][ $ji ] ?? '' ) ) );
                if ( '' !== $q ) {
                    $items[] = array( 'q' => $q, 'a' => $a );
                }
            }
            if ( ! empty( $items ) ) {
                $faqs[] = array( 'topic' => $topic, 'items' => $items );
            }
        }
        $cur['faqs'] = $faqs;

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
                        <th scope="row"><label><?php esc_html_e( 'Etiqueta del encabezado', 'workshop' ); ?></label></th>
                        <td><input type="text" name="help[badge]" class="regular-text" value="<?php echo esc_attr( $pages['help']['badge'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Texto breve que aparece en la insignia superior (ej. "Centro de ayuda").', 'workshop' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Subtítulo', 'workshop' ); ?></label></th>
                        <td><input type="text" name="help[subtitle]" class="regular-text" style="width:100%" value="<?php echo esc_attr( $pages['help']['subtitle'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Frase corta bajo el título que resume la página.', 'workshop' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Texto de introducción', 'workshop' ); ?></label></th>
                        <td><textarea name="help[content]" class="large-text" rows="4"><?php echo esc_textarea( $pages['help']['content'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Texto breve que se muestra sobre las preguntas frecuentes. Las preguntas se gestionan en la sección "Preguntas frecuentes" de más abajo.', 'workshop' ); ?></p></td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-editor-help" style="margin-right:6px"></span><?php esc_html_e( 'Preguntas frecuentes (Ayuda)', 'workshop' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Las preguntas se agrupan por tema. Añade, edita o elimina temas y preguntas; se mostrarán en la página de Ayuda.', 'workshop' ); ?></p>
                <div id="ws-sp-faqs">
                    <?php foreach ( (array) $pages['faqs'] as $fi => $topic ) : ?>
                        <div class="ws-sp-faq-topic" data-i="<?php echo (int) $fi; ?>">
                            <div class="ws-sp-faq-topic-head">
                                <strong><?php esc_html_e( 'Tema', 'workshop' ); ?></strong>
                                <button type="button" class="button button-link-delete" onclick="this.closest('.ws-sp-faq-topic').remove();ws_sp_faq_sync();"><?php esc_html_e( 'Eliminar tema', 'workshop' ); ?></button>
                            </div>
                            <label class="ws-sp-field">
                                <span><?php esc_html_e( 'Nombre del tema', 'workshop' ); ?></span>
                                <input type="text" name="faq_topic[]" class="regular-text" placeholder="<?php esc_attr_e( 'Ej. Pedidos y compras', 'workshop' ); ?>" value="<?php echo esc_attr( $topic['topic'] ); ?>">
                            </label>
                            <div class="ws-sp-faq-items" data-holder>
                                <?php foreach ( (array) $topic['items'] as $item ) : ?>
                                    <div class="ws-sp-faq-item">
                                        <label class="ws-sp-field">
                                            <span><?php esc_html_e( 'Pregunta', 'workshop' ); ?></span>
                                            <input type="text" name="faq_q[<?php echo (int) $fi; ?>][]" class="regular-text" placeholder="<?php esc_attr_e( 'Ej. ¿Cómo hago un pedido?', 'workshop' ); ?>" value="<?php echo esc_attr( $item['q'] ); ?>">
                                        </label>
                                        <label class="ws-sp-field">
                                            <span><?php esc_html_e( 'Respuesta', 'workshop' ); ?></span>
                                            <textarea name="faq_a[<?php echo (int) $fi; ?>][]" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Escribe la respuesta…', 'workshop' ); ?>"><?php echo esc_textarea( $item['a'] ); ?></textarea>
                                        </label>
                                        <p><button type="button" class="button button-link-delete" onclick="this.closest('.ws-sp-faq-item').remove()"><?php esc_html_e( 'Quitar pregunta', 'workshop' ); ?></button></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p><button type="button" class="button" onclick="ws_sp_add_faq_item(this)"><?php esc_html_e( 'Añadir pregunta', 'workshop' ); ?></button></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button button-primary" onclick="ws_sp_add_faq_topic()"><?php esc_html_e( 'Añadir tema', 'workshop' ); ?></button></p>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-email-alt" style="margin-right:6px"></span><?php esc_html_e( 'Página de Contacto', 'workshop' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Título', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[title]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['title'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Etiqueta del encabezado', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[badge]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['badge'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Subtítulo', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[subtitle]" class="regular-text" style="width:100%" value="<?php echo esc_attr( $pages['contact']['subtitle'] ); ?>"></td>
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
                        <th scope="row"><label><?php esc_html_e( 'WhatsApp', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[whatsapp]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['whatsapp'] ); ?>" placeholder="+52 555 123 4567">
                            <p class="description"><?php esc_html_e( 'Se mostrará con un botón para abrir la conversación de WhatsApp.', 'workshop' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Dirección', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[address]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['address'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Horario', 'workshop' ); ?></label></th>
                        <td><input type="text" name="contact[hours]" class="regular-text" value="<?php echo esc_attr( $pages['contact']['hours'] ); ?>" placeholder="<?php esc_attr_e( 'Lun a Vie de 9:00 a 18:00', 'workshop' ); ?>"></td>
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
                        <th scope="row"><label><?php esc_html_e( 'Etiqueta del encabezado', 'workshop' ); ?></label></th>
                        <td><input type="text" name="about[badge]" class="regular-text" value="<?php echo esc_attr( $pages['about']['badge'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Subtítulo', 'workshop' ); ?></label></th>
                        <td><input type="text" name="about[subtitle]" class="regular-text" style="width:100%" value="<?php echo esc_attr( $pages['about']['subtitle'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Contenido', 'workshop' ); ?></label></th>
                        <td><textarea name="about[content]" class="large-text" rows="8"><?php echo esc_textarea( $pages['about']['content'] ); ?></textarea></td>
                    </tr>
                </table>
            </div>

            <div class="ws-mp-admin-group">
                <h2><span class="dashicons dashicons-shield" style="margin-right:6px"></span><?php esc_html_e( 'Garantías de confianza', 'workshop' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Tarjetas que aparecen al final de las páginas para transmitir seguridad (icono de Font Awesome, título y texto).', 'workshop' ); ?></p>
                <div id="ws-sp-trust">
                    <?php foreach ( (array) $pages['trust'] as $ti => $tr ) : ?>
                        <div class="ws-sp-link-row">
                            <input type="text" name="trust_icon[]" class="regular-text" placeholder="<?php esc_attr_e( 'Icono (ej. fa-shield-halved)', 'workshop' ); ?>" value="<?php echo esc_attr( $tr['icon'] ); ?>" style="width:170px">
                            <input type="text" name="trust_title[]" class="regular-text" placeholder="<?php esc_attr_e( 'Título', 'workshop' ); ?>" value="<?php echo esc_attr( $tr['title'] ); ?>">
                            <input type="text" name="trust_text[]" class="regular-text" placeholder="<?php esc_attr_e( 'Texto', 'workshop' ); ?>" value="<?php echo esc_attr( $tr['text'] ); ?>">
                            <button type="button" class="button button-link-delete" onclick="this.closest('.ws-sp-link-row').remove()"><?php esc_html_e( '✕', 'workshop' ); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" onclick="ws_sp_add_trust()"><?php esc_html_e( 'Añadir garantía', 'workshop' ); ?></button></p>
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
        .ws-sp-faq-topic { border: 1px solid #c3c4c7; padding: 12px 14px; margin: 12px 0; background: #f8f9fa; border-radius: 6px; }
        .ws-sp-faq-topic-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .ws-sp-faq-topic-head strong { font-size: 13px; text-transform: uppercase; letter-spacing: .4px; color: #50575e; }
        .ws-sp-faq-item { border: 1px solid #dcdcde; border-left: 4px solid #c5d9ed; padding: 10px 12px; margin: 8px 0; background: #fff; border-radius: 4px; }
        .ws-sp-link-row { display: flex; gap: 6px; margin: 6px 0; flex-wrap: wrap; }
        .ws-sp-link-row .regular-text:first-child { width: 180px; }
        .ws-sp-link-row .regular-text:nth-child(2) { flex: 1; }
        .ws-sp-link-row .regular-text:nth-child(3) { flex: 2; }
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
        function ws_sp_add_trust() {
            var box = document.getElementById('ws-sp-trust');
            if (!box) return;
            var row = document.createElement('div');
            row.className = 'ws-sp-link-row';
            row.innerHTML = '<input type="text" name="trust_icon[]" class="regular-text" placeholder="<?php echo esc_js( __( 'Icono (ej. fa-shield-halved)', 'workshop' ) ); ?>" value="">'
                + '<input type="text" name="trust_title[]" class="regular-text" placeholder="<?php echo esc_js( __( 'Título', 'workshop' ) ); ?>" value="">'
                + '<input type="text" name="trust_text[]" class="regular-text" placeholder="<?php echo esc_js( __( 'Texto', 'workshop' ) ); ?>" value="">'
                + '<button type="button" class="button button-link-delete" onclick="this.closest(\'.ws-sp-link-row\').remove()"><?php echo esc_js( __( '✕', 'workshop' ) ); ?></button>';
            box.appendChild(row);
        }
        function ws_sp_add_faq_topic() {
            var box = document.getElementById('ws-sp-faqs');
            if (!box) return;
            var d = document.createElement('div');
            d.className = 'ws-sp-faq-topic';
            d.innerHTML =
                '<div class="ws-sp-faq-topic-head"><strong><?php echo esc_js( __( 'Tema', 'workshop' ) ); ?></strong>'
                + '<button type="button" class="button button-link-delete" onclick="this.closest(\'.ws-sp-faq-topic\').remove();ws_sp_faq_sync();"><?php echo esc_js( __( 'Eliminar tema', 'workshop' ) ); ?></button></div>'
                + '<label class="ws-sp-field"><span><?php echo esc_js( __( 'Nombre del tema', 'workshop' ) ); ?></span>'
                + '<input type="text" name="faq_topic[]" class="regular-text" placeholder="<?php echo esc_js( __( 'Ej. Pedidos y compras', 'workshop' ) ); ?>"></label>'
                + '<div class="ws-sp-faq-items" data-holder></div>'
                + '<p><button type="button" class="button" onclick="ws_sp_add_faq_item(this)"><?php echo esc_js( __( 'Añadir pregunta', 'workshop' ) ); ?></button></p>';
            box.appendChild(d);
            ws_sp_faq_sync();
        }
        function ws_sp_add_faq_item(btn) {
            var holder = btn.closest('.ws-sp-faq-topic').querySelector('[data-holder]');
            if (!holder) return;
            var i = ws_sp_faq_index(btn);
            var item = document.createElement('div');
            item.className = 'ws-sp-faq-item';
            item.innerHTML =
                '<label class="ws-sp-field"><span><?php echo esc_js( __( 'Pregunta', 'workshop' ) ); ?></span>'
                + '<input type="text" name="faq_q[' + i + '][]" class="regular-text" placeholder="<?php echo esc_js( __( 'Ej. ¿Cómo hago un pedido?', 'workshop' ) ); ?>"></label>'
                + '<label class="ws-sp-field"><span><?php echo esc_js( __( 'Respuesta', 'workshop' ) ); ?></span>'
                + '<textarea name="faq_a[' + i + '][]" class="large-text" rows="3" placeholder="<?php echo esc_js( __( 'Escribe la respuesta…', 'workshop' ) ); ?>"></textarea></label>'
                + '<p><button type="button" class="button button-link-delete" onclick="this.closest(\'.ws-sp-faq-item\').remove()"><?php echo esc_js( __( 'Quitar pregunta', 'workshop' ) ); ?></button></p>';
            holder.appendChild(item);
        }
        function ws_sp_faq_index(btn) {
            var topics = document.querySelectorAll('#ws-sp-faqs .ws-sp-faq-topic');
            var el = btn ? btn.closest('.ws-sp-faq-topic') : null;
            for (var k = 0; k < topics.length; k++) {
                if (el && topics[k] === el) return k;
            }
            return topics.length;
        }
        function ws_sp_faq_sync() {
            var topics = document.querySelectorAll('#ws-sp-faqs .ws-sp-faq-topic');
            topics.forEach(function (t, i) {
                t.querySelectorAll('input[name^="faq_topic"]').forEach(function (inp) { inp.name = 'faq_topic[' + i + ']'; });
                t.querySelectorAll('input[name^="faq_q"]').forEach(function (inp) { inp.name = 'faq_q[' + i + '][]'; });
                t.querySelectorAll('textarea[name^="faq_a"]').forEach(function (inp) { inp.name = 'faq_a[' + i + '][]'; });
            });
        }
        ws_sp_faq_sync();
    </script>
    <?php
}