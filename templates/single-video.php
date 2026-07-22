<?php
/**
 * Single video: player with title, date, and description below.
 *
 * Mirrors the wrapper structure of the Celine theme's single.php (page-header
 * banner, .content-area > main.site-main.entry-content.limit-width) so the
 * theme's layout and typography apply. When the theme banner renders the post
 * title, the in-body <h1> is skipped to avoid duplication.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

svc_get_header();

$svc_theme_banner = svc_theme_page_header();
$svc_settings     = svc_get_settings();

while ( have_posts() ) :
	the_post();
	?>
	<div class="content-area" id="primary">
		<main class="site-main entry-content limit-width" id="main">
			<article <?php post_class( 'svc-wrap svc-single' ); ?>>
				<?php
				/**
				 * Player mode on single video pages. 'embed' renders the real
				 * Vimeo iframe so search engines classify these as watch
				 * pages; return 'facade' to restore click-to-play.
				 */
				svc_render_player( get_the_ID(), apply_filters( 'svc_single_player_mode', 'embed' ) );
				?>
				<div class="svc-info">
					<?php if ( ! $svc_theme_banner ) : ?>
						<h1 class="svc-title"><?php the_title(); ?></h1>
					<?php endif; ?>
					<div class="svc-meta"><?php echo esc_html( get_the_date() ); ?></div>
					<div class="svc-description"><?php echo svc_render_description( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in svc_render_description(). ?></div>
					<p class="svc-back">
						<a href="<?php echo esc_url( get_post_type_archive_link( SVC_Post_Type::POST_TYPE ) ); ?>">&larr; <?php
							/* translators: %s: plural video label */
							echo esc_html( sprintf( __( 'All %s', 'parish-video-center' ), $svc_settings['plural'] ) );
						?></a>
					</p>
				</div>
				<?php svc_render_related( get_the_ID() ); ?>
			</article>
		</main>
	</div>
	<?php
endwhile;

svc_get_footer();
