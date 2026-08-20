const fs = require('fs');

/* ========================================
   FIX 1: sync.js — Sync.push() queues
   on network AND server errors, not just
   when navigator.onLine === false
   ======================================== */
const syncFile = 'mobile-app/www/js/sync.js';
let sync = fs.readFileSync(syncFile, 'utf8');

// The push function: when online, if the API call fails,
// currently only queues when err.offline. We also want to queue
// when err.network (fetch failed despite navigator.onLine) or
// when the server returns success:false (err.response exists).
// This way ALL failed writes get queued for retry on reconnect.
sync = sync.replace(
  '          if (err.offline) return ns.DB.enqueue(op);\n          throw err;',
  '          // Queue on any error (network, server, validation) —\n          // retry later when connection is solid.\n          if (err.offline || err.network) return ns.DB.enqueue(op);\n          // Server returned an error response (HTTP or validation).\n          // Still queue so it retries — the user can see the queue.\n          return ns.DB.enqueue(op);'
);
fs.writeFileSync(syncFile, sync);
console.log('✅ sync.js: push() now queues on ALL errors (not just offline)');

/* ========================================
   FIX 2: pos.js — Cart bar becomes floating
   Fixed at bottom of screen, always visible
   above the nav bar
   ======================================== */
const posFile = 'mobile-app/www/js/views/pos.js';
let pos = fs.readFileSync(posFile, 'utf8');

// Change the cart div class from wsm-cart to wsm-pos-float
pos = pos.replace(
  /<div class="wsm-cart" id="wsm-pos-cart"/g,
  '<div class="wsm-pos-float" id="wsm-pos-cart"'
);
fs.writeFileSync(posFile, pos);
console.log('✅ pos.js: cart now uses wsm-pos-float (floating) class');

/* ========================================
   Verify both files are valid JS
   ======================================== */
try {
  // sync.js should not throw
  new Function(sync.replace(/window\.WSApp/g, '({Sync:{},DB:{},Api:{},UI:{},Auth:{},config:{},Images:{}})'));
  console.log('✅ sync.js: syntax OK');
} catch (e) {
  console.error('❌ sync.js syntax error:', e.message);
}

try {
  // pos.js should not throw
  new Function(pos.replace(/window\.WSApp/g, '({Views:{},Sync:{},DB:{},Api:{},UI:{},Auth:{},config:{},Images:{}})'));
  console.log('✅ pos.js: syntax OK');
} catch (e) {
  console.error('❌ pos.js syntax error:', e.message);
}
