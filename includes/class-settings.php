<?php
/**
 * Settings page under the video post type menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Settings {

	const PAGE = 'svc-settings';

	/**
	 * Hook suffix returned by add_submenu_page(), used to detect our screen.
	 */
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_svc_test_connection', array( __CLASS__, 'handle_test_connection' ) );
	}

	/**
	 * URL of the settings page.
	 */
	public static function url() {
		return admin_url( 'edit.php?post_type=' . SVC_Post_Type::POST_TYPE . '&page=' . self::PAGE );
	}

	public static function add_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=' . SVC_Post_Type::POST_TYPE,
			__( 'Video Center Settings', 'parish-video-center' ),
			__( 'Settings', 'parish-video-center' ),
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

		// A blank token field keeps the stored token (the form never echoes it back).
		if ( ! empty( $input['remove_token'] ) ) {
			$token = '';
		} elseif ( isset( $input['vimeo_token'] ) && '' !== trim( $input['vimeo_token'] ) ) {
			$token = trim( sanitize_text_field( $input['vimeo_token'] ) );
		} else {
			$token = $current['vimeo_token'];
		}

		$singular = isset( $input['singular'] ) ? sanitize_text_field( $input['singular'] ) : $current['singular'];
		$plural   = isset( $input['plural'] ) ? sanitize_text_field( $input['plural'] ) : $current['plural'];
		if ( '' === trim( $singular ) ) {
			$singular = 'Video';
		}
		if ( '' === trim( $plural ) ) {
			$plural = 'Videos';
		}

		$slug = isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : $current['slug'];
		if ( '' === $slug ) {
			$slug = 'videos';
		}
		if ( $slug !== $current['slug'] ) {
			update_option( 'svc_flush_rewrite', 1 );
		}

		$frequency = isset( $input['sync_frequency'] ) ? $input['sync_frequency'] : $current['sync_frequency'];
		if ( ! in_array( $frequency, SVC_Sync::FREQUENCIES, true ) ) {
			$frequency = 'hourly';
		}

		return array(
			'vimeo_token'    => $token,
			'showcase_id'    => isset( $input['showcase_id'] ) ? preg_replace( '/\D/', '', $input['showcase_id'] ) : $current['showcase_id'],
			'singular'       => $singular,
			'plural'         => $plural,
			'slug'           => $slug,
			'publisher'      => isset( $input['publisher'] ) ? sanitize_text_field( $input['publisher'] ) : $current['publisher'],
			'per_page'       => isset( $input['per_page'] ) ? min( 48, max( 1, (int) $input['per_page'] ) ) : $current['per_page'],
			'sync_frequency' => $frequency,
		);
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_settings = $screen && self::$hook === $screen->id;

		// Setup nag anywhere in admin until token and showcase are configured.
		$settings = svc_get_settings();
		if ( ! svc_get_token() || ! $settings['showcase_id'] ) {
			if ( ! $on_settings ) {
				printf(
					'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'Parish Video Center needs a Vimeo access token and showcase ID before it can sync.', 'parish-video-center' ),
					esc_url( self::url() ),
					esc_html__( 'Open settings', 'parish-video-center' )
				);
			}
		}

		if ( ! $on_settings ) {
			return;
		}

		if ( isset( $_GET['svc-synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$flag = sanitize_key( wp_unslash( $_GET['svc-synced'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'ok' === $flag ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sync completed.', 'parish-video-center' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Sync failed — see the last sync status below.', 'parish-video-center' ) . '</p></div>';
			}
		}

		if ( isset( $_GET['svc-tested'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result = get_transient( 'svc_test_result' );
			delete_transient( 'svc_test_result' );
			if ( is_array( $result ) ) {
				$class = 'ok' === $result['status'] ? 'notice-success' : 'notice-error';
				echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $result['message'] ) . '</p></div>';
			}
		}

		$last = get_option( 'svc_last_sync' );
		if ( is_array( $last ) && 'error' === $last['status'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Video Center sync error:', 'parish-video-center' ) . '</strong> ' . esc_html( $last['message'] ) . '</p></div>';
		}
	}

	/**
	 * Test the token + showcase against the Vimeo API without writing anything.
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'parish-video-center' ) );
		}
		check_admin_referer( 'svc_test_connection' );

		$settings = svc_get_settings();
		$result   = SVC_Vimeo_API::test_connection( $settings['showcase_id'] );

		if ( is_wp_error( $result ) ) {
			set_transient(
				'svc_test_result',
				array(
					'status'  => 'error',
					'message' => $result->get_error_message(),
				),
				5 * MINUTE_IN_SECONDS
			);
		} else {
			set_transient(
				'svc_test_result',
				array(
					'status'  => 'ok',
					'message' => sprintf(
						/* translators: %d: number of videos */
						__( 'Connection OK — the showcase contains %d videos.', 'parish-video-center' ),
						$result
					),
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( add_query_arg( 'svc-tested', '1', self::url() ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings       = svc_get_settings();
		$token_constant = defined( 'VIMEO_TOKEN' ) && VIMEO_TOKEN;
		$has_token      = '' !== $settings['vimeo_token'];
		$last           = get_option( 'svc_last_sync' );
		$next           = wp_next_scheduled( SVC_Sync::CRON_HOOK );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Video Center Settings', 'parish-video-center' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'svc_settings_group' ); ?>

				<h2><?php esc_html_e( 'Vimeo', 'parish-video-center' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="svc-token"><?php esc_html_e( 'Access Token', 'parish-video-center' ); ?></label></th>
						<td>
							<?php if ( $token_constant ) : ?>
								<p><em><?php esc_html_e( 'Defined as VIMEO_TOKEN in wp-config.php — the constant overrides this field.', 'parish-video-center' ); ?></em></p>
							<?php endif; ?>
							<input type="password" id="svc-token" name="svc_settings[vimeo_token]" value="" class="regular-text" autocomplete="off"
								placeholder="<?php echo $has_token ? esc_attr__( 'Saved — leave blank to keep', 'parish-video-center' ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Personal access token from developer.vimeo.com/apps with the "public" and "private" scopes. The saved token is never displayed here.', 'parish-video-center' ); ?></p>
							<?php if ( $has_token ) : ?>
								<label>
									<input type="checkbox" name="svc_settings[remove_token]" value="1">
									<?php esc_html_e( 'Remove the saved token', 'parish-video-center' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-showcase"><?php esc_html_e( 'Showcase ID', 'parish-video-center' ); ?></label></th>
						<td>
							<input type="text" id="svc-showcase" name="svc_settings[showcase_id]" value="<?php echo esc_attr( $settings['showcase_id'] ); ?>" class="regular-text" inputmode="numeric">
							<p class="description"><?php esc_html_e( 'The number in the showcase URL, e.g. vimeo.com/showcase/1234567 → 1234567.', 'parish-video-center' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Display', 'parish-video-center' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="svc-singular"><?php esc_html_e( 'Video Name (singular)', 'parish-video-center' ); ?></label></th>
						<td>
							<input type="text" id="svc-singular" name="svc_settings[singular]" value="<?php echo esc_attr( $settings['singular'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'What one of these videos is called, e.g. Homily, Sermon, Message.', 'parish-video-center' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-plural"><?php esc_html_e( 'Video Name (plural)', 'parish-video-center' ); ?></label></th>
						<td><input type="text" id="svc-plural" name="svc_settings[plural]" value="<?php echo esc_attr( $settings['plural'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-slug"><?php esc_html_e( 'Archive URL Slug', 'parish-video-center' ); ?></label></th>
						<td>
							<input type="text" id="svc-slug" name="svc_settings[slug]" value="<?php echo esc_attr( $settings['slug'] ); ?>" class="regular-text">
							<p class="description">
								<?php
								printf(
									/* translators: %s: example archive URL */
									esc_html__( 'The gallery page lives at %s.', 'parish-video-center' ),
									'<code>' . esc_html( home_url( '/' . ( $settings['slug'] ? $settings['slug'] : 'videos' ) . '/' ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-publisher"><?php esc_html_e( 'Publisher Name', 'parish-video-center' ); ?></label></th>
						<td>
							<input type="text" id="svc-publisher" name="svc_settings[publisher]" value="<?php echo esc_attr( $settings['publisher'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Used in VideoObject structured data.', 'parish-video-center' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-per-page"><?php esc_html_e( 'Videos Per Page', 'parish-video-center' ); ?></label></th>
						<td><input type="number" id="svc-per-page" name="svc_settings[per_page]" value="<?php echo esc_attr( $settings['per_page'] ); ?>" min="1" max="48" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="svc-frequency"><?php esc_html_e( 'Sync Frequency', 'parish-video-center' ); ?></label></th>
						<td>
							<select id="svc-frequency" name="svc_settings[sync_frequency]">
								<option value="hourly" <?php selected( $settings['sync_frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'parish-video-center' ); ?></option>
								<option value="twicedaily" <?php selected( $settings['sync_frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'parish-video-center' ); ?></option>
								<option value="daily" <?php selected( $settings['sync_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'parish-video-center' ); ?></option>
								<option value="weekly" <?php selected( $settings['sync_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'parish-video-center' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Sync', 'parish-video-center' ); ?></h2>
			<?php if ( is_array( $last ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Last sync:', 'parish-video-center' ); ?></strong>
					<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] + (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); ?>
					— <?php echo esc_html( $last['message'] ); ?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'No sync has run yet.', 'parish-video-center' ); ?></p>
			<?php endif; ?>
			<?php if ( $next ) : ?>
				<p>
					<strong><?php esc_html_e( 'Next scheduled sync:', 'parish-video-center' ); ?></strong>
					<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next + (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); ?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:0.5em;">
				<input type="hidden" name="action" value="svc_test_connection">
				<?php wp_nonce_field( 'svc_test_connection' ); ?>
				<?php submit_button( __( 'Test Connection', 'parish-video-center' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<input type="hidden" name="action" value="svc_sync_now">
				<?php wp_nonce_field( 'svc_sync_now' ); ?>
				<?php submit_button( __( 'Sync Now', 'parish-video-center' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
