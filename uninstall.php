<?php
/**
 * Uninstall cleanup: remove plugin options and scheduled events.
 * Homily posts and sideloaded media are intentionally left in place.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'svc_settings' );
delete_option( 'svc_last_sync' );

wp_clear_scheduled_hook( 'svc_sync_event' );
