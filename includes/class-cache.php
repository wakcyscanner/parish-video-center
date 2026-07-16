<?php
/**
 * Page-cache purging. Cache plugins purge pages when a post is saved through
 * wp-admin, but this plugin changes public pages without those signals: sync
 * writes posts programmatically, and a plugin update changes templates and
 * styles with no post writes at all. So purge explicitly at those moments.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Cache {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_purge_on_upgrade' ), 30 );
		// Display-affecting settings (labels, slug, per-page) changed.
		add_action( 'update_option_svc_settings', array( __CLASS__, 'purge_all' ), 20, 0 );
	}

	/**
	 * Purge once whenever the plugin version changes: an update means new
	 * templates or styles, which no cache plugin notices on its own.
	 */
	public static function maybe_purge_on_upgrade() {
		if ( get_option( 'svc_version' ) !== SVC_VERSION ) {
			update_option( 'svc_version', SVC_VERSION, false );
			self::purge_all();
		}
	}

	/**
	 * Ask every page cache we know about to drop everything. Sites are small
	 * (a parish site with a weekly video), so a full purge is simpler and more
	 * reliable than per-URL invalidation. Unknown setups can hook the
	 * 'svc_purge_page_cache' action fired at the end.
	 */
	public static function purge_all() {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}

		// SiteGround Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// WP Engine.
		if ( class_exists( 'WpeCommon' ) ) {
			if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
				WpeCommon::purge_memcached();
			}
			if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
				WpeCommon::purge_varnish_cache();
			}
		}

		// Pantheon.
		if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {
			pantheon_wp_clear_edge_all();
		}

		// Comet Cache.
		if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ) ) {
			comet_cache::clear();
		}

		// Action-based caches: firing an unregistered action is a no-op.
		do_action( 'litespeed_purge_all' );          // LiteSpeed Cache.
		do_action( 'cache_enabler_clear_complete_cache' ); // Cache Enabler.
		do_action( 'breeze_clear_all_cache' );       // Breeze (Cloudways).
		do_action( 'wphb_clear_page_cache' );        // Hummingbird.
		do_action( 'rt_nginx_helper_purge_all' );    // Nginx Helper.

		// Anything else (host-specific caches, CDNs) can hook here.
		do_action( 'svc_purge_page_cache' );
	}
}
