<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * Rank/Xp shortcodes (for Rank & XP card)
 * ======================================================================*/

/**
 * Helper: Flight Deck rank context.
 */
function ika_fd_get_rank_context( $user_id = 0 ) {
	if ( ! is_user_logged_in() ) {
		return null;
	}

	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id ) {
		return null;
	}

	$xp     = floatval( get_user_meta( $user_id, 'ika_total_xp', true ) );
	$ladder = ika_get_rank_ladder();
	$current = ika_get_rank_for_xp( $xp );
	$next    = ika_get_next_rank_for_xp( $xp );

	$total_ranks = count( $ladder );
	$rank_index  = 0;

	foreach ( $ladder as $idx => $step ) {
		if ( $step['slug'] === $current['slug'] ) {
			$rank_index = $idx;
			break;
		}
	}

	// Ladder position (0–100)
	$percent_ladder = 0;
	if ( $total_ranks > 1 ) {
		$percent_ladder = round( ( $rank_index / ( $total_ranks - 1 ) ) * 100 );
	}

	// Level range progress
	$start_xp = isset( $current['min_xp'] ) ? floatval( $current['min_xp'] ) : 0;
	$end_xp   = $next && isset( $next['min_xp'] ) ? floatval( $next['min_xp'] ) : ( $start_xp + 500 );

	$range       = max( 1, $end_xp - $start_xp );
	$earned_lvl  = max( 0, $xp - $start_xp );
	$percent_lvl = max( 0, min( 100, round( ( $earned_lvl / $range ) * 100 ) ) );

	$xp_to_next = $next ? max( 0, $end_xp - $xp ) : 0;

	return array(
		'xp'                 => intval( $xp ),
		'rank_label'         => $current['label'],
		'rank_index'         => $rank_index,
		'total_ranks'        => $total_ranks,
		'rank_position_text' => sprintf( 'Rank %d of %d', $rank_index + 1, $total_ranks ),
		'next_label'         => $next ? $next['label'] : '',
		'xp_to_next'         => intval( $xp_to_next ),
		'percent_ladder'     => intval( $percent_ladder ),
		'percent_level'      => intval( $percent_lvl ),
		'level_xp_earned'    => intval( $earned_lvl ),
		'level_xp_goal'      => intval( $range ),
	);
}

/**
 * [ika_rank_title]
 */
add_shortcode( 'ika_rank_title', function() {
	if ( ! is_user_logged_in() ) return '';
	$data = ika_get_user_xp_and_rank();
	return $data ? esc_html( $data['rank_label'] ) : '';
});

/**
 * [ika_rank_position] → "Rank 3 of 8"
 */
add_shortcode( 'ika_rank_position', function() {
	$ctx = ika_fd_get_rank_context();
	if ( ! $ctx ) return '';
	return esc_html( $ctx['rank_position_text'] );
});

/**
 * [ika_rank_percent] → 0–100 (marker left%)
 */
add_shortcode( 'ika_rank_percent', function() {
	$ctx = ika_fd_get_rank_context();
	if ( ! $ctx ) return '0';
	return intval( $ctx['percent_ladder'] );
});

/**
 * [ika_xp_to_next_rank]
 */
add_shortcode( 'ika_xp_to_next_rank', function() {
	$ctx = ika_fd_get_rank_context();
	if ( ! $ctx ) return '';

	if ( empty( $ctx['next_label'] ) ) {
		return 'Top Rank Achieved!';
	}

	return esc_html( $ctx['xp_to_next'] . ' XP to ' . $ctx['next_label'] );
});

/**
 * [ika_xp_level_progress_percent]
 */
add_shortcode( 'ika_xp_level_progress_percent', function() {
	$ctx = ika_fd_get_rank_context();
	if ( ! $ctx ) return '0';
	return intval( $ctx['percent_level'] );
});

/**
 * [ika_xp_level_earned]
 */
add_shortcode( 'ika_xp_level_earned', function() {
	$ctx = ika_fd_get_rank_context();
	if ( ! $ctx ) return '0';
	return intval( $ctx['level_xp_earned'] );
});

/**
 * [ika_xp_level_goal]
 */
add_shortcode( 'ika_xp_level_goal', function() {
	$ctx = ika_fd_get_rank_context();
	if ( ! $ctx ) return '0';
	return intval( $ctx['level_xp_goal'] );
});