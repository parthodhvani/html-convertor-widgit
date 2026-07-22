<?php
/**
 * Main index template.
 *
 * @package AI_Starter
 */

get_header();
?>
<main id="main" class="site-main">
	<div class="container content-wrap">
		<?php if ( have_posts() ) : ?>
			<?php while ( have_posts() ) : ?>
				<?php the_post(); ?>
				<article <?php post_class( 'entry' ); ?>>
					<header class="entry-header">
						<h1 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h1>
					</header>
					<div class="entry-content">
						<?php the_excerpt(); ?>
					</div>
				</article>
			<?php endwhile; ?>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Keine Beiträge gefunden.', 'ai-starter' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
