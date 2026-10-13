<?php
/**
 * Panel: matriz de permisos por rol.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$matrix = WS_Capabilities::matrix();
$caps   = WS_Capabilities::all_caps();
$roles  = array(
    'owner'       => __( 'Dueño', 'workshop' ),
    'storekeeper' => __( 'Almacenero', 'workshop' ),
    'seller'      => __( 'Vendedor', 'workshop' ),
);
$can_edit_owner = current_user_can( 'manage_options' );
// Capacidades marcadas como solo lectura en wp-admin: nadie las cambia aquí
// (ni el dueño, en ninguna fila); solo el administrador del sitio.
$readonly   = class_exists( 'WS_Capabilities' ) ? WS_Capabilities::readonly_caps() : array();
$ro_labels  = array();
foreach ( $readonly as $ro_cap ) {
    if ( isset( $caps[ $ro_cap ] ) ) {
        $ro_labels[] = $caps[ $ro_cap ];
    }
}
?>
<div x-data="wsPermissions(<?php echo esc_attr( wp_json_encode( array( 'matrix' => $matrix ) ) ); ?>)">

    <div class="ws-card">
        <div class="ws-toolbar" style="border:none;padding:0 0 12px">
            <h3 class="ws-card-title" style="margin:0"><i class="fa-solid fa-shield-halved"></i> <?php esc_html_e( 'Matriz de permisos', 'workshop' ); ?></h3>
            <button class="ws-btn ws-btn-primary" @click="save()"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar permisos', 'workshop' ); ?></button>
        </div>
        <?php if ( ! $can_edit_owner ) : ?>
            <p style="margin:0 0 12px;font-size:12px;color:#888">
                <i class="fa-solid fa-lock"></i> <?php esc_html_e( 'Los permisos del dueño los gestiona el administrador del sitio.', 'workshop' ); ?>
            </p>
        <?php endif; ?>
        <?php if ( $ro_labels ) : ?>
            <p style="margin:0 0 12px;font-size:12px;color:#888">
                <i class="fa-solid fa-lock"></i> <?php esc_html_e( 'Solo lectura (los define el administrador del sitio):', 'workshop' ); ?>
                <?php echo esc_html( implode( ' · ', $ro_labels ) ); ?>
            </p>
        <?php endif; ?>
        <table class="ws-table ws-perm-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Módulo / Permiso', 'workshop' ); ?></th>
                    <?php foreach ( $roles as $key => $label ) : ?>
                        <th class="ws-center"><?php echo esc_html( $label ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $caps as $cap => $label ) : ?>
                    <tr>
                        <td><?php echo esc_html( $label ); ?></td>
                        <?php foreach ( array_keys( $roles ) as $rkey ) : ?>
                            <td class="ws-center">
                                <label class="ws-check ws-check-switch">
                                    <?php
                                    $ro_locked = in_array( $cap, $readonly, true );
                                    $disabled  = $ro_locked || ( 'owner' === $rkey && ! $can_edit_owner );
                                    ?>
                                    <input type="checkbox" x-model="matrix['<?php echo esc_attr( $rkey ); ?>']['<?php echo esc_attr( $cap ); ?>']"<?php echo $disabled ? ' disabled' : ''; ?>>
                                    <span></span>
                                </label>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="ws-modal-foot" style="padding-top:16px">
            <button class="ws-btn ws-btn-primary" @click="save()"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Guardar permisos', 'workshop' ); ?></button>
        </div>
    </div>
</div>
