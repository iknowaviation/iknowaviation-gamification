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


// NOTE: We intentionally avoid any custom rewrite/router logic for quiz URLs.
// Quizzes use stable flat permalinks like: /quiz/{quiz-slug}/
// Track alignment and course grouping is handled via taxonomies + post meta,
// not via URL hierarchy.




/**
 * Core modules (keep always-on unless you have a reason to gate them)
 */
require_once IKA_GAM_PLUGIN_PATH . 'includes/ranks-xp-core.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/stats-rebuild.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/quiz-taxonomies.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/quiz-wrapper.php';

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
