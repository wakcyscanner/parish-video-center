<?php
/**
 * "Copy for email": a ready-made picture and link for putting a video into an
 * email newsletter.
 *
 * Email can't play video, so a newsletter shows a picture that links to the
 * watch page. Newsletter tools that build that picture themselves assume
 * every video is 16:9 and crop tall ones. The picture made here is drawn for
 * the video's real shape: a wide poster as it is, a tall poster whole on a
 * blurred backdrop, both with a play button drawn on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Email_Share {

	const ACTION = 'svc_email_share';

	/** Folder under uploads/ holding the generated pictures. */
	const FOLDER = 'parish-video-center/email';

	/** Width newsletters show the picture at; it is drawn at twice this for sharp screens. */
	const DISPLAY_WIDTH = 600;

	/** Query argument that makes the watch page start playing on arrival (see player.js). */
	const PLAY_ARG = 'play';

	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_ajax' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'add_meta_boxes_' . SVC_Post_Type::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget' ) );
	}

	// ---------------------------------------------------------------------
	// Admin entry points
	// ---------------------------------------------------------------------

	/**
	 * Load the dialog on the video list, the video edit screen and the
	 * library screen.
	 */
	public static function enqueue( $hook ) {
		$screen   = get_current_screen();
		$on_posts = $screen && SVC_Post_Type::POST_TYPE === $screen->post_type && in_array( $screen->base, array( 'edit', 'post' ), true );
		$on_pages = $screen && false !== strpos( (string) $screen->id, SVC_Bunny_Library::PAGE );

		if ( ! $on_posts && ! $on_pages ) {
			return;
		}

		wp_enqueue_style( 'svc-email-share', SVC_PLUGIN_URL . 'assets/email-share.css', array(), SVC_VERSION );
		wp_enqueue_script( 'svc-email-share', SVC_PLUGIN_URL . 'assets/email-share.js', array(), SVC_VERSION, true );
		wp_localize_script(
			'svc-email-share',
			'svcEmailShare',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( self::ACTION ),
				'i18n'    => array(
					'title'       => __( 'Copy for email', 'parish-video-center' ),
					'working'     => __( 'Preparing the picture…', 'parish-video-center' ),
					'intro'       => __( 'Email can’t play video, so the newsletter shows this picture, and tapping it opens the video on the website.', 'parish-video-center' ),
					'copyBoth'    => __( 'Copy picture and link', 'parish-video-center' ),
					'download'    => __( 'Download picture', 'parish-video-center' ),
					'copyLink'    => __( 'Copy link only', 'parish-video-center' ),
					'copied'      => __( 'Copied', 'parish-video-center' ),
					'autoplay'    => __( 'Start the video as soon as the page opens (it starts muted — phones don’t allow sound until the viewer taps)', 'parish-video-center' ),
					'howTo'       => __( 'Paste into your newsletter. If the picture doesn’t come across, download it, add it as an image, and link that image to the copied address.', 'parish-video-center' ),
					'linkLabel'   => __( 'Link', 'parish-video-center' ),
					'close'       => __( 'Close', 'parish-video-center' ),
					'failed'      => __( 'Something went wrong. Please try again.', 'parish-video-center' ),
					'copyFailed'  => __( 'Your browser wouldn’t copy the picture. Use “Download picture” and “Copy link only” instead.', 'parish-video-center' ),
				),
			)
		);
	}

	public static function row_action( $actions, $post ) {
		if ( SVC_Post_Type::POST_TYPE === $post->post_type && 'publish' === $post->post_status && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['svc_email'] = self::button( $post->ID, 'button-link' );
		}

		return $actions;
	}

	public static function add_meta_box( $post ) {
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		add_meta_box(
			'svc-email-share',
			__( 'Email newsletter', 'parish-video-center' ),
			function ( $post ) {
				echo '<p>' . esc_html__( 'Get a picture and link for putting this video into an email.', 'parish-video-center' ) . '</p>';
				echo self::button( $post->ID, 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
			},
			SVC_Post_Type::POST_TYPE,
			'side'
		);
	}

	private static function button( $post_id, $class ) {
		return sprintf(
			'<button type="button" class="%s svc-email-share" data-post="%d">%s</button>',
			esc_attr( $class ),
			(int) $post_id,
			esc_html__( 'Copy for email', 'parish-video-center' )
		);
	}

	public static function handle_ajax() {
		check_ajax_referer( self::ACTION, 'nonce' );

		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$post    = get_post( $post_id );

		if ( ! $post || SVC_Post_Type::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Video not found.', 'parish-video-center' ) ), 404 );
		}
		if ( 'publish' !== $post->post_status ) {
			wp_send_json_error( array( 'message' => __( 'Publish this video first: the email has to link to a page everyone can open.', 'parish-video-center' ) ), 400 );
		}

		$image = self::image( $post_id );
		if ( is_wp_error( $image ) ) {
			wp_send_json_error( array( 'message' => $image->get_error_message() ), 500 );
		}

		$link = get_permalink( $post_id );

		wp_send_json_success(
			array(
				'title'    => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
				'link'     => $link,
				'playLink' => add_query_arg( self::PLAY_ARG, '1', $link ),
				'image'    => $image['url'],
				'width'    => $image['width'],
				'height'   => $image['height'],
				'filename' => sanitize_title( get_the_title( $post_id ) ) . '.jpg',
			)
		);
	}

	// ---------------------------------------------------------------------
	// The picture
	// ---------------------------------------------------------------------

	/**
	 * The email picture for a video post, made on first use and reused until
	 * the post's thumbnail changes.
	 *
	 * @return array|WP_Error array{url, width, height} (display size), or WP_Error.
	 */
	public static function image( $post_id ) {
		$source = get_attached_file( get_post_thumbnail_id( $post_id ) );
		if ( ! $source || ! is_readable( $source ) ) {
			return new WP_Error( 'svc_no_thumbnail', __( 'This video has no thumbnail yet. Give it a featured image, or wait for the next sync.', 'parish-video-center' ) );
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagecreatefromstring' ) ) {
			return new WP_Error( 'svc_no_gd', __( 'This server can’t draw pictures (the PHP GD extension is missing).', 'parish-video-center' ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'svc_no_uploads', $uploads['error'] );
		}

		$size = getimagesize( $source );
		if ( ! $size || $size[0] < 1 || $size[1] < 1 ) {
			return new WP_Error( 'svc_bad_thumbnail', __( 'The thumbnail could not be read.', 'parish-video-center' ) );
		}

		// A tall video whose thumbnail is wide has its picture padded with
		// bars at the sides (Vimeo does this); those get trimmed off below.
		$video  = SVC_Video::dimensions( $post_id );
		$padded = SVC_Video::is_portrait( $video ) && $size[0] > $size[1];
		if ( $padded ) {
			$size = array( (int) round( $size[1] * $video[0] / $video[1] ), $size[1] );
		}
		list( $width, $height ) = self::canvas( $size[0], $size[1] );

		// The name changes whenever the thumbnail or the drawing does, so
		// mail services that cache pictures by address pick up a new one.
		$key  = substr( md5( $source . '|' . filemtime( $source ) . '|' . SVC_VERSION . '|' . $width . 'x' . $height . ( $padded ? '|trimmed' : '' ) ), 0, 10 );
		$name = (int) $post_id . '-' . $key . '.jpg';
		$dir  = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		$out  = array(
			'url'    => trailingslashit( $uploads['baseurl'] ) . self::FOLDER . '/' . $name,
			'width'  => self::DISPLAY_WIDTH,
			'height' => (int) round( $height / 2 ),
		);

		if ( file_exists( $dir . '/' . $name ) ) {
			return $out;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'svc_no_uploads', __( 'The uploads folder is not writable.', 'parish-video-center' ) );
		}

		wp_raise_memory_limit( 'image' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$poster = imagecreatefromstring( file_get_contents( $source ) );
		if ( ! $poster ) {
			return new WP_Error( 'svc_bad_thumbnail', __( 'The thumbnail could not be read.', 'parish-video-center' ) );
		}

		if ( $padded ) {
			// Keep the centre strip that has the video's shape.
			$strip = imagecrop(
				$poster,
				array(
					'x'      => (int) round( ( imagesx( $poster ) - $size[0] ) / 2 ),
					'y'      => 0,
					'width'  => $size[0],
					'height' => $size[1],
				)
			);
			if ( $strip ) {
				imagedestroy( $poster );
				$poster = $strip;
			}
		}

		$picture = self::draw( $poster, $width, $height );
		imagedestroy( $poster );

		self::forget( $post_id );
		imageinterlace( $picture, true );
		$saved = imagejpeg( $picture, $dir . '/' . $name, 84 );
		imagedestroy( $picture );

		return $saved ? $out : new WP_Error( 'svc_no_uploads', __( 'The uploads folder is not writable.', 'parish-video-center' ) );
	}

	/**
	 * Canvas size, in drawn pixels, for a poster of a given size. A wide or
	 * square poster keeps its own shape. A tall one goes on a 4:5 canvas: the
	 * tallest shape that still leaves room for words around it in an email.
	 *
	 * @return int[] array{width, height}.
	 */
	private static function canvas( $poster_width, $poster_height ) {
		$width = self::DISPLAY_WIDTH * 2;

		if ( $poster_height > $poster_width ) {
			return array( $width, (int) round( $width * 5 / 4 ) );
		}

		// Very wide posters (panoramas) are held to 2.4:1 so the play button fits.
		return array( $width, (int) max( round( $width / 2.4 ), round( $width * $poster_height / $poster_width ) ) );
	}

	/**
	 * Draw the picture: poster (whole, on a blurred copy of itself where it
	 * doesn't fill the canvas) and a play button.
	 *
	 * @param resource|GdImage $poster Source poster.
	 * @return resource|GdImage
	 */
	private static function draw( $poster, $width, $height ) {
		$poster_width  = imagesx( $poster );
		$poster_height = imagesy( $poster );
		$picture       = imagecreatetruecolor( $width, $height );

		// Poster scaled to fit entirely inside the canvas.
		$scale       = min( $width / $poster_width, $height / $poster_height );
		$fit_width   = (int) round( $poster_width * $scale );
		$fit_height  = (int) round( $poster_height * $scale );
		$fills       = $fit_width >= $width - 2 && $fit_height >= $height - 2;

		if ( ! $fills ) {
			// Backdrop: the poster cropped to cover the canvas, blurred while
			// small (cheap, and a small blur becomes a wide one once it is
			// stretched back smoothly), then dimmed.
			$cover       = max( $width / $poster_width, $height / $poster_height );
			$crop_width  = (int) round( $width / $cover );
			$crop_height = (int) round( $height / $cover );
			$tiny_width  = 96;
			$tiny_height = (int) max( 1, round( 96 * $height / $width ) );
			$tiny        = imagecreatetruecolor( $tiny_width, $tiny_height );

			imagecopyresampled(
				$tiny,
				$poster,
				0,
				0,
				(int) ( ( $poster_width - $crop_width ) / 2 ),
				(int) ( ( $poster_height - $crop_height ) / 2 ),
				$tiny_width,
				$tiny_height,
				$crop_width,
				$crop_height
			);
			for ( $pass = 0; $pass < 6; $pass++ ) {
				imagefilter( $tiny, IMG_FILTER_GAUSSIAN_BLUR );
			}
			$backdrop = imagescale( $tiny, $width, $height, IMG_BILINEAR_FIXED );
			if ( $backdrop ) {
				imagecopy( $picture, $backdrop, 0, 0, 0, 0, $width, $height );
				imagedestroy( $backdrop );
			} else {
				imagecopyresampled( $picture, $tiny, 0, 0, 0, 0, $width, $height, $tiny_width, $tiny_height );
			}
			imagedestroy( $tiny );
			imagealphablending( $picture, true );
			imagefilledrectangle( $picture, 0, 0, $width, $height, imagecolorallocatealpha( $picture, 0, 0, 0, 64 ) );
		}

		imagecopyresampled(
			$picture,
			$poster,
			(int) ( ( $width - $fit_width ) / 2 ),
			(int) ( ( $height - $fit_height ) / 2 ),
			0,
			0,
			$fit_width,
			$fit_height,
			$poster_width,
			$poster_height
		);

		// Play button, drawn large and shrunk so its edges come out smooth.
		$diameter = (int) round( $width * 0.15 );
		$big      = $diameter * 4;
		$button   = imagecreatetruecolor( $big, $big );
		imagealphablending( $button, false );
		imagefilledrectangle( $button, 0, 0, $big, $big, imagecolorallocatealpha( $button, 0, 0, 0, 127 ) );
		imagefilledellipse( $button, (int) ( $big / 2 ), (int) ( $big / 2 ), $big - 4, $big - 4, imagecolorallocatealpha( $button, 0, 0, 0, 38 ) );
		// Same triangle as the on-page play button (68-unit box: 28,24 → 45,34 → 28,44).
		$unit = $big / 68;
		$triangle = array(
			(int) round( 28 * $unit ),
			(int) round( 24 * $unit ),
			(int) round( 45 * $unit ),
			(int) round( 34 * $unit ),
			(int) round( 28 * $unit ),
			(int) round( 44 * $unit ),
		);
		$white    = imagecolorallocate( $button, 255, 255, 255 );
		if ( PHP_VERSION_ID >= 80000 ) {
			imagefilledpolygon( $button, $triangle, $white );
		} else {
			imagefilledpolygon( $button, $triangle, 3, $white ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctionParameters.imagefilledpolygon_num_pointsDeprecated
		}

		imagealphablending( $picture, true );
		imagecopyresampled(
			$picture,
			$button,
			(int) ( ( $width - $diameter ) / 2 ),
			(int) ( ( $height - $diameter ) / 2 ),
			0,
			0,
			$diameter,
			$diameter,
			$big,
			$big
		);
		imagedestroy( $button );

		return $picture;
	}

	/**
	 * Remove a post's generated pictures (before making a new one, and when
	 * the post is deleted).
	 */
	public static function forget( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || SVC_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$uploads = wp_upload_dir( null, false );
		$files   = glob( trailingslashit( $uploads['basedir'] ) . self::FOLDER . '/' . $post_id . '-*.jpg' );

		foreach ( $files ? $files : array() as $file ) {
			wp_delete_file( $file );
		}
	}
}
