<?php
/**
 * Legacy deep links: the old embed used /homilies/?v=<vimeo-id>.
 * 301 those to the matching homily permalink.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Redirects {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'legacy_deep_link' ) );
	}

	public static function legacy_deep_link() {
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
