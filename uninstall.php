<?php
/**
 * Uninstall cleanup: remove plugin options, transients, and scheduled events.
 * Video posts and sideloaded media are intentionally left in place.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'svc_settings' );
delete_option( 'svc_last_sync' );
delete_option( 'svc_flush_rewrite' );
delete_option( 'svc_version' );
delete_transient( 'svc_test_result' );
delete_transient( 'svc_update_check' );
delete_transient( 'svc_update_check_stable' );
delete_transient( 'svc_update_check_beta' );

wp_clear_scheduled_hook( 'svc_sync_event' );
