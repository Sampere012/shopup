<?php
/**
 * Acciones de cuenta autenticadas para la aplicación móvil.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_ws_mobile_update_profile', 'ws_ajax_mobile_update_profile' );
function ws_ajax_mobile_update_profile() {
	$user_id = ws_mobile_auth_user();
	if ( ! $user_id ) {
		wp_send_json_error( array( 'message' => __( 'Tu sesión expiró. Vuelve a iniciar sesión.', 'workshop' ) ), 401 );
	}
	if ( 'owner' !== ws_user_role( $user_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Solo el dueño del negocio puede editar su perfil desde la app.', 'workshop' ) ), 403 );
	}

	$user     = get_userdata( $user_id );
	$name     = sanitize_text_field( wp_unslash( (string) ( $_POST['display_name'] ?? '' ) ) );
	$username = sanitize_user( wp_unslash( (string) ( $_POST['username'] ?? '' ) ), true );
	$email    = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
	$current  = (string) ( $_POST['current_password'] ?? '' );
	$new_pass = (string) ( $_POST['new_password'] ?? '' );

	if ( ! $user || '' === $name || '' === $username || ! is_email( $email ) || '' === $current ) {
		wp_send_json_error( array( 'message' => __( 'Completa el nombre, usuario, correo y contraseña actual.', 'workshop' ) ) );
	}
	$email_allowed = function_exists( 'ws_email_allowed' ) ? ws_email_allowed( $email ) : true;
	if ( true !== $email_allowed ) {
		wp_send_json_error( array( 'message' => (string) $email_allowed ) );
	}
	if ( ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
		wp_send_json_error( array( 'message' => __( 'La contraseña actual no es correcta.', 'workshop' ) ) );
	}
	if ( '' !== $new_pass && strlen( $new_pass ) < 8 ) {
		wp_send_json_error( array( 'message' => __( 'La nueva contraseña debe tener al menos 8 caracteres.', 'workshop' ) ) );
	}
	if ( '' !== $new_pass && wp_check_password( $new_pass, $user->user_pass, $user_id ) ) {
		wp_send_json_error( array( 'message' => __( 'La nueva contraseña debe ser distinta de la actual.', 'workshop' ) ) );
	}
	if ( strtolower( $username ) !== strtolower( $user->user_login ) && username_exists( $username ) ) {
		wp_send_json_error( array( 'message' => __( 'Ese usuario de acceso ya está en uso.', 'workshop' ) ) );
	}
	$email_owner = email_exists( $email );
	if ( $email_owner && (int) $email_owner !== $user_id ) {
		wp_send_json_error( array( 'message' => __( 'Ese correo ya está asociado a otra cuenta.', 'workshop' ) ) );
	}

	global $wpdb;
	$old_username = $user->user_login;
	if ( $username !== $old_username ) {
		$updated = $wpdb->update(
			$wpdb->users,
			array( 'user_login' => $username ),
			array( 'ID' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $updated || 1 !== $updated ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo actualizar el usuario de acceso.', 'workshop' ) ) );
		}
		clean_user_cache( $user_id );
	}

	$result = wp_update_user( array(
		'ID'           => $user_id,
		'display_name' => $name,
		'user_email'   => $email,
	) );
	if ( is_wp_error( $result ) ) {
		if ( $username !== $old_username ) {
			$wpdb->update( $wpdb->users, array( 'user_login' => $old_username ), array( 'ID' => $user_id ), array( '%s' ), array( '%d' ) );
			clean_user_cache( $user_id );
		}
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	if ( strtolower( $email ) !== strtolower( $user->user_email ) ) {
		delete_user_meta( $user_id, 'ws_email_verified_at' );
	}
	if ( '' !== $new_pass ) {
		wp_set_password( $new_pass, $user_id );
	}

	unset( $GLOBALS['current_user'] );
	wp_set_current_user( $user_id );
	$session = ws_mobile_token_create( $user_id );
	if ( function_exists( 'ws_log_audit' ) ) {
		ws_log_audit( 'mobile_profile_update', 'user', $user_id, array(
			'username_changed' => $username !== $old_username,
			'email_changed'    => strtolower( $email ) !== strtolower( $user->user_email ),
			'password_changed' => '' !== $new_pass,
		) );
	}
	wp_send_json_success( array(
		'token'       => $session['token'],
		'expiresAt'   => $session['expiresAt'],
		'sessionDays' => $session['sessionDays'],
		'me'          => ws_mobile_me_payload(),
		'message'     => __( 'Perfil actualizado correctamente.', 'workshop' ),
	) );
}

add_action( 'wp_ajax_ws_mobile_change_password', 'ws_ajax_mobile_change_password' );
function ws_ajax_mobile_change_password() {
    $user_id = ws_mobile_auth_user();
    if ( ! $user_id ) {
        wp_send_json_error( array( 'message' => __( 'Tu sesión expiró. Vuelve a iniciar sesión.', 'workshop' ) ), 401 );
    }
    if ( 'owner' !== ws_user_role( $user_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Solo el dueño del negocio puede cambiar la contraseña desde la app.', 'workshop' ) ), 403 );
    }

    $current = (string) ( $_POST['current_password'] ?? '' );
    $new     = (string) ( $_POST['new_password'] ?? '' );
    if ( '' === $current || strlen( $new ) < 8 ) {
        wp_send_json_error( array( 'message' => __( 'Introduce tu contraseña actual y una nueva contraseña de al menos 8 caracteres.', 'workshop' ) ) );
    }

    $user = get_userdata( $user_id );
    if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
        wp_send_json_error( array( 'message' => __( 'La contraseña actual no es correcta.', 'workshop' ) ) );
    }
    if ( wp_check_password( $new, $user->user_pass, $user_id ) ) {
        wp_send_json_error( array( 'message' => __( 'La nueva contraseña debe ser distinta de la actual.', 'workshop' ) ) );
    }

    wp_set_password( $new, $user_id );
    $session = ws_mobile_token_create( $user_id );
    ws_log_audit( 'mobile_password_change', 'user', $user_id );

    wp_send_json_success( array(
        'token'     => $session['token'],
        'expiresAt' => $session['expiresAt'],
        'message'   => __( 'La contraseña se actualizó correctamente.', 'workshop' ),
    ) );
}
