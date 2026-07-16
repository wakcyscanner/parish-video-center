<?php
/**
 * Gallery archive: most recent video as hero (page 1 only), then a thumbnail grid.
 *
 * Mirrors the wrapper structure of the Celine theme's archive.php (page-header
 * banner, .content-area > main.site-main) so the theme's layout and typography
 * apply; the wrappers are generic enough to be harmless on other themes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

svc_get_header();

$svc_theme_banner = svc_theme_page_header();
?>
<div class="content-area" id="primary">
	<main class="site-main" id="main">
		<div class="svc-wrap">

			<?php if ( ! $svc_theme_banner ) : ?>
				<header class="svc-archive-header">
					<h1 class="svc-archive-title"><?php post_type_archive_title(); ?></h1>
				</header>
			<?php endif; ?>

			<?php if ( have_posts() ) : ?>

				<?php if ( ! is_paged() ) : the_post(); ?>
					<section class="svc-hero">
						<?php svc_render_player( get_the_ID() ); ?>
						<div class="svc-info">
							<h2 class="svc-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
							<div class="svc-meta"><?php echo esc_html( get_the_date() ); ?></div>
							<div class="svc-description"><?php echo svc_render_description( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in svc_render_description(). ?></div>
						</div>
					</section>
				<?php endif; ?>

				<div class="svc-gallery">
					<?php while ( have_posts() ) : the_post(); ?>
						<a href="<?php the_permalink(); ?>" class="svc-thumbnail">
							<?php if ( has_post_thumbnail() ) : ?>
								<?php the_post_thumbnail( 'medium_large', array( 'class' => 'svc-thumb-img', 'loading' => 'lazy' ) ); ?>
							<?php endif; ?>
							<div class="svc-thumb-title"><?php the_title(); ?></div>
						</a>
					<?php endwhile; ?>
				</div>

				<?php
				the_posts_pagination(
					array(
						'prev_text' => __( '&larr; Previous', 'stpacc-video-center' ),
						'next_text' => __( 'Next &rarr;', 'stpacc-video-center' ),
					)
				);
				?>

			<?php else : ?>
				<p class="svc-empty"><?php esc_html_e( 'No videos available.', 'stpacc-video-center' ); ?></p>
			<?php endif; ?>

		</div>
	</main>
</div>
<?php
svc_get_footer();
