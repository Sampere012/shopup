<?php
/**
 * Campana de notificaciones del navbar (usuarios logueados).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
    return;
}
?>
<div class="ws-notif" x-data="wsNotifications()">
    <button class="ws-notif-toggle" :class="glow ? 'is-glowing' : ''" type="button" @click="toggle()" :aria-expanded="open" aria-haspopup="true" aria-label="<?php esc_attr_e( 'Notificaciones', 'workshop' ); ?>">
        <i class="fa-solid fa-bell"></i>
        <span class="ws-notif-count" x-show="unread > 0" x-cloak x-text="unread > 99 ? '99+' : unread"></span>
    </button>
    <div class="ws-notif-dropdown" x-show="open" @click.away="open = false" x-cloak>
        <div class="ws-notif-head">
            <strong><i class="fa-solid fa-bell"></i> <?php esc_html_e( 'Notificaciones', 'workshop' ); ?></strong>
            <div class="ws-notif-head-actions">
                <button type="button" class="ws-notif-sound" @click="toggleSound()" :aria-pressed="soundOn" :title="soundOn ? '<?php echo esc_js( __( 'Sonido activado', 'workshop' ) ); ?>' : '<?php echo esc_js( __( 'Sonido desactivado', 'workshop' ) ); ?>'">
                    <i class="fa-solid" :class="soundOn ? 'fa-volume-high' : 'fa-volume-xmark'"></i>
                </button>
                <button type="button" class="ws-notif-clear" @click="markAllRead()" x-show="unread > 0"><?php esc_html_e( 'Marcar todas leídas', 'workshop' ); ?></button>
            </div>
        </div>
        <div class="ws-notif-list">
            <template x-if="loading">
                <p class="ws-empty ws-notif-empty"><?php esc_html_e( 'Cargando…', 'workshop' ); ?></p>
            </template>
            <template x-if="!loading && items.length === 0">
                <p class="ws-empty ws-notif-empty"><?php esc_html_e( 'Sin notificaciones.', 'workshop' ); ?></p>
            </template>
            <template x-for="n in items" :key="n.id">
                <div class="ws-notif-item" :class="n.is_read ? '' : 'is-unread'">
                    <a class="ws-notif-item-link" :href="n.link || '#'" @click="openItem(n)">
                        <span class="ws-notif-icon" :class="'ws-notif-' + n.type"><i class="fa-solid" :class="iconOf(n.type)"></i></span>
                        <span class="ws-notif-body">
                            <strong x-text="n.title"></strong>
                            <small x-text="n.message"></small>
                            <em x-text="n.time"></em>
                        </span>
                        <i class="fa-solid fa-circle ws-notif-dot" x-show="!n.is_read"></i>
                    </a>
                    <span class="ws-notif-actions">
                        <button type="button" class="ws-notif-act" :title="n.is_read ? '' : '<?php esc_attr_e( 'Marcar leída', 'workshop' ); ?>'" @click="markRead(n)" x-show="!n.is_read"><i class="fa-solid fa-check"></i></button>
                        <button type="button" class="ws-notif-act ws-notif-act-del" :title="'<?php esc_attr_e( 'Eliminar', 'workshop' ); ?>'" @click="remove(n)"><i class="fa-solid fa-trash-can"></i></button>
                    </span>
                </div>
            </template>
        </div>
        <div class="ws-notif-foot" x-show="items.length > 0">
            <span x-text="unread > 0 ? unread + ' <?php esc_html_e( 'sin leer', 'workshop' ); ?>' : '<?php esc_html_e( 'Todo al día', 'workshop' ); ?>'"></span>
        </div>
    </div>
</div>
