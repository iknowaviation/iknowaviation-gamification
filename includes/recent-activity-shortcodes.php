<?php
/**
 * Flight Deck – Recent Activity (wired)
 *
 * Combines:
 *  - Recent WatuPRO quiz attempts (earned points)
 *  - Mission bonus XP ledger events (user meta)
 *
 * Shortcode:
 *   [ika_fd_recent_activity limit="8" days="14"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ika_fd_get_recent_activity_items' ) ) {
	/**
	 * @return array<int, array{ts:int,type:string,title:string,meta:string,xp:int,url:string}>
	 */
	function ika_fd_get_recent_activity_items( int $user_id, int $limit = 8, int $days = 14 ): array {
		if ( $user_id <= 0 ) return [];
		$limit = max( 1, min( 25, $limit ) );
		$days  = max( 1, min( 60, $days ) );
		$cutoff = time() - ( DAY_IN_SECONDS * $days );

		$items = [];

		// 1) Quiz attempts (WatuPRO taken exams)
		global $wpdb;
		$takings_tbl = function_exists( 'ika_fd_taken_table' ) ? ika_fd_taken_table() : $wpdb->prefix . 'watupro_taken_exams';

		$attempts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id AS taking_id, exam_id, percent_correct, points, end_time\n\t\t\t\t FROM {$takings_tbl}\n\t\t\t\t WHERE user_id = %d\n\t\t\t\t   AND (in_progress IS NULL OR in_progress = 0)\n\t\t\t\t   AND (ignore_attempt IS NULL OR ignore_attempt = 0)\n\t\t\t\t ORDER BY COALESCE(end_time,'') DESC, ID DESC\n\t\t\t\t LIMIT %d",
				$user_id,
				max( 10, $limit * 6 )
			)
		);

		foreach ( (array) $attempts as $a ) {
			$ts = function_exists( 'ika_fd_parse_mysql_datetime_to_ts' ) ? ika_fd_parse_mysql_datetime_to_ts( (string) ( $a->end_time ?? '' ) ) : (int) strtotime( (string) ( $a->end_time ?? '' ) );
			if ( $ts <= 0 ) continue;
			if ( $ts < $cutoff ) continue;

			$exam_id = (int) ( $a->exam_id ?? 0 );
			$pct     = isset( $a->percent_correct ) ? (int) round( (float) $a->percent_correct ) : 0;
			$taking_id = (int) ( $a->taking_id ?? 0 );
			$xp = function_exists( 'ika_xp_for_taking' ) ? (int) ika_xp_for_taking( $taking_id ) : ( isset( $a->points ) ? (int) $a->points : 0 );

			$post_id = function_exists( 'ika_fd_get_quiz_post_id_by_exam_id' ) ? ika_fd_get_quiz_post_id_by_exam_id( $exam_id ) : 0;
			$title   = $post_id ? get_the_title( $post_id ) : ( 'Quiz #' . $exam_id );
			$url     = $post_id ? get_permalink( $post_id ) : '';

			$items[] = [
				'ts'    => $ts,
				'type'  => 'quiz',
				'title' => (string) $title,
				'meta'  => sprintf( '%d%% score', $pct ),
				'xp'    => $xp,
				'url'   => (string) $url,
			];
		}

		// 2) Mission bonus ledger (user meta)
		if ( function_exists( 'ika_xp_bonus_get_ledger' ) ) {
			$ledger = (array) ika_xp_bonus_get_ledger( $user_id );
			// Iterate from newest to oldest to avoid sorting large ledgers.
			for ( $i = count( $ledger ) - 1; $i >= 0; $i-- ) {
				$row = $ledger[ $i ];
				$ts  = isset( $row['ts'] ) ? (int) $row['ts'] : 0;
				if ( $ts <= 0 ) continue;
				if ( $ts < $cutoff ) break; // ledger is chronological

				$amount = isset( $row['amount'] ) ? (int) $row['amount'] : 0;
				$reason = isset( $row['reason'] ) ? (string) $row['reason'] : '';
				if ( $amount === 0 ) continue;

				$items[] = [
					'ts'    => $ts,
					'type'  => 'bonus',
					'title' => 'Mission bonus',
					'meta'  => $reason !== '' ? $reason : 'Bonus XP earned',
					'xp'    => $amount,
					'url'   => '',
				];

				// Soft stop once we have plenty; we'll sort + slice below.
				if ( count( $items ) >= ( $limit * 10 ) ) {
					break;
				}
			}
		}

		// Sort newest-first.
		usort( $items, function( $a, $b ) {
			return (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 );
		} );

		return array_slice( $items, 0, $limit );
	}
}

add_shortcode( 'ika_fd_recent_activity', function( $atts ) {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$atts = shortcode_atts(
		[
			'limit' => 8,
			'days'  => 14,
		],
		(array) $atts,
		'ika_fd_recent_activity'
	);

	$user_id = get_current_user_id();
	$limit   = (int) $atts['limit'];
	$days    = (int) $atts['days'];

	$items = ika_fd_get_recent_activity_items( (int) $user_id, (int) $limit, (int) $days );

	ob_start();
	?>
	<ul class="ika-fd-activity-list" role="list">
		<?php if ( empty( $items ) ) : ?>
			<li class="ika-fd-activity-item is-empty">
				<div class="ika-fd-activity-title">No recent activity yet</div>
				<div class="ika-fd-activity-meta">Take a quiz or complete a mission to start your log.</div>
			</li>
		<?php else : ?>
			<?php foreach ( $items as $it ) :
				$ts   = (int) ( $it['ts'] ?? 0 );
				$type = (string) ( $it['type'] ?? '' );
				$title= (string) ( $it['title'] ?? '' );
				$meta = (string) ( $it['meta'] ?? '' );
				$xp   = (int) ( $it['xp'] ?? 0 );
				$url  = (string) ( $it['url'] ?? '' );
				$time_ago = function_exists( 'ika_fd_time_ago' ) ? ika_fd_time_ago( $ts ) : '';
				$xp_label = $xp !== 0 ? ( ( $xp > 0 ? '+' : '' ) . number_format_i18n( $xp ) . ' XP' ) : '';
				$icon = $type === 'bonus' ? 'fa-bullseye' : 'fa-clipboard-check';
			?>
			<li class="ika-fd-activity-item is-<?php echo esc_attr( $type ); ?>">
				<div class="ika-fd-activity-row">
					<div class="ika-fd-activity-left">
						<span class="ika-fd-activity-icon"><i class="fa-solid <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i></span>
						<div class="ika-fd-activity-text">
							<div class="ika-fd-activity-title">
								<?php if ( $url && $type === 'quiz' ) : ?>
									<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $title ); ?>
								<?php endif; ?>
							</div>
							<div class="ika-fd-activity-meta"><?php echo esc_html( trim( $meta ) ); ?></div>
						</div>
					</div>
					<div class="ika-fd-activity-right">
						<?php if ( $xp_label ) : ?><div class="ika-fd-activity-xp"><?php echo esc_html( $xp_label ); ?></div><?php endif; ?>
						<?php if ( $time_ago ) : ?><div class="ika-fd-activity-time"><?php echo esc_html( $time_ago ); ?></div><?php endif; ?>
					</div>
				</div>
			</li>
			<?php endforeach; ?>
		<?php endif; ?>
	</ul>
	<?php
	return ob_get_clean();
} );
