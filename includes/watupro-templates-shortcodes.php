<?php
/**
 * WatuPRO Quiz & Results Template Shortcodes (iKnowAviation)
 *
 * - Quiz shell: [ika_watu_quiz_shell]
 * - Results shell: [ika_watu_results_shell ...]
 * - Next actions: [ika_results_next_actions]
 *
 * Notes:
 * - Watu %%TOKENS%% are replaced by Watu. Keep tokens like %%ANSWERS%% outside our shortcode wrapper.
 * - Templates live in /templates/watu/ and use {{placeholders}}.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Load template file from plugin templates directory.
 */
function ika_watu_template_load( $template_filename ) {
	$path = plugin_dir_path( __FILE__ ) . '../templates/watu/' . $template_filename;
	if ( ! file_exists( $path ) ) {
		return '';
	}
	$html = file_get_contents( $path );
	return is_string( $html ) ? $html : '';
}

/**
 * Render template with {{placeholders}} and allow WP shortcodes inside template.
 */
function ika_watu_template_render( $template_filename, $vars = array() ) {
	$html = ika_watu_template_load( $template_filename );
	if ( $html === '' ) {
		return '';
	}
	foreach ( (array) $vars as $k => $v ) {
		$key = '{{' . $k . '}}';
		$html = str_replace( $key, esc_html( (string) $v ), $html );
	}
	return do_shortcode( $html );
}

/**
 * Resolve the current taking_id for THIS results request.
 * Priority:
 *  1) $GLOBALS['watupro_taking_id']
 *  2) $_GET['watupro_taking_id']
 *  3) $_COOKIE['watupro_taking_id'] (fallback only)
 */
function ika_watu_current_taking_id() : int {
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

/**
 * Quiz shell.
 */
add_shortcode( 'ika_watu_quiz_shell', function() {
	return ika_watu_template_render( 'quiz-shell.html', array() );
} );

/**
 * Results shell.
 */
add_shortcode( 'ika_watu_results_shell', function( $atts = array() ) {

	$atts = shortcode_atts(
		array(
			'user_name'  => '',
			'quiz_name'  => '',
			'correct'    => '',
			'total'      => '',
			'percentage' => '',
			'grade'      => '',
			'points'     => '', // Watu points (fallback only)
			'avg_points' => '',
		),
		$atts,
		'ika_watu_results_shell'
	);

	// Default XP earned display: fallback to Watu points, but prefer ledger XP.
	$xp_earned = (int) $atts['points'];

	$taking_id = ika_watu_current_taking_id();
	if ( $taking_id > 0 && function_exists( 'ika_xp_for_taking' ) ) {
		$ledger_xp = (int) ika_xp_for_taking( $taking_id );
		if ( $ledger_xp > 0 ) {
			$xp_earned = $ledger_xp;
		}
	}

	// Render the results template.
	$html = ika_watu_template_render( 'results-shell.html', array(
		'user_name'  => $atts['user_name'],
		'quiz_name'  => $atts['quiz_name'],
		'correct'    => $atts['correct'],
		'total'      => $atts['total'],
		'percentage' => $atts['percentage'],
		'grade'      => $atts['grade'],
		'xp_earned'  => (string) $xp_earned,
		'points'     => $atts['points'],
		'avg_points' => $atts['avg_points'],
	) );

	// IMPORTANT: allow nested shortcodes inside the template to render.
	// (e.g. [ika_achievement_modal] on results-shell.html)
	return do_shortcode( $html );
} );

/**
 * Fetch attempt summary from Watu taken exams row.
 */
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

/**
 * NEXT ACTIONS shortcode for results page.
 *
 * Usage: place [ika_results_next_actions] in results-shell.html.
 */
add_shortcode( 'ika_results_next_actions', function( $atts = array() ) {

	// Only meaningful for logged-in users (Flight Deck + XP systems).
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'correct'    => '',
			'total'      => '',
			'percentage' => '',
		),
		$atts,
		'ika_results_next_actions'
	);

	// Prefer the values passed from results template (these match what the user sees).
	$percent = is_numeric( $atts['percentage'] ) ? (float) $atts['percentage'] : null;
	$total   = is_numeric( $atts['total'] ) ? (int) $atts['total'] : 0;

	// Fallback to DB lookup if atts weren't passed or are empty.
	$taking_id = ika_watu_current_taking_id();
	if ( ( $percent === null || $total <= 0 ) && $taking_id > 0 ) {
		$summary = ika_results_get_attempt_summary( $taking_id );
		$percent = isset( $summary['percent'] ) ? (float) $summary['percent'] : 0.0;
		$total   = (int) ( $summary['total'] ?? 0 );
	}

	// Decide actions.
	$actions = array();

	// If we have a real attempt (total > 0), anything under 70% needs help (including 0%).
	if ( $total > 0 && (float) $percent < 70.0 ) {
		$actions[] = array( 'label' => 'Review answers', 'href' => '#ika-answer-breakdown' );
		$actions[] = array( 'label' => 'Try again', 'href' => get_permalink() );
	} else {
		// Passed or unknown: keep momentum.
		$actions[] = array( 'label' => 'Continue learning', 'href' => '#ika-results-recommended' );
	}

	$actions[] = array( 'label' => 'Go to Flight Deck', 'href' => home_url( '/flight-deck/' ) );

	// If earned a badge/level on this attempt (Phase 1 bridge), show quick link.
	if ( class_exists( 'IKA_WatuPlay_Modal_Bridge' ) && $taking_id > 0 ) {
		$earned = IKA_WatuPlay_Modal_Bridge::get_earned_for_taking( $taking_id );
		if ( is_array( $earned ) && ( ! empty( $earned['level'] ) || ! empty( $earned['badges'] ) ) ) {
			$actions[] = array( 'label' => 'View badges', 'href' => home_url( '/flight-deck/badges/' ) );
		}
	}

	$actions[] = array( 'label' => 'Browse all quizzes', 'href' => home_url( '/quizzes/' ) );

	$out  = '<div class="ika-results-actions" role="navigation" aria-label="Next actions">';
	foreach ( $actions as $a ) {
		$out .= '<a class="ika-action-pill" href="' . esc_url( (string) $a['href'] ) . '">' . esc_html( (string) $a['label'] ) . '</a>';
	}
	$out .= '</div>';

	return $out;
} );

