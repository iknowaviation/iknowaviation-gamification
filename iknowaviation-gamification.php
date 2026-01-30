<?php
/**
 * Plugin Name: iKnowAviation – Gamification Engine
 * Description: Centralized gamification logic for WatuPRO, Watu Play, UsersWP, and Daily Missions.
 * Author: I Know Aviation LLC
 * Version: 1.2.6
 * Text Domain: iknowaviation-gamification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Constants
 */
define( 'IKA_GAM_PLUGIN_VERSION', '1.2.6' );
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
ika_gam_safe_require( 'includes/xp-ledger.php' );
ika_gam_safe_require( 'includes/stats-rebuild.php' );
ika_gam_safe_require( 'includes/quiz-taxonomies.php' );
ika_gam_safe_require( 'includes/tools/class-ika-recommendations-v7.php' );
ika_gam_safe_require( 'includes/quiz-wrapper.php' );
// WatuPRO Quiz/Results shell templates (shortcodes)
ika_gam_safe_require( 'includes/watupro-templates-shortcodes.php' );
ika_gam_safe_require( 'includes/results-next-actions-shortcode.php' );
ika_gam_safe_require( 'includes/frontend-deps.php' );

/**
 * WatuPRO admin hooks + dashboards
 */
ika_gam_safe_require( 'includes/watupro-hooks-admin.php' );
ika_gam_safe_require( 'includes/watupro-dashboard-shortcodes.php' );

/**
 * Flight Deck shared helpers
 */
ika_gam_safe_require( 'includes/flightdeck-helpers.php' );

/**
 * Streaks + user status
 */
ika_gam_safe_require( 'includes/streaks-status.php' );

/**
 * Rank/XP shortcodes (Flight Deck)
 * NOTE: These MUST be loaded for [ika_rank_*] and [ika_xp_*] shortcodes to work.
 */
ika_gam_safe_require( 'includes/hero-metrics-shortcodes.php' );
ika_gam_safe_require( 'includes/ika-recent-xp-earned.php' );
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
ika_gam_safe_require( 'includes/recent-activity-shortcodes.php' );
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
ika_gam_safe_require( 'includes/quiz-hub-shortcodes.php' );

/**
 * Rank ladder helpers (Flight Deck)
 */
ika_gam_safe_require( 'includes/rank-ladder-shortcodes.php' );

/**
 * Leaderboard
 */
ika_gam_safe_require( 'includes/leaderboard.php' );

/**
 * Watu Play modal + levels
 */
ika_gam_safe_require( 'includes/watuplay-avatar-modal.php' );
ika_gam_safe_require( 'includes/watuproplay-levels.php' );

// Achievements (IKA-owned levels/badges + modal)
ika_gam_safe_require( 'includes/ika-achievements.php' );
ika_gam_safe_require( 'includes/ika-achievements-admin.php' );

/**
 * Admin UI
 */
ika_gam_safe_require( 'includes/admin-debug-panel.php' );
ika_gam_safe_require( 'includes/admin-menu-settings.php' );
ika_gam_safe_require( 'includes/admin-tools-shortcodes.php' );
ika_gam_safe_require( 'includes/admin-user-reset-tools.php' );

// Step 3: Legacy bonus → ledger migration (admin-only)
ika_gam_safe_require( 'includes/tools/class-ika-xp-legacy-bonus-migration.php' );

/**
 * Flight Deck — Recommended Next
 */
ika_gam_safe_require( 'includes/tools/class-ika-flightdeck-recommended-next.php' );
ika_gam_safe_require( 'includes/tools/class-ika-admin-recnext-cache.php' );
ika_gam_safe_require( 'includes/tools/class-ika-quiz-flightdeck-visibility.php' );
ika_gam_safe_require( 'includes/tools/class-ika-admin-recnext-settings.php' );

/**
 * Importer
 */
ika_gam_safe_require( 'includes/tools/class-ika-watupro-importer.php' );

/**
 * Daily missions scaffold
 */
ika_gam_safe_require( 'includes/daily-missions.php' );

/**
 * Flight Deck layout enforcement (Hub vs Workspace)
 *
 * Adds body classes so parent/child pages can be styled consistently by tokens:
 *   - ika-scope-flightdeck
 *   - ika-fd-layout--hub (exact hub page)
 *   - ika-fd-layout--workspace (any child/subpage under the Flight Deck parent)
 */
add_filter( 'body_class', function( array $classes ) : array {
	// Frontend only.
	if ( is_admin() ) {
		return $classes;
	}

	if ( ! is_page() ) {
		return $classes;
	}

	global $post;
	if ( ! $post instanceof WP_Post ) {
		return $classes;
	}

	// Hub page is the page with slug "flight-deck".
	$is_hub = ( $post->post_name === 'flight-deck' );

	// Workspace pages are any descendants of the Flight Deck hub.
	$is_descendant = false;
	if ( ! $is_hub ) {
		$ancestors = get_post_ancestors( $post );
		if ( ! empty( $ancestors ) ) {
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_post( (int) $ancestor_id );
				if ( $ancestor instanceof WP_Post && $ancestor->post_name === 'flight-deck' ) {
					$is_descendant = true;
					break;
				}
			}
		}
	}

	if ( $is_hub || $is_descendant ) {
		$classes[] = 'ika-scope-flightdeck';
		$classes[] = $is_hub ? 'ika-fd-layout--hub' : 'ika-fd-layout--workspace';
	}

	return $classes;
}, 20 );