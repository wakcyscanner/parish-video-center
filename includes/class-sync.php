<?php
/**
 * Sync engine: pulls Vimeo showcases and Bunny Stream collections into video
 * posts on a WP-Cron schedule.
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
	 * A run can hold a PHP worker for a while (API paging across every topic's
	 * showcase or collection, thumbnail downloads and resizing), so overlapping runs
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
	 * Each provider (Vimeo showcases, Bunny collections) syncs independently:
	 * one failing doesn't stop the other, but the run reports the failure.
	 *
	 * @return true|WP_Error
	 */
	private static function run_unlocked() {
		$messages = array();
		$errors   = array();
		$changes  = 0;

		// Cap sideloads per run, across providers: each one is a download plus
		// image resizing, and a new showcase/collection would otherwise do them
		// all in one worker-hogging request. Videos over the cap keep their
		// unchanged thumbnail_src meta and are picked up on subsequent runs.
		$sideload_budget = (int) apply_filters( 'svc_max_thumbnail_sideloads', 10 );

		foreach ( self::jobs() as $job ) {
			$label  = SVC_Video::label( $job['provider'] );
			$result = self::sync_provider( $job['provider'], $job['topics'], $job['legacy'], $sideload_budget );

			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( '%s: %s', $label, $result->get_error_message() );
				continue;
			}

			$messages[] = sprintf( '%s: %s', $label, $result['message'] );
			$changes   += $result['changes'];
		}

		update_option(
			'svc_last_sync',
			array(
				'time'    => time(),
				'status'  => $errors ? 'error' : 'ok',
				'message' => implode( ' ', array_merge( $errors, $messages ) ),
			),
			false
		);

		// Sync writes posts programmatically, which page caches don't notice.
		if ( $changes > 0 ) {
			SVC_Cache::purge_all();
		}

		return $errors ? new WP_Error( 'svc_sync_failed', implode( ' ', $errors ) ) : true;
	}

	/**
	 * What this run syncs: one job per provider that has source-backed topics.
	 * A site with no such topics at all falls back to the legacy single
	 * Vimeo showcase setting (no term handling).
	 *
	 * @return array[] Each: array{provider: string, topics: array[], legacy: bool}.
	 */
	private static function jobs() {
		$jobs = array();

		foreach ( SVC_Video::PROVIDERS as $provider ) {
			$topics = SVC_Topics::synced_topics( $provider );
			if ( $topics ) {
				$jobs[] = array(
					'provider' => $provider,
					'topics'   => $topics,
					'legacy'   => false,
				);
			}
		}

		if ( ! $jobs ) {
			$settings = svc_get_settings();
			$jobs[]   = array(
				'provider' => 'vimeo',
				'topics'   => array(
					array(
						'term_id'   => 0,
						'source_id' => $settings['showcase_id'],
						'name'      => '',
					),
				),
				'legacy'   => true,
			);
		}

		return $jobs;
	}

	/**
	 * Sync one provider: upsert posts, sideload changed thumbnails, draft
	 * videos that left their showcase/collection.
	 *
	 * @param string $provider        'vimeo' or 'bunny'.
	 * @param array  $topics          Source-backed topics for this provider.
	 * @param bool   $legacy          True for the pre-topics single-showcase config.
	 * @param int    $sideload_budget Thumbnail sideloads left in this run (decremented).
	 * @return array|WP_Error array{message: string, changes: int}, or WP_Error.
	 */
	private static function sync_provider( $provider, $topics, $legacy, &$sideload_budget ) {
		// Fetch every source up front. Any failure aborts this provider:
		// proceeding would draft all videos of the source that didn't load.
		$videos_by_id = array();
		$terms_by_id  = array();

		foreach ( $topics as $topic ) {
			$videos = self::fetch( $provider, $topic['source_id'] );
			if ( is_wp_error( $videos ) ) {
				return $topic['name']
					? new WP_Error( $videos->get_error_code(), sprintf( '%s: %s', $topic['name'], $videos->get_error_message() ) )
					: $videos;
			}

			foreach ( $videos as $video ) {
				if ( ! isset( $videos_by_id[ $video['id'] ] ) ) {
					$videos_by_id[ $video['id'] ] = $video;
				}
				if ( $topic['term_id'] ) {
					$terms_by_id[ $video['id'] ][] = $topic['term_id'];
				}
			}
		}

		$id_key = '_' . $provider . '_id';

		// Map existing posts by provider ID (any non-trashed status, so drafted
		// videos re-publish on return). Posts currently playing from the other
		// provider — e.g. migrated from Vimeo to Bunny — are not this
		// provider's to manage; remember them so they aren't re-created.
		$existing = array();
		$foreign  = array();
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
			$video_id = get_post_meta( $post_id, $id_key, true );
			if ( ! $video_id ) {
				continue;
			}
			if ( SVC_Video::provider( $post_id ) === $provider ) {
				$existing[ $video_id ] = $post_id;
			} else {
				$foreign[ $video_id ] = $post_id;
			}
		}

		$created        = 0;
		$updated        = 0;
		$drafted        = 0;
		$locked         = 0;
		$pending        = 0;
		$thumbnails     = 0;
		$seen           = array();
		$owned_term_ids = $legacy ? array() : SVC_Topics::synced_term_ids( $provider );

		foreach ( $videos_by_id as $video_id => $video ) {
			$seen[ $video_id ] = true;

			if ( isset( $foreign[ $video_id ] ) ) {
				continue;
			}

			// Still uploading/encoding: no post yet, but an existing post
			// stays as it is rather than being drafted.
			if ( ! $video['ready'] ) {
				$pending++;
				continue;
			}

			$postarr = array(
				'post_type'    => SVC_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $video['title'],
				'post_content' => $video['description'],
			);

			if ( $video['created'] ) {
				$timestamp = strtotime( $video['created'] );
				if ( $timestamp ) {
					$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
					$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
				}
			}

			if ( isset( $existing[ $video_id ] ) ) {
				$post_id = $existing[ $video_id ];

				// Editor opted this post out of sync — leave it entirely untouched.
				if ( get_post_meta( $post_id, '_svc_sync_lock', true ) ) {
					$locked++;
					continue;
				}

				$post = get_post( $post_id );

				// A video moved over from Vimeo keeps its original date; the
				// library only knows the day it was copied.
				if ( 'bunny' === $provider && get_post_meta( $post_id, '_vimeo_id', true ) ) {
					unset( $postarr['post_date'], $postarr['post_date_gmt'] );
				}

				$changed = $post
					&& ( $post->post_title !== $video['title']
						|| $post->post_content !== $video['description']
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
				update_post_meta( $post_id, SVC_Video::PROVIDER_META, $provider );
				$created++;
			}

			update_post_meta( $post_id, $id_key, $video_id );
			update_post_meta( $post_id, '_' . $provider . '_duration', $video['duration'] );

			// Frame shape, so players and share pictures fit tall videos.
			if ( $video['width'] && $video['height'] ) {
				update_post_meta( $post_id, '_' . $provider . '_width', $video['width'] );
				update_post_meta( $post_id, '_' . $provider . '_height', $video['height'] );
			}

			if ( 'bunny' === $provider ) {
				update_post_meta( $post_id, '_bunny_library_id', $video['library_id'] );
				// Marks the post as collection-managed: only these are
				// drafted when their video leaves every linked collection.
				update_post_meta( $post_id, '_bunny_synced', '1' );
			}

			// Direct .mp4 link: becomes schema contentUrl / sitemap
			// content_loc, Google's preferred fetch target for video indexing.
			// Refreshed every sync (Vimeo's are signed and expire); cleared
			// when the provider stops offering one.
			if ( $video['file_url'] ) {
				update_post_meta( $post_id, '_' . $provider . '_file_url', $video['file_url'] );
			} else {
				delete_post_meta( $post_id, '_' . $provider . '_file_url' );
			}

			// Topic membership: sync owns this provider's source-backed topics
			// — replace those — but never touches manually curated ones or
			// the other provider's.
			if ( ! $legacy ) {
				$current = wp_get_object_terms( $post_id, SVC_Topics::TAXONOMY, array( 'fields' => 'ids' ) );
				$manual  = is_wp_error( $current )
					? array()
					: array_diff( array_map( 'intval', $current ), $owned_term_ids );
				$wanted  = isset( $terms_by_id[ $video_id ] ) ? $terms_by_id[ $video_id ] : array();
				wp_set_object_terms(
					$post_id,
					array_values( array_unique( array_merge( $manual, $wanted ) ) ),
					SVC_Topics::TAXONOMY
				);
			}

			$thumb_key = '_' . $provider . '_thumbnail_src';

			if ( $video['thumbnail'] && $sideload_budget > 0 && get_post_meta( $post_id, $thumb_key, true ) !== $video['thumbnail'] ) {
				$attachment_id = self::sideload_thumbnail( $video['thumbnail'], $post_id, $video['title'], $provider . '-' . $video_id );
				if ( $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
					update_post_meta( $post_id, $thumb_key, $video['thumbnail'] );
					$thumbnails++;
					$sideload_budget--;
				}
			}
		}

		// Videos that left the showcase/collection get unpublished, not deleted.
		foreach ( $existing as $video_id => $post_id ) {
			if ( isset( $seen[ $video_id ] )
				|| 'publish' !== get_post_status( $post_id )
				|| get_post_meta( $post_id, '_svc_sync_lock', true ) ) {
				continue;
			}
			// A Bunny post that never came from a linked collection (e.g. a
			// migrated video) isn't sync's to unpublish.
			if ( 'bunny' === $provider && ! get_post_meta( $post_id, '_bunny_synced', true ) ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			$drafted++;
		}

		$message = sprintf(
			/* translators: 1: total videos, 2: created, 3: updated, 4: unpublished, 5: locked, 6: number of showcases/collections */
			__( '%1$d videos across %6$d source(s): %2$d created, %3$d updated, %4$d unpublished, %5$d locked.', 'parish-video-center' ),
			count( $videos_by_id ),
			$created,
			$updated,
			$drafted,
			$locked,
			count( $topics )
		);
		if ( $pending ) {
			/* translators: %d: number of videos */
			$message .= ' ' . sprintf( __( '%d still processing.', 'parish-video-center' ), $pending );
		}

		return array(
			'message' => $message,
			'changes' => $created + $updated + $drafted + $thumbnails,
		);
	}

	/**
	 * Fetch one source's videos in provider-neutral form.
	 *
	 * @return array[]|WP_Error Each: array{id, title, description, created,
	 *                          duration, width, height, thumbnail, file_url,
	 *                          ready, library_id}.
	 */
	private static function fetch( $provider, $source_id ) {
		$raw = 'bunny' === $provider
			? SVC_Bunny_API::list_videos( $source_id )
			: SVC_Vimeo_API::fetch_showcase_videos( $source_id );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$videos = array();
		foreach ( $raw as $video ) {
			$normalized = 'bunny' === $provider ? self::normalize_bunny( $video ) : self::normalize_vimeo( $video );
			if ( $normalized ) {
				$videos[] = $normalized;
			}
		}

		return $videos;
	}

	private static function normalize_vimeo( $video ) {
		$id = self::extract_id( isset( $video['uri'] ) ? $video['uri'] : '' );
		if ( ! $id ) {
			return null;
		}

		return array(
			'id'          => $id,
			'title'       => isset( $video['name'] ) ? (string) $video['name'] : '',
			'description' => isset( $video['description'] ) ? (string) $video['description'] : '',
			'created'     => isset( $video['created_time'] ) ? (string) $video['created_time'] : '',
			'duration'    => isset( $video['duration'] ) ? (int) $video['duration'] : 0,
			'width'       => isset( $video['width'] ) ? (int) $video['width'] : 0,
			'height'      => isset( $video['height'] ) ? (int) $video['height'] : 0,
			'thumbnail'   => self::largest_thumbnail( $video ),
			'file_url'    => self::progressive_file( $video ),
			'ready'       => true,
			'library_id'  => '',
		);
	}

	/**
	 * A raw library video in provider-neutral form (see fetch()).
	 */
	public static function normalize_bunny( $video ) {
		if ( empty( $video['guid'] ) ) {
			return null;
		}

		$config = svc_bunny_config();
		$guid   = (string) $video['guid'];
		$base   = $config['cdn_host'] ? 'https://' . $config['cdn_host'] . '/' . $guid . '/' : '';

		$thumbnail = '';
		if ( ! empty( $video['thumbnailUrl'] ) ) {
			$thumbnail = (string) $video['thumbnailUrl'];
		} elseif ( $base && ! empty( $video['thumbnailFileName'] ) ) {
			$thumbnail = $base . $video['thumbnailFileName'];
		}

		// MP4 fallback (a library encoding setting) is the only fixed-URL
		// progressive file Bunny serves. 720p first: a crawler doesn't need
		// more, and it keeps bandwidth down.
		$file_url = '';
		if ( $base && ! empty( $video['hasMP4Fallback'] ) && ! empty( $video['availableResolutions'] ) ) {
			$available = array_map( 'trim', explode( ',', (string) $video['availableResolutions'] ) );
			foreach ( array( '720p', '360p', '240p' ) as $resolution ) {
				if ( in_array( $resolution, $available, true ) ) {
					$file_url = $base . 'play_' . $resolution . '.mp4';
					break;
				}
			}
		}

		return array(
			'id'          => $guid,
			'title'       => isset( $video['title'] ) ? (string) $video['title'] : '',
			'description' => isset( $video['description'] ) ? (string) $video['description'] : '',
			'created'     => isset( $video['dateUploaded'] ) ? (string) $video['dateUploaded'] : '',
			'duration'    => isset( $video['length'] ) ? (int) $video['length'] : 0,
			'width'       => isset( $video['width'] ) ? (int) $video['width'] : 0,
			'height'      => isset( $video['height'] ) ? (int) $video['height'] : 0,
			'thumbnail'   => $thumbnail,
			'file_url'    => (string) apply_filters( 'svc_bunny_file_url', $file_url, $video ),
			'ready'       => isset( $video['status'] ) && SVC_Bunny_API::STATUS_FINISHED === (int) $video['status'],
			'library_id'  => isset( $video['videoLibraryId'] ) ? (string) $video['videoLibraryId'] : $config['library_id'],
		);
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
	 *
	 * Every listed size is 16:9, so a tall video's picture comes padded with
	 * black bars at the sides. For those, ask Vimeo's image server for the
	 * picture in the video's own shape instead.
	 */
	private static function largest_thumbnail( $video ) {
		$width  = isset( $video['width'] ) ? (int) $video['width'] : 0;
		$height = isset( $video['height'] ) ? (int) $video['height'] : 0;

		if ( $height > $width && $width > 0 && ! empty( $video['pictures']['base_link'] ) ) {
			$scale = min( 1, 1920 / $height );
			$parts = explode( '?', (string) $video['pictures']['base_link'], 2 );

			return $parts[0] . '_' . (int) round( $width * $scale ) . 'x' . (int) round( $height * $scale )
				. ( isset( $parts[1] ) ? '?' . $parts[1] : '' );
		}

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
	private static function sideload_thumbnail( $url, $post_id, $title, $basename ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// 30s cap — the default is 300s, which would hold a PHP worker for
		// five minutes per stalled CDN connection.
		//
		// Send this site as the Referer: a Bunny library with "block direct
		// URL file access" on answers referrer-less requests with 403.
		$add_referer = function ( $args ) {
			$args['headers']            = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
			$args['headers']['Referer'] = home_url( '/' );
			return $args;
		};
		add_filter( 'http_request_args', $add_referer );
		$tmp = download_url( $url, 30 );
		remove_filter( 'http_request_args', $add_referer );

		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$file_array = array(
			'name'     => $basename . '.jpg',
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
