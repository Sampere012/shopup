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

$ws_icons   = array( 'help' => 'fa-circle-question', 'contact' => 'fa-envelope', 'about' => 'fa-users' );
$ws_icon    = $ws_icons[ $ws_key ];
$ws_badge   = '' !== trim( (string) ( $ws_cur['badge'] ?? '' ) ) ? $ws_cur['badge'] : $ws_cur['title'];
$ws_sub     = (string) ( $ws_cur['subtitle'] ?? '' );
$ws_content = (string) ( $ws_cur['content'] ?? '' );

// Vías de contacto disponibles para la página de contacto y el panel lateral.
$ws_email    = trim( (string) ( $ws_cur['email'] ?? '' ) );
$ws_phone    = trim( (string) ( $ws_cur['phone'] ?? '' ) );
$ws_whatsapp = trim( (string) ( $ws_cur['whatsapp'] ?? '' ) );
$ws_address  = trim( (string) ( $ws_cur['address'] ?? '' ) );
$ws_hours    = trim( (string) ( $ws_cur['hours'] ?? '' ) );

// WhatsApp del administrador de WordPress (perfil del admin o Ajustes):
// si está configurado, los visitantes pueden enviar consultas por WhatsApp.
// Solo se consulta en la página de contacto para no hacer queries extra.
$ws_admin_wa = ( 'contact' === $ws_key ) ? ws_admin_whatsapp_number() : '';

get_header();
?>
<div class="ws-landing ws-static-page">
    <section class="ws-static-hero<?php echo ws_site_hero_has_bg() ? ' ws-has-bg' : ''; ?>" style="<?php echo esc_attr( ws_site_hero_bg_style() ); ?>">
        <div class="ws-container">
            <nav class="ws-breadcrumbs" aria-label="<?php esc_attr_e( 'Migas de pan', 'workshop' ); ?>">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><i class="fa-solid fa-house"></i> <?php esc_html_e( 'Inicio', 'workshop' ); ?></a>
                <span class="ws-breadcrumb-sep"><i class="fa-solid fa-chevron-right"></i></span>
                <span aria-current="page"><?php echo esc_html( $ws_cur['title'] ); ?></span>
            </nav>
            <span class="ws-hero-badge"><i class="fa-solid <?php echo esc_attr( $ws_icon ); ?>"></i> <?php echo esc_html( $ws_badge ); ?></span>
            <h1><?php echo esc_html( $ws_cur['title'] ); ?></h1>
            <?php if ( '' !== $ws_sub ) : ?>
                <p class="ws-static-hero-sub"><?php echo esc_html( $ws_sub ); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <main class="ws-container ws-static-layout">
        <div class="ws-static-main">

            <?php if ( 'contact' === $ws_key ) : ?>
                <div class="ws-static-card">
                    <?php if ( '' !== trim( $ws_content ) ) : ?>
                        <div class="ws-static-content">
                            <?php echo wp_kses_post( $ws_content ); ?>
                        </div>
                    <?php else : ?>
                        <div class="ws-static-content">
                            <p><?php esc_html_e( '¿Tienes dudas o quieres ponerte en contacto con nosotros? Usa cualquiera de estas vías y te responderemos lo antes posible.', 'workshop' ); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ( $ws_email || $ws_phone || $ws_whatsapp || $ws_address || $ws_hours ) : ?>
                        <div class="ws-contact-grid">
                            <?php if ( $ws_email ) : ?>
                                <div class="ws-contact-card">
                                    <span class="ws-contact-ico"><i class="fa-solid fa-envelope"></i></span>
                                    <h3><?php esc_html_e( 'Correo', 'workshop' ); ?></h3>
                                    <a href="mailto:<?php echo esc_attr( $ws_email ); ?>"><?php echo esc_html( $ws_email ); ?></a>
                                    <span class="ws-contact-hint"><?php esc_html_e( 'Te respondemos en menos de 24 h', 'workshop' ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $ws_phone ) : ?>
                                <div class="ws-contact-card">
                                    <span class="ws-contact-ico"><i class="fa-solid fa-phone"></i></span>
                                    <h3><?php esc_html_e( 'Teléfono', 'workshop' ); ?></h3>
                                    <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $ws_phone ) ); ?>"><?php echo esc_html( $ws_phone ); ?></a>
                                    <span class="ws-contact-hint"><?php esc_html_e( 'Horario comercial', 'workshop' ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $ws_whatsapp ) : ?>
                                <div class="ws-contact-card">
                                    <span class="ws-contact-ico ws-contact-ico-wa"><i class="fa-brands fa-whatsapp"></i></span>
                                    <h3><?php esc_html_e( 'WhatsApp', 'workshop' ); ?></h3>
                                    <a href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', $ws_whatsapp ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ws_whatsapp ); ?></a>
                                    <span class="ws-contact-hint"><?php esc_html_e( 'Escrúbenos y te atendemos', 'workshop' ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $ws_address ) : ?>
                                <div class="ws-contact-card">
                                    <span class="ws-contact-ico"><i class="fa-solid fa-location-dot"></i></span>
                                    <h3><?php esc_html_e( 'Dirección', 'workshop' ); ?></h3>
                                    <span class="ws-contact-value"><?php echo esc_html( $ws_address ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $ws_hours ) : ?>
                                <div class="ws-contact-card">
                                    <span class="ws-contact-ico"><i class="fa-solid fa-clock"></i></span>
                                    <h3><?php esc_html_e( 'Horario', 'workshop' ); ?></h3>
                                    <span class="ws-contact-value"><?php echo esc_html( $ws_hours ); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $ws_admin_wa ) : ?>
                        <div class="ws-contact-form-block">
                            <div class="ws-contact-form-head">
                                <span class="ws-contact-form-ico"><i class="fa-brands fa-whatsapp"></i></span>
                                <div>
                                    <h3><?php esc_html_e( 'Envíanos tu consulta por WhatsApp', 'workshop' ); ?></h3>
                                    <p><?php esc_html_e( 'Rellena el formulario y tu mensaje llegará directo a nuestro equipo de soporte.', 'workshop' ); ?></p>
                                </div>
                            </div>
                            <form class="ws-contact-form" id="ws-contact-form" novalidate>
                                <div class="ws-contact-form-row">
                                    <label class="ws-field">
                                        <span><?php esc_html_e( 'Tu nombre', 'workshop' ); ?></span>
                                        <input type="text" name="cf_name" required placeholder="<?php esc_attr_e( 'Nombre y apellido', 'workshop' ); ?>">
                                    </label>
                                    <label class="ws-field">
                                        <span><?php esc_html_e( 'Tu teléfono', 'workshop' ); ?></span>
                                        <input type="tel" name="cf_phone" required placeholder="+58 412 123 4567">
                                    </label>
                                </div>
                                <label class="ws-field">
                                    <span><?php esc_html_e( 'Asunto', 'workshop' ); ?></span>
                                    <input type="text" name="cf_subject" placeholder="<?php esc_attr_e( '¿Sobre qué nos escribes?', 'workshop' ); ?>">
                                </label>
                                <label class="ws-field">
                                    <span><?php esc_html_e( 'Tu consulta', 'workshop' ); ?></span>
                                    <textarea name="cf_message" rows="4" required placeholder="<?php esc_attr_e( 'Cuéntanos en qué podemos ayudarte…', 'workshop' ); ?>"></textarea>
                                </label>
                                <button class="ws-btn ws-btn-wa ws-btn-block" type="submit">
                                    <i class="fa-brands fa-whatsapp"></i>
                                    <span><?php esc_html_e( 'Enviar consulta por WhatsApp', 'workshop' ); ?></span>
                                </button>
                                <p class="ws-contact-form-note"><i class="fa-solid fa-shield-halved"></i> <?php esc_html_e( 'Se abrirá WhatsApp con tu mensaje listo para enviar. No compartimos tus datos.', 'workshop' ); ?></p>
                            </form>
                        </div>
                    <?php else : ?>
                        <div class="ws-contact-form-block ws-contact-form-block-empty">
                            <p><i class="fa-solid fa-circle-info"></i> <?php esc_html_e( 'También puedes escribirnos por correo o teléfono usando las vías de contacto de arriba.', 'workshop' ); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $ws_pages['socials'] ) ) : ?>
                        <div class="ws-static-socials">
                            <span class="ws-static-socials-label"><?php esc_html_e( 'También puedes seguirnos', 'workshop' ); ?></span>
                            <div class="ws-static-socials-list">
                                <?php foreach ( $ws_pages['socials'] as $ws_social ) : ?>
                                    <a class="ws-static-social" href="<?php echo esc_url( $ws_social['url'] ); ?>" target="_blank" rel="noopener">
                                        <i class="fa-brands fa-<?php echo esc_attr( sanitize_title( strtolower( $ws_social['label'] ) ) ); ?>"></i>
                                        <?php echo esc_html( $ws_social['label'] ); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="ws-static-card ws-static-card-content">
                    <?php if ( 'help' === $ws_key ) : ?>
                        <?php $ws_faqs_all = ws_site_faqs_all(); ?>
                        <?php if ( ! empty( $ws_faqs_all ) ) : ?>
                        <?php if ( '' !== trim( $ws_content ) ) : ?>
                            <div class="ws-static-content ws-faq-intro">
                                <?php echo wp_kses_post( $ws_content ); ?>
                            </div>
                        <?php endif; ?>
                        <div class="ws-faq-search">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input type="search" id="ws-faq-search" placeholder="<?php esc_attr_e( 'Buscar en las preguntas frecuentes…', 'workshop' ); ?>" aria-label="<?php esc_attr_e( 'Buscar en las preguntas frecuentes', 'workshop' ); ?>" autocomplete="off">
                        </div>
                        <div class="ws-faq" id="ws-faq">
                            <?php $ws_faq_open = 0; $ws_faq_gi = 0; ?>
                            <?php foreach ( (array) $ws_faqs_all as $ws_topic ) : ?>
                                <?php if ( empty( $ws_topic['topic'] ) || empty( $ws_topic['items'] ) ) : continue; endif; ?>
                                <div class="ws-faq-topic" data-topic>
                                    <h3 class="ws-faq-topic-title"><i class="fa-solid fa-circle-question"></i> <?php echo esc_html( $ws_topic['topic'] ); ?></h3>
                                    <?php $ws_faq_num = 0; ?>
                                    <?php foreach ( (array) $ws_topic['items'] as $ws_item ) : ?>
                                        <?php if ( empty( $ws_item['q'] ) ) : continue; endif; ?>
                                        <?php $ws_faq_num = (int) $ws_faq_num + 1; $ws_faq_gi = (int) $ws_faq_gi + 1; ?>
                                        <details class="ws-faq-item" data-pi="<?php echo (int) $ws_faq_gi; ?>"<?php echo 0 === (int) $ws_faq_open ? ' open' : ''; ?>>
                                            <summary>
                                                <span class="ws-faq-q"><span class="ws-faq-num"><?php echo (int) $ws_faq_num; ?></span><span class="ws-faq-q-text"><?php echo esc_html( $ws_item['q'] ); ?></span></span>
                                                <span class="ws-faq-chevron"><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></span>
                                            </summary>
                                            <div class="ws-faq-answer">
                                                <div class="ws-faq-answer-inner"><?php echo wp_kses_post( $ws_item['a'] ); ?></div>
                                            </div>
                                        </details>
                                        <?php $ws_faq_open = (int) $ws_faq_open + 1; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="ws-faq-empty" hidden><?php esc_html_e( 'No encontramos preguntas con ese término. Prueba con otra palabra o escríbenos desde Contacto.', 'workshop' ); ?></div>
                        </div>
                        <?php $ws_faq_total = (int) $ws_faq_gi; ?>
                        <div class="ws-faq-pagination" id="ws-faq-pagination" hidden>
                            <button type="button" class="ws-faq-page-btn" id="ws-faq-prev" aria-label="<?php esc_attr_e( 'Página anterior', 'workshop' ); ?>"><i class="fa-solid fa-chevron-left"></i></button>
                            <span class="ws-faq-page-info" id="ws-faq-page-info"></span>
                            <div class="ws-faq-page-numbers" id="ws-faq-page-numbers"></div>
                            <button type="button" class="ws-faq-page-btn" id="ws-faq-next" aria-label="<?php esc_attr_e( 'Página siguiente', 'workshop' ); ?>"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                        <script>
                        (function () {
                            var input = document.getElementById('ws-faq-search');
                            var faq = document.getElementById('ws-faq');
                            var pager = document.getElementById('ws-faq-pagination');
                            if (!input || !faq || !pager) return;
                            var topics = faq.querySelectorAll('[data-topic]');
                            var empty = faq.querySelector('.ws-faq-empty');
                            var total = <?php echo (int) $ws_faq_total; ?>;
                            var perPage = 30;
                            var page = 1;
                            var pages = Math.max(1, Math.ceil(total / perPage));
                            var info = document.getElementById('ws-faq-page-info');
                            var nums = document.getElementById('ws-faq-page-numbers');
                            var prev = document.getElementById('ws-faq-prev');
                            var next = document.getElementById('ws-faq-next');
                            var query = '';

                            function render() {
                                var any = false;
                                var q = query;
                                topics.forEach(function (t) {
                                    var shown = false;
                                    t.querySelectorAll('.ws-faq-item').forEach(function (item) {
                                        var pi = parseInt(item.getAttribute('data-pi') || '0', 10);
                                        var text = (item.textContent || '').toLowerCase();
                                        var match = !q || text.indexOf(q) !== -1;
                                        var onPage = q || pi > (page - 1) * perPage && pi <= page * perPage;
                                        item.hidden = !match || !onPage;
                                        if (!item.hidden) shown = true;
                                    });
                                    t.hidden = !shown;
                                    if (shown) any = true;
                                });
                                if (empty) empty.hidden = any;
                                var filtering = !!q;
                                pager.hidden = filtering || pages <= 1;
                                if (filtering) { return; }
                                var from = (page - 1) * perPage + 1;
                                var to = Math.min(page * perPage, total);
                                if (info) info.textContent = from + '–' + to + ' de ' + total;
                                if (nums) {
                                    nums.innerHTML = '';
                                    var start = Math.max(1, page - 2);
                                    var end = Math.min(pages, page + 2);
                                    for (var p = start; p <= end; p++) {
                                        var b = document.createElement('button');
                                        b.type = 'button';
                                        b.className = 'ws-faq-page-num' + (p === page ? ' is-active' : '');
                                        b.textContent = p;
                                        (function (pp) {
                                            b.addEventListener('click', function () { page = pp; render(); });
                                        })(p);
                                        nums.appendChild(b);
                                    }
                                }
                                if (prev) prev.disabled = page <= 1;
                                if (next) next.disabled = page >= pages;
                            }
                            if (prev) prev.addEventListener('click', function () { if (page > 1) { page--; render(); } });
                            if (next) next.addEventListener('click', function () { if (page < pages) { page++; render(); } });
                            input.addEventListener('input', function () {
                                query = (input.value || '').trim().toLowerCase();
                                faq.classList.toggle('is-filtering', !!query);
                                render();
                            });
                            render();
                        })();
                        </script>
                        <?php endif; /* ws_faqs_all */ ?>
                    <?php else : ?>
                        <div class="ws-static-content">
                            <?php if ( '' !== trim( $ws_content ) ) : ?>
                                <?php echo wp_kses_post( $ws_content ); ?>
                            <?php else : ?>
                                <p><?php esc_html_e( 'Descubre quiénes somos, qué hacemos y cómo este mercado conecta negocios y clientes.', 'workshop' ); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <aside class="ws-static-side">
            <?php if ( 'about' === $ws_key ) : ?>
                <div class="ws-static-side-card ws-static-side-cta">
                    <span class="ws-static-side-ico"><i class="fa-solid fa-store"></i></span>
                    <h3><?php esc_html_e( '¿Tienes un negocio?', 'workshop' ); ?></h3>
                    <p><?php esc_html_e( 'Crea tu cuenta gratis, personaliza tu tienda y empieza a vender hoy mismo.', 'workshop' ); ?></p>
                    <a class="ws-btn ws-btn-primary ws-btn-block" href="<?php echo esc_url( ws_register_url() ); ?>"><?php esc_html_e( 'Crear mi tienda', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            <?php else : ?>
                <div class="ws-static-side-card ws-static-side-contact">
                    <span class="ws-static-side-ico"><i class="fa-solid fa-headset"></i></span>
                    <?php if ( 'help' === $ws_key ) : ?>
                        <h3><?php esc_html_e( '¿No encuentras tu respuesta?', 'workshop' ); ?></h3>
                        <p><?php esc_html_e( 'Escríbenos y te ayudaremos a resolver cualquier duda.', 'workshop' ); ?></p>
                        <a class="ws-btn ws-btn-primary ws-btn-block" href="<?php echo esc_url( home_url( '/contacto/' ) ); ?>"><?php esc_html_e( 'Ir a Contacto', 'workshop' ); ?></a>
                    <?php else : ?>
                        <h3><?php esc_html_e( 'Preferimos escucharte', 'workshop' ); ?></h3>
                        <p><?php esc_html_e( 'Tus dudas, sugerencias y comentarios nos ayudan a mejorar cada día.', 'workshop' ); ?></p>
                        <?php if ( $ws_whatsapp ) : ?>
                            <a class="ws-btn ws-btn-wa ws-btn-block" href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', $ws_whatsapp ) ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> <?php esc_html_e( 'Escribir por WhatsApp', 'workshop' ); ?></a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="ws-static-side-card ws-static-side-trust">
                <h3><i class="fa-solid fa-shield-halved"></i> <?php esc_html_e( 'Compra con confianza', 'workshop' ); ?></h3>
                <ul>
                    <li><i class="fa-solid fa-circle-check"></i> <?php esc_html_e( 'Pedidos directos con la tienda', 'workshop' ); ?></li>
                    <li><i class="fa-solid fa-circle-check"></i> <?php esc_html_e( 'Confirmación y seguimiento por WhatsApp', 'workshop' ); ?></li>
                    <li><i class="fa-solid fa-circle-check"></i> <?php esc_html_e( 'Apoyas el comercio local', 'workshop' ); ?></li>
                </ul>
            </div>
        </aside>
    </main>

    <?php if ( ! empty( $ws_pages['trust'] ) ) : ?>
        <section class="ws-container">
            <div class="ws-trust-bar">
                <?php foreach ( $ws_pages['trust'] as $ws_tr ) : ?>
                    <div class="ws-trust-item">
                        <span class="ws-trust-ico"><i class="fa-solid <?php echo esc_attr( $ws_tr['icon'] ); ?>"></i></span>
                        <div>
                            <strong><?php echo esc_html( $ws_tr['title'] ); ?></strong>
                            <p><?php echo esc_html( $ws_tr['text'] ); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php if ( 'contact' === $ws_key && $ws_admin_wa ) : ?>
<script>
(function () {
    var form = document.getElementById('ws-contact-form');
    if (!form) return;
    var waNumber = '<?php echo esc_js( $ws_admin_wa ); ?>';
    var siteName = '<?php echo esc_js( get_bloginfo( 'name' ) ); ?>';
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = (form.cf_name.value || '').trim();
        var phone = (form.cf_phone.value || '').trim();
        var subject = (form.cf_subject.value || '').trim();
        var message = (form.cf_message.value || '').trim();

        if (!name || !phone || !message) {
            alert('<?php echo esc_js( __( 'Por favor completa tu nombre, teléfono y consulta.', 'workshop' ) ); ?>');
            return;
        }

        var lines = [];
        lines.push('👋 *Consulta desde ' + siteName + '*');
        lines.push('');
        lines.push('*Nombre:* ' + name);
        lines.push('*Teléfono:* ' + phone);
        if (subject) lines.push('*Asunto:* ' + subject);
        lines.push('');
        lines.push('*Consulta:*');
        lines.push(message);

        var url = 'https://wa.me/' + waNumber + '?text=' + encodeURIComponent(lines.join('\n'));
        window.open(url, '_blank', 'noopener');
    });
})();
</script>
<?php endif; ?>
<?php get_footer(); ?>
