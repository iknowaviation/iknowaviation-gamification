<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin Debug Panel
 * Safe diagnostics + feature flag toggles + maintenance actions.
 */

add_action( 'admin_menu', function() {

    // Honor feature flag
    if ( function_exists( 'ika_gam_feature_enabled' ) && ! ika_gam_feature_enabled( 'admin_tools' ) ) {
        return;
    }

    add_submenu_page(
        'tools.php',
        'IKA Gamification Debug',
        'IKA Gamification Debug',
        'manage_options',
        'ika-gam-debug',
        'ika_gam_render_debug_page'
    );
}, 20 );

/** Render the admin page */
function ika_gam_render_debug_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle flag save
    if ( isset( $_POST['ika_gam_save_flags'] ) ) {
        check_admin_referer( 'ika_gam_save_flags' );

        $defaults = function_exists( 'ika_gam_feature_flags_defaults' )
            ? ika_gam_feature_flags_defaults()
            : array();

        foreach ( $defaults as $key => $default ) {
            $enabled = isset( $_POST['flags'][ $key ] ) ? true : false;
            ika_gam_set_feature_flag( $key, $enabled );
        }

        echo '<div class="notice notice-success"><p>Feature flags updated.</p></div>';
    }

    // Basic dependency status (using slugs confirmed from your screenshot)
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $deps = array(
        'Watu PRO'      => 'watu-pro/watu-pro.php',
        'Watu PRO Play' => 'watu-pro-play/watu-pro-play.php',
        'UsersWP'       => 'userswp/userswp.php',
    );

    $flags = function_exists( 'ika_gam_get_feature_flags' ) ? ika_gam_get_feature_flags() : array();

    ?>
    <div class="wrap">
        <h1>IKA Gamification Debug</h1>

        <p><strong>Plugin Version:</strong> <?php echo esc_html( defined('IKA_GAM_PLUGIN_VERSION') ? IKA_GAM_PLUGIN_VERSION : 'unknown' ); ?></p>

        <hr />

        <h2>Dependencies</h2>
        <table class="widefat striped">
            <thead><tr><th>Plugin</th><th>Status</th><th>Slug</th></tr></thead>
            <tbody>
            <?php foreach ( $deps as $label => $slug ): ?>
                <tr>
                    <td><?php echo esc_html( $label ); ?></td>
                    <td>
                        <?php if ( is_plugin_active( $slug ) ): ?>
                            <span style="color: #0a7a0a; font-weight: 600;">Active</span>
                        <?php else: ?>
                            <span style="color: #b00020; font-weight: 600;">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html( $slug ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <hr />

        <h2>Feature Flags</h2>
        <form method="post">
            <?php wp_nonce_field( 'ika_gam_save_flags' ); ?>
            <input type="hidden" name="ika_gam_save_flags" value="1" />

            <table class="widefat striped">
                <thead><tr><th>Flag</th><th>Enabled</th><th>Description</th></tr></thead>
                <tbody>
                    <?php foreach ( ika_gam_feature_flags_defaults() as $key => $default ): ?>
                        <tr>
                            <td><code><?php echo esc_html( $key ); ?></code></td>
                            <td>
                                <label>
                                    <input type="checkbox" name="flags[<?php echo esc_attr( $key ); ?>]" <?php checked( ! empty( $flags[ $key ] ) ); ?> />
                                    On
                                </label>
                            </td>
                            <td>
                                <?php echo esc_html( ika_gam_flag_description( $key ) ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:12px;">
                <button class="button button-primary" type="submit">Save Feature Flags</button>
            </p>
        </form>

        <hr />

        <h2>Maintenance Actions (safe hooks)</h2>
        <p>These buttons call plugin hooks. If a hook isn’t implemented yet, nothing happens (safe).</p>

        <p>
            <a class="button" href="<?php echo esc_url( ika_gam_admin_action_url( 'ika_gam_rebuild_stats' ) ); ?>">
                Rebuild Stats
            </a>

            <a class="button" href="<?php echo esc_url( ika_gam_admin_action_url( 'ika_gam_rebuild_leaderboard_cache' ) ); ?>">
                Rebuild Leaderboard Cache
            </a>

            <a class="button button-secondary" href="<?php echo esc_url( ika_gam_admin_action_url( 'ika_gam_clear_transients' ) ); ?>">
                Clear IKA Transients
            </a>
        </p>

        <hr />

        <h2>Quick Diagnostics</h2>
        <ul style="line-height:1.8;">
            <li><strong>Site URL:</strong> <?php echo esc_html( site_url() ); ?></li>
            <li><strong>WP Version:</strong> <?php echo esc_html( get_bloginfo('version') ); ?></li>
            <li><strong>PHP Version:</strong> <?php echo esc_html( PHP_VERSION ); ?></li>
            <li><strong>Logged-in test:</strong> <?php echo is_user_logged_in() ? 'Yes' : 'No'; ?></li>
        </ul>
    </div>
    <?php
}

/** Friendly descriptions for flags */
function ika_gam_flag_description( $key ) {
    $map = array(
        'xp'          => 'Award and store XP from quizzes.',
        'ranks'       => 'Compute rank ladder + rank titles from XP.',
        'streaks'     => 'Daily/weekly streak tracking & status pills.',
        'leaderboard' => 'XP leaderboard output and related queries.',
        'missions'    => 'Daily Missions subsystem.',
        'watuplay'    => 'Watu PRO Play levels/badges modal + avatar sync.',
        'admin_tools' => 'Show this debug panel and admin tools.',
    );

    return isset( $map[ $key ] ) ? $map[ $key ] : '';
}

/** Build an admin-post URL with nonce */
function ika_gam_admin_action_url( $action ) {
    return wp_nonce_url(
        admin_url( 'admin-post.php?action=' . $action ),
        $action
    );
}

/**
 * Admin-post handlers that simply fire actions.
 * Your existing modules can hook into these.
 */
add_action( 'admin_post_ika_gam_rebuild_stats', function() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }
    check_admin_referer( 'ika_gam_rebuild_stats' );

    do_action( 'ika_gam_rebuild_stats' );

    wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'tools.php?page=ika-gam-debug' ) );
    exit;
} );

add_action( 'admin_post_ika_gam_rebuild_leaderboard_cache', function() {
   if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }
   
    check_admin_referer( 'ika_gam_rebuild_leaderboard_cache' );

    do_action( 'ika_gam_rebuild_leaderboard_cache' );

    wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'tools.php?page=ika-gam-debug' ) );
    exit;
} );

add_action( 'admin_post_ika_gam_clear_transients', function() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }
    check_admin_referer( 'ika_gam_clear_transients' );

    do_action( 'ika_gam_clear_transients' );

    wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'tools.php?page=ika-gam-debug' ) );
    exit;
} );

