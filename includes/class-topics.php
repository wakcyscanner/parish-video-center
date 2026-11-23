<?php
/**
 * Topics: named collections of videos, each with its own root-level landing
 * page (/homilies/, /parish-mission/, …). A topic linked to a Vimeo showcase and/or
 * a Bunny Stream collection is synced automatically; a topic with neither is
 * curated by hand from the video
 * edit screen. Single videos all live under /video/<slug>/ regardless of topic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVC_Topics {

	const TAXONOMY      = 'svc_topic';
	const SHOWCASE_META   = 'svc_showcase_id';
	const COLLECTION_META = 'svc_bunny_collection_id';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 10 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 11 );
		add_action( 'init', array( __CLASS__, 'add_rewrites' ), 12 );

		add_filter( 'term_link', array( __CLASS__, 'term_link' ), 10, 3 );

		// Term slugs are rewrite rules; re-flush whenever terms change.
		foreach ( array( 'created_' . self::TAXONOMY, 'edited_' . self::TAXONOMY, 'delete_' . self::TAXONOMY ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'schedule_flush' ) );
		}

		// Source fields (showcase, collection) on the term add/edit screens.
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
		// Autoloaded: read on every request by the check above.
		update_option( 'svc_topics_migrated', 1, true );

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
	 * Term meta key holding a topic's source ID for a provider.
	 */
	private static function source_meta_key( $provider ) {
		return 'bunny' === $provider ? self::COLLECTION_META : self::SHOWCASE_META;
	}

	/**
	 * Topics that sync from a provider source: a Vimeo showcase or a Bunny
	 * Stream collection. A topic may be linked to both at once.
	 *
	 * @param string $provider 'vimeo' or 'bunny'.
	 * @return array[] Each: array{term_id: int, source_id: string, name: string}.
	 */
	public static function synced_topics( $provider = 'vimeo' ) {
		$synced = array();
		$key    = self::source_meta_key( $provider );

		foreach ( self::all_topics() as $topic ) {
			$source = get_term_meta( $topic->term_id, $key, true );
			if ( $source ) {
				$synced[] = array(
					'term_id'   => (int) $topic->term_id,
					'source_id' => $source,
					'name'      => $topic->name,
				);
			}
		}

		return $synced;
	}

	/**
	 * Term IDs whose membership is owned by a provider's sync. Manual topics
	 * are never touched by sync.
	 *
	 * @param string $provider 'vimeo' or 'bunny'.
	 * @return int[]
	 */
	public static function synced_term_ids( $provider = 'vimeo' ) {
		return array_map(
			function ( $topic ) {
				return $topic['term_id'];
			},
			self::synced_topics( $provider )
		);
	}

	/**
	 * The Bunny collection control: a dropdown of the library's collections
	 * when the API is reachable, a plain GUID field otherwise.
	 */
	private static function render_collection_control( $current ) {
		$collections = svc_bunny_configured() ? SVC_Bunny_API::collection_names() : null;

		if ( is_array( $collections ) ) {
			// Keep a saved collection selectable even if Bunny no longer lists it.
			if ( $current && ! isset( $collections[ $current ] ) ) {
				/* translators: %s: collection ID */
				$collections[ $current ] = sprintf( __( 'Missing collection (%s)', 'parish-video-center' ), $current );
			}
			?>
			<select id="svc-topic-collection" name="svc_bunny_collection_id">
				<option value=""><?php esc_html_e( '— None —', 'parish-video-center' ); ?></option>
				<?php foreach ( $collections as $guid => $name ) : ?>
					<option value="<?php echo esc_attr( $guid ); ?>" <?php selected( $current, $guid ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
			return;
		}
		?>
		<input type="text" id="svc-topic-collection" name="svc_bunny_collection_id" value="<?php echo esc_attr( $current ); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
		<?php if ( is_wp_error( $collections ) ) : ?>
			<p><em><?php echo esc_html( $collections->get_error_message() ); ?></em></p>
		<?php endif; ?>
		<?php
	}

	private static function showcase_help() {
		return __( 'The number in the showcase URL. Videos in that showcase sync into this topic automatically.', 'parish-video-center' );
	}

	private static function collection_help() {
		return __( 'Videos in this video library collection sync into this topic automatically. Leave both sources empty for a topic you curate by hand on the video edit screens.', 'parish-video-center' );
	}

	public static function add_form_field() {
		wp_nonce_field( 'svc_topic_showcase', 'svc_topic_showcase_nonce' );
		?>
		<div class="form-field">
			<label for="svc-topic-showcase"><?php esc_html_e( 'Vimeo Showcase ID', 'parish-video-center' ); ?></label>
			<input type="text" id="svc-topic-showcase" name="svc_showcase_id" value="" inputmode="numeric">
			<p><?php echo esc_html( self::showcase_help() ); ?></p>
		</div>
		<div class="form-field">
			<label for="svc-topic-collection"><?php esc_html_e( 'Video Library Collection', 'parish-video-center' ); ?></label>
			<?php self::render_collection_control( '' ); ?>
			<p><?php echo esc_html( self::collection_help() ); ?></p>
		</div>
		<?php
	}

	public static function edit_form_field( $term ) {
		wp_nonce_field( 'svc_topic_showcase', 'svc_topic_showcase_nonce' );
		$showcase   = get_term_meta( $term->term_id, self::SHOWCASE_META, true );
		$collection = get_term_meta( $term->term_id, self::COLLECTION_META, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="svc-topic-showcase"><?php esc_html_e( 'Vimeo Showcase ID', 'parish-video-center' ); ?></label></th>
			<td>
				<input type="text" id="svc-topic-showcase" name="svc_showcase_id" value="<?php echo esc_attr( $showcase ); ?>" inputmode="numeric">
				<p class="description"><?php echo esc_html( self::showcase_help() ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="svc-topic-collection"><?php esc_html_e( 'Video Library Collection', 'parish-video-center' ); ?></label></th>
			<td>
				<?php self::render_collection_control( $collection ); ?>
				<p class="description"><?php echo esc_html( self::collection_help() ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_showcase_meta( $term_id ) {
		if ( ! isset( $_POST['svc_topic_showcase_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['svc_topic_showcase_nonce'] ) ), 'svc_topic_showcase' ) ) {
			return;
		}

		if ( isset( $_POST['svc_showcase_id'] ) ) {
			$showcase = preg_replace( '/\D/', '', wp_unslash( $_POST['svc_showcase_id'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- digits only survive.
			if ( $showcase ) {
				update_term_meta( $term_id, self::SHOWCASE_META, $showcase );
			} else {
				delete_term_meta( $term_id, self::SHOWCASE_META );
			}
		}

		if ( isset( $_POST['svc_bunny_collection_id'] ) ) {
			// Collection IDs are GUIDs; anything else is discarded.
			$collection = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['svc_bunny_collection_id'] ) ) ) );
			if ( preg_match( '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $collection ) ) {
				update_term_meta( $term_id, self::COLLECTION_META, $collection );
			} else {
				delete_term_meta( $term_id, self::COLLECTION_META );
			}
		}
	}

	public static function columns( $columns ) {
		$columns['svc_showcase'] = __( 'Source', 'parish-video-center' );
		return $columns;
	}

	public static function column_content( $content, $column, $term_id ) {
		if ( 'svc_showcase' !== $column ) {
			return $content;
		}

		$parts    = array();
		$showcase = get_term_meta( $term_id, self::SHOWCASE_META, true );
		if ( $showcase ) {
			/* translators: %s: Vimeo showcase ID */
			$parts[] = esc_html( sprintf( __( 'Vimeo showcase %s', 'parish-video-center' ), $showcase ) );
		}
		if ( get_term_meta( $term_id, self::COLLECTION_META, true ) ) {
			$parts[] = esc_html__( 'Library collection', 'parish-video-center' );
		}

		return $parts
			? implode( '<br>', $parts )
			: '<em>' . esc_html__( 'manual', 'parish-video-center' ) . '</em>';
	}
}
