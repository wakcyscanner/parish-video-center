<?php
/**
 * Bunny Stream API client (video.bunnycdn.com), scoped to one video library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Bunny_API {

	const BASE = 'https://video.bunnycdn.com/library/';

	// Bunny's VideoModel status values.
	const STATUS_FINISHED = 4;

	/**
	 * Every video in the library, or in one collection, newest first.
	 *
	 * @param string $collection_id Collection GUID; '' for the whole library.
	 * @return array|WP_Error Array of Bunny video arrays, or WP_Error.
	 */
	public static function list_videos( $collection_id = '' ) {
		$query = array( 'orderBy' => 'date' );
		if ( '' !== $collection_id ) {
			$query['collection'] = $collection_id;
		}

		return self::list_all( 'videos', $query );
	}

	/**
	 * Every collection in the library.
	 *
	 * @return array|WP_Error Array of Bunny collection arrays, or WP_Error.
	 */
	public static function list_collections() {
		return self::list_all( 'collections', array( 'orderBy' => 'title' ) );
	}

	/**
	 * Collections as guid => name, cached briefly for admin form dropdowns.
	 *
	 * @return array|WP_Error
	 */
	public static function collection_names() {
		$cached = get_transient( 'svc_bunny_collections' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$collections = self::list_collections();
		if ( is_wp_error( $collections ) ) {
			return $collections;
		}

		$names = array();
		foreach ( $collections as $collection ) {
			if ( ! empty( $collection['guid'] ) ) {
				$names[ $collection['guid'] ] = isset( $collection['name'] ) ? (string) $collection['name'] : $collection['guid'];
			}
		}

		set_transient( 'svc_bunny_collections', $names, 5 * MINUTE_IN_SECONDS );

		return $names;
	}

	/**
	 * Lightweight connectivity check.
	 *
	 * @return int|WP_Error Total number of videos in the library, or WP_Error.
	 */
	public static function test_connection() {
		$body = self::request(
			'GET',
			'videos',
			array(
				'page'         => 1,
				'itemsPerPage' => 10,
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return isset( $body['totalItems'] ) ? (int) $body['totalItems'] : 0;
	}

	/**
	 * Whether a value looks like a Bunny GUID (video or collection ID).
	 */
	public static function is_guid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * One page of videos for the library manager.
	 *
	 * @param array $args page, per_page, search, collection.
	 * @return array|WP_Error Bunny's list envelope (totalItems, items, …), or WP_Error.
	 */
	public static function list_page( $args ) {
		$query = array(
			'page'         => max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ),
			'itemsPerPage' => min( 100, max( 10, isset( $args['per_page'] ) ? (int) $args['per_page'] : 25 ) ),
			'orderBy'      => 'date',
		);
		if ( ! empty( $args['search'] ) ) {
			$query['search'] = (string) $args['search'];
		}
		if ( ! empty( $args['collection'] ) ) {
			$query['collection'] = (string) $args['collection'];
		}

		$body = self::request( 'GET', 'videos', $query );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! isset( $body['items'] ) || ! is_array( $body['items'] ) ) {
			$body['items'] = array();
		}

		return $body;
	}

	/**
	 * A single video.
	 *
	 * @return array|WP_Error
	 */
	public static function get_video( $guid ) {
		return self::request( 'GET', 'videos/' . rawurlencode( $guid ) );
	}

	/**
	 * Create an empty video entry that a file can then be uploaded into.
	 *
	 * @return array|WP_Error The new video (with its guid), or WP_Error.
	 */
	public static function create_video( $title, $collection_id = '' ) {
		$body = array( 'title' => (string) $title );
		if ( '' !== $collection_id ) {
			$body['collectionId'] = $collection_id;
		}

		return self::request( 'POST', 'videos', array(), $body );
	}

	/**
	 * Update a video's title, description and/or collection.
	 *
	 * Bunny stores the description as a meta tag and replaces the whole meta tag
	 * list on update, so the current tags are read first and carried over.
	 *
	 * @param string $guid   Video GUID.
	 * @param array  $fields Any of: title, description, collection ('' = no collection).
	 * @return array|WP_Error
	 */
	public static function update_video( $guid, $fields ) {
		$body = array();

		if ( isset( $fields['title'] ) ) {
			$body['title'] = (string) $fields['title'];
		}
		if ( isset( $fields['collection'] ) ) {
			$body['collectionId'] = (string) $fields['collection'];
		}
		if ( isset( $fields['description'] ) ) {
			$current = self::get_video( $guid );
			if ( is_wp_error( $current ) ) {
				return $current;
			}

			$tags = array();
			foreach ( isset( $current['metaTags'] ) && is_array( $current['metaTags'] ) ? $current['metaTags'] : array() as $tag ) {
				if ( isset( $tag['property'] ) && 'description' !== $tag['property'] ) {
					$tags[] = $tag;
				}
			}
			if ( '' !== trim( (string) $fields['description'] ) ) {
				$tags[] = array(
					'property' => 'description',
					'value'    => (string) $fields['description'],
				);
			}
			$body['metaTags'] = $tags;
		}

		if ( ! $body ) {
			return array();
		}

		return self::request( 'POST', 'videos/' . rawurlencode( $guid ), array(), $body );
	}

	/**
	 * Have the library fetch a video file from a URL into a new video.
	 *
	 * @return string|WP_Error The new video's GUID, or WP_Error.
	 */
	public static function fetch_video( $url, $title, $collection_id = '' ) {
		$result = self::request(
			'POST',
			'videos/fetch',
			'' !== $collection_id ? array( 'collectionId' => $collection_id ) : array(),
			array(
				'url'   => (string) $url,
				'title' => (string) $title,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['id'] ) || ! self::is_guid( (string) $result['id'] ) ) {
			return new WP_Error( 'svc_bunny_bad_response', __( 'The video library accepted the copy but did not say which video it created.', 'parish-video-center' ) );
		}

		return strtolower( (string) $result['id'] );
	}

	/**
	 * Permanently delete a video and its files.
	 *
	 * @return array|WP_Error
	 */
	public static function delete_video( $guid ) {
		return self::request( 'DELETE', 'videos/' . rawurlencode( $guid ) );
	}

	/**
	 * Replace a video's thumbnail with an uploaded image.
	 *
	 * @param string $guid  Video GUID.
	 * @param string $bytes Raw image data (JPEG, PNG or WebP).
	 * @return array|WP_Error
	 */
	public static function set_thumbnail( $guid, $bytes ) {
		return self::request( 'POST', 'videos/' . rawurlencode( $guid ) . '/thumbnail', array(), (string) $bytes );
	}

	/**
	 * @return array|WP_Error The new collection (with its guid), or WP_Error.
	 */
	public static function create_collection( $name ) {
		delete_transient( 'svc_bunny_collections' );

		return self::request( 'POST', 'collections', array(), array( 'name' => (string) $name ) );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function rename_collection( $guid, $name ) {
		delete_transient( 'svc_bunny_collections' );

		return self::request( 'POST', 'collections/' . rawurlencode( $guid ), array(), array( 'name' => (string) $name ) );
	}

	/**
	 * Delete a collection. Bunny deletes the videos inside it too.
	 *
	 * @return array|WP_Error
	 */
	public static function delete_collection( $guid ) {
		delete_transient( 'svc_bunny_collections' );

		return self::request( 'DELETE', 'collections/' . rawurlencode( $guid ) );
	}

	/**
	 * Short-lived credentials that let a browser upload one video's file
	 * straight to Bunny (resumable TUS upload) without seeing the API key.
	 *
	 * @return array Headers for the TUS requests.
	 */
	public static function upload_credentials( $guid ) {
		$config = svc_bunny_config();
		$expire = time() + DAY_IN_SECONDS;

		return array(
			'AuthorizationSignature' => hash( 'sha256', $config['library_id'] . $config['api_key'] . $expire . $guid ),
			'AuthorizationExpire'    => (string) $expire,
			'VideoId'                => $guid,
			'LibraryId'              => $config['library_id'],
		);
	}

	/**
	 * Walk a paginated list endpoint to the end.
	 *
	 * @return array|WP_Error
	 */
	private static function list_all( $path, $query ) {
		$items = array();
		$page  = 1;

		do {
			$query['page']         = $page;
			$query['itemsPerPage'] = 100;

			$body = self::request( 'GET', $path, $query );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			if ( ! isset( $body['items'] ) || ! is_array( $body['items'] ) ) {
				// An empty list comes back as items: null.
				$body['items'] = array();
			}

			$items = array_merge( $items, $body['items'] );
			$total = isset( $body['totalItems'] ) ? (int) $body['totalItems'] : count( $items );
			$page++;
		} while ( count( $items ) < $total && count( $body['items'] ) > 0 && $page <= 50 );

		return $items;
	}

	/**
	 * One authenticated request against the configured library.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under /library/{id}/, e.g. 'videos'.
	 * @param array      $query  Query args.
	 * @param array|string|null $body JSON body for write requests; a string is sent as raw binary.
	 * @return array|WP_Error Decoded JSON body, or WP_Error.
	 */
	public static function request( $method, $path, $query = array(), $body = null ) {
		$config = svc_bunny_config();
		if ( ! $config['api_key'] ) {
			return new WP_Error( 'svc_bunny_no_key', __( 'No Bunny Stream API key configured.', 'parish-video-center' ) );
		}
		if ( ! $config['library_id'] ) {
			return new WP_Error( 'svc_bunny_no_library', __( 'No Bunny Stream library ID configured.', 'parish-video-center' ) );
		}

		$url = self::BASE . rawurlencode( $config['library_id'] ) . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'AccessKey' => $config['api_key'],
				'Accept'    => 'application/json',
			),
		);
		if ( is_string( $body ) ) {
			// Raw binary upload (thumbnails).
			$args['headers']['Content-Type'] = 'application/octet-stream';
			$args['body']                    = $body;
			$args['timeout']                 = 60;
		} elseif ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'svc_bunny_http_' . $code, self::http_error_message( $code ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'svc_bunny_bad_response', __( 'Unexpected response from the video library.', 'parish-video-center' ) );
		}

		return $decoded;
	}

	/**
	 * Human-readable message for a failed Bunny response.
	 */
	private static function http_error_message( $code ) {
		switch ( $code ) {
			case 401:
			case 403:
				return sprintf(
					/* translators: %d: HTTP status code */
					__( 'Bunny Stream rejected the API key (HTTP %d). Use the key from the library\'s API page (Stream → your library → API), not the account-wide key.', 'parish-video-center' ),
					$code
				);
			case 404:
				return __( 'Video library or item not found (HTTP 404). Check the library ID.', 'parish-video-center' );
			case 429:
				return __( 'The video library is rate-limiting requests (HTTP 429). Wait a few minutes and try again.', 'parish-video-center' );
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'The video library returned HTTP %d.', 'parish-video-center' ),
			$code
		);
	}
}
