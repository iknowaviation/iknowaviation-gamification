<?php
/**
 * Quiz Hub (Archive) Shortcode
 *
 * Usage: [ika_quiz_hub]
 *
 * Renders:
 * - Track filter pills
 * - Search
 * - Sort dropdown
 * - Paginated quiz grid (cards)
 *
 * Step B (locked):
 * - Card chips show Group + Difficulty
 * - Score line: Best if complete (>=70), Last if not
 * - CTA text always: "Start Quiz"
 * - Completed cards de-emphasized via CSS (scoped to Quiz Hub)
 *
 * Status rules (locked):
 * - is-new: no attempts
 * - is-started: attempts exist AND best_score < 70
 * - is-complete: best_score >= 70
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



/**
 * Estimate quiz duration in minutes (low-cost weighted model).
 *
 * Uses WatuPRO question rows when available (by exam_id), otherwise falls back
 * to a simple question-count heuristic.
 *
 * Weights (seconds):
 * - simple single-choice / true-false ......... 15s
 * - image-based .............................. 20s
 * - longer stem / scenario ................... 30s
 * - multiple / sorting / matching / open ..... 35s
 *
 * Rounded to nearest 0.5 minute, minimum 1 minute.
 */
function ika_qh_estimate_minutes( $exam_id, int $question_count_fallback = 0 ): float {
	$exam_id = (int) $exam_id;

	// Fallback if no exam_id.
	if ( $exam_id <= 0 ) {
		$seconds = max( 1, $question_count_fallback ) * 20;
		$minutes = max( 1, $seconds / 60 );
		return round( $minutes * 2 ) / 2;
	}

	global $wpdb;
	$tq = $wpdb->prefix . 'watupro_question';

	// Pull only what we need: question text + optional type-ish columns.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT question, answer_type, question_type FROM {$tq} WHERE exam_id = %d",
			$exam_id
		),
		ARRAY_A
	);

	// If table/columns differ, fail gracefully.
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		$seconds = max( 1, $question_count_fallback ) * 20;
		$minutes = max( 1, $seconds / 60 );
		return round( $minutes * 2 ) / 2;
	}

	$seconds = 0;

	foreach ( $rows as $r ) {
		$text = strtolower( (string) ( $r['question'] ?? '' ) );
		$type = strtolower( (string) ( $r['question_type'] ?? $r['answer_type'] ?? '' ) );
		$len  = strlen( trim( $text ) );

		// Default: simple MC/TF.
		$w = 15;

		// Heuristic: detect likely image content in question HTML.
		$has_image = ( strpos( $text, '<img' ) !== false ) || ( strpos( $text, 'wp-image' ) !== false );

		// Type hints (best-effort; WatuPRO schemas vary)
		if ( $type ) {
			if ( preg_match( '/(multiple|checkbox|open|essay|sort|order|match)/', $type ) ) {
				$w = 35;
			} elseif ( preg_match( '/(true|false|tf)/', $type ) ) {
				$w = 15;
			} else {
				$w = 20;
			}
		}

		if ( $has_image && $w < 20 ) {
			$w = 20;
		}

		// Long stem bump.
		if ( $len >= 180 && $w < 30 ) {
			$w = 30;
		}

		$seconds += $w;
	}

	$minutes = max( 1, $seconds / 60 );
	$minutes = round( $minutes * 2 ) / 2;
	return $minutes;
}

/** Format minutes for display (shows .5 if needed). */
function ika_qh_format_minutes( float $minutes ): string {
	if ( abs( $minutes - round( $minutes ) ) < 0.001 ) {
		return (string) (int) round( $minutes );
	}
	return number_format( $minutes, 1 );
}
if ( ! function_exists( 'ika_quiz_hub_grade_from_percent' ) ) {
	/**
	 * Convert percent score to letter grade using the locked WatuPRO scale.
	 * A+ = 100; A = 99–91; B = 89–80; C = 79–70; D = 69–60; F < 60
	 */
	function ika_quiz_hub_grade_from_percent( float $pct ) : string {
		$p = (int) round( $pct );
		if ( $p >= 100 ) return 'A+';
		if ( $p >= 91 ) return 'A';
		if ( $p >= 80 ) return 'B';
		if ( $p >= 70 ) return 'C';
		if ( $p >= 60 ) return 'D';
		return 'F';
	}
}

if ( ! function_exists( 'ika_quiz_hub_status_class' ) ) {
	function ika_quiz_hub_status_class( array $stat ) : string {
		$attempts = isset( $stat['attempts'] ) ? intval( $stat['attempts'] ) : 0;
		$best     = isset( $stat['best'] ) ? floatval( $stat['best'] ) : 0.0;

		if ( $attempts <= 0 ) return 'is-new';
		if ( $best >= 70.0 ) return 'is-complete';
		return 'is-started';
	}
}

if ( ! function_exists( 'ika_quiz_hub_status_text' ) ) {
	function ika_quiz_hub_status_text( string $status_class ) : string {
		if ( $status_class === 'is-complete' ) return 'COMPLETED';
		if ( $status_class === 'is-started' ) return 'IN PROGRESS';
		return 'NEW';
	}
}

if ( ! function_exists( 'ika_quiz_hub_get_user_exam_stats' ) ) {
	/**
	 * Returns exam stats keyed by exam_id:
	 * - attempts
	 * - best (max percent_correct)
	 * - last (percent_correct of most recent attempt)
	 * - last_taken
	 */
	function ika_quiz_hub_get_user_exam_stats( array $exam_ids, int $user_id ) : array {
		global $wpdb;

		$out = array();
		if ( empty( $exam_ids ) || $user_id <= 0 ) return $out;

		$exam_ids = array_values( array_unique( array_map( 'intval', $exam_ids ) ) );
		$exam_ids = array_filter( $exam_ids, function( $v ) { return $v > 0; } );
		if ( empty( $exam_ids ) ) return $out;

		// helper exists in plugin; fallback in case file order changes
		if ( function_exists( 'ika_watupro_table' ) ) {
			$takings_tbl = ika_watupro_table( 'WATUPRO_TAKEN_EXAMS', 'watupro_taken_exams' );
		} else {
			$takings_tbl = $wpdb->prefix . 'watupro_taken_exams';
		}

		// IN (...) placeholders
		$placeholders = implode( ',', array_fill( 0, count( $exam_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			"SELECT exam_id,
			        COUNT(*) as attempts,
			        MAX(percent_correct) as best,
			        MAX(ID) as last_id,
			        SUBSTRING_INDEX(GROUP_CONCAT(percent_correct ORDER BY ID DESC), ',', 1) as last_percent
			 FROM {$takings_tbl}
			 WHERE user_id = %d
			   AND exam_id IN ($placeholders)
			 GROUP BY exam_id",
			array_merge( array( $user_id ), $exam_ids )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) return $out;

		foreach ( $rows as $r ) {
			$eid = intval( $r['exam_id'] );
			$out[ $eid ] = array(
				'attempts'   => intval( $r['attempts'] ),
				'best'       => isset( $r['best'] ) ? floatval( $r['best'] ) : 0.0,
				'last'       => isset( $r['last_percent'] ) ? floatval( $r['last_percent'] ) : 0.0,
				'last_taken' => null,
			);
		}

		return $out;
	}
}

if ( ! function_exists( 'ika_quiz_hub_get_question_counts' ) ) {
	/**
	 * Bulk fetch question counts for each exam_id.
	 * Returns [ exam_id => count ].
	 */
	function ika_quiz_hub_get_question_counts( array $exam_ids ) : array {
		global $wpdb;

		$out = array();
		$exam_ids = array_values( array_unique( array_map( 'intval', $exam_ids ) ) );
		$exam_ids = array_filter( $exam_ids, function( $v ) { return $v > 0; } );
		if ( empty( $exam_ids ) ) return $out;

		if ( function_exists( 'ika_watupro_table' ) ) {
			$q_tbl = ika_watupro_table( 'WATUPRO_QUESTIONS', 'watupro_question' );
		} else {
			$q_tbl = $wpdb->prefix . 'watupro_question';
		}

		$placeholders = implode( ',', array_fill( 0, count( $exam_ids ), '%d' ) );
		$sql = $wpdb->prepare(
			"SELECT exam_id, COUNT(*) as qcount
			 FROM {$q_tbl}
			 WHERE exam_id IN ($placeholders)
			 GROUP BY exam_id",
			$exam_ids
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ intval( $r['exam_id'] ) ] = intval( $r['qcount'] );
			}
		}

		return $out;
	}
}

if ( ! function_exists( 'ika_sc_quiz_hub' ) ) {
	function ika_sc_quiz_hub( $atts = array() ) : string {

		$atts = shortcode_atts(
			array(
				'per_page' => 12,
			),
			(array) $atts,
			'ika_quiz_hub'
		);

		$per_page = max( 6, min( 30, intval( $atts['per_page'] ) ) );

		// Inputs
		$track = isset( $_GET['track'] ) ? sanitize_text_field( wp_unslash( $_GET['track'] ) ) : '';
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$sort  = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( $_GET['sort'] ) ) : 'path';

		$paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

		// Query quiz CPT ids
		$args = array(
			'post_type'      => 'quiz',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			's'              => $q,
		);

		if ( $track ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'ika_quiz_track',
					'field'    => 'slug',
					'terms'    => array( $track ),
				),
			);
		}

		$ids = get_posts( $args );

		// Map each post to exam_id
		$post_exam = array();
		$exam_ids  = array();
		foreach ( $ids as $pid ) {
			$eid = intval( get_post_meta( $pid, '_ika_watupro_exam_id', true ) );
			$post_exam[ $pid ] = $eid;
			if ( $eid > 0 ) $exam_ids[] = $eid;
		}

		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$stats   = ( $user_id > 0 ) ? ika_quiz_hub_get_user_exam_stats( $exam_ids, $user_id ) : array();

		// Sorting
		if ( $sort === 'path' ) {
			usort( $ids, function( $a, $b ) {
				$ao = intval( get_post_field( 'menu_order', $a ) );
				$bo = intval( get_post_field( 'menu_order', $b ) );
				if ( $ao === $bo ) return $a <=> $b;
				return $ao <=> $bo;
			});
		} elseif ( $sort === 'newest' ) {
			usort( $ids, function( $a, $b ) {
				return strtotime( get_post_field( 'post_date', $b ) ) <=> strtotime( get_post_field( 'post_date', $a ) );
			});
		} elseif ( $sort === 'title' ) {
			usort( $ids, function( $a, $b ) {
				return strcasecmp( get_the_title( $a ), get_the_title( $b ) );
			});
		}

		$total = count( $ids );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged = min( $paged, $total_pages );
		$offset = ( $paged - 1 ) * $per_page;

		$page_ids = array_slice( $ids, $offset, $per_page );

		// Bulk question counts for this page
		$page_exam_ids = array();

		$q_counts = ika_quiz_hub_get_question_counts( $page_exam_ids );

		// Track terms for pills
		$tracks = get_terms( array(
			'taxonomy'   => 'ika_quiz_track',
			'hide_empty' => true,
		) );

		$base_url = get_post_type_archive_link( 'quiz' );
		if ( ! $base_url ) $base_url = home_url( '/quizzes/' );

		// Build url helper (preserve q/sort)
		$build_url = function( array $params ) use ( $base_url, $track, $q, $sort ) : string {
			$merged = array(
				'track' => $track ?: null,
				'q'     => $q ?: null,
				'sort'  => $sort ?: null,
			);
			foreach ( $params as $k => $v ) {
				$merged[ $k ] = $v;
			}
			// remove nulls
			$merged = array_filter( $merged, function( $v ) { return $v !== null && $v !== ''; } );
			return add_query_arg( $merged, $base_url );
		};

		$out = '';
		$out .= '<div class="ika-quiz-hub">';
		$out .= '<div class="ika-quiz-hub__toolbar">';

		// Track pills
		$out .= '<div class="ika-quiz-hub__tracks">';
		$all_active = empty( $track ) ? ' is-active' : '';
		$out .= '<a class="ika-pill' . esc_attr( $all_active ) . '" href="' . esc_url( $build_url( array( 'track' => null, 'paged' => 1 ) ) ) . '">All Tracks</a>';
		if ( ! is_wp_error( $tracks ) && ! empty( $tracks ) ) {
			foreach ( $tracks as $t ) {
				$active = ( $track === $t->slug ) ? ' is-active' : '';
				$out .= '<a class="ika-pill' . esc_attr( $active ) . '" href="' . esc_url( $build_url( array( 'track' => $t->slug, 'paged' => 1 ) ) ) . '">' . esc_html( $t->name ) . '</a>';
			}
		}
		$out .= '</div>';

		// Controls
		$out .= '<form class="ika-quiz-hub__controls" method="get" action="' . esc_url( $base_url ) . '">';
		if ( $track ) $out .= '<input type="hidden" name="track" value="' . esc_attr( $track ) . '" />';

		// Left: search input + button
		$out .= '<div class="ika-quiz-hub__controls-left">';
		$out .= '<div class="ika-quiz-hub__search">'
			. '<input type="search" name="q" placeholder="Search quizzes..." value="' . esc_attr( $q ) . '" '
			. 'onkeydown="if(event.key===\'Enter\'){event.preventDefault();document.getElementById(\'ika-qh-search-btn\').click();}" />'
			. '</div>';
		$out .= '<div class="ika-quiz-hub__apply">'
			. '<button id="ika-qh-search-btn" class="ika-quiz-hub__go ika-qh-search" type="submit">Search</button>'
			. '</div>';
		$out .= '</div>';

		// Right: sort dropdown (auto-submit on change)
		$out .= '<div class="ika-quiz-hub__controls-right">';
		$out .= '<div class="ika-quiz-hub__sort"><select name="sort" onchange="this.form.submit()">';
		$sort_opts = array(
			'path'   => 'Recommended order',
			'title'  => 'Title (A-Z)',
			'newest' => 'Newest',
		);
		foreach ( $sort_opts as $key => $label ) {
			$sel = selected( $sort, $key, false );
			$out .= '<option value="' . esc_attr( $key ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select></div>';
		$out .= '</div>'; // controls-right
		$out .= '</form>';

		$out .= '</div>'; // toolbar

		// Grid
		$out .= '<div class="ika-quiz-hub__grid">';

		if ( empty( $page_ids ) ) {
			$clear_url = esc_url( remove_query_arg( array( 'q', 'paged' ) ) );
				$out .= '<div class="ika-quiz-hub__empty">'
					. '<div class="ika-quiz-hub__empty-title">No quizzes found.</div>'
					. '<div class="ika-quiz-hub__empty-sub">Try clearing your search or switching tracks.</div>'
					. '<a class="ika-quiz-hub__empty-clear" href="' . $clear_url . '">Clear search</a>'
					. '</div>';
		} else {
			foreach ( $page_ids as $pid ) {
				$eid = isset( $post_exam[ $pid ] ) ? intval( $post_exam[ $pid ] ) : 0;

				$title = get_the_title( $pid );
				$link  = get_permalink( $pid );

				$stat = ( $eid > 0 && isset( $stats[ $eid ] ) ) ? $stats[ $eid ] : array();
				$status_class = ika_quiz_hub_status_class( $stat );
				$status_text  = ika_quiz_hub_status_text( $status_class );

				// Group + Difficulty pills
				$group_terms = get_the_terms( $pid, 'ika_quiz_group' );
				$group_name  = ( ! is_wp_error( $group_terms ) && ! empty( $group_terms ) ) ? $group_terms[0]->name : '';
				$diff_terms  = get_the_terms( $pid, 'ika_quiz_difficulty' );
				$diff_name   = ( ! is_wp_error( $diff_terms ) && ! empty( $diff_terms ) ) ? $diff_terms[0]->name : '';

				// Meta line
				$q_total = ( $eid > 0 && isset( $q_counts[ $eid ] ) ) ? intval( $q_counts[ $eid ] ) : 0;
				if ( $q_total < 1 ) $q_total = 8; // stable fallback
				$mins = ika_qh_estimate_minutes( $exam_id ?? 0, (int) $q_total );
				$meta = '~' . ika_qh_format_minutes( (float) $mins ) . ' min · ' . $q_total . ' questions';

				$out .= '<a class="ika-quiz-card ' . esc_attr( $status_class ) . '" href="' . esc_url( $link ) . '">';
				$out .= '<div class="ika-quiz-card__body">';

				// Chips row (Group/Difficulty) should only render if at least one chip exists.
				// This avoids an empty "blank line" when a quiz has no chips.
				if ( $group_name || $diff_name ) {
					$out .= '<div class="ika-quiz-card__pills">';
					if ( $group_name ) {
						$out .= '<span class="ika-pill ika-pill--group">' . esc_html( $group_name ) . '</span>';
					}
					if ( $diff_name ) {
						$out .= '<span class="ika-pill ika-pill--difficulty">' . esc_html( $diff_name ) . '</span>';
					}
					$out .= '</div>';
				}
				$out .= '<div class="ika-quiz-card__title">' . esc_html( $title ) . '</div>';
				$out .= '<div class="ika-quiz-card__meta">' . esc_html( $meta ) . '</div>';

				// Score line (logged-in only): Best if complete, Last otherwise
				if ( $user_id > 0 && $eid > 0 && ! empty( $stat['attempts'] ) ) {
					$best_pct = isset( $stat['best'] ) ? floatval( $stat['best'] ) : 0.0;
					$last_pct = isset( $stat['last'] ) ? floatval( $stat['last'] ) : $best_pct;
					$is_complete = ( $best_pct >= 70.0 );
					$label = $is_complete ? 'Best' : 'Last';
					$show_pct = $is_complete ? $best_pct : $last_pct;
					$grade = ika_quiz_hub_grade_from_percent( $show_pct );
					$out .= '<div class="ika-quiz-card__score">' . esc_html( $label . ': ' . (int) round( $show_pct ) . '% (' . $grade . ')' ) . '</div>';
				}

				$out .= '<div class="ika-quiz-card__cta">Start Quiz</div>';
				$out .= '</div>';
				$out .= '</a>';
			}
		}

		$out .= '</div>'; // grid

		// Pagination
		if ( $total_pages > 1 ) {
			$out .= '<div class="ika-quiz-hub__pagination">';
			$out .= paginate_links( array(
				'base'      => esc_url_raw( add_query_arg( 'paged', '%#%', $build_url( array() ) ) ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $total_pages,
				'prev_text' => '‹ Prev',
				'next_text' => 'Next ›',
				'type'      => 'list',
			) );
			$out .= '</div>';
		}

		$out .= '</div>'; // hub

		return $out;
	}
	add_shortcode( 'ika_quiz_hub', 'ika_sc_quiz_hub' );
}
