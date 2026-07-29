<?php
/**
 * REST endpoint serving a video's full description HTML.
 *
 * Single video pages render only the description's first line; the remainder
 * is deliberately absent from the served HTML (Google was classifying the
 * videos as supplementary to the text) and fetched from here when a visitor
 * clicks "Read more".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'parish-video-center/v1',
			'/description/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'description' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Full rendered description for one published video. Public content only —
	 * the same text a pre-1.12 page served inline.
	 */
	public static function description( $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post
			|| SVC_Post_Type::POST_TYPE !== $post->post_type
			|| 'publish' !== $post->post_status ) {
			return new WP_Error( 'svc_not_found', __( 'Video not found.', 'parish-video-center' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'html' => svc_render_description( $post ) ) );
	}
}
