<?php
/**
 * Capa de base de datos: crea las tablas del dominio.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

function ws_db_tables() {
    return array(
        'businesses' => "CREATE TABLE {prefix}ws_businesses (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NULL,
            logo VARCHAR(255) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1,
            marketplace_enabled TINYINT(1) NOT NULL DEFAULT 0,
            cloud_name VARCHAR(120) NOT NULL DEFAULT '',
            cloud_api_key VARCHAR(120) NOT NULL DEFAULT '',
            cloud_api_secret VARCHAR(120) NOT NULL DEFAULT '',
            cloud_upload_preset VARCHAR(120) NOT NULL DEFAULT '',
            cloud_folder VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {charset};",

        'locations'  => "CREATE TABLE {prefix}ws_locations (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL DEFAULT 'pv',
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL DEFAULT '',
            address VARCHAR(255) NOT NULL DEFAULT '',
            photo VARCHAR(255) NOT NULL DEFAULT '',
            currency VARCHAR(10) NOT NULL DEFAULT '€',
            payment_methods TEXT NULL,
            store_settings TEXT NULL,
            whatsapp VARCHAR(60) NOT NULL DEFAULT '',
            delivery_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {charset};",

        // Conexiones entre ubicaciones (stock compartido): cuando se vende en
        // una ubicación, el stock se rebaja también en TODAS las conectadas
        // (transitividad: A-B y B-C implica A-C). Se guarda el par canónico
        // (location_a < location_b) para que el grafo sea no dirigido.
        'location_links' => "CREATE TABLE {prefix}ws_location_links (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_a BIGINT(20) UNSIGNED NOT NULL,
            location_b BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY pair (location_a, location_b),
            KEY location_b (location_b)
        ) {charset};",

        // Combos: paquetes de productos (1 combo contiene x, y, z con sus
        // cantidades). El precio puede ser manual o auto-calculado desde los
        // precios de sus productos. El stock del combo se DERIVA del stock de
        // sus componentes (min de floor(stock / cantidad)).
        'combos' => "CREATE TABLE {prefix}ws_combos (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            photo VARCHAR(255) NOT NULL DEFAULT '',
            price_mode VARCHAR(10) NOT NULL DEFAULT 'auto',
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT '€',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY active (active)
        ) {charset};",

        'combo_items' => "CREATE TABLE {prefix}ws_combo_items (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            combo_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY combo_product (combo_id, product_id),
            KEY product_id (product_id)
        ) {charset};",

        'suppliers' => "CREATE TABLE {prefix}ws_suppliers (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(60) NOT NULL DEFAULT '',
            address VARCHAR(255) NOT NULL DEFAULT '',
            country VARCHAR(120) NOT NULL DEFAULT '',
            province VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {charset};",

        'products' => "CREATE TABLE {prefix}ws_products (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            barcode VARCHAR(100) NOT NULL DEFAULT '',
            category VARCHAR(100) NOT NULL DEFAULT '',
            description TEXT NULL,
            image VARCHAR(255) NOT NULL DEFAULT '',
            cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            transfer_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT '€',
            show_equiv TINYINT(1) NOT NULL DEFAULT 1,
            supplier_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            min_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
            production_date DATE NULL,
            expiry_date DATE NULL,
            fraction_parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            fraction_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY barcode (barcode),
            KEY fraction_parent (fraction_parent)
        ) {charset};",

        'stock' => "CREATE TABLE {prefix}ws_stock (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            fraction_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY product_location (product_id, location_id),
            KEY location_id (location_id)
        ) {charset};",

        'movements' => "CREATE TABLE {prefix}ws_movements (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(30) NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            dest_location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            reference VARCHAR(120) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY location_id (location_id),
            KEY type (type)
        ) {charset};",

        'orders' => "CREATE TABLE {prefix}ws_orders (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            number VARCHAR(30) NOT NULL,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            customer_name VARCHAR(255) NOT NULL DEFAULT '',
            customer_phone VARCHAR(60) NOT NULL DEFAULT '',
            customer_address VARCHAR(255) NOT NULL DEFAULT '',
            currency VARCHAR(10) NOT NULL DEFAULT '€',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            delivery_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY number (number),
            KEY location_id (location_id),
            KEY status (status)
        ) {charset};",

        'order_items' => "CREATE TABLE {prefix}ws_order_items (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            combo_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) {charset};",

        'shifts' => "CREATE TABLE {prefix}ws_shifts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            shift_date DATE NOT NULL,
            time_start TIME NOT NULL,
            time_end TIME NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY location_id (location_id),
            KEY user_id (user_id),
            KEY shift_date (shift_date)
        ) {charset};",

        'user_locations' => "CREATE TABLE {prefix}ws_user_locations (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_location (user_id, location_id),
            KEY location_id (location_id)
        ) {charset};",

        'work_sessions' => "CREATE TABLE {prefix}ws_work_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            shift_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_date DATE NOT NULL,
            clock_in DATETIME NOT NULL,
            clock_out DATETIME NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            closed_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY shift_id (shift_id),
            KEY session_date (session_date),
            KEY status (status)
        ) {charset};",

        'audit' => "CREATE TABLE {prefix}ws_audit (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(40) NOT NULL DEFAULT '',
            entity_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            detail TEXT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {charset};",

        'price_history' => "CREATE TABLE {prefix}ws_price_history (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            old_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            new_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            old_sale DECIMAL(12,2) NOT NULL DEFAULT 0,
            new_sale DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT '',
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY created_at (created_at)
        ) {charset};",

        'notifications' => "CREATE TABLE {prefix}ws_notifications (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            message VARCHAR(255) NOT NULL DEFAULT '',
            link VARCHAR(255) NOT NULL DEFAULT '',
            ref_key VARCHAR(120) NOT NULL DEFAULT '',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY user_read (user_id, is_read),
            KEY ref_key (user_id, ref_key)
        ) {charset};",

        'customers' => "CREATE TABLE {prefix}ws_customers (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL DEFAULT '',
            phone VARCHAR(60) NOT NULL DEFAULT '',
            address VARCHAR(255) NOT NULL DEFAULT '',
            city VARCHAR(120) NOT NULL DEFAULT '',
            province VARCHAR(120) NOT NULL DEFAULT '',
            postal_code VARCHAR(20) NOT NULL DEFAULT '',
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            loyalty_points INT(11) NOT NULL DEFAULT 0,
            total_spent DECIMAL(12,2) NOT NULL DEFAULT 0,
            orders_count INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email),
            KEY phone (phone)
        ) {charset};",

        'cart' => "CREATE TABLE {prefix}ws_cart (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(255) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY user_id (user_id),
            KEY location_id (location_id)
        ) {charset};",

        'reviews' => "CREATE TABLE {prefix}ws_reviews (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            customer_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            customer_name VARCHAR(255) NOT NULL DEFAULT '',
            rating INT(1) NOT NULL DEFAULT 5,
            title VARCHAR(255) NOT NULL DEFAULT '',
            comment TEXT NULL,
            verified_purchase TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            approved TINYINT(1) NOT NULL DEFAULT 0,
            client_hash VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY location_id (location_id),
            KEY customer_id (customer_id),
            KEY approved (approved),
            KEY status (status),
            KEY client_hash (client_hash)
        ) {charset};",

        'loyalty_transactions' => "CREATE TABLE {prefix}ws_loyalty_transactions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT(20) UNSIGNED NOT NULL,
            points INT(11) NOT NULL DEFAULT 0,
            type VARCHAR(20) NOT NULL DEFAULT 'earned',
            reference VARCHAR(120) NOT NULL DEFAULT '',
            order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY type (type)
        ) {charset};",

        'pos_sales' => "CREATE TABLE {prefix}ws_pos_sales (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            number VARCHAR(30) NOT NULL,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            seller_id BIGINT(20) UNSIGNED NOT NULL,
            customer_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            customer_name VARCHAR(255) NOT NULL DEFAULT '',
            customer_doc VARCHAR(60) NOT NULL DEFAULT '',
            customer_phone VARCHAR(60) NOT NULL DEFAULT '',
            currency VARCHAR(10) NOT NULL DEFAULT '€',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',
            cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            transfer_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            transfer_number VARCHAR(100) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            register_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            client_ref VARCHAR(64) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY number (number),
            UNIQUE KEY client_ref (client_ref),
            KEY location_id (location_id),
            KEY seller_id (seller_id),
            KEY status (status),
            KEY register_id (register_id)
        ) {charset};",

        'pos_sale_items' => "CREATE TABLE {prefix}ws_pos_sale_items (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sale_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            combo_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY sale_id (sale_id)
        ) {charset};",

        'pos_cash' => "CREATE TABLE {prefix}ws_pos_cash (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            seller_id BIGINT(20) UNSIGNED NOT NULL,
            opening_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_note VARCHAR(255) NOT NULL DEFAULT '',
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closing_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            closing_note VARCHAR(255) NOT NULL DEFAULT '',
            closed_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            PRIMARY KEY (id),
            KEY location_id (location_id),
            KEY status (status)
        ) {charset};",

        // Cuadre de inventario del cierre de caja: conteo FÍSICO ingresado al
        // cerrar vs. el stock VIRTUAL que maneja la app. Se compara producto
        // por producto (sobrante/faltante) y queda auditado por cierre.
        'pos_cash_counts' => "CREATE TABLE {prefix}ws_pos_cash_counts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            register_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            virtual_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            physical_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            diff DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY register_id (register_id),
            KEY product_id (product_id)
        ) {charset};",

        // Cuadre de inventario INDEPENDIENTE (sin caja): el conteo físico de la
        // ubicación vs. el stock virtual que maneja la app. Se guarda el detalle
        // completo (producto, virtual, físico, diferencia) en JSON por fila, con
        // resumen de cuadrados/sobrantes/faltantes para el historial. Sirve para
        // auditar el inventario en cualquier momento, no solo al cerrar caja.
        'stock_counts' => "CREATE TABLE {prefix}ws_stock_counts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            items LONGTEXT NULL,
            summary VARCHAR(120) NOT NULL DEFAULT '',
            adjusted TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY location_id (location_id),
            KEY created_at (created_at)
        ) {charset};",

        'queue' => "CREATE TABLE {prefix}ws_queue (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            hook VARCHAR(255) NOT NULL,
            args TEXT NULL,
            priority INT(11) NOT NULL DEFAULT 10,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            retry_count INT(11) NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY priority (priority)
        ) {charset};",

        // Planes de suscripción (globales, no por negocio).
        'plans' => "CREATE TABLE {prefix}ws_plans (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(60) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            duration_days INT(11) NOT NULL DEFAULT 30,
            limits TEXT NULL,
            has_chatbot TINYINT(1) NOT NULL DEFAULT 0,
            is_trial TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {charset};",

        // Categorías de productos en ÁRBOL (subcategorías): cada negocio
        // organiza su catálogo con jerarquía padre/hijo (podables, editables
        // y eliminables). Los productos apuntan a category_id.
        'categories' => "CREATE TABLE {prefix}ws_categories (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(150) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY parent_id (parent_id),
            KEY slug (slug)
        ) {charset};",

        // Gastos del negocio (control de gastos): concepto, monto, categoría,
        // fecha del gasto (por mes) y UBICACIÓN: 0 = general (se reparte a todas
        // las ubicaciones) o un location_id concreto (solo cuenta para esa).
        'expenses' => "CREATE TABLE {prefix}ws_expenses (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            concept VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            category VARCHAR(120) NOT NULL DEFAULT '',
            note TEXT NULL,
            expense_date DATETIME NOT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY location_id (location_id),
            KEY expense_date (expense_date)
        ) {charset};",

        // Anuncios (global, aislados por business_id): mensajes y notificaciones
        // ancladas que el dueño envía a los usuarios de SU negocio (scope
        // 'business') o el admin del sistema a TODO el sitio (scope 'site').
        'announcements' => "CREATE TABLE {prefix}ws_announcements (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            business_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            scope VARCHAR(20) NOT NULL DEFAULT 'business',
            title VARCHAR(255) NOT NULL DEFAULT '',
            message TEXT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'info',
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            dismissible TINYINT(1) NOT NULL DEFAULT 1,
            pinned_until DATETIME NULL,
            show_from DATETIME NULL,
            show_until DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY business_id (business_id),
            KEY scope (scope),
            KEY active_pinned (active, pinned),
            KEY pinned_until (pinned_until),
            KEY show_from_show_until (show_from, show_until)
        ) {charset};",

        // Suscripción de cada negocio (global, una fila por negocio).
        'subscriptions' => "CREATE TABLE {prefix}ws_subscriptions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            business_id BIGINT(20) UNSIGNED NOT NULL,
            plan_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'trial',
            trial_started_at DATETIME NULL,
            trial_ends_at DATETIME NULL,
            plan_started_at DATETIME NULL,
            plan_ends_at DATETIME NULL,
            upgrade_plan_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            upgrade_status VARCHAR(20) NOT NULL DEFAULT 'none',
            upgrade_requested_at DATETIME NULL,
            upgrade_decided_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY business_id (business_id)
        ) {charset};",
    );
}

/**
 * Tablas globales (no aisladas por negocio): no se crean por negocio, no se
 * renombran al cambiar el slug ni se eliminan al borrar el negocio.
 */
function ws_global_tables() {
    return array( 'plans', 'subscriptions', 'announcements' );
}

function ws_db_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    foreach ( ws_db_tables() as $key => $sql ) {
        $sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
        $sql = str_replace( '{charset}', $charset_collate, $sql );
        dbDelta( $sql );
    }
    update_option( 'ws_db_version', WS_DB_VERSION );
}

// Migración puntual: añade columnas nuevas a tablas existentes (dbDelta no
// las añade si la tabla ya existe y el CREATE TABLE cambió en versiones viejas).
add_action( 'init', 'ws_db_migrate' );
function ws_db_migrate() {
    global $wpdb;
    $table = $wpdb->prefix . WS_TABLE_PREFIX . 'products';
    // En instalación nueva la tabla puede no existir todavía (dbDelta corre en
    // ws_lazy_install, también en init): no intentar migrar una tabla ausente.
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return;
    }
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
    if ( ! in_array( 'show_equiv', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN show_equiv TINYINT(1) NOT NULL DEFAULT 1 AFTER currency" );
    }
    // Columna de categoría en productos: filtros del catálogo y del chatbot.
    if ( ! in_array( 'category', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT '' AFTER barcode" );
    }
    // Configuración de la TIENDA PÚBLICA por ubicación (JSON): moneda en la
    // que se muestran los precios y qué tasa de cambio mostrar (o ninguna).
    // Se migra la tabla por defecto y la de cada negocio con slug (mismo
    // patrón que las tablas de stock_counts/location_links).
    $ws_ss_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_ss_biz ) {
            $ss_slug = (string) ( $ws_ss_biz->slug ?? '' );
            if ( '' !== $ss_slug ) {
                $ws_ss_suffixes[] = ws_biz_table_suffix( $ss_slug );
            }
        }
    }
    foreach ( $ws_ss_suffixes as $ws_ss_suffix ) {
        $loc_t = ws_table_for( $ws_ss_suffix, 'locations' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $loc_t ) ) !== $loc_t ) {
            continue;
        }
        $lcols = $wpdb->get_col( "SHOW COLUMNS FROM {$loc_t}", 0 );
        if ( ! in_array( 'store_settings', $lcols, true ) ) {
            $wpdb->query( "ALTER TABLE {$loc_t} ADD COLUMN store_settings TEXT NULL AFTER payment_methods" );
        }
    }

    // Columna de moderación en reseñas: estado (pending/approved/rejected).
    $rt = $wpdb->prefix . WS_TABLE_PREFIX . 'reviews';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rt ) ) === $rt ) {
        $rcols = $wpdb->get_col( "SHOW COLUMNS FROM {$rt}", 0 );
        if ( ! in_array( 'status', $rcols, true ) ) {
            $wpdb->query( "ALTER TABLE {$rt} ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER verified_purchase" );
            $wpdb->query( "UPDATE {$rt} SET status = IF(approved = 1, 'approved', 'pending')" );
        }
    }
    // Tabla de notificaciones: se crea sola si falta en instalaciones viejas.
    $nt = $wpdb->prefix . WS_TABLE_PREFIX . 'notifications';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $nt ) ) !== $nt ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = ws_db_tables()['notifications'];
        $sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
        $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
        dbDelta( $sql );
    }
    // Tabla de historial de precios: se crea sola si falta en instalaciones viejas.
    $pt = $wpdb->prefix . WS_TABLE_PREFIX . 'price_history';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt ) ) !== $pt ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = ws_db_tables()['price_history'];
        $sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
        $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
        dbDelta( $sql );
    }
    // Tabla de transacciones de puntos de fidelización: se crea sola si falta.
    $lt = $wpdb->prefix . WS_TABLE_PREFIX . 'loyalty_transactions';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lt ) ) !== $lt ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = ws_db_tables()['loyalty_transactions'];
        $sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
        $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
        dbDelta( $sql );
    }

    // Tablas globales de planes, suscripciones y anuncios: se crean solas si
    // faltan en instalaciones viejas (y se siembran los planes por defecto).
    foreach ( array( 'plans', 'subscriptions', 'announcements' ) as $gt ) {
        $gt_table = $wpdb->prefix . WS_TABLE_PREFIX . $gt;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gt_table ) ) !== $gt_table ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $sql = ws_db_tables()[ $gt ];
            $sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
            $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
            dbDelta( $sql );
        }
    }
    if ( function_exists( 'ws_plans_seed_defaults' ) ) {
        ws_plans_seed_defaults();
    }

    // Columna `scope` en anuncios: alcance del anuncio ('business' del dueño
    // para su negocio, 'site' del admin para todo el sitio). Se aplica a la
    // tabla global; los anuncios existentes quedan como 'business'.
    $at = $wpdb->prefix . WS_TABLE_PREFIX . 'announcements';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $at ) ) === $at ) {
        $a_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$at}", 0 );
        if ( ! in_array( 'scope', $a_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$at} ADD COLUMN scope VARCHAR(20) NOT NULL DEFAULT 'business' AFTER business_id" );
            $wpdb->query( "ALTER TABLE {$at} ADD KEY scope (scope)" );
        }
        if ( ! in_array( 'dismissible', $a_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$at} ADD COLUMN dismissible TINYINT(1) NOT NULL DEFAULT 1 AFTER pinned" );
        }
        if ( ! in_array( 'pinned_until', $a_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$at} ADD COLUMN pinned_until DATETIME NULL AFTER pinned" );
        }
        if ( ! in_array( 'show_from', $a_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$at} ADD COLUMN show_from DATETIME NULL AFTER pinned_until" );
        }
        if ( ! in_array( 'show_until', $a_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$at} ADD COLUMN show_until DATETIME NULL AFTER show_from" );
        }
    }

    // Columna `has_chatbot` en planes: qué planes incluyen el asistente del
    // sitio (chatbot). Los negocios cuyo plan no lo incluye no usan el bot
    // en su panel (solo ven el aviso de upgrade).
    $plans_table = $wpdb->prefix . WS_TABLE_PREFIX . 'plans';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $plans_table ) ) === $plans_table ) {
        $p_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$plans_table}", 0 );
        if ( ! in_array( 'has_chatbot', $p_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$plans_table} ADD COLUMN has_chatbot TINYINT(1) NOT NULL DEFAULT 0 AFTER limits" );
            // Valores por defecto para los planes sembrados: la prueba y los
            // planes Pro/Premium incluyen el chatbot; el Básico no (upsell).
            $wpdb->query( "UPDATE {$plans_table} SET has_chatbot = CASE slug
                WHEN 'free-trial' THEN 1
                WHEN 'pro' THEN 1
                WHEN 'premium' THEN 1
                WHEN 'legacy' THEN 1
                ELSE has_chatbot END" );
        }
    }

    // Columna `active` en clientes (CRM): permite activar/desactivar clientes.
    $ct = $wpdb->prefix . WS_TABLE_PREFIX . 'customers';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ct ) ) === $ct ) {
        $c_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$ct}", 0 );
        if ( ! in_array( 'active', $c_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$ct} ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes" );
        }
    }

    // Columna `location_id` en gastos: 0 = general (se reparte a TODAS las
    // ubicaciones del negocio) o un location_id concreto (solo cuenta para
    // esa ubicación en los reportes). Los gastos existentes quedan generales.
    $ws_exp_tables = $wpdb->get_col( "SHOW TABLES LIKE '" . $wpdb->esc_like( $wpdb->prefix . 'ws' ) . "%_expenses'" );
    foreach ( (array) $ws_exp_tables as $ws_exp_t ) {
        $ws_exp_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$ws_exp_t}", 0 );
        if ( ! in_array( 'location_id', $ws_exp_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$ws_exp_t} ADD COLUMN location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER id" );
            $wpdb->query( "ALTER TABLE {$ws_exp_t} ADD KEY location_id (location_id)" );
        }
    }

    // Columnas en reseñas: `status` (moderación con 3 estados) y `client_hash`
    // (anti-duplicados de reseñas anónimas). Se aplica a la tabla por defecto
    // Y a la de cada negocio con slug: las reseñas de una tienda /negocio/
    // viven en la tabla del negocio y sin la columna save_review fallaría con
    // "Unknown column".
    $ws_review_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_rb ) {
            $r_slug = (string) ( $ws_rb->slug ?? '' );
            if ( '' !== $r_slug ) {
                $ws_review_suffixes[] = ws_biz_table_suffix( $r_slug );
            }
        }
    }
    foreach ( $ws_review_suffixes as $ws_rev_suffix ) {
        $rt = ws_table_for( $ws_rev_suffix, 'reviews' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rt ) ) !== $rt ) {
            continue;
        }
        $r_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$rt}", 0 );
        if ( ! in_array( 'status', $r_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$rt} ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER comment" );
            $wpdb->query( "UPDATE {$rt} SET status = 'approved' WHERE approved = 1" );
        }
        if ( ! in_array( 'client_hash', $r_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$rt} ADD COLUMN client_hash VARCHAR(64) NOT NULL DEFAULT '' AFTER approved" );
        }
        $has_hash_idx = $wpdb->get_var( "SHOW INDEX FROM {$rt} WHERE Key_name='client_hash'" );
        if ( ! $has_hash_idx ) {
            $wpdb->query( "ALTER TABLE {$rt} ADD KEY client_hash (client_hash)" );
        }
    }

    // Columnas de pago detallado en ventas POS: efectivo + transferencia
    // (datos de la transferencia y desglose de montos en pago mixto).
    $st = $wpdb->prefix . WS_TABLE_PREFIX . 'pos_sales';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $st ) ) === $st ) {
        $s_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$st}", 0 );
        $pos_alters = array(
            'customer_doc'    => "ALTER TABLE {$st} ADD COLUMN customer_doc VARCHAR(60) NOT NULL DEFAULT '' AFTER customer_name",
            'customer_phone'  => "ALTER TABLE {$st} ADD COLUMN customer_phone VARCHAR(60) NOT NULL DEFAULT '' AFTER customer_doc",
            'cash_amount'     => "ALTER TABLE {$st} ADD COLUMN cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_method",
            'transfer_amount' => "ALTER TABLE {$st} ADD COLUMN transfer_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cash_amount",
            'transfer_number' => "ALTER TABLE {$st} ADD COLUMN transfer_number VARCHAR(100) NOT NULL DEFAULT '' AFTER transfer_amount",
            'register_id'     => "ALTER TABLE {$st} ADD COLUMN register_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER status",
            'client_ref'      => "ALTER TABLE {$st} ADD COLUMN client_ref VARCHAR(64) NULL DEFAULT NULL AFTER register_id",
        );
        foreach ( $pos_alters as $col => $sql ) {
            if ( ! in_array( $col, $s_cols, true ) ) {
                $wpdb->query( $sql );
            }
        }
        // Índice único de client_ref: evita ventas duplicadas al reenviar una
        // venta offline (la respuesta pudo perderse y la cola reintenta).
        $has_ref_idx = $wpdb->get_var( "SHOW INDEX FROM {$st} WHERE Key_name='client_ref'" );
        if ( ! $has_ref_idx ) {
            $wpdb->query( "ALTER TABLE {$st} ADD UNIQUE KEY client_ref (client_ref)" );
        }
    }

    // Fraccionamiento de productos (padre/hijo conectados): columnas de enlace
    // y factor de conversión en productos, más el saldo fraccional del stock.
    // Se aplica a la tabla por defecto Y a la de cada negocio con slug propio:
    // las tablas por negocio se crearon antes de existir estas columnas y
    // dbDelta no altera tablas ya creadas, por lo que insertar/leer productos o
    // registrar movimientos de stock fallaba con "Unknown column".
    $ws_fraction_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_b ) {
            $slug = (string) ( $ws_b->slug ?? '' );
            if ( '' !== $slug ) {
                $ws_fraction_suffixes[] = ws_biz_table_suffix( $slug );
            }
        }
    }
    foreach ( $ws_fraction_suffixes as $ws_suffix ) {
        $pt = ws_table_for( $ws_suffix, 'products' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt ) ) === $pt ) {
            $p_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$pt}", 0 );
            foreach ( array(
                'fraction_parent' => "ALTER TABLE {$pt} ADD COLUMN fraction_parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER min_stock",
                'fraction_qty'    => "ALTER TABLE {$pt} ADD COLUMN fraction_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER fraction_parent",
                'category'        => "ALTER TABLE {$pt} ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT '' AFTER barcode",
            ) as $col => $sql ) {
                if ( ! in_array( $col, $p_cols, true ) ) {
                    $wpdb->query( $sql );
                }
            }
        }
        $stock_t = ws_table_for( $ws_suffix, 'stock' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stock_t ) ) === $stock_t ) {
            $stock_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$stock_t}", 0 );
            if ( ! in_array( 'fraction_balance', $stock_cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$stock_t} ADD COLUMN fraction_balance DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER qty" );
            }
        }
        // Reseñas a nivel de TIENDA (no solo de producto): las valoraciones
        // públicas se asocian a la ubicación (location_id) y product_id queda
        // 0. Se aplica a la tabla por defecto y a la de cada negocio con slug.
        $rev_t = ws_table_for( $ws_suffix, 'reviews' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rev_t ) ) === $rev_t ) {
            $rev_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$rev_t}", 0 );
            if ( ! in_array( 'location_id', $rev_cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$rev_t} ADD COLUMN location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER product_id" );
                $wpdb->query( "ALTER TABLE {$rev_t} ADD KEY location_id (location_id)" );
            }
        }
    }

    // Fechas de caducidad de productos: `production_date` (fecha de producción)
    // y `expiry_date` (fecha de vencimiento). Se aplican a la tabla por defecto
    // Y a la de cada negocio con slug propio, igual que el fraccionamiento.
    $ws_expiry_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_xb ) {
            $x_slug = (string) ( $ws_xb->slug ?? '' );
            if ( '' !== $x_slug ) {
                $ws_expiry_suffixes[] = ws_biz_table_suffix( $x_slug );
            }
        }
    }
    foreach ( $ws_expiry_suffixes as $ws_exp_suffix ) {
        $xpt = ws_table_for( $ws_exp_suffix, 'products' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $xpt ) ) === $xpt ) {
            $xp_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$xpt}", 0 );
            foreach ( array(
                'production_date' => "ALTER TABLE {$xpt} ADD COLUMN production_date DATE NULL AFTER min_stock",
                'expiry_date'     => "ALTER TABLE {$xpt} ADD COLUMN expiry_date DATE NULL AFTER production_date",
            ) as $xp_col => $xp_sql ) {
                if ( ! in_array( $xp_col, $xp_cols, true ) ) {
                    $wpdb->query( $xp_sql );
                }
            }
        }
    }

    // Costo de producto en cada item de venta POS: permite calcular la GANANCIA
    // real (precio de venta − costo) × cantidad por venta, por ubicación y por
    // período, aunque el precio/costo del producto cambie después. Se aplica a
    // la tabla por defecto y a la de cada negocio con slug propio.
    $ws_cost_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_cb ) {
            $c_slug = (string) ( $ws_cb->slug ?? '' );
            if ( '' !== $c_slug ) {
                $ws_cost_suffixes[] = ws_biz_table_suffix( $c_slug );
            }
        }
    }
    foreach ( $ws_cost_suffixes as $ws_cost_suffix ) {
        $sit_t = ws_table_for( $ws_cost_suffix, 'pos_sale_items' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sit_t ) ) === $sit_t ) {
            $sit_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$sit_t}", 0 );
            if ( ! in_array( 'cost_price', $sit_cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$sit_t} ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER price" );
            }
        }
    }

    // Caja POS (apertura/cierre): se crea sola si falta en instalaciones viejas.
    $ct2 = $wpdb->prefix . WS_TABLE_PREFIX . 'pos_cash';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ct2 ) ) !== $ct2 ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = ws_db_tables()['pos_cash'];
        $sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
        $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
        dbDelta( $sql );
    }
    // Cuadre de inventario del cierre (conteo físico vs. stock virtual).
    $ct3 = $wpdb->prefix . WS_TABLE_PREFIX . 'pos_cash_counts';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ct3 ) ) !== $ct3 ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = ws_db_tables()['pos_cash_counts'];
        $sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
        $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
        dbDelta( $sql );
    }
    // Cuadre de inventario INDEPENDIENTE (sin caja): tabla por defecto y por
    // negocio con slug (igual que los demás módulos, dbDelta no altera las
    // tablas ya creadas).
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $ws_count_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_cn ) {
            $n_slug = (string) ( $ws_cn->slug ?? '' );
            if ( '' !== $n_slug ) {
                $ws_count_suffixes[] = ws_biz_table_suffix( $n_slug );
            }
        }
    }
    foreach ( $ws_count_suffixes as $ws_count_suffix ) {
        $cnt_t = ws_table_for( $ws_count_suffix, 'stock_counts' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cnt_t ) ) !== $cnt_t ) {
            // Mismo patrón que ws_create_business_tables: el negocio por defecto
            // usa {prefix} = $wpdb->prefix (wp_ → wp_ws_stock_counts); los demás
            // usan wp_ws_{sufijo}_ (→ wp_ws_{sufijo}_ws_stock_counts).
            $sql = ws_db_tables()['stock_counts'];
            $cnt_prefix = '' !== $ws_count_suffix
                ? $wpdb->prefix . WS_TABLE_PREFIX . $ws_count_suffix . '_'
                : $wpdb->prefix;
            $sql = str_replace( '{prefix}', $cnt_prefix, $sql );
            $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
            dbDelta( $sql );
        }
    }

    // Conexiones entre ubicaciones (stock compartido): se crea la tabla de
    // enlaces por defecto y por negocio con slug (igual que stock_counts,
    // dbDelta no altera las tablas ya creadas).
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $ws_link_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_ln ) {
            $l_slug = (string) ( $ws_ln->slug ?? '' );
            if ( '' !== $l_slug ) {
                $ws_link_suffixes[] = ws_biz_table_suffix( $l_slug );
            }
        }
    }
    foreach ( $ws_link_suffixes as $ws_link_suffix ) {
        $lk_t = ws_table_for( $ws_link_suffix, 'location_links' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lk_t ) ) !== $lk_t ) {
            $sql = ws_db_tables()['location_links'];
            $lk_prefix = '' !== $ws_link_suffix
                ? $wpdb->prefix . WS_TABLE_PREFIX . $ws_link_suffix . '_'
                : $wpdb->prefix;
            $sql = str_replace( '{prefix}', $lk_prefix, $sql );
            $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
            dbDelta( $sql );
        }
    }

    // Combos: se crea la tabla por defecto y la de cada negocio con slug
    // (igual que stock_counts/location_links, dbDelta no altera las tablas ya
    // creadas). La columna combo_id de order_items/pos_sale_items se añade
    // con ALTER si falta.
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $ws_combo_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_cb ) {
            $cb_slug = (string) ( $ws_cb->slug ?? '' );
            if ( '' !== $cb_slug ) {
                $ws_combo_suffixes[] = ws_biz_table_suffix( $cb_slug );
            }
        }
    }
    foreach ( $ws_combo_suffixes as $ws_combo_suffix ) {
        $combo_prefix = '' !== $ws_combo_suffix
            ? $wpdb->prefix . WS_TABLE_PREFIX . $ws_combo_suffix . '_'
            : $wpdb->prefix;
        $combo_t = ws_table_for( $ws_combo_suffix, 'combos' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $combo_t ) ) !== $combo_t ) {
            $sql = ws_db_tables()['combos'];
            $sql = str_replace( '{prefix}', $combo_prefix, $sql );
            $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
            dbDelta( $sql );
        }
        $ci_t = ws_table_for( $ws_combo_suffix, 'combo_items' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ci_t ) ) !== $ci_t ) {
            $sql = ws_db_tables()['combo_items'];
            $sql = str_replace( '{prefix}', $combo_prefix, $sql );
            $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
            dbDelta( $sql );
        }
        // combo_id en los ítems de pedidos y ventas POS (para ventas de combos).
        foreach ( array( 'order_items', 'pos_sale_items' ) as $ws_ci_tbl ) {
            $ws_ci_t = ws_table_for( $ws_combo_suffix, $ws_ci_tbl );
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ws_ci_t ) ) !== $ws_ci_t ) {
                continue;
            }
            $ws_ci_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$ws_ci_t}", 0 );
            if ( ! in_array( 'combo_id', $ws_ci_cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$ws_ci_t} ADD COLUMN combo_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER product_id" );
            }
        }
    }

    // Módulos nuevos: Categorías (árbol con subcategorías) y Gastos (control
    // mensual). Se crean/actualizan en la tabla por defecto Y en la de cada
    // negocio con slug propio (dbDelta no altera tablas ya creadas), y se
    // añade la columna category_id a productos (enlace a la categoría árbol).
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $ws_new_suffixes = array( '' );
    if ( class_exists( 'WS_Business' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WS_Business::table() ) ) === WS_Business::table() ) {
        foreach ( WS_Business::all() as $ws_nb ) {
            $n_slug = (string) ( $ws_nb->slug ?? '' );
            if ( '' !== $n_slug ) {
                $ws_new_suffixes[] = ws_biz_table_suffix( $n_slug );
            }
        }
    }
    foreach ( $ws_new_suffixes as $ws_new_suffix ) {
        // Limpieza: una versión previa creó para negocios existentes tablas
        // con doble «ws_» en el nombre (wp_ws_{sufijo}_ws_ws_categorias) en
        // vez de wp_ws_{sufijo}_ws_categorias. Se borran antes de recrear.
        foreach ( array( 'categories', 'expenses' ) as $ws_bad_table ) {
            $ws_bad = $wpdb->prefix . WS_TABLE_PREFIX . ( '' !== $ws_new_suffix ? $ws_new_suffix . '_ws_' : '' ) . 'ws_' . $ws_bad_table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ws_bad ) ) === $ws_bad ) {
                $wpdb->query( "DROP TABLE IF EXISTS {$ws_bad}" );
            }
        }
        // El prefijo para las plantillas es wp_ws_{sufijo}_ (sin el «ws_» de
        // la entidad, que ya lo pone ws_db_tables): igual que ws_create_business_tables.
        $ws_new_prefix = $wpdb->prefix . WS_TABLE_PREFIX . ( '' !== $ws_new_suffix ? $ws_new_suffix . '_' : '' );
        foreach ( array( 'categories', 'expenses', 'work_sessions' ) as $ws_new_table ) {
            $new_t = ws_table_for( $ws_new_suffix, $ws_new_table );
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_t ) ) !== $new_t ) {
                $sql = ws_db_tables()[ $ws_new_table ];
                $sql = str_replace( '{prefix}', $ws_new_prefix, $sql );
                $sql = str_replace( '{charset}', $wpdb->get_charset_collate(), $sql );
                dbDelta( $sql );
            }
        }
        // Enlace de productos a su categoría en árbol (default y por negocio).
        $pt2 = ws_table_for( $ws_new_suffix, 'products' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt2 ) ) === $pt2 ) {
            $p2_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$pt2}", 0 );
            if ( ! in_array( 'category_id', $p2_cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$pt2} ADD COLUMN category_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER category" );
                $wpdb->query( "ALTER TABLE {$pt2} ADD KEY category_id (category_id)" );
            }
        }
    }
}
