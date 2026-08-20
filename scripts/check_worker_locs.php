<?php
require_once dirname(__DIR__) . '/wp-load.php';
global $wpdb;

echo "=== WORKERS ===\n";
$users = $wpdb->get_results(
    "SELECT u.ID, u.display_name, u.user_login
     FROM {$wpdb->users} u
     INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
     WHERE um.meta_key = '{$wpdb->prefix}capabilities'
       AND (um.meta_value LIKE '%ws_storekeeper%' OR um.meta_value LIKE '%ws_seller%')"
);
foreach ($users as $u) {
    $role = ws_user_role($u->ID);
    $locs = ws_user_locations($u->ID);
    $loc_ids = array_map(fn($l) => (int) $l->id, $locs);
    echo "  {$u->display_name} ({$u->user_login}) ID={$u->ID} role={$role} locations=" . json_encode($loc_ids) . "\n";
}

echo "\n=== ALL LOCATIONS ===\n";
$locs = $wpdb->get_results('SELECT id, name, pos_enabled FROM ' . ws_table_name('locations'));
foreach ($locs as $l) {
    echo "  ID={$l->id} name={$l->name} pos_enabled={$l->pos_enabled}\n";
}

echo "\n=== USER_LOCATIONS TABLE ===\n";
$rows = $wpdb->get_results('SELECT * FROM ' . ws_table_name('user_locations'));
foreach ($rows as $r) {
    echo "  user_id={$r->user_id} location_id={$r->location_id}\n";
}

echo "\n=== STOCK (first 10 rows) ===\n";
$rows = $wpdb->get_results('SELECT product_id, location_id, qty FROM ' . ws_table_name('stock') . ' LIMIT 10');
foreach ($rows as $r) {
    echo "  product_id={$r->product_id} location_id={$r->location_id} qty={$r->qty}\n";
}
