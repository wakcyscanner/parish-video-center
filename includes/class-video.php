<?php
/**
 * Provider-neutral view of a video post. A post plays from Vimeo or from
 * Bunny Stream; everything that renders a player, schema, or sitemap entry
 * asks here instead of reading provider meta directly.
 *
 * Meta layout, per provider prefix (_vimeo_* / _bunny_*): id, duration,
 * file_url, thumbnail_src, width, height. A post may carry both sets (a video migrated from
 * Vimeo to Bunny keeps its Vimeo meta); _svc_provider names the one that
 * plays, so switching back is a one-field change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Video {

	const PROVIDERS     = array( 'vimeo', 'bunny' );
	const PROVIDER_META = '_svc_provider';

	/**
	 * The provider this post plays from, or '' when it has no video.
	 */
	public static function provider( $post_id ) {
		$chosen = get_post_meta( $post_id, self::PROVIDER_META, true );
		if ( in_array( $chosen, self::PROVIDERS, true ) && get_post_meta( $post_id, '_' . $chosen . '_id', true ) ) {
			return $chosen;
		}

		// Posts created before providers existed carry only Vimeo meta.
		foreach ( self::PROVIDERS as $candidate ) {
			if ( get_post_meta( $post_id, '_' . $candidate . '_id', true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Human label for a provider slug.
	 */
	public static function label( $provider ) {
		return 'bunny' === $provider ? __( 'Video library', 'parish-video-center' ) : 'Vimeo';
	}

	/**
	 * One piece of the active provider's meta (id, duration, file_url, thumbnail_src).
	 */
	private static function meta( $post_id, $key ) {
		$provider = self::provider( $post_id );

		return $provider ? get_post_meta( $post_id, '_' . $provider . '_' . $key, true ) : '';
	}

	public static function id( $post_id ) {
		return (string) self::meta( $post_id, 'id' );
	}

	public static function duration( $post_id ) {
		return (int) self::meta( $post_id, 'duration' );
	}

	/**
	 * Direct .mp4 URL (schema contentUrl / sitemap content_loc), or ''.
	 */
	public static function file_url( $post_id ) {
		return (string) self::meta( $post_id, 'file_url' );
	}

	/**
	 * The provider-hosted thumbnail the featured image was sideloaded from.
	 */
	public static function thumbnail_src( $post_id ) {
		return (string) self::meta( $post_id, 'thumbnail_src' );
	}

	/**
	 * The video's own frame size in pixels. Videos synced before the shape
	 * was recorded, and providers that don't report one, count as 16:9.
	 *
	 * @return int[] array{width, height}.
	 */
	public static function dimensions( $post_id ) {
		$width  = self::meta( $post_id, 'width' );
		$height = self::meta( $post_id, 'height' );

		// A video copied between providers is the same shape on both; use
		// the other one's record when the playing provider has none yet.
		if ( ! $width || ! $height ) {
			foreach ( self::PROVIDERS as $provider ) {
				$width  = get_post_meta( $post_id, '_' . $provider . '_width', true );
				$height = get_post_meta( $post_id, '_' . $provider . '_height', true );
				if ( $width && $height ) {
					break;
				}
			}
		}

		return self::clean_dimensions( $width, $height );
	}

	/**
	 * A usable width/height pair from whatever a provider reported.
	 *
	 * @return int[] array{width, height}.
	 */
	public static function clean_dimensions( $width, $height ) {
		$width  = (int) $width;
		$height = (int) $height;

		return $width > 0 && $height > 0 ? array( $width, $height ) : array( 1280, 720 );
	}

	/**
	 * Whether a frame is taller than it is wide (a phone-shaped video).
	 */
	public static function is_portrait( $dimensions ) {
		return $dimensions[1] > $dimensions[0];
	}

	/**
	 * class and style attributes that size a player box to a frame shape.
	 * Wide videos fill the column; tall ones are capped to the screen height
	 * (see .svc-player in video-center.css).
	 *
	 * @param int[]  $dimensions array{width, height}.
	 * @param string $classes    Classes the box always has.
	 * @return string Escaped attributes, with a leading space.
	 */
	public static function box_attributes( $dimensions, $classes = 'svc-player' ) {
		if ( self::is_portrait( $dimensions ) ) {
			$classes .= ' svc-portrait';
		}

		return sprintf( ' class="%s" style="--svc-ratio: %d / %d"', esc_attr( $classes ), $dimensions[0], $dimensions[1] );
	}

	/**
	 * Player iframe URL, or '' when the post has no video.
	 *
	 * The non-autoplay form is the canonical one: the single-page iframe src,
	 * JSON-LD embedUrl, sitemap player_loc and social tags must all name the
	 * same URL byte for byte.
	 *
	 * @param bool $autoplay Start playing as soon as the player loads.
	 * @param bool $muted    Start silent — the only autoplay phones allow
	 *                       when the visitor hasn't tapped anything yet.
	 */
	public static function embed_url( $post_id, $autoplay = false, $muted = false ) {
		$provider = self::provider( $post_id );
		$id       = self::id( $post_id );

		if ( 'vimeo' === $provider ) {
			return 'https://player.vimeo.com/video/' . rawurlencode( $id ) . '?' . ( $autoplay ? 'autoplay=1&' : '' ) . ( $muted ? 'muted=1&' : '' ) . 'dnt=1';
		}

		if ( 'bunny' === $provider ) {
			// The library the video was synced from, so a later settings
			// change can't break existing embeds.
			$library = get_post_meta( $post_id, '_bunny_library_id', true );
			if ( ! $library ) {
				$config  = svc_bunny_config();
				$library = $config['library_id'];
			}
			if ( ! $library ) {
				return '';
			}

			// Bunny's player autoplays unless told otherwise.
			return 'https://player.mediadelivery.net/embed/' . rawurlencode( $library ) . '/' . rawurlencode( $id )
				. '?autoplay=' . ( $autoplay ? 'true' : 'false' ) . ( $muted ? '&muted=true' : '' );
		}

		return '';
	}

	/**
	 * Origins worth preconnecting to before the provider's player loads.
	 */
	public static function origins( $provider ) {
		if ( 'bunny' === $provider ) {
			$origins = array( 'https://player.mediadelivery.net' );
			$config  = svc_bunny_config();
			if ( $config['cdn_host'] ) {
				$origins[] = 'https://' . $config['cdn_host'];
			}
			return $origins;
		}

		if ( 'vimeo' === $provider ) {
			return array( 'https://player.vimeo.com', 'https://i.vimeocdn.com', 'https://f.vimeocdn.com' );
		}

		return array();
	}
}
