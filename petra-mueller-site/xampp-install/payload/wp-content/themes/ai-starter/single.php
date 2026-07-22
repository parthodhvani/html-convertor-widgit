<?php
/**
 * Single post template.
 *
 * @package AI_Starter
 */

get_header();
?>
<main id="main" class="site-main">
	<div class="container content-wrap narrow">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'entry single-entry' ); ?>>
				<header class="entry-header">
					<p class="eyebrow"><?php echo esc_html( get_the_date() ); ?></p>
					<h1 class="entry-title"><?php the_title(); ?></h1>
					<?php if ( has_post_thumbnail() ) : ?>
						<figure class="featured-media"><?php the_post_thumbnail( 'large' ); ?></figure>
					<?php endif; ?>
				</header>
				<div class="entry-content">
					<?php the_content(); ?>
				</div>
			</article>
			<?php
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
