<?php
/**
 * Shortcode: [ika_recent_xp_earned]
 *
 * Displays total XP earned by the current user over the last N days.
 * Default: last 7 days
 *
 * Includes:
 *  - Quiz XP (WatuPRO points from finished attempts)
 *  - Bonus XP (mission bonuses) if XP Bonus Ledger is present
 *
 * Usage:
 *  [ika_recent_xp_earned]
 *  [ika_recent_xp_earned days="1"]
 *  [ika_recent_xp_earned days="7" debug="1"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'ika_recent_xp_earned', function ( $atts ) {

	if ( ! is_user_logged_in() ) {
		return '';
	}

	global $wpdb;

	$user_id = get_current_user_id();

	$atts = shortcode_atts(
		[
			'days'  => 7,
			'debug' => 0,
		],
		$atts,
		'ika_recent_xp_earned'
	);

	$days  = max( 1, intval( $atts['days'] ) );
	$debug = (int) $atts['debug'] === 1;

	$table = $wpdb->prefix . 'watupro_taken_exams';

	// Confirm table exists.
	$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
	if ( $found !== $table ) {
		return $debug ? 'DEBUG: WatuPRO taken exams table not found.' : '0 XP';
	}

	// Detect available columns (WatuPRO varies slightly by version/config).
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
	$cols_lc = array_map( 'strtolower', $cols );

	// Pick the best date column available.
	$date_col = null;
	foreach ( [ 'date_taken', 'date', 'date_started', 'date_end', 'end_time' ] as $cand ) {
		if ( in_array( $cand, $cols_lc, true ) ) {
			$date_col = $cand;
			break;
		}
	}

	// Pick the best points column available.
	$points_col = null;
	foreach ( [ 'points', 'earned_points', 'result_points', 'wp_points' ] as $cand ) {
		if ( in_array( $cand, $cols_lc, true ) ) {
			$points_col = $cand;
			break;
		}
	}

	// Finished flag column (optional).
	$finished_col = null;
	foreach ( [ 'is_finished', 'finished', 'is_complete', 'completed' ] as $cand ) {
		if ( in_array( $cand, $cols_lc, true ) ) {
			$finished_col = $cand;
			break;
		}
	}

	if ( ! $date_col || ! $points_col ) {
		if ( $debug ) {
			return 'DEBUG: Missing required columns. date_col=' . ( $date_col ?: 'NONE' ) . ' points_col=' . ( $points_col ?: 'NONE' );
		}
		return '0 XP';
	}

	$where_finished = '';
	if ( $finished_col ) {
		$where_finished = " AND {$finished_col} = 1 ";
	}

	// Quiz XP via WatuPRO points.
	$sql_now = $wpdb->prepare(
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

	$sql_utc = $wpdb->prepare(
		"
		SELECT COALESCE(SUM({$points_col}), 0)
		FROM {$table}
		WHERE user_id = %d
		  {$where_finished}
		  AND {$date_col} >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
		",
		$user_id,
		$days
	);

	$quiz_now = (int) $wpdb->get_var( $sql_now );
	$quiz_utc = (int) $wpdb->get_var( $sql_utc );

	$quiz_xp = max( $quiz_now, $quiz_utc );

	// Bonus XP (missions) via ledger if available.
	$bonus_xp = 0;
	if ( function_exists( 'ika_xp_bonus_sum_since_days' ) ) {
		$bonus_xp = (int) ika_xp_bonus_sum_since_days( (int) $user_id, (int) $days );
	}

	$total = (int) $quiz_xp + (int) $bonus_xp;

	if ( $debug ) {
		return sprintf(
			'DEBUG: days=%d date_col=%s points_col=%s finished_col=%s quiz_now=%d quiz_utc=%d bonus=%d total=%d',
			$days,
			$date_col,
			$points_col,
			$finished_col ?: 'NONE',
			$quiz_now,
			$quiz_utc,
			$bonus_xp,
			$total
		);
	}

	if ( $total <= 0 ) {
		return '0 XP';
	}

	return '+' . number_format_i18n( $total ) . ' XP';
} );
