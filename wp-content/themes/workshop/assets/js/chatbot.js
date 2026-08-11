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
        '<span class="wsb-close-icon" aria-hidden="true"><i class="fa-solid fa-xmark"></i></span>' +
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
            b.className = 'wsb-chip';
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
            if (chips && chips.length) { appendChips(chips); }
        }, 450 + Math.min(500, text.length * 8));
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
                reply(S.productHint, chipsFor(['productNew', 'products']), 'createProduct');
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
                reply(S.ordersHint, chipsFor(['orders']), 'orders');
            }
        },
        trackOrder: {
            keys: ['donde esta mi pedido', 'rastrear', 'seguimiento', 'estado del pedido', 'mi compra', 'reclamo pedido'],
            run: function () {
                if (isPanel) { reply('Revisa tus pedidos en el panel.', chipsFor(['orders']), 'trackOrder'); return; }
                reply('Puedes consultar el estado de tu pedido directamente en la tienda donde lo hiciste.', chipsFor(['tienda', 'marketplace']).filter(Boolean), 'trackOrder');
            }
        },
        stock: {
            keys: ['stock', 'inventario', 'existencias', 'reponer', 'entrada de stock', 'salida de stock', 'bajo stock'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                reply(S.stockHint, chipsFor(['stock']), 'stock');
            }
        },
        customers: {
            keys: ['clientes', 'crm', 'contacto de cliente', 'base de clientes', 'agregar cliente'],
            run: function () {
                if (!isPanel || locked) { actions.contact.run(); return; }
                reply('Tu CRM de clientes está aquí.', chipsFor(['customers']), 'customers');
            }
        },
        pos: {
            keys: ['vender', 'pos', 'punto de venta', 'caja', 'registrar venta'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                reply('Abre el punto de venta para cobrar en el momento.', chipsFor(['pos', 'posSales']), 'pos');
            }
        },
        reports: {
            keys: ['reportes', 'reporte', 'estadisticas', 'ventas mes', 'ganancia', 'facturacion', 'graficos'],
            run: function () {
                if (!isPanel || locked) { actions.webstore.run(); return; }
                reply('Tus reportes y estadísticas te esperan.', chipsFor(['reports']), 'reports');
            }
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
                reply('Administra tu equipo y sus permisos.', chipsFor(['workers']), 'workers');
            }
        },
        plan: {
            keys: ['plan', 'precio', 'upgrade', 'suscripcion', 'pagar', 'cuanto cuesta', 'precios', 'costo', 'planes'],
            run: function () {
                if (isPanel) { reply('Tu plan actual es ' + C.planName + '. Si quieres más funciones, aquí puedes pedirlo.', chipsFor(['plan']), 'plan'); return; }
                reply('Montar tu negocio en el sitio es gratis en la prueba de 7 días. Crea tu cuenta y elige el plan que mejor te quede.', [registerChip(), { label: 'Ver tarifas', url: C.urls.plan, icon: 'fa-crown' }].filter(Boolean), 'plan');
            }
        },
        tutorial: {
            keys: ['recorrido', 'tour', 'tutorial', 'guiame', 'guiar', 'como se usa el panel', 'como funciona el panel', 'primeros pasos', 'como empiezo', 'enseñame', 'ensename'],
            run: function () {
                // El recorrido guiado es onboarding nativo del panel: se ofrece
                // incluso si el plan no incluye el asistente (locked).
                if (isPanel) {
                    reply('Claro, te hago un recorrido guiado por esta sección del panel y te voy señalando cada elemento. Pulsa el botón para empezar:', [{
                        label: 'Iniciar recorrido guiado',
                        icon: 'fa-location-arrow',
                        click: startTour
                    }], 'tutorial');
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

    /* Base de conocimiento del admin: responde antes que las intenciones
       internas (patrón más largo gana). */
    function matchKnowledge(text) {
        var low = String(text || '').toLowerCase();
        var best = null, bestLen = 0;
        (C.knowledge || []).forEach(function (item) {
            (item.patterns || []).forEach(function (p) {
                p = String(p || '').toLowerCase().trim();
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
        var chips = item && item.chip ? [item.chip] : null;
        track('knowledge:' + (item ? (item.id || 'x') : 'x'));
        reply(item ? (item.answer || '') : '', chips, 'knowledge');
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

        var intent = resolveIntent(value);
        var delay = 350 + Math.min(600, value.length * 12);
        window.setTimeout(function () {
            if (intent && intent.knowledge) { runKnowledge(intent.knowledge); }
            else if (intent) { intent.run(); }
            else {
                reply(S.fallback, chipsFor(isPanel ? ['atajos'] : ['marketplace', 'ayuda', 'contacto']), 'fallback');
            }
            busy = false;
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
        if (isPanel) {
            reply(S.welcomePanel, chipsFor(['productNew', 'orders', 'stock', 'plan']), 'boot:panel');
        } else if (!C.logged) {
            reply(S.welcomeGuest + ' ' + S.registerHook, [registerChip(), chipsFor(['marketplace', 'ayuda'])[0]].filter(Boolean), 'boot:guest');
        } else if (C.context === 'store') {
            reply(S.storeTeaser + ' Puedes también ver todas las tiendas.', chipsFor(['marketplace']), 'boot:store');
        } else {
            reply(S.welcomeNewUser, chipsFor(['marketplace', 'ayuda']), 'boot:user');
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
})();