<?php
/**
 * Thin Vimeo API client for fetching showcase (album) videos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Vimeo_API {

	/**
	 * Fetch all videos in a showcase, newest first.
	 *
	 * @param string $showcase_id Vimeo showcase/album ID.
	 * @return array|WP_Error Array of video data arrays, or WP_Error on failure.
	 */
	public static function fetch_showcase_videos( $showcase_id ) {
		$token = svc_get_token();
		if ( ! $token ) {
			return new WP_Error( 'svc_no_token', __( 'No Vimeo token configured.', 'stpacc-video-center' ) );
		}

		$videos = array();
		$page   = 1;

		do {
			$url = add_query_arg(
				array(
					'sort'      => 'date',
					'direction' => 'desc',
					'per_page'  => 50,
					'page'      => $page,
					'fields'    => 'uri,name,description,created_time,duration,pictures.sizes',
				),
				'https://api.vimeo.com/albums/' . rawurlencode( $showcase_id ) . '/videos'
			);

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error(
					'svc_http_' . $code,
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vimeo API returned HTTP %d.', 'stpacc-video-center' ),
						$code
					)
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				return new WP_Error( 'svc_bad_response', __( 'Unexpected Vimeo API response.', 'stpacc-video-center' ) );
			}

			$videos = array_merge( $videos, $body['data'] );
			$total  = isset( $body['total'] ) ? (int) $body['total'] : count( $videos );
			$page++;
		} while ( count( $videos ) < $total && count( $body['data'] ) > 0 && $page <= 20 );

		return $videos;
	}
}
