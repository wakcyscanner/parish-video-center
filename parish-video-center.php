<?php
/**
 * Plugin Name: Parish Video Center
 * Description: Syncs a Vimeo showcase into WordPress video posts with a gallery archive, single video pages, and VideoObject structured data. Post labels and URL slug are configurable (Homilies, Sermons, Messages, …).
 * Version: 1.9.0-beta.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: St. Paul the Apostle Catholic Church
 * License: GPL-2.0-or-later
 * Text Domain: parish-video-center
 * Update URI: https://github.com/wakcyscanner/parish-video-center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SVC_VERSION', '1.9.0-beta.1' );
define( 'SVC_PLUGIN_FILE', __FILE__ );
define( 'SVC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SVC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SVC_PLUGIN_DIR . 'includes/class-post-type.php';
require_once SVC_PLUGIN_DIR . 'includes/class-vimeo-api.php';
require_once SVC_PLUGIN_DIR . 'includes/class-sync.php';
require_once SVC_PLUGIN_DIR . 'includes/class-settings.php';
require_once SVC_PLUGIN_DIR . 'includes/class-structured-data.php';
require_once SVC_PLUGIN_DIR . 'includes/class-redirects.php';
require_once SVC_PLUGIN_DIR . 'includes/class-cache.php';
require_once SVC_PLUGIN_DIR . 'includes/class-updates.php';
require_once SVC_PLUGIN_DIR . 'includes/class-social-meta.php';
require_once SVC_PLUGIN_DIR . 'includes/class-sitemap.php';
require_once SVC_PLUGIN_DIR . 'includes/class-embeds.php';

SVC_Post_Type::init();
SVC_Sync::init();
SVC_Settings::init();
SVC_Structured_Data::init();
SVC_Redirects::init();
SVC_Cache::init();
SVC_Updates::init();
SVC_Social_Meta::init();
SVC_Sitemap::init();
SVC_Embeds::init();

/**
 * Get plugin settings merged with defaults.
 *
 * Label/slug defaults are deliberately untranslated plain strings: this runs
 * before init on activation, where loading translations is too early.
 */
function svc_get_settings() {
	$defaults = array(
		'vimeo_token'    => '',
		'showcase_id'    => '',
		'singular'       => 'Video',
		'plural'         => 'Videos',
		'slug'           => 'videos',
		'publisher'      => get_bloginfo( 'name' ),
		'per_page'       => 12,
		'sync_frequency' => 'hourly',
		'update_channel' => 'stable',
	);

	$settings = get_option( 'svc_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

/**
 * Vimeo API token. A VIMEO_TOKEN constant in wp-config.php overrides the stored option.
 */
function svc_get_token() {
	if ( defined( 'VIMEO_TOKEN' ) && VIMEO_TOKEN ) {
		return VIMEO_TOKEN;
	}

	$settings = svc_get_settings();

	return $settings['vimeo_token'];
}

/**
 * Format a duration in seconds as ISO 8601 (PT1H5M30S) for VideoObject schema.
 */
function svc_format_duration( $seconds ) {
	$seconds = (int) $seconds;
	if ( $seconds <= 0 ) {
		return '';
	}

	$hours   = floor( $seconds / 3600 );
	$minutes = floor( ( $seconds % 3600 ) / 60 );
	$secs    = $seconds % 60;

	$duration = 'PT';
	if ( $hours > 0 ) {
		$duration .= $hours . 'H';
	}
	if ( $minutes > 0 ) {
		$duration .= $minutes . 'M';
	}
	if ( $secs > 0 ) {
		$duration .= $secs . 'S';
	}

	return $duration;
}

/**
 * Render the video player.
 *
 * 'facade' (default): poster image + play button; assets/player.js swaps in
 * the Vimeo iframe on click. Fast, no third-party requests — used on list
 * pages and embeds.
 *
 * 'embed': the real Vimeo iframe, rendered immediately. Used on single video
 * pages so search engines see an actual player and classify them as watch
 * pages — a facade renders as div+img+button, i.e. no video at all to a
 * crawler that doesn't click.
 */
function svc_render_player( $post_id, $mode = 'facade' ) {
	$vimeo_id = get_post_meta( $post_id, '_vimeo_id', true );
	if ( ! $vimeo_id ) {
		return;
	}

	$title = get_the_title( $post_id );

	if ( 'embed' === $mode ) {
		?>
		<div class="svc-player svc-playing">
			<iframe src="<?php echo esc_url( 'https://player.vimeo.com/video/' . rawurlencode( $vimeo_id ) . '?dnt=1' ); ?>"
				title="<?php echo esc_attr( $title ); ?>"
				allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
		</div>
		<?php
		return;
	}

	$poster = get_the_post_thumbnail_url( $post_id, 'large' );
	?>
	<div class="svc-player" data-vimeo-id="<?php echo esc_attr( $vimeo_id ); ?>" data-title="<?php echo esc_attr( $title ); ?>">
		<?php if ( $poster ) : ?>
			<img class="svc-poster" src="<?php echo esc_url( $poster ); ?>" alt="<?php echo esc_attr( $title ); ?>">
		<?php endif; ?>
		<button type="button" class="svc-play" aria-label="<?php echo esc_attr( sprintf( __( 'Play %s', 'parish-video-center' ), $title ) ); ?>">
			<svg width="68" height="68" viewBox="0 0 68 68" fill="none" aria-hidden="true" focusable="false">
				<circle cx="34" cy="34" r="34" fill="rgba(0,0,0,0.7)"/>
				<path d="M45 34L28 44V24L45 34Z" fill="white"/>
			</svg>
		</button>
	</div>
	<?php
}

/**
 * Render a video description: Vimeo descriptions are plain text, so escape,
 * linkify URLs, and convert line breaks to paragraphs.
 */
function svc_render_description( $post ) {
	$text = is_object( $post ) ? $post->post_content : '';
	if ( '' === trim( (string) $text ) ) {
		return '';
	}

	return wp_kses_post( wpautop( make_clickable( esc_html( $text ) ) ) );
}

/**
 * Human-readable duration for display, e.g. "32 min" or "1 hr 5 min".
 */
function svc_human_duration( $seconds ) {
	$seconds = (int) $seconds;
	if ( $seconds <= 0 ) {
		return '';
	}

	$hours   = (int) floor( $seconds / 3600 );
	$minutes = (int) round( ( $seconds % 3600 ) / 60 );
	if ( 60 === $minutes ) {
		$hours++;
		$minutes = 0;
	}

	if ( $hours > 0 ) {
		if ( $minutes > 0 ) {
			/* translators: 1: hours, 2: minutes */
			return sprintf( __( '%1$d hr %2$d min', 'parish-video-center' ), $hours, $minutes );
		}
		/* translators: %d: number of hours */
		return sprintf( __( '%d hr', 'parish-video-center' ), $hours );
	}

	/* translators: %d: number of minutes */
	return sprintf( __( '%d min', 'parish-video-center' ), max( 1, $minutes ) );
}

/**
 * Render one thumbnail tile: poster, duration chip, title overlay.
 * Shared by the archive grid and the recirculation module.
 */
function svc_render_tile( $tile_post ) {
	$duration = svc_human_duration( get_post_meta( $tile_post->ID, '_vimeo_duration', true ) );
	?>
	<a href="<?php echo esc_url( get_permalink( $tile_post ) ); ?>" class="svc-thumbnail">
		<?php if ( has_post_thumbnail( $tile_post ) ) : ?>
			<?php echo get_the_post_thumbnail( $tile_post, 'medium_large', array( 'class' => 'svc-thumb-img', 'loading' => 'lazy' ) ); ?>
		<?php endif; ?>
		<?php if ( $duration ) : ?>
			<span class="svc-thumb-duration"><?php echo esc_html( $duration ); ?></span>
		<?php endif; ?>
		<div class="svc-thumb-title"><?php echo esc_html( get_the_title( $tile_post ) ); ?></div>
	</a>
	<?php
}

/**
 * Render the recirculation module: a grid of other recent videos with
 * thumbnail tiles, shown at the bottom of single video pages. The count
 * is filterable via 'svc_related_count'; 0 disables the module.
 */
function svc_render_related( $post_id, $count = 4 ) {
	$count = (int) apply_filters( 'svc_related_count', $count, $post_id );
	if ( $count < 1 ) {
		return;
	}

	$related = get_posts(
		array(
			'post_type'      => SVC_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'post__not_in'   => array( (int) $post_id ),
			'no_found_rows'  => true,
		)
	);

	if ( ! $related ) {
		return;
	}

	$settings = svc_get_settings();
	$plural   = '' !== trim( $settings['plural'] ) ? $settings['plural'] : 'Videos';
	/* translators: %s: plural video label */
	$heading = sprintf( __( 'More %s', 'parish-video-center' ), $plural );
	?>
	<aside class="svc-related" aria-label="<?php echo esc_attr( $heading ); ?>">
		<h2 class="svc-related-title"><?php echo esc_html( $heading ); ?></h2>
		<div class="svc-gallery">
			<?php foreach ( $related as $related_post ) : ?>
				<?php svc_render_tile( $related_post ); ?>
			<?php endforeach; ?>
		</div>
		<p class="svc-related-all">
			<a href="<?php echo esc_url( get_post_type_archive_link( SVC_Post_Type::POST_TYPE ) ); ?>"><?php
				/* translators: %s: plural video label */
				echo esc_html( sprintf( __( 'View all %s', 'parish-video-center' ), $plural ) );
			?> &rarr;</a>
		</p>
	</aside>
	<?php
}

/**
 * Open the page chrome. Classic themes get get_header(); block themes have no
 * header.php, so render the document shell and the header template part directly.
 */
function svc_get_header() {
	if ( ! wp_is_block_theme() ) {
		get_header();
		return;
	}
	?><!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); ?>
	</head>
	<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<div class="wp-site-blocks">
	<?php block_template_part( 'header' ); ?>
	<?php
}

/**
 * Close the page chrome opened by svc_get_header().
 */
function svc_get_footer() {
	if ( ! wp_is_block_theme() ) {
		get_footer();
		return;
	}
	block_template_part( 'footer' );
	?>
	</div>
	<?php wp_footer(); ?>
	</body>
	</html>
	<?php
}

/**
 * Render the theme's page-header partial when it provides one. Diocesan themes
 * (Celine et al.) put the page-title banner there — and it also closes the
 * .site-content div that their header.php opens, so on those themes skipping it
 * breaks the document structure. Returns true when rendered so templates can
 * skip their own <h1> (the banner already shows the title).
 */
function svc_theme_page_header() {
	if ( locate_template( 'template-parts/headers/page-header.php' ) ) {
		get_template_part( 'template-parts/headers/page-header' );
		return true;
	}
	return false;
}

register_activation_hook( __FILE__, function () {
	SVC_Post_Type::register();
	flush_rewrite_rules();
	SVC_Sync::schedule();
} );

register_deactivation_hook( __FILE__, function () {
	SVC_Sync::unschedule();
	flush_rewrite_rules();
} );

// Settings shortcut on the Plugins screen.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( SVC_Settings::url() ) . '">' . esc_html__( 'Settings', 'parish-video-center' ) . '</a>'
	);
	return $links;
} );

// Serve plugin templates for the video post type unless the theme provides its own.
add_filter( 'template_include', function ( $template ) {
	if ( is_singular( SVC_Post_Type::POST_TYPE ) ) {
		$theme_template = locate_template( 'single-' . SVC_Post_Type::POST_TYPE . '.php' );
		return $theme_template ? $theme_template : SVC_PLUGIN_DIR . 'templates/single-video.php';
	}

	if ( is_post_type_archive( SVC_Post_Type::POST_TYPE ) ) {
		$theme_template = locate_template( 'archive-' . SVC_Post_Type::POST_TYPE . '.php' );
		return $theme_template ? $theme_template : SVC_PLUGIN_DIR . 'templates/archive-video.php';
	}

	return $template;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( SVC_Post_Type::POST_TYPE ) && ! is_post_type_archive( SVC_Post_Type::POST_TYPE ) ) {
		return;
	}

	wp_enqueue_style( 'svc-video-center', SVC_PLUGIN_URL . 'assets/video-center.css', array(), SVC_VERSION );
	wp_enqueue_script( 'svc-player', SVC_PLUGIN_URL . 'assets/player.js', array(), SVC_VERSION, true );
} );

// Single pages embed the Vimeo player immediately — warm the connections early.
add_action( 'wp_head', function () {
	if ( ! is_singular( SVC_Post_Type::POST_TYPE ) ) {
		return;
	}
	foreach ( array( 'https://player.vimeo.com', 'https://i.vimeocdn.com', 'https://f.vimeocdn.com' ) as $svc_origin ) {
		printf( '<link rel="preconnect" href="%s" crossorigin>' . "\n", esc_url( $svc_origin ) );
	}
}, 3 );

// Apply the configured per-page count to the archive.
add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_post_type_archive( SVC_Post_Type::POST_TYPE ) ) {
		$settings = svc_get_settings();
		$query->set( 'posts_per_page', max( 1, (int) $settings['per_page'] ) );
	}
} );
