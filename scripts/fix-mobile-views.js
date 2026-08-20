const fs = require('fs');

/* ===== FIX 1: CSS - Stock search overflow ===== */
let css = fs.readFileSync('mobile-app/www/css/app.css', 'utf8');
css = css.replace(
  '.wsm-search select, .wsm-search .wsm-select { padding: 10px 8px; border: 1px solid var(--ws-border); border-radius: 10px; font-size: 14px; background: #fff; outline: none; }',
  '.wsm-search select, .wsm-search .wsm-select { padding: 10px 8px; border: 1px solid var(--ws-border); border-radius: 10px; font-size: 14px; background: #fff; outline: none; min-width: 0; flex-shrink: 1; max-width: 45%; }\n.wsm-search input { min-width: 0; flex: 1 1 0; }'
);
fs.writeFileSync('mobile-app/www/css/app.css', css);
console.log('1. CSS fixed');

/* ===== FIX 2: Stock view - location loading + search overflow + refresh ===== */
let stock = fs.readFileSync('mobile-app/www/js/views/stock.js', 'utf8');

// Fix render: inline overflow prevention on search container
stock = stock.replace(
  "'<div class=\"wsm-search\">' +\n        '<select id=\"wsm-stock-loc\"></select>' +\n        '<input type=\"search\" id=\"wsm-stock-search\" placeholder=\"Buscar…\">' +\n      '</div>'",
  "'<div class=\"wsm-search\" style=\"overflow:hidden\">' +\n        '<select id=\"wsm-stock-loc\" style=\"min-width:0\"></select>' +\n        '<input type=\"search\" id=\"wsm-stock-search\" placeholder=\"Buscar…\" style=\"min-width:0;flex:1 1 0\">' +\n      '</div>'"
);

// Fix refreshFromServer: fetch ALL locations stock (not filtered)
stock = stock.replace(
  "ns.Api.req('ws_stock_list', { location_id: locFilter, search: '', pageSize: 500, page: 1, include_combos: 1 })",
  "ns.Api.req('ws_stock_list', { location_id: 0, search: '', pageSize: 500, page: 1, include_combos: 1 })"
);

// Fix loadLocationsInstant: add background refresh from server
stock = stock.replace(
  `  function loadLocationsInstant(container) {
    ns.DB.all('locations').then(function (cached) {
      locations = cached && cached.length ? cached : [];
      renderLocSelect(container);
    }).catch(function () {
      // Fallback: intentar la caché de meta
      ns.Sync.cacheGet('ws_locations_list').then(function (c) {
        locations = (c && c.length) ? c : [];
        renderLocSelect(container);
      }).catch(function () {});
    });
  }`,
  `  function loadLocationsInstant(container) {
    ns.DB.all('locations').then(function (cached) {
      locations = cached && cached.length ? cached : [];
      renderLocSelect(container);
    }).catch(function () {
      ns.Sync.cacheGet('ws_locations_list').then(function (c) {
        locations = (c && c.length) ? c : [];
        renderLocSelect(container);
      }).catch(function () {});
    });
    // Background refresh from server for fresh location list
    if (ns.Sync.isPulling()) return;
    ns.Api.req('ws_locations_list', {}).then(function (d) {
      var serverLocs = d.data || [];
      if (serverLocs.length) {
        locations = serverLocs;
        ns.DB.replaceAll('locations', serverLocs).catch(function () {});
        ns.Sync.cache('ws_locations_list', serverLocs);
        renderLocSelect(container);
      }
    }).catch(function () {});
  }`
);

fs.writeFileSync('mobile-app/www/js/views/stock.js', stock);
console.log('2. Stock fixed');

/* ===== FIX 3: POS view - Fix location race condition + image caching ===== */
let pos = fs.readFileSync('mobile-app/www/js/views/pos.js', 'utf8');

// Fix loadCatalogFromServer: add race condition protection + always save to SQLite per-location
pos = pos.replace(
  `  function loadCatalogFromServer(container) {
    if (ns.Sync.isPulling()) return;
    ns.Api.req('ws_products_get', { location_id: locId, search: search, limit: 300 })
      .then(function (d) {`,
  `  function loadCatalogFromServer(container) {
    if (ns.Sync.isPulling()) return;
    var reqLocId = locId; // snapshot for race condition check
    ns.Api.req('ws_products_get', { location_id: locId, search: search, limit: 300 })
      .then(function (d) {
        if (String(reqLocId) !== String(locId)) return; // stale response`
);

// Fix loadCatalogFromServer: always save products to SQLite per-location
pos = pos.replace(
  `        ns.Sync.cache('ws_products_get:' + locId, raw);
        ns.DB.all('stock').then(function (stockRows) {
          var list = stockRows || [];
          if (list.some(function (r) { return String(r.location_id) === String(locId); })) return null;
          var add = raw.map(function (p) {`,
  `        ns.Sync.cache('ws_products_get:' + locId, raw);
        // Always save/update stock rows for this location in SQLite
        ns.DB.all('stock').then(function (stockRows) {
          var list = (stockRows || []).filter(function (r) {
            return String(r.location_id) !== String(locId);
          });
          var add = raw.map(function (p) {`
);

// Fix loadCatalogFromServer: add location_name + image warming
pos = pos.replace(
  `              location_name: '',`,
  `              location_name: (locations.find(function (l) { return String(l.id) === String(locId); }) || {}).name || '',`
);

// Add image warming at end of loadCatalogFromServer
pos = pos.replace(
  `        }).catch(function () {});\n      }).catch(function () {}); // Silencioso\n  }`,
  `        }).catch(function () {});\n        // Cache product images in background\n        raw.forEach(function (p) { if (p.image) ns.Images.warm([p.image]); });\n      }).catch(function () {});\n  }`
);

// Fix drawGrid: add lazy loading + image cache
pos = pos.replace(
  `    grid.innerHTML = rows.slice(0, 60).map(function (p) {
      var stock = Number(p.stock) || 0;
      return '<div class=\"wsm-pos-item\" data-id=\"' + p.product_id + '\" data-combo=\"' + (p.combo_id || 0) + '\">' +
        '<div class=\"wsm-pos-photo\">' + (p.image ? '<img src=\"' + ns.UI.esc(p.image) + '\" alt=\"\">' : '<span class=\"wsm-pos-photo-ico\">' + ns.UI.icon('box') + '</span>') + '</div>'`,
  `    grid.innerHTML = rows.slice(0, 60).map(function (p) {
      var stock = Number(p.stock) || 0;
      var imgId = 'pos-img-' + (p.product_id || p.combo_id || 0);
      return '<div class=\"wsm-pos-item\" data-id=\"' + p.product_id + '\" data-combo=\"' + (p.combo_id || 0) + '\">' +
        '<div class=\"wsm-pos-photo\">' + (p.image ? '<img id=\"' + imgId + '\" src=\"' + ns.UI.esc(p.image) + '\" alt=\"\" loading=\"lazy\">' : '<span class=\"wsm-pos-photo-ico\">' + ns.UI.icon('box') + '</span>') + '</div>'`
);

// Add image preparation after grid render
pos = pos.replace(
  `    }).join('');
    grid.querySelectorAll('.wsm-pos-item').forEach(function (el) {`,
  `    }).join('');
    // Cache images in background
    rows.forEach(function (p) {
      if (p.image) {
        var img = grid.querySelector('#pos-img-' + (p.product_id || p.combo_id));
        if (img) ns.Images.prepareImg(img, p.image);
      }
    });
    grid.querySelectorAll('.wsm-pos-item').forEach(function (el) {`
);

fs.writeFileSync('mobile-app/www/js/views/pos.js', pos);
console.log('3. POS fixed');

/* ===== FIX 4: Orders view - optimize double-rendering ===== */
let orders = fs.readFileSync('mobile-app/www/js/views/orders.js', 'utf8');

// Fix loadInstant: chain DB + cache reads, draw once
orders = orders.replace(
  `  function loadInstant(container) {
    ns.DB.all('orders').then(function (cached) {
      if (cached && cached.length) { orders = cached; draw(container); }
    }).catch(function () {});
    ns.Sync.cacheGet('ws_order_list').then(function (c) {
      if (c && c.length && !orders.length) { orders = c; draw(container); }
    }).catch(function () {});
  }`,
  `  function loadInstant(container) {
    var dbDone = false;
    ns.DB.all('orders').then(function (cached) {
      if (cached && cached.length) { orders = cached; }
      dbDone = true;
      return ns.Sync.cacheGet('ws_order_list');
    }).then(function (c) {
      if (c && c.length && !orders.length) { orders = c; }
      draw(container);
    }).catch(function () { if (!dbDone) draw(container); });
  }`
);

// Fix refreshFromServer: always fetch all orders (no status filter for cache)
orders = orders.replace(
  `  function refreshFromServer(container) {
    if (ns.Sync.isPulling()) return;
    ns.Api.req('ws_order_list', { status: status, pageSize: 100, page: 1 }).then(function (d) {`,
  `  function refreshFromServer(container) {
    if (ns.Sync.isPulling()) return;
    ns.Api.req('ws_order_list', { status: '', pageSize: 100, page: 1 }).then(function (d) {`
);

fs.writeFileSync('mobile-app/www/js/views/orders.js', orders);
console.log('4. Orders fixed');

/* ===== FIX 5: Products view - add image caching ===== */
let products = fs.readFileSync('mobile-app/www/js/views/products.js', 'utf8');

// Check if drawList exists and add image warming
if (products.includes('function drawList(container)') && !products.includes('Images.warm')) {
  products = products.replace(
    `  function drawList(container) {`,
    `  function drawList(container) {
    // Cache product images in background
    (current || []).forEach(function (p) { if (p.image) ns.Images.warm([p.image]); });
`
  );
  fs.writeFileSync('mobile-app/www/js/views/products.js', products);
  console.log('5. Products image caching added');
} else {
  console.log('5. Products already has image caching or drawList not found');
}

console.log('\nAll fixes applied successfully!');
