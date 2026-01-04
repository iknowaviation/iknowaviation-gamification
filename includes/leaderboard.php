<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * Leaderboard: [ika_leaderboard limit="10"]
 * ======================================================================*/

/* Helper: quizzes completed for a given user (distinct exams) */
function ika_fd_get_quizzes_completed_for_user( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return 0;
	}

	$takings_tbl = $wpdb->prefix . 'watupro_taken_exams';

	$sql = "
		SELECT COUNT(DISTINCT exam_id)
		FROM {$takings_tbl}
		WHERE user_id = %d
		  AND (in_progress = 0 OR in_progress IS NULL)
		  AND (ignore_attempt IS NULL OR ignore_attempt = 0)
	";

	$count = $wpdb->get_var( $wpdb->prepare( $sql, $user_id ) );
	return intval( $count );
}

/* Leaderboard table */
add_shortcode( 'ika_leaderboard', function( $atts ) {
	$atts  = shortcode_atts( array( 'limit' => 10 ), $atts );
	$limit = max( 1, intval( $atts['limit'] ) );

	$cache_key = 'ika_leaderboard_top_' . $limit;
	$results   = get_transient( $cache_key );

	if ( false === $results ) {
		$users = new WP_User_Query( array(
			'number'     => $limit,
			'meta_key'   => 'ika_total_xp',
			'orderby'    => 'meta_value_num',
			'order'      => 'DESC',
			'meta_query' => array(
				array(
					'key'     => 'ika_total_xp',
					'value'   => 1,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		) );

		$results = $users->get_results();
		// Cache for 10 minutes (adjust later)
		set_transient( $cache_key, $results, 10 * MINUTE_IN_SECONDS );
	}

	if ( empty( $results ) ) {
		return '<div class="ika-leaderboard-empty">No pilots on the leaderboard yet.</div>';
	}

	$current_user_id = get_current_user_id();
	$pos             = 1;

	ob_start(); ?>
	<div class="ika-leaderboard">
	  <table class="ika-hub-flightlog-table ika-hub-leaderboard-table">
		<thead>
		  <tr>
			<th>Rank</th>
			<th>Pilot</th>
			<th>Level</th>
			<th>Quizzes</th>
			<th>XP</th>
		  </tr>
		</thead>
		<tbody>
		  <?php foreach ( $results as $user ) :
			  $user_id = $user->ID;
			  $data    = ika_get_user_xp_and_rank( $user_id );
			  $xp      = $data ? intval( $data['xp'] ) : 0;
			  $level   = $data ? $data['rank_label'] : '';
			  $quizzes = ika_fd_get_quizzes_completed_for_user( $user_id );

			  $row_class = ( $user_id === $current_user_id ) ? 'ika-leaderboard-row--me' : '';
			  ?>
			  <tr class="<?php echo esc_attr( $row_class ); ?>">
				<td>#<?php echo intval( $pos ); ?></td>
				<td>
				  <?php echo get_avatar( $user_id, 24 ); ?>
				  <?php echo esc_html( $user->display_name ); ?>
				</td>
				<td><?php echo esc_html( $level ); ?></td>
				<td><?php echo intval( $quizzes ); ?></td>
				<td><?php echo intval( $xp ); ?></td>
			  </tr>
		  <?php
			  $pos++;
		  endforeach; ?>
		</tbody>
	  </table>
	</div>
	<?php
	return ob_get_clean();
});

// Debug Panel hook: clear leaderboard caches
add_action( 'ika_gam_rebuild_leaderboard_cache', function() {

    // Clear a reasonable range of cached sizes you may use.
    // Adjust if you only ever use limit=10.
    foreach ( array( 5, 10, 15, 20, 25, 50 ) as $limit ) {
        delete_transient( 'ika_leaderboard_top_' . $limit );
        delete_transient( 'ika_leaderboard_week_7_' . $limit );
    }
} );



/* ======================================================================
 * Weekly Leaderboard: [ika_leaderboard_week limit="10" days="7"]
 * - Ranks by XP earned in last N days:
 *   * Quiz XP from WatuPRO taken exams (finished attempts)
 *   * + Mission bonus XP from bonus ledger (if available)
 * - For performance, starts from top quiz-XP earners as candidates, then adds bonus in PHP.
 * ======================================================================*/

add_shortcode( 'ika_leaderboard_week', function( $atts ) {

	if ( ! is_user_logged_in() ) {
		return '';
	}

	global $wpdb;

	$atts = shortcode_atts( array(
		'limit' => 10,
		'days'  => 7,
	), $atts );

	$limit = max( 1, intval( $atts['limit'] ) );
	if ( $limit > 50 ) $limit = 50;

	$days = max( 1, intval( $atts['days'] ) );

	$cache_key = 'ika_leaderboard_week_' . $days . '_' . $limit;
	$rows = get_transient( $cache_key );

	$table = $wpdb->prefix . 'watupro_taken_exams';

	if ( false === $rows ) {

		$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $found !== $table ) {
			$rows = [];
		} else {

			// Detect columns (WatuPRO varies).
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			$cols_lc = array_map( 'strtolower', $cols );

			$date_col = null;
			foreach ( array( 'date_taken', 'date', 'date_started', 'date_end', 'end_time' ) as $cand ) {
				if ( in_array( $cand, $cols_lc, true ) ) { $date_col = $cand; break; }
			}

			$points_col = null;
			foreach ( array( 'points', 'earned_points', 'result_points', 'wp_points' ) as $cand ) {
				if ( in_array( $cand, $cols_lc, true ) ) { $points_col = $cand; break; }
			}

			$finished_col = null;
			foreach ( array( 'is_finished', 'finished', 'is_complete', 'completed' ) as $cand ) {
				if ( in_array( $cand, $cols_lc, true ) ) { $finished_col = $cand; break; }
			}

			$exam_col = null;
			foreach ( array( 'exam_id', 'quiz_id', 'exam' ) as $cand ) {
				if ( in_array( $cand, $cols_lc, true ) ) { $exam_col = $cand; break; }
			}

			$percent_col = null;
			foreach ( array( 'percent_correct', 'percent', 'result_percent' ) as $cand ) {
				if ( in_array( $cand, $cols_lc, true ) ) { $percent_col = $cand; break; }
			}

			$rows = [];

			if ( $date_col && $points_col ) {

				$where_finished = $finished_col ? " AND {$finished_col} = 1 " : '';

				// Candidate set: top recent quiz XP earners.
				$candidate_limit = max( 50, min( 300, $limit * 30 ) );

				$sql = $wpdb->prepare(
					"
					SELECT user_id, COALESCE(SUM({$points_col}), 0) AS quiz_xp
					FROM {$table}
					WHERE {$date_col} >= DATE_SUB(NOW(), INTERVAL %d DAY)
					  {$where_finished}
					GROUP BY user_id
					ORDER BY quiz_xp DESC
					LIMIT %d
					",
					$days,
					$candidate_limit
				);

				$cand = $wpdb->get_results( $sql, ARRAY_A );

				if ( $cand ) {

					$user_ids = array_map( 'intval', wp_list_pluck( $cand, 'user_id' ) );

					// Weekly completed quizzes count when possible (distinct exams with best attempt >= 70% in window).
					$completed_map = [];
					if ( $exam_col && $percent_col && $user_ids ) {
						$in = implode( ',', array_map( 'intval', $user_ids ) );
						$sqlc = $wpdb->prepare(
							"
							SELECT t.user_id, COUNT(*) AS completed_cnt
							FROM (
								SELECT user_id, {$exam_col} AS exam_id, MAX({$percent_col}) AS best_pct
								FROM {$table}
								WHERE user_id IN ({$in})
								  AND {$date_col} >= DATE_SUB(NOW(), INTERVAL %d DAY)
								  {$where_finished}
								GROUP BY user_id, {$exam_col}
								HAVING best_pct >= 70
							) t
							GROUP BY t.user_id
							",
							$days
						);

						$comp = $wpdb->get_results( $sqlc, ARRAY_A );
						if ( $comp ) {
							foreach ( $comp as $r ) {
								$completed_map[ (int) $r['user_id'] ] = (int) $r['completed_cnt'];
							}
						}
					}

					foreach ( $cand as $r ) {
						$uid = (int) $r['user_id'];
						$quiz_xp = (int) $r['quiz_xp'];

						$bonus = 0;
						if ( function_exists( 'ika_xp_bonus_sum_since_days' ) ) {
							$bonus = (int) ika_xp_bonus_sum_since_days( $uid, $days );
						}

						$rows[] = array(
							'user_id' => $uid,
							'xp'      => $quiz_xp + $bonus,
							'quizzes' => (int) ( $completed_map[ $uid ] ?? 0 ),
						);
					}

					usort( $rows, function( $a, $b ) {
						return (int) $b['xp'] <=> (int) $a['xp'];
					} );

					$rows = array_slice( $rows, 0, $limit );
				}
			}
		}

		set_transient( $cache_key, $rows, 10 * MINUTE_IN_SECONDS );
	}

	$current_user_id = get_current_user_id();
	$pos = 1;

	ob_start(); ?>
	<div class="ika-leaderboard">
	  <table class="ika-hub-flightlog-table ika-hub-leaderboard-table">
		<thead>
		  <tr>
			<th>Rank</th>
			<th>Pilot</th>
			<th>Level</th>
			<th>Quizzes (<?php echo intval( $days ); ?>d)</th>
			<th>XP (<?php echo intval( $days ); ?>d)</th>
		  </tr>
		</thead>
		<tbody>
		  <?php foreach ( (array) $rows as $row ) :
			  $user_id = (int) ( $row['user_id'] ?? 0 );
			  if ( ! $user_id ) continue;
			  $user = get_user_by( 'id', $user_id );
			  if ( ! $user ) continue;

			  $data  = ika_get_user_xp_and_rank( $user_id );
			  $level = $data ? $data['rank_label'] : '';
			  $xp    = (int) ( $row['xp'] ?? 0 );
			  $quizzes = (int) ( $row['quizzes'] ?? 0 );

			  $row_class = ( $user_id === $current_user_id ) ? 'ika-leaderboard-row--me' : '';
			  ?>
			  <tr class="<?php echo esc_attr( $row_class ); ?>">
				<td>#<?php echo intval( $pos ); ?></td>
				<td>
				  <?php echo get_avatar( $user_id, 24 ); ?>
				  <?php echo esc_html( $user->display_name ); ?>
				</td>
				<td><?php echo esc_html( $level ); ?></td>
				<td><?php echo intval( $quizzes ); ?></td>
				<td><?php echo intval( $xp ); ?></td>
			  </tr>
			  <?php
			  $pos++;
		  endforeach; ?>
		</tbody>
	  </table>
	</div>
	<?php
	return ob_get_clean();
} );
