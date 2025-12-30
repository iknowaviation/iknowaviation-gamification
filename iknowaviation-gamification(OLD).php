<?php
/**
 * Plugin Name: iKnowAviation – Gamification Engine
 * Description: Centralized gamification logic for WatuPRO, Watu Play, UsersWP, and Daily Missions.
 * Author: I Know Aviation LLC
 * Version: 1.2.2
 * Text Domain: iknowaviation-gamification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Constants
 */
define( 'IKA_GAM_PLUGIN_VERSION', '1.2.2' );
define( 'IKA_GAM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'IKA_GAM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Feature flags MUST load before conditional includes.
 */
require_once IKA_GAM_PLUGIN_PATH . 'includes/feature-flags.php';

/**
 * Helper: safe include (prevents fatal if a file was not uploaded yet)
 */
if ( ! function_exists( 'ika_gam_safe_require' ) ) {
	function ika_gam_safe_require( string $rel_path ) : void {
		$full = IKA_GAM_PLUGIN_PATH . ltrim( $rel_path, '/' );
		if ( file_exists( $full ) ) {
			require_once $full;
		}
	}
}

/**
 * Core modules (always-on)
 */
ika_gam_safe_require( 'includes/ranks-xp-core.php' );
ika_gam_safe_require( 'includes/stats-rebuild.php' );
ika_gam_safe_require( 'includes/quiz-taxonomies.php' );
ika_gam_safe_require( 'includes/quiz-wrapper.php' );
ika_gam_safe_require( 'includes/frontend-deps.php' );

/**
 * WatuPRO admin hooks + dashboards
 */
ika_gam_safe_require( 'includes/watupro-hooks-admin.php' );
ika_gam_safe_require( 'includes/watupro-dashboard-shortcodes.php' );

/**
 * Streaks + user status
 */
ika_gam_safe_require( 'includes/streaks-status.php' );

/**
 * Rank/XP shortcodes (Flight Deck)
 * NOTE: These MUST be loaded for [ika_rank_*] and [ika_xp_*] shortcodes to work.
 */
ika_gam_safe_require( 'includes/hero-metrics-shortcodes.php' );
ika_gam_safe_require( 'includes/rank-card-shortcodes.php' );

/**
 * Missions (Flight Deck dashboard preview)
 */
ika_gam_safe_require( 'includes/missions-shortcodes.php' );

/**
 * Badges (Flight Deck dashboard preview)
 */
ika_gam_safe_require( 'includes/badges-shortcodes.php' );

/**
 * Flight Log (Flight Deck dashboard preview)
 */
ika_gam_safe_require( 'includes/flightlog-shortcodes.php' );

/**
 * Leaderboard (Flight Deck wrapper)
 */
ika_gam_safe_require( 'includes/leaderboard-shortcodes.php' );

/**
 * Recommendations rail (Flight Deck + Results)
 * NOTE: If this file isn't present on the server yet, safe include prevents a fatal.
 */
ika_gam_safe_require( 'includes/recommendations-shortcodes.php' );

/**
 * Leaderboard
 */
ika_gam_safe_require( 'includes/leaderboard.php' );

/**
 * Watu Play modal + levels
 */
ika_gam_safe_require( 'includes/watuplay-avatar-modal.php' );
ika_gam_safe_require( 'includes/watuproplay-levels.php' );

/**
 * Admin UI
 */
ika_gam_safe_require( 'includes/admin-debug-panel.php' );
ika_gam_safe_require( 'includes/admin-menu-settings.php' );
ika_gam_safe_require( 'includes/admin-tools-shortcodes.php' );

/**
 * Importer
 */
ika_gam_safe_require( 'includes/tools/class-ika-watupro-importer.php' );

/**
 * Daily missions scaffold
 */
ika_gam_safe_require( 'includes/daily-missions.php' );
