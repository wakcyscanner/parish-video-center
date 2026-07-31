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
	const LOCK        = 'svc_sync_running';

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
		$flag   = 'ok';
		if ( is_wp_error( $result ) ) {
			$flag = 'svc_sync_running' === $result->get_error_code() ? 'locked' : 'error';
		}

		wp_safe_redirect( add_query_arg( 'svc-synced', $flag, SVC_Settings::url() ) );
		exit;
	}

	/**
	 * Run a full sync: upsert posts, sideload changed thumbnails, draft removed videos.
	 *
	 * A run can hold a PHP worker for a while (Vimeo API paging across every
	 * topic's showcase, thumbnail downloads and resizing), so overlapping runs
	 * — hourly cron colliding with a manual Sync Now, or slow runs stacking up
	 * — are refused via a lock. The lock expires on its own so a fatally
	 * interrupted run can't wedge syncing for good.
	 *
	 * @return true|WP_Error
	 */
	public static function run() {
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'svc_sync_running', __( 'A sync is already running — skipped this one.', 'parish-video-center' ) );
		}
		set_transient( self::LOCK, time(), 10 * MINUTE_IN_SECONDS );

		$result = self::run_unlocked();

		delete_transient( self::LOCK );

		return $result;
	}

	/**
	 * The sync itself; callers must hold the lock (see run()).
	 *
	 * @return true|WP_Error
	 */
	private static function run_unlocked() {
		$settings = svc_get_settings();

		// Showcase-backed topics drive the sync; a site not yet using topics
		// falls back to the legacy single-showcase setting (no term handling).
		$topics = SVC_Topics::synced_topics();
		$legacy = empty( $topics );
		if ( $legacy ) {
			$topics = array(
				array(
					'term_id'     => 0,
					'showcase_id' => $settings['showcase_id'],
					'name'        => '',
				),
			);
		}

		// Fetch every showcase up front. Any failure aborts the whole run:
		// proceeding would draft all videos of the showcase that didn't load.
		$videos_by_id = array();
		$terms_by_id  = array();

		foreach ( $topics as $topic ) {
			$videos = SVC_Vimeo_API::fetch_showcase_videos( $topic['showcase_id'] );
			if ( is_wp_error( $videos ) ) {
				$message = $topic['name']
					? sprintf( '%s: %s', $topic['name'], $videos->get_error_message() )
					: $videos->get_error_message();
				update_option(
					'svc_last_sync',
					array(
						'time'    => time(),
						'status'  => 'error',
						'message' => $message,
					),
					false
				);
				return $videos;
			}

			foreach ( $videos as $video ) {
				$vimeo_id = self::extract_id( isset( $video['uri'] ) ? $video['uri'] : '' );
				if ( ! $vimeo_id ) {
					continue;
				}
				if ( ! isset( $videos_by_id[ $vimeo_id ] ) ) {
					$videos_by_id[ $vimeo_id ] = $video;
				}
				if ( $topic['term_id'] ) {
					$terms_by_id[ $vimeo_id ][] = $topic['term_id'];
				}
			}
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

		$created     = 0;
		$updated     = 0;
		$drafted     = 0;
		$locked      = 0;
		$thumbnails  = 0;
		$seen        = array();
		$synced_term_ids = $legacy ? array() : SVC_Topics::synced_term_ids();

		foreach ( $videos_by_id as $vimeo_id => $video ) {
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

			// Direct progressive .mp4 link (Vimeo plans with file links only):
			// becomes schema contentUrl / sitemap content_loc, Google's
			// preferred fetch target for video indexing. Refreshed every sync
			// so the signed URL stays current; cleared if the plan loses it.
			$file_url = self::progressive_file( $video );
			if ( $file_url ) {
				update_post_meta( $post_id, '_vimeo_file_url', $file_url );
			} else {
				delete_post_meta( $post_id, '_vimeo_file_url' );
			}

			// Topic membership: sync owns the showcase-backed topics — replace
			// those — but never touches manually curated ones.
			if ( ! $legacy ) {
				$current = wp_get_object_terms( $post_id, SVC_Topics::TAXONOMY, array( 'fields' => 'ids' ) );
				$manual  = is_wp_error( $current )
					? array()
					: array_diff( array_map( 'intval', $current ), $synced_term_ids );
				$wanted  = isset( $terms_by_id[ $vimeo_id ] ) ? $terms_by_id[ $vimeo_id ] : array();
				wp_set_object_terms(
					$post_id,
					array_values( array_unique( array_merge( $manual, $wanted ) ) ),
					SVC_Topics::TAXONOMY
				);
			}

			// Cap sideloads per run: each one is a download plus image
			// resizing, and a new showcase would otherwise do them all in one
			// worker-hogging request. Videos over the cap keep their unchanged
			// _vimeo_thumbnail_src and are picked up on subsequent runs.
			$max_sideloads = (int) apply_filters( 'svc_max_thumbnail_sideloads', 10 );

			if ( $thumbnail && $thumbnails < $max_sideloads && get_post_meta( $post_id, '_vimeo_thumbnail_src', true ) !== $thumbnail ) {
				$attachment_id = self::sideload_thumbnail( $thumbnail, $post_id, $title, $vimeo_id );
				if ( $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
					update_post_meta( $post_id, '_vimeo_thumbnail_src', $thumbnail );
					$thumbnails++;
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
					__( '%1$d videos across %6$d showcase(s): %2$d created, %3$d updated, %4$d unpublished, %5$d locked.', 'parish-video-center' ),
					count( $videos_by_id ),
					$created,
					$updated,
					$drafted,
					$locked,
					count( $topics )
				),
			),
			false
		);

		// Sync writes posts programmatically, which page caches don't notice.
		if ( $created + $updated + $drafted + $thumbnails > 0 ) {
			SVC_Cache::purge_all();
		}

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
	 * Highest-resolution progressive .mp4 link from the video's files list,
	 * or '' when the account's plan/token doesn't expose file links.
	 */
	private static function progressive_file( $video ) {
		if ( empty( $video['files'] ) || ! is_array( $video['files'] ) ) {
			return '';
		}

		$best        = '';
		$best_height = -1;

		foreach ( $video['files'] as $file ) {
			if ( ! is_array( $file ) || empty( $file['link'] ) ) {
				continue;
			}

			// Progressive downloads only — playlist formats aren't a valid
			// contentUrl target.
			$quality = isset( $file['quality'] ) ? $file['quality'] : '';
			if ( in_array( $quality, array( 'hls', 'dash' ), true ) ) {
				continue;
			}

			$type = isset( $file['type'] ) ? $file['type'] : '';
			if ( $type && false === strpos( $type, 'mp4' ) ) {
				continue;
			}

			$height = isset( $file['height'] ) ? (int) $file['height'] : 0;
			if ( $height > $best_height ) {
				$best_height = $height;
				$best        = $file['link'];
			}
		}

		return $best;
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

		// 30s cap — the default is 300s, which would hold a PHP worker for
		// five minutes per stalled CDN connection.
		$tmp = download_url( $url, 30 );
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
