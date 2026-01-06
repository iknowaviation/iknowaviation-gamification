<?php
/**
 * Flight Deck – Shared Helpers
 *
 * Small, safe helpers used by multiple Flight Deck shortcodes.
 * Designed to avoid heavy queries and provide consistent data access.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'ika_fd_taken_table' ) ) {
	function ika_fd_taken_table() {
		global $wpdb;
		if ( function_exists( 'ika_watupro_table' ) ) {
			return ika_watupro_table( 'WATUPRO_TAKEN_EXAMS', 'watupro_taken_exams' );
		}
		return $wpdb->prefix . 'watupro_taken_exams';
	}
}

if ( ! function_exists( 'ika_fd_get_completed_exam_ids' ) ) {
	/**
	 * Completed = best attempt score >= $threshold.
	 *
	 * @return int[] exam_ids
	 */
	function ika_fd_get_completed_exam_ids( $user_id, $threshold = 70 ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$threshold = (int) $threshold;
		if ( ! $user_id ) return [];

		$table = ika_fd_taken_table();

		$sql = "
			SELECT exam_id
			FROM {$table}
			WHERE user_id = %d
			  AND (in_progress IS NULL OR in_progress = 0)
			  AND (ignore_attempt IS NULL OR ignore_attempt = 0)
			GROUP BY exam_id
			HAVING MAX(COALESCE(percent_correct,0)) >= %d
		";

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $user_id, $threshold ) );
		return array_values( array_filter( array_map( 'intval', (array) $rows ) ) );
	}
}

if ( ! function_exists( 'ika_fd_get_last_attempt_exam_id' ) ) {
	function ika_fd_get_last_attempt_exam_id( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) return 0;

		$table = ika_fd_taken_table();

		$sql = "
			SELECT exam_id
			FROM {$table}
			WHERE user_id = %d
			  AND (in_progress IS NULL OR in_progress = 0)
			  AND (ignore_attempt IS NULL OR ignore_attempt = 0)
			ORDER BY COALESCE(end_time,'') DESC, ID DESC
			LIMIT 1
		";

		$val = $wpdb->get_var( $wpdb->prepare( $sql, $user_id ) );
		return (int) $val;
	}
}

if ( ! function_exists( 'ika_fd_extract_exam_id_from_content' ) ) {
	function ika_fd_extract_exam_id_from_content( $content ) {
		if ( ! is_string( $content ) || $content === '' ) return 0;
		if ( preg_match( '/\[watupro\s+(\d+)\]/i', $content, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}
}

if ( ! function_exists( 'ika_fd_get_quiz_index' ) ) {
	/**
	 * Build a lightweight index of Quiz CPT posts keyed by exam_id.
	 * Cached for the request and via wp_cache.
	 *
	 * @return array{by_exam: array<int,int>, by_post: array<int,int>}
	 */
	function ika_fd_get_quiz_index() {
		static $local = null;
		if ( $local !== null ) return $local;

		$cache_key = 'ika_fd_quiz_index_v1';
		$cached = wp_cache_get( $cache_key, 'ika' );
		if ( is_array( $cached ) && isset( $cached['by_exam'], $cached['by_post'] ) ) {
			$local = $cached;
			return $local;
		}

		$by_exam = [];
		$by_post = [];

		$ids = get_posts([
			'post_type'      => 'quiz',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'numberposts'    => 5000,
			'no_found_rows'  => true,
			'suppress_filters'=> true,
		]);

		foreach ( (array) $ids as $post_id ) {
			$content = get_post_field( 'post_content', $post_id );
			$exam_id = ika_fd_extract_exam_id_from_content( $content );
			if ( $exam_id ) {
				$by_exam[ $exam_id ] = (int) $post_id;
				$by_post[ (int) $post_id ] = (int) $exam_id;
			}
		}

		$local = [ 'by_exam' => $by_exam, 'by_post' => $by_post ];
		wp_cache_set( $cache_key, $local, 'ika', 300 ); // 5 min
		return $local;
	}
}

if ( ! function_exists( 'ika_fd_get_quiz_post_id_by_exam_id' ) ) {
	function ika_fd_get_quiz_post_id_by_exam_id( $exam_id ) {
		$exam_id = (int) $exam_id;
		if ( ! $exam_id ) return 0;
		$idx = ika_fd_get_quiz_index();
		return isset( $idx['by_exam'][ $exam_id ] ) ? (int) $idx['by_exam'][ $exam_id ] : 0;
	}
}

if ( ! function_exists( 'ika_fd_get_quiz_group_term_ids' ) ) {
	function ika_fd_get_quiz_group_term_ids( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) return [];
		$terms = get_the_terms( $post_id, 'ika_quiz_group' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) return [];
		return array_values( array_map( fn($t)=> (int) $t->term_id, $terms ) );
	}
}

if ( ! function_exists( 'ika_fd_format_attempt_date' ) ) {
	function ika_fd_format_attempt_date( $end_time ) {
		if ( empty( $end_time ) ) return '';
		try {
			$dt = new DateTime( $end_time );
			return $dt->format( 'M j, Y' );
		} catch ( Exception $e ) {
			return '';
		}
	}
}

if ( ! function_exists( 'ika_fd_parse_mysql_datetime_to_ts' ) ) {
	/**
	 * Best-effort: parse a MySQL DATETIME-ish string to a unix timestamp.
	 * Returns 0 on failure.
	 */
	function ika_fd_parse_mysql_datetime_to_ts( $datetime ): int {
		if ( empty( $datetime ) || ! is_string( $datetime ) ) return 0;
		$ts = strtotime( $datetime );
		return $ts ? (int) $ts : 0;
	}
}

if ( ! function_exists( 'ika_fd_time_ago' ) ) {
	/**
	 * Human-friendly time-ago label for a unix timestamp.
	 */
	function ika_fd_time_ago( int $ts ): string {
		if ( $ts <= 0 ) return '';
		$now = (int) current_time( 'timestamp' );
		if ( $ts > $now ) $ts = $now;
		return human_time_diff( $ts, $now ) . ' ago';
	}
}
