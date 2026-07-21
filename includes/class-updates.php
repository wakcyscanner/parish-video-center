<?php
/**
 * Update checker: lets WordPress discover new versions from GitHub releases.
 *
 * The plugin header declares "Update URI: https://github.com/<repo>", so core
 * routes update checks for this plugin to the update_plugins_github.com
 * filter instead of wordpress.org. We answer it from the GitHub releases API
 * (cached), and core takes care of version comparison and the update UI.
 *
 * Two release channels:
 * - stable (default): reads /releases/latest, which never includes GitHub
 *   pre-releases — production sites cannot see betas.
 * - beta: reads the full release list and offers whichever release, beta or
 *   stable, is newest. For staging sites.
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
	 * Release channel: 'stable' (default) or 'beta'. A staging site opts into
	 * betas with the "Receive beta (pre-release) updates" checkbox in
	 * settings. An SVC_UPDATE_CHANNEL constant in wp-config.php overrides the
	 * checkbox; the svc_update_channel filter overrides both.
	 */
	public static function channel() {
		$settings = svc_get_settings();
		$channel  = 'beta' === $settings['update_channel'] ? 'beta' : 'stable';

		if ( defined( 'SVC_UPDATE_CHANNEL' ) && SVC_UPDATE_CHANNEL ) {
			$channel = SVC_UPDATE_CHANNEL;
		}

		$channel = apply_filters( 'svc_update_channel', $channel );

		return 'beta' === $channel ? 'beta' : 'stable';
	}

	/**
	 * Provide update info for this plugin. Core compares versions itself,
	 * so we always return the newest release for our channel when we have one.
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
	 * Newest release for the current channel, cached per channel. Failures are
	 * cached too (as an empty array) so a GitHub outage doesn't add a slow
	 * request to every update check. The beta cache is short so staging can
	 * iterate without waiting half a day.
	 *
	 * @return array{version?: string, package?: string}
	 */
	private static function latest_release() {
		$channel = self::channel();
		$key     = self::CACHE_KEY . '_' . $channel;

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$release = 'beta' === $channel ? self::newest_of_all_releases() : self::latest_stable_release();

		set_transient( $key, $release, 'beta' === $channel ? 15 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Stable channel: GitHub's "latest" is the newest non-prerelease,
	 * non-draft release, so betas can never reach production sites.
	 */
	private static function latest_stable_release() {
		$release = self::release_info( self::api_get( '/releases/latest' ) );

		return $release ? $release : array();
	}

	/**
	 * Beta channel: scan recent releases (pre-releases included) and pick the
	 * highest version. Stable releases participate too, so a beta site is
	 * offered the final release once its beta is promoted.
	 */
	private static function newest_of_all_releases() {
		$list = self::api_get( '/releases?per_page=15' );
		$best = array();

		if ( is_array( $list ) ) {
			foreach ( $list as $item ) {
				$candidate = self::release_info( $item );
				if ( $candidate && ( ! $best || version_compare( $candidate['version'], $best['version'], '>' ) ) ) {
					$best = $candidate;
				}
			}
		}

		return $best;
	}

	/**
	 * Extract version + installable asset URL from one API release object.
	 *
	 * @return array|null Null when the release is unusable (draft, no zip).
	 */
	private static function release_info( $release ) {
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) || ! empty( $release['draft'] ) || empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return null;
		}

		foreach ( $release['assets'] as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
				return array(
					'version' => ltrim( (string) $release['tag_name'], 'v' ),
					'package' => $asset['browser_download_url'],
				);
			}
		}

		return null;
	}

	private static function api_get( $path ) {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . $path,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : null;
	}
}
