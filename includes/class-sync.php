<?php
/**
 * Sync engine: pulls the Vimeo showcase into video posts on a WP-Cron schedule.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Sync {

	const CRON_HOOK   = 'svc_sync_event';
	const FREQUENCIES = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_post_svc_sync_now', array( __CLASS__, 'handle_manual_sync' ) );
		add_action( 'update_option_svc_settings', array( __CLASS__, 'reschedule' ), 10, 0 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$settings  = svc_get_settings();
			$frequency = in_array( $settings['sync_frequency'], self::FREQUENCIES, true )
				? $settings['sync_frequency']
				: 'hourly';
			wp_schedule_event( time(), $frequency, self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function reschedule() {
		self::unschedule();
		self::schedule();
	}

	public static function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'parish-video-center' ) );
		}
		check_admin_referer( 'svc_sync_now' );

		$result = self::run();
		$flag   = is_wp_error( $result ) ? 'error' : 'ok';

		wp_safe_redirect( add_query_arg( 'svc-synced', $flag, SVC_Settings::url() ) );
		exit;
	}

	/**
	 * Run a full sync: upsert posts, sideload changed thumbnails, draft removed videos.
	 *
	 * @return true|WP_Error
	 */
	public static function run() {
		$settings = svc_get_settings();

		$videos = SVC_Vimeo_API::fetch_showcase_videos( $settings['showcase_id'] );
		if ( is_wp_error( $videos ) ) {
			update_option(
				'svc_last_sync',
				array(
					'time'    => time(),
					'status'  => 'error',
					'message' => $videos->get_error_message(),
				),
				false
			);
			return $videos;
		}

		// Map existing posts by Vimeo ID (any non-trashed status, so drafted videos re-publish on return).
		$existing = array();
		$query    = new WP_Query(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $query->posts as $post_id ) {
			$vimeo_id = get_post_meta( $post_id, '_vimeo_id', true );
			if ( $vimeo_id ) {
				$existing[ $vimeo_id ] = $post_id;
			}
		}

		$created = 0;
		$updated = 0;
		$drafted = 0;
		$locked  = 0;
		$seen    = array();

		foreach ( $videos as $video ) {
			$vimeo_id = self::extract_id( isset( $video['uri'] ) ? $video['uri'] : '' );
			if ( ! $vimeo_id ) {
				continue;
			}
			$seen[ $vimeo_id ] = true;

			$title        = isset( $video['name'] ) ? $video['name'] : '';
			$description  = isset( $video['description'] ) ? (string) $video['description'] : '';
			$created_time = isset( $video['created_time'] ) ? $video['created_time'] : '';
			$duration     = isset( $video['duration'] ) ? (int) $video['duration'] : 0;
			$thumbnail    = self::largest_thumbnail( $video );

			$postarr = array(
				'post_type'    => SVC_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $description,
			);

			if ( $created_time ) {
				$timestamp = strtotime( $created_time );
				if ( $timestamp ) {
					$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
					$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
				}
			}

			if ( isset( $existing[ $vimeo_id ] ) ) {
				$post_id = $existing[ $vimeo_id ];

				// Editor opted this post out of sync — leave it entirely untouched.
				if ( get_post_meta( $post_id, '_svc_sync_lock', true ) ) {
					$locked++;
					continue;
				}

				$post = get_post( $post_id );

				$changed = $post
					&& ( $post->post_title !== $title
						|| $post->post_content !== $description
						|| 'publish' !== $post->post_status );

				if ( $changed ) {
					$postarr['ID'] = $post_id;
					wp_update_post( wp_slash( $postarr ) );
					$updated++;
				}
			} else {
				$post_id = wp_insert_post( wp_slash( $postarr ), true );
				if ( is_wp_error( $post_id ) ) {
					continue;
				}
				$created++;
			}

			update_post_meta( $post_id, '_vimeo_id', $vimeo_id );
			update_post_meta( $post_id, '_vimeo_duration', $duration );

			if ( $thumbnail && get_post_meta( $post_id, '_vimeo_thumbnail_src', true ) !== $thumbnail ) {
				$attachment_id = self::sideload_thumbnail( $thumbnail, $post_id, $title, $vimeo_id );
				if ( $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
					update_post_meta( $post_id, '_vimeo_thumbnail_src', $thumbnail );
				}
			}
		}

		// Videos that left the showcase get unpublished, not deleted.
		foreach ( $existing as $vimeo_id => $post_id ) {
			if ( ! isset( $seen[ $vimeo_id ] )
				&& 'publish' === get_post_status( $post_id )
				&& ! get_post_meta( $post_id, '_svc_sync_lock', true ) ) {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
				$drafted++;
			}
		}

		update_option(
			'svc_last_sync',
			array(
				'time'    => time(),
				'status'  => 'ok',
				'message' => sprintf(
					/* translators: 1: total videos, 2: created, 3: updated, 4: unpublished, 5: locked */
					__( '%1$d videos in showcase: %2$d created, %3$d updated, %4$d unpublished, %5$d locked.', 'parish-video-center' ),
					count( $videos ),
					$created,
					$updated,
					$drafted,
					$locked
				),
			),
			false
		);

		return true;
	}

	/**
	 * Extract the numeric video ID from a Vimeo URI like /videos/123456789.
	 */
	private static function extract_id( $uri ) {
		if ( preg_match( '#/videos/(\d+)#', (string) $uri, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Largest available thumbnail URL (Vimeo lists sizes smallest to largest).
	 */
	private static function largest_thumbnail( $video ) {
		if ( empty( $video['pictures']['sizes'] ) || ! is_array( $video['pictures']['sizes'] ) ) {
			return '';
		}
		$last = end( $video['pictures']['sizes'] );
		return isset( $last['link'] ) ? $last['link'] : '';
	}

	/**
	 * Sideload a thumbnail as an attachment. Vimeo CDN URLs have no file
	 * extension, so media_sideload_image() would reject them — download
	 * manually and name the temp file explicitly instead.
	 *
	 * @return int Attachment ID, or 0 on failure.
	 */
	private static function sideload_thumbnail( $url, $post_id, $title, $vimeo_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$file_array = array(
			'name'     => 'vimeo-' . $vimeo_id . '.jpg',
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return 0;
		}

		return $attachment_id;
	}
}
