<?php
/**
 * Server-rendered JSON-LD: VideoObject on single homilies, ItemList on the archive.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Structured_Data {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ) );
	}

	public static function output() {
		if ( is_singular( SVC_Post_Type::POST_TYPE ) ) {
			self::video_object();
		} elseif ( is_post_type_archive( SVC_Post_Type::POST_TYPE ) ) {
			self::item_list();
		}
	}

	private static function video_object() {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$vimeo_id = get_post_meta( $post->ID, '_vimeo_id', true );
		if ( ! $vimeo_id ) {
			return;
		}

		$settings = svc_get_settings();

		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'VideoObject',
			'name'            => get_the_title( $post ),
			'description'     => wp_strip_all_tags( $post->post_content ),
			'uploadDate'      => get_post_time( 'c', true, $post ),
			'embedUrl'        => 'https://player.vimeo.com/video/' . $vimeo_id,
			'url'             => get_permalink( $post ),
			'publisher'       => array(
				'@type' => 'Organization',
				'name'  => $settings['publisher'],
			),
			'potentialAction' => array(
				'@type'  => 'WatchAction',
				'target' => array( get_permalink( $post ) ),
			),
		);

		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );
		if ( $thumbnail ) {
			$data['thumbnailUrl'] = array( $thumbnail );
		}

		$duration = svc_format_duration( get_post_meta( $post->ID, '_vimeo_duration', true ) );
		if ( $duration ) {
			$data['duration'] = $duration;
		}

		self::print_jsonld( $data );
	}

	private static function item_list() {
		global $wp_query;

		$items    = array();
		$position = 1;

		foreach ( $wp_query->posts as $item_post ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'url'      => get_permalink( $item_post ),
			);
		}

		if ( ! $items ) {
			return;
		}

		self::print_jsonld(
			array(
				'@context'        => 'https://schema.org',
				'@type'           => 'ItemList',
				'itemListElement' => $items,
			)
		);
	}

	private static function print_jsonld( $data ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) . "</script>\n";
	}
}
