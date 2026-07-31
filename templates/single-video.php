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
					<?php
					/*
					 * Only the description's first line is served; the rest is
					 * fetched on demand via REST. Deliberate: the full text in
					 * the HTML makes Google classify the page as an article
					 * with a supplementary video instead of a watch page.
					 */
					list( $svc_desc_first, $svc_desc_rest ) = svc_description_split( get_post() );
					?>
					<div class="svc-description" id="svc-description-<?php the_ID(); ?>"><?php echo svc_format_description_text( $svc_desc_first ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in svc_format_description_text(). ?></div>
					<?php if ( '' !== $svc_desc_rest ) : ?>
						<p class="svc-read-more-wrap">
							<button type="button" class="svc-read-more"
								aria-expanded="false"
								aria-controls="svc-description-<?php the_ID(); ?>"
								data-endpoint="<?php echo esc_url( rest_url( 'parish-video-center/v1/description/' . get_the_ID() ) ); ?>">
								<?php esc_html_e( 'Read more', 'parish-video-center' ); ?>
							</button>
						</p>
					<?php endif; ?>
					<?php
					// Back link goes to the video's topic landing page; the
					// all-videos archive only when the video has no topic.
					$svc_back_url   = get_post_type_archive_link( SVC_Post_Type::POST_TYPE );
					$svc_back_label = $svc_settings['plural'];
					$svc_back_terms = get_the_terms( get_the_ID(), SVC_Topics::TAXONOMY );
					if ( $svc_back_terms && ! is_wp_error( $svc_back_terms ) ) {
						$svc_back_term_url = get_term_link( $svc_back_terms[0] );
						if ( ! is_wp_error( $svc_back_term_url ) ) {
							$svc_back_url   = $svc_back_term_url;
							$svc_back_label = $svc_back_terms[0]->name;
						}
					}
					?>
					<p class="svc-back">
						<a href="<?php echo esc_url( $svc_back_url ); ?>">&larr; <?php
							/* translators: %s: topic name or plural video label */
							echo esc_html( sprintf( __( 'All %s', 'parish-video-center' ), $svc_back_label ) );
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
