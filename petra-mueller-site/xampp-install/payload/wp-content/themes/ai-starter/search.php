<?php
/**
 * Search results template.
 *
 * @package AI_Starter
 */
get_header();
?>
<main id="main" class="site-main">
	<div class="container content-wrap">
		<header class="archive-header">
			<h1 class="archive-title"><?php printf( esc_html__( 'Suchergebnisse für: %s', 'ai-starter' ), esc_html( get_search_query() ) ); ?></h1>
		</header>
		<?php if ( have_posts() ) : ?>
			<?php while ( have_posts() ) : the_post(); ?>
				<article <?php post_class( 'entry' ); ?>>
					<h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php the_excerpt(); ?>
				</article>
			<?php endwhile; ?>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Keine Treffer. Bitte anderen Suchbegriff versuchen.', 'ai-starter' ); ?></p>
			<?php get_search_form(); ?>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
