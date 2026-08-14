<?php
/**
 * Módulo de Clientes (CRM).
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$can_create = ws_can( 'customers_create' );
$can_edit = ws_can( 'customers_edit' );
$can_delete = ws_can( 'customers_delete' );
?>

<div class="ws-module-customers" x-data="wsCustomers()">
    <div class="ws-module-header">
        <div class="ws-header-left">
            <h2><?php esc_html_e( 'Gestión de Clientes', 'workshop' ); ?></h2>
            <p class="ws-header-desc"><?php esc_html_e( 'Administra la base de datos de clientes y su historial de compras.', 'workshop' ); ?></p>
        </div>
        <?php if ( $can_create ) : ?>
        <button class="ws-btn ws-btn-primary" @click="showModal = true">
            <i class="fa-solid fa-plus"></i>
            <?php esc_html_e( 'Nuevo Cliente', 'workshop' ); ?>
        </button>
        <?php endif; ?>
    </div>

    <div class="ws-module-toolbar">
        <div class="ws-search-box">
            <i class="fa-solid fa-search"></i>
            <input type="text" 
                   x-model="search" 
                   @input="debounceSearch()"
                   placeholder="<?php esc_attr_e( 'Buscar por nombre, email o teléfono...', 'workshop' ); ?>">
        </div>
        <div class="ws-filters">
            <select x-model="filterStatus" @change="loadCustomers()">
                <option value=""><?php esc_html_e( 'Todos los estados', 'workshop' ); ?></option>
                <option value="active"><?php esc_html_e( 'Activos', 'workshop' ); ?></option>
                <option value="inactive"><?php esc_html_e( 'Inactivos', 'workshop' ); ?></option>
            </select>
        </div>
    </div>

    <div class="ws-data-table">
        <table>
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Nombre', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Teléfono', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Ciudad', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Puntos', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Total Compras', 'workshop' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr>
                        <td colspan="7" class="ws-text-center">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            <?php esc_html_e( 'Cargando...', 'workshop' ); ?>
                        </td>
                    </tr>
                </template>
                <template x-if="!loading && customers.length === 0">
                    <tr>
                        <td colspan="7" class="ws-text-center">
                            <?php esc_html_e( 'No hay clientes registrados', 'workshop' ); ?>
                        </td>
                    </tr>
                </template>
                <template x-for="customer in pagedRows()" :key="customer.id">
                    <tr>
                        <td>
                            <div class="ws-customer-name" x-text="customer.name"></div>
                        </td>
                        <td x-text="customer.email || '-'"></td>
                        <td x-text="customer.phone || '-'"></td>
                        <td x-text="customer.city || '-'"></td>
                        <td>
                            <span class="ws-badge ws-badge-success" x-text="customer.points || 0"></span>
                        </td>
                        <td x-text="formatCurrency(customer.total_spent)"></td>
                        <td>
                            <div class="ws-actions">
                                <?php if ( $can_edit ) : ?>
                                <button class="ws-btn-icon" @click="editCustomer(customer)" title="<?php esc_attr_e( 'Editar', 'workshop' ); ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ( $can_delete ) : ?>
                                <button class="ws-btn-icon ws-btn-danger" @click="deleteCustomer(customer.id)" title="<?php esc_attr_e( 'Eliminar', 'workshop' ); ?>">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div class="ws-pagination" x-show="total > pageSize">
            <span class="ws-pagination-info" x-text="(total ? (page - 1) * pageSize + 1 : 0) + '–' + Math.min(page * pageSize, total) + ' de ' + total"></span>
            <div class="ws-pagination-controls">
                <button class="ws-page-btn" @click="prevPage()" :disabled="page <= 1"><i class="fa-solid fa-chevron-left"></i></button>
                <template x-for="n in pages()" :key="n">
                    <button class="ws-page-btn" :class="n === page ? 'is-active' : ''" @click="goPage(n)" x-text="n"></button>
                </template>
                <button class="ws-page-btn" @click="nextPage()" :disabled="page >= totalPages()"><i class="fa-solid fa-chevron-right"></i></button>
                <select class="ws-page-size" x-model.number="pageSize" @change="changePageSize()">
                    <option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Modal de cliente -->
    <div class="ws-modal" x-show="showModal" x-cloak x-transition>
        <div class="ws-modal-content" @click.away="showModal = false">
            <div class="ws-modal-header">
                <h3 x-text="editingCustomer ? '<?php esc_html_e( 'Editar Cliente', 'workshop' ); ?>' : '<?php esc_html_e( 'Nuevo Cliente', 'workshop' ); ?>'"></h3>
                <button class="ws-modal-close" @click="showModal = false">&times;</button>
            </div>
            <div class="ws-modal-body">
                <form @submit.prevent="saveCustomer()">
                    <div class="ws-form-grid">
                        <div class="ws-form-group">
                            <label><?php esc_html_e( 'Nombre completo *', 'workshop' ); ?></label>
                            <input type="text" x-model="form.name" required>
                        </div>
                        <div class="ws-form-group">
                            <label><?php esc_html_e( 'Email', 'workshop' ); ?></label>
                            <input type="email" x-model="form.email">
                        </div>
                        <div class="ws-form-group">
                            <label><?php esc_html_e( 'Teléfono', 'workshop' ); ?></label>
                            <input type="tel" x-model="form.phone">
                        </div>
                        <div class="ws-form-group">
                            <label><?php esc_html_e( 'Ciudad', 'workshop' ); ?></label>
                            <input type="text" x-model="form.city">
                        </div>
                        <div class="ws-form-group">
                            <label><?php esc_html_e( 'Provincia', 'workshop' ); ?></label>
                            <input type="text" x-model="form.province">
                        </div>
                        <div class="ws-form-group">
                            <label><?php esc_html_e( 'Código Postal', 'workshop' ); ?></label>
                            <input type="text" x-model="form.postal_code">
                        </div>
                    </div>
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Dirección', 'workshop' ); ?></label>
                        <textarea x-model="form.address" rows="3"></textarea>
                    </div>
                    <div class="ws-form-group">
                        <label><?php esc_html_e( 'Notas', 'workshop' ); ?></label>
                        <textarea x-model="form.notes" rows="3"></textarea>
                    </div>
                    <div class="ws-modal-footer">
                        <button type="button" class="ws-btn ws-btn-secondary" @click="showModal = false">
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
    Alpine.data('wsCustomers', () => ({
        ...WSClientPager('customers'),
        customers: [],
        loading: false,
        showModal: false,
        editingCustomer: null,
        search: '',
        filterStatus: '',
        searchTimeout: null,
        form: {
            id: 0,
            name: '',
            email: '',
            phone: '',
            address: '',
            city: '',
            province: '',
            postal_code: '',
            notes: ''
        },

        init() {
            this.loadCustomers();
        },

        async loadCustomers() {
            this.loading = true;
            this.onPageFilter();
            try {
                const response = await $('ws_customers_get', {
                    search: this.search,
                    status: this.filterStatus,
                    limit: 500,
                    offset: 0
                });
                if (response.success) {
                    this.customers = response.data.data || [];
                }
            } catch (error) {
                console.error('Error cargando clientes:', error);
            }
            this.loading = false;
        },

        debounceSearch() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => this.loadCustomers(), 500);
        },

        editCustomer(customer) {
            this.editingCustomer = customer;
            this.form = { ...customer };
            this.showModal = true;
        },

        async saveCustomer() {
            try {
                const response = await $('ws_customers_save', this.form);
                if (response.success) {
                    this.showModal = false;
                    this.resetForm();
                    this.loadCustomers();
                    Swal.fire({
                        icon: 'success',
                        title: '<?php esc_html_e( 'Cliente guardado', 'workshop' ); ?>',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '<?php esc_html_e( 'Error', 'workshop' ); ?>',
                    text: error.responseJSON?.data?.msg || '<?php esc_html_e( 'No se pudo guardar el cliente', 'workshop' ); ?>'
                });
            }
        },

        async deleteCustomer(id) {
            const result = await Swal.fire({
                title: '<?php esc_html_e( '¿Eliminar cliente?', 'workshop' ); ?>',
                text: '<?php esc_html_e( 'Esta acción no se puede deshacer', 'workshop' ); ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<?php esc_html_e( 'Sí, eliminar', 'workshop' ); ?>',
                cancelButtonText: '<?php esc_html_e( 'Cancelar', 'workshop' ); ?>'
            });

            if (result.isConfirmed) {
                try {
                    const response = await $('ws_customers_delete', { id: id });
                    if (response.success) {
                        this.loadCustomers();
                        Swal.fire({
                            icon: 'success',
                            title: '<?php esc_html_e( 'Cliente eliminado', 'workshop' ); ?>',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: '<?php esc_html_e( 'Error', 'workshop' ); ?>',
                        text: '<?php esc_html_e( 'No se pudo eliminar el cliente', 'workshop' ); ?>'
                    });
                }
            }
        },

        resetForm() {
            this.form = {
                id: 0,
                name: '',
                email: '',
                phone: '',
                address: '',
                city: '',
                province: '',
                postal_code: '',
                notes: ''
            };
            this.editingCustomer = null;
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
        }
    }));
});
</script>
