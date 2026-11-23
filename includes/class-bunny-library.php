<?php
/**
 * Bunny Stream library manager: browse, upload, edit, organise and delete the
 * library's videos from wp-admin.
 *
 * Video files upload straight from the browser to Bunny (resumable), so they
 * never pass through — or count against — the WordPress server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Bunny_Library {

	const PAGE         = 'svc-bunny-library';
	const ACTION       = 'svc_bunny_library';
	const TUS_ENDPOINT = 'https://video.bunnycdn.com/tusupload';

	// Largest thumbnail image accepted for upload.
	const MAX_THUMBNAIL_BYTES = 5242880;

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_ajax' ) );
	}

	/**
	 * Capability needed to manage the library (it can permanently delete videos).
	 */
	public static function capability() {
		return (string) apply_filters( 'svc_bunny_library_capability', 'manage_options' );
	}

	public static function url() {
		return admin_url( 'edit.php?post_type=' . SVC_Post_Type::POST_TYPE . '&page=' . self::PAGE );
	}

	public static function add_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=' . SVC_Post_Type::POST_TYPE,
			__( 'Video Library', 'parish-video-center' ),
			__( 'Video Library', 'parish-video-center' ),
			self::capability(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( $hook ) {
		if ( ! self::$hook || $hook !== self::$hook || ! svc_bunny_configured() ) {
			return;
		}

		wp_enqueue_style( 'svc-bunny-library', SVC_PLUGIN_URL . 'assets/bunny-library.css', array(), SVC_VERSION );
		wp_enqueue_script( 'svc-bunny-library', SVC_PLUGIN_URL . 'assets/bunny-library.js', array(), SVC_VERSION, true );
		wp_localize_script(
			'svc-bunny-library',
			'svcBunnyLibrary',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'action'      => self::ACTION,
				'nonce'       => wp_create_nonce( self::ACTION ),
				'tusEndpoint' => self::TUS_ENDPOINT,
				'perPage'     => 25,
				'topicsUrl'   => admin_url( 'edit-tags.php?taxonomy=' . SVC_Topics::TAXONOMY . '&post_type=' . SVC_Post_Type::POST_TYPE ),
				'i18n'        => self::strings(),
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		?>
		<div class="wrap svc-bl">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Video Library', 'parish-video-center' ); ?></h1>
			<?php if ( ! svc_bunny_configured() ) : ?>
				<hr class="wp-header-end">
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'Connect a video library in the settings before managing it.', 'parish-video-center' ); ?>
					<a href="<?php echo esc_url( SVC_Settings::url() ); ?>"><?php esc_html_e( 'Open settings', 'parish-video-center' ); ?></a>
				</p></div>
			<?php else : ?>
				<button type="button" class="page-title-action" data-svc="upload-toggle"><?php esc_html_e( 'Upload videos', 'parish-video-center' ); ?></button>
				<button type="button" class="page-title-action" data-svc="collections"><?php esc_html_e( 'Collections', 'parish-video-center' ); ?></button>
				<button type="button" class="page-title-action" data-svc="sync"><?php esc_html_e( 'Sync posts now', 'parish-video-center' ); ?></button>
				<hr class="wp-header-end">
				<div id="svc-bl-app"><p><?php esc_html_e( 'Loading library…', 'parish-video-center' ); ?></p></div>
				<noscript><p><?php esc_html_e( 'The library manager needs JavaScript.', 'parish-video-center' ); ?></p></noscript>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Single AJAX entry point; the "op" field picks the operation.
	 */
	public static function handle_ajax() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'parish-video-center' ) ), 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );

		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$methods = array(
			'list'              => 'op_list',
			'save'              => 'op_save',
			'delete'            => 'op_delete',
			'thumbnail'         => 'op_thumbnail',
			'upload_start'      => 'op_upload_start',
			'upload_resume'     => 'op_upload_resume',
			'collection_create' => 'op_collection_create',
			'collection_rename' => 'op_collection_rename',
			'collection_delete' => 'op_collection_delete',
			'sync'              => 'op_sync',
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

	private static function op_list() {
		$collection = self::post_guid( 'collection', true );
		if ( is_wp_error( $collection ) ) {
			return $collection;
		}

		$page = SVC_Bunny_API::list_page(
			array(
				'page'       => isset( $_POST['page'] ) ? (int) $_POST['page'] : 1,
				'per_page'   => isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 25,
				'search'     => self::post_text( 'search' ),
				'collection' => $collection,
			)
		);
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$collections = self::collections();
		if ( is_wp_error( $collections ) ) {
			return $collections;
		}

		$posts = self::posts_by_guid( wp_list_pluck( $page['items'], 'guid' ) );
		$items = array();
		foreach ( $page['items'] as $video ) {
			$items[] = self::present( $video, $posts );
		}

		$last_sync = get_option( 'svc_last_sync' );

		return array(
			'items'       => $items,
			'total'       => isset( $page['totalItems'] ) ? (int) $page['totalItems'] : count( $items ),
			'page'        => isset( $page['currentPage'] ) ? (int) $page['currentPage'] : 1,
			'collections' => $collections,
			'lastSync'    => is_array( $last_sync ) && ! empty( $last_sync['time'] )
				? sprintf(
					/* translators: %s: human-readable time difference */
					__( 'Posts last synced %s ago.', 'parish-video-center' ),
					human_time_diff( (int) $last_sync['time'] )
				)
				: '',
		);
	}

	private static function op_save() {
		$guid = self::post_guid( 'guid' );
		if ( is_wp_error( $guid ) ) {
			return $guid;
		}

		$fields = array();
		if ( isset( $_POST['title'] ) ) {
			$fields['title'] = self::post_text( 'title' );
			if ( '' === $fields['title'] ) {
				return new WP_Error( 'svc_bunny_title', __( 'A video needs a title.', 'parish-video-center' ) );
			}
		}
		if ( isset( $_POST['description'] ) ) {
			$fields['description'] = trim( sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) );
		}
		if ( isset( $_POST['collection'] ) ) {
			$collection = self::post_guid( 'collection', true );
			if ( is_wp_error( $collection ) ) {
				return $collection;
			}
			$fields['collection'] = $collection;
		}

		$result = SVC_Bunny_API::update_video( $guid, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_transient( 'svc_bunny_collections' );
		SVC_Video_Embed::forget( $guid );

		return array( 'saved' => true );
	}

	private static function op_delete() {
		$guid = self::post_guid( 'guid' );
		if ( is_wp_error( $guid ) ) {
			return $guid;
		}

		$result = SVC_Bunny_API::delete_video( $guid );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The video is gone for good, so take its post off the site right away
		// instead of leaving a dead player up until the next scheduled sync.
		$drafted = 0;
		$posts   = self::posts_by_guid( array( $guid ) );
		if ( isset( $posts[ $guid ] ) && 'bunny' === SVC_Video::provider( $posts[ $guid ] ) && 'publish' === get_post_status( $posts[ $guid ] ) ) {
			wp_update_post(
				array(
					'ID'          => $posts[ $guid ],
					'post_status' => 'draft',
				)
			);
			SVC_Cache::purge_all();
			$drafted = 1;
		}

		delete_transient( 'svc_bunny_collections' );
		SVC_Video_Embed::forget( $guid );

		return array( 'drafted' => $drafted );
	}

	private static function op_thumbnail() {
		$guid = self::post_guid( 'guid' );
		if ( is_wp_error( $guid ) ) {
			return $guid;
		}

		if ( empty( $_FILES['file']['tmp_name'] ) || ! empty( $_FILES['file']['error'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			return new WP_Error( 'svc_bunny_thumb', __( 'The image did not upload. Try a smaller file.', 'parish-video-center' ) );
		}

		$path = $_FILES['file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- server-generated temp path.
		if ( filesize( $path ) > self::MAX_THUMBNAIL_BYTES ) {
			return new WP_Error( 'svc_bunny_thumb', __( 'Thumbnails can be at most 5 MB.', 'parish-video-center' ) );
		}

		$info = wp_getimagesize( $path );
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
			return new WP_Error( 'svc_bunny_thumb', __( 'Thumbnails must be JPEG, PNG or WebP images.', 'parish-video-center' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		$result = SVC_Bunny_API::set_thumbnail( $guid, file_get_contents( $path ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		SVC_Video_Embed::forget( $guid );

		return array( 'saved' => true );
	}

	private static function op_upload_start() {
		$title = self::post_text( 'title' );
		if ( '' === $title ) {
			return new WP_Error( 'svc_bunny_title', __( 'A video needs a title.', 'parish-video-center' ) );
		}

		$collection = self::post_guid( 'collection', true );
		if ( is_wp_error( $collection ) ) {
			return $collection;
		}

		$video = SVC_Bunny_API::create_video( $title, $collection );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		if ( empty( $video['guid'] ) || ! SVC_Bunny_API::is_guid( $video['guid'] ) ) {
			return new WP_Error( 'svc_bunny_bad_response', __( 'Unexpected response from the video library.', 'parish-video-center' ) );
		}

		delete_transient( 'svc_bunny_collections' );

		return array(
			'guid'    => $video['guid'],
			'headers' => SVC_Bunny_API::upload_credentials( $video['guid'] ),
		);
	}

	/**
	 * Fresh upload credentials for a video whose upload was interrupted.
	 */
	private static function op_upload_resume() {
		$guid = self::post_guid( 'guid' );
		if ( is_wp_error( $guid ) ) {
			return $guid;
		}

		$video = SVC_Bunny_API::get_video( $guid );
		if ( is_wp_error( $video ) ) {
			return $video;
		}

		return array(
			'guid'    => $guid,
			'headers' => SVC_Bunny_API::upload_credentials( $guid ),
		);
	}

	private static function op_collection_create() {
		$name = self::post_text( 'name' );
		if ( '' === $name ) {
			return new WP_Error( 'svc_bunny_name', __( 'A collection needs a name.', 'parish-video-center' ) );
		}

		$result = SVC_Bunny_API::create_collection( $name );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'guid'        => isset( $result['guid'] ) ? (string) $result['guid'] : '',
			'collections' => self::collections(),
		);
	}

	private static function op_collection_rename() {
		$guid = self::post_guid( 'guid' );
		if ( is_wp_error( $guid ) ) {
			return $guid;
		}

		$name = self::post_text( 'name' );
		if ( '' === $name ) {
			return new WP_Error( 'svc_bunny_name', __( 'A collection needs a name.', 'parish-video-center' ) );
		}

		$result = SVC_Bunny_API::rename_collection( $guid, $name );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'collections' => self::collections() );
	}

	/**
	 * Only empty, unlinked collections can be deleted here: Bunny removes a
	 * collection's videos along with it, which is too easy to do by accident.
	 */
	private static function op_collection_delete() {
		$guid = self::post_guid( 'guid' );
		if ( is_wp_error( $guid ) ) {
			return $guid;
		}

		$collections = self::collections();
		if ( is_wp_error( $collections ) ) {
			return $collections;
		}
		foreach ( $collections as $collection ) {
			if ( $collection['guid'] !== $guid ) {
				continue;
			}
			if ( $collection['count'] > 0 ) {
				return new WP_Error( 'svc_bunny_not_empty', __( 'Move or delete the videos in this collection first — deleting a collection would delete its videos.', 'parish-video-center' ) );
			}
			if ( $collection['topic'] ) {
				return new WP_Error( 'svc_bunny_linked', __( 'This collection is linked to a topic. Unlink it on the Topics screen first.', 'parish-video-center' ) );
			}
		}

		$result = SVC_Bunny_API::delete_collection( $guid );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'collections' => self::collections() );
	}

	private static function op_sync() {
		$result = SVC_Sync::run();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$last = get_option( 'svc_last_sync' );

		return array( 'message' => is_array( $last ) && isset( $last['message'] ) ? (string) $last['message'] : '' );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * A trimmed single-line text field from the request.
	 */
	private static function post_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_ajax().
		return isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	/**
	 * A GUID field from the request.
	 *
	 * @param string $key      Field name.
	 * @param bool   $optional Whether '' is acceptable.
	 * @return string|WP_Error
	 */
	private static function post_guid( $key, $optional = false ) {
		$value = self::post_text( $key );
		if ( '' === $value && $optional ) {
			return '';
		}
		if ( ! SVC_Bunny_API::is_guid( $value ) ) {
			return new WP_Error( 'svc_bunny_bad_id', __( 'That video or collection ID is not valid.', 'parish-video-center' ) );
		}

		return strtolower( $value );
	}

	/**
	 * The library's collections, each with its video count and linked topic.
	 *
	 * @return array[]|WP_Error
	 */
	private static function collections() {
		$raw = SVC_Bunny_API::list_collections();
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$topics = array();
		foreach ( SVC_Topics::synced_topics( 'bunny' ) as $topic ) {
			$topics[ strtolower( $topic['source_id'] ) ] = $topic['name'];
		}

		$collections = array();
		foreach ( $raw as $collection ) {
			if ( empty( $collection['guid'] ) ) {
				continue;
			}
			$guid          = strtolower( (string) $collection['guid'] );
			$collections[] = array(
				'guid'  => $guid,
				'name'  => isset( $collection['name'] ) ? (string) $collection['name'] : $guid,
				'count' => isset( $collection['videoCount'] ) ? (int) $collection['videoCount'] : 0,
				'topic' => isset( $topics[ $guid ] ) ? $topics[ $guid ] : '',
			);
		}

		return $collections;
	}

	/**
	 * Post IDs for a set of Bunny video GUIDs.
	 *
	 * @return array guid => post ID
	 */
	private static function posts_by_guid( $guids ) {
		$guids = array_values( array_filter( array_map( 'strval', (array) $guids ) ) );
		if ( ! $guids ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_bunny_id',
						'value'   => $guids,
						'compare' => 'IN',
					),
				),
			)
		);

		$map = array();
		foreach ( $ids as $id ) {
			$map[ (string) get_post_meta( $id, '_bunny_id', true ) ] = (int) $id;
		}

		return $map;
	}

	/**
	 * Shape a Bunny video for the screen.
	 */
	private static function present( $video, $posts ) {
		$config = svc_bunny_config();
		$guid   = isset( $video['guid'] ) ? (string) $video['guid'] : '';
		$status = isset( $video['status'] ) ? (int) $video['status'] : 0;

		$thumbnail = '';
		if ( ! empty( $video['thumbnailUrl'] ) ) {
			$thumbnail = (string) $video['thumbnailUrl'];
		} elseif ( $config['cdn_host'] && ! empty( $video['thumbnailFileName'] ) ) {
			$thumbnail = 'https://' . $config['cdn_host'] . '/' . $guid . '/' . $video['thumbnailFileName'];
		}

		$post = null;
		if ( isset( $posts[ $guid ] ) ) {
			$post_id = $posts[ $guid ];
			$post    = array(
				'id'     => (int) $post_id,
				'status' => get_post_status( $post_id ),
				'edit'   => get_edit_post_link( $post_id, 'raw' ),
				'view'   => get_permalink( $post_id ),
			);
		}

		$uploaded = isset( $video['dateUploaded'] ) ? strtotime( (string) $video['dateUploaded'] . ' UTC' ) : 0;

		return array(
			'guid'        => $guid,
			'title'       => isset( $video['title'] ) ? (string) $video['title'] : '',
			'description' => isset( $video['description'] ) ? (string) $video['description'] : '',
			'collection'  => isset( $video['collectionId'] ) ? strtolower( (string) $video['collectionId'] ) : '',
			'status'      => $status,
			'statusLabel' => self::status_label( $status ),
			'ready'       => SVC_Bunny_API::STATUS_FINISHED === $status,
			'failed'      => in_array( $status, array( 5, 6 ), true ),
			'progress'    => isset( $video['encodeProgress'] ) ? (int) $video['encodeProgress'] : 0,
			'duration'    => ! empty( $video['length'] ) ? self::clock( (int) $video['length'] ) : '',
			'size'        => ! empty( $video['storageSize'] ) ? size_format( (float) $video['storageSize'], 1 ) : '',
			'views'       => isset( $video['views'] ) ? (int) $video['views'] : 0,
			'uploaded'    => $uploaded ? wp_date( get_option( 'date_format' ), $uploaded ) : '',
			'thumbnail'   => $thumbnail,
			'embed'       => $guid ? 'https://player.mediadelivery.net/embed/' . rawurlencode( $config['library_id'] ) . '/' . rawurlencode( $guid ) . '?autoplay=false' : '',
			'post'        => $post,
		);
	}

	/**
	 * Seconds as a clock time, e.g. 47:44 or 1:02:09.
	 */
	private static function clock( $seconds ) {
		$hours = (int) floor( $seconds / 3600 );

		return $hours
			? sprintf( '%d:%02d:%02d', $hours, ( $seconds / 60 ) % 60, $seconds % 60 )
			: sprintf( '%d:%02d', $seconds / 60, $seconds % 60 );
	}

	private static function status_label( $status ) {
		switch ( $status ) {
			case 0:
				return __( 'Waiting for upload', 'parish-video-center' );
			case 1:
				return __( 'Uploaded', 'parish-video-center' );
			case 2:
			case 3:
			case 7:
			case 8:
				return __( 'Processing', 'parish-video-center' );
			case 4:
				return __( 'Ready', 'parish-video-center' );
			case 5:
				return __( 'Processing failed', 'parish-video-center' );
			case 6:
				return __( 'Upload failed', 'parish-video-center' );
		}

		return __( 'Unknown', 'parish-video-center' );
	}

	private static function strings() {
		return array(
			'loading'          => __( 'Loading…', 'parish-video-center' ),
			'allVideos'        => __( 'All videos', 'parish-video-center' ),
			'noCollection'     => __( 'No collection', 'parish-video-center' ),
			'search'           => __( 'Search videos', 'parish-video-center' ),
			'empty'            => __( 'No videos found.', 'parish-video-center' ),
			/* translators: %d: number of videos */
			'count'            => __( '%d videos', 'parish-video-center' ),
			'colVideo'         => __( 'Video', 'parish-video-center' ),
			'colCollection'    => __( 'Collection', 'parish-video-center' ),
			'colStatus'        => __( 'Status', 'parish-video-center' ),
			'colLength'        => __( 'Length', 'parish-video-center' ),
			'colSize'          => __( 'Size', 'parish-video-center' ),
			'colViews'         => __( 'Views', 'parish-video-center' ),
			'colUploaded'      => __( 'Uploaded', 'parish-video-center' ),
			'colPost'          => __( 'Post', 'parish-video-center' ),
			'edit'             => __( 'Edit', 'parish-video-center' ),
			'preview'          => __( 'Preview', 'parish-video-center' ),
			'delete'           => __( 'Delete', 'parish-video-center' ),
			'copyId'           => __( 'Copy shortcode', 'parish-video-center' ),
			'copyEmail'        => __( 'Copy for email', 'parish-video-center' ),
			'copied'           => __( 'Copied', 'parish-video-center' ),
			'editPost'         => __( 'Edit post', 'parish-video-center' ),
			'viewPost'         => __( 'View', 'parish-video-center' ),
			'draft'            => __( 'draft', 'parish-video-center' ),
			'noPost'           => __( '—', 'parish-video-center' ),
			'prev'             => __( '‹ Previous', 'parish-video-center' ),
			'next'             => __( 'Next ›', 'parish-video-center' ),
			/* translators: 1: current page, 2: total pages */
			'pageOf'           => __( 'Page %1$d of %2$d', 'parish-video-center' ),
			'editTitle'        => __( 'Edit video', 'parish-video-center' ),
			'title'            => __( 'Title', 'parish-video-center' ),
			'description'      => __( 'Description', 'parish-video-center' ),
			'collection'       => __( 'Collection', 'parish-video-center' ),
			'thumbnail'        => __( 'Thumbnail', 'parish-video-center' ),
			'thumbnailHelp'    => __( 'Choose a JPEG, PNG or WebP image (up to 5 MB) to replace the thumbnail.', 'parish-video-center' ),
			'save'             => __( 'Save changes', 'parish-video-center' ),
			'saving'           => __( 'Saving…', 'parish-video-center' ),
			'saved'            => __( 'Saved. Posts pick up the change at the next sync.', 'parish-video-center' ),
			'cancel'           => __( 'Cancel', 'parish-video-center' ),
			'close'            => __( 'Close', 'parish-video-center' ),
			/* translators: %s: video title */
			'confirmDelete'    => __( "Permanently delete \"%s\" from the video library?\n\nThe video file is removed for good and cannot be restored.", 'parish-video-center' ),
			'confirmDeletePost' => __( 'Its post on the website will be switched to draft.', 'parish-video-center' ),
			'deleted'          => __( 'Video deleted.', 'parish-video-center' ),
			'deletedDrafted'   => __( 'Video deleted and its post switched to draft.', 'parish-video-center' ),
			'collectionsTitle' => __( 'Collections', 'parish-video-center' ),
			'collectionsHelp'  => __( 'Videos in a collection that is linked to a topic get their own post on the website. Link collections on the Topics screen.', 'parish-video-center' ),
			'topics'           => __( 'Open Topics', 'parish-video-center' ),
			'newCollection'    => __( 'New collection name', 'parish-video-center' ),
			'add'              => __( 'Add collection', 'parish-video-center' ),
			'rename'           => __( 'Rename', 'parish-video-center' ),
			'renamePrompt'     => __( 'New name for this collection:', 'parish-video-center' ),
			/* translators: %s: collection name */
			'confirmDeleteCollection' => __( 'Delete the empty collection "%s"?', 'parish-video-center' ),
			/* translators: %s: topic name */
			'linkedTo'         => __( 'Creates posts in: %s', 'parish-video-center' ),
			'notLinked'        => __( 'Library only', 'parish-video-center' ),
			'noCollections'    => __( 'No collections yet.', 'parish-video-center' ),
			'uploadTitle'      => __( 'Upload videos', 'parish-video-center' ),
			'uploadHelp'       => __( 'Drop video files here, or choose files. They upload one at a time, straight to the video library, and an interrupted upload resumes when you add the same file again.', 'parish-video-center' ),
			'chooseFiles'      => __( 'Choose files', 'parish-video-center' ),
			'uploadTo'         => __( 'Add to collection', 'parish-video-center' ),
			'queued'           => __( 'Waiting', 'parish-video-center' ),
			'starting'         => __( 'Starting…', 'parish-video-center' ),
			'resuming'         => __( 'Resuming…', 'parish-video-center' ),
			'retrying'         => __( 'Connection lost — retrying…', 'parish-video-center' ),
			'uploadDone'       => __( 'Uploaded — the video is now processing.', 'parish-video-center' ),
			'uploadFailed'     => __( 'Upload failed', 'parish-video-center' ),
			'uploadCancelled'  => __( 'Cancelled', 'parish-video-center' ),
			'retry'            => __( 'Retry', 'parish-video-center' ),
			'notVideo'         => __( 'Not a video file — skipped.', 'parish-video-center' ),
			'leaveWarning'     => __( 'An upload is still running. Leaving this page will interrupt it.', 'parish-video-center' ),
			'syncing'          => __( 'Syncing…', 'parish-video-center' ),
			'syncNow'          => __( 'Sync posts now', 'parish-video-center' ),
			'error'            => __( 'Something went wrong. Try again.', 'parish-video-center' ),
		);
	}
}
