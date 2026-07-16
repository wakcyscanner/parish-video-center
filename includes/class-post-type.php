<?php
/**
 * Registers the homily custom post type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Post_Type {

	const POST_TYPE = 'homily';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_lock_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_lock_meta' ), 10, 2 );
	}

	public static function add_lock_meta_box() {
		add_meta_box(
			'svc-sync-lock',
			__( 'Vimeo Sync', 'stpacc-video-center' ),
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
			<?php esc_html_e( "Don't overwrite with Vimeo data", 'stpacc-video-center' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When checked, sync leaves this homily alone entirely: the title, description, status, and featured image you set here are preserved, and it will not be unpublished if the video leaves the showcase.', 'stpacc-video-center' ); ?></p>
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
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Homilies', 'stpacc-video-center' ),
					'singular_name'      => __( 'Homily', 'stpacc-video-center' ),
					'menu_name'          => __( 'Homilies', 'stpacc-video-center' ),
					'add_new_item'       => __( 'Add New Homily', 'stpacc-video-center' ),
					'edit_item'          => __( 'Edit Homily', 'stpacc-video-center' ),
					'view_item'          => __( 'View Homily', 'stpacc-video-center' ),
					'search_items'       => __( 'Search Homilies', 'stpacc-video-center' ),
					'not_found'          => __( 'No homilies found.', 'stpacc-video-center' ),
					'all_items'          => __( 'All Homilies', 'stpacc-video-center' ),
				),
				'description'  => __( 'Videos synced from the parish Vimeo showcase.', 'stpacc-video-center' ),
				'public'       => true,
				'has_archive'  => 'homilies',
				'rewrite'      => array(
					'slug'       => 'homilies',
					'with_front' => false,
				),
				'menu_icon'    => 'dashicons-video-alt3',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'show_in_rest' => true,
			)
		);
	}
}
