<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * Streak helpers
 * ======================================================================*/

/**
 * Get current streak (days).
 */
function ika_get_user_streak( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id ) {
		return 0;
	}

	$streak = get_user_meta( $user_id, 'ika_current_streak_days', true );
	if ( $streak === '' ) {
		$streak = 0;
	}

	return absint( $streak );
}

/**
 * Last quiz timestamp.
 */
function ika_get_last_quiz_time( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id ) {
		return 0;
	}

	$ts = get_user_meta( $user_id, 'ika_last_quiz_timestamp', true );
	return $ts ? (int) $ts : 0;
}

/**
 * Compute streak from an ordered list of quiz dates (Y-m-d).
 * Used by rebuild; accounts for breaks.
 */
function ika_compute_streak_from_dates( $dates ) {
	if ( empty( $dates ) ) {
		return 0;
	}

	$dates = array_unique( $dates );
	sort( $dates ); // ascending

	$today     = current_time( 'Y-m-d' );
	$last_date = end( $dates );

	try {
		$today_dt = new DateTime( $today );
		$last_dt  = new DateTime( $last_date );
		$gap      = (int) $today_dt->diff( $last_dt )->days;
	} catch ( Exception $e ) {
		$gap = 999;
	}

	// If last quiz was 2+ days ago, streak is broken.
	if ( $gap >= 2 ) {
		return 0;
	}

	// Walk backwards from last_date counting consecutive days.
	$streak    = 1;
	$prev_dt   = new DateTime( $last_date );
	$dates_rev = array_reverse( $dates );
	array_shift( $dates_rev ); // remove last_date

	foreach ( $dates_rev as $d ) {
		$d_dt = new DateTime( $d );
		$diff = (int) $prev_dt->diff( $d_dt )->days;

		if ( 1 === $diff ) {
			$streak++;
			$prev_dt = $d_dt;
		} else {
			break;
		}
	}

	return $streak;
}


/* ======================================================================
 * Hero streak + status shortcodes
 * ======================================================================*/

/**
 * [ika_streak_summary]
 */
function ika_shortcode_streak_summary() {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$streak = ika_get_user_streak();

	if ( $streak <= 0 ) {
		return 'No streak yet';
	}

	if ( 1 === (int) $streak ) {
		return 'Streak: 1 day';
	}

	return 'Streak: ' . intval( $streak ) . ' days';
}
add_shortcode( 'ika_streak_summary', 'ika_shortcode_streak_summary' );

/**
 * [ika_user_status]
 */
function ika_shortcode_user_status() {
	if ( ! is_user_logged_in() ) {
		return 'Guest';
	}

	$streak       = ika_get_user_streak();
	$last_quiz_ts = ika_get_last_quiz_time();
	$now          = time();

	$seconds_since = $last_quiz_ts ? max( 0, $now - $last_quiz_ts ) : null;

	if ( null === $seconds_since || 0 === $last_quiz_ts ) {
		$status = 'Ready for takeoff';
	} elseif ( $streak >= 3 && $seconds_since < DAY_IN_SECONDS * 2 ) {
		$status = 'On mission';
	} elseif ( $seconds_since < DAY_IN_SECONDS * 7 ) {
		$status = 'Cruising';
	} else {
		$status = 'In standby';
	}

	return esc_html( $status );
}
add_shortcode( 'ika_user_status', 'ika_shortcode_user_status' );
