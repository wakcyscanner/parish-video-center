<?php
/**
 * Deep links: /<archive-slug>/?v=<vimeo-id> 301s to the matching video permalink.
 * (Also covers legacy links from embeds that addressed videos by Vimeo ID.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Redirects {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'deep_link' ) );
	}

	public static function deep_link() {
		if ( ! is_post_type_archive( SVC_Post_Type::POST_TYPE ) || empty( $_GET['v'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$vimeo_id = preg_replace( '/\D/', '', wp_unslash( $_GET['v'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $vimeo_id ) {
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => '_vimeo_id',
				'meta_value'     => $vimeo_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( $posts ) {
			wp_safe_redirect( get_permalink( $posts[0] ), 301 );
			exit;
		}
	}
}
