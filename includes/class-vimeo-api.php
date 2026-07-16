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
		$videos = array();
		$page   = 1;

		do {
			$body = self::request(
				$showcase_id,
				array(
					'sort'      => 'date',
					'direction' => 'desc',
					'per_page'  => 50,
					'page'      => $page,
					'fields'    => 'uri,name,description,created_time,duration,pictures.sizes',
				)
			);

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$videos = array_merge( $videos, $body['data'] );
			$total  = isset( $body['total'] ) ? (int) $body['total'] : count( $videos );
			$page++;
		} while ( count( $videos ) < $total && count( $body['data'] ) > 0 && $page <= 20 );

		return $videos;
	}

	/**
	 * Lightweight connectivity check: one video, one field.
	 *
	 * @param string $showcase_id Vimeo showcase/album ID.
	 * @return int|WP_Error Total number of videos in the showcase, or WP_Error.
	 */
	public static function test_connection( $showcase_id ) {
		$body = self::request(
			$showcase_id,
			array(
				'per_page' => 1,
				'fields'   => 'uri',
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return isset( $body['total'] ) ? (int) $body['total'] : count( $body['data'] );
	}

	/**
	 * One authenticated GET against the showcase videos endpoint.
	 *
	 * @param string $showcase_id Vimeo showcase/album ID.
	 * @param array  $query       Query args for the endpoint.
	 * @return array|WP_Error Decoded body (with a data array), or WP_Error.
	 */
	private static function request( $showcase_id, $query ) {
		$token = svc_get_token();
		if ( ! $token ) {
			return new WP_Error( 'svc_no_token', __( 'No Vimeo access token configured.', 'parish-video-center' ) );
		}

		$showcase_id = preg_replace( '/\D/', '', (string) $showcase_id );
		if ( ! $showcase_id ) {
			return new WP_Error( 'svc_no_showcase', __( 'No Vimeo showcase ID configured.', 'parish-video-center' ) );
		}

		$url = add_query_arg( $query, 'https://api.vimeo.com/albums/' . rawurlencode( $showcase_id ) . '/videos' );

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
			return new WP_Error( 'svc_http_' . $code, self::http_error_message( $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'svc_bad_response', __( 'Unexpected Vimeo API response.', 'parish-video-center' ) );
		}

		return $body;
	}

	/**
	 * Human-readable message for a non-200 Vimeo response.
	 */
	private static function http_error_message( $code ) {
		switch ( $code ) {
			case 401:
				return __( 'Vimeo rejected the access token (HTTP 401). Check that the token is correct and has not been revoked.', 'parish-video-center' );
			case 403:
				return __( 'Vimeo denied access (HTTP 403). The token needs the "public" and "private" scopes and must belong to the account that owns the showcase.', 'parish-video-center' );
			case 404:
				return __( 'Showcase not found (HTTP 404). Check the showcase ID and that the token belongs to the account that owns it.', 'parish-video-center' );
			case 429:
				return __( 'Vimeo rate limit reached (HTTP 429). Wait a few minutes and try again.', 'parish-video-center' );
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Vimeo API returned HTTP %d.', 'parish-video-center' ),
			$code
		);
	}
}
