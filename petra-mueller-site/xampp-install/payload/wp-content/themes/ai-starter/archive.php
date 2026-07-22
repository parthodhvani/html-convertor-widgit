<?php
/**
 * Archive template.
 *
 * @package AI_Starter
 */

get_header();
?>
<main id="main" class="site-main">
	<div class="container content-wrap">
		<header class="archive-header">
			<?php the_archive_title( '<h1 class="archive-title">', '</h1>' ); ?>
			<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
		</header>
		<div class="posts-grid">
			<?php if ( have_posts() ) : ?>
				<?php
				while ( have_posts() ) :
					the_post();
					?>
					<article <?php post_class( 'card post-card' ); ?>>
						<?php if ( has_post_thumbnail() ) : ?>
							<a href="<?php the_permalink(); ?>" class="post-card-media"><?php the_post_thumbnail( 'medium_large' ); ?></a>
						<?php endif; ?>
						<div class="post-card-body">
							<p class="eyebrow"><?php echo esc_html( get_the_date() ); ?></p>
							<h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
							<?php the_excerpt(); ?>
							<a class="card-link" href="<?php the_permalink(); ?>"><?php esc_html_e( 'Weiterlesen', 'ai-starter' ); ?></a>
						</div>
					</article>
				<?php endwhile; ?>
			<?php endif; ?>
		</div>
		<?php the_posts_pagination(); ?>
	</div>
</main>
<?php
get_footer();
