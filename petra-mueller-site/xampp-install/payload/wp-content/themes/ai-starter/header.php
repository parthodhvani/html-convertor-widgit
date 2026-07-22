<?php
/**
 * Theme header.
 *
 * @package AI_Starter
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( get_template_directory_uri() . '/assets/icons/favicon.svg' ); ?>">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'Zum Inhalt springen', 'ai-starter' ); ?></a>
<header class="site-header" role="banner">
	<div class="container header-inner">
		<div class="site-branding">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<a class="site-logo-text" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/petra_muller_logo.svg' ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="180" height="48" class="logo-img" />
				</a>
			<?php endif; ?>
		</div>
		<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="primary-navigation" aria-label="<?php esc_attr_e( 'Menü öffnen', 'ai-starter' ); ?>">
			<span></span><span></span><span></span>
		</button>
		<nav id="primary-navigation" class="primary-navigation" aria-label="<?php esc_attr_e( 'Hauptnavigation', 'ai-starter' ); ?>">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'container'      => false,
					'menu_class'     => 'nav-list',
					'fallback_cb'    => false,
				)
			);
			?>
			<a class="btn btn-gold header-cta" href="<?php echo esc_url( home_url( '/buchen/' ) ); ?>"><?php esc_html_e( 'Termin buchen', 'ai-starter' ); ?></a>
		</nav>
	</div>
</header>
