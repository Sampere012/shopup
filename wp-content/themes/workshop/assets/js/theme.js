/* Workshop MultiTienda - Alpine.js components */
(function () {
    'use strict';

    const $ = (path, data) => fetch(WS.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(Object.assign({ action: path, ws_nonce: WS.nonce }, data || {}))
    }).then(r => r.json());

    // Exponer el helper AJAX globalmente para que los módulos offline
    // (offline-queue.js, panel-offline.js) puedan reenviar peticiones al
    // sincronizar la cola.
    window.$ = $;

    /* ---------- Loader global + guard genérico de envíos ---------- */
    // El círculo pulsante SOLO se muestra al navegar entre páginas (clic en un
    // enlace interno), no por cada petición AJAX, para no molestar en cargas
    // ligeras. El contador de peticiones se mantiene para el guard de envíos.
    let wsReqs = 0;
    let wsNavTimer = null;

    const wsLoaderShow = () => {
        const el = document.getElementById('ws-loader');
        if (el) el.classList.add('is-active');
    };
    const wsLoaderHide = () => {
        const el = document.getElementById('ws-loader');
        if (el) el.classList.remove('is-active');
    };

    // Envuelve fetch para contar TODAS las peticiones AJAX del tema
    // (incluye el helper $, checkout y listados) de forma genérica.
    const _wsFetch = window.fetch.bind(window);
    window.fetch = (...args) => {
        wsReqs++;
        // Adjunta el slug del negocio actual a cada petición form-urlencoded
        // para que el servidor escale el scope (tablas/opciones) correctamente.
        try {
            const opts = args[1];
            if (opts && opts.body && opts.body instanceof URLSearchParams && window.WS && WS.business && !opts.body.has('ws_biz')) {
                opts.body.append('ws_biz', WS.business);
            }
        } catch (e) {}
        return _wsFetch(...args).then(
            r => { wsReqDone(); return r; },
            e => { wsReqDone(); throw e; }
        );
    };
    const wsReqDone = () => {
        wsReqs = Math.max(0, wsReqs - 1);
    };

    // Guard genérico de formularios: evita enviar dos veces el mismo form
    // (clics dobles, Enter, etc.). Corre en FASE CAPTURA para bloquear antes
    // de que corra el handler de Alpine del form (@submit.prevent="save"),
    // que es el que dispararía una petición duplicada.
    document.addEventListener('submit', (e) => {
        const form = e.target && e.target.closest ? e.target.closest('form') : null;
        if (!form) return;
        if (form.classList.contains('ws-form-submitting')) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        form.classList.add('ws-form-submitting');
        // Nivel base de peticiones en vuelo ANTES de este envío (p. ej. el
        // poll de notificaciones de 90s): el form se desbloquea en cuanto el
        // contador vuelve a ese nivel (su petición terminó), sin esperar el
        // cooldown completo. Tope de seguridad de 2.5s por si la petición
        // tarda más o el handler no dispara ninguna (validación que falla).
        const baseReqs = wsReqs;
        const wsUnlockForm = () => {
            clearTimeout(form._wsSubmitTimer);
            clearTimeout(form._wsCheckTimer);
            form.classList.remove('ws-form-submitting');
        };
        form._wsSubmitTimer = setTimeout(wsUnlockForm, 2500);
        const wsCheckDone = () => {
            if (wsReqs <= baseReqs) { wsUnlockForm(); return; }
            form._wsCheckTimer = setTimeout(wsCheckDone, 150);
        };
        wsCheckDone();
    }, true);

    // Loader en navegación entre páginas: muestra el círculo al hacer clic en
    // un enlace interno real (mismo origen, sin pestaña nueva, sin clic
    // modificado). Ignora enlaces que otro handler ya canceló (defaultPrevented,
    // p. ej. el logout con confirmación de Alpine), anclas '#', mailto:/tel:/js,
    // download y target=_blank. La nueva página se carga con el loader oculto.
    document.addEventListener('click', (e) => {
        const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        if (e.defaultPrevented || e.button !== 0) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const t = (a.target || '').toLowerCase();
        if (t && t !== '_self') return;
        if (a.hasAttribute('download')) return;
        const href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) return;
        if (a.origin !== location.origin) return;
        // Pequeño retardo para no parpadear en enlaces manejados por JS.
        clearTimeout(wsNavTimer);
        wsNavTimer = setTimeout(wsLoaderShow, 60);
    });

    const toast = (icon, title, text) => {
        if (typeof Swal !== 'undefined') {
            return Swal.fire({ icon: icon || 'success', title: title || '', text: text || '', toast: true, position: 'top-end', showConfirmButton: false, timer: 2500 });
        }
        alert((title || '') + ' ' + (text || ''));
    };

    // Exponer toast globalmente para scripts inline de los paneles
    // (p. ej. la exportación de reportes).
    window.toast = toast;

    const money = (amount, currency) => {
        const c = currency || WS.currency || '€';
        const n = new Intl.NumberFormat('es', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount) || 0);
        return n + ' ' + c;
    };

    /* Ajusta brillo de un color hex (+ claro / - oscuro). */
    const shadeHex = (hex, pct) => {
        hex = String(hex || '#4f46e5').replace('#', '');
        if (!/^([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)) return '#' + hex;
        if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
        const n = parseInt(hex, 16);
        const f = v => Math.max(0, Math.min(255, v + pct));
        const r = f((n >> 16) & 255), g = f((n >> 8) & 255), b = f(n & 255);
        return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    };

    /* Slug (URL) a partir de un nombre, p. ej. "Mi Tienda 2" -> "mi-tienda-2". */
    const slugify = s => String(s || '').toLowerCase().normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);

    /* Valor normalizado para ordenar: fechas d/m/Y -> timestamp,
       valores realmente numéricos -> número, resto -> string lowercase.
       Solo trata como número cuando la cadena es numérica (con símbolo de
       moneda opcional al final), para no romper nombres como "Arroz 5lb". */
    const wsSortNorm = v => {
        if (typeof v === 'number') return v;
        v = String(v === null || v === undefined ? '' : v).trim();
        if (!v) return '';
        const m = v.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/);
        if (m) return Date.UTC(+m[3], +m[2] - 1, +m[1]);
        const cur = v.replace(/\s*(RD\$|US\$|Bs|€|\$)\s*$/i, '').replace(/\s/g, '');
        if (/^[-+]?\d[\d.,]*$/.test(cur)) {
            const n = parseFloat(cur.replace(',', '.'));
            if (!isNaN(n)) return n;
        }
        return v.toLowerCase();
    };

    /* Persistencia de estado de tablas en localStorage (por usuario y tabla). */
    const wsTsKey = (key) => 'ws_ts_' + ((window.WS && WS.userId) || 0) + '_' + (key || '');
    const wsTsRead = (key) => {
        try { return JSON.parse(localStorage.getItem(wsTsKey(key))); } catch (e) { return null; }
    };
    const wsTsWrite = (key, state) => {
        try { localStorage.setItem(wsTsKey(key), JSON.stringify(state)); } catch (e) {}
    };

    /* Estado compartido de tablas (paginación server-side): ordenamiento,
       búsqueda y paginación se resuelven en el servidor. El mixin guarda
       page/pageSize/sortKey/sortDir, los envía al AJAX y recibe las filas de
       la página + el total. Se extiende en los componentes Alpine de listados
       (products, locations, suppliers, stock, movements, orders). */
    const tableState = (key) => ({
        sortKey: '',
        sortDir: 'asc',
        page: 1,
        pageSize: 10,
        total: 0,
        tsKey: key || '',
        restoreTableState() {
            if (!this.tsKey) return;
            const saved = wsTsRead(this.tsKey);
            if (saved) {
                if (typeof saved.sortKey === 'string' && saved.sortKey) this.sortKey = saved.sortKey;
                if (saved.sortDir === 'asc' || saved.sortDir === 'desc') this.sortDir = saved.sortDir;
                if ([10, 25, 50, 100].includes(saved.pageSize)) this.pageSize = saved.pageSize;
                if (Number.isInteger(saved.page) && saved.page > 0) this.page = saved.page;
            }
            this.$watch('sortKey', () => this.persistTableState());
            this.$watch('sortDir', () => this.persistTableState());
            this.$watch('page', () => this.persistTableState());
            this.$watch('pageSize', () => this.persistTableState());
        },
        persistTableState() {
            if (!this.tsKey) return;
            wsTsWrite(this.tsKey, {
                sortKey: this.sortKey, sortDir: this.sortDir, page: this.page, pageSize: this.pageSize
            });
        },
        // Refresca llamando al método de carga del componente (reload o load).
        refresh() {
            if (typeof this.reload === 'function') this.reload();
            else if (typeof this.load === 'function') this.load();
        },
        sort(key) {
            if (this.sortKey === key) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortKey = key;
                this.sortDir = 'asc';
            }
            this.page = 1;
            this.refresh();
        },
        sortIcon(key) {
            if (this.sortKey !== key) return 'fa-sort';
            return this.sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
        },
        totalPages() { return Math.max(1, Math.ceil((this.total || 0) / this.pageSize)); },
        pages() {
            const t = this.totalPages();
            const out = [];
            for (let i = Math.max(1, this.page - 2); i <= Math.min(t, this.page + 2); i++) out.push(i);
            return out;
        },
        goPage(n) { this.page = n; this.refresh(); },
        prevPage() { this.goPage(this.page - 1); },
        nextPage() { this.goPage(this.page + 1); },
        changePageSize() { this.page = 1; this.refresh(); },
        onFilter() { this.page = 1; this.refresh(); },
        onSearch() {
            this.page = 1;
            clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => this.refresh(), 300);
        }
    });

    const registerAlpine = () => {
        Alpine.data('wsStore', (opts) => ({
            locationId: opts.locationId,
            deliveryCost: opts.deliveryCost,
            currency: opts.currency,
            baseCurrency: opts.baseCurrency || opts.currency,
            rates: opts.rates || {},
            currencies: opts.currencies || [],
            whatsappNumbers: opts.whatsappNumbers || [],
            products: [],
            search: '',
            searchTerm: '',
            // Categorías en árbol: filtro de la tienda. Al elegir una categoría
            // se muestran también los productos de TODA su rama de subcategorías.
            categoryTree: [],
            categoryOptions: [],
            categoryFilter: 0,
            cartOpen: false,
            step: 'cart',
            cartItems: [],
            customer: { name: '', phone: '', address: '', number: '' },
            busy: false,
            // Sonido opcional al añadir al carrito (persistido en localStorage).
            soundOn: true,
            // FAB del carrito (visible al hacer scroll).
            fabVisible: false,
            // Modal de producto.
            productOpen: false,
            activeProduct: null,
            modalQty: 1,
            // Valoraciones de la TIENDA (las estrellas valoran al negocio,
            // no a los productos individuales).
            storeReviews: [],
            storeRating: 0,
            showStoreReviewForm: false,
            reviewSubmitted: false,
            reviewBusy: false,
            alreadyReviewed: false,
            reviewForm: { customer_name: '', rating: 5, comment: '' },
            // Consulta de estado de pedido.
            trackOpen: false,
            trackNumber: '',
            trackPhone: '',
            trackBusy: false,
            trackResult: null,
            trackError: '',

            cartKey() { return 'ws_cart_' + this.locationId; },

            init() {
                const seed = window.WS_STORE_DATA || null;
                if (seed && seed.products) { this.products = seed.products; }
                if (seed && seed.categories) {
                    this.categoryTree = seed.categories.tree || [];
                    this.categoryOptions = (seed.categories.flat || []).map(c => ({ id: c.id, name: c.name }));
                }
                // Monedas/tasas/WhatsApp llegan por WS_STORE_DATA (JSON en un
                // <script>, no por el atributo x-data, para no romper el HTML).
                if (seed && seed.baseCurrency) { this.baseCurrency = seed.baseCurrency; }
                if (seed && seed.rates) { this.rates = seed.rates; }
                if (seed && seed.currencies) { this.currencies = seed.currencies; }
                if (seed && seed.whatsappNumbers) { this.whatsappNumbers = seed.whatsappNumbers; }
                this.loadCart();
                // Valoraciones de la tienda: se cargan al entrar (las estrellas
                // reflejan la opinión sobre el negocio, no sobre un producto).
                this.loadStoreReviews();
                try {
                    const s = localStorage.getItem('ws_cart_sound');
                    if (s !== null) this.soundOn = s === '1';
                } catch (e) {}
                this.onScroll();
                this._onScroll = () => this.onScroll();
                window.addEventListener('scroll', this._onScroll, { passive: true });
            },
            destroy() {
                if (this._onScroll) window.removeEventListener('scroll', this._onScroll);
            },
            onScroll() {
                this.fabVisible = window.scrollY > 120 || this.cartCount > 0;
            },
            // --- Persistencia del carrito en localStorage ---
            loadCart() {
                try {
                    const raw = localStorage.getItem(this.cartKey());
                    if (!raw) return;
                    const saved = JSON.parse(raw);
                    if (Array.isArray(saved.cartItems)) {
                        // Re-sincroniza precios/nombre con el catálogo actual y respeta stock.
                        // Descarta ítems cuyo producto ya no está en esta tienda.
                        this.cartItems = saved.cartItems.map(item => {
                            const p = this.products.find(x => x.id === item.product_id);
                            if (!p) return null;
                            return Object.assign({}, item, { name: p.name, price: p.price, currency: p.currency || this.currency, qty: Math.min(item.qty, p.qty) });
                        }).filter(i => i && i.qty > 0);
                    }
                    if (saved.customer && typeof saved.customer === 'object') {
                        this.customer = Object.assign({ name: '', phone: '', address: '', number: '' }, saved.customer);
                    }
                } catch (e) { /* carrito corrupto: se ignora */ }
            },
            saveCart() {
                try {
                    localStorage.setItem(this.cartKey(), JSON.stringify({ cartItems: this.cartItems, customer: this.customer }));
                } catch (e) {}
            },
            clearCart() {
                this.cartItems = [];
                this.customer = { name: '', phone: '', address: '' };
                try { localStorage.removeItem(this.cartKey()); } catch (e) {}
            },
            openCart() { this.step = 'cart'; this.cartOpen = true; },
            goCheckout() { if (this.cartItems.length) this.step = 'data'; },
            backToCart() { this.step = 'cart'; },
            get cartCount() { return this.cartItems.reduce((a, i) => a + i.qty, 0); },
            applySearch() {
                this.search = this.searchTerm.trim();
            },
            get filtered() {
                const s = this.search.toLowerCase().trim();
                const ids = this.categoryFilter ? this.categoryBranchIds(this.categoryFilter) : null;
                return this.products.filter(p => {
                    if (ids && !ids.has(Number(p.category_id) || 0)) return false;
                    if (!s) return true;
                    return (p.name || '').toLowerCase().includes(s) || (p.barcode || '').toLowerCase().includes(s);
                });
            },
            // IDs de una categoría y de todas sus subcategorías (ramas del árbol).
            categoryBranchIds(id) {
                const set = new Set([Number(id)]);
                const walk = (nodes) => {
                    (nodes || []).forEach(n => {
                        if (Number(n.id) === Number(id) || set.has(Number(n.parent_id))) {
                            set.add(Number(n.id));
                        }
                        if (n.children && n.children.length) walk(n.children);
                    });
                };
                walk(this.categoryTree);
                return set;
            },
            get subtotal() { return this.cartItems.reduce((a, i) => a + this.convert(i.price, i.currency || this.currency, this.currency) * i.qty, 0); },
            // Subtotal en transferencia: aplica el % de transferencia de cada producto.
            get subtotalTransfer() {
                return this.cartItems.reduce((a, i) => {
                    const p = this.products.find(x => x.id === i.product_id);
                    const pct = p ? (Number(p.transfer_pct) || 0) : 0;
                    const price = this.convert(i.price, i.currency || this.currency, this.currency);
                    return a + price * (1 + pct / 100) * i.qty;
                }, 0);
            },
            get delivery() { return this.cartItems.length ? this.deliveryCost : 0; },
            get total() { return this.subtotal + this.delivery; },
            get totalTransfer() { return this.subtotalTransfer + this.delivery; },
            price(v) { return money(v, this.currency); },
            moneyOf(v, c) { return money(v, c || this.currency); },
            // Convierte un monto de una moneda a otra usando la tasa configurada.
            convert(amount, from, to) {
                amount = Number(amount) || 0;
                if (!from || !to || from === to) return amount;
                const base = this.baseCurrency || to;
                let inBase;
                if (from === base) { inBase = amount; }
                else { const r = Number(this.rates[from]) || 0; inBase = r > 0 ? amount * r : amount; }
                if (to === base) return inBase;
                const rt = Number(this.rates[to]) || 0;
                return rt > 0 ? inBase / rt : inBase;
            },
            // Cantidad del producto en el carrito (0 si no está).
            inCart(id) {
                const item = this.cartItems.find(i => i.product_id === id);
                return item ? item.qty : 0;
            },
            // Etiqueta de stock: cantidad actual (unidades) o 'Agotado'.
            stockLabel(p) {
                const qty = Number(p && p.qty) || 0;
                return qty > 0 ? ('Stock: ' + qty) : 'Agotado';
            },
            // Precio en la moneda del producto + equivalencia en la otra moneda
            // configurada (p. ej. CUP ↔ USD), solo si el producto tiene activo
            // el check 'mostrar precio equivalente'.
            priceInfo(p) {
                const cur = p.currency || this.currency;
                const main = money(Number(p.price) || 0, cur);
                const show = p.show_equiv === undefined ? 1 : Number(p.show_equiv);
                if (!show) return { main: main, equiv: '' };
                // La otra moneda del par configurado (primera distinta a la del producto).
                const others = (this.currencies || []).filter(c => c && c !== cur);
                const other = others.length ? others[0] : (cur !== this.currency ? this.currency : '');
                if (!other) return { main: main, equiv: '' };
                return { main: main, equiv: '≈ ' + money(this.convert(p.price, cur, other), other) };
            },
            // --- Modal de producto ---
            openProduct(p) {
                this.activeProduct = p;
                this.modalQty = 1;
                this.productOpen = true;
            },
            closeProduct() { this.productOpen = false; this.activeProduct = null; },
            // --- Valoraciones de la tienda ---
            // Hash persistente del visitante: identifica al anónimo en el
            // servidor para que solo pueda enviar UNA reseña por tienda
            // (anti puntuación infinita). Se guarda en localStorage.
            clientHash() {
                let h = '';
                try { h = localStorage.getItem('ws_reviewer_hash'); } catch (e) {}
                if (!h) {
                    h = 'c_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);
                    try { localStorage.setItem('ws_reviewer_hash', h); } catch (e) {}
                }
                return h;
            },
            loadStoreReviews() {
                if (!this.locationId) return;
                this.reviewBusy = true;
                $('ws_reviews_get', { location_id: this.locationId, client_hash: this.clientHash() })
                    .then(res => {
                        if (res.success) {
                            // Normaliza SIEMPRE a array: el endpoint público
                            // devuelve data.data (array), pero se protege ante
                            // cualquier forma de respuesta para que el template
                            // (storeReviews.slice/length) nunca truene.
                            const raw = res.data && res.data.data !== undefined ? res.data.data : (res.data || []);
                            this.storeReviews = Array.isArray(raw) ? raw : [];
                            this.storeRating = (res.data && res.data.stats && res.data.stats.average) || 0;
                            this.alreadyReviewed = !!(res.data && res.data.already_reviewed);
                        }
                    })
                    .catch(err => console.error('Error cargando valoraciones:', err))
                    .then(() => { this.reviewBusy = false; });
            },
            formatReviewDate(v) {
                if (!v) return '';
                const d = new Date(String(v).replace(' ', 'T'));
                if (isNaN(d.getTime())) return '';
                return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
            },
            submitReview() {
                const form = this.reviewForm;
                if (!form.customer_name || !form.rating) {
                    toast('warning', 'Completa tu nombre y valoración');
                    return;
                }
                if (!this.locationId) return;
                if (this.alreadyReviewed) {
                    toast('info', 'Ya enviaste una reseña', 'Solo se permite una por persona.');
                    this.showStoreReviewForm = false;
                    return;
                }
                this.reviewBusy = true;
                $('ws_reviews_save', {
                    location_id: this.locationId,
                    customer_name: form.customer_name,
                    rating: form.rating,
                    comment: form.comment,
                    client_hash: this.clientHash()
                })
                    .then(res => {
                        if (res.success) {
                            toast('success', 'Reseña enviada', 'Se revisará antes de publicarse.');
                            this.showStoreReviewForm = false;
                            this.reviewSubmitted = true;
                            this.alreadyReviewed = true;
                            this.reviewForm = { customer_name: '', rating: 5, comment: '' };
                            // Si la reseña se reabrió (rechazada → pendiente),
                            // la tienda sigue sin publicar nada nuevo.
                            this.loadStoreReviews();
                        } else {
                            // Duplicado (u otro motivo): se bloquea el form y
                            // se muestra el motivo del servidor.
                            this.alreadyReviewed = true;
                            this.showStoreReviewForm = false;
                            toast('error', 'No se pudo enviar', res.data && res.data.msg);
                        }
                    })
                    .catch(() => toast('error', 'Error de red'))
                    .then(() => { this.reviewBusy = false; });
            },
            setModalQty(v) {
                let n = parseInt(v, 10);
                if (isNaN(n) || n < 1) n = 1;
                if (this.activeProduct && n > this.activeProduct.qty) n = this.activeProduct.qty || 1;
                this.modalQty = n;
            },
            addFromModal() {
                if (!this.activeProduct) return;
                const p = this.activeProduct;
                for (let i = 0; i < this.modalQty; i++) {
                    this.addOnce(p);
                }
                this.playAddSound();
                this.closeProduct();
            },
            // --- Sonido opcional al añadir ---
            toggleSound() {
                this.soundOn = !this.soundOn;
                try { localStorage.setItem('ws_cart_sound', this.soundOn ? '1' : '0'); } catch (e) {}
                // Al activarlo, reproduce un tono para confirmar.
                if (this.soundOn) this.playAddSound();
            },
            // Tono corto y agradable generado con Web Audio (sin archivos).
            playAddSound() {
                if (!this.soundOn) return;
                try {
                    const AC = window.AudioContext || window.webkitAudioContext;
                    if (!AC) return;
                    if (!this._audioCtx) this._audioCtx = new AC();
                    const ctx = this._audioCtx;
                    if (ctx.state === 'suspended') ctx.resume();
                    const now = ctx.currentTime;
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(880, now);
                    osc.frequency.exponentialRampToValueAtTime(1320, now + 0.09);
                    gain.gain.setValueAtTime(0.0001, now);
                    gain.gain.exponentialRampToValueAtTime(0.22, now + 0.012);
                    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.2);
                    osc.connect(gain).connect(ctx.destination);
                    osc.start(now);
                    osc.stop(now + 0.22);
                } catch (e) { /* audio no disponible: silencio */ }
            },
            addOnce(p) {
                const item = this.cartItems.find(i => i.product_id === p.id);
                if (item) {
                    if (item.qty + 1 > p.qty) { toast('warning', 'Stock insuficiente'); return false; }
                    item.qty++;
                } else {
                    this.cartItems.push({ product_id: p.id, name: p.name, price: p.price, currency: p.currency || this.currency, qty: 1 });
                }
                this.saveCart();
                this.pulseCard(p.id);
                return true;
            },
            // Pulso/rebote sutil en la tarjeta del producto recién agregado.
            pulseCard(id) {
                const card = this.$root.querySelector('.ws-product-card[data-pid="' + id + '"]');
                if (!card) return;
                // Timer único: al re-agregar rápido no se corta la animación.
                if (this._popTimer) clearTimeout(this._popTimer);
                card.classList.remove('ws-card-pop');
                // Fuerza reflow para reiniciar la animación si se agrega de nuevo.
                void card.offsetWidth;
                card.classList.add('ws-card-pop');
                this._popTimer = setTimeout(() => card.classList.remove('ws-card-pop'), 650);
            },
            add(p) {
                if (!this.addOnce(p)) return;
                toast('success', 'Añadido', p.name);
                this.playAddSound();
            },
            changeQty(item, delta) {
                const p = this.products.find(x => x.id === item.product_id);
                const next = item.qty + delta;
                if (next <= 0) { this.removeItem(item.product_id); return; }
                if (p && next > p.qty) { toast('warning', 'Stock insuficiente'); return; }
                item.qty = next;
                this.saveCart();
            },
            setQty(item, value) {
                const p = this.products.find(x => x.id === item.product_id);
                let next = parseInt(value, 10);
                if (isNaN(next) || next < 1) { next = 1; }
                if (p && next > p.qty) { toast('warning', 'Stock insuficiente'); next = p.qty; }
                item.qty = next;
                this.saveCart();
            },
            removeItem(id) { this.cartItems = this.cartItems.filter(i => i.product_id !== id); this.saveCart(); },
            stockOf(id) {
                const p = this.products.find(x => x.id === id);
                return p ? p.qty : 9999;
            },
            // --- Consulta de estado de pedido ---
            toggleTrack() { this.trackOpen = !this.trackOpen; },
            trackStatus() {
                if (this.trackBusy) return;
                if (!this.trackNumber.trim() || !this.trackPhone.trim()) {
                    this.trackError = 'Ingresa el número de pedido y tu teléfono.';
                    this.trackResult = null;
                    return;
                }
                this.trackBusy = true;
                this.trackError = '';
                this.trackResult = null;
                $('ws_public_order_status', { number: this.trackNumber, phone: this.trackPhone })
                    .then(res => {
                        if (res.success) { this.trackResult = res.data.order; }
                        else { this.trackError = (res.data && res.data.msg) || 'Error al consultar.'; }
                    })
                    .catch(() => { this.trackError = 'Error de conexión.'; })
                    .finally(() => { this.trackBusy = false; });
            },
            checkout() {
                if (this.busy) return;
                const items = {};
                this.cartItems.forEach(i => { items[i.product_id] = i.qty; });
                this.busy = true;
                const body = new URLSearchParams({
                    action: 'ws_create_order',
                    ws_nonce: WS.nonce,
                    location_id: this.locationId,
                    customer_name: this.customer.name,
                    customer_phone: this.customer.phone,
                    customer_address: this.customer.address,
                    whatsapp_number: this.customer.number || '',
                });
                Object.keys(items).forEach(k => body.append('items[' + k + ']', items[k]));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            this.clearCart();
                            if (res.data.whatsapp_url) {
                                window.open(res.data.whatsapp_url, '_blank', 'noopener');
                            }
                            window.location.href = WS.home + 'tienda/' + (opts.slug || '') + '/pedido/' + res.data.id + '/';
                        } else {
                            toast('error', 'Error', res.data && res.data.msg);
                        }
                    })
                    .catch(() => toast('error', 'Error de red'))
                    .finally(() => { this.busy = false; });
            }
        }));

        /* Productos */
        Alpine.data('wsProducts', (opts) => ({
            ...tableState('products'),
            suppliers: opts.suppliers || [],
            currency: opts.currency,
            currencies: opts.currencies || [],
            categories: opts.categories || [],
            canEdit: opts.canEdit,
            canDelete: opts.canDelete,
            canCreate: opts.canCreate,
            canFraction: opts.canFraction,
            products: [],
            search: '',
            formOpen: false,
            importModal: false,
            dragOver: false,
            form: {},
            // Pestañas: lista de productos / historial de precios.
            tab: 'products',
            historyLoaded: false,
            historyRows: [],
            historySearch: '',
            historySortKey: '',
            historySortDir: 'desc',
            historyPage: 1,
            historyPageSize: 10,
            historyTotal: 0,

            init() {
                this.restoreTableState();
                this.reload();
                // Restaura el estado del historial guardado en localStorage.
                const hs = wsTsRead('price_history');
                if (hs) {
                    if (typeof hs.sortKey === 'string' && hs.sortKey) this.historySortKey = hs.sortKey;
                    if (hs.sortDir === 'asc' || hs.sortDir === 'desc') this.historySortDir = hs.sortDir;
                    if ([10, 25, 50, 100].includes(hs.pageSize)) this.historyPageSize = hs.pageSize;
                    if (Number.isInteger(hs.page) && hs.page > 0) this.historyPage = hs.page;
                }
            },
            setTab(t) {
                this.tab = t;
                if (t === 'history' && !this.historyLoaded) this.loadHistory();
            },
            loadHistory() {
                this.historyRows = [];
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_price_history_list', ws_nonce: WS.nonce, search: this.historySearch, sort: this.historySortKey, dir: this.historySortDir, page: this.historyPage, pageSize: this.historyPageSize }) })
                    .then(r => r.json()).then(r => {
                        if (r.success) {
                            this.historyRows = r.data.history;
                            this.historyTotal = r.data.total;
                            this.historyPage = r.data.page;
                            this.historyLoaded = true;
                            // Persiste después de que el servidor ajusta la página.
                            wsTsWrite('price_history', { sortKey: this.historySortKey, sortDir: this.historySortDir, page: this.historyPage, pageSize: this.historyPageSize });
                        }
                    });
            },
            historySort(key) {
                if (this.historySortKey === key) {
                    this.historySortDir = this.historySortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.historySortKey = key;
                    this.historySortDir = 'asc';
                }
                this.historyPage = 1;
                this.loadHistory();
            },
            historySortIcon(key) {
                if (this.historySortKey !== key) return 'fa-sort';
                return this.historySortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
            },
            historyTotalPages() { return Math.max(1, Math.ceil((this.historyTotal || 0) / this.historyPageSize)); },
            historyPages() {
                const t = this.historyTotalPages();
                const out = [];
                for (let i = Math.max(1, this.historyPage - 2); i <= Math.min(t, this.historyPage + 2); i++) out.push(i);
                return out;
            },
            historyGo(n) { this.historyPage = n; this.loadHistory(); },
            historyPrev() { this.historyGo(this.historyPage - 1); },
            historyNext() { this.historyGo(this.historyPage + 1); },
            historyChangePageSize() { this.historyPage = 1; this.loadHistory(); },
            historyOnSearch() {
                this.historyPage = 1;
                clearTimeout(this._historySearchTimer);
                this._historySearchTimer = setTimeout(() => this.loadHistory(), 300);
            },
            // Dirección de la fluctuación de precio: devuelve el string completo
            // de clases (flecha + color) para :class, '' si no cambió.
            priceTrend(a, b) {
                a = Number(a) || 0; b = Number(b) || 0;
                if (b > a) return 'fa-solid fa-arrow-trend-up ws-text-success';
                if (b < a) return 'fa-solid fa-arrow-trend-down ws-text-danger';
                return '';
            },
            reload() {
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_products_list', ws_nonce: WS.nonce, search: this.search, sort: this.sortKey, dir: this.sortDir, page: this.page, pageSize: this.pageSize }) })
                    .then(r => r.json()).then(r => { if (r.success) { this.products = r.data.products; this.total = r.data.total; this.page = r.data.page; } });
            },
            money(v, c) { return money(v, c || this.currency); },
            // Formatea YYYY-MM-DD (input date / BD) a DD/MM/YYYY para la tabla.
            fmtDate(v) {
                if (!v) return '';
                const p = String(v).split('-');
                if (p.length !== 3) return v;
                return p[2] + '/' + p[1] + '/' + p[0];
            },
            openForm(p) {
                this.form = p ? Object.assign({}, p) : { name: '', barcode: '', category_id: 0, description: '', image: '', cost_price: 0, sale_price: 0, transfer_pct: 0, currency: this.currency, show_equiv: 1, supplier_id: 0, min_stock: 0, production_date: '', expiry_date: '', fraction_parent: 0, fraction_qty: 0 };
                if (this.form.category_id === undefined) this.form.category_id = 0;
                if (!this.form.currency) this.form.currency = this.currency;
                if (this.form.show_equiv === undefined) this.form.show_equiv = 1;
                if (this.form.fraction_parent === undefined) this.form.fraction_parent = 0;
                if (this.form.fraction_qty === undefined) this.form.fraction_qty = 0;
                if (!this.currencies.length || this.currencies.indexOf(this.form.currency) === -1) {
                    this.currencies = this.currencies.concat([this.form.currency]);
                }
                this.formOpen = true;
            },
            parentCandidates() {
                // Candidatos a "producto madre": productos que no son hijos.
                return (this.products || []).filter(p => !p.fraction_parent && Number(p.id) !== Number(this.form.id || 0));
            },
            clone(p) {
                // Clona conservando barcode; el servidor añade sufijo si ya existe.
                this.form = Object.assign({}, p);
                delete this.form.id;
                this.form.name = (p.name || '') + ' (copia)';
                this.formOpen = true;
                toast('info', 'Clonado', 'Revisa y guarda el nuevo producto');
            },
            save() {
                const body = new URLSearchParams({ action: 'ws_save_product', ws_nonce: WS.nonce });
                Object.keys(this.form).forEach(k => body.append(k, this.form[k] === null || this.form[k] === undefined ? '' : this.form[k]));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            toast('success', 'Guardado');
                            // Fraccionamiento: muestra la conversión de stock
                            // realizada (1 unidad del padre -> N del hijo).
                            const f = res.data && res.data.fraction;
                            if (f && f.converted > 0 && f.locations && f.locations.length) {
                                const num = v => Number(v).toLocaleString('es-ES', { maximumFractionDigits: 4 });
                                const parts = f.locations.map(l => (l.location_name || 'Ubicación') + ': -' + num(l.parent_qty) + ' → +' + num(l.child_qty));
                                toast('info', 'Fraccionamiento', 'Stock convertido del producto madre: ' + parts.join(' · '));
                            } else if (f && f.attempted) {
                                toast('warning', 'Fraccionamiento', f.error || 'El producto madre no tiene stock para convertir. El hijo queda sin stock hasta que se dé entrada.');
                            }
                            this.formOpen = false;
                            this.reload();
                            if (this.historyLoaded) this.loadHistory();
                        } else { toast('error', 'Error', res.data && res.data.msg); }
                    });
            },
            remove(p) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: '¿Eliminar producto?', text: p.name, icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar' })
                        .then(r => { if (r.isConfirmed) this.removeReq(p); });
                } else { this.removeReq(p); }
            },
            removeReq(p) {
                $('ws_delete_product', { id: p.id }).then(res => {
                    if (res.success) { toast('success', 'Eliminado'); this.reload(); } else { toast('error', 'Error', res.data && res.data.msg); }
                });
            },
            handleDrop(e) {
                this.dragOver = false;
                const file = e.dataTransfer && e.dataTransfer.files[0];
                if (file) this.readCsv(file);
            },
            importCsv(input) {
                if (input.files && input.files[0]) this.readCsv(input.files[0]);
            },
            readCsv(file) {
                const reader = new FileReader();
                reader.onload = (ev) => {
                    const lines = ev.target.result.split(/\r?\n/).filter(l => l.trim() !== '');
                    if (lines.length < 2) { toast('warning', 'CSV vacío'); return; }
                    const rows = [];
                    for (let i = 1; i < lines.length; i++) {
                        const c = lines[i].split(',');
                        rows.push({
                            name: (c[0] || '').trim(),
                            barcode: (c[1] || '').trim(),
                            description: (c[2] || '').trim(),
                            cost_price: parseFloat(c[3]) || 0,
                            sale_price: parseFloat(c[4]) || 0,
                            transfer_pct: parseFloat(c[5]) || 0,
                            currency: (c[6] || '').trim() || this.currency,
                            supplier_id: parseInt(c[7]) || 0,
                            min_stock: parseFloat(c[8]) || 0,
                            image: (c[9] || '').trim()
                        });
                    }
                    $('ws_import_products', { rows: JSON.stringify(rows) }).then(res => {
                        if (res.success) {
                            toast('success', 'Importados: ' + res.data.created);
                            if (res.data.errors && res.data.errors.length) {
                                if (typeof Swal !== 'undefined') Swal.fire({ title: 'Errores', text: res.data.errors.join('\n'), icon: 'warning' });
                            }
                            this.importModal = false;
                            this.reload();
                        } else { toast('error', 'Error', res.data && res.data.msg); }
                    });
                };
                reader.readAsText(file);
            }
        }));

        /* Ubicaciones */
        Alpine.data('wsLocations', (opts) => ({
            ...tableState('locations'),
            currency: opts.currency,
            currencies: opts.currencies || [],
            canManage: opts.canManage,
            locations: [],
            search: '',
            formOpen: false,
            form: {},
            init() { this.restoreTableState(); this.reload(); },
            reload() {
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_locations_list', ws_nonce: WS.nonce, search: this.search, sort: this.sortKey, dir: this.sortDir, page: this.page, pageSize: this.pageSize }) })
                    .then(r => r.json()).then(r => { if (r.success) { this.locations = r.data.locations; this.total = r.data.total; this.page = r.data.page; } });
            },
            money(v) { return money(v, this.currency); },
            save() {
                const body = new URLSearchParams({ action: 'ws_save_location', ws_nonce: WS.nonce, payment_methods: JSON.stringify(this.form.payment_methods || []) });
                Object.keys(this.form).forEach(k => {
                    if (k === 'payment_methods') return;
                    body.append(k, this.form[k] === null || this.form[k] === undefined ? '' : this.form[k]);
                });
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) { toast('success', 'Guardado'); this.formOpen = false; this.reload(); }
                        else { toast('error', 'Error', res.data && res.data.msg); }
                    });
            },
            remove(l) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: '¿Eliminar ubicación?', text: 'Se eliminará también su stock.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar' })
                        .then(r => { if (r.isConfirmed) this.removeReq(l); });
                } else { this.removeReq(l); }
            },
            removeReq(l) {
                $('ws_delete_location', { id: l.id }).then(res => {
                    if (res.success) { toast('success', 'Ubicación eliminada'); this.reload(); } else { toast('error', 'Error', res.data && res.data.msg); }
                });
            },
            openForm(l) {
                if (l) {
                    this.form = Object.assign({}, l, { payment_methods: (l.payment_methods && l.payment_methods.length ? l.payment_methods : []) });
                } else {
                    this.form = { type: 'pv', name: '', slug: '', address: '', photo: '', currency: this.currency, whatsapp: '', delivery_cost: 0, active: true, payment_methods: [] };
                }
                if (!this.form.currency) this.form.currency = this.currency;
                if (!this.currencies.length || this.currencies.indexOf(this.form.currency) === -1) {
                    this.currencies = this.currencies.concat([this.form.currency]);
                }
                this.formOpen = true;
            },
            onNameInput() {
                // Autocompleta el slug solo cuando el usuario no lo ha tocado.
                if (!this.form.slug && this.form.name) {
                    this.form.slug = slugify(this.form.name);
                }
            },
            storeUrlPreview(slug) {
                const s = slug || this.form.slug || slugify(this.form.name) || 'mi-tienda';
                // Muestra la URL final ya normalizada.
                return WS.home + 'tienda/' + slugify(s) + '/';
            },
            storeUrl(slug) { return WS.home + 'tienda/' + slug + '/'; }
        }));

        /* Proveedores */
        Alpine.data('wsSuppliers', (opts) => ({
            ...tableState('suppliers'),
            canManage: opts.canManage,
            suppliers: [],
            search: '',
            formOpen: false,
            form: {},
            init() { this.restoreTableState(); this.reload(); },
            reload() {
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_suppliers_list', ws_nonce: WS.nonce, search: this.search, sort: this.sortKey, dir: this.sortDir, page: this.page, pageSize: this.pageSize }) })
                    .then(r => r.json()).then(r => { if (r.success) { this.suppliers = r.data.suppliers; this.total = r.data.total; this.page = r.data.page; } });
            },
            openForm(x) {
                this.form = x ? Object.assign({}, x) : { name: '', phone: '', address: '', country: '', province: '' };
                this.formOpen = true;
            },
            save() {
                const body = new URLSearchParams({ action: 'ws_save_supplier', ws_nonce: WS.nonce });
                Object.keys(this.form).forEach(k => body.append(k, this.form[k] || ''));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) { toast('success', 'Guardado'); this.formOpen = false; this.reload(); } else { toast('error', 'Error', res.data && res.data.msg); } });
            },
            remove(x) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: '¿Eliminar proveedor?', text: x.name, icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar' })
                        .then(r => { if (r.isConfirmed) this.removeReq(x); });
                } else { this.removeReq(x); }
            },
            removeReq(x) {
                $('ws_delete_supplier', { id: x.id }).then(res => {
                    if (res.success) { toast('success', 'Proveedor eliminado'); this.reload(); } else { toast('error', 'Error', res.data && res.data.msg); }
                });
            }
        }));

        /* Stock */
        Alpine.data('wsStock', (opts) => ({
            ...tableState('stock'),
            locations: opts.locations || [],
            currency: opts.currency,
            canEntry: opts.canEntry,
            canExit: opts.canExit,
            canWriteoff: opts.canWriteoff,
            canTransfer: opts.canTransfer,
            rows: [],
            search: '',
            locationId: '',
            lowOnly: false,
            moveOpen: false,
            transferOpen: false,
            moveType: 'entrada',
            moveProduct: {},
            move: {},
            transfer: {},
            wizOpen: false,
            wizStep: 1,
            wizType: 'entrada',
            wizLocation: '',
            wizFrom: '',
            wizTo: '',
            wizProducts: [],
            wizItems: [],
            wizSearch: '',
            wizRef: '',
            wizNote: '',
            wizLoading: false,
            init() { this.restoreTableState(); this.load(); },
            load() {
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_stock_list', ws_nonce: WS.nonce, location_id: this.locationId, search: this.search, low_only: this.lowOnly ? 1 : 0, sort: this.sortKey, dir: this.sortDir, page: this.page, pageSize: this.pageSize }) })
                    .then(r => r.json()).then(r => { if (r.success) { this.rows = r.data.rows; this.total = r.data.total; this.page = r.data.page; } });
            },
            get canAnyMove() { return this.canEntry || this.canExit || this.canWriteoff || this.canTransfer; },
            get wizFiltered() {
                const s = this.wizSearch.toLowerCase().trim();
                if (!s) return this.wizProducts;
                return this.wizProducts.filter(p => p.name.toLowerCase().includes(s) || (p.barcode || '').toLowerCase().includes(s));
            },
            get wizSelectedCount() { return this.wizItems.length; },
            get wizHasStockProducts() { return this.wizType === 'entrada' || this.wizProducts.some(p => p.stock > 0); },
            money(v, c) { return money(v, c || this.currency); },
            typeLabel(t) { const m = { entrada: 'Entrada', salida: 'Salida', baja: 'Baja', traslado: 'Traslado' }; return m[t] || t; },
            locationName(id) { const l = this.locations.find(l => l.id === Number(id)); return l ? l.name : '—'; },
            openMove(type, row) {
                this.moveType = type;
                this.moveProduct = row;
                this.move = { product_id: row.product_id, location_id: row.location_id, qty: '', reference: '', note: '' };
                this.moveOpen = true;
            },
            openTransfer(row) {
                this.moveProduct = row;
                this.transfer = { product_id: row.product_id, from_location: row.location_id, to_location: '', qty: '', note: '' };
                this.transferOpen = true;
            },
            openWizard() {
                this.wizOpen = true;
                this.wizStep = 1;
                this.wizType = this.canEntry ? 'entrada' : (this.canExit ? 'salida' : (this.canWriteoff ? 'baja' : 'traslado'));
                this.wizLocation = '';
                this.wizFrom = '';
                this.wizTo = '';
                this.wizProducts = [];
                this.wizItems = [];
                this.wizSearch = '';
                this.wizRef = '';
                this.wizNote = '';
            },
            wizNext() {
                if (this.wizStep === 1) {
                    if (this.wizType === 'traslado') {
                        if (!this.wizFrom || !this.wizTo) { toast('error', 'Selecciona origen y destino'); return; }
                        if (this.wizFrom === this.wizTo) { toast('error', 'Origen y destino deben ser distintos'); return; }
                    } else if (!this.wizLocation) {
                        toast('error', 'Selecciona una ubicación'); return;
                    }
                    this.wizStep = 2;
                    this.loadWizProducts();
                } else if (this.wizStep === 2) {
                    if (!this.wizItems.length) { toast('error', 'Selecciona al menos un producto'); return; }
                    this.wizStep = 3;
                }
            },
            wizBack() { if (this.wizStep > 1) this.wizStep--; },
            loadWizProducts() {
                this.wizLoading = true;
                this.wizProducts = [];
                this.wizItems = [];
                if (this.wizType === 'entrada') {
                    $('ws_products_list', {}).then(r => {
                        this.wizLoading = false;
                        if (!r.success) { toast('error', 'Error', r.data && r.data.msg); return; }
                        this.wizProducts = r.data.products.map(p => ({ product_id: p.id, name: p.name, barcode: p.barcode, image: p.image, stock: 0, sale_price: p.sale_price, qty: 1, selected: false }));
                    });
                } else {
                    const loc = this.wizType === 'traslado' ? this.wizFrom : this.wizLocation;
                    $('ws_stock_list', { location_id: loc, search: '', low_only: 0 }).then(r => {
                        this.wizLoading = false;
                        if (!r.success) { toast('error', 'Error', r.data && r.data.msg); return; }
                        this.wizProducts = r.data.rows.filter(p => p.qty > 0).map(p => ({ product_id: p.product_id, name: p.name, barcode: p.barcode, image: p.image, stock: p.qty, sale_price: p.sale_price, qty: 1, selected: false }));
                    });
                }
            },
            toggleWiz(p) {
                if (p.selected) {
                    p.selected = false;
                    this.wizItems = this.wizItems.filter(i => i.product_id !== p.product_id);
                } else {
                    p.selected = true;
                    p.qty = 1;
                    this.wizItems.push(p);
                }
            },
            wizQty(p) {
                if (p.qty < 1) p.qty = 1;
                if (this.wizType !== 'entrada' && p.qty > p.stock) p.qty = p.stock;
            },
            wizSubmit() {
                const items = this.wizItems.map(i => ({ product_id: i.product_id, qty: i.qty }));
                if (this.wizType === 'traslado') {
                    $('ws_stock_batch_transfer', { from_location: this.wizFrom, to_location: this.wizTo, note: this.wizNote, items: JSON.stringify(items) }).then(res => {
                        if (res.success) { toast('success', 'Transferencia realizada'); this.wizOpen = false; this.load(); }
                        else { toast('error', 'Error', res.data && res.data.msg); }
                    });
                } else {
                    $('ws_stock_batch_move', { type: this.wizType, location_id: this.wizLocation, reference: this.wizRef, note: this.wizNote, items: JSON.stringify(items) }).then(res => {
                        if (res.success) { toast('success', 'Movimiento registrado'); this.wizOpen = false; this.load(); }
                        else { toast('error', 'Error', res.data && res.data.msg); }
                    });
                }
            },
            doMove() {
                const body = new URLSearchParams({ action: 'ws_stock_move', ws_nonce: WS.nonce, type: this.moveType });
                Object.keys(this.move).forEach(k => body.append(k, this.move[k]));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) { toast('success', 'Movimiento registrado'); this.moveOpen = false; this.load(); } else { toast('error', 'Error', res.data && res.data.msg); } });
            },
            doTransfer() {
                const body = new URLSearchParams({ action: 'ws_stock_transfer', ws_nonce: WS.nonce });
                Object.keys(this.transfer).forEach(k => body.append(k, this.transfer[k]));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) { toast('success', 'Transferencia realizada'); this.transferOpen = false; this.load(); } else { toast('error', 'Error', res.data && res.data.msg); } });
            }
        }));

        /* Movimientos */
        Alpine.data('wsMovements', (opts) => ({
            ...tableState('movements'),
            init() { this.restoreTableState(); this.load(); },
            locations: opts.locations || [],
            currency: opts.currency || '€',
            canEntry: opts.canEntry,
            canExit: opts.canExit,
            canVenta: opts.canVenta,
            sellers: opts.sellers || [],
            movements: [],
            products: [],
            addOpen: false,
            saving: false,
            form: {},
            search: '',
            typeFilter: '',
            locationFilter: '',
            load() {
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_movements_list', ws_nonce: WS.nonce, type: this.typeFilter, location_id: this.locationFilter, search: this.search, sort: this.sortKey, dir: this.sortDir, page: this.page, pageSize: this.pageSize }) })
                    .then(r => r.json()).then(r => { if (r.success) { this.movements = r.data.movements; this.total = r.data.total; this.page = r.data.page; } });
            },
            typeLabel(t) {
                const m = { entrada: 'Entrada', salida: 'Salida', baja: 'Baja', transferencia: 'Transferencia', pedido: 'Pedido', venta: 'Venta' };
                return m[t] || t;
            },
            get canAnyMove() { return this.canEntry || this.canExit || this.canVenta; },
            openAdd() {
                this.form = { kind: 'entrada', customType: '', direction: 'entrada', product_id: 0, location_id: 0, seller_id: 0, qty: 0, price: 0, reference: '', note: '' };
                this.addOpen = true;
                this.loadProducts();
            },
            loadProducts() {
                if (this.products.length) return;
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_cache_products', ws_nonce: WS.nonce }) })
                    .then(r => r.json()).then(r => { if (r.success) this.products = (r.data && r.data.data) || []; });
            },
            onKindChange() {
                if (this.form.kind === 'entrada') this.form.direction = 'entrada';
                if (this.form.kind === 'salida' || this.form.kind === 'baja') this.form.direction = 'salida';
                if (this.form.kind !== 'venta' && this.form.price) this.form.price = 0;
                if (this.form.kind === 'venta' && this.form.product_id) this.onProductChange();
            },
            onProductChange() {
                const p = this.products.find(x => Number(x.id) === Number(this.form.product_id));
                if (p) this.form.price = Number(p.sale_price) || 0;
            },
            doAdd() {
                if (this.saving) return;
                if (this.form.kind === 'otro' && !this.form.customType.trim()) { toast('error', 'Error', 'Escribe el tipo de movimiento personalizado.'); return; }
                if (!this.form.product_id || !this.form.location_id || Number(this.form.qty) <= 0) { toast('error', 'Error', 'Completa producto, ubicación y cantidad.'); return; }
                if (this.form.kind === 'venta' && (!this.form.seller_id || Number(this.form.price) < 0)) { toast('error', 'Error', 'Completa vendedor y precio.'); return; }
                this.saving = true;
                const isVenta = this.form.kind === 'venta';
                const body = new URLSearchParams({ action: isVenta ? 'ws_movement_venta' : 'ws_movement_add', ws_nonce: WS.nonce });
                if (isVenta) {
                    body.append('product_id', this.form.product_id);
                    body.append('location_id', this.form.location_id);
                    body.append('seller_id', this.form.seller_id || 0);
                    body.append('qty', this.form.qty);
                    body.append('price', this.form.price);
                    body.append('reference', this.form.reference || '');
                    body.append('note', this.form.note || '');
                } else {
                    body.append('direction', this.form.direction);
                    body.append('type', this.form.kind === 'otro' ? this.form.customType : this.form.kind);
                    body.append('product_id', this.form.product_id);
                    body.append('location_id', this.form.location_id);
                    body.append('qty', this.form.qty);
                    body.append('reference', this.form.reference || '');
                    body.append('note', this.form.note || '');
                }
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json()).then(r => {
                        this.saving = false;
                        if (r.success) {
                            toast('success', isVenta ? 'Venta registrada' : 'Movimiento registrado');
                            this.addOpen = false;
                            this.load();
                        } else {
                            toast('error', 'Error', r.data && r.data.msg);
                        }
                    }).catch(() => { this.saving = false; toast('error', 'Error', 'Sin conexión.'); });
            }
        }));

        /* Pedidos */
        Alpine.data('wsOrders', (opts) => ({
            ...tableState('orders'),
            init() { this.restoreTableState(); this.load(); },
            canAccept: opts.canAccept,
            orders: [],
            statusFilter: '',
            detailOpen: false,
            detail: {},
            load() {
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_order_list', ws_nonce: WS.nonce, status: this.statusFilter, sort: this.sortKey, dir: this.sortDir, page: this.page, pageSize: this.pageSize }) })
                    .then(r => r.json()).then(r => { if (r.success) { this.orders = r.data.orders; this.total = r.data.total; this.page = r.data.page; } });
            },
            money(v, c) { return money(v, c); },
            statusLabel(s) { const m = { pending: 'Pendiente', accepted: 'Aceptado', rejected: 'Rechazado', cancelled: 'Cancelado', completed: 'Completado' }; return m[s] || s; },
            view(o) {
                $('ws_order_detail', { id: o.id }).then(res => {
                    if (res.success) { this.detail = Object.assign({}, res.data.order); this.detailOpen = true; }
                    else { toast('error', 'Error', res.data && res.data.msg); }
                });
            },
            accept(o) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: 'Aceptar pedido', text: 'Se descontará el stock automáticamente.', icon: 'question', showCancelButton: true, confirmButtonText: 'Aceptar', cancelButtonText: 'Cancelar' })
                        .then(r => { if (r.isConfirmed) this.acceptReq(o); });
                } else { this.acceptReq(o); }
            },
            acceptReq(o) {
                $('ws_order_accept', { id: o.id }).then(res => { if (res.success) { toast('success', 'Pedido aceptado'); this.load(); } else { toast('error', 'Error', res.data && res.data.msg); } });
            },
            reject(o) {
                $('ws_order_reject', { id: o.id }).then(res => { if (res.success) { toast('success', 'Pedido rechazado'); this.load(); } });
            },
            complete(o) {
                $('ws_order_complete', { id: o.id }).then(res => { if (res.success) { toast('success', 'Pedido completado'); this.load(); } });
            }
        }));

        /* Turnos */
        Alpine.data('wsShifts', (opts) => ({
            locations: opts.locations || [],
            workers: opts.workers || [],
            canManage: opts.canManage,
            locationFilter: '',
            calendar: null,
            shiftOpen: false,
            shift: {},
            initCalendar() {
                const el = document.getElementById('ws-calendar');
                if (!el || typeof FullCalendar === 'undefined') return;
                this.calendar = new FullCalendar.Calendar(el, {
                    locale: 'es',
                    initialView: 'dayGridMonth',
                    headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek' },
                    events: (info, success) => {
                        fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'ws_shifts_list', ws_nonce: WS.nonce, start: info.startStr, end: info.endStr, location_id: this.locationFilter }) })
                            .then(r => r.json()).then(r => { success(r.data && r.data.shifts ? r.data.shifts : []); });
                    },
                    eventClick: (info) => {
                        if (!this.canManage) return;
                        const e = info.event.extendedProps;
                        this.shift = { id: info.event.id, location_id: e.location_id, user_id: e.user_id, shift_date: e.shift_date, time_start: e.time_start, time_end: e.time_end, note: e.note || '' };
                        this.shiftOpen = true;
                    },
                    dateClick: (info) => {
                        if (!this.canManage) return;
                        this.shift = { id: 0, location_id: parseInt(this.locationFilter) || (this.locations[0] ? this.locations[0].id : 0), user_id: this.workers[0] ? this.workers[0].id : 0, shift_date: info.dateStr, time_start: '08:00', time_end: '16:00', note: '' };
                        this.shiftOpen = true;
                    }
                });
                this.calendar.render();
            },
            calendarReload() { if (this.calendar) this.calendar.refetchEvents(); },
            saveShift() {
                const body = new URLSearchParams({ action: 'ws_save_shift', ws_nonce: WS.nonce });
                Object.keys(this.shift).forEach(k => body.append(k, this.shift[k]));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) { toast('success', 'Turno guardado'); this.shiftOpen = false; this.calendarReload(); } else { toast('error', 'Error', res.data && res.data.msg); } });
            },
            deleteShift() {
                $('ws_delete_shift', { id: this.shift.id }).then(res => { if (res.success) { toast('success', 'Turno eliminado'); this.shiftOpen = false; this.calendarReload(); } });
            }
        }));

        /* Trabajadores */
        Alpine.data('wsWorkers', (opts) => ({
            roleOptions: opts.roleOptions || {},
            locations: opts.locations || [],
            newOpen: false,
            workerOpen: false,
            editOpen: false,
            newUser: {},
            workerUser: {},
            editUser: {},
            openNew() {
                this.newUser = { display_name: '', username: '', email: '', password: '', role: '', locations: [] };
                this.newOpen = true;
            },
            createWorker() {
                const body = new URLSearchParams({ action: 'ws_save_worker_user', ws_nonce: WS.nonce });
                Object.keys(this.newUser).forEach(k => { if (k === 'locations') return; body.append(k, this.newUser[k]); });
                (this.newUser.locations || []).forEach(id => body.append('locations[]', id));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) { toast('success', 'Trabajador creado'); this.newOpen = false; setTimeout(() => location.reload(), 600); } else { toast('error', 'Error', res.data && res.data.msg); } });
            },
            showWorker(id, wlocs) {
                this.workerUser = { user_id: id, name: '', locations: (wlocs || []).map(l => l.id) };
                this.workerOpen = true;
            },
            saveWorkerLocations() {
                const body = new URLSearchParams({ action: 'ws_save_worker', ws_nonce: WS.nonce, user_id: this.workerUser.user_id, role: this.workerUser.role || '' });
                (this.workerUser.locations || []).forEach(id => body.append('locations[]', id));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) { toast('success', 'Guardado'); this.workerOpen = false; setTimeout(() => location.reload(), 600); } else { toast('error', 'Error', res.data && res.data.msg); } });
            },
            saveWorker(id, role, wlocs) {
                const body = new URLSearchParams({ action: 'ws_save_worker', ws_nonce: WS.nonce, user_id: id, role: role });
                (wlocs || []).forEach(l => body.append('locations[]', l));
                return fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) toast('success', 'Rol actualizado'); else toast('error', 'Error', res.data && res.data.msg); });
            },
            editWorker(id, name, email, role, wlocs) {
                this.editUser = {
                    user_id: id,
                    display_name: name || '',
                    email: email || '',
                    role: role || '',
                    password: '',
                    locations: (wlocs || []).map(l => Number(l.id) || l.id)
                };
                this.editOpen = true;
            },
            saveEditWorker() {
                if (!this.editUser.user_id) return;
                const body = new URLSearchParams({ action: 'ws_update_worker', ws_nonce: WS.nonce, user_id: this.editUser.user_id, display_name: this.editUser.display_name, email: this.editUser.email, role: this.editUser.role || '', password: this.editUser.password });
                (this.editUser.locations || []).forEach(id => body.append('locations[]', id));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) { toast('success', 'Trabajador actualizado'); this.editOpen = false; setTimeout(() => location.reload(), 600); } else { toast('error', 'Error', res.data && res.data.msg); } });
            },
            deleteWorker(id, name) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: '¿Eliminar trabajador?', text: name || '', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar' })
                        .then(r => { if (r.isConfirmed) this.deleteWorkerReq(id); });
                } else { this.deleteWorkerReq(id); }
            },
            deleteWorkerReq(id) {
                $('ws_delete_worker', { user_id: id }).then(res => {
                    if (res.success) { toast('success', 'Trabajador eliminado'); setTimeout(() => location.reload(), 600); } else { toast('error', 'Error', res.data && res.data.msg); }
                });
            },
            closeSession(id) {
                const confirm = () => this.closeSessionReq(id);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: '¿Cerrar sesión de trabajo?', text: 'Se registrará la hora de salida del trabajador.', icon: 'question', showCancelButton: true, confirmButtonText: 'Cerrar', cancelButtonText: 'Cancelar' })
                        .then(r => { if (r.isConfirmed) confirm(); });
                } else { confirm(); }
            },
            closeSessionReq(id) {
                $('ws_session_close', { session_id: id }).then(res => {
                    if (res.success) { toast('success', 'Sesión cerrada'); setTimeout(() => location.reload(), 600); } else { toast('error', 'Error', res.data && res.data.msg); }
                });
            },
            setDisabled(id, name, disabled) {
                const confirm = () => {
                    $('ws_worker_set_disabled', { user_id: id, disabled: disabled ? 1 : 0 }).then(res => {
                        if (res.success) { toast('success', disabled ? 'Trabajador deshabilitado' : 'Trabajador habilitado'); setTimeout(() => location.reload(), 600); } else { toast('error', 'Error', res.data && res.data.msg); }
                    });
                };
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: (disabled ? '¿Deshabilitar' : '¿Habilitar') + ' a ' + (name || '') + '?', text: disabled ? 'Se cerrará su sesión, no podrá entrar y no se le registrarán más jornadas.' : 'Volverá a poder entrar con su cuenta.', icon: 'warning', showCancelButton: true, confirmButtonText: disabled ? 'Deshabilitar' : 'Habilitar', cancelButtonText: 'Cancelar' })
                        .then(r => { if (r.isConfirmed) confirm(); });
                } else { confirm(); }
            }
        }));

        /* Permisos */
        Alpine.data('wsPermissions', (opts) => ({
            matrix: opts.matrix || {},
            save() {
                const body = new URLSearchParams({ action: 'ws_save_permissions', ws_nonce: WS.nonce, matrix: JSON.stringify(this.matrix) });
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) toast('success', 'Permisos guardados'); else toast('error', 'Error', res.data && res.data.msg); });
            }
        }));

        /* Configuración */
        Alpine.data('wsSettings', (opts) => ({
            currency: opts.currency,
            currencies: opts.currencies || '',
            rates: opts.rates || {},
            rates_updated: opts.rates_updated || '',
            payment_methods: opts.payment_methods || [],
            whatsapp: opts.whatsapp || '',
            rateBusy: false,
            get currencyList() {
                return (this.currencies || '').split(',').map(c => c.trim()).filter(Boolean);
            },
            // Asegura que la moneda por defecto exista en la lista.
            syncCurrencies() {
                if (this.currencyList.indexOf(this.currency) === -1 && this.currencyList.length) {
                    this.currency = this.currencyList[0];
                }
            },
            initRates() {
                this.syncCurrencies();
            },
            // Consulta la tasa desde El Toque (scraper server-side).
            fetchRate() {
                if (this.rateBusy) return;
                this.rateBusy = true;
                $('ws_update_rate', {})
                    .then(res => {
                        this.rateBusy = false;
                        if (res.success) {
                            this.rates = Object.assign({}, res.data.rate || {});
                            this.rates_updated = res.data.updated || '';
                            toast('success', 'Tasa actualizada', '1 USD = ' + (res.data.rate.USD || res.data.rate.CUP ? (res.data.rate.USD || (res.data.rate.CUP ? (1 / res.data.rate.CUP).toFixed(2) : '')) : '') + ' ' + this.currency);
                        } else {
                            toast('error', 'El Toque', res.data && res.data.msg);
                        }
                    })
                    .catch(() => { this.rateBusy = false; toast('error', 'Error de red'); });
            },
            save() {
                const body = new URLSearchParams({ action: 'ws_save_settings', ws_nonce: WS.nonce, currency: this.currency, currencies: this.currencies, whatsapp: this.whatsapp });
                (this.payment_methods || []).forEach(m => body.append('payment_methods[]', m));
                Object.keys(this.rates || {}).forEach(k => { if (this.rates[k]) body.append('rates[' + k + ']', this.rates[k]); });
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) toast('success', 'Configuración guardada'); else toast('error', 'Error', res.data && res.data.msg); });
            }
        }));

        /* Notificaciones del navbar (todos los roles) */
        Alpine.data('wsNotifications', () => ({
            open: false,
            items: [],
            unread: 0,
            loading: false,
            // Sonido + brillo cuando llega una notificación nueva.
            soundOn: true,
            glow: false,
            _lastUnread: 0,
            _firstLoad: true,
            _reqToken: 0,
            init() {
                try {
                    const s = localStorage.getItem('ws_notif_sound');
                    if (s !== null) this.soundOn = s === '1';
                } catch (e) {}
                // Desbloquea el audio con el primer gesto del usuario: la
                // política de autoplay deja el AudioContext en 'suspended'
                // cuando se crea fuera de un clic (p. ej. desde el intervalo),
                // y resume() es asíncrono, así que sin esto el sonido de las
                // notificaciones que llegan por el poll no se escucharía.
                this._unlockAudio = () => {
                    try {
                        const AC = window.AudioContext || window.webkitAudioContext;
                        if (!AC) return;
                        if (!this._audioCtx) this._audioCtx = new AC();
                        if (this._audioCtx.state === 'suspended') this._audioCtx.resume();
                    } catch (e) { /* audio no disponible */ }
                    document.removeEventListener('pointerdown', this._unlockAudio);
                    document.removeEventListener('keydown', this._unlockAudio);
                };
                document.addEventListener('pointerdown', this._unlockAudio, { once: true });
                document.addEventListener('keydown', this._unlockAudio, { once: true });
                this.load();
                // Refresco periódico mientras esté abierta la página.
                this._timer = setInterval(() => this.load(), 90000);
            },
            destroy() {
                if (this._timer) clearInterval(this._timer);
                if (this._glowTimer) clearTimeout(this._glowTimer);
                if (this._unlockAudio) {
                    document.removeEventListener('pointerdown', this._unlockAudio);
                    document.removeEventListener('keydown', this._unlockAudio);
                }
            },
            load() {
                // Token de secuencia: si una respuesta antigua (p. ej. la del
                // init()) llega después de una más reciente, se descarta para
                // no sobrescribir datos nuevos con datos obsoletos.
                const token = ++this._reqToken;
                this.loading = true;
                $('ws_notifications_list', {})
                    .then(res => {
                        if (token !== this._reqToken) return;
                        if (!res.success) return;
                        this.items = res.data.items || [];
                        this.unread = Number(res.data.unread) || 0;
                        // Solo alerta cuando el contador sube respecto a la
                        // última carga (no al primer render de la página).
                        if (!this._firstLoad && this.unread > this._lastUnread) {
                            this.alertNew();
                        }
                        this._lastUnread = this.unread;
                        this._firstLoad = false;
                    })
                    .catch(() => {})
                    .finally(() => {
                        if (token === this._reqToken) { this.loading = false; }
                    });
            },
            // Brillo + sonido al llegar una notificación nueva.
            alertNew() {
                this.glow = true;
                if (this._glowTimer) clearTimeout(this._glowTimer);
                this._glowTimer = setTimeout(() => { this.glow = false; }, 2600);
                if (this.soundOn) this.playSound();
            },
            toggleSound() {
                this.soundOn = !this.soundOn;
                try { localStorage.setItem('ws_notif_sound', this.soundOn ? '1' : '0'); } catch (e) {}
                if (this.soundOn) this.playSound();
            },
            // Tono doble corto (campana) con Web Audio, sin archivos.
            playSound() {
                try {
                    const AC = window.AudioContext || window.webkitAudioContext;
                    if (!AC) return;
                    if (!this._audioCtx) this._audioCtx = new AC();
                    const ctx = this._audioCtx;
                    if (ctx.state === 'suspended') ctx.resume();
                    const now = ctx.currentTime;
                    [880, 1174.66].forEach((freq, i) => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        const t = now + i * 0.14;
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(freq, t);
                        gain.gain.setValueAtTime(0.0001, t);
                        gain.gain.exponentialRampToValueAtTime(0.18, t + 0.015);
                        gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.32);
                        osc.connect(gain).connect(ctx.destination);
                        osc.start(t);
                        osc.stop(t + 0.34);
                    });
                } catch (e) { /* audio no disponible: silencio */ }
            },
            toggle() {
                this.open = !this.open;
                if (this.open) { this.loading = true; this.load(); }
            },
            markAllRead() {
                $('ws_notifications_read', { all: 1 }).then(res => {
                    if (!res.success) return;
                    this.items.forEach(i => i.is_read = 1);
                    this.unread = 0;
                });
            },
            openItem(n) {
                if (n.is_read) return true;
                n.is_read = 1;
                this.unread = Math.max(0, this.unread - 1);
                $('ws_notifications_read', { ids: [n.id] });
                return true;
            },
            markRead(n) {
                if (n.is_read) return;
                n.is_read = 1;
                this.unread = Math.max(0, this.unread - 1);
                $('ws_notifications_read', { ids: [n.id] });
            },
            remove(n) {
                const before = n.is_read ? 0 : 1;
                this.items = this.items.filter(i => i.id !== n.id);
                this.unread = Math.max(0, this.unread - before);
                $('ws_notifications_delete', { ids: [n.id] });
            },
            iconOf(type) {
                const m = {
                    low_stock: 'fa-triangle-exclamation',
                    out_stock: 'fa-box-open',
                    pending_orders: 'fa-receipt',
                    new_order: 'fa-cart-plus',
                    order_accepted: 'fa-circle-check',
                    sales_today: 'fa-circle-dollar',
                    recent_movements: 'fa-clock-rotate-left',
                    top_product: 'fa-trophy',
                    top_supplier: 'fa-truck-fast',
                    stock_discrepancy: 'fa-cloud-arrow-down'
                };
                return m[type] || 'fa-bell';
            }
        }));

        /* Apariencia del sitio (vista previa en tiempo real) */
        Alpine.data('wsAppearance', (opts) => ({
            canSite: !!opts.canSite,
            canLayout: !!opts.canLayout,
            live: true,
            busy: false,
            name: opts.name || '',
            logo: opts.logo || '',
            favicon: opts.favicon || '',
            primary: opts.primary || '#4f46e5',
            accent: opts.accent || '#f59e0b',
            hero_badge: opts.hero_badge || '',
            hero_title: opts.hero_title || '',
            hero_sub: opts.hero_sub || '',
            hero_bg: opts.hero_bg || '',
            hero_gradient: opts.hero_gradient || '',
            footer_text: opts.footer_text || '',
            defaults: opts.defaults || {},
            init() {
                // Guarda los valores actuales para poder revertir la vista previa.
                this.saved = {
                    name: this.name, logo: this.logo, primary: this.primary,
                    accent: this.accent, hero_badge: this.hero_badge,
                    hero_title: this.hero_title, hero_sub: this.hero_sub,
                    hero_bg: this.hero_bg, hero_gradient: this.hero_gradient,
                    footer_text: this.footer_text
                };
                this.applyLive();
                // Reaplicar cuando cambien los valores mientras se edita.
                this.$watch('name', () => this.applyLive());
                this.$watch('logo', () => this.applyLive());
                this.$watch('primary', () => this.applyLive());
                this.$watch('accent', () => this.applyLive());
                this.$watch('hero_badge', () => this.applyLive());
                this.$watch('hero_title', () => this.applyLive());
                this.$watch('hero_sub', () => this.applyLive());
                this.$watch('hero_bg', () => this.applyLive());
                this.$watch('hero_gradient', () => this.applyLive());
                this.$watch('footer_text', () => this.applyLive());
                // Al desactivar la vista previa, vuelve a lo guardado.
                this.$watch('live', v => { if (!v) this.applySaved(); });
            },
            // Aplica un tema dado (valores actuales o guardados).
            applyTheme(t) {
                const r = document.documentElement;
                r.style.setProperty('--ws-primary', t.primary || '#4f46e5');
                r.style.setProperty('--ws-primary-dark', shadeHex(t.primary, -10));
                r.style.setProperty('--ws-primary-deep', shadeHex(t.primary, -22));
                r.style.setProperty('--ws-primary-light', shadeHex(t.primary, 26));
                r.style.setProperty('--ws-accent', t.accent || '#f59e0b');
                document.querySelectorAll('.ws-brand-name').forEach(el => { el.textContent = t.name || ''; });
                document.querySelectorAll('.ws-brand-img').forEach(img => {
                    if (t.logo) { img.src = t.logo; img.style.display = ''; }
                    else { img.style.display = 'none'; }
                });
                document.querySelectorAll('.ws-brand-icon').forEach(ic => {
                    ic.style.display = t.logo ? 'none' : '';
                });
                // Fondo del hero: imagen > gradiente > degradado por defecto.
                document.querySelectorAll('.ws-landing-hero').forEach(hero => {
                    if (t.hero_bg) {
                        // Limpia primero el shorthand para que no borre la imagen.
                        hero.style.background = '';
                        hero.style.backgroundImage = "url('" + t.hero_bg + "')";
                        hero.style.backgroundSize = 'cover';
                        hero.style.backgroundPosition = 'center';
                        hero.classList.add('ws-has-bg');
                    } else if (t.hero_gradient) {
                        hero.style.background = t.hero_gradient;
                        hero.style.backgroundImage = '';
                        hero.classList.remove('ws-has-bg');
                    } else {
                        hero.style.background = '';
                        hero.style.backgroundImage = '';
                        hero.classList.remove('ws-has-bg');
                    }
                });
            },
            // Estilo del fondo del hero en la vista previa (misma prioridad).
            pvHeroStyle() {
                if (this.hero_bg) return { backgroundImage: "url('" + this.hero_bg + "')", backgroundSize: 'cover', backgroundPosition: 'center' };
                if (this.hero_gradient) return { background: this.hero_gradient };
                return { background: 'radial-gradient(600px 260px at 85% -10%, ' + this.primary + '88, transparent 60%), linear-gradient(160deg, #171b3a 0%, ' + this.primary + 'cc 130%)' };
            },
            applyLive() {
                if (!this.live) return;
                this.applyTheme({
                    name: this.name, logo: this.logo, primary: this.primary,
                    accent: this.accent, hero_badge: this.hero_badge,
                    hero_title: this.hero_title, hero_sub: this.hero_sub,
                    hero_bg: this.hero_bg, hero_gradient: this.hero_gradient,
                    footer_text: this.footer_text
                });
            },
            applySaved() {
                this.applyTheme(this.saved || {});
            },
            rgbToHex(r,g,b){ const toHex = v => ('0' + Number(v).toString(16)).slice(-2); return '#' + toHex(r) + toHex(g) + toHex(b); },
            hslToHex(h,s,l){
                s /= 100; l /= 100;
                const c = (1 - Math.abs(2 * l - 1)) * s;
                const x = c * (1 - Math.abs((h / 60) % 2 - 1));
                const m = l - c / 2;
                let r=0,g=0,b=0;
                if (h >= 0 && h < 60) [r,g,b] = [c,x,0];
                else if (h >= 60 && h < 120) [r,g,b] = [x,c,0];
                else if (h >= 120 && h < 180) [r,g,b] = [0,c,x];
                else if (h >= 180 && h < 240) [r,g,b] = [0,x,c];
                else if (h >= 240 && h < 300) [r,g,b] = [x,0,c];
                else [r,g,b] = [c,0,x];
                return this.rgbToHex(Math.round((r + m) * 255), Math.round((g + m) * 255), Math.round((b + m) * 255));
            },
            detectPalette() {
                if (!this.logo) { toast('error', 'Añade un logo para detectar la paleta'); return; }
                const img = new Image();
                img.crossOrigin = 'anonymous';
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const size = 80;
                    canvas.width = size; canvas.height = size;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, size, size);
                    const data = ctx.getImageData(0, 0, size, size).data;
                    let r = 0, g = 0, b = 0, count = 0;
                    for (let i = 0; i < data.length; i += 4) {
                        const a = data[i + 3];
                        if (a < 128) continue;
                        r += data[i]; g += data[i + 1]; b += data[i + 2]; count++;
                    }
                    if (!count) { toast('error', 'No se pudo analizar el logo'); return; }
                    r = Math.round(r / count); g = Math.round(g / count); b = Math.round(b / count);
                    const max = Math.max(r, g, b), min = Math.min(r, g, b);
                    const delta = max - min;
                    let h = 0;
                    if (delta) {
                        if (max === r) h = 60 * (((g - b) / delta) % 6);
                        else if (max === g) h = 60 * (((b - r) / delta) + 2);
                        else h = 60 * (((r - g) / delta) + 4);
                    }
                    if (h < 0) h += 360;
                    const l = (max + min) / 2 / 255 * 100;
                    const s = delta === 0 ? 0 : delta / (1 - Math.abs(2 * l / 100 - 1)) * 100;
                    const primary = this.hslToHex((h + 360) % 360, Math.min(90, Math.max(40, s)), Math.min(48, Math.max(22, l)));
                    const accent = this.hslToHex((h + 35) % 360, Math.min(95, Math.max(55, s * 0.92)), Math.min(62, Math.max(42, l + 18)));
                    this.primary = primary;
                    this.accent = accent;
                    this.applyLive();
                    toast('success', 'Paleta sugerida aplicada');
                };
                img.onerror = () => toast('error', 'No se pudo cargar el logo para generar la paleta');
                img.src = this.logo;
            },
            save() {
                if (this.busy) return;
                this.busy = true;
                const body = new URLSearchParams({ action: 'ws_save_site_theme', ws_nonce: WS.nonce });
                ['name', 'logo', 'favicon', 'primary', 'accent', 'hero_badge', 'hero_title', 'hero_sub', 'hero_bg', 'hero_gradient', 'footer_text'].forEach(k => body.append(k, this[k] || ''));
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) { toast('success', 'Apariencia guardada'); setTimeout(() => location.reload(), 700); }
                        else { toast('error', 'Error', res.data && res.data.msg); this.busy = false; }
                    })
                    .catch(() => { toast('error', 'Error de red'); this.busy = false; });
            },
            reset() {
                const d = this.defaults || {};
                this.name = d.name || '';
                this.logo = d.logo || '';
                this.favicon = d.favicon || '';
                this.primary = d.primary || '#4f46e5';
                this.accent = d.accent || '#f59e0b';
                this.hero_badge = d.hero_badge || '';
                this.hero_title = d.hero_title || '';
                this.hero_sub = d.hero_sub || '';
                this.hero_bg = d.hero_bg || '';
                this.hero_gradient = d.hero_gradient || '';
                this.footer_text = d.footer_text || '';
                this.applyLive();
            }
        }));

        /* Mi cuenta */
        Alpine.data('wsAccount', (opts) => ({
            id: opts.id,
            username: opts.username,
            display_name: opts.display_name,
            email: opts.email,
            role: opts.role,
            last_login: opts.last_login,
            password: { current: '', new: '', confirm: '' },
            busy: false,
            saveData() {
                if (this.busy) return;
                this.busy = true;
                const body = new URLSearchParams({ action: 'ws_save_account', ws_nonce: WS.nonce, id: this.id, display_name: this.display_name, email: this.email });
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => { if (res.success) toast('success', 'Datos guardados'); else toast('error', 'Error', res.data && res.data.msg); })
                    .finally(() => { this.busy = false; });
            },
            savePassword() {
                if (this.busy) return;
                if (this.password.new !== this.password.confirm) { toast('error', 'Las contraseñas no coinciden'); return; }
                this.busy = true;
                const body = new URLSearchParams({ action: 'ws_change_password', ws_nonce: WS.nonce, id: this.id, current: this.password.current, new: this.password.new, confirm: this.password.confirm });
                fetch(WS.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) { toast('success', 'Contraseña actualizada'); this.password = { current: '', new: '', confirm: '' }; }
                        else { toast('error', 'Error', res.data && res.data.msg); }
                    })
                    .finally(() => { this.busy = false; });
            }
        }));

        /* Categorías de productos en ÁRBOL (subcategorías podables). */
        Alpine.data('wsCategories', (data) => {
            return {
                can: !!data.can,
                list: data.list || [],
                flat: data.flat || [],
                editingId: 0,
                saving: false,
                open: {},
                form: { name: '', parent_id: 0, sort_order: 0, active: true },

                init() {
                    // Al cargar, las categorías raíz quedan abiertas: se ve la
                    // jerarquía de un vistazo y cada rama se pliega con el acordeón.
                    this.list.forEach((c) => {
                        if (!c.parent_id || !this.list.some((x) => x.id === c.parent_id)) {
                            this.open[c.id] = true;
                        }
                    });
                },
                hasChildren(c) {
                    return (c.children || 0) > 0;
                },
                depth(c) {
                    return (c.path ? c.path.split(' / ').length : 1) - 1;
                },
                isOpen(id) {
                    return !!this.open[id];
                },
                toggle(id) {
                    this.open[id] = !this.open[id];
                },
                // Un nodo se muestra solo si TODOS sus ancestros están abiertos
                // (recursivo por parent_id, soporta cualquier profundidad).
                isVisible(c) {
                    if (!c.parent_id) {
                        return true;
                    }
                    const parent = this.list.find((x) => x.id === c.parent_id);
                    if (!parent) {
                        return true;
                    }
                    return !!this.open[parent.id] && this.isVisible(parent);
                },

                resetForm() {
                    this.editingId = 0;
                    this.form = { name: '', parent_id: 0, sort_order: 0, active: true };
                },
                edit(c) {
                    this.editingId = c.id;
                    this.form = { name: c.name, parent_id: c.parent_id, sort_order: c.sort_order, active: !!c.active };
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },
                // Padres posibles: todos menos la propia categoría y su rama
                // (no puede estar dentro de sí misma) al editar. La rama se
                // detecta por la ruta; el servidor también lo valida.
                parentOptions() {
                    const editing = this.list.find((x) => x.id === this.editingId) || null;
                    const banned = {};
                    if (editing) {
                        banned[editing.id] = 1;
                        this.list.forEach((c) => {
                            if (c.path === editing.path || c.path.indexOf(editing.path + ' / ') === 0) {
                                banned[c.id] = 1;
                            }
                        });
                    }
                    return this.flat.filter((c) => !banned[c.id]);
                },
                api(action, extra, cb) {
                    const body = new URLSearchParams();
                    body.append('action', action);
                    body.append('ws_nonce', (window.WS && WS.nonce) || '');
                    Object.keys(extra || {}).forEach((k) => body.append(k, extra[k]));
                    fetch((window.WS && WS.ajaxUrl) || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body })
                        .then((r) => r.json()).then(cb)
                        .catch(() => cb({ success: false, data: { msg: 'Sin conexión.' } }));
                },
                refresh(json) {
                    if (json && json.success && json.data && json.data.payload) {
                        const p = json.data.payload;
                        const out = [];
                        const flat = [];
                        const walk = (node, parents) => {
                            const path = parents.concat([node.name]).join(' / ');
                            out.push({
                                id: node.id, parent_id: node.parent_id, name: node.name,
                                slug: node.slug || '', active: node.active, sort_order: node.sort_order,
                                path: path, children: (node.children || []).length
                            });
                            flat.push({ id: node.id, name: path });
                            (node.children || []).forEach((kid) => walk(kid, parents.concat([node.name])));
                        };
                        (p.tree || []).forEach((root) => walk(root, []));
                        this.list = out;
                        this.flat = flat;
                        // El payload no trae conteos: se recalculan en cada carga.
                        this.list.forEach((c) => { c.products = 0; });
                    }
                },
                save() {
                    if (!this.form.name.trim()) { return; }
                    this.saving = true;
                    this.api('ws_category_save', {
                        id: this.editingId || 0,
                        name: this.form.name,
                        parent_id: this.form.parent_id || 0,
                        sort_order: this.form.sort_order || 0,
                        active: this.form.active ? '1' : '0'
                    }, (json) => {
                        this.saving = false;
                        if (json && json.success) {
                            this.refresh(json);
                            this.resetForm();
                            window.Swal ? Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Categoría guardada', showConfirmButton: false, timer: 2000 })
                                        : alert('Categoría guardada');
                        } else {
                            window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: (json && json.data && json.data.msg) || 'No se pudo guardar.' })
                                        : alert((json && json.data && json.data.msg) || 'No se pudo guardar.');
                        }
                    });
                },
                remove(c) {
                    const doRemove = () => {
                        this.api('ws_category_delete', { id: c.id }, (json) => {
                            if (json && json.success) { this.refresh(json); }
                        });
                    };
                    const msg = c.children > 0
                        ? 'Se PODARÁ esta categoría y sus subcategorías. Los productos pasarán a la categoría padre. ¿Continuar?'
                        : '¿Eliminar esta categoría? Sus productos pasarán a la categoría padre.';
                    if (window.Swal) {
                        Swal.fire({ title: 'Eliminar categoría', text: msg, icon: 'warning', showCancelButton: true,
                            confirmButtonText: 'Sí, podar', cancelButtonText: 'Cancelar' })
                            .then((r) => { if (r.isConfirmed) { doRemove(); } });
                    } else if (confirm(msg)) { doRemove(); }
                }
            };
        });

        /* Control de gastos: registro mensual + utilidad (ingresos - gastos). */
        Alpine.data('wsExpenses', (data) => {
            return {
                can: !!data.can,
                currency: data.currency || '',
                months: data.months || {},
                year: Number(data.year) || new Date().getFullYear(),
                month: Number(data.month) || (new Date().getMonth() + 1),
                list: data.list || [],
                summary: data.summary || { income: 0, expenses: 0, utility: 0 },
                locations: data.locations || [],
                editingId: 0,
                saving: false,
                repeatMonths: 1,
                form: { concept: '', amount: 0, category: '', note: '', expense_date: '', location_id: 0 },

                years() {
                    const out = [];
                    for (let i = this.year; i >= this.year - 4; i--) { out.push(i); }
                    return out;
                },
                categories() {
                    const out = [];
                    (this.list || []).forEach((e) => {
                        if (e.category && out.indexOf(e.category) === -1) { out.push(e.category); }
                    });
                    return out;
                },
                total() {
                    return (this.list || []).reduce((acc, e) => acc + (Number(e.amount) || 0), 0);
                },
                locName(e) {
                    if (!e.location_id) return '';
                    const loc = this.locations.find((l) => l.id === e.location_id);
                    return loc ? loc.name : '';
                },
                resetForm() {
                    this.editingId = 0;
                    this.repeatMonths = 1;
                    this.form = { concept: '', amount: 0, category: '', note: '', expense_date: '', location_id: 0 };
                },
                edit(e) {
                    this.editingId = e.id;
                    this.repeatMonths = 1;
                    this.form = {
                        concept: e.concept, amount: e.amount, category: e.category, note: e.note,
                        expense_date: e.date_raw, location_id: e.location_id || 0
                    };
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },
                // Suma meses a una fecha 'YYYY-MM-DD' (el día se ajusta si el mes
                // no tiene ese día, p. ej. 31 en febrero).
                addMonths(dateStr, n) {
                    const d = dateStr ? new Date(dateStr + 'T00:00:00') : new Date();
                    const day = d.getDate();
                    const t = new Date(d.getFullYear(), d.getMonth() + n, 1);
                    const last = new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate();
                    t.setDate(Math.min(day, last));
                    const pad = (x) => String(x).padStart(2, '0');
                    return t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate());
                },
                duplicate(e) {
                    // Ofrece registrar el gasto recurrente por varios meses de una
                    // vez (1, 3, 6 o 12): se rellena el formulario con la fecha del
                    // mes siguiente y al pulsar Guardar se crea un gasto por mes.
                    const startDate = this.addMonths(e.date_raw || '', 1);
                    const apply = (months) => {
                        this.editingId = 0;
                        this.repeatMonths = months;
                        this.form = {
                            concept: e.concept || '',
                            amount: Number(e.amount) || 0,
                            category: e.category || '',
                            note: e.note || '',
                            expense_date: startDate,
                            location_id: e.location_id || 0
                        };
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        if (window.Swal) {
                            Swal.fire({ toast: true, position: 'top-end', icon: 'info',
                                title: months > 1 ? ('Se registrará en ' + months + ' meses: ajústalo y guarda') : 'Gasto duplicado: ajústalo y guarda',
                                showConfirmButton: false, timer: 2400 });
                        }
                    };
                    if (!window.Swal) { apply(1); return; }
                    Swal.fire({
                        title: 'Repetir gasto',
                        html: '¿Por cuántos meses quieres registrar este gasto?<br><small class="ws-muted">Se creará un gasto por mes a partir del próximo; podrás ajustarlos antes de guardar.</small>',
                        input: 'range',
                        inputAttributes: { min: '1', max: '12', step: '1' },
                        inputValue: 1,
                        showCancelButton: true,
                        confirmButtonText: 'Continuar',
                        cancelButtonText: 'Cancelar',
                        didOpen: () => {
                            const r = Swal.getInput();
                            if (r) {
                                const out = document.createElement('div');
                                out.style.cssText = 'margin-top:8px;font-weight:600;color:#0f766e';
                                r.addEventListener('input', () => {
                                    const v = Number(r.value) || 1;
                                    out.textContent = v === 1 ? '1 mes' : v + ' meses';
                                });
                                out.textContent = '1 mes';
                                r.parentNode.appendChild(out);
                            }
                        }
                    }).then((res) => { if (res.isConfirmed) { apply(Number(res.value) || 1); } });
                },
                api(action, extra, cb) {
                    const body = new URLSearchParams();
                    body.append('action', action);
                    body.append('ws_nonce', (window.WS && WS.nonce) || '');
                    Object.keys(extra || {}).forEach((k) => body.append(k, extra[k]));
                    fetch((window.WS && WS.ajaxUrl) || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body })
                        .then((r) => r.json()).then(cb)
                        .catch(() => cb({ success: false, data: { msg: 'Sin conexión.' } }));
                },
                money(v) {
                    v = Number(v) || 0;
                    const s = v.toLocaleString('es-CU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    return (this.currency ? this.currency + ' ' : '') + s;
                },
                load() {
                    this.api('ws_expenses_list', { year: this.year, month: this.month }, (json) => {
                        if (json && json.success) {
                            this.list = (json.data && json.data.expenses) || [];
                            this.summary = (json.data && json.data.summary) || this.summary;
                        }
                    });
                },
                save() {
                    if (!this.form.concept.trim() || !(Number(this.form.amount) > 0) || !this.form.expense_date) { return; }
                    // Edición normal: un solo gasto.
                    if (this.editingId || this.repeatMonths <= 1) {
                        this._saveOne(this.form.expense_date, (json) => {
                            if (json && json.success) {
                                this.resetForm();
                                this.load();
                                window.Swal ? Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Gasto guardado', showConfirmButton: false, timer: 2000 })
                                            : alert('Gasto guardado');
                            } else {
                                window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: (json && json.data && json.data.msg) || 'No se pudo guardar.' })
                                            : alert((json && json.data && json.data.msg) || 'No se pudo guardar.');
                            }
                        });
                        return;
                    }
                    // Duplicado por varios meses: guardar un gasto por cada mes.
                    const months = Math.max(1, Math.min(24, Math.floor(this.repeatMonths) || 1));
                    const dates = [];
                    for (let i = 0; i < months; i++) { dates.push(this.addMonths(this.form.expense_date, i)); }
                    this.saving = true;
                    let done = 0, failed = 0;
                    const next = () => {
                        if (done >= dates.length) {
                            this.saving = false;
                            this.resetForm();
                            this.load();
                            if (failed === 0) {
                                window.Swal ? Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: months + ' gastos guardados', showConfirmButton: false, timer: 2400 })
                                            : alert(months + ' gastos guardados');
                            } else {
                                window.Swal ? Swal.fire({ icon: 'warning', title: 'Parcial', text: (months - failed) + ' de ' + months + ' gastos guardados. Revisa los meses siguientes.' })
                                            : alert((months - failed) + ' de ' + months + ' gastos guardados.');
                            }
                            return;
                        }
                        this._saveOne(dates[done], (json) => {
                            done++;
                            if (!(json && json.success)) { failed++; }
                            next();
                        });
                    };
                    next();
                },
                _saveOne(dateStr, cb) {
                    this.api('ws_expense_save', {
                        id: this.editingId || 0,
                        concept: this.form.concept,
                        amount: String(this.form.amount),
                        category: this.form.category,
                        note: this.form.note,
                        expense_date: dateStr,
                        location_id: this.form.location_id || 0
                    }, cb);
                },
                remove(e) {
                    const doRemove = () => {
                        this.api('ws_expense_delete', { id: e.id }, (json) => {
                            if (json && json.success) { this.load(); }
                        });
                    };
                    if (window.Swal) {
                        Swal.fire({ title: 'Eliminar gasto', text: '¿Eliminar este gasto?', icon: 'warning', showCancelButton: true,
                            confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar' })
                            .then((r) => { if (r.isConfirmed) { doRemove(); } });
                    } else if (confirm('¿Eliminar este gasto?')) { doRemove(); }
                }
            };
        });

        /* Registro público de negocios (2 pasos: datos + código de 6 dígitos). */
        Alpine.data('wsTutorial', (opts) => ({
            open: false,
            view: 'list',
            currentKey: '',
            sections: (opts && opts.sections) || [],
            auto: !!(opts && opts.auto),
            activePage: (opts && opts.current) ? String(opts.current) : '',
            // --- Tour guiado (spotlight sobre la sección actual) ---
            tourActive: false,
            tourIndex: -1,
            tourVisible: [],
            spotStyle: { display: 'none' },
            arrowStyle: { display: 'none' },
            arrowSide: 'right',
            popStyle: { display: 'none' },
            reduced: !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches),
            _reposTimer: null,
            _reposHandler: null,
            _keyHandler: null,
            _settleTimer: null,
            _popGeo: null,

            init() {
                // Primer acceso tras registrarse: bienvenida con felicitación.
                if (this.auto) {
                    this.$nextTick(() => {
                        this.open = true;
                        this.view = 'welcome';
                    });
                }
                // El asistente (chatbot) puede abrir el recorrido guiado.
                window.addEventListener('ws-open-tutorial', () => {
                    this.open = true;
                    this.view = 'list';
                    this.currentKey = '';
                });
            },
            openTutorial() {
                this.open = true;
                this.view = 'list';
                this.currentKey = '';
            },
            close() { this.open = false; },
            showSection(key) {
                this.currentKey = key;
                this.view = 'steps';
            },
            get current() {
                return this.sections.find(s => s.key === this.currentKey) || null;
            },

            // ----- Tour -----
            startTour() {
                const sec = this.current;
                // El recorrido guiado recorre TODOS los pasos de la guía (texto)
                // y añade al final los pasos con spotlight que apuntan a
                // elementos concretos de la sección.
                const guide = (sec && sec.steps) || [];
                const spots = (sec && sec.tour) || [];
                const items = [];
                guide.forEach(s => {
                    // Paso textual de la guía: popup centrado, sin spotlight.
                    items.push({ el: null, hasEl: true, textual: true, title: s.title, text: s.text, tip: '' });
                });
                spots.forEach(s => {
                    let el = null;
                    try { el = document.querySelector(s.sel); } catch (e) { el = null; }
                    // Todos los pasos definidos participan: si el elemento no
                    // existe o está oculto (p. ej. sin datos aún), el paso se
                    // muestra con el popup centrado en pantalla.
                    const ok = !!el && el.offsetWidth > 0 && el.offsetHeight > 0;
                    items.push({ el: ok ? el : null, hasEl: ok, textual: false, title: s.title, text: s.text, tip: s.tip || '' });
                });
                this.tourVisible = items;
                if (!this.tourVisible.length) return;
                this.open = false;
                this.tourActive = true;
                this.tourIndex = 0;
                this._stepTo();
                this._bindTour(true);
            },
            // ¿La sección tiene recorrido definido? (siempre visible aunque el
            // elemento de un paso no esté presente todavía).
            canTour() {
                const sec = this.current;
                return !!(sec && sec.tour && sec.tour.length);
            },
            stopTour() {
                this.tourActive = false;
                this.tourIndex = -1;
                this.tourVisible = [];
                if (this._settleTimer) { clearTimeout(this._settleTimer); this._settleTimer = null; }
                this._bindTour(false);
            },
            tourNext() {
                if (this.tourIndex >= this.tourVisible.length - 1) { this.stopTour(); return; }
                this.tourIndex++;
                this._stepTo();
            },
            tourPrev() {
                if (this.tourIndex <= 0) return;
                this.tourIndex--;
                this._stepTo();
            },
            _stepTo() {
                const item = this.tourVisible[this.tourIndex];
                if (item && item.el) {
                    item.el.scrollIntoView({ behavior: this.reduced ? 'auto' : 'smooth', block: 'center', inline: 'nearest' });
                }
                // Reposiciona cuando el scroll se asienta: un microtask iría
                // demasiado pronto (antes de que el scroll suave mueva la página
                // y el elemento aún estaría "fuera de la vista").
                if (this._settleTimer) clearTimeout(this._settleTimer);
                this._settleTimer = setTimeout(() => {
                    this._settleTimer = null;
                    this.positionTour();
                }, this.reduced ? 0 : 450);
            },
            _bindTour(on) {
                if (on) {
                    this._reposHandler = () => {
                        if (this._reposTimer) return;
                        this._reposTimer = requestAnimationFrame(() => {
                            this.positionTour();
                            this._reposTimer = null;
                        });
                    };
                    // Fase de captura: detecta el scroll de cualquier contenedor
                    // (la zona de contenido del panel puede scrollear interna).
                    document.addEventListener('scroll', this._reposHandler, { capture: true, passive: true });
                    window.addEventListener('resize', this._reposHandler);
                    this._keyHandler = this._tourKeys.bind(this);
                    document.addEventListener('keydown', this._keyHandler);
                } else {
                    if (this._reposHandler) {
                        document.removeEventListener('scroll', this._reposHandler, { capture: true });
                        window.removeEventListener('resize', this._reposHandler);
                        this._reposHandler = null;
                    }
                    if (this._reposTimer) { cancelAnimationFrame(this._reposTimer); this._reposTimer = null; }
                    if (this._keyHandler) {
                        document.removeEventListener('keydown', this._keyHandler);
                        this._keyHandler = null;
                    }
                }
            },
            _tourKeys(e) {
                if (!this.tourActive) return;
                if (e.key === 'Escape') { e.preventDefault(); this.stopTour(); }
                else if (e.key === 'ArrowRight') { e.preventDefault(); this.tourNext(); }
                else if (e.key === 'ArrowLeft') { e.preventDefault(); this.tourPrev(); }
            },
            positionTour() {
                if (!this.tourActive || this.tourIndex < 0) return;
                const item = this.tourVisible[this.tourIndex];
                if (!item) return;
                const vw = window.innerWidth || 1;
                const vh = window.innerHeight || 1;
                // Paso sin elemento visible (no existe o está oculto): popup
                // centrado en pantalla, sin spotlight ni flecha.
                if (!item.hasEl || !item.el) {
                    this.spotStyle = { display: 'none' };
                    this.arrowStyle = { display: 'none' };
                    this._popGeo = null;
                    const POP_W = Math.min(380, vw - 24);
                    const POP_H = 250;
                    this.popStyle = {
                        display: 'block',
                        top: Math.max(12, (vh - POP_H) / 2) + 'px',
                        left: Math.max(12, (vw - POP_W) / 2) + 'px',
                        width: POP_W + 'px',
                        maxHeight: (vh - 16) + 'px'
                    };
                    return;
                }
                const r = item.el.getBoundingClientRect();
                // Elemento oculto: no posicionar. startTour ya filtra los visibles
                // y _stepTo lo trae a la vista antes de posicionar (sin saltos).
                if (r.width < 4 || r.height < 4) {
                    item.hasEl = false;
                    item.el = null;
                    this.positionTour();
                    return;
                }
                const pad = 8;
                this.spotStyle = {
                    display: 'block',
                    top: Math.max(0, r.top - pad) + 'px',
                    left: Math.max(0, r.left - pad) + 'px',
                    width: (r.width + pad * 2) + 'px',
                    height: (r.height + pad * 2) + 'px'
                };
                const POP_W = Math.min(330, vw - 16);
                const POP_H = 220;
                const GAP = 20;
                let side = 'right', px = 0, py = 0;
                if (r.right + GAP + POP_W <= vw - 8) {
                    side = 'right';
                    px = r.right + GAP;
                    py = Math.min(Math.max(8, r.top + r.height / 2 - POP_H / 2), vh - POP_H - 8);
                } else if (r.left - GAP - POP_W >= 8) {
                    side = 'left';
                    px = r.left - GAP - POP_W;
                    py = Math.min(Math.max(8, r.top + r.height / 2 - POP_H / 2), vh - POP_H - 8);
                } else if (r.bottom + GAP + POP_H <= vh - 8) {
                    side = 'bottom';
                    px = Math.min(Math.max(8, r.left + r.width / 2 - POP_W / 2), vw - POP_W - 8);
                    py = r.bottom + GAP;
                } else {
                    side = 'top';
                    px = Math.min(Math.max(8, r.left + r.width / 2 - POP_W / 2), vw - POP_W - 8);
                    py = Math.max(8, r.top - GAP - POP_H);
                }
                this.arrowSide = side;
                this._popGeo = { side: side, px: px, py: py, w: POP_W, vh: vh };
                this.popStyle = {
                    display: 'block', top: py + 'px', left: px + 'px', width: POP_W + 'px',
                    maxHeight: (vh - 16) + 'px'
                };
                this.$nextTick(() => this._placeArrow());
            },
            // Ancla la flecha a la altura real del popup (ya renderizado).
            _placeArrow() {
                const geo = this._popGeo;
                if (!geo) return;
                const pop = document.querySelector('.ws-tour-pop');
                const ph = pop ? Math.min(pop.offsetHeight, geo.vh - 16) : 220;
                let ax = geo.px, ay = geo.py;
                if (geo.side === 'right') { ax = geo.px - 7; ay = geo.py + ph / 2 - 7; }
                else if (geo.side === 'left') { ax = geo.px + geo.w - 7; ay = geo.py + ph / 2 - 7; }
                else if (geo.side === 'bottom') { ax = geo.px + geo.w / 2 - 7; ay = geo.py - 7; }
                else { ax = geo.px + geo.w / 2 - 7; ay = geo.py + ph - 7; }
                this.arrowStyle = { display: 'block', top: ay + 'px', left: ax + 'px' };
            },
            get currentStep() {
                return this.tourVisible[this.tourIndex] || { title: '', text: '', tip: '', hasEl: true };
            },
        }));

        Alpine.data('wsRegister', () => ({
            step: 1,
            busy: false,
            error: '',
            resendIn: 0,
            form: { biz_name: '', slug: '', owner_name: '', email: '', phone: '', username: '', password: '' },
            otp: ['', '', '', '', '', ''],
            _resendTimer: null,

            slugify(s) { return slugify(s); },

            // Reactivo: depende de this.otp, así el botón se habilita al
            // teclear los 6 dígitos (Alpine re-evalúa :disabled).
            otpFilled() {
                return this.otp.length === 6 && this.otp.every(v => v && v.trim() !== '');
            },

            onOtpInput(e, i) {
                const v = e.target.value.replace(/[^0-9]/g, '');
                this.otp[i] = v;
                if (v && i < 5) {
                    const next = this.$root.querySelectorAll('.ws-otp input')[i + 1];
                    if (next) next.focus();
                }
            },
            onOtpBack(i) {
                const boxes = this.$root.querySelectorAll('.ws-otp input');
                const cur = boxes[i];
                if (cur && !cur.value && i > 0) {
                    this.otp[i - 1] = '';
                    const prev = boxes[i - 1];
                    if (prev) { prev.focus(); prev.value = ''; }
                }
            },
            onOtpPaste(e, i) {
                const text = (e.clipboardData || window.clipboardData || {}).getData('text').replace(/[^0-9]/g, '');
                if (!text) return;
                e.preventDefault();
                [...text].slice(0, 6).forEach((ch, j) => {
                    const idx = i + j;
                    this.otp[idx] = ch;
                });
                const lastIdx = Math.min(5, i + text.length - 1);
                const boxes = this.$root.querySelectorAll('.ws-otp input');
                if (boxes[lastIdx]) boxes[lastIdx].focus();
                if (this.otpFilled()) this.submitStep2();
            },

            submitStep1() {
                if (this.busy) return;
                const f = this.form;
                if (!f.biz_name.trim()) { this.error = 'Escribe el nombre de tu negocio.'; return; }
                if (!f.slug.trim()) { this.error = 'Escribe la dirección de tu tienda.'; return; }
                if (!f.owner_name.trim()) { this.error = 'Escribe tu nombre.'; return; }
                if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.email)) { this.error = 'Email inválido.'; return; }
                if (!f.username.trim()) { this.error = 'Elige un nombre de usuario.'; return; }
                if (f.password.length < 8) { this.error = 'La contraseña debe tener al menos 8 caracteres.'; return; }
                this.error = '';
                this.busy = true;
                $('ws_register_step1', {
                    biz_name: f.biz_name, slug: f.slug, owner_name: f.owner_name,
                    email: f.email, phone: f.phone, username: f.username, password: f.password
                })
                    .then(res => {
                        if (res.success) { this.step = 2; this.startResend(); this.error = ''; }
                        else { this.error = (res.data && res.data.msg) || 'No se pudo continuar.'; }
                    })
                    .catch(() => { this.error = 'Error de conexión. Inténtalo de nuevo.'; })
                    .finally(() => { this.busy = false; });
            },

            submitStep2() {
                if (this.busy || !this.otpFilled()) return;
                const code = this.otp.join('');
                this.error = '';
                this.busy = true;
                $('ws_register_verify', { email: this.form.email, code })
                    .then(res => {
                        if (res.success) {
                            toast('success', '¡Listo!', res.data && res.data.msg);
                            window.location.href = (res.data && res.data.redirect) || (WS.home || '/');
                        } else {
                            this.error = (res.data && res.data.msg) || 'Código incorrecto.';
                            this.clearOtp();
                        }
                    })
                    .catch(() => { this.error = 'Error de conexión.'; })
                    .finally(() => { this.busy = false; });
            },

            clearOtp() {
                this.otp = ['', '', '', '', '', ''];
                const first = this.$root.querySelector('.ws-otp input');
                if (first) first.focus();
            },

            startResend() {
                this.resendIn = 60;
                clearInterval(this._resendTimer);
                this._resendTimer = setInterval(() => {
                    this.resendIn--;
                    if (this.resendIn <= 0) clearInterval(this._resendTimer);
                }, 1000);
            },

            resend() {
                if (this.resendIn > 0 || this.busy) return;
                this.busy = true;
                $('ws_register_resend', { email: this.form.email })
                    .then(res => {
                        if (res.success) { this.startResend(); toast('success', 'Código reenviado'); }
                        else { this.error = (res.data && res.data.msg) || 'No se pudo reenviar.'; }
                    })
                    .catch(() => { this.error = 'Error de conexión.'; })
                    .finally(() => { this.busy = false; });
            }
        }));
    };

    /* Tablas renderizadas en servidor (workers, reports, dashboard):
       agrega ordenamiento por columnas y paginación client-side.
       Se activa con el atributo data-sortable en <table class="ws-table">. */
    const enhanceStaticTables = () => {
        document.querySelectorAll('table.ws-table[data-sortable]').forEach(table => {
            if (table.dataset.wsEnhanced) return;
            table.dataset.wsEnhanced = '1';

            const thead = table.querySelector('thead');
            const tbody = table.querySelector('tbody');
            if (!thead || !tbody) return;

            // Lee filas como datos planos (ignorando la fila de "sin resultados").
            const headers = [...thead.querySelectorAll('th')].map(th => (th.textContent || '').trim());
            let rows = [...tbody.querySelectorAll('tr')].filter(tr => !tr.querySelector('.ws-empty'));
            if (!rows.length) return;

            const data = rows.map(tr => {
                const cells = [...tr.querySelectorAll('td')].map(td => (td.textContent || '').trim());
                return { el: tr, cells };
            });

            // Estado persistido en localStorage (data-ts identifica la tabla).
            const tsKey = table.dataset.ts || '';
            let sortKey = -1;
            let sortDir = 'asc';
            let page = 1;
            let pageSize = 10;
            if (tsKey) {
                const saved = wsTsRead(tsKey);
                if (saved) {
                    if (Number.isInteger(saved.sortKey) && saved.sortKey >= 0 && saved.sortKey < headers.length) sortKey = saved.sortKey;
                    if (saved.sortDir === 'asc' || saved.sortDir === 'desc') sortDir = saved.sortDir;
                    if ([10, 25, 50, 100].includes(saved.pageSize)) pageSize = saved.pageSize;
                    if (Number.isInteger(saved.page) && saved.page > 0) page = saved.page;
                }
            }
            const persistTable = () => {
                if (!tsKey) return;
                wsTsWrite(tsKey, { sortKey, sortDir, page, pageSize });
            };

            const render = () => {
                const sorted = [...data];
                if (sortKey >= 0) {
                    const dir = sortDir === 'asc' ? 1 : -1;
                    sorted.sort((a, b) => {
                        const na = wsSortNorm(a.cells[sortKey]);
                        const nb = wsSortNorm(b.cells[sortKey]);
                        if (typeof na === 'number' && typeof nb === 'number') return (na - nb) * dir;
                        const sa = typeof na === 'number' ? String(na) : na;
                        const sb = typeof nb === 'number' ? String(nb) : nb;
                        return sa.localeCompare(sb, 'es', { numeric: true }) * dir;
                    });
                }
                const total = sorted.length;
                const totalPages = Math.max(1, Math.ceil(total / pageSize));
                if (page > totalPages) page = totalPages;
                if (page < 1) page = 1;
                const start = (page - 1) * pageSize;
                const slice = sorted.slice(start, start + pageSize);

                tbody.innerHTML = '';
                slice.forEach(r => tbody.appendChild(r.el));
                const emptyRow = [...tbody.querySelectorAll('tr')].find(tr => tr.querySelector('.ws-empty'));
                if (!slice.length && !emptyRow) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="' + headers.length + '"><p class="ws-empty">Sin resultados.</p></td>';
                    tbody.appendChild(tr);
                }

                headers.forEach((h, i) => {
                    const th = thead.querySelectorAll('th')[i];
                    if (!th) return;
                    const icon = th.querySelector('.ws-sort-ic');
                    if (icon) {
                        icon.className = 'ws-sort-ic fa-solid ' + (i === sortKey ? (sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort');
                    }
                    th.classList.toggle('is-sorted', i === sortKey);
                });

                const info = footer.querySelector('.ws-pagination-info');
                const ctrl = footer.querySelector('.ws-pagination-controls');
                if (info) info.textContent = total ? ((start + 1) + '–' + Math.min(start + pageSize, total) + ' de ' + total) : '0 resultados';
                if (ctrl) {
                    ctrl.innerHTML = '';
                    const mk = (label, fn, active, disabled) => {
                        const b = document.createElement('button');
                        b.className = 'ws-page-btn' + (active ? ' is-active' : '');
                        b.innerHTML = label;
                        b.disabled = !!disabled;
                        b.addEventListener('click', fn);
                        ctrl.appendChild(b);
                    };
                    mk('<i class="fa-solid fa-chevron-left"></i>', () => { page--; persistTable(); render(); }, false, page <= 1);
                    for (let p = Math.max(1, page - 2); p <= Math.min(totalPages, page + 2); p++) {
                        mk(p, () => { page = p; persistTable(); render(); }, p === page);
                    }
                    mk('<i class="fa-solid fa-chevron-right"></i>', () => { page++; persistTable(); render(); }, false, page >= totalPages);
                    const sel = document.createElement('select');
                    sel.className = 'ws-page-size';
                    [10, 25, 50, 100].forEach(n => {
                        const o = document.createElement('option');
                        o.value = n; o.textContent = n;
                        if (n === pageSize) o.selected = true;
                        sel.appendChild(o);
                    });
                    sel.addEventListener('change', () => { pageSize = Number(sel.value); page = 1; persistTable(); render(); });
                    ctrl.appendChild(sel);
                }
                footer.style.display = total > pageSize ? '' : 'none';
                persistTable();
            };

            headers.forEach((h, i) => {
                const th = thead.querySelectorAll('th')[i];
                if (!th) return;
                if (!h.trim()) return; // salta cabeceras vacías (columna de acciones)
                th.classList.add('ws-th-sort');
                const ic = document.createElement('i');
                ic.className = 'ws-sort-ic fa-solid fa-sort';
                th.appendChild(ic);
                th.addEventListener('click', () => {
                    if (sortKey === i) {
                        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortKey = i;
                        sortDir = 'asc';
                    }
                    page = 1;
                    persistTable();
                    render();
                });
            });

            const footer = document.createElement('div');
            footer.className = 'ws-pagination';
            footer.innerHTML = '<span class="ws-pagination-info"></span><div class="ws-pagination-controls"></div>';
            table.after(footer);

            render();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceStaticTables);
    } else {
        enhanceStaticTables();
    }

    if (window.Alpine && typeof window.Alpine.data === 'function') {
        registerAlpine();
    } else {
        document.addEventListener('alpine:init', registerAlpine);
    }

    /* ---------- Carrito Persistente ---------- */
    window.WSCart = {
        sessionId: localStorage.getItem('ws_cart_session_id') || null,
        locationId: null,

        init: function(locationId) {
            this.locationId = locationId;
            if (!this.sessionId) {
                this.sessionId = this.generateSessionId();
                localStorage.setItem('ws_cart_session_id', this.sessionId);
            }
            // Si el usuario está logueado, merge carrito de invitado
            if (WS.userId > 0) {
                this.mergeGuestCart();
            }
        },

        generateSessionId: function() {
            return Math.random().toString(36).substring(2, 15) + 
                   Math.random().toString(36).substring(2, 15) + 
                   Date.now().toString(36);
        },

        get: async function() {
            // Primero intentar obtener de IndexedDB (offline)
            if (window.WSIndexedDB) {
                try {
                    const offlineCart = await WSIndexedDB.getCartItems();
                    if (offlineCart.length > 0) {
                        return offlineCart;
                    }
                } catch (e) {
                    console.log('Error obteniendo carrito offline:', e);
                }
            }
            
            // Si no hay datos offline, intentar del servidor
            return $('ws_cart_get', {
                session_id: this.sessionId,
                location_id: this.locationId,
                user_id: WS.userId
            });
        },

        add: async function(productId, qty = 1) {
            const cartItem = {
                session_id: this.sessionId,
                location_id: this.locationId,
                product_id: productId,
                qty: qty,
                user_id: WS.userId
            };

            // Si está offline, guardar en IndexedDB
            if (!navigator.onLine && window.WSIndexedDB) {
                await WSIndexedDB.saveCartItem(cartItem);
                this.updateCartCount();
                return { offline: true };
            }

            // Si está online, enviar al servidor
            try {
                const response = await $('ws_cart_add', cartItem);
                if (response.success) {
                    this.updateCartCount();
                    return response;
                }
            } catch (error) {
                // Si falla, guardar en IndexedDB y cola
                if (window.WSIndexedDB && window.WSOfflineQueue) {
                    await WSIndexedDB.saveCartItem(cartItem);
                    await WSOfflineQueue.addToQueue(WSOfflineQueue.QUEUE_ACTIONS.CART_ADD, cartItem);
                    this.updateCartCount();
                    return { offline: true };
                }
                throw error;
            }
        },

        update: async function(cartId, qty) {
            // Si está offline, actualizar en IndexedDB
            if (!navigator.onLine && window.WSIndexedDB) {
                const item = await WSIndexedDB.getCartItem(cartId);
                if (item) {
                    item.qty = qty;
                    await WSIndexedDB.saveCartItem(item);
                    this.updateCartCount();
                    return { offline: true };
                }
            }

            try {
                const response = await $('ws_cart_update', { cart_id: cartId, qty: qty });
                if (response.success) {
                    this.updateCartCount();
                    return response;
                }
            } catch (error) {
                if (window.WSIndexedDB && window.WSOfflineQueue) {
                    const item = await WSIndexedDB.getCartItem(cartId);
                    if (item) {
                        item.qty = qty;
                        await WSIndexedDB.saveCartItem(item);
                        await WSOfflineQueue.addToQueue(WSOfflineQueue.QUEUE_ACTIONS.CART_UPDATE, { cart_id: cartId, qty: qty });
                        this.updateCartCount();
                        return { offline: true };
                    }
                }
                throw error;
            }
        },

        remove: async function(cartId) {
            // Si está offline, eliminar de IndexedDB
            if (!navigator.onLine && window.WSIndexedDB) {
                await WSIndexedDB.deleteCartItem(cartId);
                this.updateCartCount();
                return { offline: true };
            }

            try {
                const response = await $('ws_cart_remove', { cart_id: cartId });
                if (response.success) {
                    this.updateCartCount();
                    return response;
                }
            } catch (error) {
                if (window.WSIndexedDB && window.WSOfflineQueue) {
                    await WSIndexedDB.deleteCartItem(cartId);
                    await WSOfflineQueue.addToQueue(WSOfflineQueue.QUEUE_ACTIONS.CART_REMOVE, { cart_id: cartId });
                    this.updateCartCount();
                    return { offline: true };
                }
                throw error;
            }
        },

        clear: async function() {
            if (!navigator.onLine && window.WSIndexedDB) {
                await WSIndexedDB.clearCart();
                this.updateCartCount();
                return { offline: true };
            }

            try {
                const response = await $('ws_cart_clear', {
                    session_id: this.sessionId,
                    location_id: this.locationId
                });
                if (response.success) {
                    this.updateCartCount();
                    return response;
                }
            } catch (error) {
                if (window.WSIndexedDB) {
                    await WSIndexedDB.clearCart();
                    this.updateCartCount();
                    return { offline: true };
                }
                throw error;
            }
        },

        getTotal: async function() {
            // Calcular desde IndexedDB si está offline
            if (!navigator.onLine && window.WSIndexedDB) {
                const items = await WSIndexedDB.getCartItems();
                return items.reduce((total, item) => total + (item.qty * item.price), 0);
            }

            return $('ws_cart_total', {
                session_id: this.sessionId,
                location_id: this.locationId
            });
        },

        getCount: async function() {
            // Contar desde IndexedDB si está offline
            if (!navigator.onLine && window.WSIndexedDB) {
                const items = await WSIndexedDB.getCartItems();
                return items.reduce((count, item) => count + item.qty, 0);
            }

            return $('ws_cart_count', {
                session_id: this.sessionId,
                location_id: this.locationId
            });
        },

        updateCartCount: async function() {
            const count = await this.getCount();
            const countEl = document.querySelector('.ws-cart-count');
            if (countEl) {
                countEl.textContent = count.data?.count || count;
                countEl.style.display = (count.data?.count || count) > 0 ? 'inline-block' : 'none';
            }
        },

        mergeGuestCart: function() {
            return $('ws_cart_merge', {
                session_id: this.sessionId,
                user_id: WS.userId,
                location_id: this.locationId
            });
        },

        destroy: function() {
            localStorage.removeItem('ws_cart_session_id');
            this.sessionId = null;
        }
    };

    // Inicializar carrito si estamos en una tienda
    const locationSlug = window.location.pathname.match(/\/tienda\/([^\/]+)/);
    if (locationSlug && locationSlug[1]) {
        // Obtener location_id del slug (necesita implementación en backend)
        $('ws_get_location_by_slug', { slug: locationSlug[1] }).then(res => {
            if (res.success && res.data) {
                WSCart.init(res.data.id);
                WSCart.updateCartCount();
            }
        });
    }
})();

/* ------------------------------------------------------------------ */
/* Grupo de botones flotantes (⋮): si hay más de un botón flotante en  */
/* la página, se agrupan detrás de un botón de tres puntos que los     */
/* despliega apilados (evita que el chat opaque o tape a los demás).   */
/* ------------------------------------------------------------------ */
(function () {
    'use strict';

    function initFabGroup() {
        if (document.getElementById('ws-fab-group')) { return; }

        // Botones flotantes conocidos: chat, carrito de la tienda y carrito POS.
        var fabs = [];
        var chat = document.getElementById('wsb-root');
        if (chat && chat.querySelector('#wsb-button')) { fabs.push(chat); }
        var cartFab = document.querySelector('.ws-cart-fab');
        if (cartFab) { fabs.push(cartFab); }
        var posToggle = document.querySelector('.ws-pos-cart-toggle');
        if (posToggle && window.getComputedStyle(posToggle).display !== 'none') { fabs.push(posToggle); }
        document.querySelectorAll('[data-ws-fab]').forEach(function (el) {
            if (fabs.indexOf(el) === -1) { fabs.push(el); }
        });

        // Solo se agrupa cuando hay más de uno; con uno solo queda como está.
        if (fabs.length < 2) { return; }

        var group = document.createElement('div');
        group.id = 'ws-fab-group';
        group.className = 'ws-fab-group';
        group.innerHTML = '<button type="button" class="ws-fab-trigger" aria-label="Más acciones" aria-expanded="false">' +
            '<i class="fa-solid fa-ellipsis-vertical"></i><i class="fa-solid fa-xmark"></i></button>';
        document.body.appendChild(group);

        var backdrop = document.createElement('div');
        backdrop.className = 'ws-fab-backdrop';
        document.body.appendChild(backdrop);

        var trigger = group.querySelector('.ws-fab-trigger');

        // Mini-badge de no leídas en el botón ⋮ (el chat queda oculto al colapsar).
        var chatBtn = chat && chat.querySelector('#wsb-button');
        var dot = document.createElement('span');
        dot.className = 'ws-fab-dot';
        dot.setAttribute('aria-hidden', 'true');
        trigger.appendChild(dot);
        function syncDot() {
            var b = chatBtn && chatBtn.querySelector('.wsb-badge');
            var has = chatBtn && (chatBtn.classList.contains('has-badge') || chatBtn.classList.contains('wsb-unseen'));
            dot.textContent = has && b ? b.textContent : '';
            dot.style.display = has ? 'flex' : 'none';
        }
        syncDot();
        if (chatBtn && window.MutationObserver) {
            // Clase del botón (tiene/no tiene no leídas).
            new MutationObserver(syncDot).observe(chatBtn, { attributes: true, attributeFilter: ['class'] });
            // Texto del contador: si pasa de 3 a 4 la clase no cambia y el dot quedaría viejo.
            var badgeEl = chatBtn.querySelector('.wsb-badge');
            if (badgeEl) {
                new MutationObserver(syncDot).observe(badgeEl, { childList: true, characterData: true, subtree: true });
            }
        }

        var open = false;

        function setOpen(next) {
            open = next;
            group.classList.toggle('is-open', open);
            backdrop.classList.toggle('show', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        trigger.addEventListener('click', function () { setOpen(!open); });
        backdrop.addEventListener('click', function () { setOpen(false); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { setOpen(false); }
        });

        // Cada botón flotante pasa a ser un ítem apilado del grupo.
        fabs.forEach(function (fab, i) {
            fab.classList.add('ws-fab-item');
            fab.style.setProperty('--i', i);
            group.appendChild(fab);
            // Cualquier acción cierra el menú (el botón hace su función).
            fab.addEventListener('click', function () { setOpen(false); }, true);
        });

        // Cuando el chat se abre, colapsa el grupo y lo deja visible a él solo.
        var chatRoot = document.getElementById('wsb-root');
        if (chatRoot && window.MutationObserver) {
            var obs = new MutationObserver(function () {
                var chatOpen = chatRoot.classList.contains('is-open');
                group.classList.toggle('chat-open', chatOpen);
                if (chatOpen) { setOpen(false); }
            });
            obs.observe(chatRoot, { attributes: true, attributeFilter: ['class'] });
        }
    }

    // El widget del chat se crea al final del footer; reintenta hasta que exista.
    var tries = 0;
    function tryInit() {
        if (document.getElementById('wsb-root') || tries > 16) {
            initFabGroup();
            return;
        }
        tries++;
        window.setTimeout(tryInit, 250);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryInit);
    } else {
        tryInit();
    }
    // Segunda pasada: si algún FAB se renderiza después (async), se agrupa igualmente.
    window.setTimeout(initFabGroup, 1200);
})();

/* ------------------------------------------------------------------ */
/* Sesiones de trabajo: reloj del tiempo transcurrido (en curso).      */
/* Actualiza los .ws-elapsed[data-in] (epoch de la entrada) cada minuto. */
/* ------------------------------------------------------------------ */
(function () {
    'use strict';
    function fmt(sec) {
        sec = Math.max(0, Math.floor(sec));
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
        return h > 0 ? h + ' h ' + ('0' + m).slice(-2) + ' min' : m + ' min';
    }
    function wsSessionsTick() {
        var els = document.querySelectorAll('.ws-elapsed[data-in]');
        if (!els.length) { return; }
        var now = Date.now() / 1000;
        els.forEach(function (el) {
            var t = parseInt(el.getAttribute('data-in'), 10);
            if (!isNaN(t)) { el.textContent = fmt(now - t); }
        });
    }
    function wsSessionsInit() {
        wsSessionsTick();
        window.setInterval(wsSessionsTick, 60000);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wsSessionsInit);
    } else {
        wsSessionsInit();
    }
})();

/* ------------------------------------------------------------------ */
/* Anuncios anclados (banners): se pueden ocultar y no vuelven a       */
/* aparecer. Funciona en el panel y en la portada (landing).           */
/* ------------------------------------------------------------------ */
(function () {
    'use strict';
    var KEY = 'ws_dismissed_announcements';
    function getDismissed() {
        try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
    }
    window.wsDismissAnnouncement = function (id, btn) {
        var list = getDismissed();
        if (list.indexOf(id) === -1) { list.push(id); }
        try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
        var banner = btn && btn.closest ? btn.closest('.ws-ann-banner') : null;
        if (banner) { banner.remove(); }
    };
    var dismissed = getDismissed();
    document.querySelectorAll('.ws-ann-banner').forEach(function (b) {
        var id = parseInt(b.getAttribute('data-ann') || '0', 10);
        if (dismissed.indexOf(id) !== -1) { b.remove(); }
    });
})();
