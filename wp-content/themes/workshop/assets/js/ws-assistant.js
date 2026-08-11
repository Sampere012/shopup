/* Workshop MultiTienda - Chatbot del sitio (asistente por rol y plan) */
(function () {
    'use strict';

    var C = window.WSBOT;
    if (!C || !C.show) { return; }

    var S = C.strings;
    var hasBizRole = C.role === 'owner' || C.role === 'storekeeper' || C.role === 'seller';
    var isPanel = C.inPanel && (hasBizRole || C.role === 'admin');
    var locked = C.inPanel && hasBizRole && !C.chatbot; // plan sin chatbot: solo upsell
    var mode = isPanel ? 'panel' : 'public';

    var open = false;
    var busy = false;

    var ls = window.localStorage || null;
    function getF(k) { try { return ls ? ls.getItem(k) : null; } catch (e) { return null; } }
    function setF(k, v) { try { if (ls) { ls.setItem(k, v); } } catch (e) {} }

    function track(intent, extra) {
        if (!C.trackUrl) { return; }
        try {
            var body = new URLSearchParams();
            body.append('ws_nonce', C.nonce);
            body.append('intent', intent);
            body.append('mode', mode);
            body.append('context', C.context || '');
            if (extra && extra.indexOf) { body.append('target', extra); }
            fetch(C.trackUrl, { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {});
        } catch (e) {}
    }

    /* ------------------------------------------------------------------ */
    /* Construcción del DOM                                                */
    /* ------------------------------------------------------------------ */

    var base = document.createElement('div');
    base.id = 'wsb-root';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'wsb-button';
    btn.setAttribute('aria-label', S.open);
    btn.innerHTML = '<span class="wsb-icon"><i class="fa-solid fa-robot" aria-hidden="true"></i></span>' +
        '<span class="wsb-badge" aria-hidden="true"></span>';
    base.appendChild(btn);

    var teaser = document.createElement('div');
    teaser.id = 'wsb-teaser';
    teaser.setAttribute('role', 'button');
    teaser.tabIndex = 0;
    teaser.innerHTML = '<i class="fa-solid fa-robot" aria-hidden="true"></i><span id="wsb-teaser-text"></span>';
    base.appendChild(teaser);

    var win = document.createElement('section');
    win.id = 'wsb-window';
    win.setAttribute('role', 'dialog');
    win.setAttribute('aria-label', S.title);
    win.innerHTML =
        '<header class="wsb-header">' +
            '<span class="wsb-avatar"><i class="fa-solid fa-robot" aria-hidden="true"></i></span>' +
            '<div class="wsb-head-titles"><strong id="wsb-title"></strong><span class="wsb-subtitle"><i class="fa-solid fa-circle" aria-hidden="true"></i> ' + S.subtitle + '</span></div>' +
            '<button type="button" class="wsb-close" aria-label="' + S.open + '"><i class="fa-solid fa-xmark"></i></button>' +
        '</header>' +
        '<div class="wsb-body" id="wsb-body"></div>' +
        '<button type="button" class="wsb-atajos" id="wsb-atajos"><i class="fa-solid fa-bolt" aria-hidden="true"></i> <span>Atajos</span></button>' +
        '<form class="wsb-input-row" id="wsb-form">' +
            '<input type="text" id="wsb-input" class="wsb-input" placeholder="' + S.placeholder + '" autocomplete="off" autocapitalize="off">' +
            '<button type="submit" class="wsb-send" aria-label="Enviar"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>' +
        '</form>';
    base.appendChild(win);
    document.body.appendChild(base);

    // Overlay que bloquea la interacción con el fondo mientras el chat está
    // abierto (tocar fuera de la ventana lo cierra).
    var overlay = document.createElement('div');
    overlay.id = 'wsb-overlay';
    overlay.className = 'wsb-overlay';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function () {
        if (open) { setOpen(false); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && open) { setOpen(false); }
    });

    var body = win.querySelector('#wsb-body');
    var input = win.querySelector('#wsb-input');
    var form = win.querySelector('#wsb-form');
    var wsbTitle = win.querySelector('#wsb-title');
    wsbTitle.textContent = S.title;
    var atajosBtn = win.querySelector('#wsb-atajos');

    function icon(name) {
        return name ? '<i class="fa-solid ' + name + '" aria-hidden="true"></i> ' : '';
    }

    function appendMsg(text, isUser) {
        var row = document.createElement('div');
        row.className = isUser ? 'wsb-msg wsb-user' : 'wsb-msg';
        var bubble = document.createElement('div');
        bubble.className = 'wsb-bubble';
        bubble.textContent = text;
        row.appendChild(bubble);
        body.appendChild(row);
        body.scrollTop = body.scrollHeight;
        return row;
    }

    function showTyping() {
        var row = document.createElement('div');
        row.className = 'wsb-msg wsb-typing-row';
        row.innerHTML = '<div class="wsb-bubble"><span class="wsb-typing"><i></i><i></i><i></i></span></div>';
        body.appendChild(row);
        body.scrollTop = body.scrollHeight;
        return row;
    }

    function appendChips(chips) {
        if (!chips || !chips.length) { return; }
        var row = document.createElement('div');
        row.className = 'wsb-chips-row';
        chips.forEach(function (chip) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'wsb-chip' + (chip.cls ? ' ' + chip.cls : '');
            b.innerHTML = '<i class="fa-solid ' + (chip.icon || 'fa-arrow-pointer') + '" aria-hidden="true"></i> <span></span>';
            if (chip.label) { b.querySelector('span').textContent = chip.label; }
            if (chip.click) {
                b.addEventListener('click', function () { chip.click(); });
            } else if (chip.send) {
                b.addEventListener('click', function () { sendUser(chip.send); });
            } else if (chip.url) {
                b.addEventListener('click', function () {
                    track(chip.track || 'shortcut', chip.label);
                    if (chip.newTab) { window.open(chip.url, '_blank', 'noopener'); }
                    else { window.location.href = chip.url; }
                });
            }
            row.appendChild(b);
        });
        var prev = body.querySelector('.wsb-chips-row');
        if (prev && prev.parentNode === body) {
            // mover la fila de chips al final (siempre debajo del último mensaje)
            body.removeChild(prev);
        }
        body.appendChild(row);
        body.scrollTop = body.scrollHeight;
    }

    function getShortcut(id) {
        var list = isPanel ? (C.shortcuts.panel || {}) : (C.shortcuts.public || {});
        return list[id] || null;
    }

    /* ------------------------------------------------------------------ */
    /* Respuestas                                                          */
    /* ------------------------------------------------------------------ */

    function chipsFor(ids) {
        return ids.map(function (id) {
            var sc = getShortcut(id);
            if (!sc) { return null; }
            return { label: sc.label, url: sc.url, icon: sc.icon, track: 'shortcut:' + id };
        }).filter(Boolean);
    }

    function reply(text, chips, intent) {
        track(intent || 'reply');
        var t = showTyping();
        window.setTimeout(function () {
            if (t && t.parentNode) { body.removeChild(t); }
            appendMsg(text, false);
            chatHistory.push({ role: 'assistant', content: text });
            if (chatHistory.length > 20) { chatHistory = chatHistory.slice(-20); }
            if (chips && chips.length) { appendChips(chips); }
        }, 450 + Math.min(500, text.length * 8));
    }

    /* ------------------------------------------------------------------ */
    /* Datos en vivo: búsqueda, seguimiento de pedido y resumen del negocio */
    /* ------------------------------------------------------------------ */

    var lastUserText = '';
    var pendingAsk = null; // {type:'search'} | {type:'order_number'} | {type:'order_phone', number}
    var pendingAction = null; // {flow, step, data} — formularios guiados del panel
    var mem = { lastAction: '', lastEntity: '', count: 0 };
    var chatHistory = [];

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function api(action, data, cb) {
        if (!C.apiUrl) { cb({ success: false, data: { msg: 'No disponible.' } }); return; }
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('ws_nonce', C.nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        fetch(C.apiUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (json) { cb(json); })
            .catch(function () { cb({ success: false, data: { msg: 'Sin conexión para consultar ahora.' } }); });
    }

    function removeTyping(t) {
        if (t && t.parentNode) { body.removeChild(t); }
    }

    // Tarjeta con filas enlazables (resultados de búsqueda, pedido, resumen).
    function appendCard(title, rows, opts) {
        opts = opts || {};
        var row = document.createElement('div');
        row.className = 'wsb-msg';
        var card = document.createElement('div');
        card.className = 'wsb-card';
        var html = '<div class="wsb-card-title">' + escapeHtml(title) + '</div>';
        (rows || []).forEach(function (it) {
            html += '<a class="wsb-card-item" href="' + escapeHtml(it.url || '#') + '" target="_blank" rel="noopener">' +
                '<span class="wsb-card-item-name">' + escapeHtml(it.name) + '</span>' +
                '<span class="wsb-card-item-meta">' + (it.meta || '') + '</span>' +
                '</a>';
        });
        card.innerHTML = html;
        row.appendChild(card);
        body.appendChild(row);
        body.scrollTop = body.scrollHeight;
        if (opts.chips && opts.chips.length) { appendChips(opts.chips); }
        return row;
    }

    // Widgets ricos: tarjetas visuales (planes, tiendas, secciones) en vez de
    // solo texto. Cada widget es una columna de items con icono/imagen,
    // titulo, detalle y accion. Los datos llegan del endpoint ws_chatbot_cards.
    function starsHtml(rating) {
        var n = Math.round(Number(rating) || 0);
        var out = '';
        for (var i = 1; i <= 5; i++) {
            out += '<i class="fa-solid fa-star' + (i <= n ? ' is-on' : '') + '" aria-hidden="true"></i>';
        }
        return '<span class="wsb-stars">' + out + '</span>';
    }

    function appendWidget(title, items, opts) {
        opts = opts || {};
        var row = document.createElement('div');
        row.className = 'wsb-msg';
        var card = document.createElement('div');
        card.className = 'wsb-widget';
        var html = '';
        if (title) { html += '<div class="wsb-widget-title">' + escapeHtml(title) + '</div>'; }
        (items || []).forEach(function (it) {
            var badge = it.badge ? '<span class="wsb-widget-badge ' + (it.badgeCls || 'is-hot') + '">' + escapeHtml(it.badge) + '</span>' : '';
            var icon = it.icon ? '<span class="wsb-widget-ico"><i class="fa-solid ' + escapeHtml(it.icon) + '"></i></span>' : '';
            var img = it.img ? '<span class="wsb-widget-img" style="background-image:url(' + escapeHtml(it.img) + ')"></span>' : '';
            var meta = it.meta ? '<span class="wsb-widget-meta">' + escapeHtml(it.meta) + '</span>' : '';
            var price = it.price ? '<b class="wsb-price">' + escapeHtml(it.price) + '</b>' : '';
            var feats = (it.features || []).length
                ? '<span class="wsb-widget-feats">' + it.features.map(function (f) { return '<span class="wsb-feat"><i class="fa-solid fa-circle-check"></i> ' + escapeHtml(f) + '</span>'; }).join('') + '</span>'
                : '';
            var stars = it.rating ? '<span class="wsb-widget-rating">' + starsHtml(it.rating) + (it.reviews ? ' <em>' + escapeHtml(it.reviews) + '</em>' : '') + '</span>' : '';
            html += '<a class="wsb-widget-item' + (it.recommended ? ' is-recommended' : '') + '" href="' + escapeHtml(it.url || '#') + '" target="_blank" rel="noopener">' +
                (img || icon) +
                '<span class="wsb-widget-body">' +
                    '<span class="wsb-widget-name">' + escapeHtml(it.name) + badge + '</span>' +
                    (it.duration ? '<span class="wsb-widget-duration">' + escapeHtml(it.duration) + '</span>' : '') +
                    (it.desc ? '<span class="wsb-widget-desc">' + escapeHtml(it.desc) + '</span>' : '') +
                    stars +
                    feats +
                    price +
                    meta +
                '</span>' +
                '<span class="wsb-widget-go"><i class="fa-solid fa-chevron-right"></i></span>' +
            '</a>';
        });
        if (!(items || []).length) { html += '<div class="wsb-widget-empty">' + (opts.empty || 'Sin resultados.') + '</div>'; }
        card.innerHTML = html;
        row.appendChild(card);
        body.appendChild(row);
        body.scrollTop = body.scrollHeight;
        if (opts.chips && opts.chips.length) { appendChips(opts.chips); }
        return row;
    }

    // --- Widgets: planes, tiendas y secciones ---
    function doPlans() {
        var t = showTyping();
        api('ws_chatbot_cards', { type: 'plans' }, function (json) {
            removeTyping(t);
            if (!json || !json.success || !json.data || !json.data.plans || !json.data.plans.length) {
                reply((json && json.data && json.data.msg) || 'Aún no hay planes publicados.', [], 'plans:none');
                return;
            }
            appendWidget('Planes disponibles:', json.data.plans.map(function (p) {
                return {
                    name: p.name,
                    badge: p.is_trial ? 'GRATIS' : (p.recommended ? 'RECOMENDADO' : ''),
                    badgeCls: p.is_trial ? 'is-free' : 'is-hot',
                    duration: p.duration,
                    features: p.features,
                    price: p.price,
                    icon: p.is_trial ? 'fa-gift' : 'fa-crown',
                    url: p.url,
                    recommended: p.recommended
                };
            }), {
                chips: C.urls.register ? [{ label: 'Crear mi negocio', url: C.urls.register, icon: 'fa-rocket' }] : []
            });
            track('cards:plans');
        });
    }

    function doStores() {
        var t = showTyping();
        api('ws_chatbot_cards', { type: 'stores' }, function (json) {
            removeTyping(t);
            if (!json || !json.success || !json.data || !json.data.stores || !json.data.stores.length) {
                reply((json && json.data && json.data.msg) || 'Aún no hay tiendas en el mercado.', [], 'stores:none');
                return;
            }
            var list = json.data.stores;
            appendWidget('Tiendas del mercado:', list.map(function (s) {
                return {
                    name: s.name,
                    img: s.logo || '',
                    icon: s.logo ? '' : 'fa-store',
                    rating: s.rating,
                    reviews: s.reviews ? '(' + s.reviews + ')' : '',
                    desc: s.pvs ? (s.pvs + ' punto(s) de venta') : '',
                    meta: s.reviews ? (s.reviews + ' reseña' + (s.reviews === 1 ? '' : 's')) : 'Nueva',
                    url: s.url
                };
            }), {
                chips: [{ label: 'Ver todas', url: C.urls.stores, icon: 'fa-store' }]
            });
            track('cards:stores');
        });
    }

    function doSections() {
        var t = showTyping();
        api('ws_chatbot_cards', { type: 'sections' }, function (json) {
            removeTyping(t);
            if (!json || !json.success || !json.data || !json.data.sections) {
                reply((json && json.data && json.data.msg) || 'No pude cargar las secciones.', [], 'sections:error');
                return;
            }
            appendWidget('Secciones del sitio:', json.data.sections.map(function (s) {
                return { name: s.label, desc: s.desc, icon: s.icon, url: s.url };
            }), {});
            track('cards:sections');
        });
    }

    // Contexto de la página actual: explica dónde está el usuario con datos
    // reales (C.page) y le ofrece navegar a otras secciones.
    function doPageContext() {
        var p = C.page || {};
        if (!p.key) { reply('No sé exactamente en qué sección estás ahora, pero puedo ayudarte con lo que necesites.', chipsFor(['marketplace', 'ayuda', 'contacto']), 'page:none'); return; }
        var msg = (p.desc ? p.desc + ' ' : '') + (p.hint || '');
        reply(msg, (p.sections ? Object.keys(p.sections).slice(0, 6).map(function (k) {
            var s = p.sections[k];
            return { label: s.label, url: s.url, icon: s.icon };
        }) : []), 'page:' + p.key);
    }

    function doSearch(q) {
        var t = showTyping();
        // Se envía el contexto de la página (negocio + ubicación) para que la
        // búsqueda pública apunte a la tienda correcta también en negocios con slug.
        api('ws_chatbot_search', { q: q, biz: C.bizSlug || '', loc: C.locSlug || '' }, function (json) {
            removeTyping(t);
            if (json && json.success) {
                var list = (json.data && json.data.products) || [];
                if (!list.length) {
                    reply('No encontré "' + q + '" disponible ahora mismo. ¿Pruebas con otra palabra o revisas todas las tiendas?',
                        [marketChip()].filter(Boolean), 'search:none');
                    return;
                }
                var rows = list.map(function (it) {
                    return {
                        name: it.name,
                        meta: (it.price_text || '') + (it.stock_text ? ' <span class="wsb-badge-stock' + (it.in_stock === false ? ' is-out' : '') + '">' + escapeHtml(it.stock_text) + '</span>' : '') + (it.where ? ' <span class="wsb-where">' + escapeHtml(it.where) + '</span>' : ''),
                        url: it.url
                    };
                });
                appendCard('Resultados de "' + q + '":', rows, { chips: [marketChip()].filter(Boolean) });
            } else {
                reply((json && json.data && json.data.msg) || 'No pude buscar en este momento.', [marketChip()].filter(Boolean), 'search:error');
            }
        });
    }

    function doSummary() {
        var t = showTyping();
        api('ws_chatbot_summary', {}, function (json) {
            removeTyping(t);
            if (!json || !json.success || !json.data || !json.data.summary) {
                reply((json && json.data && json.data.msg) || 'Aún no tienes ubicaciones asignadas o no pude obtener el resumen ahora.', [], 'summary:error');
                return;
            }
            var s = json.data.summary;
            var rows = [
                { name: 'Ventas de hoy', meta: '<b>' + escapeHtml(s.sales_text) + '</b>', url: s.urls.posSales },
                { name: 'Pedidos pendientes', meta: '<b>' + s.pending + '</b>', url: s.urls.orders },
                { name: 'Productos con stock bajo', meta: '<b>' + s.low_stock + '</b>', url: s.urls.stock },
                { name: 'Caja POS', meta: s.cash_open ? '<span class="wsb-badge-stock">Abierta</span>' : '<span class="wsb-badge-stock is-out">Cerrada</span>', url: s.urls.pos }
            ];
            appendCard('Resumen de tu negocio hoy:', rows, {
                chips: [
                    { label: 'Ver pedidos', url: s.urls.orders, icon: 'fa-cart-shopping' },
                    { label: 'Abrir POS', url: s.urls.pos, icon: 'fa-cash-register' }
                ]
            });
            track('summary:shown');
        });
    }

    function checkOrder(number, phone) {
        var t = showTyping();
        api('ws_public_order_status', { number: number, phone: phone }, function (json) {
            removeTyping(t);
            if (!json || !json.success || !json.data || !json.data.order) {
                reply((json && json.data && json.data.msg) || 'No encontré el pedido. Revisa el número y el teléfono e inténtalo de nuevo.',
                    [marketChip(), contactChip()].filter(Boolean), 'trackOrder:error');
                return;
            }
            var o = json.data.order;
            // appendCard escapa name y href; los meta llevan HTML controlado.
            var rows = [
                { name: o.status_label, meta: '<span class="wsb-badge-stock">' + escapeHtml(o.status) + '</span>', url: '#' },
                { name: o.date, meta: '', url: '#' },
                { name: 'Total', meta: '<b>' + escapeHtml(o.currency) + ' ' + formatNum(o.total) + '</b>', url: '#' }
            ];
            (o.items || []).slice(0, 4).forEach(function (it) {
                rows.push({ name: it.product_name, meta: it.qty + ' × ' + formatNum(it.price), url: '#' });
            });
            if ((o.items || []).length > 4) {
                rows.push({ name: '…', meta: 'y ' + (o.items.length - 4) + ' producto(s) más', url: '#' });
            }
            appendCard('Pedido ' + escapeHtml(o.number), rows, { chips: [marketChip()].filter(Boolean) });
            track('trackOrder:ok');
        });
    }

    function formatNum(v) {
        v = Number(v) || 0;
        return v.toLocaleString('es-CU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function marketChip() {
        return isPanel ? chipsFor(['products'])[0] : { label: 'Ver tiendas', url: C.urls.stores, icon: 'fa-store' };
    }
    function contactChip() {
        return { label: 'Contacto', url: C.urls.contacto, icon: 'fa-envelope' };
    }

    // Extrae el término de búsqueda de frases como "buscar camisa" o "¿tienes arroz?"
    function extractQuery(text) {
        var m = String(text || '').match(/(?:buscar|busco|busca|tienes|tienen|tiene|hay|disponible|precio de|donde encuentro|encuentro)\s*(.+)$/i);
        if (m && m[1]) {
            return m[1].trim().replace(/[?.!]+$/, '');
        }
        return '';
    }

    function handlePending(value) {
        if (pendingAction) { handlePendingAction(value); return; }
        var low = value.toLowerCase();
        if (low.indexOf('cancelar') > -1 || low.indexOf('salir') > -1 || low === 'nada' || low === 'no' || low === 'olvidalo' || low === 'olvídalo') {
            pendingAsk = null;
            reply('No hay problema. ¿Te ayudo con otra cosa?', chipsFor(['marketplace', 'ayuda', 'contacto']), 'cancel');
            busy = false;
            return;
        }
        var p = pendingAsk;
        if (p.type === 'search') {
            pendingAsk = null;
            busy = false;
            doSearch(value);
            return;
        }
        if (p.type === 'order_number') {
            pendingAsk = { type: 'order_phone', number: value };
            busy = false;
            reply('Perfecto. Ahora dime el teléfono que usaste al hacer el pedido (para verificar que es tuyo):', [], 'trackOrder:askPhone');
            return;
        }
        if (p.type === 'order_phone') {
            pendingAsk = null;
            busy = false;
            checkOrder(p.number, value);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fase 2: el bot EJECUTA acciones del panel (formularios guiados)      */
    /* ------------------------------------------------------------------ */

    function setMem(action, entity) {
        mem.lastAction = action;
        mem.lastEntity = entity || '';
        mem.count = (mem.count || 0) + 1;
        try { setF('wsb_mem', JSON.stringify(mem)); } catch (e) {}
    }

    function loadMem() {
        try {
            var raw = getF('wsb_mem');
            if (raw) {
                var parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') { mem = parsed; }
            }
        } catch (e) {}
    }

    function cancelFlow() {
        pendingAction = null;
        reply('Entendido, lo dejamos ahí. ¿Algo más?', chipsFor(isPanel ? ['atajos'] : ['marketplace', 'ayuda', 'contacto']), 'action:cancel');
        busy = false;
    }

    // Arranca un flujo solo si no hay otro en curso (evita estados solapados).
    function startFlowGuard(name) {
        if (pendingAction || pendingAsk) {
            reply('Ya tienes una acción en curso. Escríbela o "cancelar" para empezar otra.', [], 'action:busy');
            busy = false;
            return;
        }
        startFlow(name);
    }

    function ask(text, chips, intent) {
        reply(text, chips || [], intent);
        busy = false;
    }

    // Pide confirmación con chips Sí/No (las acciones destructivas la exigen).
    function confirmAsk(text, intent) {
        reply(text, [
            { label: 'Sí, confirmar', cls: 'wsb-chip-success', icon: 'fa-check', click: function () { pendingAction.step = 'execute'; executeFlow(); } },
            { label: 'No, cancelar', cls: 'wsb-chip-danger', icon: 'fa-xmark', click: cancelFlow }
        ], intent);
        busy = false;
    }

    function confirmYes(low) {
        return low === 'si' || low === 'sí' || low === 'ok' || low === 'confirmar' || low === 'dale' || low === 'confirmo';
    }

    // Busca productos en el panel y ofrece elegir con chips.
    function pickFromSearch(q, onPick) {
        var t = showTyping();
        api('ws_chatbot_search', { q: q }, function (json) {
            removeTyping(t);
            if (!json || !json.success || !(json.data && json.data.products && json.data.products.length)) {
                reply('No encontré "' + q + '" en tu inventario. Prueba con otra palabra o escribe cancelar.', [{ label: 'Cancelar', cls: 'wsb-chip-danger', icon: 'fa-xmark', click: cancelFlow }], 'action:pick:none');
                busy = false;
                return;
            }
            var seen = {};
            var rows = [];
            (json.data.products || []).forEach(function (r) {
                if (!seen[r.id]) { seen[r.id] = 1; rows.push(r); }
            });
            reply('¿Cuál de estos?', rows.slice(0, 6).map(function (r) {
                return {
                    label: r.name + (r.price_text ? ' · ' + r.price_text : ''),
                    cls: 'wsb-chip-pick',
                    icon: 'fa-box',
                    click: function () { onPick(r); }
                };
            }), 'action:pick:list');
            busy = false;
        });
    }

    // Ejecuta una secuencia de llamadas API (bulk) y resume con los resultados.
    function execSeq(list, stepFn, done) {
        var ok = 0, err = '', i = 0;
        function next() {
            if (i >= list.length) { done(ok, err); return; }
            stepFn(list[i], function (json) {
                if (json && json.success) { ok++; }
                else if (!err) { err = (json && json.data && json.data.msg) || ''; }
                i++;
                next();
            });
        }
        next();
    }

    // Resuelve varios nombres a productos reales (para tareas en lote).
    function resolveProductsBulk(names, done) {
        var found = [];
        var seen = {};
        execSeq(names, function (n, next) {
            api('ws_chatbot_search', { q: n }, function (json) {
                var r = json && json.success && json.data && json.data.products && json.data.products[0];
                if (r && !seen[r.id]) { seen[r.id] = 1; found.push(r); }
                next(json || { success: false });
            });
        }, function () { done(found); });
    }

    function reportLabel(type) {
        return ({ sales: 'de ventas', stock: 'de stock', orders: 'de pedidos', workers: 'del equipo', security: 'de seguridad', summary: 'resumen' })[type] || 'resumen';
    }

    function reportTypeFromText(text) {
        var t = normText(text);
        if (t.indexOf('venta') > -1 || t.indexOf('vend') > -1 || t.indexOf('caja') > -1) { return 'sales'; }
        if (t.indexOf('stock') > -1 || t.indexOf('inventario') > -1 || t.indexOf('existencia') > -1) { return 'stock'; }
        if (t.indexOf('pedido') > -1 || t.indexOf('orden') > -1) { return 'orders'; }
        if (t.indexOf('equipo') > -1 || t.indexOf('trabajador') > -1 || t.indexOf('empleado') > -1 || t.indexOf('personal') > -1) { return 'workers'; }
        if (t.indexOf('segur') > -1 || t.indexOf('acceso') > -1 || t.indexOf('login') > -1) { return 'security'; }
        if (t.indexOf('logs') > -1 || t.indexOf('errores') > -1 || t.indexOf('incidente') > -1) { return 'logs'; }
        if (t.indexOf('resumen') > -1 || t.indexOf('todo') > -1 || t.indexOf('completo') > -1) { return 'summary'; }
        return '';
    }

    // Interpreta fechas en español para programar tareas.
    function parseWhenText(text) {
        var w = normText(text);
        var m;
        if (w === 'ya' || w.indexOf('ahora') > -1) { return { when: 'ahora', label: 'ahora mismo', recurring: false }; }
        m = w.match(/en\s+(\d+)\s*(hora|h|minuto|min|dia|d)/);
        if (m) {
            var n = parseInt(m[1], 10);
            var u = (m[2][0] === 'h') ? 'hora(s)' : ((m[2][0] === 'd') ? 'día(s)' : 'minuto(s)');
            return { when: 'in' + n + m[2], label: 'en ' + n + ' ' + u, recurring: false };
        }
        if (w.indexOf('manana') > -1) {
            m = w.match(/(\d{1,2})(?::(\d{2}))?/);
            var h1 = m ? parseInt(m[1], 10) : 8;
            var mi1 = (m && m[2]) ? parseInt(m[2], 10) : 0;
            return { when: 'manana ' + h1 + ':' + (mi1 < 10 ? '0' : '') + mi1, label: 'mañana a las ' + h1 + ':' + (mi1 < 10 ? '0' : '') + mi1, recurring: false };
        }
        if (w.indexOf('hoy') > -1) {
            m = w.match(/(\d{1,2})(?::(\d{2}))?/);
            if (m) {
                var h2 = parseInt(m[1], 10);
                var mi2 = (m[2] ? parseInt(m[2], 10) : 0);
                return { when: 'hoy ' + h2 + ':' + (mi2 < 10 ? '0' : '') + mi2, label: 'hoy a las ' + h2 + ':' + (mi2 < 10 ? '0' : '') + mi2, recurring: false };
            }
            return { when: 'hoy 20:00', label: 'hoy a las 20:00', recurring: false };
        }
        if (w.indexOf('cada dia') > -1 || w.indexOf('diario') > -1 || w.indexOf('todos los dias') > -1) {
            m = w.match(/(\d{1,2})(?::(\d{2}))?/);
            var h3 = m ? parseInt(m[1], 10) : 8;
            var mi3 = (m && m[2]) ? parseInt(m[2], 10) : 0;
            return { when: 'cada dia ' + h3 + ':' + (mi3 < 10 ? '0' : '') + mi3, label: 'cada día a las ' + h3 + ':' + (mi3 < 10 ? '0' : '') + mi3, recurring: true };
        }
        return null;
    }

    function reportTypeChips(flow) {
        return [
            { label: 'Ventas', cls: 'wsb-chip-pick', icon: 'fa-cash-register', click: function () { pendingAction.data.type = 'sales'; flow === 'schedule' ? scheduleFlow('when') : reportFlow('days'); } },
            { label: 'Stock', cls: 'wsb-chip-pick', icon: 'fa-warehouse', click: function () { pendingAction.data.type = 'stock'; flow === 'schedule' ? scheduleFlow('when') : reportFlow('days'); } },
            { label: 'Pedidos', cls: 'wsb-chip-pick', icon: 'fa-cart-shopping', click: function () { pendingAction.data.type = 'orders'; flow === 'schedule' ? scheduleFlow('when') : reportFlow('days'); } },
            { label: 'Equipo', cls: 'wsb-chip-pick', icon: 'fa-users', click: function () { pendingAction.data.type = 'workers'; flow === 'schedule' ? scheduleFlow('when') : reportFlow('days'); } },
            { label: 'Seguridad', cls: 'wsb-chip-pick', icon: 'fa-shield-halved', click: function () { pendingAction.data.type = 'security'; flow === 'schedule' ? scheduleFlow('when') : reportFlow('days'); } },
            { label: 'Logs', cls: 'wsb-chip-pick', icon: 'fa-file-lines', click: function () { pendingAction.data.type = 'logs'; flow === 'schedule' ? scheduleFlow('when') : reportFlow('days'); } },
            { label: 'Resumen', cls: 'wsb-chip-pick', icon: 'fa-chart-line', click: function () { pendingAction.data.type = 'summary'; flow === 'schedule' ? scheduleFlow('when') : reportFlow('days'); } }
        ];
    }

    function reportFlow(step) {
        if (step === 'ask') {
            pendingAction = { flow: 'report', step: 'type', data: {} };
            ask('¿Qué reporte quieres?', reportTypeChips('report'), 'report:type');
            return;
        }
        if (step === 'days') {
            ask('¿De qué período?', [
                { label: 'Hoy', cls: 'wsb-chip-pick', icon: 'fa-calendar-day', click: function () { pendingAction.data.days = 1; runReportNow(); } },
                { label: '7 días', cls: 'wsb-chip-pick', icon: 'fa-calendar-week', click: function () { pendingAction.data.days = 7; runReportNow(); } },
                { label: '30 días', cls: 'wsb-chip-pick', icon: 'fa-calendar', click: function () { pendingAction.data.days = 30; runReportNow(); } }
            ], 'report:days');
            return;
        }
    }

    function runReportNow() {
        var d = pendingAction.data;
        var t = showTyping();
        api('ws_chatbot_report', { type: d.type, days: String(d.days || 1) }, function (json) {
            removeTyping(t);
            pendingAction = null;
            if (json && json.success && json.data && json.data.text) {
                reply(json.data.text, [
                    { label: 'Programarlo', cls: 'wsb-chip-success', icon: 'fa-clock', click: function () { startFlowGuard('schedule'); } },
                    { label: 'Otro reporte', icon: 'fa-chart-line', click: function () { startFlowGuard('report'); } },
                    { label: 'Mis reportes', icon: 'fa-list-check', click: function () { myTasks(); } }
                ], 'report:ok');
            } else {
                reply((json && json.data && json.data.msg) || 'No pude generar el reporte.', [], 'report:error');
            }
            busy = false;
        });
    }

    function scheduleFlow(step) {
        if (step === 'ask') {
            pendingAction = { flow: 'schedule', step: 'type', data: {} };
            ask('¡Claro! Puedo programar reportes que se entregan solos. ¿Qué reporte quieres programar?', reportTypeChips('schedule'), 'schedule:type');
            return;
        }
        if (step === 'when') {
            pendingAction.step = 'when';
            ask('¿Cuándo lo quieres? Ejemplos: "en 2 horas", "mañana a las 09:00", "cada día a las 08:00", "hoy a las 18:00":', [
                { label: 'Ahora', cls: 'wsb-chip-pick', icon: 'fa-bolt', click: function () { scheduleWhen('ahora'); } },
                { label: 'En 2 horas', cls: 'wsb-chip-pick', icon: 'fa-clock', click: function () { scheduleWhen('en 2 horas'); } },
                { label: 'Mañana 08:00', cls: 'wsb-chip-pick', icon: 'fa-sun', click: function () { scheduleWhen('manana a las 08:00'); } },
                { label: 'Cada día 08:00', cls: 'wsb-chip-pick', icon: 'fa-calendar-check', click: function () { scheduleWhen('cada dia a las 08:00'); } }
            ], 'schedule:when');
            return;
        }
    }

    function scheduleWhen(text) {
        var r = parseWhenText(text);
        if (!r) { ask('No entendí la fecha. Prueba: "en 2 horas", "mañana a las 09:00"...', [], 'schedule:when'); return; }
        var d = pendingAction.data;
        d.when = r.when; d.whenLabel = r.label; d.recurring = r.recurring;
        pendingAction.step = 'confirm';
        confirmAsk('¿Confirmas programar el reporte ' + reportLabel(d.type) + ' para ' + r.label + '?', 'schedule:confirm');
    }

    function myTasks() {
        var t = showTyping();
        api('ws_chatbot_tasks', {}, function (json) {
            removeTyping(t);
            if (!json || !json.success || !(json.data && json.data.tasks && json.data.tasks.length)) {
                reply('No tienes reportes programados. Puedo programar uno: ventas, stock, pedidos, equipo, seguridad o un resumen diario automático.', [{ label: 'Programar', cls: 'wsb-chip-success', icon: 'fa-clock', click: function () { startFlowGuard('schedule'); } }], 'tasks:empty');
                busy = false;
                return;
            }
            var rows = (json.data.tasks || []).map(function (tk) {
                var meta = 'Para ' + tk.when_label + (tk.recurring ? ' · cada día' : '') + (tk.status === 'done' ? ' · completado' : ' · pendiente') + (tk.last_result ? ' · Último: ' + tk.last_result.replace(/\n/g, ' ').slice(0, 80) : '');
                return { name: tk.label, meta: meta, url: '#' };
            });
            appendCard('Tus reportes programados:', rows, {
                chips: [{ label: 'Programar otro', cls: 'wsb-chip-success', icon: 'fa-clock', click: function () { startFlowGuard('schedule'); } }]
            });
            busy = false;
        });
    }

    function handlePendingAction(value) {
        var low = String(value || '').toLowerCase();
        if (low.indexOf('cancelar') > -1 || low === 'no' || low === 'nada' || low === 'salir' || low.indexOf('olvid') > -1) {
            if (pendingAction.step === 'confirm') { cancelFlow(); return; }
            pendingAction = null;
            reply('No hay problema. ¿Te ayudo con otra cosa?', chipsFor(isPanel ? ['atajos'] : ['marketplace', 'ayuda', 'contacto']), 'cancel');
            busy = false;
            return;
        }
        var p = pendingAction;
        var d = p.data;

        if (p.flow === 'product_new') {
            if (p.step === 'name') {
                if (!value.trim()) { ask('El nombre no puede quedar vacío:', [], 'action:product_new:name'); return; }
                d.names = value.split(/[,;\n]/).map(function (s) { return s.trim(); }).filter(Boolean);
                if (!d.names.length) { ask('Escribe al menos un nombre:', [], 'action:product_new:name'); return; }
                d.name = d.names[0];
                p.step = 'price';
                ask(d.names.length > 1 ? 'Perfecto, crearé ' + d.names.length + ' productos (' + d.names.join(', ') + '). ¿Qué precio de venta tienen? (uno para todos, ej: 150):' : '¿Cuál es el precio de venta? (ej: 150):', [], 'action:product_new:price');
            } else if (p.step === 'price') {
                var pr = parseFloat(value.replace(',', '.').replace(/[^\d.]/g, ''));
                if (isNaN(pr) || pr < 0) { ask('Escribe un precio válido (ej: 150):', [], 'action:product_new:price'); return; }
                d.sale_price = pr;
                p.step = 'min';
                ask(d.names.length > 1 ? '¿Stock mínimo para recibir avisos? (0 si no quieres, aplica a todos):' : '¿Stock mínimo para recibir avisos? (0 si no quieres):', [], 'action:product_new:min');
            } else if (p.step === 'min') {
                var mn = parseFloat(value.replace(',', '.').replace(/[^\d.]/g, ''));
                d.min_stock = isNaN(mn) ? 0 : mn;
                p.step = 'confirm';
                confirmAsk(d.names.length > 1 ? '¿Confirmas crear ' + d.names.length + ' productos (' + d.names.join(', ') + ') a ' + formatNum(d.sale_price) + ' cada uno?' : '¿Confirmas crear el producto "' + d.name + '" a ' + formatNum(d.sale_price) + '?', 'action:product_new:confirm');
            } else if (p.step === 'confirm') {
                if (confirmYes(low)) { p.step = 'execute'; executeFlow(); }
                else { ask('Escribe "confirmar" para continuar o "cancelar" para salir:', [], 'action:confirm:again'); }
            }
            return;
        }

        if (p.flow === 'product_edit') {
            if (p.step === 'pick') {
                var enames = value.split(/[,;\n]/).map(function (s) { return s.trim(); }).filter(Boolean);
                if (enames.length > 1) {
                    resolveProductsBulk(enames, function (found) {
                        if (!found.length) { reply('No encontré ninguno de esos productos.', [{ label: 'Cancelar', cls: 'wsb-chip-danger', icon: 'fa-xmark', click: cancelFlow }], 'action:edit:none'); busy = false; return; }
                        d.list = found;
                        p.step = 'field';
                        ask('¿Qué quieres cambiar de esos ' + found.length + ' productos?', [
                            { label: 'Nombre', cls: 'wsb-chip-pick', icon: 'fa-font', click: function () { d.field = 'name'; p.step = 'value'; ask('Escribe el nuevo nombre (aplica a todos):', [], 'action:edit:name'); } },
                            { label: 'Precio de venta', cls: 'wsb-chip-pick', icon: 'fa-tag', click: function () { d.field = 'price'; p.step = 'value'; ask('Escribe el nuevo precio para todos (ej: 150):', [], 'action:edit:price'); } }
                        ], 'action:edit:field');
                    });
                } else {
                    pickFromSearch(value, function (r) {
                        d.list = [r]; d.product = r;
                        p.step = 'field';
                        ask('¿Qué quieres cambiar de "' + r.name + '"?', [
                            { label: 'Nombre', cls: 'wsb-chip-pick', icon: 'fa-font', click: function () { d.field = 'name'; p.step = 'value'; ask('Escribe el nuevo nombre:', [], 'action:edit:name'); } },
                            { label: 'Precio de venta', cls: 'wsb-chip-pick', icon: 'fa-tag', click: function () { d.field = 'price'; p.step = 'value'; ask('Escribe el nuevo precio (ej: 150):', [], 'action:edit:price'); } }
                        ], 'action:edit:field');
                    });
                }
            } else if (p.step === 'field') {
                if (low === '1' || low === 'nombre' || low === 'name') { d.field = 'name'; p.step = 'value'; ask((d.list && d.list.length > 1 ? 'Escribe el nuevo nombre (aplica a ' + d.list.length + '):' : 'Escribe el nuevo nombre:'), [], 'action:edit:name'); }
                else if (low === '2' || low === 'precio' || low === 'price' || low.indexOf('precio') > -1) { d.field = 'price'; p.step = 'value'; ask((d.list && d.list.length > 1 ? 'Escribe el nuevo precio para todos (ej: 150):' : 'Escribe el nuevo precio (ej: 150):'), [], 'action:edit:price'); }
                else { ask('Elige 1) Nombre o 2) Precio de venta:', [], 'action:edit:field'); }
            } else if (p.step === 'value') {
                var many = d.list && d.list.length > 1;
                if (d.field === 'name') {
                    if (!value.trim()) { ask('El nombre no puede quedar vacío:', [], 'action:edit:name'); return; }
                    d.value = value;
                    p.step = 'confirm';
                    confirmAsk(many ? '¿Confirmas cambiar el nombre a "' + value + '" en ' + d.list.length + ' productos?' : '¿Confirmas cambiar el nombre a "' + value + '"?', 'action:edit:confirm');
                } else {
                    var vp = parseFloat(value.replace(',', '.').replace(/[^\d.]/g, ''));
                    if (isNaN(vp) || vp < 0) { ask('Precio inválido. Escribe el nuevo precio (ej: 150):', [], 'action:edit:price'); return; }
                    d.value = vp;
                    p.step = 'confirm';
                    confirmAsk(many ? '¿Confirmas el nuevo precio ' + formatNum(vp) + ' en ' + d.list.length + ' productos?' : '¿Confirmas el nuevo precio ' + formatNum(vp) + '?', 'action:edit:confirm');
                }
            } else if (p.step === 'confirm') {
                if (confirmYes(low)) { p.step = 'execute'; executeFlow(); }
                else { ask('Escribe "confirmar" para continuar o "cancelar" para salir:', [], 'action:confirm:again'); }
            }
            return;
        }

        if (p.flow === 'product_delete') {
            if (p.step === 'pick') {
                var dnames = value.split(/[,;\n]/).map(function (s) { return s.trim(); }).filter(Boolean);
                if (dnames.length > 1) {
                    resolveProductsBulk(dnames, function (found) {
                        if (!found.length) { reply('No encontré ninguno de esos productos. Prueba con otra palabra.', [{ label: 'Cancelar', cls: 'wsb-chip-danger', icon: 'fa-xmark', click: cancelFlow }], 'action:delete:none'); busy = false; return; }
                        d.list = found;
                        p.step = 'confirm';
                        confirmAsk('⚠️ Vas a ELIMINAR ' + found.length + ' producto(s): ' + found.map(function (r) { return r.name; }).join(', ') + '. ¿Confirmas?', 'action:delete:confirm');
                    });
                } else {
                    pickFromSearch(value, function (r) {
                        d.list = [r]; d.product = r;
                        p.step = 'confirm';
                        confirmAsk('⚠️ Vas a ELIMINAR "' + r.name + '" para siempre. ¿Confirmas?', 'action:delete:confirm');
                    });
                }
            } else if (p.step === 'confirm') {
                if (confirmYes(low)) { p.step = 'execute'; executeFlow(); }
                else { ask('Escribe "confirmar" para continuar o "cancelar" para salir:', [], 'action:confirm:again'); }
            }
            return;
        }

        if (p.flow === 'restock') {
            if (p.step === 'pick') {
                pickFromSearch(value, function (r) {
                    d.product = r;
                    if (d.meta.locations.length > 1) {
                        p.step = 'loc';
                        ask('¿En qué tienda entra el stock?', d.meta.locations.map(function (l) {
                            return { label: l.name, cls: 'wsb-chip-pick', icon: 'fa-store', click: function () { d.location = l; p.step = 'qty'; ask('¿Cuántas unidades de "' + r.name + '" entran?', [], 'action:restock:qty'); } };
                        }), 'action:restock:loc');
                    } else {
                        d.location = d.meta.locations[0];
                        p.step = 'qty';
                        ask('¿Cuántas unidades de "' + r.name + '" entran en ' + d.location.name + '?', [], 'action:restock:qty');
                    }
                });
            } else if (p.step === 'loc') {
                var li = parseInt(low, 10);
                if (!isNaN(li) && d.meta.locations[li - 1]) { d.location = d.meta.locations[li - 1]; p.step = 'qty'; ask('¿Cuántas unidades entran en ' + d.location.name + '?', [], 'action:restock:qty'); }
                else { ask('Elige una tienda de la lista (número):', [], 'action:restock:loc'); }
            } else if (p.step === 'qty') {
                var q = parseFloat(value.replace(',', '.'));
                if (isNaN(q) || q <= 0) { ask('Cantidad inválida. ¿Cuántas unidades entran?', [], 'action:restock:qty'); return; }
                d.qty = q;
                p.step = 'confirm';
                confirmAsk('¿Confirmas entrada de ' + q + ' uds de "' + d.product.name + '" en ' + d.location.name + '?', 'action:restock:confirm');
            } else if (p.step === 'confirm') {
                if (confirmYes(low)) { p.step = 'execute'; executeFlow(); }
                else { ask('Escribe "confirmar" para continuar o "cancelar" para salir:', [], 'action:confirm:again'); }
            }
            return;
        }

        if (p.flow === 'order_accept' || p.flow === 'order_reject') {
            if (p.step === 'pick') {
                var found = null;
                (d.list || []).forEach(function (o) {
                    if (!found && (String(o.number) === value.trim() || String(o.id) === value.trim())) { found = o; }
                });
                if (found) { pickOrder(p.flow === 'order_accept' ? 'accept' : 'reject', found); }
                else { ask('Escribe el número del pedido (ej: 12) o toca un chip:', [], 'action:order:pick'); }
            } else if (p.step === 'confirm') {
                if (confirmYes(low)) { p.step = 'execute'; executeFlow(); }
                else { ask('Escribe "confirmar" para continuar o "cancelar" para salir:', [], 'action:confirm:again'); }
            }
            return;
        }

        if (p.flow === 'customer_new') {
            if (p.step === 'name') {
                if (!value.trim()) { ask('El nombre no puede quedar vacío:', [], 'action:customer:name'); return; }
                d.names = value.split(/[,;\n]/).map(function (s) { return s.trim(); }).filter(Boolean);
                if (!d.names.length) { ask('Escribe al menos un nombre:', [], 'action:customer:name'); return; }
                d.name = d.names[0];
                p.step = 'phone';
                ask(d.names.length > 1 ? 'Perfecto, crearé ' + d.names.length + ' clientes. ¿Qué teléfono les pongo? (uno para todos, o "ninguno"):' : '¿Teléfono? (opcional, ej: 5xxxxxxx o escribe "ninguno"):', [], 'action:customer:phone');
            } else if (p.step === 'phone') {
                d.phone = (low === 'ninguno' || low === 'ningun' || low === 'no') ? '' : value;
                p.step = 'confirm';
                confirmAsk(d.names.length > 1 ? '¿Confirmas crear ' + d.names.length + ' clientes (' + d.names.join(', ') + ')' + (d.phone ? ' · ' + d.phone : '') + '?' : '¿Confirmas crear el cliente "' + d.name + '"' + (d.phone ? ' · ' + d.phone : '') + '?', 'action:customer:confirm');
            } else if (p.step === 'confirm') {
                if (confirmYes(low)) { p.step = 'execute'; executeFlow(); }
                else { ask('Escribe "confirmar" para continuar o "cancelar" para salir:', [], 'action:confirm:again'); }
            }
            return;
        }

        if (p.flow === 'pos_cart') {
            if (p.step === 'pick') {
                pickFromSearch(value, addToPosCart);
            } else if (p.step === 'qty') {
                var q2 = parseFloat(value.replace(',', '.'));
                if (isNaN(q2) || q2 <= 0) { ask('Cantidad inválida. ¿Cuántas unidades?', [], 'action:pos:qty'); return; }
                var r = d.tmp;
                var cur = d.cart.filter(function (it) { return it.product_id === r.id; })[0];
                if (cur) { cur.qty += q2; } else { d.cart.push({ product_id: r.id, product_name: r.name, qty: q2, price: r.price }); }
                p.step = 'more';
                var tot = d.cart.reduce(function (a, it) { return a + it.qty * it.price; }, 0);
                ask('Agregado ✅ Llevas ' + d.cart.length + ' producto(s), total ' + formatNum(tot) + ' ' + (d.meta.currency || '') + '. ¿Seguimos?', [
                    { label: 'Agregar otro', cls: 'wsb-chip-pick', icon: 'fa-plus', click: function () { p.step = 'pick'; ask('¿Qué otro producto vendes? Escríbelo:', [], 'action:pos:pick'); } },
                    { label: 'Finalizar venta', cls: 'wsb-chip-success', icon: 'fa-flag-checkered', click: function () { p.step = 'confirm'; showPosSummary(); } },
                    { label: 'Cancelar', cls: 'wsb-chip-danger', icon: 'fa-xmark', click: cancelFlow }
                ], 'action:pos:more');
            } else if (p.step === 'more') {
                if (low === 'finalizar' || low === 'terminar' || low === 'cobrar' || low === 'listo') { p.step = 'confirm'; showPosSummary(); }
                else if (low === 'agregar otro' || low === 'agregar') { p.step = 'pick'; ask('¿Qué otro producto vendes?', [], 'action:pos:pick'); }
                else { p.step = 'pick'; pickFromSearch(value, addToPosCart); }
            } else if (p.step === 'confirm') {
                if (confirmYes(low)) { p.step = 'execute'; executeFlow(); }
                else { ask('Escribe "confirmar" para continuar o "cancelar" para salir:', [], 'action:confirm:again'); }
            }
            return;
        }

        if (p.flow === 'schedule') {
            if (p.step === 'type') {
                var rt = reportTypeFromText(value);
                if (rt) { d.type = rt; scheduleFlow('when'); return; }
                ask('Elige un reporte de la lista (ventas, stock, pedidos, equipo, seguridad o resumen):', [], 'schedule:type');
                return;
            }
            if (p.step === 'when') {
                var wr = parseWhenText(value);
                if (wr) {
                    d.when = wr.when; d.whenLabel = wr.label; d.recurring = wr.recurring;
                    p.step = 'confirm';
                    confirmAsk('¿Confirmas programar el reporte ' + reportLabel(d.type) + ' para ' + wr.label + '?', 'schedule:confirm');
                } else {
                    ask('No entendí la fecha. Ejemplos: "en 2 horas", "mañana a las 09:00", "cada día a las 08:00", "hoy a las 18:00", "ahora":', [], 'schedule:when');
                }
                return;
            }
            if (p.step === 'confirm') {
                if (confirmYes(low)) { p.step = 'execute'; executeFlow(); }
                else { ask('Escribe "confirmar" para continuar o "cancelar" para salir:', [], 'schedule:confirm'); }
                return;
            }
            return;
        }
    }

    function addToPosCart(r) {
        var p = pendingAction;
        var d = p.data;
        d.tmp = r;
        p.step = 'qty';
        var cur = d.cart.filter(function (it) { return it.product_id === r.id; })[0];
        ask((cur ? 'Ya tienes "' + cur.product_name + '" en la venta. ' : '') + '¿Cuántas unidades de "' + r.name + '"?', [], 'action:pos:qty');
    }

    function showPosSummary() {
        var d = pendingAction.data;
        var rows = d.cart.map(function (it) {
            return { name: it.product_name, meta: it.qty + ' × ' + formatNum(it.price) + ' = <b>' + formatNum(it.qty * it.price) + '</b>', url: '#' };
        });
        var total = d.cart.reduce(function (a, it) { return a + it.qty * it.price; }, 0);
        appendCard('Resumen de la venta (' + d.locName + '):', rows, {});
        var t = showTyping();
        window.setTimeout(function () {
            removeTyping(t);
            appendMsg('Total a cobrar: ' + formatNum(total) + ' ' + (d.meta.currency || '') + ' (efectivo).', false);
            appendChips([
                { label: 'Confirmar venta', cls: 'wsb-chip-success', icon: 'fa-check', click: function () { pendingAction.step = 'execute'; executeFlow(); } },
                { label: 'Cancelar', cls: 'wsb-chip-danger', icon: 'fa-xmark', click: cancelFlow }
            ]);
        }, 550);
        busy = false;
    }

    function executeFlow() {
        var p = pendingAction;
        if (!p) { busy = false; return; }
        // Evita el doble envío si se pulsa "Confirmar" dos veces.
        if (p.executing) { return; }
        p.executing = true;
        var d = p.data;

        if (p.flow === 'product_new') {
            var names = (d.names && d.names.length) ? d.names : [d.name];
            execSeq(names, function (n, next) {
                api('ws_save_product', { name: n, sale_price: String(d.sale_price), min_stock: String(d.min_stock || 0) }, next);
            }, function (okCount, firstErr) {
                pendingAction = null;
                if (okCount > 0) {
                    setMem('product_new', names.join(', '));
                    reply(okCount === names.length
                        ? '¡Listo! ✅ Creé ' + okCount + ' producto(s) a ' + formatNum(d.sale_price) + ' cada uno.'
                        : 'Se crearon ' + okCount + ' de ' + names.length + ' productos.' + (firstErr ? ' ' + firstErr : ''), [{ label: 'Ver productos', url: C.shortcuts.panel.products.url, icon: 'fa-boxes-stacked' }], 'action:product_new:ok');
                } else {
                    reply(firstErr || 'No se pudo crear ningún producto. Revisa los límites de tu plan.', [{ label: 'Reintentar', icon: 'fa-rotate', send: 'crear producto' }], 'action:product_new:error');
                }
                busy = false;
            });
        } else if (p.flow === 'product_edit') {
            var elist = (d.list && d.list.length) ? d.list : [d.product];
            execSeq(elist, function (r, next) {
                var payload = { id: r.id };
                if (d.field === 'name') { payload.name = d.value; } else { payload.sale_price = String(d.value); }
                api('ws_save_product', payload, next);
            }, function (okCount, firstErr) {
                pendingAction = null;
                if (okCount > 0) {
                    setMem('product_edit', (d.field === 'name' ? d.value : 'precio ' + d.value));
                    reply(okCount === elist.length ? 'Producto(s) actualizado(s) ✅ (' + okCount + ').' : 'Se actualizaron ' + okCount + ' de ' + elist.length + '.' + (firstErr ? ' ' + firstErr : ''), [{ label: 'Ver productos', url: C.shortcuts.panel.products.url, icon: 'fa-boxes-stacked' }], 'action:product_edit:ok');
                } else {
                    reply(firstErr || 'No se pudo actualizar el producto.', [], 'action:product_edit:error');
                }
                busy = false;
            });
        } else if (p.flow === 'product_delete') {
            var dlist = (d.list && d.list.length) ? d.list : [d.product];
            execSeq(dlist, function (r, next) {
                api('ws_delete_product', { id: r.id }, next);
            }, function (okCount, firstErr) {
                pendingAction = null;
                if (okCount > 0) {
                    setMem('product_delete', dlist.length + ' productos');
                    reply(okCount === dlist.length ? 'Productos eliminados ✅ (' + okCount + ').' : 'Se eliminaron ' + okCount + ' de ' + dlist.length + '.' + (firstErr ? ' ' + firstErr : ''), [], 'action:product_delete:ok');
                } else {
                    reply(firstErr || 'No se pudo eliminar el producto.', [], 'action:product_delete:error');
                }
                busy = false;
            });
        } else if (p.flow === 'restock') {
            api('ws_stock_move', { type: 'entrada', product_id: d.product.id, location_id: d.location.id, qty: String(d.qty) }, function (json) {
                if (json && json.success) {
                    setMem('restock', d.product.name + ' +' + d.qty);
                    pendingAction = null;
                    reply('Entrada registrada ✅ ' + d.qty + ' uds de "' + d.product.name + '" en ' + d.location.name + '. Stock actual: ' + formatNum((json.data && json.data.qty) || 0) + '.', [{ label: 'Ver stock', url: C.shortcuts.panel.stock.url, icon: 'fa-warehouse' }], 'action:restock:ok');
                } else {
                    pendingAction = null;
                    reply((json && json.data && json.data.msg) || 'No se pudo registrar la entrada.', [], 'action:restock:error');
                }
                busy = false;
            });
        } else if (p.flow === 'order_accept') {
            api('ws_order_accept', { id: d.order.id }, function (json) {
                pendingAction = null;
                reply((json && json.success) ? 'Pedido Nº ' + d.order.number + ' aceptado ✅ (el stock se descuenta automáticamente).' : ((json && json.data && json.data.msg) || 'No se pudo aceptar el pedido.'), [{ label: 'Ver pedidos', url: C.shortcuts.panel.orders.url, icon: 'fa-cart-shopping' }], 'action:order_accept');
                busy = false;
            });
        } else if (p.flow === 'order_reject') {
            api('ws_order_reject', { id: d.order.id }, function (json) {
                pendingAction = null;
                reply((json && json.success) ? 'Pedido Nº ' + d.order.number + ' rechazado.' : ((json && json.data && json.data.msg) || 'No se pudo rechazar el pedido.'), [{ label: 'Ver pedidos', url: C.shortcuts.panel.orders.url, icon: 'fa-cart-shopping' }], 'action:order_reject');
                busy = false;
            });
        } else if (p.flow === 'customer_new') {
            var cnames = (d.names && d.names.length) ? d.names : [d.name];
            execSeq(cnames, function (n, next) {
                api('ws_customers_save', { name: n, phone: d.phone || '' }, next);
            }, function (okCount, firstErr) {
                pendingAction = null;
                if (okCount > 0) {
                    setMem('customer_new', cnames.join(', '));
                    reply(okCount === cnames.length ? 'Clientes creados ✅ (' + okCount + ').' : 'Se crearon ' + okCount + ' de ' + cnames.length + ' clientes.' + (firstErr ? ' ' + firstErr : ''), [{ label: 'Ver clientes', url: C.shortcuts.panel.customers.url, icon: 'fa-users' }], 'action:customer_new:ok');
                } else {
                    reply(firstErr || 'No se pudo crear el cliente.', [], 'action:customer_new:error');
                }
                busy = false;
            });
        } else if (p.flow === 'schedule') {
            api('ws_chatbot_schedule', { type: d.type, when: d.when, recurring: d.recurring ? '1' : '0' }, function (json) {
                pendingAction = null;
                if (json && json.success && json.data && json.data.when_label) {
                    reply('¡Listo! ✅ Reporte ' + reportLabel(d.type) + ' programado para ' + json.data.when_label + (d.recurring ? ' (se repite cada día)' : '') + '. Te avisaré aquí cuando esté listo.', [{ label: 'Mis reportes', icon: 'fa-clock', click: function () { myTasks(); } }], 'schedule:ok');
                } else {
                    reply((json && json.data && json.data.msg) || 'No se pudo programar el reporte.', [], 'schedule:error');
                }
                busy = false;
            });
        } else if (p.flow === 'pos_cart') {
            var subtotal = d.cart.reduce(function (a, it) { return a + it.qty * it.price; }, 0);
            api('ws_pos_sale_save', {
                location_id: d.loc,
                seller_id: d.meta.user_id,
                currency: d.meta.currency || '',
                subtotal: String(subtotal),
                discount: '0',
                total: String(subtotal),
                payment_method: 'cash',
                cash_amount: String(subtotal),
                transfer_amount: '0',
                transfer_number: '',
                items: JSON.stringify(d.cart.map(function (it) { return { product_id: it.product_id, product_name: it.product_name, qty: it.qty, price: it.price, discount: 0, subtotal: it.qty * it.price }; }))
            }, function (json) {
                if (json && json.success) {
                    setMem('pos_cart', d.cart.length + ' items');
                    pendingAction = null;
                    reply('Venta registrada ✅ Nº ' + (json.data && json.data.sale_id) + ' por ' + formatNum(subtotal) + ' ' + (d.meta.currency || '') + '. El stock se descontó al momento.', [{ label: 'Ver ventas', url: C.shortcuts.panel.posSales.url, icon: 'fa-receipt' }, { label: 'Nueva venta', icon: 'fa-cash-register', send: 'registrar venta' }], 'action:pos_cart:ok');
                } else {
                    pendingAction = null;
                    reply((json && json.data && json.data.msg) || 'No se pudo registrar la venta.', [{ label: 'Abrir POS', url: C.shortcuts.panel.pos.url, icon: 'fa-cash-register' }], 'action:pos_cart:error');
                }
                busy = false;
            });
        }
    }

    // Arranques de los flujos
    function flowRestockStart() {
        api('ws_chatbot_meta', {}, function (json) {
            if (!json || !json.success || !(json.data && json.data.locations.length)) {
                reply((json && json.data && json.data.msg) || 'No tienes tiendas asignadas.', [], 'action:restock:noloc');
                busy = false;
                return;
            }
            pendingAction = { flow: 'restock', step: 'pick', data: { meta: json.data } };
            ask('¿Qué producto quieres reponer? Escríbelo y te muestro coincidencias:', [], 'action:restock:pick');
        });
    }

    function flowOrderStart(kind) {
        api('ws_order_list', { status: 'pending' }, function (json) {
            var list = (json && json.success && json.data && json.data.orders) || [];
            if (!list.length) {
                reply('No tienes pedidos pendientes ahora mismo 👌', [], 'action:order:none');
                busy = false;
                return;
            }
            pendingAction = { flow: kind === 'accept' ? 'order_accept' : 'order_reject', step: 'pick', data: { list: list } };
            ask('Tienes ' + list.length + ' pedido(s) pendiente(s). ¿Cuál ' + (kind === 'accept' ? 'aceptas' : 'rechazas') + '?', list.slice(0, 6).map(function (o) {
                return { label: 'Nº ' + o.number + ' · ' + (o.customer_name || 'Cliente') + ' · ' + formatNum(o.total), cls: 'wsb-chip-pick', icon: 'fa-cart-shopping', click: function () { pickOrder(kind, o); } };
            }), 'action:order:list');
        });
    }

    function pickOrder(kind, o) {
        pendingAction = { flow: kind === 'accept' ? 'order_accept' : 'order_reject', step: 'confirm', data: { order: o } };
        confirmAsk(kind === 'accept' ? '¿Confirmas ACEPTAR el pedido Nº ' + o.number + '? (se descuenta el stock)' : '¿Confirmas RECHAZAR el pedido Nº ' + o.number + '?', 'action:order:confirm');
    }

    function flowCustomerStart() {
        pendingAction = { flow: 'customer_new', step: 'name', data: {} };
        ask('Perfecto, creo el cliente por ti. ¿Cómo se llama?', [], 'action:customer:name');
    }

    function flowPosStart() {
        api('ws_chatbot_meta', {}, function (json) {
            if (!json || !json.success || !json.data) { reply('No pude iniciar la venta.', [], 'action:pos:meta'); busy = false; return; }
            var meta = json.data;
            if (!meta.locations.length) { reply('No tienes tiendas asignadas para vender.', [], 'action:pos:noloc'); busy = false; return; }
            var open = meta.locations.filter(function (l) { return l.open_cash; });
            if (!open.length) {
                reply('Para registrar una venta necesitas la caja abierta en alguna tienda. Te llevo al POS para abrirla:', [{ label: 'Abrir caja (POS)', url: C.shortcuts.panel.pos.url, icon: 'fa-cash-register' }], 'action:pos:nocash');
                busy = false;
                return;
            }
            pendingAction = { flow: 'pos_cart', step: 'pick', data: { meta: meta, loc: open[0].id, locName: open[0].name, cart: [] } };
            ask('Venta en "' + open[0].name + '" (caja abierta ✅). ¿Qué producto vendes? Escríbelo:', [], 'action:pos:pick');
        });
    }

    function startFlow(name) {
        if (name === 'product_new') { pendingAction = { flow: 'product_new', step: 'name', data: {} }; ask('Perfecto, creo el producto por ti. ¿Cómo se llama? (si son varios, sepáralos con comas)', [], 'action:product_new:name'); }
        else if (name === 'product_edit') { pendingAction = { flow: 'product_edit', step: 'pick', data: {} }; ask('¿Qué producto quieres editar? Escríbelo (o varios separados por comas para el mismo cambio):', [], 'action:edit:pick'); }
        else if (name === 'product_delete') { pendingAction = { flow: 'product_delete', step: 'pick', data: {} }; ask('¿Qué producto quieres eliminar? Escríbelo (o varios separados por comas):', [], 'action:delete:pick'); }
        else if (name === 'report') { reportFlow('ask'); }
        else if (name === 'schedule') { scheduleFlow('ask'); }
        else if (name === 'mytasks') { myTasks(); }
        else if (name === 'restock') { flowRestockStart(); }
        else if (name === 'order_accept') { flowOrderStart('accept'); }
        else if (name === 'order_reject') { flowOrderStart('reject'); }
        else if (name === 'customer_new') { flowCustomerStart(); }
        else if (name === 'pos_cart') { flowPosStart(); }
        else if (name === 'top') { doTop(); }
        else if (name === 'guide') { startGuideFlow(); }
    }

    /* ------------------------------------------------------------------ */
    /* Fase 3-5: multi-intención, IA opcional, proactividad y analítica     */
    /* ------------------------------------------------------------------ */

    var ACTION_PHRASES = [
        ['product_new', ['crea un producto', 'crear un producto', 'quiero crear un producto', 'nuevo producto', 'agregar un producto', 'agregar producto', 'crear producto', 'dar de alta un producto', 'añadir producto', 'añadir un producto']],
        ['product_edit', ['editar producto', 'edita el producto', 'editar un producto', 'cambiar precio', 'cambiar el precio', 'actualizar producto', 'modificar producto', 'cambiar nombre del producto']],
        ['product_delete', ['borrar producto', 'eliminar producto', 'quitar producto', 'borra el producto', 'elimina el producto', 'borrar un producto', 'eliminar un producto', 'dar de baja un producto']],
        ['restock', ['reponer stock', 'reponer inventario', 'entrada de stock', 'entrada de mercancia', 'agregar stock', 'meter stock', 'agregar existencias', 'reponer existencias']],
        ['order_accept', ['aceptar pedido', 'acepta el pedido', 'aceptar un pedido', 'confirmar pedido', 'acepta pedido', 'aceptar orden']],
        ['order_reject', ['rechazar pedido', 'rechaza el pedido', 'rechazar un pedido', 'rechazar orden']],
        ['customer_new', ['crear cliente', 'nuevo cliente', 'agregar cliente', 'crear un cliente', 'dar de alta un cliente', 'registrar un cliente']],
        ['pos_cart', ['registrar venta', 'registrar una venta', 'vender ahora', 'hacer una venta', 'vender en el pos', 'cobrar ahora', 'vender']],
        ['top', ['recomiendame', 'recomiéndame', 'que vende mas', 'qué vende más', 'mas vendido', 'más vendido', 'productos mas vendidos', 'top ventas', 'lo que mas se vende', 'que productos se venden mas']],
        ['report', ['reporte de ventas', 'reporte del dia', 'reporte de stock', 'stock bajo', 'reporte de pedidos', 'reporte del equipo', 'actividad del equipo', 'que vendi hoy', 'cuanto vendi', 'cuánto vendí', 'ventas de hoy', 'ventas del mes', 'resumen del negocio', 'resumen de ventas', 'reporte de seguridad', 'reporte de logs', 'errores del dia', 'errores de la app', 'reporte completo', 'quiero un reporte', 'dame un reporte']],
        ['schedule', ['programa un reporte', 'programar un reporte', 'programa reporte', 'programar reporte', 'agenda un reporte', 'agendar reporte', 'programar tarea', 'programa una tarea', 'tarea programada', 'reporte automatico', 'reporte diario', 'reporte todos los dias', 'reporte en 2 horas', 'reporte manana', 'reporte mañana']],
        ['mytasks', ['mis reportes', 'mis tareas', 'reportes programados', 'tareas programadas', 'que reportes tengo', 'ver mis reportes', 'ver mis tareas', 'reportes que programe']],
        ['guide', ['guia', 'guía', 'explicame', 'explícame', 'paso a paso', 'como se usa', 'como se hace', 'como uso', 'cómo uso', 'como hago para', 'como hago', 'quiero aprender', 'manual', 'enseñame a usar', 'que hace cada modulo', 'para que sirve cada modulo', 'como trabajo en el panel', 'guia del panel', 'guía del panel']]
    ];

    // Frases imperativas de acción: ganan a la base de conocimiento (el bot
    // ejecuta en lugar de solo explicar). Solo aplica en el panel con plan.
    function matchAction(text) {
        if (!isPanel || locked) { return null; }
        var low = String(text || '').toLowerCase();
        var best = null, bestLen = 0;
        ACTION_PHRASES.forEach(function (pair) {
            var key = pair[0];
            (pair[1] || []).forEach(function (k) {
                if (low.indexOf(k) > -1 && k.length > bestLen) { best = key; bestLen = k.length; }
            });
        });
        return best;
    }

    function waLink(w) {
        if (/^https?:\/\//i.test(w)) { return w; }
        return 'https://wa.me/' + String(w).replace(/[^\d]/g, '');
    }
    function whatsappChip() {
        var w = String(C.whatsapp || '').trim();
        return w ? { label: 'Hablar por WhatsApp', url: waLink(w), icon: 'fa-brands fa-whatsapp', newTab: true, track: 'escalate:whatsapp' } : null;
    }

    // Fallback con derivación en caliente (WhatsApp/contacto) en vez de solo enlaces.
    var STOP_WORDS = ['como','que','cual','cuales','cuando','donde','cuanto','cuantos','quiero','puedo','puedes','hacer','hago','haces','para','con','por','los','las','el','la','una','unos','unas','del','al','de','y','o','a','en','mi','mis','me','te','se','su','es','son','hay','tiene','tienen','tengo','saber','dime','cuentame','explicame','ayuda','necesito','quiere','debo','tienes','esta','este','esto','asi','bien','todo','toda','todos','todas','sobre','acerca','pregunta','preguntas','respuesta','respuestas','hola','buenas','gracias','porfa'];

    function textWords(s) {
        return normText(s).split(' ').filter(function (w) {
            return w.length >= 4 && STOP_WORDS.indexOf(w) === -1;
        });
    }

    // Búsqueda por palabras clave sobre TODA la base de conocimiento (patrones
    // + respuestas): responde aunque la frase no sea exacta a ninguna entrada.
    function keywordMatch(text) {
        var words = textWords(text);
        if (!words.length) { return null; }
        var best = null, bestScore = 0;
        (C.knowledge || []).forEach(function (item) {
            var corpus = normText((item.patterns || []).join(' ') + ' ' + (item.answer || ''));
            var score = 0, hits = 0;
            words.forEach(function (w) {
                if (corpus.indexOf(w) > -1) {
                    score += (w.length >= 7 ? 2 : 1);
                    hits++;
                }
            });
            if (hits && score > bestScore) { best = item; bestScore = score; }
        });
        if (!best || bestScore < 2) { return null; }
        return best;
    }

    function topicChips() {
        if (isPanel) {
            return [
                { label: 'Productos', icon: 'fa-boxes-stacked', send: 'crear producto' },
                { label: 'Stock', icon: 'fa-warehouse', send: 'reponer stock' },
                { label: 'Pedidos', icon: 'fa-cart-shopping', send: 'mis pedidos' },
                { label: 'Ventas', icon: 'fa-cash-register', send: 'ventas de hoy' },
                { label: 'Reportes', icon: 'fa-chart-line', send: 'reporte de ventas' },
                { label: 'Equipo', icon: 'fa-users', send: 'reporte del equipo' },
                { label: 'Plan', icon: 'fa-crown', send: 'mi plan' }
            ];
        }
        return [
            { label: 'Cómo comprar', icon: 'fa-cart-shopping', send: 'como comprar' },
            { label: 'Seguimiento', icon: 'fa-truck', send: 'seguimiento de pedido' },
            { label: 'Devoluciones', icon: 'fa-rotate-left', send: 'devoluciones' },
            { label: 'Envíos', icon: 'fa-truck-fast', send: 'envio a domicilio' },
            { label: 'Planes', icon: 'fa-crown', send: 'planes y precios' },
            { label: 'Crear mi tienda', icon: 'fa-store', send: 'crear mi tienda' },
            { label: 'Contacto', icon: 'fa-headset', send: 'contacto' }
        ];
    }

    // Fallback inteligente: si no hubo coincidencia exacta, intenta responder
    // por palabras clave; si tampoco, sugiere temas y deriva a un humano.
    function smartFallback(text) {
        var item = keywordMatch(text);
        if (item) {
            var kchips = [];
            if (item.chip) { kchips.push(item.chip); }
            if (item.chips && item.chips.length) { kchips = kchips.concat(item.chips); }
            reply('Creo que te refieres a esto 👇\n\n' + (item.answer || ''), kchips.length ? kchips : null, 'fallback:keywords');
            track('fallback:keywords:' + (item.id || 'x'));
            return;
        }
        var chips = topicChips();
        var wa = whatsappChip();
        if (wa) { chips.push(wa); }
        chips.push(contactChip());
        reply(S.fallback + '\n\n' + (isPanel
            ? 'Puedo ayudarte con: productos, stock, pedidos, ventas, clientes, reportes, tu equipo, tu plan o programar reportes.'
            : 'Puedo orientarte para comprar, seguir tu pedido, devoluciones, envíos o montar tu tienda.'), chips.filter(Boolean), 'fallback');
        // Auto-aprendizaje: la frase que el bot no supo responder se guarda
        // para que el admin la revise en wp-admin > Asistente > Aprender.
        learnCandidate(text);
    }

    // Cola de aprendizaje: envía la pregunta no resuelta al servidor (el admin
    // la verá en wp-admin > Asistente > Aprender y puede enseñarle al bot).
    var lastLearnSent = '';
    function learnCandidate(text) {
        var t = String(text || '').trim();
        if (!t || t.length > 300 || t === lastLearnSent) { return; }
        lastLearnSent = t;
        api('ws_chatbot_learn', { text: t }, function () {});
    }

    // IA opcional: cuando el admin configuró OpenRouter, la frase no resuelta
    // se deriva al proxy PHP (la clave nunca viaja al navegador).
    function doLLM(text) {
        var t = showTyping();
        api('ws_chatbot_llm', { text: text, history: JSON.stringify(chatHistory.slice(-6)) }, function (json) {
            removeTyping(t);
            if (json && json.success && json.data && json.data.text) {
                appendMsg(json.data.text, false);
                chatHistory.push({ role: 'assistant', content: json.data.text });
                track('llm:used');
            } else {
                fallbackReply();
            }
            busy = false;
        });
    }

    // Recomendaciones reales (Fase 5): lo más vendido del negocio.
    function doTop() {
        var t = showTyping();
        api('ws_chatbot_top', {}, function (json) {
            removeTyping(t);
            if (!json || !json.success || !(json.data && json.data.products && json.data.products.length)) {
                reply('Aún no hay suficientes ventas para recomendarte. ¡Vende un poco y vuelve! 😉', [], 'top:none');
                busy = false;
                return;
            }
            var rows = json.data.products.map(function (p) {
                return { name: p.name, meta: '<b>' + formatNum(p.qty) + '</b> uds · ' + formatNum(p.total) + ' ' + escapeHtml(json.data.currency || ''), url: C.shortcuts.panel.reports.url };
            });
            appendCard('Tus productos más vendidos:', rows, { chips: [{ label: 'Ver reportes', url: C.shortcuts.panel.reports.url, icon: 'fa-chart-line' }] });
            track('top:shown');
            busy = false;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Guías paso a paso por rol (respuestas por defecto específicas)       */
    /* ------------------------------------------------------------------ */

    function guideOf(id) {
        var g = null;
        (C.guides || []).forEach(function (x) { if (x.id === id) { g = x; } });
        return g;
    }

    // Intro específica del rol para el módulo (p. ej. la de vendedor difiere
    // de la de almacenero al explicar Stock o Productos).
    function guideIntro(id) {
        var g = guideOf(id);
        return (g && g.intro) || '';
    }

    function guideChip(id) {
        return guideOf(id) ? { label: 'Guía paso a paso', icon: 'fa-list-ol', click: function () { showGuide(id); } } : null;
    }

    function startGuideFlow() {
        var guides = C.guides || [];
        if (!guides.length) {
            reply('No tengo guías disponibles para tu rol en este momento.', [], 'guide:none');
            busy = false;
            return;
        }
        reply('Claro, te explico paso a paso cómo se trabaja en cada módulo. Elige uno:', guides.map(function (g) {
            return { label: g.label, cls: 'wsb-chip-pick', icon: g.icon, click: function () { showGuide(g.id); } };
        }), 'guide:list');
        busy = false;
    }

    function showGuide(id) {
        var g = guideOf(id);
        if (!g) {
            reply('No encontré esa guía.', [], 'guide:missing');
            return;
        }
        track('guide:' + id);
        // Los pasos se muestran como mensajes normales del bot (burbujas),
        // no como un card clicable que se desborda dentro del chat y no se lee.
        if (g.intro) { appendMsg(g.intro, false); }
        var steps = g.steps || [];
        if (!steps.length) { steps = ['Entra al módulo y explora sus opciones.']; }
        steps.forEach(function (s, i) {
            appendMsg('Paso ' + (i + 1) + ': ' + s, false);
        });
        var chips = [];
        if (g.url) { chips.push({ label: 'Abrir módulo', icon: 'fa-arrow-pointer', url: g.url, newTab: true }); }
        chips.push({ label: 'Otra guía', icon: 'fa-list-ol', click: startGuideFlow });
        appendChips(chips);
        busy = false;
    }

    // Multi-intención: "crea un producto y dime el stock" se ejecuta en secuencia.
    function splitIntents(text) {
        var parts = String(text || '').split(/\s+(?:y|además|también)\s+/i);
        if (parts.length < 2) { return null; }
        var ok = [];
        parts.forEach(function (p) {
            p = p.trim();
            if (!p) { return; }
            if (matchAction(p) || resolveIntent(p)) { ok.push(p); }
        });
        return ok.length >= 2 ? ok : null;
    }

    function respondTo(text) {
        var act = matchAction(text);
        if (act) { startFlowGuard(act); return; }
        var intent = resolveIntent(text);
        if (intent && intent.knowledge) { runKnowledge(intent.knowledge); }
        else if (intent) { intent.run(); }
        else { fallbackReply(); }
    }

    function runQueue(list, i) {
        i = i || 0;
        if (i >= list.length) { busy = false; return; }
        var text = list[i];
        var delay = 450 + Math.min(600, text.length * 12);
        window.setTimeout(function () {
            busy = false;
            respondTo(text);
            if (pendingAsk || pendingAction) { return; }
            runQueue(list, i + 1);
        }, delay);
    }

    /* ------------------------------------------------------------------ */
    /* Proactividad (Fase 4): notificaciones en el panel y carrito público  */
    /* ------------------------------------------------------------------ */

    function setBadge(n) {
        var b = btn.querySelector('.wsb-badge');
        if (b) { b.textContent = n > 99 ? '99+' : (n ? String(n) : ''); }
        btn.classList.toggle('has-badge', !!n);
    }

    // Evita repetir el aviso del mismo error incluso tras recargar la página.
    var lastLogAlert = getF('wsb_log_alert_seen') || '';
    function rememberLogAlert(key) { lastLogAlert = key; setF('wsb_log_alert_seen', key); }

    // En el panel y en wp-admin: muestra el badge, avisa de pedidos nuevos y
    // — lo más importante — el bot abre SOLO cuando llega un error de la app
    // (logs) o un evento de seguridad, sin que el admin tenga que preguntar.
    function checkNotifications() {
        if (!isPanel || locked) { return; }
        api('ws_notifications_list', {}, function (json) {
            if (!json || !json.success) { return; }
            var unread = (json.data && json.data.unread) || 0;
            setBadge(unread);
            var items = (json.data && json.data.items) || [];
            var hasNew = items.some(function (n) { return !n.is_read && (n.type === 'order_new' || n.type === 'order_pending'); });
            if (hasNew && !open && !getF('wsb_pro_alert')) {
                setF('wsb_pro_alert', '1');
                var ordersUrl = C.shortcuts && C.shortcuts.panel && C.shortcuts.panel.orders && C.shortcuts.panel.orders.url;
                window.setTimeout(function () {
                    setOpen(true);
                    if (!body.children.length) { boot(); }
                    appendMsg('🔔 Tienes pedidos nuevos esperando tu revisión.', false);
                    if (ordersUrl) { appendChips([{ label: 'Ver pedidos', url: ordersUrl, icon: 'fa-cart-shopping' }]); }
                }, 600);
            }
            // Errores de la app (ref_key ws_log_*) y alertas de seguridad
            // (sec_alert_*): el bot avisa automáticamente al admin de WP.
            var logErr = items.filter(function (n) {
                return !n.is_read && n.ref_key && (n.ref_key.indexOf('ws_log_') === 0 || n.ref_key.indexOf('sec_alert_') === 0);
            })[0];
            if (logErr && !open && logErr.ref_key !== lastLogAlert) {
                rememberLogAlert(logErr.ref_key);
                window.setTimeout(function () {
                    setOpen(true);
                    if (!body.children.length) { boot(); }
                    appendMsg((logErr.title ? logErr.title + ': ' : '⚠️ ') + logErr.message, false);
                    var chips = [];
                    if (C.logsUrl) { chips.push({ label: 'Ver Logs', url: C.logsUrl, icon: 'fa-file-lines' }); }
                    chips.push({ label: 'Reporte de logs', icon: 'fa-chart-line', send: 'reporte de logs' });
                    appendChips(chips);
                }, 800);
            }
        });
    }

    // Visitantes con carrito: el bot se adelanta al terminar el pedido.
    function cartItemsCount() {
        try {
            for (var i = 0; i < ls.length; i++) {
                var k = ls.key(i);
                if (k && k.indexOf('ws_cart_') === 0) {
                    var raw = ls.getItem(k);
                    if (!raw) { continue; }
                    var c = JSON.parse(raw);
                    if (c && Array.isArray(c.cartItems) && c.cartItems.length) { return c.cartItems.length; }
                }
            }
        } catch (e) {}
        return 0;
    }

    var actions = {
        greeting: {
            keys: ['hola', 'buenas', 'hey', 'hi', 'saludo', 'que tal', 'holi', 'hello', 'buen dia', 'buenas tardes'],
            run: function () {
                if (locked) { actions.locked.run(); return; }
                if (isPanel) {
                    reply(S.welcomePanel, chipsFor(['productNew', 'orders', 'stock', 'plan']), 'greeting');
                } else if (!C.logged) {
                    reply(S.welcomeGuest + ' ' + S.registerHook, [registerChip(), chipsFor(['marketplace', 'ayuda'])[0]].filter(Boolean), 'greeting');
                } else {
                    reply(C.context === 'store' ? S.storeTeaser : S.welcomeNewUser, chipsFor(C.context === 'store' ? ['tienda', 'marketplace'] : ['marketplace', 'ayuda']), 'greeting');
                }
            }
        },
        atajos: {
            keys: ['atajos', 'menu', 'opciones', 'inicio', 'dashboard', 'que puedo hacer', 'explorar', 'navegar'],
            run: function () {
                if (locked) { actions.locked.run(); return; }
                if (isPanel) {
                    var ids = Object.keys(C.shortcuts.panel || {});
                    reply(S.atajosTitle, chipsFor(ids.slice(0, 6)), 'atajos');
                } else {
                    reply(S.noAtajos, chipsFor(['marketplace', 'ayuda', 'contacto']), 'atajos');
                }
            }
        },
        createProduct: {
            keys: ['crear producto', 'nuevo producto', 'agregar producto', 'alta producto', 'crear un producto', 'añadir producto', 'anadir producto'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                reply(guideIntro('products') || S.productHint, [guideChip('products'), chipsFor(['productNew', 'products'])[0]].filter(Boolean), 'createProduct');
            }
        },
        guia: {
            keys: ['guia', 'explicame', 'paso a paso', 'manual', 'como se usa el panel', 'que hace cada modulo', 'para que sirve cada modulo', 'como trabajo en el panel'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                startGuideFlow();
            }
        },
        listProducts: {
            keys: ['productos', 'catalogo', 'listado de productos', 'ver productos', 'categoria', 'categorias'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                reply('Te llevo a tu catálogo de productos.', chipsFor(['products', 'productNew']), 'listProducts');
            }
        },
        orders: {
            keys: ['pedidos', 'orden', 'ordenes', 'mis pedidos', 'revisar pedidos', 'aceptar pedido', 'pedido nuevo'],
            run: function () {
                if (!isPanel || locked) { actions.trackOrder.run(); return; }
                reply(guideIntro('orders') || S.ordersHint, [guideChip('orders'), chipsFor(['orders'])[0]].filter(Boolean), 'orders');
            }
        },
        trackOrder: {
            keys: ['donde esta mi pedido', 'rastrear', 'seguimiento', 'estado del pedido', 'mi compra', 'reclamo pedido', 'seguir mi pedido'],
            run: function () {
                if (isPanel) { reply('Revisa tus pedidos en el panel.', chipsFor(['orders']), 'trackOrder'); return; }
                reply('Claro, consulto el estado al instante. Escríbeme el número de tu pedido (ej: 12):', [], 'trackOrder:askNumber');
                pendingAsk = { type: 'order_number' };
            }
        },
        searchProduct: {
            keys: ['buscar', 'busco', 'busca ', 'tienes ', 'tienen ', 'hay ', 'precio de', 'disponible', 'encuentro', 'donde encuentro'],
            run: function () {
                if (locked) { actions.locked.run(); return; }
                var q = extractQuery(lastUserText);
                if (!q) {
                    reply('¿Qué producto buscas? Escríbelo y lo busco al instante.', [], 'search:ask');
                    pendingAsk = { type: 'search' };
                    return;
                }
                doSearch(q);
            }
        },
        summary: {
            keys: ['resumen', 'como va mi negocio', 'como va mi tienda', 'como va el dia', 'estado de mi negocio', 'ventas de hoy', 'pedidos pendientes', 'stock bajo', 'caja abierta', 'reporte del dia', 'numeros del dia'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                doSummary();
            }
        },
        stock: {
            keys: ['stock', 'inventario', 'existencias', 'reponer', 'entrada de stock', 'salida de stock', 'bajo stock'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                reply(guideIntro('stock') || S.stockHint, [guideChip('stock'), chipsFor(['stock'])[0]].filter(Boolean), 'stock');
            }
        },
        customers: {
            keys: ['clientes', 'crm', 'contacto de cliente', 'base de clientes', 'agregar cliente'],
            run: function () {
                if (!isPanel || locked) { actions.contact.run(); return; }
                reply(guideIntro('customers') || 'Tu CRM de clientes está aquí.', [guideChip('customers'), chipsFor(['customers'])[0]].filter(Boolean), 'customers');
            }
        },
        pos: {
            keys: ['vender', 'pos', 'punto de venta', 'caja', 'registrar venta'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                reply(guideIntro('pos') || 'Abre el punto de venta para cobrar en el momento.', [guideChip('pos'), chipsFor(['pos', 'posSales'])[0]].filter(Boolean), 'pos');
            }
        },
        reports: {
            keys: ['reportes', 'reporte', 'estadisticas', 'ventas mes', 'ganancia', 'facturacion', 'graficos'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                reply(guideIntro('reports') || 'Tus reportes y estadísticas te esperan.', [guideChip('reports'), chipsFor(['reports'])[0]].filter(Boolean), 'reports');
            }
        },
        report: {
            keys: ['reporte de ventas', 'ventas de hoy', 'stock bajo', 'cuanto vendi', 'cuánto vendí', 'resumen del negocio', 'reporte del equipo'],
            run: function () { if (!isPanel || locked) { actions.webstore.run(); return; } reportFlow('ask'); }
        },
        schedule: {
            keys: ['programa un reporte', 'programar reporte', 'agendar reporte', 'programar tarea', 'tarea programada', 'reporte diario'],
            run: function () { if (!isPanel || locked) { actions.webstore.run(); return; } scheduleFlow('ask'); }
        },
        mytasks: {
            keys: ['mis reportes', 'mis tareas', 'tareas programadas', 'reportes programados'],
            run: function () { if (!isPanel || locked) { actions.webstore.run(); return; } myTasks(); }
        },
        suppliers: {
            keys: ['proveedores', 'proveedor', 'compania', 'compras a proveedor'],
            run: function () {
                if (!isPanel || locked) { actions.contact.run(); return; }
                reply('Gestiona tus proveedores desde aquí.', chipsFor(['suppliers']), 'suppliers');
            }
        },
        workers: {
            keys: ['trabajadores', 'empleados', 'personal', 'permisos', 'roles', 'invitar usuario'],
            run: function () {
                if (!isPanel || locked) { reply(S.noAtajos, chipsFor(['marketplace', 'ayuda']), 'workers'); return; }
                reply(guideIntro('workers') || 'Administra tu equipo y sus permisos.', [guideChip('workers'), chipsFor(['workers'])[0]].filter(Boolean), 'workers');
            }
        },
        plan: {
            keys: ['plan', 'precio', 'upgrade', 'suscripcion', 'pagar', 'cuanto cuesta', 'precios', 'costo', 'planes'],
            run: function () {
                if (locked) { actions.locked.run(); return; }
                if (isPanel) {
                    // En el panel: card con los planes y el recomendado para el negocio.
                    doPlans();
                    return;
                }
                doPlans();
            }
        },
        tiendas: {
            keys: ['tiendas', 'ver tiendas', 'las tiendas', 'directorio', 'marketplace', 'negocios', 'ver negocios', 'que tiendas hay', 'todas las tiendas', 'donde compro'],
            run: function () {
                if (locked) { actions.locked.run(); return; }
                doStores();
            }
        },
        secciones: {
            keys: ['secciones', 'navegar', 'donde estoy', 'que pagina es esta', 'en que pagina estoy', 'que hay aqui', 'sobre esta pagina', 'explicame esta pagina', 'que seccion', 'ir a otra seccion', 'mapa del sitio', 'todas las paginas', 'paginas del sitio'],
            run: function () {
                if (locked) { actions.locked.run(); return; }
                doPageContext();
            }
        },
        tutorial: {
            keys: ['recorrido', 'tour', 'tutorial', 'guiame', 'guiar', 'como se usa el panel', 'como funciona el panel', 'primeros pasos', 'como empiezo', 'enseñame', 'ensename'],
            run: function () {
                // El recorrido guiado por spotlight del panel se retiró del chat:
                // se veía como un card clicable desbordado dentro de la burbuja
                // y el texto no se leía. Ahora el asistente explica por pasos en
                // burbujas normales (startGuideFlow) y con las guías por rol.
                if (isPanel) {
                    startGuideFlow();
                } else {
                    reply('Puedes explorar las tiendas del mercado, revisar la Ayuda o contactarnos. Y si quieres vender, montar tu negocio toma menos de 5 minutos 😉', chipsFor(['marketplace', 'ayuda', 'contacto']), 'tutorial');
                }
            }
        },
        register: {
            keys: ['registrar', 'registro', 'crear negocio', 'crear cuenta', 'mi negocio', 'montar', 'abrir tienda', 'vender', 'emprender', 'negocio', 'cuenta gratis', 'empezar'],
            run: function () {
                if (C.logged && hasBizRole) { actions.atajos.run(); return; }
                reply('Es gratis empezar: crea tu cuenta y en menos de 5 minutos tienes tu tienda lista para vender. ' + S.registerHook, [registerChip()].filter(Boolean), 'register');
            }
        },
        webstore: {
            keys: ['comprar', 'compras', 'como comprar', 'tienda', 'ofertas', 'promociones', 'producto en venta', 'precio producto', 'catalogo de tienda'],
            run: function () {
                reply('Puedes entrar a cualquier tienda del mercado, explorar su catálogo y pedir desde ahí. ¿Qué te gustaría ver?', chipsFor(['marketplace', 'tienda']).filter(Boolean), 'webstore');
            }
        },
        contact: {
            keys: ['contacto', 'soporte', 'atencion', 'whatsapp', 'correo', 'telefono', 'queja', 'problema', 'falla', 'ayudame'],
            run: function () {
                reply('Puedes escribirnos desde la página de contacto o revisar la sección de ayuda.', chipsFor(['contacto', 'ayuda']), 'contact');
            }
        },
        locked: {
            keys: [],
            run: function () {
                track('locked');
                appendMsg(S.welcomeLocked, false);
                appendChips([{ label: S.goPlan, url: C.urls.plan, icon: 'fa-crown', track: 'upsell:plan' }]);
            }
        }
    };

    function registerChip() {
        return { label: 'Crear mi negocio', url: C.urls.register, icon: 'fa-rocket', track: 'shortcut:register', newTab: !C.logged };
    }

    /* Inicia el recorrido guiado del panel (wsTutorial) desde el chat. */
    function startTour() {
        track('tutorial:start');
        setOpen(false);
        window.dispatchEvent(new CustomEvent('ws-open-tutorial'));
    }

    /* Normaliza texto (minúsculas, sin tildes ni signos) igual que el PHP:
       así las FAQs y patrones matchean escribas con o sin tildes. */
    function normText(s) {
        return String(s || '').toLowerCase()
            .replace(/[¿?¡!.,;:()"'“”‘’\-–—\/]+/g, ' ')
            .replace(/[áàäâ]/g, 'a').replace(/[éèëê]/g, 'e')
            .replace(/[íìïî]/g, 'i').replace(/[óòöô]/g, 'o')
            .replace(/[úùüû]/g, 'u').replace(/ñ/g, 'n').replace(/ç/g, 'c')
            .replace(/\s+/g, ' ').trim();
    }

    /* Base de conocimiento del admin + FAQs de Ayuda: responde antes que las
       intenciones internas (patrón más largo gana). */
    function matchKnowledge(text) {
        var low = normText(text);
        var best = null, bestLen = 0;
        (C.knowledge || []).forEach(function (item) {
            (item.patterns || []).forEach(function (p) {
                p = normText(p);
                if (p && low.indexOf(p) > -1 && p.length > bestLen) {
                    best = item;
                    bestLen = p.length;
                }
            });
        });
        return best;
    }

    function runKnowledge(item) {
        if (locked) { actions.locked.run(); return; }
        var chips = [];
        // Preguntas del FAQ primero (acción), enlace a Ayuda al final.
        if (item && item.chips && item.chips.length) { chips = chips.concat(item.chips); }
        if (item && item.chip) { chips.push(item.chip); }
        track('knowledge:' + (item ? (item.id || 'x') : 'x'));
        reply(item ? (item.answer || '') : '', chips.length ? chips : null, 'knowledge');
    }

    function resolveIntent(text) {
        var k = matchKnowledge(text);
        if (k) { return { knowledge: k }; }
        var low = text.toLowerCase();
        var best = null;
        Object.keys(actions).forEach(function (key) {
            var a = actions[key];
            if (!a.keys || !a.keys.length) { return; }
            for (var i = 0; i < a.keys.length; i++) {
                if (low.indexOf(a.keys[i]) > -1) {
                    if (!best || a.keys[i].length > best_key.length) { best = actions[key]; best_key = a.keys[i]; }
                    return;
                }
            }
        });
        return best;
    }

    var best_key = '';

    function sendUser(text) {
        var value = (text || '').trim();
        if (!value || busy) { return; }
        input.value = '';
        appendMsg(value, true);
        busy = true;
        lastUserText = value;
        chatHistory.push({ role: 'user', content: value });
        if (chatHistory.length > 20) { chatHistory = chatHistory.slice(-20); }

        // Flujo conversacional pendiente (búsqueda, pedido o acción guiada).
        if (pendingAction) { handlePendingAction(value); return; }
        if (pendingAsk) { handlePending(value); return; }

        // Multi-intención: "crea un producto y dime el stock" → en secuencia.
        var multi = splitIntents(value);
        if (multi && multi.length >= 2) {
            appendMsg('¡Claro! Hago las dos cosas 👇', false);
            runQueue(multi, 0);
            return;
        }

        var act = matchAction(value);
        var intent = resolveIntent(value);
        var delay = 350 + Math.min(600, value.length * 12);
        window.setTimeout(function () {
            if (act) { busy = false; startFlowGuard(act); return; }
            if (intent && intent.knowledge) { busy = false; runKnowledge(intent.knowledge); }
            else if (intent) { busy = false; intent.run(); }
            else if (C.llm && C.llm.enabled) { doLLM(value); } // busy queda activo hasta la respuesta
            else { busy = false; smartFallback(value); }
        }, delay);
    }

    /* ------------------------------------------------------------------ */
    /* Apertura / cierre                                                   */
    /* ------------------------------------------------------------------ */

    function setOpen(next) {
        open = next;
        base.classList.toggle('is-open', open);
        base.classList.toggle('is-closed', !open);
        teaser.classList.remove('is-visible');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { input.focus(); }
        // Bloquea el scroll del fondo mientras el chat está abierto.
        document.body.classList.toggle('wsb-chat-open', open);
    }

    btn.addEventListener('click', function () {
        if (open) { return; }
        setOpen(true);
        if (!body.children.length) { boot(); }
    });

    win.querySelector('.wsb-close').addEventListener('click', function () { setOpen(false); });

    atajosBtn.addEventListener('click', function () {
        track('atajos-btn');
        appendMsg('Atajos', true);
        if (locked) { actions.locked.run(); }
        else if (isPanel) {
            reply(S.atajosTitle, chipsFor(Object.keys(C.shortcuts.panel || {})), 'atajos-btn');
        } else {
            reply(S.noAtajos, chipsFor(['marketplace', 'ayuda', 'contacto']), 'atajos-btn');
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        sendUser(input.value);
    });

    teaser.addEventListener('click', function () {
        setOpen(true);
        if (!body.children.length) { boot(); }
    });

    /* ------------------------------------------------------------------ */
    /* Bienvenida y proactividad                                           */
    /* ------------------------------------------------------------------ */

    function boot() {
        var first = getF('wsb_intro_v1');
        if (locked) {
            appendMsg(S.welcomeLocked, false);
            appendMsg(S.lockedBody, false);
            appendChips([{ label: S.goPlan, url: C.urls.plan, icon: 'fa-crown', track: 'upsell:plan' }]);
            return;
        }
        // Proactividad pública: carrito con productos → adelantarse al cierre.
        if (!isPanel) {
            var cartN = cartItemsCount();
            if (cartN > 0) {
                appendMsg('Veo que tienes ' + cartN + ' producto(s) en tu carrito 🛒. ¿Quieres terminar tu pedido?', false);
                appendChips([{ label: 'Cómo comprar', icon: 'fa-cart-shopping', send: 'como compro' }, { label: 'Ver tiendas', url: C.urls.stores, icon: 'fa-store' }]);
            }
        }
        if (isPanel) {
            reply(S.welcomePanel, [
                { label: 'Resumen del día', icon: 'fa-gauge-high', send: 'resumen' },
                { label: 'Crear producto', icon: 'fa-plus', send: 'crear producto' },
                { label: 'Abrir caja', icon: 'fa-cash-register', send: 'abrir caja' },
                { label: 'Guía del panel', icon: 'fa-list-ol', send: 'guia del panel' },
                { label: 'Ver tiendas', icon: 'fa-store', send: 'ver tiendas' }
            ], 'boot:panel');
            // Memoria de sesión: retoma donde quedó la última vez.
            if (mem && mem.lastAction && mem.lastEntity) {
                var resumeChip = mem.lastAction === 'product_new' ? { label: 'Seguir con "' + mem.lastEntity + '"', icon: 'fa-rotate', send: 'crear producto' }
                    : mem.lastAction === 'restock' ? { label: 'Seguir reponiendo', icon: 'fa-rotate', send: 'reponer stock' }
                    : mem.lastAction === 'pos_cart' ? { label: 'Registrar otra venta', icon: 'fa-cash-register', send: 'registrar venta' }
                    : null;
                appendMsg('La última vez estuvimos con "' + mem.lastEntity + '" ✨ ¿Seguimos con eso o prefieres otra cosa?', false);
                appendChips(resumeChip ? [resumeChip, { label: 'Otra cosa', icon: 'fa-bolt', send: 'atajos' }] : [{ label: 'Otra cosa', icon: 'fa-bolt', send: 'atajos' }]);
            }
        } else if (!C.logged) {
            reply(S.welcomeGuest + ' ' + S.registerHook, [
                { label: 'Buscar producto', icon: 'fa-magnifying-glass', send: 'buscar' },
                { label: 'Cómo comprar', icon: 'fa-cart-shopping', send: 'como compro' },
                { label: 'Seguir mi pedido', icon: 'fa-truck-fast', send: 'seguir mi pedido' },
                registerChip()
            ].filter(Boolean), 'boot:guest');
        } else {
            // Contexto rico de la página: el bot sabe dónde está el usuario
            // (tienda, negocio, mercado, ayuda…) y pregunta algo útil según eso.
            var pg = C.page || {};
            var pgMsg = (pg.desc ? pg.desc + ' ' : '') + (pg.hint || '');
            var pgChips = [];
            if (pg.key === 'store' || C.context === 'store') {
                pgChips = [
                    { label: 'Buscar en esta tienda', icon: 'fa-magnifying-glass', send: 'buscar' },
                    { label: 'Ver todas las tiendas', icon: 'fa-store', send: 'todas las tiendas' },
                    { label: 'Seguir mi pedido', icon: 'fa-truck-fast', send: 'seguir mi pedido' }
                ];
            } else if (pg.key === 'landing' || pg.key === 'marketplace') {
                pgChips = [
                    { label: 'Ver tiendas', icon: 'fa-store', send: 'todas las tiendas' },
                    { label: 'Buscar producto', icon: 'fa-magnifying-glass', send: 'buscar' },
                    { label: 'Ver planes', icon: 'fa-crown', send: 'planes' }
                ];
            } else if (pg.key === 'planes' || pg.key === 'pricing' || pg.key === 'panel:plan') {
                pgChips = [
                    { label: 'Ver planes', icon: 'fa-crown', send: 'planes' },
                    { label: 'Crear mi negocio', icon: 'fa-rocket', send: 'crear negocio' }
                ];
            } else if (pg.key === 'ayuda' || pg.key === 'help' || pg.key === 'static:ayuda') {
                pgChips = [
                    { label: 'Buscar en la Ayuda', icon: 'fa-circle-question', send: 'ayuda' },
                    { label: 'Contactar', icon: 'fa-envelope', send: 'contacto' }
                ];
            } else {
                pgChips = [
                    { label: 'Buscar producto', icon: 'fa-magnifying-glass', send: 'buscar' },
                    { label: 'Ver tiendas', icon: 'fa-store', send: 'todas las tiendas' },
                    { label: 'Ver planes', icon: 'fa-crown', send: 'planes' }
                ];
            }
            var pgLead = pg.key === 'store' ? (S.storeTeaser + ' Puedes también ver todas las tiendas.') : (pgMsg || S.welcomeNewUser);
            reply(pgLead, pgChips, 'boot:' + (pg.key || 'user'));
        }
    }

    function teaserText() {
        if (locked) { return 'Activa el asistente en tu plan'; }
        if (isPanel) { return '¿Qué quieres hacer hoy?'; }
        if (C.context === 'store') { return S.storeTeaser; }
        return '¿Necesitas ayuda?'; 
    }

    function pulse() {
        if (getF('wsb_seen') === '1') { return; } // no molestar a quien ya lo vio
        teaser.querySelector('#wsb-teaser-text').textContent = teaserText();
        teaser.classList.add('is-visible');
        btn.classList.add('wsb-pulse');
        setF('wsb_seen', '1');
    }

    window.setTimeout(pulse, 2200);
    if (getF('wsb_intro_v1') === null) {
        setF('wsb_intro_v1', '1');
    }

    // Memoria de sesión: carga lo último que se hizo (para retomar en el saludo).
    loadMem();

    // Proactividad del panel: revisa notificaciones al cargar y cada minuto
    // (pedidos nuevos abren el bot con el aviso; el badge muestra las no leídas).
    if (isPanel && !locked) {
        checkNotifications();
        window.setInterval(checkNotifications, 60000);
    }
})();