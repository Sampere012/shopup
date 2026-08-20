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
define( 'WS_APP_VERSION', '0.4.64' );

/**
 * Ajustes por defecto de la app móvil.
 */
function ws_app_settings_defaults() {
	return array(
		'enabled'   => 0,
		'apk_url'   => '',
		'version'   => WS_APP_VERSION,
		'changelog' => '',
	);
}

function ws_app_settings() {
	$saved = get_option( 'ws_app_download', null );
	$def   = ws_app_settings_defaults();
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, $def );
}

/**
 * URL de descarga del APK, o '' si no está configurada.
 */
function ws_app_apk_url() {
	$s   = ws_app_settings();
	$url = trim( (string) ( $s['apk_url'] ?? '' ) );
	// Si no hay URL configurada, usar la ruta por defecto (/app/shopup-panel.apk).
	if ( '' === $url ) {
		$url = home_url( '/app/shopup-panel.apk' );
	}
	// Ruta relativa (p.ej. /app/shopup-panel.apk): resolver contra el sitio
	// para que funcione en cualquier dominio (local, producción, subcarpeta).
	if ( '/' === $url[0] ) {
		$url = home_url( $url );
	}
	return $url;
}

/**
 * ¿Hay descarga disponible? Siempre True cuando hay URL (configurada o por defecto).
 * El admin puede ocultar el botón deshabilitando la descarga en wp-admin.
 */
function ws_app_has_download() {
	$s = ws_app_settings();
	// Si el admin deshabilitó explícitamente, no mostrar.
	if ( isset( $s['enabled'] ) && empty( $s['enabled'] ) && '' !== trim( (string) ( $s['apk_url'] ?? '' ) ) ) {
		return false;
	}
	return true;
}

/**
 * Info de versión para consumidores externos (footer, bot, app móvil).
 */
function ws_app_version_info() {
	$s = ws_app_settings();
	return array(
		'version'     => trim( (string) ( $s['version'] ?? WS_APP_VERSION ) ),
		'apk_url'     => ws_app_apk_url(),
		'changelog'   => trim( (string) ( $s['changelog'] ?? '' ) ),
		'has_apk'     => ws_app_has_download(),
		'pwa_version' => defined( 'WS_VERSION' ) ? WS_VERSION : '',
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
		$apk_url = esc_url_raw( (string) ( $_POST['apk_url'] ?? '' ) );
		if ( '' === trim( $apk_url ) ) {
			// Ruta por defecto: carpeta /app/ en la raíz del sitio.
			$apk_url = home_url( '/app/shopup-panel.apk' );
		}
		update_option( 'ws_app_download', array(
			'enabled'   => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'apk_url'   => $apk_url,
			'version'   => sanitize_text_field( (string) ( $_POST['version'] ?? WS_APP_VERSION ) ),
			'changelog' => sanitize_textarea_field( (string) ( $_POST['changelog'] ?? '' ) ),
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
			<?php esc_html_e( 'La aplicación móvil del panel (ShopUp Panel) se compila con Cordova. Configura aquí la descarga del APK y la versión disponible: el botón del pie, el asistente y la propia app usan estos datos para saber si hay una actualización.', 'workshop' ); ?>
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
							<input type="url" id="ws-app-apk-url" name="apk_url" class="regular-text" value="<?php echo esc_attr( $s['apk_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/app/shopup-panel.apk' ) ); ?>">
							<p class="description">
								<?php esc_html_e( 'Si lo dejas vacío se usa la ruta por defecto:', 'workshop' ); ?>
								<code><?php echo esc_html( home_url( '/app/shopup-panel.apk' ) ); ?></code>.
								<?php esc_html_e( 'Sube el archivo .apk a esa carpeta del servidor (o pega una URL externa).', 'workshop' ); ?>
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
						<th scope="row"><label for="ws-app-changelog"><?php esc_html_e( 'Novedades (changelog)', 'workshop' ); ?></label></th>
						<td>
							<textarea id="ws-app-changelog" name="changelog" class="large-text" rows="4" placeholder="<?php esc_attr_e( 'Ej.: Nuevas imágenes por producto, botón de actualizaciones, caché de imágenes…', 'workshop' ); ?>"><?php echo esc_textarea( $s['changelog'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Se muestra al usuario cuando hay una versión nueva disponible (en la app y en el asistente).', 'workshop' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<?php submit_button( __( 'Guardar app', 'workshop' ) ); ?>
		</form>

		<hr>
		<p>
			<strong><?php esc_html_e( 'Cómo se compila el APK:', 'workshop' ); ?></strong>
			<code>cd mobile-app && npm install && npm run build:android</code>
			<?php esc_html_e( 'El archivo queda en', 'workshop' ); ?>
			<code>platforms/android/app/build/outputs/apk/</code>
			<?php esc_html_e( 'y se copia a la carpeta /app/ del servidor.', 'workshop' ); ?>
		</p>
	</div>
	<style>
		.ws-mp-admin-group { background: #fff; border: 1px solid #c3c4c7; padding: 8px 20px 16px; margin: 0 0 18px; border-radius: 6px; }
		.ws-mp-admin-group h2 { font-size: 15px; padding-top: 12px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; }
	</style>
	<?php
}
