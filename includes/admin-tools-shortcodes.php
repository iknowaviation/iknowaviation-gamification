<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * IKA Gamification – Admin tools shortcodes
 *
 * Shortcodes intended for admin/debug usage only.
 */

/**
 * [ika_stats_rebuild_admin_link label="Open Stats Rebuild"]
 *
 * Outputs a link to the Stats Rebuild screen, visible only to users
 * with manage_options (admins). Safe to drop into the Flight Deck page.
 */
function ika_gam_stats_rebuild_admin_link_shortcode( $atts ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '';
    }

    $atts = shortcode_atts(
        array(
            'label' => 'Open Stats Rebuild',
        ),
        $atts,
        'ika_stats_rebuild_admin_link'
    );

    $url   = admin_url( 'admin.php?page=ika-gam-stats-rebuild' );
    $label = esc_html( $atts['label'] );

    $html  = '<a href="' . esc_url( $url ) . '" class="ika-gam-admin-debug-link" target="_blank" rel="noopener noreferrer">';
    $html .= $label;
    $html .= '</a>';

    return $html;
}
add_shortcode( 'ika_stats_rebuild_admin_link', 'ika_gam_stats_rebuild_admin_link_shortcode' );

/**
 * [ika_debug_rank_ladder]
 *
 * Admin-only debug view showing:
 *  - The effective rank ladder from ika_get_rank_ladder()
 *  - The raw WatuPRO Play level thresholds (if available)
 *
 * Useful to verify that levels / required_points in Watu Play
 * match what the IKA gamification engine is actually using.
 */
function ika_gam_debug_rank_ladder_shortcode( $atts ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '';
    }

    // Effective ladder as used everywhere in the plugin.
    if ( function_exists( 'ika_get_rank_ladder' ) ) {
        $ladder = ika_get_rank_ladder();
    } else {
        $ladder = array();
    }

    // Raw Watu Play level thresholds (optional).
    if ( function_exists( 'ika_watuproplay_get_level_thresholds' ) ) {
        $thresholds = ika_watuproplay_get_level_thresholds();
    } else {
        $thresholds = array();
    }

    ob_start();
    ?>
    <style>
        .ika-gam-debug-ladder {
            margin: 20px 0;
            padding: 15px 20px;
            border-left: 4px solid #2271b1;
            background: #f0f6fc;
        }
        .ika-gam-debug-ladder h3 {
            margin-top: 0;
            margin-bottom: 8px;
        }
        .ika-gam-debug-ladder table {
            border-collapse: collapse;
            width: 100%;
            max-width: 700px;
            margin-bottom: 16px;
        }
        .ika-gam-debug-ladder th,
        .ika-gam-debug-ladder td {
            border: 1px solid #d0d7de;
            padding: 4px 8px;
            text-align: left;
            font-size: 13px;
        }
        .ika-gam-debug-ladder th {
            background: #e5effa;
            font-weight: 600;
        }
        .ika-gam-debug-ladder caption {
            text-align: left;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .ika-gam-debug-note {
            font-size: 12px;
            color: #555d66;
        }
        .ika-gam-debug-user-card {
            margin: 20px 0;
            padding: 15px 20px;
            border-left: 4px solid #7a3bba;
            background: #f5f0ff;
            max-width: 700px;
        }
        .ika-gam-debug-user-card h3 {
            margin-top: 0;
            margin-bottom: 8px;
        }
        .ika-gam-debug-user-card table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 8px;
        }
        .ika-gam-debug-user-card th,
        .ika-gam-debug-user-card td {
            border: 1px solid #d0d7de;
            padding: 4px 8px;
            text-align: left;
            font-size: 13px;
        }
        .ika-gam-debug-user-card th {
            background: #ece4ff;
            width: 30%;
        }
    </style>

    <div class="ika-gam-debug-ladder">
        <h3>IKA Rank Ladder Debug</h3>
        <p class="ika-gam-debug-note">
            Visible to admins only. This shows how ranks/levels are currently resolved.
        </p>

        <table>
            <caption>Effective rank ladder (ika_get_rank_ladder())</caption>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Label</th>
                    <th>Slug</th>
                    <th>min_xp</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( ! empty( $ladder ) ) : ?>
                <?php foreach ( $ladder as $idx => $row ) : ?>
                    <tr>
                        <td><?php echo (int) ( $idx + 1 ); ?></td>
                        <td><?php echo isset( $row['label'] ) ? esc_html( $row['label'] ) : ''; ?></td>
                        <td><?php echo isset( $row['slug'] ) ? esc_html( $row['slug'] ) : ''; ?></td>
                        <td><?php echo isset( $row['min_xp'] ) ? (int) $row['min_xp'] : 0; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="4"><em>No ladder data found (ika_get_rank_ladder() returned empty).</em></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $thresholds ) ) : ?>
            <table>
                <caption>Raw WatuPRO Play level thresholds</caption>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>required_points</th>
                        <th>rank</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $thresholds as $idx => $row ) : ?>
                        <tr>
                            <td><?php echo (int) ( $idx + 1 ); ?></td>
                            <td><?php echo isset( $row['name'] ) ? esc_html( $row['name'] ) : ''; ?></td>
                            <td><?php echo isset( $row['required_points'] ) ? (int) $row['required_points'] : 0; ?></td>
                            <td><?php echo isset( $row['rank'] ) ? (int) $row['rank'] : 0; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="ika-gam-debug-note">
                No WatuPRO Play level thresholds found (ika_watuproplay_get_level_thresholds() is empty or unavailable).
            </p>
        <?php endif; ?>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode( 'ika_debug_rank_ladder', 'ika_gam_debug_rank_ladder_shortcode' );

/**
 * [ika_debug_user_rank user_id="123"]
 * [ika_debug_user_rank login="someuser"]
 * [ika_debug_user_rank email="user@example.com"]
 *
 * Admin-only debug view for a single user:
 *  - User ID, login, email
 *  - Total XP
 *  - Current rank (label + slug)
 *  - Next rank (label + slug)
 *  - XP needed to reach the next rank
 */
function ika_gam_debug_user_rank_shortcode( $atts ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '';
    }

    $atts = shortcode_atts(
        array(
            'user_id' => '',
            'login'   => '',
            'email'   => '',
        ),
        $atts,
        'ika_debug_user_rank'
    );

    $user = null;

    if ( ! empty( $atts['user_id'] ) ) {
        $user = get_user_by( 'ID', (int) $atts['user_id'] );
    } elseif ( ! empty( $atts['login'] ) ) {
        $user = get_user_by( 'login', $atts['login'] );
    } elseif ( ! empty( $atts['email'] ) ) {
        $user = get_user_by( 'email', $atts['email'] );
    }

    // If nothing specified, fall back to current user (still admin-only).
    if ( ! $user ) {
        $user = wp_get_current_user();
    }

    if ( ! $user || ! $user->ID ) {
        return '<p><em>ika_debug_user_rank: Could not resolve a user.</em></p>';
    }

    $user_id    = (int) $user->ID;
    $user_login = $user->user_login;
    $user_email = $user->user_email;

    // XP and current rank via helper, with fallback.
    $xp          = 0;
    $rank_label  = '';
    $rank_slug   = '';

    if ( function_exists( 'ika_get_user_xp_and_rank' ) ) {
        $data = ika_get_user_xp_and_rank( $user_id );
        if ( is_array( $data ) ) {
            $xp         = isset( $data['xp'] ) ? (int) $data['xp'] : 0;
            $rank_label = isset( $data['rank_label'] ) ? $data['rank_label'] : '';
            $rank_slug  = isset( $data['rank_slug'] ) ? $data['rank_slug'] : '';
        }
    }

    if ( $xp === 0 && '' === $rank_label ) {
        // Fallback if helper not available for some reason.
        $xp         = (int) get_user_meta( $user_id, 'ika_total_xp', true );
        $rank_label = get_user_meta( $user_id, 'ika_rank_label', true );
        $rank_slug  = get_user_meta( $user_id, 'ika_rank_slug', true );
    }

    // Next rank.
    $next_rank        = null;
    $next_rank_label  = '';
    $next_rank_slug   = '';
    $xp_to_next       = null;

    if ( function_exists( 'ika_get_next_rank_for_xp' ) ) {
        $next_rank = ika_get_next_rank_for_xp( $xp );
    }

    if ( is_array( $next_rank ) ) {
        $next_rank_label = isset( $next_rank['label'] ) ? $next_rank['label'] : '';
        $next_rank_slug  = isset( $next_rank['slug'] ) ? $next_rank['slug'] : '';
        $next_min_xp     = isset( $next_rank['min_xp'] ) ? (int) $next_rank['min_xp'] : 0;
        $xp_to_next      = max( 0, $next_min_xp - $xp );
    }

    ob_start();
    ?>
    <div class="ika-gam-debug-user-card">
        <h3>User Rank Debug</h3>
        <p class="ika-gam-debug-note">
            Visible to admins only. Use user_id, login, or email to inspect a specific account.
        </p>

        <table>
            <tbody>
                <tr>
                    <th scope="row">User ID</th>
                    <td><?php echo (int) $user_id; ?></td>
                </tr>
                <tr>
                    <th scope="row">Login</th>
                    <td><?php echo esc_html( $user_login ); ?></td>
                </tr>
                <tr>
                    <th scope="row">Email</th>
                    <td><?php echo esc_html( $user_email ); ?></td>
                </tr>
                <tr>
                    <th scope="row">Total XP</th>
                    <td><?php echo (int) $xp; ?></td>
                </tr>
                <tr>
                    <th scope="row">Current Rank</th>
                    <td>
                        <?php echo $rank_label ? esc_html( $rank_label ) : '<em>(none)</em>'; ?>
                        <?php if ( $rank_slug ) : ?>
                            <br /><span class="ika-gam-debug-note">Slug: <?php echo esc_html( $rank_slug ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Next Rank</th>
                    <td>
                        <?php if ( $next_rank_label ) : ?>
                            <?php echo esc_html( $next_rank_label ); ?>
                            <?php if ( $next_rank_slug ) : ?>
                                <br /><span class="ika-gam-debug-note">Slug: <?php echo esc_html( $next_rank_slug ); ?></span>
                            <?php endif; ?>
                        <?php else : ?>
                            <em>None (already at top rank)</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">XP needed for next rank</th>
                    <td>
                        <?php
                        if ( null === $xp_to_next ) {
                            echo '<em>N/A</em>';
                        } else {
                            echo (int) $xp_to_next;
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode( 'ika_debug_user_rank', 'ika_gam_debug_user_rank_shortcode' );
