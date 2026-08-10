<?php
/**
 * Cabecera del tema.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

$ws_role   = get_query_var( 'ws_role', '' );
$ws_public = get_query_var( 'ws_public', '' );
$is_panel  = ! empty( $ws_role );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'ws-body' ); ?>>
<?php wp_body_open(); ?>

<!-- Loader global: círculo pulsante para cargas pesadas (se muestra con .is-active) -->
<div id="ws-loader" class="ws-loader" aria-hidden="true"><span class="ws-loader-ring"></span></div>

<?php
// En las pantallas de acceso y registro el topbar se oculta: el formulario
// ocupa todo el protagonismo (patrón de login/registro de SaaS).
$ws_hide_topbar = in_array( $ws_public, array( 'login', 'register' ), true );
?>
<?php if ( ! $is_panel && ! $ws_hide_topbar ) : ?>
<header class="ws-topbar">
    <div class="ws-container ws-topbar-inner">
        <a class="ws-brand" href="<?php echo esc_url( ws_business_home() ); ?>">
            <?php $ws_logo = ws_site_logo(); ?>
            <img class="ws-brand-img" src="<?php echo ws_site_logo_src(); ?>" alt="<?php echo esc_attr( ws_site_name() ); ?>" style="<?php echo $ws_logo ? '' : 'display:none'; ?>">
            <i class="fa-solid fa-store ws-brand-icon" style="<?php echo $ws_logo ? 'display:none' : ''; ?>"></i>
            <span class="ws-brand-name"><?php echo esc_html( ws_site_name() ); ?></span>
        </a>
        <nav class="ws-topbar-nav" aria-label="<?php esc_attr_e( 'Enlaces útiles', 'workshop' ); ?>">
            <a href="<?php echo esc_url( home_url( '/marketplace/' ) ); ?>"><?php esc_html_e( 'Tiendas', 'workshop' ); ?></a>
            <a href="<?php echo esc_url( home_url( '/ayuda/' ) ); ?>"><?php esc_html_e( 'Ayuda', 'workshop' ); ?></a>
            <a href="<?php echo esc_url( home_url( '/contacto/' ) ); ?>"><?php esc_html_e( 'Contacto', 'workshop' ); ?></a>
            <a href="<?php echo esc_url( home_url( '/acerca/' ) ); ?>"><?php esc_html_e( 'Acerca de nosotros', 'workshop' ); ?></a>
        </nav>
        <?php if ( 'stores' === (string) get_query_var( 'ws_public' ) ) : ?>
            <div class="ws-topbar-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input id="ws-mp-header-search" class="ws-mp-search" type="search" placeholder="<?php esc_attr_e( 'Busca un negocio...', 'workshop' ); ?>" autocomplete="off">
            </div>
        <?php endif; ?>
        <div class="ws-topbar-links">
            <?php if ( is_user_logged_in() ) : ?>
                <?php get_template_part( 'partials/notifications-menu' ); ?>
                <?php get_template_part( 'partials/user-menu' ); ?>
            <?php else : ?>
                <a class="ws-btn ws-btn-primary ws-btn-sm" href="<?php echo esc_url( home_url( '/login/' ) ); ?>"><i class="fa-solid fa-right-to-bracket"></i> <?php esc_html_e( 'Entrar', 'workshop' ); ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php endif; ?>
