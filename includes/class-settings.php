<?php
/**
 * Settings → Video Center admin page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Settings {

	const PAGE = 'svc-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function add_page() {
		add_options_page(
			__( 'Video Center', 'stpacc-video-center' ),
			__( 'Video Center', 'stpacc-video-center' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'svc_settings_group',
			'svc_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = svc_get_settings();

		$frequency = isset( $input['sync_frequency'] ) ? $input['sync_frequency'] : $current['sync_frequency'];
		if ( ! in_array( $frequency, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
			$frequency = 'hourly';
		}

		return array(
			'vimeo_token'    => isset( $input['vimeo_token'] ) ? trim( sanitize_text_field( $input['vimeo_token'] ) ) : $current['vimeo_token'],
			'showcase_id'    => isset( $input['showcase_id'] ) ? preg_replace( '/\D/', '', $input['showcase_id'] ) : $current['showcase_id'],
			'publisher'      => isset( $input['publisher'] ) ? sanitize_text_field( $input['publisher'] ) : $current['publisher'],
			'per_page'       => isset( $input['per_page'] ) ? min( 48, max( 1, (int) $input['per_page'] ) ) : $current['per_page'],
			'sync_frequency' => $frequency,
		);
	}

	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'settings_page_' . self::PAGE !== $screen->id ) {
			return;
		}

		if ( isset( $_GET['svc-synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$flag = sanitize_key( wp_unslash( $_GET['svc-synced'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'ok' === $flag ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sync completed.', 'stpacc-video-center' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Sync failed — see the last sync status below.', 'stpacc-video-center' ) . '</p></div>';
			}
		}

		$last = get_option( 'svc_last_sync' );
		if ( is_array( $last ) && 'error' === $last['status'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Video Center sync error:', 'stpacc-video-center' ) . '</strong> ' . esc_html( $last['message'] ) . '</p></div>';
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings       = svc_get_settings();
		$token_constant = defined( 'VIMEO_TOKEN' ) && VIMEO_TOKEN;
		$last           = get_option( 'svc_last_sync' );
		$next           = wp_next_scheduled( SVC_Sync::CRON_HOOK );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Video Center', 'stpacc-video-center' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'svc_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="svc-token"><?php esc_html_e( 'Vimeo Token', 'stpacc-video-center' ); ?></label></th>
						<td>
							<?php if ( $token_constant ) : ?>
								<p><em><?php esc_html_e( 'Defined as VIMEO_TOKEN in wp-config.php — the constant overrides this field.', 'stpacc-video-center' ); ?></em></p>
							<?php endif; ?>
							<input type="password" id="svc-token" name="svc_settings[vimeo_token]" value="<?php echo esc_attr( $settings['vimeo_token'] ); ?>" class="regular-text" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Personal access token from developer.vimeo.com/apps with public and private scopes.', 'stpacc-video-center' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-showcase"><?php esc_html_e( 'Showcase ID', 'stpacc-video-center' ); ?></label></th>
						<td><input type="text" id="svc-showcase" name="svc_settings[showcase_id]" value="<?php echo esc_attr( $settings['showcase_id'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-publisher"><?php esc_html_e( 'Publisher Name', 'stpacc-video-center' ); ?></label></th>
						<td>
							<input type="text" id="svc-publisher" name="svc_settings[publisher]" value="<?php echo esc_attr( $settings['publisher'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Used in VideoObject structured data.', 'stpacc-video-center' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-per-page"><?php esc_html_e( 'Videos Per Page', 'stpacc-video-center' ); ?></label></th>
						<td><input type="number" id="svc-per-page" name="svc_settings[per_page]" value="<?php echo esc_attr( $settings['per_page'] ); ?>" min="1" max="48" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-frequency"><?php esc_html_e( 'Sync Frequency', 'stpacc-video-center' ); ?></label></th>
						<td>
							<select id="svc-frequency" name="svc_settings[sync_frequency]">
								<option value="hourly" <?php selected( $settings['sync_frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'stpacc-video-center' ); ?></option>
								<option value="twicedaily" <?php selected( $settings['sync_frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'stpacc-video-center' ); ?></option>
								<option value="daily" <?php selected( $settings['sync_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'stpacc-video-center' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Sync', 'stpacc-video-center' ); ?></h2>
			<?php if ( is_array( $last ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Last sync:', 'stpacc-video-center' ); ?></strong>
					<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] + (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); ?>
					— <?php echo esc_html( $last['message'] ); ?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'No sync has run yet.', 'stpacc-video-center' ); ?></p>
			<?php endif; ?>
			<?php if ( $next ) : ?>
				<p>
					<strong><?php esc_html_e( 'Next scheduled sync:', 'stpacc-video-center' ); ?></strong>
					<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next + (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); ?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="svc_sync_now">
				<?php wp_nonce_field( 'svc_sync_now' ); ?>
				<?php submit_button( __( 'Sync Now', 'stpacc-video-center' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
