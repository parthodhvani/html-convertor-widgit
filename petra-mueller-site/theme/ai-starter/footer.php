<?php
/**
 * Theme footer.
 *
 * @package AI_Starter
 */
?>
<footer class="site-footer" role="contentinfo">
	<div class="container footer-grid">
		<div class="footer-brand">
			<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/petra_muller_logo-light.svg' ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="160" height="44" class="footer-logo" />
			<p><?php esc_html_e( 'Astrologische Beratung und Persönlichkeitsentwicklung in Zug – Klarheit statt Vorhersagen.', 'ai-starter' ); ?></p>
		</div>
		<div class="footer-nav">
			<h2 class="footer-heading"><?php esc_html_e( 'Navigation', 'ai-starter' ); ?></h2>
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'footer',
					'container'      => false,
					'menu_class'     => 'footer-list',
					'fallback_cb'    => false,
					'depth'          => 1,
				)
			);
			?>
		</div>
		<div class="footer-contact">
			<h2 class="footer-heading"><?php esc_html_e( 'Kontakt', 'ai-starter' ); ?></h2>
			<ul class="footer-list">
				<li><?php esc_html_e( 'Praxis Zug, Schweiz', 'ai-starter' ); ?></li>
				<li><a href="mailto:kontakt@example.com">kontakt@example.com</a></li>
				<li><a href="tel:+41410000000">+41 41 000 00 00</a></li>
			</ul>
		</div>
		<div class="footer-legal">
			<h2 class="footer-heading"><?php esc_html_e( 'Rechtliches', 'ai-starter' ); ?></h2>
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'legal',
					'container'      => false,
					'menu_class'     => 'footer-list',
					'fallback_cb'    => false,
					'depth'          => 1,
				)
			);
			?>
		</div>
	</div>
	<div class="footer-bottom">
		<div class="container">
			<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'Alle Rechte vorbehalten.', 'ai-starter' ); ?></p>
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
