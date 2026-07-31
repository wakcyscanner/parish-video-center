<?php
/**
 * Topics: named collections of videos, each with its own root-level landing
 * page (/homilies/, /parish-mission/, …). A topic with a Vimeo Showcase ID is
 * synced automatically; a topic without one is curated by hand from the video
 * edit screen. Single videos all live under /video/<slug>/ regardless of topic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Topics {

	const TAXONOMY      = 'svc_topic';
	const SHOWCASE_META = 'svc_showcase_id';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 10 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 11 );
		add_action( 'init', array( __CLASS__, 'add_rewrites' ), 12 );

		add_filter( 'term_link', array( __CLASS__, 'term_link' ), 10, 3 );

		// Term slugs are rewrite rules; re-flush whenever terms change.
		foreach ( array( 'created_' . self::TAXONOMY, 'edited_' . self::TAXONOMY, 'delete_' . self::TAXONOMY ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'schedule_flush' ) );
		}

		// Showcase ID field on the term add/edit screens.
		add_action( self::TAXONOMY . '_add_form_fields', array( __CLASS__, 'add_form_field' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( __CLASS__, 'edit_form_field' ) );
		add_action( 'created_' . self::TAXONOMY, array( __CLASS__, 'save_showcase_meta' ) );
		add_action( 'edited_' . self::TAXONOMY, array( __CLASS__, 'save_showcase_meta' ) );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( __CLASS__, 'column_content' ), 10, 3 );
	}

	public static function register() {
		register_taxonomy(
			self::TAXONOMY,
			SVC_Post_Type::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Topics', 'parish-video-center' ),
					'singular_name' => __( 'Topic', 'parish-video-center' ),
					'menu_name'     => __( 'Topics', 'parish-video-center' ),
					'add_new_item'  => __( 'Add New Topic', 'parish-video-center' ),
					'edit_item'     => __( 'Edit Topic', 'parish-video-center' ),
					'search_items'  => __( 'Search Topics', 'parish-video-center' ),
					'not_found'     => __( 'No topics found.', 'parish-video-center' ),
				),
				'description'       => __( 'Video collections, each with its own landing page.', 'parish-video-center' ),
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				// Landing pages live at the site root (/<topic-slug>/) via the
				// hand-rolled rules in add_rewrites().
				'rewrite'           => false,
			)
		);
	}

	/**
	 * One-time 1.13 migration: turn the legacy single-showcase configuration
	 * into the first topic (name from the plural label, slug from the old
	 * archive slug, so the existing landing page URL keeps working) and put
	 * every existing video in it.
	 */
	public static function maybe_migrate() {
		if ( get_option( 'svc_topics_migrated' ) ) {
			return;
		}
		update_option( 'svc_topics_migrated', 1, false );

		$settings = svc_get_settings();
		if ( ! $settings['showcase_id'] || get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) ) ) {
			return;
		}

		$name = '' !== trim( $settings['plural'] ) ? $settings['plural'] : 'Videos';
		$slug = sanitize_title( $settings['slug'] ? $settings['slug'] : $name );

		$term = wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
		if ( is_wp_error( $term ) ) {
			return;
		}

		update_term_meta( $term['term_id'], self::SHOWCASE_META, $settings['showcase_id'] );

		$videos = get_posts(
			array(
				'post_type'      => SVC_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $videos as $video_id ) {
			wp_set_object_terms( $video_id, array( (int) $term['term_id'] ), self::TAXONOMY, true );
		}

		self::schedule_flush();
	}

	/**
	 * Root-level landing page rules for every topic: /<slug>/ and its
	 * pagination. Registered 'top' so they beat WP's page fallback rules.
	 */
	public static function add_rewrites() {
		foreach ( self::all_topics() as $topic ) {
			$quoted = preg_quote( $topic->slug, '#' );
			add_rewrite_rule( '^' . $quoted . '/?$', 'index.php?' . self::TAXONOMY . '=' . $topic->slug, 'top' );
			add_rewrite_rule( '^' . $quoted . '/page/(\d+)/?$', 'index.php?' . self::TAXONOMY . '=' . $topic->slug . '&paged=$matches[1]', 'top' );
		}
	}

	public static function schedule_flush() {
		update_option( 'svc_flush_rewrite', 1 );
	}

	/**
	 * Topic landing pages live at the site root.
	 */
	public static function term_link( $link, $term, $taxonomy ) {
		if ( self::TAXONOMY === $taxonomy ) {
			return home_url( '/' . $term->slug . '/' );
		}
		return $link;
	}

	/**
	 * All topics, empty ones included (a new showcase topic has no posts yet).
	 *
	 * @return WP_Term[]
	 */
	public static function all_topics() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Topics that sync from a Vimeo showcase.
	 *
	 * @return array[] Each: array{term_id: int, showcase_id: string}.
	 */
	public static function synced_topics() {
		$synced = array();

		foreach ( self::all_topics() as $topic ) {
			$showcase = get_term_meta( $topic->term_id, self::SHOWCASE_META, true );
			if ( $showcase ) {
				$synced[] = array(
					'term_id'     => (int) $topic->term_id,
					'showcase_id' => $showcase,
					'name'        => $topic->name,
				);
			}
		}

		return $synced;
	}

	/**
	 * Term IDs whose membership is owned by sync (showcase-backed topics).
	 * Manual topics are never touched by sync.
	 *
	 * @return int[]
	 */
	public static function synced_term_ids() {
		return array_map(
			function ( $topic ) {
				return $topic['term_id'];
			},
			self::synced_topics()
		);
	}

	public static function add_form_field() {
		wp_nonce_field( 'svc_topic_showcase', 'svc_topic_showcase_nonce' );
		?>
		<div class="form-field">
			<label for="svc-topic-showcase"><?php esc_html_e( 'Vimeo Showcase ID', 'parish-video-center' ); ?></label>
			<input type="text" id="svc-topic-showcase" name="svc_showcase_id" value="" inputmode="numeric">
			<p><?php esc_html_e( 'The number in the showcase URL. Videos in that showcase sync into this topic automatically. Leave empty for a topic you curate by hand on the video edit screens.', 'parish-video-center' ); ?></p>
		</div>
		<?php
	}

	public static function edit_form_field( $term ) {
		wp_nonce_field( 'svc_topic_showcase', 'svc_topic_showcase_nonce' );
		$showcase = get_term_meta( $term->term_id, self::SHOWCASE_META, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="svc-topic-showcase"><?php esc_html_e( 'Vimeo Showcase ID', 'parish-video-center' ); ?></label></th>
			<td>
				<input type="text" id="svc-topic-showcase" name="svc_showcase_id" value="<?php echo esc_attr( $showcase ); ?>" inputmode="numeric">
				<p class="description"><?php esc_html_e( 'The number in the showcase URL. Videos in that showcase sync into this topic automatically. Leave empty for a topic you curate by hand on the video edit screens.', 'parish-video-center' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_showcase_meta( $term_id ) {
		if ( ! isset( $_POST['svc_topic_showcase_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['svc_topic_showcase_nonce'] ) ), 'svc_topic_showcase' ) ) {
			return;
		}
		if ( ! isset( $_POST['svc_showcase_id'] ) ) {
			return;
		}

		$showcase = preg_replace( '/\D/', '', wp_unslash( $_POST['svc_showcase_id'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- digits only survive.
		if ( $showcase ) {
			update_term_meta( $term_id, self::SHOWCASE_META, $showcase );
		} else {
			delete_term_meta( $term_id, self::SHOWCASE_META );
		}
	}

	public static function columns( $columns ) {
		$columns['svc_showcase'] = __( 'Showcase', 'parish-video-center' );
		return $columns;
	}

	public static function column_content( $content, $column, $term_id ) {
		if ( 'svc_showcase' !== $column ) {
			return $content;
		}

		$showcase = get_term_meta( $term_id, self::SHOWCASE_META, true );

		return $showcase
			? esc_html( $showcase )
			: '<em>' . esc_html__( 'manual', 'parish-video-center' ) . '</em>';
	}
}
