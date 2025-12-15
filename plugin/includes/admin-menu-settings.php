<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * IKA Gamification – Settings + Admin Menu
 *
 * - Top-level "IKA Gamification" menu.
 * - Settings page for feature toggles.
 * - Submenu that reuses the existing Stats Rebuild screen.
 */

/* ----------------------------------------------------------------------
 * Settings helpers
 * ------------------------------------------------------------------- */

/**
 * Default settings.
 */
function ika_gam_get_default_settings() {
    return array(
        'enable_daily_missions' => 1,
        'enable_avatar_modal'   => 1,
    );
}

/**
 * Get all settings, merged with defaults.
 */
function ika_gam_get_settings() {
    $defaults = ika_gam_get_default_settings();
    $settings = get_option( 'ika_gam_settings', array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    // settings override defaults, but fall back if missing.
    return wp_parse_args( $settings, $defaults );
}

/**
 * Convenience helper: get a single setting as bool.
 *
 * @param string $key
 * @return bool
 */
function ika_gam_get_setting( $key ) {
	// Bridge legacy settings -> feature flags (avoids having 2 separate toggle systems).
	// Keep this mapping minimal and backwards compatible.
	if ( function_exists( 'ika_gam_feature_enabled' ) ) {
		if ( $key === 'enable_daily_missions' ) {
			return ika_gam_feature_enabled( 'missions' );
		}
		if ( $key === 'enable_avatar_modal' ) {
			return ika_gam_feature_enabled( 'watuplay' );
		}
	}

	$settings = ika_gam_get_settings();

	if ( isset( $settings[ $key ] ) ) {
		return (bool) $settings[ $key ];
	}

	// If unknown key, default to false.
	return false;
}

/* ----------------------------------------------------------------------
 * Admin menu
 * ------------------------------------------------------------------- */

/**
 * Register "IKA Gamification" top-level menu and submenus.
 */
add_action( 'admin_menu', 'ika_gam_register_admin_menu' );

function ika_gam_register_admin_menu() {
    // Top-level page.
    add_menu_page(
        'IKA Gamification',             // Page title
        'IKA Gamification',             // Menu title
        'manage_options',               // Capability
        'ika-gamification',             // Menu slug
        'ika_gam_render_settings_page', // Callback
        'dashicons-awards',             // Icon
        60                              // Position
    );

    // "Settings" submenu (points to the same slug/callback as parent).
    add_submenu_page(
        'ika-gamification',
        'Gamification Settings',
        'Settings',
        'manage_options',
        'ika-gamification',
        'ika_gam_render_settings_page'
    );

    // "Stats Rebuild" submenu: reuse existing page renderer from stats-rebuild.php.
    add_submenu_page(
        'ika-gamification',
        'Stats Rebuild',
        'Stats Rebuild',
        'manage_options',
        'ika-gam-stats-rebuild',
        'ika_render_stats_rebuild_page'
    );
}

/* ----------------------------------------------------------------------
 * Settings page renderer
 * ------------------------------------------------------------------- */

/**
 * Render the main Settings page.
 */
function ika_gam_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $updated = false;

    // Handle form submit.
    if ( isset( $_POST['ika_gam_settings_submit'] ) ) {
        check_admin_referer( 'ika_gam_settings_save', 'ika_gam_settings_nonce' );

        $new_settings = array(
            'enable_daily_missions' => ! empty( $_POST['enable_daily_missions'] ) ? 1 : 0,
            'enable_avatar_modal'   => ! empty( $_POST['enable_avatar_modal'] ) ? 1 : 0,
        );

        update_option( 'ika_gam_settings', $new_settings );

		// Also persist into the feature flags system if available.
		// This makes the toggles actually control the underlying subsystems.
		if ( function_exists( 'ika_gam_set_feature_flag' ) ) {
			ika_gam_set_feature_flag( 'missions', ! empty( $new_settings['enable_daily_missions'] ) );
			ika_gam_set_feature_flag( 'watuplay', ! empty( $new_settings['enable_avatar_modal'] ) );
		}
        $updated = true;
    }

	// Read status from the bridge (feature flags first, then legacy option).
	$daily_on  = ika_gam_get_setting( 'enable_daily_missions' );
	$avatar_on = ika_gam_get_setting( 'enable_avatar_modal' );

    $daily_label  = $daily_on ? 'ON' : 'OFF';
    $avatar_label = $avatar_on ? 'ON' : 'OFF';

    $daily_class  = $daily_on ? 'ika-gam-status-on' : 'ika-gam-status-off';
    $avatar_class = $avatar_on ? 'ika-gam-status-on' : 'ika-gam-status-off';
    ?>
    <div class="wrap">
        <h1>IKA Gamification – Settings</h1>

        <?php if ( $updated ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Settings saved.', 'iknowaviation-gamification' ); ?></p>
            </div>
        <?php endif; ?>

        <!-- Status summary box -->
        <style>
            .ika-gam-status-box {
                margin: 20px 0;
                padding: 15px 20px;
                border-left: 4px solid #2271b1;
                background: #f0f6fc;
                max-width: 600px;
            }
            .ika-gam-status-box h2 {
                margin-top: 0;
            }
            .ika-gam-status-list {
                margin: 0;
                padding-left: 18px;
            }
            .ika-gam-status-list li {
                margin-bottom: 4px;
            }
            .ika-gam-status-on {
                font-weight: 600;
                color: #008700;
            }
            .ika-gam-status-off {
                font-weight: 600;
                color: #b32d2e;
            }
        </style>

        <div class="ika-gam-status-box">
            <h2>Current Status</h2>
            <ul class="ika-gam-status-list">
                <li>
                    Daily Missions:
                    <span class="<?php echo esc_attr( $daily_class ); ?>">
                        <?php echo esc_html( $daily_label ); ?>
                    </span>
                </li>
                <li>
                    Level + Badges Avatar Modal:
                    <span class="<?php echo esc_attr( $avatar_class ); ?>">
                        <?php echo esc_html( $avatar_label ); ?>
                    </span>
                </li>
            </ul>
            <p style="margin-top:8px;">
                Need to rebuild quiz stats? Go to
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ika-gam-stats-rebuild' ) ); ?>">
                    Stats Rebuild
                </a>.
            </p>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'ika_gam_settings_save', 'ika_gam_settings_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="enable_daily_missions">Daily Missions</label>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    id="enable_daily_missions"
                                    name="enable_daily_missions"
                                    value="1"
                                    <?php checked( $daily_on, true ); ?>
                                />
                                Enable the Daily Missions panel, streak, and XP rewards.
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="enable_avatar_modal">Level + Badges avatar modal</label>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    id="enable_avatar_modal"
                                    name="enable_avatar_modal"
                                    value="1"
                                    <?php checked( $avatar_on, true ); ?>
                                />
                                Enable the unified Watu Play level + badges modal and avatar picker.
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button( __( 'Save Changes', 'iknowaviation-gamification' ), 'primary', 'ika_gam_settings_submit' ); ?>
</form>

<hr />

<h2>Tools</h2>

<p>
    You can rebuild all quiz stats from WatuPRO data on the
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ika-gam-stats-rebuild' ) ); ?>">
        Stats Rebuild
    </a>
    page.
</p>

<form method="post" style="margin-top:20px;">
    <?php wp_nonce_field( 'ika_gam_clear_cache', 'ika_gam_clear_cache_nonce' ); ?>

    <p><strong>Watu Play Cache:</strong></p>

    <p>
        <input type="submit"
               name="ika_gam_clear_cache_submit"
               class="button button-secondary"
               value="Clear Watu Play Cache Now">
    </p>
	</form>

	<?php
	// Handle cache clearing submit.
	if ( isset( $_POST['ika_gam_clear_cache_submit'] ) ) {
		if ( check_admin_referer( 'ika_gam_clear_cache', 'ika_gam_clear_cache_nonce' ) ) {

			if ( function_exists( 'ika_watuproplay_flush_cache' ) ) {
				ika_watuproplay_flush_cache();
			}

			echo '<div class="notice notice-success is-dismissible" style="margin-top:15px;">
					<p>Watu Play cache cleared successfully.</p>
				  </div>';
		}
	}
}
