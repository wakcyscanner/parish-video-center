<?php
/**
 * Open Graph / Twitter meta on single video pages, so shared links unfurl as
 * playable video cards in social feeds, chat apps, and newsletters.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Social_Meta {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 5 );
	}

	public static function output() {
		if ( ! is_singular( SVC_Post_Type::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$player = SVC_Video::embed_url( $post->ID );
		if ( ! $player ) {
			return;
		}

		$title       = get_the_title( $post );
		$description = '' !== trim( (string) $post->post_content )
			? wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 )
			: $title;
		$image       = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'full' );

		// The video's real shape: a tall video announced as 1280×720 gets a
		// wide, letterboxed card wherever the link is shared.
		list( $width, $height ) = SVC_Video::dimensions( $post->ID );

		$tags = array();

		// SEO plugins already emit the basic og:/twitter: tags; emitting them
		// twice confuses scrapers, so add only the video tags they don't know
		// about. Without an SEO plugin, emit the full set.
		if ( ! self::seo_plugin_active() ) {
			$tags['og:type']        = 'video.other';
			$tags['og:title']       = $title;
			$tags['og:description'] = $description;
			$tags['og:url']         = get_permalink( $post );
			$tags['og:site_name']   = get_bloginfo( 'name' );
			if ( $image ) {
				$tags['og:image']        = $image[0];
				$tags['og:image:width']  = (string) $image[1];
				$tags['og:image:height'] = (string) $image[2];
			}
			$tags['twitter:card']          = 'player';
			$tags['twitter:title']         = $title;
			$tags['twitter:description']   = $description;
			if ( $image ) {
				$tags['twitter:image'] = $image[0];
			}
			$tags['twitter:player']        = $player;
			$tags['twitter:player:width']  = (string) $width;
			$tags['twitter:player:height'] = (string) $height;
		}

		$tags['og:video']            = $player;
		$tags['og:video:secure_url'] = $player;
		$tags['og:video:type']       = 'text/html';
		$tags['og:video:width']      = (string) $width;
		$tags['og:video:height']     = (string) $height;

		/**
		 * Filter the social meta tags before output. Return an empty array
		 * to suppress them entirely.
		 *
		 * @param array   $tags Tag name => content.
		 * @param WP_Post $post The video post.
		 */
		$tags = apply_filters( 'svc_social_meta', $tags, $post );

		foreach ( $tags as $name => $content ) {
			$attribute = 0 === strpos( $name, 'twitter:' ) ? 'name' : 'property';
			printf(
				'<meta %s="%s" content="%s">' . "\n",
				esc_attr( $attribute ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal 'name'/'property'.
				esc_attr( $name ),
				esc_attr( $content )
			);
		}
	}

	private static function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' )        // Yoast SEO.
			|| class_exists( 'RankMath' )        // Rank Math.
			|| defined( 'SEOPRESS_VERSION' )     // SEOPress.
			|| defined( 'AIOSEO_VERSION' );      // All in One SEO.
	}
}
