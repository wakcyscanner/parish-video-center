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
delete_option( 'svc_topics_migrated' );
delete_transient( 'svc_test_result' );
delete_transient( 'svc_bunny_collections' );
delete_transient( 'svc_sync_running' );
delete_transient( 'svc_update_check' );
delete_transient( 'svc_update_check_stable' );
delete_transient( 'svc_update_check_beta' );

wp_clear_scheduled_hook( 'svc_sync_event' );

// Generated newsletter pictures (they can be made again from the thumbnails).
$svc_uploads = wp_upload_dir( null, false );
$svc_folder  = trailingslashit( $svc_uploads['basedir'] ) . 'parish-video-center';
$svc_files   = glob( $svc_folder . '/email/*.jpg' );
foreach ( $svc_files ? $svc_files : array() as $svc_file ) {
	wp_delete_file( $svc_file );
}
if ( is_dir( $svc_folder . '/email' ) ) {
	rmdir( $svc_folder . '/email' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
if ( is_dir( $svc_folder ) ) {
	@rmdir( $svc_folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- only removed when empty.
}
