<?php
/**
 * Panel por rol.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$role = ws_user_role();
// El administrador del sistema (esamper) no tiene rol de negocio: usa el rol
// del panel que está viendo para construir las URLs del menú.
if ( ! $role ) {
    $role = get_query_var( 'ws_role' ) ? (string) get_query_var( 'ws_role' ) : 'owner';
}
$page = ws_current_page();

$items = array(
    'dashboard' => array( 'icon' => 'fa-gauge-high',  'label' => __( 'Dashboard', 'workshop' ), 'caps' => array() ),
    'products'  => array( 'icon' => 'fa-boxes-stacked', 'label' => __( 'Productos', 'workshop' ), 'caps' => array( 'products_view' ) ),
    'locations' => array( 'icon' => 'fa-location-dot', 'label' => __( 'Ubicaciones', 'workshop' ), 'caps' => array( 'locations_view' ) ),
    'stock'     => array( 'icon' => 'fa-warehouse',    'label' => __( 'Stock', 'workshop' ), 'caps' => array( 'stock_view' ) ),
    'movements' => array( 'icon' => 'fa-clock-rotate-left', 'label' => __( 'Historial', 'workshop' ), 'caps' => array( 'movements_view' ) ),
    'orders'    => array( 'icon' => 'fa-receipt',      'label' => __( 'Pedidos', 'workshop' ), 'caps' => array( 'orders_view' ) ),
    'shifts'    => array( 'icon' => 'fa-calendar-days','label' => __( 'Turnos', 'workshop' ), 'caps' => array( 'shifts_view' ) ),
    'workers'   => array( 'icon' => 'fa-user-gear',    'label' => __( 'Trabajadores', 'workshop' ), 'caps' => array( 'workers_manage' ) ),
    'customers' => array( 'icon' => 'fa-users',        'label' => __( 'Clientes', 'workshop' ), 'caps' => array( 'customers_view' ) ),
    'pos'       => array( 'icon' => 'fa-cash-register', 'label' => __( 'POS', 'workshop' ), 'caps' => array( 'pos_sell' ) ),
    'pos-sales' => array( 'icon' => 'fa-chart-line',   'label' => __( 'Ventas POS', 'workshop' ), 'caps' => array( 'pos_view' ) ),
    'reviews'   => array( 'icon' => 'fa-star',         'label' => __( 'Valoraciones', 'workshop' ), 'caps' => array( 'reviews_view' ) ),
    'loyalty'   => array( 'icon' => 'fa-gift',         'label' => __( 'Fidelización', 'workshop' ), 'caps' => array( 'loyalty_manage' ) ),
    'expenses'  => array( 'icon' => 'fa-money-bill-wave', 'label' => __( 'Gastos', 'workshop' ), 'caps' => array( 'expenses_manage' ) ),
    'anuncios'  => array( 'icon' => 'fa-bullhorn',     'label' => __( 'Anuncios', 'workshop' ), 'caps' => array( 'settings_manage', 'workers_manage' ) ),
    'plan'      => array( 'icon' => 'fa-crown',        'label' => __( 'Plan', 'workshop' ), 'caps' => array() ),
    'permissions' => array( 'icon' => 'fa-shield-halved','label' => __( 'Permisos', 'workshop' ), 'caps' => array( 'permissions_manage' ) ),
    'reports'   => array( 'icon' => 'fa-chart-pie',    'label' => __( 'Reportes', 'workshop' ), 'caps' => array( 'reports_view' ) ),
    'appearance' => array( 'icon' => 'fa-palette',     'label' => __( 'Apariencia', 'workshop' ), 'caps' => array( 'site_manage', 'layout_manage' ) ),
    'settings'  => array( 'icon' => 'fa-gear',         'label' => __( 'Configuración', 'workshop' ), 'caps' => array( 'settings_manage' ) ),
    'account'   => array( 'icon' => 'fa-user',         'label' => __( 'Mi cuenta', 'workshop' ), 'caps' => array() ),
);

// Tutorial de onboarding: solo las secciones que el usuario puede ver
// (mismo filtro de capacidades que el menú lateral).
$ws_tutorial_sections = array();
if ( function_exists( 'ws_tutorial_data' ) ) {
    $ws_tutorial_all = ws_tutorial_data();
    foreach ( $items as $key => $item ) {
        if ( empty( $ws_tutorial_all[ $key ] ) ) {
            continue;
        }
        $visible = true;
        if ( ! empty( $item['caps'] ) ) {
            $visible = false;
            foreach ( $item['caps'] as $cap ) {
                if ( ws_can( $cap ) ) {
                    $visible = true;
                    break;
                }
            }
        }
        if ( ! $visible ) {
            continue;
        }
        $ws_tutorial_sections[] = array(
            'key'   => $key,
            'label' => $item['label'],
            'icon'  => $ws_tutorial_all[ $key ]['icon'],
            'desc'  => $ws_tutorial_all[ $key ]['desc'],
            // Los pasos se serializan como objetos {title,text} para Alpine
            // (la fuente usa arrays numéricos [título, texto]).
            'steps' => array_map( static function ( $st ) {
                return array(
                    'title' => (string) ( $st[0] ?? '' ),
                    'text'  => (string) ( $st[1] ?? '' ),
                );
            }, $ws_tutorial_all[ $key ]['steps'] ),
            'tour'  => isset( $ws_tutorial_all[ $key ]['tour'] ) ? array_values( array_filter(
                $ws_tutorial_all[ $key ]['tour'],
                static function ( $st ) {
                    // Pasos opcionales que exigen una capacidad (p. ej. fraccionamiento).
                    if ( ! empty( $st['cap'] ) && ! ws_can( $st['cap'] ) ) {
                        return false;
                    }
                    return true;
                }
            ) ) : array(),
            'url'   => ws_panel_url( $role, $key ),
        );
    }
}
// Primera visita tras registrarse: se abre la bienvenida con felicitación.
$ws_tutorial_auto = (bool) get_user_meta( get_current_user_id(), 'ws_tutorial_pending', true );
if ( $ws_tutorial_auto ) {
    delete_user_meta( get_current_user_id(), 'ws_tutorial_pending' );
}

get_header();
?>
<div class="ws-panel" x-data="wsTutorial(<?php echo esc_attr( wp_json_encode( array(
    'sections' => $ws_tutorial_sections,
    'auto'     => $ws_tutorial_auto,
    'current'  => $page,
) ) ); ?>)">
    <aside class="ws-sidebar">
        <a class="ws-sidebar-brand" href="<?php echo esc_url( ws_business_home() ); ?>">
            <?php $ws_logo = ws_site_logo(); ?>
            <img class="ws-brand-img" src="<?php echo ws_site_logo_src(); ?>" alt="<?php echo esc_attr( ws_site_name() ); ?>" style="<?php echo $ws_logo ? '' : 'display:none'; ?>">
            <i class="fa-solid fa-store ws-brand-icon" style="<?php echo $ws_logo ? 'display:none' : ''; ?>"></i>
            <span class="ws-brand-name"><?php echo esc_html( ws_site_name() ); ?></span>
        </a>
        <button class="ws-sidebar-close" onclick="document.body.classList.remove('ws-sidebar-open')"><i class="fa-solid fa-xmark"></i></button>
        <?php
        // Píldora del plan del negocio (solo usuarios con rol de negocio).
        $ws_plan_pill = null;
        if ( ws_user_role() ) {
            $ws_pd = ws_subscription_data();
            $ws_plan_pill = array(
                'name' => ! empty( $ws_pd['plan'] ) ? $ws_pd['plan']->name : __( 'Plan', 'workshop' ),
                'label' => $ws_pd['is_trial']
                    ? sprintf( _n( 'Prueba · %d día', 'Prueba · %d días', $ws_pd['trial_days_left'], 'workshop' ), $ws_pd['trial_days_left'] )
                    : $ws_pd['status_label'],
                'locked' => $ws_pd['locked'],
            );
        }
        ?>
        <?php if ( $ws_plan_pill ) : ?>
            <a class="ws-sidebar-plan<?php echo ! empty( $ws_plan_pill['locked'] ) ? ' is-locked' : ''; ?>" href="<?php echo esc_url( ws_panel_url( $role, 'plan' ) ); ?>">
                <i class="fa-solid <?php echo ! empty( $ws_plan_pill['locked'] ) ? 'fa-lock' : 'fa-crown'; ?>"></i>
                <span class="ws-sidebar-plan-body">
                    <strong><?php echo esc_html( $ws_plan_pill['name'] ); ?></strong>
                    <small><?php echo esc_html( $ws_plan_pill['label'] ); ?></small>
                </span>
                <i class="fa-solid fa-chevron-right"></i>
            </a>
        <?php endif; ?>
        <nav class="ws-nav">
            <div class="ws-sidebar-label"><?php esc_html_e( 'Principal', 'workshop' ); ?></div>
            <?php foreach ( $items as $key => $item ) : ?>
                <?php
                $visible = true;
                if ( ! empty( $item['caps'] ) ) {
                    $visible = false;
                    foreach ( $item['caps'] as $cap ) {
                        if ( ws_can( $cap ) ) {
                            $visible = true;
                            break;
                        }
                    }
                }
                if ( ! $visible ) {
                    continue;
                }
                $active = ( $page === $key ) ? ' is-active' : '';
                ?>
                <a class="ws-nav-link<?php echo esc_attr( $active ); ?>" href="<?php echo esc_url( ws_panel_url( $role, $key ) ); ?>">
                    <i class="fa-solid <?php echo esc_attr( $item['icon'] ); ?>"></i>
                    <span><?php echo esc_html( $item['label'] ); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="ws-panel-main">
        <header class="ws-panel-topbar">
            <button class="ws-mobile-menu" onclick="document.body.classList.toggle('ws-sidebar-open')"><i class="fa-solid fa-bars"></i></button>
            <h1><?php echo esc_html( $items[ $page ]['label'] ?? '' ); ?></h1>
            <div class="ws-topbar-right">
                <?php get_template_part( 'partials/notifications-menu' ); ?>
                <?php get_template_part( 'partials/user-menu' ); ?>
            </div>
        </header>

        <div class="ws-panel-content">
            <?php
            // Banner de prueba gratis / plan por vencer (no en la página Plan).
            $ws_plan_banner = null;
            if ( 'plan' !== $page && ws_user_role() ) {
                $ws_pd = ws_subscription_data();
                if ( $ws_pd['locked'] ) {
                    $ws_plan_banner = array(
                        'kind'  => 'danger',
                        'title' => $ws_pd['lock']['title'],
                        'text'  => $ws_pd['lock']['message'],
                    );
                } elseif ( $ws_pd['is_trial'] && $ws_pd['trial_days_left'] <= 7 ) {
                    $ws_plan_banner = array(
                        'kind'  => 'info',
                        'title' => sprintf( _n( 'Te queda %d día de prueba gratis', 'Te quedan %d días de prueba gratis', $ws_pd['trial_days_left'], 'workshop' ), $ws_pd['trial_days_left'] ),
                        'text'  => __( 'Elige un plan para que tu negocio siga activo cuando termine la prueba.', 'workshop' ),
                    );
                } elseif ( $ws_pd['is_active'] && $ws_pd['plan_days_left'] > 0 && $ws_pd['plan_days_left'] <= 7 ) {
                    $ws_plan_banner = array(
                        'kind'  => 'warn',
                        'title' => sprintf( _n( 'Tu plan vence en %d día', 'Tu plan vence en %d días', $ws_pd['plan_days_left'], 'workshop' ), $ws_pd['plan_days_left'] ),
                        'text'  => __( 'Renueva o solicita otro plan para no interrumpir tu negocio.', 'workshop' ),
                    );
                }
            }
            if ( $ws_plan_banner ) : ?>
                <div class="ws-banner ws-banner-<?php echo esc_attr( $ws_plan_banner['kind'] ); ?>">
                    <i class="fa-solid <?php echo 'danger' === $ws_plan_banner['kind'] ? 'fa-lock' : 'fa-clock'; ?>"></i>
                    <div>
                        <strong><?php echo esc_html( $ws_plan_banner['title'] ); ?></strong>
                        <span><?php echo esc_html( $ws_plan_banner['text'] ); ?></span>
                    </div>
                    <a class="ws-btn ws-btn-sm <?php echo 'danger' === $ws_plan_banner['kind'] ? 'ws-btn-primary' : 'ws-btn-secondary'; ?>" href="<?php echo esc_url( ws_panel_url( $role, 'plan' ) ); ?>">
                        <?php esc_html_e( 'Ver plan', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            <?php endif; ?>
            <?php
            // Anuncios anclados del negocio: notificaciones destacadas que el
            // dueño fija desde ShopUp → Anuncios. Se ven en todo el panel.
            if ( function_exists( 'ws_announcements_pinned' ) ) :
                foreach ( ws_announcements_pinned() as $ws_ann ) :
                    $ws_ann_kind = 'warning' === $ws_ann->type ? 'warn' : $ws_ann->type;
                    if ( ! in_array( $ws_ann_kind, array( 'danger', 'info', 'warn' ), true ) ) {
                        $ws_ann_kind = 'info';
                    }
                    ?>
                    <div class="ws-banner ws-banner-<?php echo esc_attr( $ws_ann_kind ); ?> ws-ann-banner" data-ann="<?php echo (int) $ws_ann->id; ?>">
                        <i class="fa-solid fa-bullhorn"></i>
                        <div>
                            <strong><?php echo esc_html( $ws_ann->title ); ?></strong>
                            <span><?php echo esc_html( $ws_ann->message ); ?></span>
                        </div>
                        <?php if ( function_exists( 'ws_announcement_can_close' ) && ws_announcement_can_close( $ws_ann ) ) : ?>
                            <button type="button" class="ws-banner-close" onclick="wsDismissAnnouncement(<?php echo (int) $ws_ann->id; ?>, this)" aria-label="<?php esc_attr_e( 'Ocultar anuncio', 'workshop' ); ?>"><i class="fa-solid fa-xmark"></i></button>
                        <?php endif; ?>
                    </div>
                    <?php
                endforeach;
            endif;
            ?>
            <?php
            $file = WS_PATH . 'templates/panel/' . $page . '.php';
            if ( file_exists( $file ) ) {
                include $file;
            } else {
                echo '<p class="ws-empty">' . esc_html__( 'Módulo en construcción.', 'workshop' ) . '</p>';
            }
            ?>
        </div>
    </div>

    <!-- ============ TUTORIAL / ONBOARDING ============ -->
    <div class="ws-modal ws-tutorial-modal" x-show="open" x-cloak x-transition.opacity>
        <div class="ws-modal-backdrop" @click="close()"></div>
        <div class="ws-modal-box ws-tutorial-box" x-transition.scale.90>
            <div class="ws-modal-head">
                <h3><i class="fa-solid fa-circle-question"></i> <?php esc_html_e( 'Guía de tu panel', 'workshop' ); ?></h3>
                <button class="ws-cart-close" type="button" @click="close()" aria-label="<?php esc_attr_e( 'Cerrar', 'workshop' ); ?>"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Bienvenida con felicitación (solo primera vez). -->
            <div x-show="view === 'welcome'" x-cloak>
                <div class="ws-tutorial-welcome">
                    <div class="ws-tutorial-party" aria-hidden="true">
                        <span></span><span></span><span></span><span></span><span></span><span></span>
                        <div class="ws-tutorial-trophy"><i class="fa-solid fa-trophy"></i></div>
                    </div>
                    <h2><?php esc_html_e( '¡Felicidades, tu negocio está listo!', 'workshop' ); ?></h2>
                    <p class="ws-tutorial-welcome-sub"><?php esc_html_e( 'Esta es tu mejor opción para gestionarlo: todo en un solo lugar.', 'workshop' ); ?></p>
                    <p class="ws-muted"><?php esc_html_e( 'Es muy fácil. Te mostramos qué puedes hacer y cómo, paso a paso. Si alguna vez te pierdes, abre la guía desde el menú lateral.', 'workshop' ); ?></p>
                    <button class="ws-btn ws-btn-primary ws-btn-lg ws-btn-block" type="button" @click="view = 'list'">
                        <?php esc_html_e( 'Ver qué puedo hacer', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- Lista de secciones disponibles. -->
            <div x-show="view === 'list'" x-cloak>
                <p class="ws-tutorial-intro"><?php esc_html_e( 'Toca una sección para ver cómo usarla paso a paso. Solo te mostramos las que tienes disponibles.', 'workshop' ); ?></p>
                <div class="ws-tutorial-grid">
                    <template x-for="s in sections" :key="s.key">
                        <button type="button" class="ws-tutorial-item" :class="s.key === activePage ? 'is-current' : ''" @click="showSection(s.key)">
                            <span class="ws-tutorial-item-ico"><i class="fa-solid" :class="s.icon"></i></span>
                            <span class="ws-tutorial-item-body">
                                <strong x-text="s.label"></strong>
                                <span class="ws-tutorial-here" x-show="s.key === activePage"><i class="fa-solid fa-location-arrow"></i> <?php esc_html_e( 'Estás aquí', 'workshop' ); ?></span>
                                <small x-text="s.desc"></small>
                            </span>
                            <i class="fa-solid fa-chevron-right ws-tutorial-item-arrow"></i>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Pasos de la sección elegida (x-if: no evalúa current.* si es nulo). -->
            <template x-if="view === 'steps' && current">
                <div class="ws-tutorial-steps">
                    <button type="button" class="ws-link-btn" @click="view = 'list'"><i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Volver a las secciones', 'workshop' ); ?></button>
                    <h2 class="ws-tutorial-step-title"><i class="fa-solid" :class="current.icon"></i> <span x-text="current.label"></span></h2>
                    <p class="ws-muted" x-text="current.desc"></p>
                    <ol class="ws-tutorial-steps-list">
                        <template x-for="(st, i) in current.steps" :key="i">
                            <li>
                                <span class="ws-tutorial-step-num" x-text="i + 1"></span>
                                <div>
                                    <strong x-text="st.title"></strong>
                                    <small x-text="st.text"></small>
                                </div>
                            </li>
                        </template>
                    </ol>
                    <button type="button" class="ws-btn ws-btn-secondary ws-btn-block ws-tour-start" @click="startTour()" x-show="current.tour && canTour() && currentKey === activePage">
                        <i class="fa-solid fa-location-arrow"></i> <?php esc_html_e( 'Recorrido guiado en esta sección', 'workshop' ); ?>
                    </button>
                    <p class="ws-muted ws-tour-hint" x-show="currentKey === activePage"><?php esc_html_e( 'Una flecha te señalará cada elemento, qué hace y consejos para trabajar mejor.', 'workshop' ); ?></p>
                    <a class="ws-btn ws-btn-primary ws-btn-block" :href="current.url" @click="close()">
                        <?php esc_html_e( 'Ir a esta sección', 'workshop' ); ?> <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </template>
        </div>
    </div>
    <!-- La ayuda del panel ahora la da el asistente (chatbot): botón flotante
         propio con recorridos guiados, atajos por rol y preguntas frecuentes. -->

    <!-- ============ TOUR GUIADO: spotlight sobre la sección actual ============ -->
    <div class="ws-tour" x-show="tourActive" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Recorrido guiado', 'workshop' ); ?>">
        <div class="ws-tour-spot" :style="spotStyle"></div>
        <div class="ws-tour-arrow" :class="'ws-tour-arrow-' + arrowSide" :style="arrowStyle" aria-hidden="true"></div>
        <div class="ws-tour-pop" :class="'ws-tour-pop-' + arrowSide" :style="popStyle">
            <div class="ws-tour-progress"><i class="fa-solid fa-location-arrow"></i> <span x-text="(tourIndex + 1) + ' / ' + tourVisible.length"></span></div>
            <h4 x-text="currentStep.title"></h4>
            <p class="ws-tour-text" x-text="currentStep.text"></p>
            <p class="ws-tour-note" x-show="!currentStep.hasEl && !currentStep.textual"><i class="fa-solid fa-circle-info"></i> <?php esc_html_e( 'Aún no aparece este elemento en tu pantalla (sin datos registrados o sin permisos). Así funciona: lo verás en cuanto empieces a usar esta sección.', 'workshop' ); ?></p>
            <p class="ws-tour-tip" x-show="currentStep.tip"><i class="fa-solid fa-lightbulb"></i> <span x-text="currentStep.tip"></span></p>
            <div class="ws-tour-nav">
                <button type="button" class="ws-btn ws-btn-ghost ws-btn-sm" @click="stopTour()"><?php esc_html_e( 'Salir', 'workshop' ); ?></button>
                <span class="ws-tour-spacer"></span>
                <button type="button" class="ws-btn ws-btn-secondary ws-btn-sm" @click="tourPrev()" x-show="tourIndex > 0"><i class="fa-solid fa-chevron-left"></i> <?php esc_html_e( 'Atrás', 'workshop' ); ?></button>
                <button type="button" class="ws-btn ws-btn-primary ws-btn-sm" @click="tourNext()">
                    <template x-if="tourIndex < tourVisible.length - 1"><?php esc_html_e( 'Siguiente', 'workshop' ); ?> <i class="fa-solid fa-chevron-right"></i></template>
                    <template x-if="tourIndex >= tourVisible.length - 1"><i class="fa-solid fa-check"></i> <?php esc_html_e( 'Entendido', 'workshop' ); ?></template>
                </button>
            </div>
        </div>
    </div>
</div>
<?php get_footer(); ?>
