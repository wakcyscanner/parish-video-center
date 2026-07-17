<?php
/**
 * Embeds: drop recent videos onto any page as a grid or slider, via the
 * [parish_videos] shortcode or the "Parish Videos" block.
 *
 *   [parish_videos count="6" layout="slider" title="Recent Homilies"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Embeds {

	public static function init() {
		add_shortcode( 'parish_videos', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_assets_and_block' ) );
	}

	public static function register_assets_and_block() {
		if ( ! wp_style_is( 'svc-video-center', 'registered' ) ) {
			wp_register_style( 'svc-video-center', SVC_PLUGIN_URL . 'assets/video-center.css', array(), SVC_VERSION );
		}
		wp_register_script( 'svc-slider', SVC_PLUGIN_URL . 'assets/slider.js', array(), SVC_VERSION, true );
		wp_register_script(
			'svc-videos-editor',
			SVC_PLUGIN_URL . 'blocks/videos/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			SVC_VERSION,
			true
		);

		register_block_type(
			SVC_PLUGIN_DIR . 'blocks/videos',
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'count'  => 6,
				'layout' => 'grid',
				'title'  => '',
			),
			$atts,
			'parish_videos'
		);

		return self::render( $atts );
	}

	public static function render_block( $attributes ) {
		return self::render(
			wp_parse_args(
				$attributes,
				array(
					'count'  => 6,
					'layout' => 'grid',
					'title'  => '',
				)
			)
		);
	}

	/**
	 * Render a collection of recent videos.
	 *
	 * @param array $args count, layout (grid|slider), title.
	 * @return string HTML, or '' when no videos exist.
	 */
	private static function render( $args ) {
		$count  = min( 24, max( 1, (int) $args['count'] ) );
		$layout = 'slider' === $args['layout'] ? 'slider' : 'grid';
		$title  = sanitize_text_field( (string) $args['title'] );

		$posts = get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $count,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! $posts ) {
			return '';
		}

		wp_enqueue_style( 'svc-video-center' );
		if ( 'slider' === $layout ) {
			wp_enqueue_script( 'svc-slider' );
		}

		ob_start();
		?>
		<div class="svc-collection svc-collection-<?php echo esc_attr( $layout ); ?>">
			<?php if ( '' !== $title ) : ?>
				<h2 class="svc-section-title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>

			<?php if ( 'slider' === $layout ) : ?>
				<div class="svc-slider">
					<button type="button" class="svc-slider-arrow svc-slider-prev" aria-label="<?php esc_attr_e( 'Scroll to previous videos', 'parish-video-center' ); ?>" hidden>&#8249;</button>
					<div class="svc-slider-track">
						<?php foreach ( $posts as $collection_post ) : ?>
							<?php svc_render_tile( $collection_post ); ?>
						<?php endforeach; ?>
					</div>
					<button type="button" class="svc-slider-arrow svc-slider-next" aria-label="<?php esc_attr_e( 'Scroll to more videos', 'parish-video-center' ); ?>" hidden>&#8250;</button>
				</div>
			<?php else : ?>
				<div class="svc-gallery">
					<?php foreach ( $posts as $collection_post ) : ?>
						<?php svc_render_tile( $collection_post ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
