<?php
/**
 * Solicitudes de soporte de la app móvil y gestión exclusiva del admin.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

function ws_support_requests_table() {
	global $wpdb;
	return $wpdb->prefix . 'ws_support_requests';
}

function ws_support_maybe_install() {
	if ( '1' === (string) get_option( 'ws_support_schema', '' ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;
	$table   = ws_support_requests_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		request_key CHAR(64) NOT NULL,
		business_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		message TEXT NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_request (user_id,request_key),
		KEY status_created (status,created_at),
		KEY business_id (business_id)
	) {$charset};" );
	update_option( 'ws_support_schema', '1' );
}
add_action( 'admin_init', 'ws_support_maybe_install', 30 );

add_action( 'wp_ajax_ws_support_submit', 'ws_ajax_support_submit' );
function ws_ajax_support_submit() {
	$user_id = ws_mobile_auth_user();
	if ( ! $user_id ) {
		wp_send_json_error( array( 'message' => __( 'Tu sesión expiró. Vuelve a iniciar sesión.', 'workshop' ) ), 401 );
	}
	if ( 'owner' !== ws_user_role( $user_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Solo el dueño del negocio puede enviar solicitudes de soporte.', 'workshop' ) ), 403 );
	}

	$message = sanitize_textarea_field( wp_unslash( (string) ( $_POST['message'] ?? '' ) ) );
	if ( '' === trim( $message ) || mb_strlen( $message ) > 4000 ) {
		wp_send_json_error( array( 'message' => __( 'Escribe un mensaje de hasta 4000 caracteres.', 'workshop' ) ) );
	}
	$key = preg_replace( '/[^a-f0-9]/i', '', (string) ( $_POST['request_id'] ?? '' ) );
	if ( 32 !== strlen( $key ) && 64 !== strlen( $key ) ) {
		wp_send_json_error( array( 'message' => __( 'No se pudo validar la solicitud. Inténtalo de nuevo.', 'workshop' ) ) );
	}

	ws_support_maybe_install();
	global $wpdb;
	$table = ws_support_requests_table();
	$found = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE user_id = %d AND request_key = %s",
		$user_id,
		$key
	) );
	if ( $found ) {
		wp_send_json_success( array( 'id' => (int) $found, 'message' => __( 'Solicitud recibida.', 'workshop' ) ) );
	}

	$now = current_time( 'mysql' );
	$ok  = $wpdb->insert( $table, array(
		'request_key' => $key,
		'business_id' => (int) get_user_meta( $user_id, 'ws_business_id', true ),
		'user_id'     => $user_id,
		'message'     => $message,
		'status'      => 'pending',
		'created_at'  => $now,
		'updated_at'  => $now,
	), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' ) );
	if ( false === $ok ) {
		// Una repetición concurrente con la misma clave es éxito idempotente.
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND request_key = %s",
			$user_id,
			$key
		) );
		if ( ! $found ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar la solicitud. Inténtalo de nuevo.', 'workshop' ) ) );
		}
	}
	$id = $found ? (int) $found : (int) $wpdb->insert_id;
	if ( function_exists( 'ws_log_audit' ) ) {
		ws_log_audit( 'support_request_create', 'support_request', $id, array( 'business_id' => (int) get_user_meta( $user_id, 'ws_business_id', true ) ) );
	}
	wp_send_json_success( array( 'id' => $id, 'message' => __( 'Solicitud recibida.', 'workshop' ) ) );
}

add_action( 'admin_menu', 'ws_support_admin_menu', 26 );
function ws_support_admin_menu() {
	add_submenu_page(
		'ws-permissions',
		__( 'Solicitudes de soporte', 'workshop' ),
		__( 'Soporte', 'workshop' ),
		'manage_options',
		'ws-support',
		'ws_admin_page_support'
	);
}

function ws_admin_page_support() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'workshop' ) );
	}
	ws_support_maybe_install();
	global $wpdb;
	$table  = ws_support_requests_table();
	$notice = '';

	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['ws_support_action'], $_POST['request_id'] ) ) {
		$id     = absint( $_POST['request_id'] );
		$action = sanitize_key( wp_unslash( (string) $_POST['ws_support_action'] ) );
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ws_support_' . $action . '_' . $id ) ) {
			wp_die( esc_html__( 'La sesión expiró. Recarga la página e inténtalo de nuevo.', 'workshop' ) );
		}
		if ( 'status' === $action ) {
			$status = sanitize_key( wp_unslash( (string) ( $_POST['status'] ?? '' ) ) );
			if ( in_array( $status, array( 'pending', 'in_progress', 'completed' ), true ) ) {
				$wpdb->update( $table, array(
					'status'     => $status,
					'updated_at' => current_time( 'mysql' ),
				), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
				$notice = __( 'Estado actualizado.', 'workshop' );
			}
		} elseif ( 'delete' === $action ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			$notice = __( 'Solicitud eliminada.', 'workshop' );
		}
	}

	$counts = array();
	foreach ( array( 'pending', 'in_progress', 'completed' ) as $status ) {
		$counts[ $status ] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			$status
		) );
	}
	$total = array_sum( $counts );
	$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100" );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Solicitudes de soporte', 'workshop' ); ?></h1>
		<p><?php esc_html_e( 'Las solicitudes enviadas por los dueños de negocio se guardan aquí. Solo el administrador del sitio puede gestionarlas.', 'workshop' ); ?></p>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<div class="ws-support-chart" role="img" aria-label="<?php esc_attr_e( 'Solicitudes por estado', 'workshop' ); ?>">
			<?php foreach ( array(
				'pending'    => __( 'Pendientes', 'workshop' ),
				'in_progress' => __( 'En proceso', 'workshop' ),
				'completed'  => __( 'Terminadas', 'workshop' ),
			) as $status => $label ) :
				$width = $total ? round( 100 * $counts[ $status ] / $total, 1 ) : 0;
				?>
				<div class="ws-support-stat">
					<div><strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( (string) $counts[ $status ] ); ?></span></div>
					<div class="ws-support-track"><span style="width:<?php echo esc_attr( (string) $width ); ?>%"></span></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'Todavía no hay solicitudes.', 'workshop' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Fecha', 'workshop' ); ?></th>
					<th><?php esc_html_e( 'Dueño', 'workshop' ); ?></th>
					<th><?php esc_html_e( 'Negocio', 'workshop' ); ?></th>
					<th><?php esc_html_e( 'Inquietud o solicitud', 'workshop' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'workshop' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'workshop' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$user = get_userdata( (int) $row->user_id );
					$biz  = function_exists( 'ws_business_get' ) ? ws_business_get( (int) $row->business_id ) : null;
					?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $row->created_at ) ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name . ' · ' . $user->user_email : '#' . (int) $row->user_id ); ?></td>
						<td><?php echo esc_html( $biz ? $biz->name : '#' . (int) $row->business_id ); ?></td>
						<td class="ws-support-message"><?php echo nl2br( esc_html( $row->message ) ); ?></td>
						<td>
							<form method="post">
								<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
								<input type="hidden" name="ws_support_action" value="status">
								<?php wp_nonce_field( 'ws_support_status_' . (int) $row->id ); ?>
								<select name="status" aria-label="<?php esc_attr_e( 'Estado de la solicitud', 'workshop' ); ?>">
									<?php foreach ( array( 'pending' => __( 'Pendiente', 'workshop' ), 'in_progress' => __( 'En proceso', 'workshop' ), 'completed' => __( 'Terminada', 'workshop' ) ) as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row->status, $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button class="button" type="submit"><?php esc_html_e( 'Guardar', 'workshop' ); ?></button>
							</form>
						</td>
						<td>
							<form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar esta solicitud?', 'workshop' ) ); ?>');">
								<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
								<input type="hidden" name="ws_support_action" value="delete">
								<?php wp_nonce_field( 'ws_support_delete_' . (int) $row->id ); ?>
								<button class="button button-link-delete" type="submit"><?php esc_html_e( 'Eliminar', 'workshop' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<style>
		.ws-support-chart { display:flex; gap:18px; margin:18px 0; max-width:900px; }
		.ws-support-stat { flex:1; background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:12px; }
		.ws-support-stat > div:first-child { display:flex; justify-content:space-between; margin-bottom:8px; }
		.ws-support-track { height:8px; background:#f0f0f1; border-radius:8px; overflow:hidden; }
		.ws-support-track span { display:block; height:100%; background:#2271b1; }
		.ws-support-message { min-width:240px; max-width:420px; white-space:normal; }
	</style>
	<?php
}
