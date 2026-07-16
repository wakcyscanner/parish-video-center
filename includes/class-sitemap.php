<?php
/**
 * Google video sitemap at /video-sitemap.xml, advertised in robots.txt.
 * Core's wp-sitemap.xml lists the video URLs but carries no video metadata;
 * Google's video extension needs a dedicated sitemap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Sitemap {

	const QUERY_VAR = 'svc_video_sitemap';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		// Priority 0: render and exit before redirect_canonical can 301 the URL.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 10, 2 );
	}

	public static function add_rewrite() {
		add_rewrite_rule( '^video-sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function robots( $output, $public ) {
		if ( $public ) {
			$output .= "\nSitemap: " . home_url( '/video-sitemap.xml' ) . "\n";
		}
		return $output;
	}

	public static function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		foreach ( $posts as $post ) {
			$vimeo_id = get_post_meta( $post->ID, '_vimeo_id', true );
			if ( ! $vimeo_id ) {
				continue;
			}

			// Google requires a thumbnail; fall back to the Vimeo CDN URL if
			// the sideloaded featured image is missing.
			$thumbnail = get_the_post_thumbnail_url( $post, 'full' );
			if ( ! $thumbnail ) {
				$thumbnail = get_post_meta( $post->ID, '_vimeo_thumbnail_src', true );
			}
			if ( ! $thumbnail ) {
				continue;
			}

			$title       = get_the_title( $post );
			$description = '' !== trim( (string) $post->post_content )
				? wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 )
				: $title;
			$duration    = (int) get_post_meta( $post->ID, '_vimeo_duration', true );

			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( get_permalink( $post ) ) . "</loc>\n";
			echo "\t\t<video:video>\n";
			echo "\t\t\t<video:thumbnail_loc>" . esc_url( $thumbnail ) . "</video:thumbnail_loc>\n";
			echo "\t\t\t<video:title>" . esc_xml( $title ) . "</video:title>\n";
			echo "\t\t\t<video:description>" . esc_xml( $description ) . "</video:description>\n";
			echo "\t\t\t<video:player_loc>" . esc_url( 'https://player.vimeo.com/video/' . rawurlencode( $vimeo_id ) ) . "</video:player_loc>\n";
			if ( $duration > 0 && $duration <= 28800 ) {
				echo "\t\t\t<video:duration>" . (int) $duration . "</video:duration>\n";
			}
			echo "\t\t\t<video:publication_date>" . esc_xml( get_post_time( 'c', true, $post ) ) . "</video:publication_date>\n";
			echo "\t\t</video:video>\n";
			echo "\t</url>\n";
		}

		echo '</urlset>' . "\n";
		exit;
	}
}
