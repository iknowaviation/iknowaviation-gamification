<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * Hero metrics strip shortcodes
 * ======================================================================*/

/**
 * [ika_quizzes_completed]
 */
function ika_shortcode_quizzes_completed() {
	if ( ! is_user_logged_in() ) {
		return '0';
	}

	$val = ika_get_user_meta_int( 'ika_quizzes_completed', 0 );
	return number_format_i18n( $val );
}
add_shortcode( 'ika_quizzes_completed', 'ika_shortcode_quizzes_completed' );

/**
 * [ika_total_attempts]
 */
function ika_shortcode_total_attempts() {
	if ( ! is_user_logged_in() ) {
		return '0';
	}

	$val = ika_get_user_meta_int( 'ika_total_attempts', 0 );
	return number_format_i18n( $val );
}
add_shortcode( 'ika_total_attempts', 'ika_shortcode_total_attempts' );

/**
 * [ika_avg_score] – "82%" or "—"
 */
function ika_shortcode_avg_score() {
	if ( ! is_user_logged_in() ) {
		return '—';
	}

	$avg = ika_get_user_meta_float( 'ika_avg_score', 0.0 );
	if ( $avg <= 0 ) {
		return '—';
	}

	$avg = round( $avg );
	return esc_html( $avg . '%' );
}
add_shortcode( 'ika_avg_score', 'ika_shortcode_avg_score' );

/**
 * [ika_best_score] – "100%" or "—"
 */
function ika_shortcode_best_score() {
	if ( ! is_user_logged_in() ) {
		return '—';
	}

	$best = ika_get_user_meta_float( 'ika_best_score', 0.0 );
	if ( $best <= 0 ) {
		return '—';
	}

	$best = round( $best );
	return esc_html( $best . '%' );
}
add_shortcode( 'ika_best_score', 'ika_shortcode_best_score' );

/**
 * [ika_current_streak] – numeric
 */
function ika_shortcode_current_streak() {
	if ( ! is_user_logged_in() ) {
		return '0';
	}

	$streak = ika_get_user_streak();
	return number_format_i18n( $streak );
}
add_shortcode( 'ika_current_streak', 'ika_shortcode_current_streak' );

/**
 * [ika_total_xp] – numeric XP
 */
add_shortcode( 'ika_total_xp', function() {
	if ( ! is_user_logged_in() ) return '0';
	$xp = get_user_meta( get_current_user_id(), 'ika_total_xp', true );
	return number_format_i18n( intval( $xp ) );
});
