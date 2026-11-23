<?php
/**
 * Vimeo → video library migrator.
 *
 * Deliberately pick-and-choose: an administrator selects individual videos,
 * sees how much data the copy will transfer, and only a few copies run at a
 * time. There is no "move everything" action.
 *
 * A copy goes straight from Vimeo to the library (the library fetches the
 * file by URL), so it never passes through the WordPress server. The post —
 * and its URL — stays the same throughout: it keeps playing from Vimeo until
 * playback is switched, and can be switched back while the Vimeo video exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Migrator {

	const PAGE   = 'svc-migrate';
	const ACTION = 'svc_migrator';

	// Post meta.
	const STATE = '_svc_migration';       // copying | ready | failed
	const ERROR = '_svc_migration_error'; // why a copy failed
	const AUTO  = '_svc_migration_auto';  // switch playback as soon as the copy is ready

	const QUALITIES = array( 'original', '1080', '720' );

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_ajax' ) );
		// Finish copies in the background too, so nobody has to keep the screen open.
		add_action( SVC_Sync::CRON_HOOK, array( __CLASS__, 'refresh' ), 5 );
	}

	/**
	 * How many copies may be in progress at once.
	 */
	public static function limit() {
		return max( 1, (int) apply_filters( 'svc_migration_concurrent_limit', 3 ) );
	}

	private static function available() {
		return svc_bunny_configured() && svc_get_token();
	}

	public static function add_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=' . SVC_Post_Type::POST_TYPE,
			__( 'Move Videos from Vimeo', 'parish-video-center' ),
			__( 'Move from Vimeo', 'parish-video-center' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( $hook ) {
		if ( ! self::$hook || $hook !== self::$hook || ! self::available() ) {
			return;
		}

		wp_enqueue_style( 'svc-bunny-library', SVC_PLUGIN_URL . 'assets/bunny-library.css', array(), SVC_VERSION );
		wp_enqueue_script( 'svc-migrator', SVC_PLUGIN_URL . 'assets/migrator.js', array(), SVC_VERSION, true );
		wp_localize_script(
			'svc-migrator',
			'svcMigrator',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( self::ACTION ),
				'limit'   => self::limit(),
				'i18n'    => self::strings(),
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap svc-bl">
			<h1><?php esc_html_e( 'Move Videos from Vimeo', 'parish-video-center' ); ?></h1>
			<?php if ( ! self::available() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'Moving videos needs both a Vimeo access token and a connected video library.', 'parish-video-center' ); ?>
					<a href="<?php echo esc_url( SVC_Settings::url() ); ?>"><?php esc_html_e( 'Open settings', 'parish-video-center' ); ?></a>
				</p></div>
			<?php else : ?>
				<p class="description" style="max-width: 80ch;">
					<?php
					printf(
						/* translators: %d: number of copies allowed at once */
						esc_html__( 'Choose the videos to copy into the video library. Each copy goes straight from Vimeo to the library, and at most %d run at a time. A video keeps playing from Vimeo, at the same address, until you switch its playback — and you can switch back for as long as the Vimeo video exists.', 'parish-video-center' ),
						(int) self::limit()
					);
					?>
				</p>
				<div id="svc-mig-app"><p><?php esc_html_e( 'Loading…', 'parish-video-center' ); ?></p></div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'parish-video-center' ) ), 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );

		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$methods = array(
			'list'     => 'op_list',
			'estimate' => 'op_estimate',
			'start'    => 'op_start',
			'switch'   => 'op_switch',
		);
		if ( ! isset( $methods[ $op ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown operation.', 'parish-video-center' ) ), 400 );
		}

		$result = call_user_func( array( __CLASS__, $methods[ $op ] ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	// ---------------------------------------------------------------------
	// Operations
	// ---------------------------------------------------------------------

	/**
	 * Every post that has a Vimeo video, with where its migration stands.
	 * Also moves any finished copies along first.
	 */
	private static function op_list() {
		self::refresh();

		$ids = get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_key'       => '_vimeo_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'   => 'EXISTS',
			)
		);

		$items = array();
		foreach ( $ids as $post_id ) {
			$topics  = wp_get_object_terms( $post_id, SVC_Topics::TAXONOMY, array( 'fields' => 'names' ) );
			$items[] = array(
				'id'       => $post_id,
				'title'    => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
				'date'     => get_the_date( '', $post_id ),
				'duration' => svc_human_duration( (int) get_post_meta( $post_id, '_vimeo_duration', true ) ),
				'topics'   => is_wp_error( $topics ) ? '' : implode( ', ', $topics ),
				'status'   => get_post_status( $post_id ),
				'state'    => self::state( $post_id ),
				'error'    => (string) get_post_meta( $post_id, self::ERROR, true ),
				'auto'     => (bool) get_post_meta( $post_id, self::AUTO, true ),
				'edit'     => get_edit_post_link( $post_id, 'raw' ),
				'view'     => get_permalink( $post_id ),
			);
		}

		$collections = SVC_Bunny_API::collection_names();

		return array(
			'items'       => $items,
			'collections' => is_wp_error( $collections ) ? array() : $collections,
		);
	}

	/**
	 * How much data copying the chosen videos would transfer.
	 */
	private static function op_estimate() {
		$quality = self::post_quality();
		$videos  = array();
		$total   = 0;
		$unknown = 0;

		foreach ( self::post_ids() as $post_id ) {
			$file = self::can_copy( $post_id );
			if ( ! is_wp_error( $file ) ) {
				$file = self::source_file( $post_id, $quality );
			}

			if ( is_wp_error( $file ) ) {
				$videos[] = array(
					'id'    => $post_id,
					'error' => $file->get_error_message(),
				);
				continue;
			}

			$total += $file['size'];
			if ( ! $file['size'] ) {
				$unknown++;
			}
			$videos[] = array(
				'id'     => $post_id,
				'size'   => $file['size'] ? size_format( $file['size'], 1 ) : '',
				'height' => $file['height'],
			);
		}

		return array(
			'videos'  => $videos,
			'total'   => $total ? size_format( $total, 1 ) : '',
			'unknown' => $unknown,
		);
	}

	/**
	 * Start copying one video. The screen calls this once per chosen video.
	 */
	private static function op_start() {
		$ids = self::post_ids();
		if ( 1 !== count( $ids ) ) {
			return new WP_Error( 'svc_migrate_one', __( 'Start one video at a time.', 'parish-video-center' ) );
		}
		$post_id = $ids[0];

		$check = self::can_copy( $post_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( count( self::posts_in_state( 'copying' ) ) >= self::limit() ) {
			return new WP_Error(
				'svc_migrate_busy',
				sprintf(
					/* translators: %d: number of copies allowed at once */
					__( 'Only %d copies can run at a time. Wait for one to finish, then start this one.', 'parish-video-center' ),
					self::limit()
				)
			);
		}

		$collection = isset( $_POST['collection'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['collection'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_ajax().
		if ( '' !== $collection && ! SVC_Bunny_API::is_guid( $collection ) ) {
			return new WP_Error( 'svc_bunny_bad_id', __( 'That video or collection ID is not valid.', 'parish-video-center' ) );
		}

		$file = self::source_file( $post_id, self::post_quality() );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// A failed earlier attempt leaves a broken entry in the library.
		$previous = (string) get_post_meta( $post_id, '_bunny_id', true );
		if ( $previous && 'failed' === get_post_meta( $post_id, self::STATE, true ) ) {
			SVC_Bunny_API::delete_video( $previous );
		}

		$post  = get_post( $post_id );
		$title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$guid  = SVC_Bunny_API::fetch_video( $file['link'], $title, $collection );
		if ( is_wp_error( $guid ) ) {
			return $guid;
		}

		// Carry the description over now, so the library entry is complete
		// even before the copy finishes.
		if ( '' !== trim( (string) $post->post_content ) ) {
			SVC_Bunny_API::update_video( $guid, array( 'description' => wp_strip_all_tags( $post->post_content ) ) );
		}

		$config = svc_bunny_config();
		// Pin playback to Vimeo explicitly: the post now has IDs for both.
		update_post_meta( $post_id, SVC_Video::PROVIDER_META, 'vimeo' );
		update_post_meta( $post_id, '_bunny_id', $guid );
		update_post_meta( $post_id, '_bunny_library_id', $config['library_id'] );
		update_post_meta( $post_id, self::STATE, 'copying' );
		delete_post_meta( $post_id, self::ERROR );
		if ( ! empty( $_POST['auto'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, self::AUTO, '1' );
		} else {
			delete_post_meta( $post_id, self::AUTO );
		}

		delete_transient( 'svc_bunny_collections' );

		return array( 'state' => 'copying' );
	}

	/**
	 * Switch chosen videos' playback to the library, or back to Vimeo.
	 */
	private static function op_switch() {
		$to = isset( $_POST['to'] ) && 'vimeo' === $_POST['to'] ? 'vimeo' : 'bunny'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ok = 0;

		foreach ( self::post_ids() as $post_id ) {
			$state = self::state( $post_id );
			if ( ( 'bunny' === $to && 'ready' === $state ) || ( 'vimeo' === $to && 'switched' === $state ) ) {
				update_post_meta( $post_id, SVC_Video::PROVIDER_META, $to );
				$ok++;
			}
		}

		if ( $ok ) {
			SVC_Cache::purge_all();
		}

		return array( 'switched' => $ok );
	}

	// ---------------------------------------------------------------------
	// Background progress
	// ---------------------------------------------------------------------

	/**
	 * Check the copies in progress and complete the ones that have finished.
	 */
	public static function refresh() {
		if ( ! svc_bunny_configured() ) {
			return;
		}

		$purge = false;
		foreach ( self::posts_in_state( 'copying' ) as $post_id ) {
			$guid  = (string) get_post_meta( $post_id, '_bunny_id', true );
			$video = SVC_Bunny_API::get_video( $guid );

			if ( is_wp_error( $video ) ) {
				// A missing video will never finish; anything else may be a blip.
				if ( 'svc_bunny_http_404' === $video->get_error_code() ) {
					self::fail( $post_id, __( 'The copy is no longer in the video library.', 'parish-video-center' ) );
				}
				continue;
			}

			$status = isset( $video['status'] ) ? (int) $video['status'] : 0;
			if ( in_array( $status, array( 5, 6 ), true ) ) {
				self::fail( $post_id, __( 'The video library could not fetch or process the file. Try again.', 'parish-video-center' ) );
				continue;
			}
			if ( SVC_Bunny_API::STATUS_FINISHED !== $status ) {
				continue;
			}

			self::complete( $post_id, $video );
			if ( get_post_meta( $post_id, self::AUTO, true ) ) {
				update_post_meta( $post_id, SVC_Video::PROVIDER_META, 'bunny' );
				$purge = true;
			}
		}

		if ( $purge ) {
			SVC_Cache::purge_all();
		}
	}

	private static function fail( $post_id, $message ) {
		update_post_meta( $post_id, self::STATE, 'failed' );
		update_post_meta( $post_id, self::ERROR, $message );
	}

	/**
	 * Record a finished copy on its post and give it the post's thumbnail.
	 */
	private static function complete( $post_id, $video ) {
		$normalized = SVC_Sync::normalize_bunny( $video );
		if ( ! $normalized ) {
			return;
		}

		update_post_meta( $post_id, '_bunny_duration', $normalized['duration'] );
		update_post_meta( $post_id, '_bunny_library_id', $normalized['library_id'] );
		if ( $normalized['width'] && $normalized['height'] ) {
			update_post_meta( $post_id, '_bunny_width', $normalized['width'] );
			update_post_meta( $post_id, '_bunny_height', $normalized['height'] );
		}
		if ( $normalized['file_url'] ) {
			update_post_meta( $post_id, '_bunny_file_url', $normalized['file_url'] );
		}

		// The library picked a frame as the thumbnail; replace it with the
		// one this post already uses.
		$path = get_attached_file( get_post_thumbnail_id( $post_id ) );
		if ( $path && is_readable( $path ) && filesize( $path ) <= SVC_Bunny_Library::MAX_THUMBNAIL_BYTES ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			SVC_Bunny_API::set_thumbnail( $normalized['id'], file_get_contents( $path ) );
		}

		update_post_meta( $post_id, self::STATE, 'ready' );
		delete_post_meta( $post_id, self::ERROR );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Where a post's migration stands:
	 * vimeo (not copied) | copying | ready (copied, still on Vimeo) | failed | switched.
	 */
	public static function state( $post_id ) {
		if ( 'bunny' === SVC_Video::provider( $post_id ) ) {
			return 'switched';
		}

		$state = (string) get_post_meta( $post_id, self::STATE, true );

		return in_array( $state, array( 'copying', 'ready', 'failed' ), true ) ? $state : 'vimeo';
	}

	/**
	 * @return true|WP_Error Whether a copy may be started for this post.
	 */
	private static function can_copy( $post_id ) {
		if ( SVC_Post_Type::POST_TYPE !== get_post_type( $post_id ) || ! get_post_meta( $post_id, '_vimeo_id', true ) ) {
			return new WP_Error( 'svc_migrate_not_vimeo', __( 'This is not a Vimeo video.', 'parish-video-center' ) );
		}
		if ( ! in_array( self::state( $post_id ), array( 'vimeo', 'failed' ), true ) ) {
			return new WP_Error( 'svc_migrate_done', __( 'This video has already been copied, or its copy is in progress.', 'parish-video-center' ) );
		}

		return true;
	}

	/**
	 * The file to copy for a post: normally the one Vimeo offers.
	 *
	 * @return array|WP_Error array{link, size, height}, or WP_Error.
	 */
	private static function source_file( $post_id, $quality ) {
		$file = SVC_Vimeo_API::source_file( get_post_meta( $post_id, '_vimeo_id', true ), $quality );

		/**
		 * Filters the file copied into the video library for a post, e.g. to
		 * point at an original kept somewhere other than Vimeo.
		 *
		 * @param array|WP_Error $file    array{link, size, height}, or WP_Error.
		 * @param int            $post_id Video post ID.
		 * @param string         $quality 'original', '1080' or '720'.
		 */
		return apply_filters( 'svc_migration_source_file', $file, $post_id, $quality );
	}

	private static function posts_in_state( $state ) {
		return get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $state, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * Post IDs from the request ("ids" as a comma-separated list), capped.
	 */
	private static function post_ids() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_ajax().
		$raw = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );

		return array_slice( $ids, 0, 50 );
	}

	private static function post_quality() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_ajax().
		$quality = isset( $_POST['quality'] ) ? sanitize_key( wp_unslash( $_POST['quality'] ) ) : '1080';

		return in_array( $quality, self::QUALITIES, true ) ? $quality : '1080';
	}

	private static function strings() {
		return array(
			'filterAll'      => __( 'All Vimeo videos', 'parish-video-center' ),
			'search'         => __( 'Search videos', 'parish-video-center' ),
			'colVideo'       => __( 'Video', 'parish-video-center' ),
			'colTopic'       => __( 'Topic', 'parish-video-center' ),
			'colLength'      => __( 'Length', 'parish-video-center' ),
			'colDate'        => __( 'Date', 'parish-video-center' ),
			'colStatus'      => __( 'Status', 'parish-video-center' ),
			'selectAll'      => __( 'Select all shown videos that can be copied', 'parish-video-center' ),
			'empty'          => __( 'No videos found.', 'parish-video-center' ),
			'stateVimeo'     => __( 'On Vimeo', 'parish-video-center' ),
			'stateCopying'   => __( 'Copying', 'parish-video-center' ),
			'stateReady'     => __( 'Copied — still playing from Vimeo', 'parish-video-center' ),
			'stateSwitched'  => __( 'Playing from the video library', 'parish-video-center' ),
			'stateFailed'    => __( 'Copy failed', 'parish-video-center' ),
			'autoNote'       => __( 'switches when ready', 'parish-video-center' ),
			'draft'          => __( 'draft', 'parish-video-center' ),
			'view'           => __( 'View', 'parish-video-center' ),
			'editPost'       => __( 'Edit post', 'parish-video-center' ),
			'switchTo'       => __( 'Switch playback to the library', 'parish-video-center' ),
			'switchBack'     => __( 'Switch back to Vimeo', 'parish-video-center' ),
			'retry'          => __( 'Try again', 'parish-video-center' ),
			'quality'        => __( 'Copy quality', 'parish-video-center' ),
			'q1080'          => __( 'Up to 1080p (recommended)', 'parish-video-center' ),
			'q720'           => __( 'Up to 720p (least data)', 'parish-video-center' ),
			'qOriginal'      => __( 'Original file (most data)', 'parish-video-center' ),
			'collection'     => __( 'Add to collection', 'parish-video-center' ),
			'noCollection'   => __( 'No collection', 'parish-video-center' ),
			'auto'           => __( 'Switch playback as soon as each copy is ready', 'parish-video-center' ),
			'copy'           => __( 'Copy selected…', 'parish-video-center' ),
			/* translators: %d: number of selected videos */
			'selected'       => __( '%d selected', 'parish-video-center' ),
			/* translators: 1: copies in progress, 2: copies allowed at once */
			'inProgress'     => __( '%1$d of %2$d copies in progress', 'parish-video-center' ),
			/* translators: %d: number of videos that can be started now */
			'tooMany'        => __( 'Only %d more can be started right now. Select fewer videos, or wait for the copies in progress.', 'parish-video-center' ),
			'checking'       => __( 'Checking file sizes on Vimeo…', 'parish-video-center' ),
			'confirmTitle'   => __( 'Copy these videos?', 'parish-video-center' ),
			/* translators: %s: total size, e.g. 2.4 GB */
			'confirmTotal'   => __( 'Total to transfer from Vimeo: %s', 'parish-video-center' ),
			'confirmUnknown' => __( 'Vimeo did not report a size for some files, so the real total is higher.', 'parish-video-center' ),
			'confirmNone'    => __( 'None of the selected videos can be copied.', 'parish-video-center' ),
			'confirmGo'      => __( 'Start copying', 'parish-video-center' ),
			'cancel'         => __( 'Cancel', 'parish-video-center' ),
			'close'          => __( 'Close', 'parish-video-center' ),
			'starting'       => __( 'Starting…', 'parish-video-center' ),
			/* translators: %d: number of copies started */
			'started'        => __( '%d copies started. This screen updates as they finish; you can also leave and come back.', 'parish-video-center' ),
			/* translators: %d: number of videos switched */
			'switched'       => __( 'Playback switched for %d video(s).', 'parish-video-center' ),
			'error'          => __( 'Something went wrong. Try again.', 'parish-video-center' ),
		);
	}
}
