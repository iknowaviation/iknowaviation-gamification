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
    }
} );
