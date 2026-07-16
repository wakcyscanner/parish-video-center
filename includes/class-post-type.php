<?php
/**
 * Registers the video custom post type with labels and URL slug from settings,
 * so each site can call these what it likes (Homilies, Sermons, Messages, …).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Post_Type {

	const POST_TYPE = 'svc_video';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 20 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_lock_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_lock_meta' ), 10, 2 );
	}

	public static function add_lock_meta_box() {
		add_meta_box(
			'svc-sync-lock',
			__( 'Vimeo Sync', 'parish-video-center' ),
			array( __CLASS__, 'render_lock_meta_box' ),
			self::POST_TYPE,
			'side'
		);
	}

	public static function render_lock_meta_box( $post ) {
		$locked = (bool) get_post_meta( $post->ID, '_svc_sync_lock', true );
		wp_nonce_field( 'svc_sync_lock', 'svc_sync_lock_nonce' );
		?>
		<label for="svc-sync-lock-field">
			<input type="checkbox" id="svc-sync-lock-field" name="svc_sync_lock" value="1" <?php checked( $locked ); ?>>
			<?php esc_html_e( "Don't overwrite with Vimeo data", 'parish-video-center' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When checked, sync leaves this post alone entirely: the title, description, status, and featured image you set here are preserved, and it will not be unpublished if the video leaves the showcase.', 'parish-video-center' ); ?></p>
		<?php
	}

	public static function save_lock_meta( $post_id, $post ) {
		if ( ! isset( $_POST['svc_sync_lock_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['svc_sync_lock_nonce'] ) ), 'svc_sync_lock' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['svc_sync_lock'] ) ) {
			update_post_meta( $post_id, '_svc_sync_lock', '1' );
		} else {
			delete_post_meta( $post_id, '_svc_sync_lock' );
		}
	}

	public static function register() {
		$settings = svc_get_settings();

		$singular = '' !== trim( $settings['singular'] ) ? $settings['singular'] : 'Video';
		$plural   = '' !== trim( $settings['plural'] ) ? $settings['plural'] : 'Videos';
		$slug     = sanitize_title( $settings['slug'] );
		if ( '' === $slug ) {
			$slug = 'videos';
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => $plural,
					'singular_name' => $singular,
					'menu_name'     => $plural,
					/* translators: %s: singular video label */
					'add_new_item'  => sprintf( __( 'Add New %s', 'parish-video-center' ), $singular ),
					/* translators: %s: singular video label */
					'edit_item'     => sprintf( __( 'Edit %s', 'parish-video-center' ), $singular ),
					/* translators: %s: singular video label */
					'view_item'     => sprintf( __( 'View %s', 'parish-video-center' ), $singular ),
					/* translators: %s: plural video label */
					'search_items'  => sprintf( __( 'Search %s', 'parish-video-center' ), $plural ),
					/* translators: %s: plural video label */
					'not_found'     => sprintf( __( 'No %s found.', 'parish-video-center' ), $plural ),
					/* translators: %s: plural video label */
					'all_items'     => sprintf( __( 'All %s', 'parish-video-center' ), $plural ),
				),
				'description'  => __( 'Videos synced from a Vimeo showcase.', 'parish-video-center' ),
				'public'       => true,
				'has_archive'  => $slug,
				'rewrite'      => array(
					'slug'       => $slug,
					'with_front' => false,
				),
				'menu_icon'    => 'dashicons-video-alt3',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Flush rewrite rules once after the archive slug setting changes.
	 * Runs at init 20, after register() has re-registered with the new slug.
	 */
	public static function maybe_flush_rewrites() {
		if ( get_option( 'svc_flush_rewrite' ) ) {
			flush_rewrite_rules();
			delete_option( 'svc_flush_rewrite' );
		}
	}
}
