<?php
/**
 * Results Next Actions (Phase 2 polish)
 *
 * Provides context-aware "Next Actions" pills on the Watu results page.
 * Kept lightweight and safe: no front-end JS required, no markup dependencies outside the results shell.
 *
 * Logic (current):
 * - If score < 70%: encourage review + retry + browse basics
 * - If score >= 70%: encourage recommended next + Flight Deck
 * - If earned badge/level (Phase 1 bridge transient exists): show "View Badges"
 *
 * Uses the current taking_id from Watu request context when available (global/GET), falls back to cookie.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'ika_results_current_taking_id' ) ) {
	function ika_results_current_taking_id() : int {
		if ( ! empty( $GLOBALS['watupro_taking_id'] ) ) {
			return (int) $GLOBALS['watupro_taking_id'];
		}
		if ( ! empty( $_GET['watupro_taking_id'] ) ) {
			return (int) $_GET['watupro_taking_id'];
		}
		if ( ! empty( $_COOKIE['watupro_taking_id'] ) ) {
			return (int) $_COOKIE['watupro_taking_id'];
		}
		return 0;
	}
}

if ( ! function_exists( 'ika_results_get_attempt_summary' ) ) {
	function ika_results_get_attempt_summary( int $taking_id ) : array {
		global $wpdb;

		if ( $taking_id <= 0 ) {
			return array( 'percent' => 0.0, 'correct' => 0, 'total' => 0, 'exam_id' => 0, 'user_id' => 0 );
		}

		$taken = $wpdb->prefix . 'watupro_taken_exams';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, user_id, exam_id, num_correct, num_wrong, num_empty, percent_correct
			 FROM {$taken}
			 WHERE ID = %d LIMIT 1",
			$taking_id
		) );

		if ( ! $row ) {
			return array( 'percent' => 0.0, 'correct' => 0, 'total' => 0, 'exam_id' => 0, 'user_id' => 0 );
		}

		$correct = (int) ( $row->num_correct ?? 0 );
		$wrong   = (int) ( $row->num_wrong ?? 0 );
		$empty   = (int) ( $row->num_empty ?? 0 );
		$total   = max( 0, $correct + $wrong + $empty );
		$percent = isset( $row->percent_correct ) ? (float) $row->percent_correct : 0.0;

		return array(
			'percent' => $percent,
			'correct' => $correct,
			'total'   => $total,
			'exam_id' => (int) ( $row->exam_id ?? 0 ),
			'user_id' => (int) ( $row->user_id ?? 0 ),
		);
	}
}

add_shortcode( 'ika_results_next_actions', function() {

	// Only meaningful for logged-in users (Flight Deck + XP systems).
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$taking_id = ika_results_current_taking_id();
	$summary   = ika_results_get_attempt_summary( $taking_id );

	$percent = (float) ( $summary['percent'] ?? 0.0 );

	$passed = ( $percent >= 70.0 );
	$needs_help = ( $percent > 0.0 && $percent < 70.0 );

	$actions = array();

	// Primary: Continue learning (passed) vs Review + Retry (not passed)
	if ( $passed ) {
		$actions[] = array(
			'label' => 'Continue learning',
			'href'  => '#ika-results-recommended',
		);
	} elseif ( $needs_help ) {
		$actions[] = array(
			'label' => 'Review answers',
			'href'  => '#ika-answer-breakdown',
		);
		$actions[] = array(
			'label' => 'Try again',
			'href'  => get_permalink(),
		);
	} else {
		// Fallback when percent isn't available
		$actions[] = array(
			'label' => 'Continue learning',
			'href'  => '#ika-results-recommended',
		);
	}

	// Secondary: Flight Deck (always)
	$actions[] = array(
		'label' => 'Go to Flight Deck',
		'href'  => home_url( '/flight-deck/' ),
	);

	// Optional: Badges if newly earned this attempt (Phase 1 bridge)
	$earned_any = false;
	if ( class_exists( 'IKA_WatuPlay_Modal_Bridge' ) && $taking_id > 0 ) {
		$earned = IKA_WatuPlay_Modal_Bridge::get_earned_for_taking( $taking_id );
		if ( is_array( $earned ) && ( ! empty( $earned['level'] ) || ! empty( $earned['badges'] ) ) ) {
			$earned_any = true;
		}
	}
	if ( $earned_any ) {
		$actions[] = array(
			'label' => 'View badges',
			'href'  => home_url( '/flight-deck/badges/' ),
		);
	}

	// Always provide browse (lower priority)
	$actions[] = array(
		'label' => 'Browse all quizzes',
		'href'  => home_url( '/quizzes/' ),
	);

	// Render
	$out  = '<div class="ika-results-actions" role="navigation" aria-label="Next actions">';
	foreach ( $actions as $a ) {
		$label = esc_html( (string) $a['label'] );
		$href  = esc_url( (string) $a['href'] );
		$out  .= '<a class="ika-action-pill" href="' . $href . '">' . $label . '</a>';
	}
	$out .= '</div>';

	return $out;
} );
