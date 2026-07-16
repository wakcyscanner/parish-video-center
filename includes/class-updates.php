<?php
/**
 * Update checker: lets WordPress discover new versions from GitHub releases.
 *
 * The plugin header declares "Update URI: https://github.com/<repo>", so core
 * routes update checks for this plugin to the update_plugins_github.com
 * filter instead of wordpress.org. We answer it from the GitHub releases API
 * (cached), and core takes care of version comparison and the update UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Updates {

	const REPO      = 'wakcyscanner/parish-video-center';
	const ASSET     = 'parish-video-center.zip';
	const CACHE_KEY = 'svc_update_check';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'inject' ), 10, 3 );
	}

	/**
	 * Provide update info for this plugin. Core compares versions itself,
	 * so we always return the latest release when we have one.
	 */
	public static function inject( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( SVC_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $update;
		}

		return array(
			'id'      => 'github.com/' . self::REPO,
			'slug'    => 'parish-video-center',
			'version' => $release['version'],
			'url'     => 'https://github.com/' . self::REPO,
			'package' => $release['package'],
		);
	}

	/**
	 * Latest release version + installable asset URL, cached for 12 hours.
	 * Failures are cached too (as an empty array) so a GitHub outage doesn't
	 * add a slow request to every update check.
	 *
	 * @return array{version?: string, package?: string}
	 */
	private static function latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$release  = array();
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) && ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
				foreach ( $body['assets'] as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
						$release = array(
							'version' => ltrim( (string) $body['tag_name'], 'v' ),
							'package' => $asset['browser_download_url'],
						);
						break;
					}
				}
			}
		}

		set_transient( self::CACHE_KEY, $release, 12 * HOUR_IN_SECONDS );

		return $release;
	}
}
