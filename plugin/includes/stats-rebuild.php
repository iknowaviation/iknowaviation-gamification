<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * Core stats rebuild (single user + all users)
 * ======================================================================*/

/**
 * Rebuild all IKA stats for a single user from WatuPRO data.
 */
function ika_rebuild_stats_for_user( $user_id ) {
	if ( ! $user_id ) {
		return;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'watupro_taken_exams';

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT exam_id, percent_correct, points, end_time, in_progress, ignore_attempt
			 FROM {$table}
			 WHERE user_id = %d
			   AND (ignore_attempt IS NULL OR ignore_attempt = 0)
			   AND (in_progress IS NULL OR in_progress = 0)",
			$user_id
		)
	);

	if ( ! $rows ) {
		// Reset stats if no attempts.
		update_user_meta( $user_id, 'ika_quizzes_completed', 0 );
		update_user_meta( $user_id, 'ika_total_attempts', 0 );
		update_user_meta( $user_id, 'ika_score_sum', 0 );
		update_user_meta( $user_id, 'ika_avg_score', 0 );
		update_user_meta( $user_id, 'ika_best_score', 0 );
		update_user_meta( $user_id, 'ika_total_xp', 0 );
		update_user_meta( $user_id, 'ika_current_streak_days', 0 );
		update_user_meta( $user_id, 'ika_last_quiz_date', '' );
		update_user_meta( $user_id, 'ika_last_quiz_timestamp', 0 );
		return;
	}

	$total_attempts  = count( $rows );
	$unique_quiz_ids = array();
	$score_sum       = 0.0;
	$best_score      = 0.0;
	$total_points    = 0;
	$dates           = array();
	$last_quiz_ts    = 0;
	$last_quiz_date  = '';

	foreach ( $rows as $row ) {
		$unique_quiz_ids[ $row->exam_id ] = true;

		$score = isset( $row->percent_correct ) ? (float) $row->percent_correct : 0.0;
		if ( $score > 0 ) {
			$score_sum += $score;
			if ( $score > $best_score ) {
				$best_score = $score;
			}
		}

		$pts          = isset( $row->points ) ? (int) $row->points : 0;
		$total_points += $pts;

		if ( ! empty( $row->end_time ) ) {
			$dt = new DateTime( $row->end_time );
			$dates[] = $dt->format( 'Y-m-d' );

			$ts = $dt->getTimestamp();
			if ( $ts > $last_quiz_ts ) {
				$last_quiz_ts   = $ts;
				$last_quiz_date = $dt->format( 'Y-m-d' );
			}
		}
	}

	$quizzes_completed = count( $unique_quiz_ids );
	$avg_score         = $total_attempts > 0 ? ( $score_sum / $total_attempts ) : 0.0;
	$streak_days       = ika_compute_streak_from_dates( $dates );

	update_user_meta( $user_id, 'ika_quizzes_completed', $quizzes_completed );
	update_user_meta( $user_id, 'ika_total_attempts', $total_attempts );
	update_user_meta( $user_id, 'ika_score_sum', $score_sum );
	update_user_meta( $user_id, 'ika_avg_score', $avg_score );
	update_user_meta( $user_id, 'ika_best_score', $best_score );
	update_user_meta( $user_id, 'ika_total_xp', $total_points );
	update_user_meta( $user_id, 'ika_current_streak_days', $streak_days );
	update_user_meta( $user_id, 'ika_last_quiz_date', $last_quiz_date );
	update_user_meta( $user_id, 'ika_last_quiz_timestamp', $last_quiz_ts );

	// Also update stored rank label/slug for convenience.
	$rank = ika_get_rank_for_xp( $total_points );
	update_user_meta( $user_id, 'ika_rank_slug',  $rank['slug'] );
	update_user_meta( $user_id, 'ika_rank_label', $rank['label'] );
}

/**
 * Rebuild stats for all users who have Watu attempts.
 */
function ika_rebuild_stats_for_all_users() {
	global $wpdb;

	$table    = $wpdb->prefix . 'watupro_taken_exams';
	$user_ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} WHERE user_id > 0" );

	if ( empty( $user_ids ) ) {
		return 0;
	}

	foreach ( $user_ids as $uid ) {
		ika_rebuild_stats_for_user( (int) $uid );
	}

	return count( $user_ids );
}

/* ======================================================================
 * Admin tool – Tools → IKA Stats Rebuild
 * ======================================================================*/

/**
 * Add Tools → IKA Stats Rebuild page.
 */
add_action( 'admin_menu', 'ika_register_stats_tools_page' );

function ika_register_stats_tools_page() {
	add_management_page(
		'IKA Stats Rebuild',
		'IKA Stats Rebuild',
		'manage_options',
		'ika-stats-rebuild',
		'ika_render_stats_rebuild_page'
	);
}

/**
 * Render rebuild page.
 */
function ika_render_stats_rebuild_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ika' ) );
	}

	$did_rebuild   = false;
	$rebuild_count = 0;

	if ( isset( $_POST['ika_rebuild_stats'] ) && check_admin_referer( 'ika_rebuild_stats_action', 'ika_rebuild_stats_nonce' ) ) {
		$rebuild_count = ika_rebuild_stats_for_all_users();
		$did_rebuild   = true;
	}
	?>
	<div class="wrap">
		<h1>IKA Stats Rebuild</h1>
		<p>Click the button below to rebuild Flight Deck stats (quizzes, attempts, scores, XP, streaks, ranks) for all users based on WatuPRO data.</p>

		<?php if ( $did_rebuild ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html( "Rebuilt stats for {$rebuild_count} user(s)." ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'ika_rebuild_stats_action', 'ika_rebuild_stats_nonce' ); ?>
			<p>
				<input type="submit" class="button button-primary" name="ika_rebuild_stats" value="Rebuild Stats Now" />
			</p>
		</form>
	</div>
	<?php
}

// Debug Panel hook: Rebuild stats (Tools → IKA Gamification Debug)
add_action( 'ika_gam_rebuild_stats', function() {
    ika_rebuild_stats_for_all_users();
} );
