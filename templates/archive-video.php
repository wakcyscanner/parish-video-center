<?php
/**
 * Video Center landing page. Page 1: split hero — player on the left, a rail
 * with a "Latest <singular>" badge, title, date/duration, short excerpt, and
 * an "Up Next" list on the right — then a "Browse all" thumbnail grid of the
 * remaining videos. Paged views show just the grid.
 *
 * Posts are partitioned from $wp_query->posts directly (hero, up-next, grid)
 * instead of consuming the loop, so the loop can't rewind and duplicate.
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

$svc_settings = svc_get_settings();
$svc_singular = '' !== trim( $svc_settings['singular'] ) ? $svc_settings['singular'] : 'Video';
$svc_plural   = '' !== trim( $svc_settings['plural'] ) ? $svc_settings['plural'] : 'Videos';

$svc_all_posts = $GLOBALS['wp_query']->posts;
$svc_hero      = null;
$svc_upnext    = array();
$svc_grid      = $svc_all_posts;

if ( ! is_paged() && $svc_all_posts ) {
	$svc_hero   = $svc_all_posts[0];
	$svc_upnext = array_slice( $svc_all_posts, 1, 3 );
	$svc_grid   = array_slice( $svc_all_posts, 4 );
}
?>
<div class="content-area" id="primary">
	<main class="site-main" id="main">
		<div class="svc-wrap">

			<?php if ( ! $svc_theme_banner ) : ?>
				<header class="svc-archive-header">
					<h1 class="svc-archive-title"><?php post_type_archive_title(); ?></h1>
				</header>
			<?php endif; ?>

			<?php if ( $svc_all_posts ) : ?>

				<?php if ( $svc_hero ) : ?>
					<section class="svc-hero">
						<div class="svc-hero-player">
							<?php svc_render_player( $svc_hero->ID ); ?>
						</div>
						<div class="svc-hero-rail">
							<p class="svc-eyebrow">
								<?php
								/* translators: %s: singular video label */
								echo esc_html( sprintf( __( 'Latest %s', 'parish-video-center' ), $svc_singular ) );
								?>
							</p>
							<h2 class="svc-hero-title"><a href="<?php echo esc_url( get_permalink( $svc_hero ) ); ?>"><?php echo esc_html( get_the_title( $svc_hero ) ); ?></a></h2>
							<div class="svc-meta">
								<?php
								echo esc_html( get_the_date( '', $svc_hero ) );
								$svc_hero_duration = svc_human_duration( get_post_meta( $svc_hero->ID, '_vimeo_duration', true ) );
								if ( $svc_hero_duration ) {
									echo ' &middot; ' . esc_html( $svc_hero_duration );
								}
								?>
							</div>
							<?php if ( '' !== trim( (string) $svc_hero->post_content ) ) : ?>
								<p class="svc-hero-excerpt"><?php echo esc_html( wp_trim_words( $svc_hero->post_content, 28 ) ); ?></p>
							<?php endif; ?>

							<?php if ( $svc_upnext ) : ?>
								<div class="svc-upnext">
									<h3 class="svc-upnext-heading"><?php esc_html_e( 'Up Next', 'parish-video-center' ); ?></h3>
									<?php foreach ( $svc_upnext as $svc_upnext_post ) : ?>
										<a class="svc-upnext-item" href="<?php echo esc_url( get_permalink( $svc_upnext_post ) ); ?>">
											<span class="svc-upnext-thumb">
												<?php if ( has_post_thumbnail( $svc_upnext_post ) ) : ?>
													<?php echo get_the_post_thumbnail( $svc_upnext_post, 'medium', array( 'loading' => 'lazy' ) ); ?>
												<?php endif; ?>
											</span>
											<span class="svc-upnext-info">
												<span class="svc-upnext-item-title"><?php echo esc_html( get_the_title( $svc_upnext_post ) ); ?></span>
												<?php $svc_upnext_duration = svc_human_duration( get_post_meta( $svc_upnext_post->ID, '_vimeo_duration', true ) ); ?>
												<?php if ( $svc_upnext_duration ) : ?>
													<span class="svc-upnext-duration"><?php echo esc_html( $svc_upnext_duration ); ?></span>
												<?php endif; ?>
											</span>
										</a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $svc_grid ) : ?>
					<?php if ( $svc_hero ) : ?>
						<h2 class="svc-section-title">
							<?php
							/* translators: %s: plural video label */
							echo esc_html( sprintf( __( 'Browse all %s', 'parish-video-center' ), $svc_plural ) );
							?>
						</h2>
					<?php endif; ?>
					<div class="svc-gallery">
						<?php foreach ( $svc_grid as $svc_grid_post ) : ?>
							<?php svc_render_tile( $svc_grid_post ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php
				the_posts_pagination(
					array(
						'prev_text' => __( '&larr; Previous', 'parish-video-center' ),
						'next_text' => __( 'Next &rarr;', 'parish-video-center' ),
					)
				);
				?>

			<?php else : ?>
				<p class="svc-empty"><?php esc_html_e( 'No videos available.', 'parish-video-center' ); ?></p>
			<?php endif; ?>

		</div>
	</main>
</div>
<?php
svc_get_footer();
