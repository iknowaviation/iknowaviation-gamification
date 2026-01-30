<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * iKnowAviation – Daily Missions module
 *
 * This is the existing "iKnowAviation Daily Missions" plugin code,
 * now loaded as part of the main Gamification Engine.
 *
 * Contains:
 * - Mission config (take quiz, score ≥ X, etc.)
 * - Mission state helpers (per user, per day)
 * - XP/streak updates when missions are completed
 * - WatuPRO exam hook to update mission progress
 * - [ika_daily_missions], [ika_user_xp], [ika_user_level] shortcodes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CONFIG: Define daily missions.
 *
 * Types supported in this v1:
 * - any_quiz         → counts any completed quiz
 * - score_at_least   → requires minimum % score
 */
function ika_dm_get_missions_config() {
    return array(
        'first_flight' => array(
            'label'        => __( 'First Flight of the Day', 'ika-dm' ),
            'description'  => __( 'Complete any quiz today.', 'ika-dm' ),
            'type'         => 'any_quiz',
            'target'       => 1,
            'xp_reward'    => 5,
        ),
        'smooth_landing' => array(
            'label'          => __( 'Smooth Landing', 'ika-dm' ),
            'description'    => __( 'Score at least 80% on any quiz today.', 'ika-dm' ),
            'type'           => 'score_at_least',
            'target'         => 1,
            'min_percentage' => 80,
            'xp_reward'      => 10,
        ),
    );
}

/**
 * Return today key in site timezone (YYYY-MM-DD).
 */
function ika_dm_get_today_key() {
    // Uses WP timezone setting.
    $timestamp = current_time( 'timestamp' );
    return gmdate( 'Y-m-d', $timestamp );
}

/**
 * Get daily mission state for a user.
 *
 * Structure:
 * [
 *   'date'     => 'YYYY-MM-DD',
 *   'missions' => [
 *      'mission_id' => [ 'progress' => int, 'completed' => bool ],
 *      ...
 *   ]
 * ]
 */
function ika_dm_get_state( $user_id ) {
    $today = ika_dm_get_today_key();
    $state = get_user_meta( $user_id, 'ika_daily_missions_state', true );

    if ( ! is_array( $state ) || empty( $state['date'] ) || $state['date'] !== $today ) {
        // New day → reset.
        $state = array(
            'date'     => $today,
            'missions' => array(),
        );
    }

    $missions = ika_dm_get_missions_config();

    // Ensure each mission has a state entry.
    foreach ( $missions as $id => $mission ) {
        if ( ! isset( $state['missions'][ $id ] ) ) {
            $state['missions'][ $id ] = array(
                'progress'  => 0,
                'completed' => false,
            );
        }
    }

    // Persist normalization.
    ika_dm_save_state( $user_id, $state );

    return $state;
}

/**
 * Save daily mission state.
 */
function ika_dm_save_state( $user_id, $state ) {
    update_user_meta( $user_id, 'ika_daily_missions_state', $state );
}

/**
 * Add XP to the user (separate from Watu Play internal points).
 */
function ika_dm_add_xp( $user_id, $xp, $reason = "", $taking_id = 0 ) {
    $xp = (int) $xp;
    if ( $xp <= 0 ) {
        return;
    }

    // Ledger-only bonus XP.
    // Tie the bonus to the quiz attempt when possible (taking_id) so Results + Recent Activity
    // can display a clean breakdown.
    $taking_id = (int) $taking_id;
    $exam_id = 0;
    if ( $taking_id > 0 ) {
        global $wpdb;
        $taken_tbl = $wpdb->prefix . 'watupro_taken_exams';
        $exam_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT exam_id FROM {$taken_tbl} WHERE ID = %d LIMIT 1", $taking_id ) );
    }

    if ( function_exists( 'ika_xp_ledger_add_row' ) ) {
        $source = 'mission_bonus';
        // Safety: UNIQUE(taking_id, source) would collide globally when taking_id=0.
        // If we cannot tie to an attempt, generate a unique source key.
        if ( $taking_id <= 0 ) {
            $source = 'mission_bonus_' . substr( md5( uniqid( 'ika', true ) ), 0, 8 );
        }
        ika_xp_ledger_add_row( (int) $user_id, (int) $exam_id, (int) $taking_id, (int) $xp, $source, array(
            'reason' => sanitize_text_field( (string) $reason ),
            'tied_to_attempt' => $taking_id > 0 ? 1 : 0,
        ) );
    }

    // Refresh cached totals from ledger.
    if ( function_exists( 'ika_xp_sync_user_cache_from_ledger' ) ) {
        ika_xp_sync_user_cache_from_ledger( (int) $user_id );
    } elseif ( function_exists( 'ika_get_total_xp_canonical' ) ) {
        ika_get_total_xp_canonical( (int) $user_id, true );
    }
}

/**
 * Update the user's daily streak.
 *
 * - If first time: streak = 1
 * - If last active date is yesterday: streak++
 * - If last active date is today: no change
 * - Else: streak resets to 1
 */
function ika_dm_update_streak( $user_id ) {
    $today          = ika_dm_get_today_key();
    $last_active    = get_user_meta( $user_id, 'ika_last_active_date', true );
    $current_streak = (int) get_user_meta( $user_id, 'ika_daily_streak', true );
    $best_streak    = (int) get_user_meta( $user_id, 'ika_best_streak', true );

    if ( $last_active === $today ) {
        // Already counted for today.
        return;
    }

    if ( empty( $last_active ) ) {
        // First time activity.
        $current_streak = 1;
    } else {
        $today_ts       = strtotime( $today );
        $last_active_ts = strtotime( $last_active );

        if ( $last_active_ts === ( $today_ts - DAY_IN_SECONDS ) ) {
            // Yesterday → extend streak.
            $current_streak = max( 1, $current_streak + 1 );
        } else {
            // Gap → reset streak.
            $current_streak = 1;
        }
    }

    // Update best streak.
    if ( $current_streak > $best_streak ) {
        $best_streak = $current_streak;
        update_user_meta( $user_id, 'ika_best_streak', $best_streak );
    }

    update_user_meta( $user_id, 'ika_daily_streak', $current_streak );
    update_user_meta( $user_id, 'ika_last_active_date', $today );
}

/**
 * Update missions when a quiz is completed.
 *
 * @param int   $user_id
 * @param float $percentage  Quiz final percentage score (0–100).
 */
function ika_dm_update_on_completion( $user_id, $percentage, $taking_id = 0 ) {
    $missions = ika_dm_get_missions_config();
    $state    = ika_dm_get_state( $user_id );
    $changed  = false;

    foreach ( $missions as $id => $mission ) {
        if ( empty( $state['missions'][ $id ] ) ) {
            $state['missions'][ $id ] = array(
                'progress'  => 0,
                'completed' => false,
            );
        }

        $mstate = $state['missions'][ $id ];

        // Skip if already completed.
        if ( $mstate['completed'] ) {
            continue;
        }

        switch ( $mission['type'] ) {
            case 'any_quiz':
                $mstate['progress']++;
                if ( $mstate['progress'] >= (int) $mission['target'] ) {
                    $mstate['progress']  = (int) $mission['target'];
                    $mstate['completed'] = true;
                    if ( ! empty( $mission['xp_reward'] ) ) {
                        ika_dm_add_xp( $user_id, (int) $mission['xp_reward'], (string) $id, (int) $taking_id );
                    }
                }
                $changed = true;
                break;

            case 'score_at_least':
                $min = isset( $mission['min_percentage'] ) ? (float) $mission['min_percentage'] : 0;
                if ( $percentage >= $min ) {
                    $mstate['progress']  = (int) $mission['target'];
                    $mstate['completed'] = true;
                    if ( ! empty( $mission['xp_reward'] ) ) {
                        ika_dm_add_xp( $user_id, (int) $mission['xp_reward'], (string) $id, (int) $taking_id );
                    }
                    $changed = true;
                }
                break;
        }

        $state['missions'][ $id ] = $mstate;
    }

    if ( $changed ) {
        ika_dm_save_state( $user_id, $state );
    }

    // Every successful completion counts as "activity" for streaks.
    ika_dm_update_streak( $user_id );

    return $state;
}

/**
 * AJAX: Log quiz completion from the front-end.
 * Expects:
 * - POST['percentage']  (float, 0–100)
 */
function ika_dm_ajax_log_quiz_completion() {
    check_ajax_referer( 'ika_daily_missions', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'not_logged_in' ) );
    }

    $user_id    = get_current_user_id();
    $percentage = isset( $_POST['percentage'] ) ? floatval( wp_unslash( $_POST['percentage'] ) ) : 0;
	$taking_id  = isset( $_POST['taking_id'] ) ? absint( wp_unslash( $_POST['taking_id'] ) ) : 0;

	ika_dm_update_on_completion( $user_id, $percentage, (int) $taking_id );

    wp_send_json_success();
}
add_action( 'wp_ajax_ika_log_quiz_completion', 'ika_dm_ajax_log_quiz_completion' );

/**
 * Enqueue front-end script to read %%PERCENTAGE%% and send AJAX.
 *
 * You can adjust the condition (is_singular('quiz')) if your
 * quiz pages use a different post type / condition.
 */
function ika_dm_enqueue_scripts() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    // Respect settings toggle.
    if ( function_exists( 'ika_gam_get_setting' ) && ! ika_gam_get_setting( 'enable_daily_missions' ) ) {
        return;
    }
	
    // Adjust this if your quizzes are elsewhere:
    if ( ! is_singular( 'quiz' ) ) {
        return;
    }

    // Register a "dummy" script handle so we can attach inline JS.
    wp_register_script(
        'ika-daily-missions',
        '', // no external file, everything is inline in this v1.
        array(),
        false,
        true
    );
    wp_enqueue_script( 'ika-daily-missions' );

    $data = array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'ika_daily_missions' ),
    );

    wp_add_inline_script(
        'ika-daily-missions',
        'window.ikaDailyMissions = ' . wp_json_encode( $data ) . ';',
        'before'
    );

    $inline_js = <<<JS
document.addEventListener('DOMContentLoaded', function() {
    // Avoid double-reporting.
    if (window.ikaDailyMissionReported) { return; }

    var el = document.getElementById('ika-quiz-percentage');
    if (!el) { return; }

    window.ikaDailyMissionReported = true;

    var raw = el.getAttribute('data-percentage') || '0';
    var percent = parseFloat(String(raw).replace(',', '.')) || 0;

    if (!window.ikaDailyMissions || !window.ikaDailyMissions.ajax_url) {
        return;
    }

    var formData = new FormData();
    formData.append('action', 'ika_log_quiz_completion');
    formData.append('nonce', window.ikaDailyMissions.nonce);
    formData.append('percentage', percent);

    // Best-effort: tie mission bonus to this attempt (for Results breakdown + Recent Activity folding).
    var takingEl = document.getElementById('ika-taking-id');
    var takingId = takingEl ? parseInt(takingEl.getAttribute('data-taking-id') || '0', 10) : 0;
    if (!takingId) {
        var qs = new URLSearchParams(window.location.search);
        takingId = parseInt(qs.get('watupro_taking_id') || '0', 10) || 0;
    }
    if (takingId) {
        formData.append('taking_id', String(takingId));
    }

    fetch(window.ikaDailyMissions.ajax_url, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
    }).catch(function(err) {
        // Silent fail; not critical for user.
        if (window.console && console.warn) {
            console.warn('Daily missions AJAX failed', err);
        }
    });
});
JS;

    wp_add_inline_script( 'ika-daily-missions', $inline_js );
}
add_action( 'wp_enqueue_scripts', 'ika_dm_enqueue_scripts' );

/**
 * LEVEL SYSTEM
 * Convert ika_xp_total → Level X
 */

/**
 * Simple XP → level curve.
 *
 * Level 1:   0–49 XP
 * Level 2:  50–99 XP
 * Level 3: 100–149 XP
 * ...
 * Level 50 (cap).
 */
function ika_dm_get_level_from_xp( $xp ) {
    $xp    = max( 0, (int) $xp );
    $base  = 50; // XP per level step.
    $level = (int) floor( $xp / $base ) + 1;

    if ( $level > 50 ) {
        $level = 50;
    }

    return $level;
}

/**
 * Helper: resolve a user ID from the mixed $id_or_email that get_avatar() receives.
 */
function ika_dm_get_user_id_from_mixed( $id_or_email ) {
    $user = false;

    if ( is_numeric( $id_or_email ) ) {
        $user = get_user_by( 'id', (int) $id_or_email );
    } elseif ( $id_or_email instanceof WP_User ) {
        $user = $id_or_email;
    } elseif ( $id_or_email instanceof WP_Post ) {
        $user = get_user_by( 'id', (int) $id_or_email->post_author );
    } elseif ( $id_or_email instanceof WP_Comment ) {
        if ( ! empty( $id_or_email->user_id ) ) {
            $user = get_user_by( 'id', (int) $id_or_email->user_id );
        } elseif ( ! empty( $id_or_email->comment_author_email ) ) {
            $user = get_user_by( 'email', $id_or_email->comment_author_email );
        }
    } elseif ( is_email( $id_or_email ) ) {
        $user = get_user_by( 'email', $id_or_email );
    }

    return ( $user instanceof WP_User ) ? (int) $user->ID : 0;
}

/**
 * Filter: wrap avatar HTML in a level-ring wrapper and show "Lv X".
 *
 * Affects all get_avatar() outputs (including UsersWP avatar widget/shortcode).
 */
function ika_dm_filter_get_avatar( $avatar, $id_or_email, $size, $default_value, $alt, $args ) {
    $user_id = ika_dm_get_user_id_from_mixed( $id_or_email );

    if ( ! $user_id ) {
        return $avatar;
    }

	$xp    = function_exists( 'ika_get_total_xp_canonical' )
		? (int) ika_get_total_xp_canonical( (int) $user_id )
		: (int) ( function_exists('ika_get_total_xp_canonical') ? ika_get_total_xp_canonical( (int) $user_id ) : get_user_meta( $user_id, 'ika_total_xp', true ) );
    $level = ika_dm_get_level_from_xp( $xp );

    // Wrap avatar in a span so we can draw a ring + badge via CSS.
    $wrapper_classes = sprintf(
        'ika-level-avatar-wrapper ika-level-%d',
        (int) $level
    );

    $badge_html = sprintf(
        '<span class="ika-level-badge">Lv %d</span>',
        (int) $level
    );

    $wrapped = sprintf(
        '<span class="%s">%s%s</span>',
        esc_attr( $wrapper_classes ),
        $avatar,
        $badge_html
    );

    return $wrapped;
}
add_filter( 'get_avatar', 'ika_dm_filter_get_avatar', 10, 6 );

/**
 * Shortcode: [ika_daily_missions]
 *
 * Renders today's missions and progress for the logged-in user,
 * plus XP + streak stats.
 */
function ika_dm_shortcode_render() {
    if ( ! is_user_logged_in() ) {
        return '<p class="ika-dm-notice">Log in to see your daily missions.</p>';
    }

	// If the feature is toggled off, output nothing.
		if ( function_exists( 'ika_gam_get_setting' ) && ! ika_gam_get_setting( 'enable_daily_missions' ) ) {
			return '';
		}
	
    $user_id  = get_current_user_id();
    $config   = ika_dm_get_missions_config();
    $state    = ika_dm_get_state( $user_id );
    $missions = $config;

	$xp_total       = function_exists( 'ika_get_total_xp_canonical' )
		? (int) ika_get_total_xp_canonical( (int) $user_id )
		: (int) ( function_exists('ika_get_total_xp_canonical') ? ika_get_total_xp_canonical( (int) $user_id ) : get_user_meta( $user_id, 'ika_total_xp', true ) );
    $streak_current = (int) get_user_meta( $user_id, 'ika_daily_streak', true );
    $streak_best    = (int) get_user_meta( $user_id, 'ika_best_streak', true );
    $level          = ika_dm_get_level_from_xp( $xp_total );

    ob_start();
    ?>
    <div class="ika-dm-wrapper">
        <div class="ika-dm-header">
            <h3 class="ika-dm-title">Daily Missions</h3>
            <p class="ika-dm-subtitle">Keep flying every day to grow your streak, XP, and level.</p>
        </div>

        <div class="ika-dm-stats">
            <div class="ika-dm-stat">
                <div class="ika-dm-stat-label">Level</div>
                <div class="ika-dm-stat-value">
                    <?php echo esc_html( 'Level ' . $level ); ?>
                </div>
            </div>
            <div class="ika-dm-stat">
                <div class="ika-dm-stat-label">Total XP</div>
                <div class="ika-dm-stat-value">
                    <?php echo esc_html( number_format_i18n( $xp_total ) ); ?>
                </div>
            </div>
            <div class="ika-dm-stat">
                <div class="ika-dm-stat-label">Current Streak</div>
                <div class="ika-dm-stat-value">
                    <?php echo esc_html( $streak_current ); ?> day<?php echo ( $streak_current === 1 ? '' : 's' ); ?>
                </div>
            </div>
            <div class="ika-dm-stat">
                <div class="ika-dm-stat-label">Best Streak</div>
                <div class="ika-dm-stat-value">
                    <?php echo esc_html( $streak_best ); ?> day<?php echo ( $streak_best === 1 ? '' : 's' ); ?>
                </div>
            </div>
        </div>

        <div class="ika-dm-list">
            <?php foreach ( $missions as $id => $mission ) :
                $mstate    = isset( $state['missions'][ $id ] ) ? $state['missions'][ $id ] : array( 'progress' => 0, 'completed' => false );
                $progress  = (int) $mstate['progress'];
                $target    = isset( $mission['target'] ) ? (int) $mission['target'] : 1;
                $completed = ! empty( $mstate['completed'] );
                $state_key = $completed ? 'completed' : ( $progress > 0 ? 'in_progress' : 'new' );
                ?>
                <div class="ika-dm-card <?php echo $completed ? 'ika-dm-card--completed' : 'ika-dm-card--active'; ?> ika-dm-card--state-<?php echo esc_attr( $state_key ); ?>" data-state="<?php echo esc_attr( $state_key ); ?>">
                    <div class="ika-dm-card-main">
                        <div class="ika-dm-card-title-row">
                            <span class="ika-dm-card-title">
                                <?php echo esc_html( $mission['label'] ); ?>
                            </span>
                            <span class="ika-dm-card-status ika-status-chip">
                                <?php echo $completed ? 'Completed' : ( $progress > 0 ? 'In Progress' : 'New' ); ?>
                            </span>
                        </div>
                        <p class="ika-dm-card-description">
                            <?php echo esc_html( $mission['description'] ); ?>
                        </p>
                    </div>
                    <div class="ika-dm-card-meta">
                        <div class="ika-dm-progress">
                            <span class="ika-dm-progress-label">
                                Progress:
                            </span>
                            <span class="ika-dm-progress-value">
                                <?php echo esc_html( $progress . ' / ' . $target ); ?>
                            </span>
                        </div>
                        <?php if ( ! empty( $mission['xp_reward'] ) ) : ?>
                            <div class="ika-dm-reward">
                                Reward: <?php echo (int) $mission['xp_reward']; ?> XP
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode( 'ika_daily_missions', 'ika_dm_shortcode_render' );

/**
 * Shortcode: [ika_user_level]
 *
 * Shows "Level X" for the current logged-in user.
 */
function ika_dm_shortcode_user_level( $atts = array() ) {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $atts = shortcode_atts(
        array(
            'number_only' => 'false',
            'prefix'      => 'Level ',
        ),
        $atts,
        'ika_user_level'
    );

    $user_id = get_current_user_id();
	$xp      = function_exists( 'ika_get_total_xp_canonical' )
		? (int) ika_get_total_xp_canonical( (int) $user_id )
		: (int) ( function_exists('ika_get_total_xp_canonical') ? ika_get_total_xp_canonical( (int) $user_id ) : get_user_meta( $user_id, 'ika_total_xp', true ) );
    $level   = ika_dm_get_level_from_xp( $xp );

    if ( 'true' === strtolower( $atts['number_only'] ) ) {
        return esc_html( $level );
    }

    return esc_html( $atts['prefix'] . $level );
}
add_shortcode( 'ika_user_level', 'ika_dm_shortcode_user_level' );

/**
 * Simple test shortcode to verify plugin is loading (optional).
 * Usage: [ika_dm_test]
 */
function ika_dm_test_shortcode() {
    return '<p>Daily Missions TEST shortcode is working.</p>';
}
add_shortcode( 'ika_dm_test', 'ika_dm_test_shortcode' );

/* =========================================================
   XP Bonus Ledger (Missions) — supports:
   - Including mission bonus in "XP This Week" pill
   - Resetting today's mission bonuses for testing
   Stored in user meta: ika_total_xp_bonus + ika_xp_bonus_ledger (array)
========================================================= */

if ( ! function_exists( 'ika_xp_bonus_get_ledger' ) ) {
	function ika_xp_bonus_get_ledger( int $user_id ): array {
		$ledger = get_user_meta( $user_id, 'ika_xp_bonus_ledger', true );
		return is_array( $ledger ) ? $ledger : [];
	}
}

if ( ! function_exists( 'ika_xp_bonus_set_ledger' ) ) {
	function ika_xp_bonus_set_ledger( int $user_id, array $ledger ): void {
		update_user_meta( $user_id, 'ika_xp_bonus_ledger', array_values( $ledger ) );
	}
}

if ( ! function_exists( 'ika_xp_bonus_add' ) ) {
	function ika_xp_bonus_add( int $user_id, int $amount, string $reason = '' ): void {
		if ( $amount === 0 ) return;

		$amount = (int) $amount;
		$ledger = ika_xp_bonus_get_ledger( $user_id );

		$ledger[] = [
			'ts'     => time(),
			'date'   => wp_date( 'Y-m-d' ),
			'amount' => $amount,
			'reason' => sanitize_text_field( $reason ),
		];

		ika_xp_bonus_set_ledger( $user_id, $ledger );
	}
}

if ( ! function_exists( 'ika_xp_bonus_sum_since_days' ) ) {
	function ika_xp_bonus_sum_since_days( int $user_id, int $days ): int {
		$days = max( 1, (int) $days );
		$cutoff = time() - ( DAY_IN_SECONDS * $days );

		$sum = 0;
		foreach ( ika_xp_bonus_get_ledger( $user_id ) as $row ) {
			$ts = isset( $row['ts'] ) ? (int) $row['ts'] : 0;
			if ( $ts >= $cutoff ) {
				$sum += (int) ( $row['amount'] ?? 0 );
			}
		}
		return (int) $sum;
	}
}

if ( ! function_exists( 'ika_xp_bonus_remove_by_date' ) ) {
	function ika_xp_bonus_remove_by_date( int $user_id, string $ymd ): int {
		$removed = 0;
		$ledger = ika_xp_bonus_get_ledger( $user_id );

		$new = [];
		foreach ( $ledger as $row ) {
			$date = (string) ( $row['date'] ?? '' );
			if ( $date === $ymd ) {
				$removed += (int) ( $row['amount'] ?? 0 );
				continue;
			}
			$new[] = $row;
		}

		ika_xp_bonus_set_ledger( $user_id, $new );
		return (int) $removed;
	}
}

/**
 * Recompute ika_total_xp (quiz + bonus) from stored components.
 * - quiz component should live in ika_total_xp_quiz
 * - bonus component in ika_total_xp_bonus
 */
if ( ! function_exists( 'ika_xp_recompute_total' ) ) {
	function ika_xp_recompute_total( int $user_id ): int {
		$quiz  = (int) get_user_meta( $user_id, 'ika_total_xp_quiz', true );
		$bonus = (int) get_user_meta( $user_id, 'ika_total_xp_bonus', true );
		$total = max( 0, $quiz + $bonus );

		update_user_meta( $user_id, 'ika_total_xp', $total );

		// Back-compat key (legacy).
		// Keep canonical cache in sync.
		update_user_meta( $user_id, 'ika_total_xp', $total );

		return $total;
	}
}

/**
 * ADMIN-ONLY: Reset today's mission completion + today's mission bonus awards for the current user.
 * Usage: [ika_dm_reset_today]
 */
add_shortcode( 'ika_dm_reset_today', function () {

	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	if ( ! is_user_logged_in() ) {
		return 'Not logged in.';
	}

	$user_id = get_current_user_id();
	$today   = wp_date( 'Y-m-d' );

	// Hard reset: daily missions state for today.
	$state = array(
		'date'     => $today,
		'missions' => array(),
	);

	if ( function_exists( 'ika_dm_save_state' ) ) {
		ika_dm_save_state( $user_id, $state );
	} else {
		update_user_meta( $user_id, 'ika_daily_missions_state', $state );
	}

	// Remove today's bonus ledger entries and decrement bonus meta accordingly.
	$removed = 0;
	if ( function_exists( 'ika_xp_bonus_remove_by_date' ) ) {
		$removed = ika_xp_bonus_remove_by_date( $user_id, $today );
	}

	if ( $removed > 0 ) {
		$bonus = (int) get_user_meta( $user_id, 'ika_total_xp_bonus', true );
		$bonus = max( 0, $bonus - $removed );
		update_user_meta( $user_id, 'ika_total_xp_bonus', $bonus );
	}

	$total = function_exists( 'ika_xp_recompute_total' ) ? ika_xp_recompute_total( $user_id ) : (int) ( function_exists('ika_get_total_xp_canonical') ? ika_get_total_xp_canonical( (int) $user_id ) : get_user_meta( $user_id, 'ika_total_xp', true ) );

	return 'Reset OK. Removed bonus=' . (int) $removed . ' XP. Total XP now=' . (int) $total . '.';
} );

/**
 * ADMIN-ONLY: Debug XP breakdown for current user.
 * Usage: [ika_debug_xp_breakdown days="7"]
 */
add_shortcode( 'ika_debug_xp_breakdown', function ( $atts ) {

	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	if ( ! is_user_logged_in() ) {
		return 'Not logged in.';
	}

	$atts = shortcode_atts( [ 'days' => 7 ], $atts, 'ika_debug_xp_breakdown' );
	$days = max( 1, (int) $atts['days'] );

	$user_id = get_current_user_id();

	$quiz  = (int) get_user_meta( $user_id, 'ika_total_xp_quiz', true );
	$bonus = (int) get_user_meta( $user_id, 'ika_total_xp_bonus', true );
	$total = (int) ( function_exists('ika_get_total_xp_canonical') ? ika_get_total_xp_canonical( (int) $user_id ) : get_user_meta( $user_id, 'ika_total_xp', true ) );

	$bonus_recent = ika_xp_bonus_sum_since_days( $user_id, $days );

	// WatuPRO recent quiz XP (best-effort using same adaptive query approach as ika_recent_xp_earned).
	global $wpdb;
	$table = $wpdb->prefix . 'watupro_taken_exams';
	$quiz_recent = 0;

	$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
	if ( $found === $table ) {
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$cols_lc = array_map( 'strtolower', $cols );

		$date_col = null;
		foreach ( [ 'date_taken', 'date', 'date_started', 'date_end', 'end_time' ] as $cand ) {
			if ( in_array( $cand, $cols_lc, true ) ) { $date_col = $cand; break; }
		}
		$points_col = null;
		foreach ( [ 'points', 'earned_points', 'result_points', 'wp_points' ] as $cand ) {
			if ( in_array( $cand, $cols_lc, true ) ) { $points_col = $cand; break; }
		}
		$finished_col = null;
		foreach ( [ 'is_finished', 'finished', 'is_complete', 'completed' ] as $cand ) {
			if ( in_array( $cand, $cols_lc, true ) ) { $finished_col = $cand; break; }
		}

		if ( $date_col && $points_col ) {
			$where_finished = $finished_col ? " AND {$finished_col} = 1 " : '';
			$sql = $wpdb->prepare(
				"
				SELECT COALESCE(SUM({$points_col}), 0)
				FROM {$table}
				WHERE user_id = %d
				  {$where_finished}
				  AND {$date_col} >= DATE_SUB(NOW(), INTERVAL %d DAY)
				",
				$user_id,
				$days
			);
			$quiz_recent = (int) $wpdb->get_var( $sql );
		}
	}

	$out  = '<pre style="white-space:pre-wrap">';
	$out .= 'XP Breakdown (user_id=' . (int) $user_id . ")\n";
	$out .= "TOTAL: {$total}\n";
	$out .= "QUIZ META (ika_total_xp_quiz): {$quiz}\n";
	$out .= "BONUS META (ika_total_xp_bonus): {$bonus}\n";
	$out .= "RECENT {$days}d QUIZ XP (WatuPRO sum): {$quiz_recent}\n";
	$out .= "RECENT {$days}d BONUS XP (ledger): {$bonus_recent}\n";
	$out .= 'RECENT ' . $days . 'd COMBINED: ' . (int) ( $quiz_recent + $bonus_recent ) . "\n";
	$out .= '</pre>';

	return $out;
} );
