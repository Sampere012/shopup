<?php
/**
 * Workshop MultiTienda - bootstrap del tema.
 *
 * El tema es la aplicación front-end para los 3 roles de negocio:
 * Dueño, Almacenero y Vendedor/PV. El plugin (si existe) es solo para el Admin.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

define( 'WS_VERSION', '0.4.29' );
// Versión del esquema de BD: subir al añadir columnas/tablas para forzar la
// instalación/migración diferida (ws_lazy_install / ws_db_migrate).
define( 'WS_DB_VERSION', '0.10.4' );
define( 'WS_PATH', get_template_directory() . '/' );
define( 'WS_URL', get_template_directory_uri() . '/' );
define( 'WS_TABLE_PREFIX', 'ws_' );

require_once WS_PATH . 'inc/setup.php';
require_once WS_PATH . 'inc/helpers.php';
require_once WS_PATH . 'inc/business.php';
require_once WS_PATH . 'inc/images.php';
require_once WS_PATH . 'inc/rates.php';
require_once WS_PATH . 'inc/appearance.php';
require_once WS_PATH . 'inc/db.php';
require_once WS_PATH . 'inc/roles.php';
require_once WS_PATH . 'inc/capabilities.php';
require_once WS_PATH . 'inc/crud.php';
require_once WS_PATH . 'inc/categories.php';
require_once WS_PATH . 'inc/expenses.php';
require_once WS_PATH . 'inc/stock.php';
require_once WS_PATH . 'inc/combos.php';
require_once WS_PATH . 'inc/orders.php';
require_once WS_PATH . 'inc/shifts.php';
require_once WS_PATH . 'inc/sessions.php';
require_once WS_PATH . 'inc/router.php';
require_once WS_PATH . 'inc/login.php';
require_once WS_PATH . 'inc/ajax.php';
require_once WS_PATH . 'inc/reports.php';
require_once WS_PATH . 'inc/notifications.php';
require_once WS_PATH . 'inc/announcements.php';
require_once WS_PATH . 'inc/crm.php';
require_once WS_PATH . 'inc/cart.php';
require_once WS_PATH . 'inc/reviews.php';
require_once WS_PATH . 'inc/pos.php';
require_once WS_PATH . 'inc/loyalty.php';
require_once WS_PATH . 'inc/queue.php';
require_once WS_PATH . 'inc/plans.php';
require_once WS_PATH . 'inc/email.php';
require_once WS_PATH . 'inc/registration.php';
require_once WS_PATH . 'inc/site-pages.php';
require_once WS_PATH . 'inc/faqs.php';
require_once WS_PATH . 'inc/tutorial.php';
require_once WS_PATH . 'inc/chatbot.php';
require_once WS_PATH . 'inc/chatbot-tools.php';
require_once WS_PATH . 'inc/admin-chatbot.php';
require_once WS_PATH . 'inc/logs.php';
require_once WS_PATH . 'inc/admin.php';
require_once WS_PATH . 'inc/admin-users.php';
require_once WS_PATH . 'inc/admin-plans.php';

// Flush rewrite rules on theme activation
add_action( 'after_switch_theme', 'ws_flush_rewrite_rules' );
function ws_flush_rewrite_rules() {
    ws_rewrite_rules();
    flush_rewrite_rules();
}
