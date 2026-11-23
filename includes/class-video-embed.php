<?php
/**
 * Single-video embeds: put one video into any post or page with the
 * [parish_video] shortcode or the "Parish Video" block.
 *
 *   [parish_video id="123"]                                   a video post, by post ID
 *   [parish_video id="0f6c1c2e-…" title="yes"]                a library video, by its ID
 *
 * A library video doesn't need a post of its own: videos in collections that
 * aren't linked to a topic can still be embedded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Video_Embed {

	const BLOCK = 'parish-video-center/video';

	public static function init() {
		add_shortcode( 'parish_video', array( __CLASS__, 'shortcode' ) );
		// After SVC_Embeds has registered the shared stylesheet.
		add_action( 'init', array( __CLASS__, 'register_block' ), 11 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_block() {
		if ( ! wp_style_is( 'svc-video-center', 'registered' ) ) {
			wp_register_style( 'svc-video-center', SVC_PLUGIN_URL . 'assets/video-center.css', array(), SVC_VERSION );
		}
		if ( ! wp_script_is( 'svc-player', 'registered' ) ) {
			wp_register_script( 'svc-player', SVC_PLUGIN_URL . 'assets/player.js', array(), SVC_VERSION, true );
		}
		wp_register_script(
			'svc-video-editor',
			SVC_PLUGIN_URL . 'blocks/video/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'wp-api-fetch' ),
			SVC_VERSION,
			true
		);

		register_block_type(
			SVC_PLUGIN_DIR . 'blocks/video',
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => '',
				'title' => '',
			),
			$atts,
			'parish_video'
		);

		return self::render(
			trim( (string) $atts['id'] ),
			in_array( strtolower( trim( (string) $atts['title'] ) ), array( '1', 'yes', 'true', 'on' ), true )
		);
	}

	public static function render_block( $attributes ) {
		$id = '';
		if ( ! empty( $attributes['videoId'] ) ) {
			$id = (string) $attributes['videoId'];
		} elseif ( ! empty( $attributes['postId'] ) ) {
			$id = (string) (int) $attributes['postId'];
		}

		$html = self::render( $id, ! empty( $attributes['showTitle'] ) );
		if ( '' === $html ) {
			return '';
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
	}

	/**
	 * Render one video as a click-to-play player.
	 *
	 * @param string $id         A video post ID, or a library video ID.
	 * @param bool   $show_title Whether to caption the player with the title.
	 * @return string HTML, or '' when the video can't be shown.
	 */
	public static function render( $id, $show_title = false ) {
		$video = self::resolve( $id );
		if ( ! $video ) {
			// Tell editors why nothing shows; visitors just see nothing.
			if ( '' !== $id && current_user_can( 'edit_posts' ) && ( is_admin() || wp_is_serving_rest_request() ) ) {
				return '<p class="svc-embed-missing">' . esc_html__( 'This video is not available. It may have been deleted, or it is still processing.', 'parish-video-center' ) . '</p>';
			}
			return '';
		}

		wp_enqueue_style( 'svc-video-center' );
		wp_enqueue_script( 'svc-player' );

		ob_start();
		?>
		<figure class="svc-embed-video">
			<div<?php echo SVC_Video::box_attributes( $video['dimensions'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in box_attributes(). ?> data-provider="<?php echo esc_attr( $video['provider'] ); ?>" data-embed-src="<?php echo esc_url( $video['embed'] ); ?>" data-title="<?php echo esc_attr( $video['title'] ); ?>">
				<?php if ( $video['poster'] ) : ?>
					<img class="svc-poster" src="<?php echo esc_url( $video['poster'] ); ?>" alt="<?php echo esc_attr( $video['title'] ); ?>" loading="lazy">
				<?php endif; ?>
				<button type="button" class="svc-play" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: video title */ __( 'Play %s', 'parish-video-center' ), $video['title'] ) ); ?>">
					<svg width="68" height="68" viewBox="0 0 68 68" fill="none" aria-hidden="true" focusable="false">
						<circle cx="34" cy="34" r="34" fill="rgba(0,0,0,0.7)"/>
						<path d="M45 34L28 44V24L45 34Z" fill="white"/>
					</svg>
				</button>
			</div>
			<?php if ( $show_title && '' !== $video['title'] ) : ?>
				<figcaption class="svc-embed-caption">
					<?php if ( $video['link'] ) : ?>
						<a href="<?php echo esc_url( $video['link'] ); ?>"><?php echo esc_html( $video['title'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $video['title'] ); ?>
					<?php endif; ?>
				</figcaption>
			<?php endif; ?>
		</figure>
		<?php
		return ob_get_clean();
	}

	/**
	 * Work out what to play for an ID.
	 *
	 * @return array|null array{provider, embed, title, poster, link, dimensions}, or null.
	 */
	private static function resolve( $id ) {
		$id = trim( (string) $id );

		if ( SVC_Bunny_API::is_guid( $id ) ) {
			$id = strtolower( $id );

			// A library video that has its own published post plays as that post.
			$post_id = self::post_for_library_video( $id );

			return $post_id ? self::from_post( $post_id ) : self::from_library( $id );
		}

		if ( ctype_digit( $id ) && (int) $id > 0 ) {
			return self::from_post( (int) $id );
		}

		return null;
	}

	private static function from_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || SVC_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$embed = SVC_Video::embed_url( $post_id, true );
		if ( ! $embed ) {
			return null;
		}

		return array(
			'provider'   => SVC_Video::provider( $post_id ),
			'embed'      => $embed,
			'title'      => get_the_title( $post_id ),
			'poster'     => (string) get_the_post_thumbnail_url( $post_id, 'large' ),
			'link'       => get_permalink( $post_id ),
			'dimensions' => SVC_Video::dimensions( $post_id ),
		);
	}

	private static function from_library( $guid ) {
		$video = self::library_video( $guid );
		if ( ! $video ) {
			return null;
		}

		$config = svc_bunny_config();

		return array(
			'provider'   => 'bunny',
			'embed'      => 'https://player.mediadelivery.net/embed/' . rawurlencode( $config['library_id'] ) . '/' . rawurlencode( $guid ) . '?autoplay=true',
			'title'      => $video['title'],
			'poster'     => $video['poster'],
			'link'       => '',
			'dimensions' => SVC_Video::clean_dimensions(
				isset( $video['width'] ) ? $video['width'] : 0,
				isset( $video['height'] ) ? $video['height'] : 0
			),
		);
	}

	/**
	 * Title and poster for a library video, cached so a page view doesn't
	 * call the library API. Missing or unfinished videos are cached briefly.
	 *
	 * @return array|null array{title, poster, width, height}, or null when it can't be played.
	 */
	private static function library_video( $guid ) {
		if ( ! svc_bunny_configured() ) {
			return null;
		}

		$key    = 'svc_lib_video_' . md5( $guid );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return ! empty( $cached['ok'] ) ? $cached : null;
		}

		$raw = SVC_Bunny_API::get_video( $guid );
		if ( is_wp_error( $raw ) || ! isset( $raw['status'] ) || SVC_Bunny_API::STATUS_FINISHED !== (int) $raw['status'] ) {
			set_transient( $key, array( 'ok' => false ), MINUTE_IN_SECONDS );
			return null;
		}

		$video = array(
			'ok'     => true,
			'title'  => isset( $raw['title'] ) ? (string) $raw['title'] : '',
			'poster' => self::library_poster( $raw ),
			'width'  => isset( $raw['width'] ) ? (int) $raw['width'] : 0,
			'height' => isset( $raw['height'] ) ? (int) $raw['height'] : 0,
		);
		set_transient( $key, $video, HOUR_IN_SECONDS );

		return $video;
	}

	/**
	 * Forget the cached copy of a library video (after it is edited or deleted).
	 */
	public static function forget( $guid ) {
		delete_transient( 'svc_lib_video_' . md5( strtolower( (string) $guid ) ) );
	}

	private static function library_poster( $raw ) {
		if ( ! empty( $raw['thumbnailUrl'] ) ) {
			return (string) $raw['thumbnailUrl'];
		}

		$config = svc_bunny_config();
		if ( $config['cdn_host'] && ! empty( $raw['guid'] ) && ! empty( $raw['thumbnailFileName'] ) ) {
			return 'https://' . $config['cdn_host'] . '/' . $raw['guid'] . '/' . $raw['thumbnailFileName'];
		}

		return '';
	}

	/**
	 * The published post that plays a given library video, if there is one.
	 *
	 * @return int Post ID, or 0.
	 */
	private static function post_for_library_video( $guid ) {
		$ids = get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_bunny_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $guid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids && 'bunny' === SVC_Video::provider( $ids[0] ) ? (int) $ids[0] : 0;
	}

	// ---------------------------------------------------------------------
	// Block editor picker
	// ---------------------------------------------------------------------

	public static function register_routes() {
		register_rest_route(
			'parish-video-center/v1',
			'/picker',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'picker' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'search' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
	}

	/**
	 * Videos an editor can pick from: published video posts, plus finished
	 * library videos that have no post. Newest first, optionally searched.
	 */
	public static function picker( $request ) {
		$search = trim( (string) $request['search'] );
		$items  = array();
		$seen   = array();

		$posts = get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				's'              => $search,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $posts as $post ) {
			if ( 'bunny' === SVC_Video::provider( $post->ID ) ) {
				$seen[ strtolower( SVC_Video::id( $post->ID ) ) ] = true;
			}
			$items[] = array(
				'postId'    => $post->ID,
				'videoId'   => '',
				'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'thumbnail' => (string) get_the_post_thumbnail_url( $post, 'medium' ),
				'duration'  => svc_human_duration( SVC_Video::duration( $post->ID ) ),
				'hasPost'   => true,
			);
		}

		if ( svc_bunny_configured() ) {
			$page = SVC_Bunny_API::list_page(
				array(
					'per_page' => 20,
					'search'   => $search,
				)
			);
			if ( ! is_wp_error( $page ) ) {
				foreach ( $page['items'] as $raw ) {
					$guid = isset( $raw['guid'] ) ? strtolower( (string) $raw['guid'] ) : '';
					if ( ! $guid || isset( $seen[ $guid ] ) || ! isset( $raw['status'] ) || SVC_Bunny_API::STATUS_FINISHED !== (int) $raw['status'] ) {
						continue;
					}
					// A post that didn't match this page of posts still wins.
					if ( self::post_for_library_video( $guid ) ) {
						continue;
					}
					$items[] = array(
						'postId'    => 0,
						'videoId'   => $guid,
						'title'     => isset( $raw['title'] ) ? (string) $raw['title'] : '',
						'thumbnail' => self::library_poster( $raw ),
						'duration'  => svc_human_duration( isset( $raw['length'] ) ? (int) $raw['length'] : 0 ),
						'hasPost'   => false,
					);
				}
			}
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}
}
