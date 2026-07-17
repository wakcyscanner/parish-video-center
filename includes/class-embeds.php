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

	const EMBED_QUERY_VAR = 'svc_video_embed';

	public static function init() {
		add_shortcode( 'parish_videos', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_assets_and_block' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		// Priority 0: render and exit before redirect_canonical can 301 the URL.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_embed' ), 0 );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::EMBED_QUERY_VAR;
		return $vars;
	}

	public static function register_assets_and_block() {
		add_rewrite_rule( '^video-embed/?$', 'index.php?' . self::EMBED_QUERY_VAR . '=1', 'top' );

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
	 * Standalone embed page at /video-embed/ — the collection with its own
	 * assets and no theme chrome, made to be iframed (or fetched and inlined)
	 * into pages the plugin can't reach: locked homepage templates, edge
	 * workers, other sites. Params: ?layout=grid|slider&count=N&title=...
	 */
	public static function maybe_render_embed() {
		if ( ! get_query_var( self::EMBED_QUERY_VAR ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only endpoint.
		$args = array(
			'count'  => isset( $_GET['count'] ) ? (int) $_GET['count'] : 8,
			'layout' => isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( $_GET['layout'] ) ) : 'slider',
			'title'  => isset( $_GET['title'] ) ? sanitize_text_field( wp_unslash( $_GET['title'] ) ) : '',
		);
		// phpcs:enable

		$html = self::render( $args );

		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		// The embed is a fragment of other pages; keep it out of search results.
		header( 'X-Robots-Tag: noindex' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<base target="_top">
	<link rel="stylesheet" href="<?php echo esc_url( SVC_PLUGIN_URL . 'assets/video-center.css?ver=' . SVC_VERSION ); ?>">
	<style>body { margin: 0; background: transparent; } .svc-collection { margin: 0; }</style>
</head>
<body class="svc-embed">
<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in render(). ?>
<script src="<?php echo esc_url( SVC_PLUGIN_URL . 'assets/slider.js?ver=' . SVC_VERSION ); ?>"></script>
<script>
(function () {
	function send() {
		window.parent.postMessage(
			{ type: 'svc-embed-height', height: document.documentElement.scrollHeight },
			'*'
		);
	}
	window.addEventListener('load', send);
	window.addEventListener('resize', send);
})();
</script>
</body>
</html>
		<?php
		exit;
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
