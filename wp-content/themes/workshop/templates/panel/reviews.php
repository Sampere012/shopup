<?php
/**
 * Módulo de Valoraciones/Reseñas.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$can_moderate = ws_can( 'reviews_moderate' );
?>

<div class="ws-module-reviews" x-data="wsReviews()">
    <div class="ws-module-header">
        <div class="ws-header-left">
            <h2><?php esc_html_e( 'Valoraciones de la tienda', 'workshop' ); ?></h2>
            <p class="ws-header-desc"><?php esc_html_e( 'Gestiona las reseñas y valoraciones de los clientes sobre tu tienda y tus productos.', 'workshop' ); ?></p>
        </div>
    </div>

    <div class="ws-stats-grid">
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-yellow">
                <i class="fa-solid fa-star"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Promedio General', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="stats.average_rating?.toFixed(1) + '/5'"></div>
            </div>
        </div>
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-green">
                <i class="fa-solid fa-check-circle"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Aprobadas', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="stats.approved_count"></div>
            </div>
        </div>
        <div class="ws-stat-card">
            <div class="ws-stat-icon ws-stat-icon-orange">
                <i class="fa-solid fa-clock"></i>
            </div>
            <div class="ws-stat-content">
                <div class="ws-stat-label"><?php esc_html_e( 'Pendientes', 'workshop' ); ?></div>
                <div class="ws-stat-value" x-text="stats.pending_count"></div>
            </div>
        </div>
    </div>

    <div class="ws-module-toolbar">
        <div class="ws-search-box">
            <i class="fa-solid fa-search"></i>
            <input type="text" 
                   x-model="search" 
                   @input="debounceSearch()"
                   placeholder="<?php esc_attr_e( 'Buscar reseña...', 'workshop' ); ?>">
        </div>
        <div class="ws-filters">
            <select x-model="statusFilter" @change="loadReviews()">
                <option value=""><?php esc_html_e( 'Todos los estados', 'workshop' ); ?></option>
                <option value="approved"><?php esc_html_e( 'Aprobadas', 'workshop' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendientes', 'workshop' ); ?></option>
                <option value="rejected"><?php esc_html_e( 'Rechazadas', 'workshop' ); ?></option>
            </select>
            <select x-model="ratingFilter" @change="loadReviews()">
                <option value=""><?php esc_html_e( 'Todas las estrellas', 'workshop' ); ?></option>
                <option value="5">5 <?php esc_html_e( 'estrellas', 'workshop' ); ?></option>
                <option value="4">4 <?php esc_html_e( 'estrellas', 'workshop' ); ?></option>
                <option value="3">3 <?php esc_html_e( 'estrellas', 'workshop' ); ?></option>
                <option value="2">2 <?php esc_html_e( 'estrellas', 'workshop' ); ?></option>
                <option value="1">1 <?php esc_html_e( 'estrella', 'workshop' ); ?></option>
            </select>
        </div>
    </div>

    <div class="ws-reviews-list">
        <template x-if="loading">
            <div class="ws-loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <?php esc_html_e( 'Cargando reseñas...', 'workshop' ); ?>
            </div>
        </template>
        <template x-if="!loading && reviews.length === 0">
            <div class="ws-empty">
                <?php esc_html_e( 'No hay reseñas registradas', 'workshop' ); ?>
            </div>
        </template>
        <template x-for="review in pagedRows()" :key="review.id">
            <div class="ws-review-card">
                <div class="ws-review-header">
                    <div class="ws-review-product">
                        <!-- Reseña de tienda (location_id) o de producto. -->
                        <template x-if="review.location_id">
                            <div class="ws-review-thumb ws-review-thumb-store"><i class="fa-solid fa-store"></i></div>
                        </template>
                        <template x-if="!review.location_id">
                            <img :src="review.product_image || '<?php echo WS_URL; ?>assets/images/placeholder.png'" :alt="review.product_name">
                        </template>
                        <div>
                            <div class="ws-product-name" x-text="review.location_id ? review.location_name : review.product_name"></div>
                            <div class="ws-review-date" x-text="formatDate(review.created_at)"></div>
                        </div>
                    </div>
                    <div class="ws-review-rating">
                        <template x-for="i in 5" :key="i">
                            <i class="fa-solid fa-star" :class="i <= review.rating ? 'ws-star-filled' : 'ws-star-empty'"></i>
                        </template>
                    </div>
                </div>
                <div class="ws-review-content">
                    <div class="ws-review-author">
                        <i class="fa-solid fa-user"></i>
                        <span x-text="review.customer_name || '<?php esc_html_e( 'Cliente anónimo', 'workshop' ); ?>'"></span>
                    </div>
                    <p x-text="review.comment"></p>
                </div>
                <div class="ws-review-footer">
                    <span class="ws-badge" :class="'ws-badge-' + review.status" x-text="getStatusLabel(review.status)"></span>
                    <?php if ( $can_moderate ) : ?>
                    <div class="ws-review-actions">
                        <template x-if="review.status === 'pending'">
                            <button class="ws-btn ws-btn-success ws-btn-sm" @click="moderateReview(review.id, 'approved')">
                                <i class="fa-solid fa-check"></i>
                                <?php esc_html_e( 'Aprobar', 'workshop' ); ?>
                            </button>
                        </template>
                        <template x-if="review.status === 'pending'">
                            <button class="ws-btn ws-btn-danger ws-btn-sm" @click="moderateReview(review.id, 'rejected')">
                                <i class="fa-solid fa-times"></i>
                                <?php esc_html_e( 'Rechazar', 'workshop' ); ?>
                            </button>
                        </template>
                        <button class="ws-btn-icon" @click="deleteReview(review.id)" title="<?php esc_attr_e( 'Eliminar', 'workshop' ); ?>">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </template>
    </div>
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

<script>
// Helper AJAX
const $ = (path, data) => fetch(WS.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(Object.assign({ action: path, ws_nonce: WS.nonce }, data || {}))
}).then(r => r.json());

document.addEventListener('alpine:init', () => {
    Alpine.data('wsReviews', () => ({
        ...WSClientPager('reviews'),
        reviews: [],
        loading: false,
        search: '',
        statusFilter: '',
        ratingFilter: '',
        stats: { average_rating: 0, approved_count: 0, pending_count: 0 },
        searchTimeout: null,

        init() {
            this.loadReviews();
            this.loadStats();
        },

        async loadReviews() {
            this.loading = true;
            this.onPageFilter();
            try {
                const response = await $('ws_reviews_get', {
                    search: this.search,
                    status: this.statusFilter,
                    rating: this.ratingFilter,
                    limit: 500,
                    offset: 0
                });
                if (response.success) {
                    this.reviews = response.data.data || [];
                }
            } catch (error) {
                console.error('Error cargando reseñas:', error);
            }
            this.loading = false;
        },

        async loadStats() {
            try {
                const response = await $('ws_reviews_stats');
                if (response.success) {
                    this.stats = response.data.data || { average_rating: 0, approved_count: 0, pending_count: 0 };
                }
            } catch (error) {
                console.error('Error cargando estadísticas:', error);
            }
        },

        debounceSearch() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => this.loadReviews(), 500);
        },

        async moderateReview(id, status) {
            try {
                const response = await $('ws_reviews_moderate', { id: id, status: status });
                if (response.success) {
                    this.loadReviews();
                    this.loadStats();
                    Swal.fire({
                        icon: 'success',
                        title: '<?php esc_html_e( 'Reseña moderada', 'workshop' ); ?>',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '<?php esc_html_e( 'Error', 'workshop' ); ?>',
                    text: '<?php esc_html_e( 'No se pudo moderar la reseña', 'workshop' ); ?>'
                });
            }
        },

        async deleteReview(id) {
            const result = await Swal.fire({
                title: '<?php esc_html_e( '¿Eliminar reseña?', 'workshop' ); ?>',
                text: '<?php esc_html_e( 'Esta acción no se puede deshacer', 'workshop' ); ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<?php esc_html_e( 'Sí, eliminar', 'workshop' ); ?>',
                cancelButtonText: '<?php esc_html_e( 'Cancelar', 'workshop' ); ?>'
            });

            if (result.isConfirmed) {
                try {
                    const response = await $('ws_reviews_delete', { id: id });
                    if (response.success) {
                        this.loadReviews();
                        this.loadStats();
                        Swal.fire({
                            icon: 'success',
                            title: '<?php esc_html_e( 'Reseña eliminada', 'workshop' ); ?>',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: '<?php esc_html_e( 'Error', 'workshop' ); ?>',
                        text: '<?php esc_html_e( 'No se pudo eliminar la reseña', 'workshop' ); ?>'
                    });
                }
            }
        },

        formatDate(date) {
            return new Date(date).toLocaleDateString('es-ES');
        },

        getStatusLabel(status) {
            const labels = {
                approved: '<?php esc_html_e( 'Aprobada', 'workshop' ); ?>',
                pending: '<?php esc_html_e( 'Pendiente', 'workshop' ); ?>',
                rejected: '<?php esc_html_e( 'Rechazada', 'workshop' ); ?>'
            };
            return labels[status] || status;
        }
    }));
});
</script>
