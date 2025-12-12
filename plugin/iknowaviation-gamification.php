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

// Basic constants.
define( 'IKA_GAM_PLUGIN_VERSION', '1.1.0' );
define( 'IKA_GAM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'IKA_GAM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Core gamification modules (split from your former gamification-core.php).
require_once IKA_GAM_PLUGIN_PATH . 'includes/watupro-dashboard-shortcodes.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/watuplay-avatar-modal.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/frontend-deps.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/ranks-xp-core.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/streaks-status.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/hero-metrics-shortcodes.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/rank-card-shortcodes.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/leaderboard.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/stats-rebuild.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/watupro-hooks-admin.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/admin-menu-settings.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/admin-tools-shortcodes.php';
require_once IKA_GAM_PLUGIN_PATH . 'includes/watuproplay-levels.php';

// Daily Missions subsystem (keep your existing module file as-is).
if ( file_exists( IKA_GAM_PLUGIN_PATH . 'includes/daily-missions.php' ) ) {
    require_once IKA_GAM_PLUGIN_PATH . 'includes/daily-missions.php';
}

// Bootstrap hook.
add_action( 'plugins_loaded', function() {
    // Place for future setup if needed (textdomains, etc.).
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

    $required = array(
        'Watu PRO'      => 'watu-pro/watu-pro.php',
        'Watu PRO Play' => 'watu-pro-play/watu-pro-play.php',
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
