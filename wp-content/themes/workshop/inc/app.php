<?php
/**
 * App móvil: versión, descarga (APK) y comprobación de actualizaciones.
 *
 * Todo el sistema lee de una sola fuente: el ajuste ws_app_download que el
 * administrador configura en wp-admin (ShopUp → App móvil). El endpoint
 * AJAX ws_app_version lo consume:
 *   - El botón de descarga del footer.
 *   - El asistente (chatbot) cuando le piden "obtener app"/"get app".
 *   - La propia app móvil al arrancar y desde el botón de actualizaciones.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Versión del build móvil actual (la sube sync:web / se edita en wp-admin).
 * Es el valor por defecto del ajuste; el administrador lo cambia al publicar.
 */
define( 'WS_APP_VERSION', '0.6.5' );
define( 'WS_APP_RELEASE_DATE', '2026-10-13' );
define( 'WS_APP_CHANGELOG', 'OFFLINE REAL
- Entradas, salidas, bajas y transferencias se guardan ya en el dispositivo: se ven al instante en Stock, en el Historial, en las demás ubicaciones y en el POS, sin tener que subir ni refrescar.
- Transferencia: corregido el error «Datos de transferencia inválidos» al subir la cola.
- Los movimientos subidos ya no salen duplicados en el Historial.
- Refrescar Stock ya no borra lo hecho sin conexión.
- Si la fila de stock de una ubicación no existía, ahora se crea (transferencias y entradas a ubicaciones nuevas).

STOCK Y POS
- Stock, POS, Historial y Productos dejan de «marearse» al cambiar de pestaña o al subir la cola.
- Historial de caja del POS con pestañas (Todas / Abiertas / Cerradas), resumen de aperturas, cierres y diferencia.
- Cierre de caja con monto contado y cuadre.
- Nuevo: monto de cierre registrado en cada cierre.

PRODUCTOS Y CATÁLOGO
- Nueva gráfica de precios por producto (semana / mes / año) con subidas y bajadas, desde el detalle del producto.
- Botón «Ver histórico de precios».
- Conteo de productos en la portada y en /tienda-negocio/ ahora cuenta solo las tiendas activas.

UBICACIONES Y PERMISOS
- Ubicaciones: se pueden eliminar (con su stock, movimientos, conexiones y asignaciones), igual que en la web.
- El dueño ya no puede editarse sus propios permisos: los gestiona el administrador del sitio.

TRABAJADORES
- El rol «Dueño» ya no aparece al crear o editar un trabajador: el dueño se da de alta desde el panel web.
- Las filas de dueño se muestran solo en lectura (sin editar, pausar ni eliminar) desde la app.
- Un trabajador creado con su calendario ya entra a la app con sus propias credenciales y ve su turno.

ANUNCIOS Y AJUSTES
- Anuncios con tipo, banner fijado y permiso de cierre, e igual que la web: creaciones, ediciones y toggles se guardan offline sin duplicar.
- Ajustes del negocio desde la app: monedas, tasa de cambio (USD/CUP), WhatsApp de pedidos y métodos de pago.
- Banners de anuncios fijados visibles para todos los usuarios.
- Mejoras de estabilidad general en la sincronización.' );

/**
 * Descarga del APK desde el repo público de GitHub (raw).
 * La APK firmada se sube a releases/shopup-panel.apk del repo al publicar
 * una versión; tanto la app como la web la descargan desde aquí sin login.
 */
define( 'WS_APP_RELEASE_URL', 'https://raw.githubusercontent.com/Sampere012/shopup/main/releases/shopup-panel.apk' );
define( 'WS_APP_RELEASE_PAGE', 'https://github.com/Sampere012/shopup/releases/latest' );

/**
 * Ajustes por defecto de la app móvil.
 */
function ws_app_settings_defaults() {
	return array(
		'enabled'   => 0,
		'apk_url'   => '',
		'version'   => WS_APP_VERSION,
		'changelog' => '',
		'release_date' => '',
		'support_links' => array(
			'whatsapp' => '',
			'telegram' => '',
			'facebook' => '',
			'other'    => '',
		),
	);
}

function ws_app_settings() {
	$saved = get_option( 'ws_app_download', null );
	$def   = ws_app_settings_defaults();
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$settings = wp_parse_args( $saved, $def );
	$settings['support_links'] = wp_parse_args(
		is_array( $saved['support_links'] ?? null ) ? $saved['support_links'] : array(),
		$def['support_links']
	);
	return $settings;
}

/**
 * URL de descarga del APK, o '' si no está configurada.
 */
function ws_app_apk_url() {
	$s   = ws_app_settings();
	$url = trim( (string) ( $s['apk_url'] ?? '' ) );
	$def = WS_APP_RELEASE_URL;
	// Si no hay URL configurada, usar la de GitHub Releases (default).
	if ( '' === $url ) {
		return $def;
	}
	// Ruta relativa o la antigua ruta /app/...: resolver contra GitHub Releases,
	// así la carpeta /app/ del servidor ya no se usa para servir el APK.
	if ( '/' === $url[0] || false !== strpos( $url, '/app/shopup-panel.apk' ) || false !== strpos( $url, 'releases/latest/download' ) ) {
		return $def;
	}
	return $url;
}

/**
 * ¿Hay descarga disponible? True cuando hay URL efectiva de descarga.
 *
 * Se mira la URL efectiva (ws_app_apk_url): si el admin no configuró una,
 * cae al APK del repo público, que siempre existe. La casilla «Activar
 * descarga» del admin no entra aquí: footer, asistente y app consumen esta
 * función (o has_apk) y la APK debe poder descargarse siempre que haya URL.
 */
function ws_app_has_download() {
	$apk = trim( (string) ws_app_apk_url() );
	return '' !== $apk;
}

/**
 * Info de versión para consumidores externos (footer, bot, app móvil).
 */
function ws_app_version_info() {
	$s = ws_app_settings();
	// La versión del build del tema manda: si la opción guardada en la BD
	// es más antigua (el admin no la actualizó al desplegar), se reporta la
	// del tema para que las apps detecten la actualización.
	$savedVersion = trim( (string) ( $s['version'] ?? '' ) );
	$version      = ( '' === $savedVersion || version_compare( $savedVersion, WS_APP_VERSION, '<' ) )
		? WS_APP_VERSION
		: $savedVersion;
	$theme_release = '' === $savedVersion || version_compare( $savedVersion, WS_APP_VERSION, '<' );
	$changelog     = trim( (string) ( $s['changelog'] ?? '' ) );
	$release_date  = trim( (string) ( $s['release_date'] ?? '' ) );
	if ( $theme_release ) {
		$changelog    = WS_APP_CHANGELOG;
		$release_date = WS_APP_RELEASE_DATE;
	}
	return array(
		'version'     => $version,
		'apk_url'     => ws_app_apk_url(),
		'release_url' => WS_APP_RELEASE_PAGE,
		'changelog'   => $changelog,
		'release_date' => $release_date,
		'support_links' => $s['support_links'],
		'has_apk'     => ws_app_has_download(),
		'pwa_version' => defined( 'WS_VERSION' ) ? WS_VERSION : '',
		// Días de prueba configurables en wp-admin (no hardcodear en la app).
		'trial_days'  => function_exists( 'ws_trial_days' ) ? ws_trial_days() : 7,
	);
}

/**
 * Endpoint AJAX: versión de la app (público, lo usan footer, bot y app).
 */
add_action( 'wp_ajax_ws_app_version', 'ws_ajax_app_version' );
add_action( 'wp_ajax_nopriv_ws_app_version', 'ws_ajax_app_version' );
function ws_ajax_app_version() {
	// La app móvil puede venir sin token (público) o con token; sin sesión
	// también debe responder (el footer/bot lo llaman como visitante).
	wp_send_json_success( ws_app_version_info() );
}

/* -------------------------------------------------------------------------
 * wp-admin: página de configuración de la app móvil
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'ws_app_admin_menu', 25 );
function ws_app_admin_menu() {
	add_submenu_page(
		'ws-permissions',
		__( 'App móvil', 'workshop' ),
		__( 'App móvil', 'workshop' ),
		'manage_options',
		'ws-app',
		'ws_admin_page_app'
	);
}

function ws_admin_page_app() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
	}

	$saved = false;
	if ( isset( $_POST['ws_app_nonce'] ) && wp_verify_nonce( $_POST['ws_app_nonce'], 'ws_save_app' ) ) {
		$previous = ws_app_settings();
		$apk_url = esc_url_raw( (string) ( $_POST['apk_url'] ?? '' ) );
		if ( '' === trim( $apk_url ) ) {
			// Ruta por defecto: GitHub Releases (repo público del negocio).
			$apk_url = WS_APP_RELEASE_URL;
		}
		$version = sanitize_text_field( (string) ( $_POST['version'] ?? WS_APP_VERSION ) );
		$release_date = sanitize_text_field( (string) ( $_POST['release_date'] ?? '' ) );
		if ( '' === $release_date ) {
			$release_date = $version !== (string) $previous['version']
				? current_time( 'Y-m-d' )
				: (string) ( $previous['release_date'] ?? '' );
		}
		$support_links = array(
			'whatsapp' => esc_url_raw( (string) ( $_POST['support_whatsapp'] ?? '' ) ),
			'telegram' => esc_url_raw( (string) ( $_POST['support_telegram'] ?? '' ) ),
			'facebook' => esc_url_raw( (string) ( $_POST['support_facebook'] ?? '' ) ),
			'other'    => esc_url_raw( (string) ( $_POST['support_other'] ?? '' ) ),
		);
		update_option( 'ws_app_download', array(
			'enabled'   => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'apk_url'   => $apk_url,
			'version'   => $version,
			'changelog' => sanitize_textarea_field( (string) ( $_POST['changelog'] ?? '' ) ),
			'release_date' => $release_date,
			'support_links' => $support_links,
		) );
		if ( function_exists( 'ws_log_audit' ) ) {
			ws_log_audit( 'app_settings_update', 'settings', 0, array( 'apk_url' => $apk_url ) );
		}
		$saved = true;
	}

	$s = ws_app_settings();
	?>
	<div class="wrap">
		<h1><span class="dashicons dashicons-smartphone" style="vertical-align:middle"></span> <?php esc_html_e( 'App móvil', 'workshop' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'La aplicación móvil del panel (ShopUp Panel) está desarrollada con Flutter. Configura aquí la descarga, la versión, las novedades y los enlaces de soporte.', 'workshop' ); ?>
		</p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración de la app guardada.', 'workshop' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'ws_save_app', 'ws_app_nonce' ); ?>
			<div class="ws-mp-admin-group">
				<h2><span class="dashicons dashicons-download" style="margin-right:6px"></span><?php esc_html_e( 'Descarga de la app', 'workshop' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activar descarga', 'workshop' ); ?></th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Mostrar el botón de descarga (footer y asistente) y permitir la instalación de la app móvil', 'workshop' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ws-app-apk-url"><?php esc_html_e( 'URL del APK', 'workshop' ); ?></label></th>
						<td>
							<input type="url" id="ws-app-apk-url" name="apk_url" class="regular-text" value="<?php echo esc_attr( $s['apk_url'] ); ?>" placeholder="<?php echo esc_attr( WS_APP_RELEASE_URL ); ?>">
							<p class="description">
								<?php esc_html_e( 'Si lo dejas vacío se usa el APK del repo público de GitHub:', 'workshop' ); ?>
								<code><?php echo esc_html( WS_APP_RELEASE_URL ); ?></code>.
								<?php esc_html_e( 'Al publicar una versión se sube releases/shopup-panel.apk al repo y se cambia aquí la versión; la app y la web lo descargan desde GitHub.', 'workshop' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="ws-mp-admin-group">
				<h2><span class="dashicons dashicons-update" style="margin-right:6px"></span><?php esc_html_e( 'Versión y novedades', 'workshop' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ws-app-version"><?php esc_html_e( 'Versión disponible', 'workshop' ); ?></label></th>
						<td>
							<input type="text" id="ws-app-version" name="version" class="regular-text" value="<?php echo esc_attr( $s['version'] ); ?>" placeholder="<?php echo esc_attr( WS_APP_VERSION ); ?>">
							<p class="description"><?php esc_html_e( 'La app instalada compara esta versión con la suya: si la suya es menor, avisa de una actualización. Sube el número cuando publiques un build nuevo.', 'workshop' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ws-app-release-date"><?php esc_html_e( 'Fecha de publicación', 'workshop' ); ?></label></th>
						<td>
							<input type="date" id="ws-app-release-date" name="release_date" value="<?php echo esc_attr( $s['release_date'] ); ?>">
							<p class="description"><?php esc_html_e( 'Si cambias la versión y dejas la fecha vacía, se asignará la fecha de hoy.', 'workshop' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ws-app-changelog"><?php esc_html_e( 'Novedades (changelog)', 'workshop' ); ?></label></th>
						<td>
							<textarea id="ws-app-changelog" name="changelog" class="large-text" rows="4" placeholder="<?php esc_attr_e( 'Ej.: Nuevas imágenes por producto, botón de actualizaciones, caché de imágenes…', 'workshop' ); ?>"><?php echo esc_textarea( $s['changelog'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Se muestra al usuario cuando hay una versión nueva disponible (en la app y en el asistente).', 'workshop' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="ws-mp-admin-group">
				<h2><span class="dashicons dashicons-format-chat" style="margin-right:6px"></span><?php esc_html_e( 'Contactos de soporte', 'workshop' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( array(
						'whatsapp' => __( 'WhatsApp', 'workshop' ),
						'telegram' => __( 'Telegram', 'workshop' ),
						'facebook' => __( 'Facebook', 'workshop' ),
						'other'    => __( 'Otro enlace', 'workshop' ),
					) as $key => $label ) : ?>
						<tr>
							<th scope="row"><label for="ws-support-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="url" id="ws-support-<?php echo esc_attr( $key ); ?>" name="support_<?php echo esc_attr( $key ); ?>" class="regular-text" value="<?php echo esc_attr( $s['support_links'][ $key ] ); ?>" placeholder="https://"></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>
			<?php submit_button( __( 'Guardar app', 'workshop' ) ); ?>
		</form>

		<hr>
		<p>
			<strong><?php esc_html_e( 'Cómo compilar el APK:', 'workshop' ); ?></strong>
			<code>cd mobile-app-2 && flutter build apk --release</code>
			<?php esc_html_e( 'El archivo queda en', 'workshop' ); ?>
			<code>build/app/outputs/flutter-apk/app-release.apk</code>.
		</p>
	</div>
	<style>
		.ws-mp-admin-group { background: #fff; border: 1px solid #c3c4c7; padding: 8px 20px 16px; margin: 0 0 18px; border-radius: 6px; }
		.ws-mp-admin-group h2 { font-size: 15px; padding-top: 12px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; }
	</style>
	<?php
}
