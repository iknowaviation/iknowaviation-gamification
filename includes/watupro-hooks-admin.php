<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * WATU completion hook → rebuild stats for that user
 * ======================================================================*/

/**
 * WatuPRO fires: do_action( 'watupro_completed_exam', $taking_id );
 */
add_action( 'watupro_completed_exam', 'ika_on_watupro_completed_exam', 10, 1 );

function ika_on_watupro_completed_exam( $taking_id ) {
	if ( ! $taking_id ) {
		return;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'watupro_taken_exams';

	$taking = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT user_id, percent_correct FROM {$table} WHERE ID = %d",
			$taking_id
		)
	);

	if ( ! $taking || empty( $taking->user_id ) ) {
		return;
	}

	// Update daily missions based on this completion (awards bonus XP once per mission/day).
	if ( function_exists( 'ika_dm_update_on_completion' ) ) {
		$percent = isset( $taking->percent_correct ) ? floatval( $taking->percent_correct ) : 0;
		ika_dm_update_on_completion( (int) $taking->user_id, $percent );
	}

	ika_rebuild_stats_for_user( (int) $taking->user_id );}
