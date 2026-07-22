<?php
/**
 * 404 template.
 *
 * @package AI_Starter
 */

get_header();
?>
<main id="main" class="site-main">
	<div class="container content-wrap narrow error-404">
		<p class="eyebrow"><?php esc_html_e( '404', 'ai-starter' ); ?></p>
		<h1><?php esc_html_e( 'Seite nicht gefunden', 'ai-starter' ); ?></h1>
		<p><?php esc_html_e( 'Die gesuchte Seite existiert nicht oder wurde verschoben. Nutzen Sie die Navigation oder kehren Sie zur Startseite zurück.', 'ai-starter' ); ?></p>
		<p><a class="btn btn-gold" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Zur Startseite', 'ai-starter' ); ?></a></p>
		<?php get_search_form(); ?>
	</div>
</main>
<?php
get_footer();
