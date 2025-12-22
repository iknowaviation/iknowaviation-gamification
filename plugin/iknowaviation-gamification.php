<?php
/**
 * Plugin Name: iKnowAviation – Gamification Engine
 * Description: Centralized gamification logic for WatuPRO, Watu Play, UsersWP, and Daily Missions.
 * Author: I Know Aviation LLC
 * Version: 1.1.0
 * Text Domain: iknowaviation-gamification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Constants
 */
define( 'IKA_GAM_PLUGIN_VERSION', '1.1.0' );
define( 'IKA_GAM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'IKA_GAM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );


/**
 * Feature flags MUST load before conditional includes.
 */
require_once IKA_GAM_PLUGIN_PATH . 'includes/feature-flags.php';


/**
 * Quiz CPT permalink support:
 * Ensure hierarchical quiz URLs like /quiz/intro-to-aviation/quiz-1/ work.
 * This forces rewrite rules to accept parent/child segments.
 *
 * NOTE: Flush permalinks after deploying this change.
 */
add_filter( 'register_post_type_args', function( $args, $post_type ) {

	if ( $post_type !== 'quiz' ) {
		return $args;
	}

	// Ensure hierarchical behavior on the post type itself.
	$args['hierarchical'] = true;

	// Ensure Page Attributes support so "Parent" UI works.
	$args['supports'] = isset( $args['supports'] ) && is_array( $args['supports'] ) ? $args['supports'] : [];
	if ( ! in_array( 'page-attributes', $args['supports'], true ) ) {
		$args['supports'][] = 'page-attributes';
	}

	// CRITICAL: allow hierarchical URLs like /quiz/parent/child/
	if ( empty( $args['rewrite'] ) || ! is_array( $args['rewrite'] ) ) {
		$args['rewrite'] = [];
	}

	if ( empty( $args['rewrite']['slug'] ) ) {
		$args['rewrite']['slug'] = 'quiz';
	}

	$args['rewrite']['hierarchical'] = true;

	return $args;

}, 20, 2 );

/**
 * Resolve hierarchical Quiz CPT URLs like:
 * /quiz/intro-to-aviation/importer-wp-parenttax-test-fixed-v2/
 *
 * WordPress can be inconsistent resolving hierarchical CPT URLs on some installs,
 * especially when the CPT is registered via CPT UI. This request filter resolves
 * the full path against the 'quiz' post type and forces WP to load the correct post.
 */
/**
 * Quiz CPT – True hierarchy URLs
 *
 * WordPress is inconsistent with hierarchical URLs for hierarchical CPTs.
 * This resolver makes URLs like:
 *   /quiz/{parent}/{child}/
 * work reliably by resolving the child post by slug and validating the requested
 * parent slug against the child’s ancestor chain.
 *
 * It only targets multi-segment /quiz/.../... requests.
 */

/* Debug header (safe to leave; remove later if you want). */
add_action( 'send_headers', function () {
	if ( is_admin() ) return;

	$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( strpos( $path, '/quiz/' ) === 0 || strpos( $path, '/index.php/quiz/' ) === 0 ) {
		header( 'X-IKA-Quiz-Router: active' );
	}
} );

/* Resolve /quiz/{parent}/{child}/ (and deeper) to the child quiz post. */
add_filter( 'request', function( $qv ) {

	if ( is_admin() ) {
		return $qv;
	}

	$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	if ( $path === '' ) {
		return $qv;
	}

	// Support /index.php/quiz/... as well.
	if ( strpos( $path, 'index.php/' ) === 0 ) {
		$path = ltrim( substr( $path, 10 ), '/' );
	}

	if ( strpos( $path, 'quiz/' ) !== 0 ) {
		return $qv;
	}

	$rel = trim( substr( $path, 5 ), '/' ); // strip 'quiz/'
	if ( $rel === '' ) {
		return $qv;
	}

	$parts = array_values( array_filter( explode( '/', $rel ) ) );

	// Only handle multi-segment paths (parent/child or deeper).
	if ( count( $parts ) < 2 ) {
		return $qv;
	}

	$parent_slug = sanitize_title( (string) $parts[0] );
	$child_slug  = sanitize_title( (string) $parts[ count( $parts ) - 1 ] );

	// Resolve the child quiz by slug.
	$child = get_page_by_path( $child_slug, OBJECT, 'quiz' );
	if ( ! $child || empty( $child->ID ) ) {
		$found = get_posts( [
			'name'           => $child_slug,
			'post_type'      => 'quiz',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		] );
		$child = $found ? $found[0] : null;
	}

	if ( ! $child || empty( $child->ID ) ) {
		return $qv;
	}

	// Validate that the requested parent slug is actually in the ancestor chain.
	$ancestors = get_post_ancestors( $child );
	$ok = false;
	foreach ( $ancestors as $aid ) {
		if ( get_post_field( 'post_name', $aid ) === $parent_slug ) {
			$ok = true;
			break;
		}
	}

	if ( ! $ok ) {
		return $qv;
	}

	// Force WP to load the child quiz post.
	return [
		'post_type' => 'quiz',
		'p'         => (int) $child->ID,
		'name'      => $child->post_name,
	];

}, 0 );

add_filter( 'redirect_canonical', function( $redirect_url, $requested_url ) {

	$path = (string) wp_parse_url( $requested_url, PHP_URL_PATH );

	if ( strpos( $path, '/quiz/' ) === 0 || strpos( $path, '/index.php/quiz/' ) === 0 ) {
		return false;
	}

	return $redirect_url;

}, 10, 2 );




/**
 * Core modules (keep always-on unless you have a reason to gate them)
 */
require_once IKA_GAM_PLUGIN_PATH . 'includes/ranks-xp-core.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/stats-rebuild.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/quiz-taxonomies.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/watupro/quiz-wrapper.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/quiz-rewrites.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/quiz-hierarchy-router.php';

/**
 * Optional improvement:
 * Gate WatuPRO-related hooks behind the XP flag so a Watu/XP subsystem issue
 * can be turned off without taking down the whole plugin.
 */
if ( function_exists( 'ika_gam_feature_enabled' ) && ika_gam_feature_enabled( 'xp' ) ) {
	require_once IKA_GAM_PLUGIN_PATH . 'includes/watupro-hooks-admin.php';
}

/**
 * Optional modules behind flags (turn on/off in Debug Panel)
 */
if ( function_exists( 'ika_gam_feature_enabled' ) && ika_gam_feature_enabled( 'xp' ) ) {
	require_once IKA_GAM_PLUGIN_PATH . 'includes/watupro-dashboard-shortcodes.php';
}

if ( function_exists( 'ika_gam_feature_enabled' ) && ika_gam_feature_enabled( 'streaks' ) ) {
	require_once IKA_GAM_PLUGIN_PATH . 'includes/streaks-status.php';
}

if ( function_exists( 'ika_gam_feature_enabled' ) && ika_gam_feature_enabled( 'ranks' ) ) {
	require_once IKA_GAM_PLUGIN_PATH . 'includes/hero-metrics-shortcodes.php';
	require_once IKA_GAM_PLUGIN_PATH . 'includes/rank-card-shortcodes.php';
}

if ( function_exists( 'ika_gam_feature_enabled' ) && ika_gam_feature_enabled( 'leaderboard' ) ) {
	require_once IKA_GAM_PLUGIN_PATH . 'includes/leaderboard.php';
}

if ( function_exists( 'ika_gam_feature_enabled' ) && ika_gam_feature_enabled( 'watuplay' ) ) {
	require_once IKA_GAM_PLUGIN_PATH . 'includes/watuplay-avatar-modal.php';
	require_once IKA_GAM_PLUGIN_PATH . 'includes/watuproplay-levels.php';
}

if ( function_exists( 'ika_gam_feature_enabled' ) && ika_gam_feature_enabled( 'admin_tools' ) ) {
	require_once IKA_GAM_PLUGIN_PATH . 'includes/admin-debug-panel.php';
}

/**
 * Admin settings + tools (you can gate these later if desired)
 */
require_once IKA_GAM_PLUGIN_PATH . 'includes/admin-menu-settings.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/admin-tools-shortcodes.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/tools/class-ika-watupro-importer.php';

/**
 * Daily Missions subsystem (optional + file-exists safe)
 */
if (
	function_exists( 'ika_gam_feature_enabled' )
	&& ika_gam_feature_enabled( 'missions' )
	&& file_exists( IKA_GAM_PLUGIN_PATH . 'includes/daily-missions.php' )
) {
	require_once IKA_GAM_PLUGIN_PATH . 'includes/daily-missions.php';
}

/**
 * Bootstrap hook (future use)
 */
add_action( 'plugins_loaded', function() {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[IKA] Gamification Engine loaded: ' . IKA_GAM_PLUGIN_VERSION );
	}
} );

/**
 * Show plugin version in WP Admin → Plugins list
 */
add_filter( 'plugin_row_meta', function( $links, $file ) {

	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = sprintf(
			'IKA Version: <strong>%s</strong>',
			esc_html( IKA_GAM_PLUGIN_VERSION )
		);
	}

	return $links;

}, 10, 2 );

/**
 * Admin-only dependency notice (non-fatal).
 */
add_action( 'admin_notices', function() {

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// IMPORTANT: These are the slugs you confirmed are correct on your site.
	$required = array(
		'Watu PRO'      => 'watupro/watupro.php',
		'Watu PRO Play' => 'watupro-play/watupro-play.php',
		'UsersWP'       => 'userswp/userswp.php',
	);

	$missing = array();

	foreach ( $required as $label => $slug ) {
		if ( ! is_plugin_active( $slug ) ) {
			$missing[] = $label;
		}
	}

	if ( empty( $missing ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo '<strong>iKnowAviation – Gamification Engine:</strong> ';
	echo 'The following required plugin(s) are inactive: ';
	echo esc_html( implode( ', ', $missing ) );
	echo '. Some gamification features may not work until they are activated.';
	echo '</p></div>';
} );
