<?php
/**
 * Módulo de Fidelización.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$can_manage = ws_can( 'loyalty_manage' );
?>

<div class="ws-module-loyalty" x-data="wsLoyalty()">
    <div class="ws-module-header">
        <div class="ws-header-left">
            <h2><?php esc_html_e( 'Programa de Fidelización', 'workshop' ); ?></h2>
            <p class="ws-header-desc"><?php esc_html_e( 'Gestiona los puntos de fidelización y recompensas de los clientes.', 'workshop' ); ?></p>
        </div>
        <?php if ( $can_manage ) : ?>
        <button class="ws-btn ws-btn-primary" @click="showSettingsModal = true">
            <i class="fa-solid fa-gear"></i>
            <?php esc_html_e( 'Configurar Programa', 'workshop' ); ?>
        </button>
        <?php endif; ?>
    </div>

    <div class="ws-stats-grid">
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-yellow">
                <i class="fa-solid fa-coins"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Puntos Totales Emitidos', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="stats.total_points_earned"></div>
            </div>
        </div>
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-green">
                <i class="fa-solid fa-gift"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Puntos Canjeados', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="stats.total_points_redeemed"></div>
            </div>
        </div>
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-purple">
                <i class="fa-solid fa-users"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Clientes Activos', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="stats.active_customers"></div>
            </div>
        </div>
    </div>

    <div class="ws-module-toolbar">
        <div class="ws-search-box">
            <i class="fa-solid fa-search"></i>
            <input type="text" 
                   x-model="search" 
                   @input="debounceSearch()"
                   placeholder="<?php esc_attr_e( 'Buscar cliente...', 'workshop' ); ?>">
        </div>
        <div class="ws-filters">
            <select x-model="sortBy" @change="loadCustomers()">
                <option value="points_desc"><?php esc_html_e( 'Más puntos primero', 'workshop' ); ?></option>
                <option value="points_asc"><?php esc_html_e( 'Menos puntos primero', 'workshop' ); ?></option>
                <option value="name_asc"><?php esc_html_e( 'Nombre A-Z', 'workshop' ); ?></option>
                <option value="name_desc"><?php esc_html_e( 'Nombre Z-A', 'workshop' ); ?></option>
            </select>
        </div>
    </div>

    <div class="ws-data-table">
        <table>
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Cliente', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Puntos', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Nivel', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Total Gastado', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Última Actividad', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr>
                        <td colspan="6" class="ws-text-center">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            <?php esc_html_e( 'Cargando...', 'workshop' ); ?>
                        </td>
                    </tr>
                </template>
                <template x-if="!loading && customers.length === 0">
                    <tr>
                        <td colspan="6" class="ws-text-center">
                            <?php esc_html_e( 'No hay clientes con puntos', 'workshop' ); ?>
                        </td>
                    </tr>
                </template>
                <template x-for="customer in customers" :key="customer.id">
                    <tr>
                        <td>
                            <div class="ws-customer-name" x-text="customer.name"></div>
                            <div class="ws-customer-email" x-text="customer.email || '-'"></div>
                        </td>
                        <td>
                            <span class="ws-points-badge" x-text="customer.points"></span>
                        </td>
                        <td>
                            <span class="ws-badge" :class="'ws-badge-' + customer.tier" x-text="getTierLabel(customer.tier)"></span>
                        </td>
                        <td x-text="formatCurrency(customer.total_spent)"></td>
                        <td x-text="formatDate(customer.last_activity)"></td>
                        <td>
                            <div class="ws-actions">
                                <?php if ( $can_manage ) : ?>
                                <button class="ws-btn-icon" @click="adjustPoints(customer)" title="<?php esc_attr_e( 'Ajustar puntos', 'workshop' ); ?>">
                                    <i class="fa-solid fa-plus-minus"></i>
                                </button>
                                <button class="ws-btn-icon" @click="viewHistory(customer)" title="<?php esc_attr_e( 'Historial', 'workshop' ); ?>">
                                    <i class="fa-solid fa-history"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Modal de ajuste de puntos -->
    <div class="ws-modal" x-show="showPointsModal" x-cloak x-transition>
        <div class="ws-modal-content" @click.away="showPointsModal = false">
            <div class="ws-modal-header">
                <h3><?php esc_html_e( 'Ajustar Puntos', 'workshop' ); ?></h3>
                <button class="ws-modal-close" @click="showPointsModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <form @submit.prevent="savePointsAdjustment()">
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Cliente', 'workshop' ); ?></label>
                        <input type="text" :value="selectedCustomer?.name" disabled>
                    </div>
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Puntos actuales', 'workshop' ); ?></label>
                        <input type="number" :value="selectedCustomer?.points" disabled>
                    </div>
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Ajuste (+/-)', 'workshop' ); ?></label>
                        <input type="number" x-model="pointsAdjustment" required>
                    </div>
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Motivo', 'workshop' ); ?></label>
                        <textarea x-model="adjustmentReason" rows="3" required></textarea>
                    </div>
                    <div class="ws-modal-footer">
                        <button type="button" class="ws-btn ws-btn-secondary" @click="showPointsModal = false">
                            <?php esc_html_e( 'Cancelar', 'workshop' ); ?>
                        </button>
                        <button type="submit" class="ws-btn ws-btn-primary">
                            <?php esc_html_e( 'Guardar', 'workshop' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de configuración -->
    <div class="ws-modal" x-show="showSettingsModal" x-cloak x-transition>
        <div class="ws-modal-content" @click.away="showSettingsModal = false">
            <div class="ws-modal-header">
                <h3><?php esc_html_e( 'Configurar Programa de Fidelización', 'workshop' ); ?></h3>
                <button class="ws-modal-close" @click="showSettingsModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <form @submit.prevent="saveSettings()">
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Puntos por € gastado', 'workshop' ); ?></label>
                        <input type="number" x-model="settings.points_per_euro" min="0" step="0.1">
                    </div>
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Valor de 1 punto en €', 'workshop' ); ?></label>
                        <input type="number" x-model="settings.point_value" min="0" step="0.01">
                    </div>
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Puntos para nivel Plata', 'workshop' ); ?></label>
                        <input type="number" x-model="settings.silver_tier" min="0">
                    </div>
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Puntos para nivel Oro', 'workshop' ); ?></label>
                        <input type="number" x-model="settings.gold_tier" min="0">
                    </div>
                    <div class="ws-modal-footer">
                        <button type="button" class="ws-btn ws-btn-secondary" @click="showSettingsModal = false">
                            <?php esc_html_e( 'Cancelar', 'workshop' ); ?>
                        </button>
                        <button type="submit" class="ws-btn ws-btn-primary">
                            <?php esc_html_e( 'Guardar', 'workshop' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de historial de puntos -->
    <div class="ws-modal" x-show="showHistoryModal" x-cloak x-transition>
        <div class="ws-modal-content ws-modal-lg" @click.away="showHistoryModal = false">
            <div class="ws-modal-header">
                <h3><?php esc_html_e( 'Historial de Puntos', 'workshop' ); ?> — <span x-text="historyCustomer?.name"></span></h3>
                <button class="ws-modal-close" @click="showHistoryModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <div class="ws-data-table ws-data-table-compact">
                    <table>
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Fecha', 'workshop' ); ?></th>
                                <th><?php esc_html_e( 'Puntos', 'workshop' ); ?></th>
                                <th><?php esc_html_e( 'Tipo', 'workshop' ); ?></th>
                                <th><?php esc_html_e( 'Motivo', 'workshop' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-if="historyTransactions.length === 0">
                                <tr>
                                    <td colspan="4" class="ws-text-center"><?php esc_html_e( 'Sin movimientos', 'workshop' ); ?></td>
                                </tr>
                            </template>
                            <template x-for="tx in historyTransactions" :key="tx.id">
                                <tr>
                                    <td x-text="formatDate(tx.created_at)"></td>
                                    <td>
                                        <span class="ws-points-badge" :class="tx.points > 0 ? 'ws-badge-success' : 'ws-badge-danger'" x-text="(tx.points > 0 ? '+' : '') + tx.points"></span>
                                    </td>
                                    <td x-text="getTxTypeLabel(tx.type)"></td>
                                    <td x-text="tx.note || tx.reference || '-'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Helper AJAX
const $ = (path, data) => fetch(WS.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(Object.assign({ action: path, ws_nonce: WS.nonce }, data || {}))
}).then(r => r.json());

document.addEventListener('alpine:init', () => {
    Alpine.data('wsLoyalty', () => ({
        customers: [],
        loading: false,
        search: '',
        sortBy: 'points_desc',
        stats: { total_points_earned: 0, total_points_redeemed: 0, active_customers: 0 },
        showPointsModal: false,
        showSettingsModal: false,
        showHistoryModal: false,
        selectedCustomer: null,
        historyCustomer: null,
        historyTransactions: [],
        pointsAdjustment: 0,
        adjustmentReason: '',
        settings: {
            points_per_euro: 1,
            point_value: 0.01,
            silver_tier: 100,
            gold_tier: 500
        },
        searchTimeout: null,

        init() {
            this.loadCustomers();
            this.loadStats();
            this.loadSettings();
        },

        async loadCustomers() {
            this.loading = true;
            try {
                const response = await $('ws_loyalty_customers', {
                    search: this.search,
                    sort_by: this.sortBy
                });
                if (response.success) {
                    this.customers = response.data.data || [];
                }
            } catch (error) {
                console.error('Error cargando clientes:', error);
            }
            this.loading = false;
        },

        async loadStats() {
            try {
                const response = await $('ws_loyalty_stats');
                if (response.success) {
                    this.stats = response.data.data || { total_points_earned: 0, total_points_redeemed: 0, active_customers: 0 };
                }
            } catch (error) {
                console.error('Error cargando estadísticas:', error);
            }
        },

        async loadSettings() {
            try {
                const response = await $('ws_loyalty_settings');
                if (response.success) {
                    this.settings = response.data.data || this.settings;
                }
            } catch (error) {
                console.error('Error cargando configuración:', error);
            }
        },

        debounceSearch() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => this.loadCustomers(), 500);
        },

        adjustPoints(customer) {
            this.selectedCustomer = customer;
            this.pointsAdjustment = 0;
            this.adjustmentReason = '';
            this.showPointsModal = true;
        },

        async savePointsAdjustment() {
            try {
                const response = await $('ws_loyalty_adjust_points', {
                    customer_id: this.selectedCustomer.id,
                    points: this.pointsAdjustment,
                    reason: this.adjustmentReason
                });
                if (response.success) {
                    this.showPointsModal = false;
                    this.loadCustomers();
                    Swal.fire({
                        icon: 'success',
                        title: '<?php esc_html_e( 'Puntos ajustados', 'workshop' ); ?>',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '<?php esc_html_e( 'Error', 'workshop' ); ?>',
                    text: '<?php esc_html_e( 'No se pudo ajustar los puntos', 'workshop' ); ?>'
                });
            }
        },

        async saveSettings() {
            try {
                const response = await $('ws_loyalty_save_settings', this.settings);
                if (response.success) {
                    this.showSettingsModal = false;
                    Swal.fire({
                        icon: 'success',
                        title: '<?php esc_html_e( 'Configuración guardada', 'workshop' ); ?>',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '<?php esc_html_e( 'Error', 'workshop' ); ?>',
                    text: '<?php esc_html_e( 'No se pudo guardar la configuración', 'workshop' ); ?>'
                });
            }
        },

        viewHistory(customer) {
            this.historyCustomer = customer;
            this.historyTransactions = [];
            this.showHistoryModal = true;
            $('ws_loyalty_transactions', { customer_id: customer.id })
                .then(res => {
                    if (res.success) {
                        this.historyTransactions = res.data.data || [];
                    }
                })
                .catch(err => console.error('Error cargando historial:', err));
        },

        getTxTypeLabel(type) {
            const labels = {
                earned: '<?php esc_html_e( 'Ganados', 'workshop' ); ?>',
                redeemed: '<?php esc_html_e( 'Canjeados', 'workshop' ); ?>',
                manual: '<?php esc_html_e( 'Manual', 'workshop' ); ?>'
            };
            return labels[type] || type;
        },

        formatDate(date) {
            return date ? new Date(date).toLocaleDateString('es-ES') : '-';
        },

        formatCurrency(amount) {
            const val = Number(amount) || 0;
            const sym = WS.currency || '';
            if (/^[A-Z]{3}$/.test(sym)) {
                try {
                    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: sym }).format(val);
                } catch (e) { /* símbolo o código inválido: formato manual */ }
            }
            const num = val.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return (sym ? sym + ' ' : '') + num;
        },

        getTierLabel(tier) {
            const labels = {
                bronze: '<?php esc_html_e( 'Bronce', 'workshop' ); ?>',
                silver: '<?php esc_html_e( 'Plata', 'workshop' ); ?>',
                gold: '<?php esc_html_e( 'Oro', 'workshop' ); ?>',
                platinum: '<?php esc_html_e( 'Platino', 'workshop' ); ?>'
            };
            return labels[tier] || tier;
        }
    }));
});
</script>
