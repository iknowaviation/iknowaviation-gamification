<?php
/**
 * Recommendation Engine v7 (Unified: Quizzes + Briefings + Courses)
 *
 * - Uses shared taxonomies across post types: quiz, briefingroom, academy
 *   (ika_quiz_group, ika_quiz_track, ika_quiz_difficulty, ika_quiz_audience, ika_quiz_topic)
 * - Results-page aware (grade-aware): can prioritize retakes when score < 70%
 *
 * This engine is intentionally conservative:
 * - Uses WP_Query + light scoring (no heavy joins)
 * - Falls back gracefully (never returns empty UI when avoidable)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class IKA_Recs_V7 {

	const TAX_GROUP      = 'ika_quiz_group';
	const TAX_TRACK      = 'ika_quiz_track';
	const TAX_LEVEL      = 'ika_quiz_difficulty';
	const TAX_AUDIENCE   = 'ika_quiz_audience';
	const TAX_TOPIC      = 'ika_quiz_topic';

	const META_EXAM_ID   = '_ika_exam_id';


	/** @var array<string> */
	private static $debug_log = [];

	private static function debug_enabled() : bool {
		// Admin-only debug, opt-in via constant or URL param.
		if ( ! is_user_logged_in() ) return false;
		if ( ! current_user_can( 'manage_options' ) ) return false;

		if ( defined( 'IKA_RECS_DEBUG' ) && IKA_RECS_DEBUG ) return true;
		if ( isset( $_GET['ika_recs_debug'] ) && $_GET['ika_recs_debug'] ) return true;

		return false;
	}

	private static function debug_reset() : void {
		self::$debug_log = [];
	}

	private static function debug_add( string $msg ) : void {
		if ( ! self::debug_enabled() ) return;
		self::$debug_log[] = trim( $msg );
	}

	public static function get_debug_log() : array {
		return self::$debug_log;
	}

	public static function get_debug_comment() : string {
		if ( ! self::debug_enabled() ) return '';
		if ( empty( self::$debug_log ) ) return "<!-- IKA_RECS_DEBUG: (no debug data) -->";
		$lines = array_map( function( $l ) {
			$l = preg_replace( '/\s+/', ' ', (string) $l );
			return $l;
		}, self::$debug_log );

		return "<!-- IKA_RECS_DEBUG\n" . implode( "\n", $lines ) . "\n-->";
	}

	/**
	 * Public: Unified getter.
	 *
	 * Args:
	 * - context: 'flightdeck' | 'results' | 'post'
	 * - types: array of 'quiz' | 'briefing' | 'course'
	 * - limit: int items total (across types)
	 * - post_id: optional context post (defaults to global $post)
	 */
	public static function get( array $args = [] ) : array {
		self::debug_reset();

		$defaults = [
			'context' => 'flightdeck',
			'types'   => [ 'quiz', 'briefing', 'course' ],
			'limit'   => 3,
			'post_id' => 0,
			'user_id' => get_current_user_id(),
		];
		$args = array_merge( $defaults, $args );

		$args['limit']  = max( 1, min( 12, intval( $args['limit'] ) ) );
		$args['post_id'] = intval( $args['post_id'] );
		$args['user_id'] = intval( $args['user_id'] );

		if ( empty( $args['post_id'] ) ) {
			$p = get_post();
			if ( $p instanceof WP_Post ) $args['post_id'] = intval( $p->ID );
		}

		$types = array_values( array_unique( array_map( 'strval', (array) $args['types'] ) ) );
		$types = array_values( array_intersect( $types, [ 'quiz', 'briefing', 'course' ] ) );
		if ( empty( $types ) ) $types = [ 'quiz', 'briefing', 'course' ];

		$context = in_array( $args['context'], [ 'flightdeck', 'results', 'post' ], true ) ? $args['context'] : 'flightdeck';

		// Build reference signals.
		$signals = self::get_signals( $context, $args['post_id'], $args['user_id'] );
		self::debug_add( 'context=' . $context . ' post_id=' . intval( $args['post_id'] ) . ' user_id=' . intval( $args['user_id'] ) );
		self::debug_add( 'signals: groups=' . count( (array) $signals['groups'] ) . ' tracks=' . count( (array) $signals['tracks'] ) . ' levels=' . count( (array) $signals['levels'] ) . ' topics=' . count( (array) $signals['topics'] ) . ' audience=' . count( (array) $signals['audiences'] ) );
		if ( isset( $signals['percent'] ) && is_numeric( $signals['percent'] ) ) {
			self::debug_add( 'latest_percent=' . self::format_percent( floatval( $signals['percent'] ) ) . ' grade=' . (string) ( $signals['grade'] ?? '' ) );
		}

		// Grade gating (>=70 unlocks courses). Applies when we actually have a score signal.
		$can_show_courses = true;
		if ( isset( $signals['percent'] ) && is_numeric( $signals['percent'] ) ) {
			$can_show_courses = floatval( $signals['percent'] ) >= 70.0;
		}

		$items = [];

		// Results context can inject a "retake" recommendation for the current quiz.
		if ( $context === 'results' && in_array( 'quiz', $types, true ) ) {
			$retake = self::maybe_build_retake_item( $args['post_id'], $args['user_id'], $signals );
			if ( $retake ) {
				$items[] = self::format_item( $retake );
				self::debug_add( 'added retake item (score < 70)' );
			}
		}

		// Collect per-type candidates.
		$per_type_limit = max( 2, $args['limit'] * 6 );
		$by_type = [ 'quiz' => [], 'briefing' => [], 'course' => [] ];

		foreach ( $types as $t ) {
			if ( $t === 'course' && ! $can_show_courses ) {
				self::debug_add( 'courses gated off (score < 70)' );
				continue;
			}
			$cands = self::get_candidates_for_type( $t, $signals, $args['user_id'], $args['post_id'], $per_type_limit );
			// Rotate candidates on Flight Deck to reduce repeat recommendations (stable per user per day).
			if ( $context === 'flightdeck' && ! empty( $cands ) ) {
				$cands = self::stable_shuffle_candidates( $cands, $args['user_id'], $t );
			}
			$by_type[ $t ] = $cands;
			self::debug_add( 'candidates[' . $t . ']=' . count( $cands ) );
		}

		// De-dupe across types by post_id, preserving per-type order.
		$seen = [];
		foreach ( $by_type as $t => $list ) {
			$out = [];
			foreach ( $list as $c ) {
				$pid = intval( $c['post_id'] ?? 0 );
				if ( ! $pid ) continue;
				if ( isset( $seen[ $pid ] ) ) continue;
				$seen[ $pid ] = true;
				$out[] = $c;
			}
			$by_type[ $t ] = $out;
		}

		// Priority: Quiz > Briefing > Course (fill one of each first).
		$priority = [ 'quiz', 'briefing', 'course' ];
		foreach ( $priority as $t ) {
			if ( ! in_array( $t, $types, true ) ) continue;
			if ( $t === 'course' && ! $can_show_courses ) continue;
			if ( count( $items ) >= $args['limit'] ) break;
			if ( ! empty( $by_type[ $t ] ) ) {
				$items[] = self::format_item( array_shift( $by_type[ $t ] ) );
			}
		}

		// Fill remaining slots (still honoring priority).
		foreach ( $priority as $t ) {
			if ( ! in_array( $t, $types, true ) ) continue;
			if ( $t === 'course' && ! $can_show_courses ) continue;
			while ( count( $items ) < $args['limit'] && ! empty( $by_type[ $t ] ) ) {
				$items[] = self::format_item( array_shift( $by_type[ $t ] ) );
			}
		}

		// Last-resort fallback.
		if ( empty( $items ) ) {
			self::debug_add( 'no items after selection; using fallback' );
			$items = self::fallback_items( $types );
		}

		return $items;
	}

	/**
	 * Results-only helper for [ika_results_recs].
	 * Attempts to provide a balanced set:
	 * - If score < 70: Retake quiz + 1 briefing + 1 course
	 * - Else: Next quiz + 1 briefing + 1 course (if available)
	 */
	public static function get_results_bundle( int $quiz_post_id, int $user_id, int $limit = 3 ) : array {
		$limit = max( 1, min( 12, intval( $limit ) ) );
		$user_id = intval( $user_id );
		$quiz_post_id = intval( $quiz_post_id );

		$signals = self::get_signals( 'results', $quiz_post_id, $user_id );
		$items = [];

		$can_show_courses = true;
		if ( isset( $signals['percent'] ) && is_numeric( $signals['percent'] ) ) {
			$can_show_courses = floatval( $signals['percent'] ) >= 70.0;
		}

		$retake = self::maybe_build_retake_item( $quiz_post_id, $user_id, $signals );
		if ( $retake ) $items[] = self::format_item( $retake );

		if ( ! $retake ) {
			$quiz = self::get_candidates_for_type( 'quiz', $signals, $user_id, $quiz_post_id, 8 );
			if ( ! empty( $quiz ) ) $items[] = self::format_item( $quiz[0] );
		}

		$brief = self::get_candidates_for_type( 'briefing', $signals, $user_id, $quiz_post_id, 8 );
		if ( ! empty( $brief ) ) $items[] = self::format_item( $brief[0] );

		if ( $can_show_courses ) {
			$course = self::get_candidates_for_type( 'course', $signals, $user_id, $quiz_post_id, 8 );
			if ( ! empty( $course ) ) $items[] = self::format_item( $course[0] );
		}

		return array_slice( $items, 0, $limit );
	}

	/* --------------------------------------------------------------------- */
	/* Signals */
	/* --------------------------------------------------------------------- */

	private static function get_signals( string $context, int $post_id, int $user_id ) : array {
		$signals = [
			'groups'    => [],
			'tracks'    => [],
			'levels'    => [],
			'audiences' => [],
			'topics'    => [],
			'percent'   => null,
			'grade'     => null,
			'exam_id'   => 0,
		];

		// Primary: from the current post if it has our taxonomies.
		if ( $post_id > 0 ) {
			$signals = array_merge( $signals, self::signals_from_post( $post_id ) );
		}

		// Flight Deck: if no group signal, try from last attempt quiz (and capture latest score for gating).
		if ( $context === 'flightdeck' && $user_id > 0 ) {
			$last_exam = function_exists( 'ika_fd_get_last_attempt_exam_id' ) ? intval( ika_fd_get_last_attempt_exam_id( $user_id ) ) : 0;
			if ( $last_exam > 0 ) {
				$last_post = function_exists( 'ika_fd_get_quiz_post_id_by_exam_id' ) ? intval( ika_fd_get_quiz_post_id_by_exam_id( $last_exam ) ) : 0;
				if ( $last_post > 0 ) {
					$signals['last_quiz_post_id'] = $last_post;
					if ( empty( $signals['groups'] ) ) {
						$signals = self::merge_signals( $signals, self::signals_from_post( $last_post ) );
					}
					$exam_id = self::resolve_exam_id_for_quiz_post( $last_post );
					if ( $exam_id > 0 ) {
						$signals['exam_id'] = $exam_id;
						$percent = self::get_latest_percent_for_user_exam( $user_id, $exam_id );
						if ( $percent !== null ) {
							$signals['percent'] = $percent;
							$signals['grade']   = self::grade_from_percent( $percent );
						}
					}
				}
			}
		}

		// Results: attempt to load percent + grade from latest attempt for this exam.
		if ( $context === 'results' && $user_id > 0 ) {
			$exam_id = self::resolve_exam_id_for_quiz_post( $post_id );
			if ( $exam_id > 0 ) {
				$signals['exam_id'] = $exam_id;
				$percent = self::get_latest_percent_for_user_exam( $user_id, $exam_id );
				if ( $percent !== null ) {
					$signals['percent'] = $percent;
					$signals['grade']   = self::grade_from_percent( $percent );
				}
			}
		}

		return $signals;
	}

	private static function merge_signals( array $a, array $b ) : array {
		foreach ( [ 'groups','tracks','levels','audiences','topics' ] as $k ) {
			$a[$k] = array_values( array_unique( array_merge( (array) ($a[$k] ?? []), (array) ($b[$k] ?? []) ) ) );
		}
		return $a;
	}

	private static function signals_from_post( int $post_id ) : array {
		$post_id = intval( $post_id );
		$out = [
			'groups'    => self::get_term_ids( $post_id, self::TAX_GROUP ),
			'tracks'    => self::get_term_ids( $post_id, self::TAX_TRACK ),
			'levels'    => self::get_term_ids( $post_id, self::TAX_LEVEL ),
			'audiences' => self::get_term_ids( $post_id, self::TAX_AUDIENCE ),
			'topics'    => self::get_term_ids( $post_id, self::TAX_TOPIC ),
		];
		return $out;
	}

	private static function get_term_ids( int $post_id, string $tax ) : array {
		$terms = wp_get_post_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) return [];
		return array_values( array_filter( array_map( 'intval', (array) $terms ) ) );
	}

	/* --------------------------------------------------------------------- */
	/* Candidate selection + scoring */
	/* --------------------------------------------------------------------- */

	private static function get_candidates_for_type( string $type, array $signals, int $user_id, int $context_post_id, int $limit ) : array {
		$post_type = self::map_type_to_post_type( $type );
		if ( ! $post_type ) return [];

		$exclude_ids = [ intval( $context_post_id ) ];

		// Option A3: avoid repeating the most recently attempted quiz on Flight Deck.
		if ( $type === 'quiz' && ! empty( $signals['last_quiz_post_id'] ) ) {
			$exclude_ids[] = intval( $signals['last_quiz_post_id'] );
		}


		// For quizzes: exclude completed (>=70) so we don't recommend already "passed" quizzes.
		if ( $type === 'quiz' && $user_id > 0 && function_exists( 'ika_fd_get_completed_exam_ids' ) && function_exists( 'ika_fd_get_quiz_post_id_by_exam_id' ) ) {
			$completed_exam_ids = (array) ika_fd_get_completed_exam_ids( $user_id );
			foreach ( $completed_exam_ids as $eid ) {
				$pid = intval( ika_fd_get_quiz_post_id_by_exam_id( intval( $eid ) ) );
				if ( $pid > 0 ) $exclude_ids[] = $pid;
			}
		}

		$q = [];

		if ( $type === 'briefing' || $type === 'course' ) {
			$tier_label = '';
			$q = self::query_by_signals_tiered( $post_type, $signals, $exclude_ids, $limit, $type, $tier_label );
		} else {
			$q = self::query_by_signals_or( $post_type, $signals, $exclude_ids, $limit );
			if ( empty( $q ) ) {
				$q = self::fallback_quiz_query( $exclude_ids, $limit );
			}
		}

		$cands = [];
		foreach ( $q as $pid ) {
			$score = self::score_post( $pid, $signals );

			if ( $type === 'quiz' && isset( $signals['percent'] ) && is_numeric( $signals['percent'] ) ) {
				$p = floatval( $signals['percent'] );
				if ( $p >= 90 ) $score += 12;
				else if ( $p >= 70 ) $score += 6;
			}

			$why = self::build_why_line( $pid, $signals, $type, ( isset( $tier_label ) ? $tier_label : '' ) );

			$cands[] = [
				'type'    => $type,
				'post_id' => intval( $pid ),
				'score'   => intval( $score ),
				'_tier'   => ( isset( $tier_label ) ? (string) $tier_label : '' ),
				'_why'    => (string) $why,
			];
		}

		usort( $cands, function( $a, $b ) {
			return intval( $b['score'] ?? 0 ) <=> intval( $a['score'] ?? 0 );
		} );

		return $cands;
	}

	private static function query_by_signals_or( string $post_type, array $signals, array $exclude_ids, int $limit ) : array {
		$tax_query = self::build_tax_query_or( $signals );
		if ( empty( $tax_query ) ) return [];

		$args = [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 50, intval( $limit ) ) ),
			'post__not_in'   => array_values( array_unique( array_map( 'intval', $exclude_ids ) ) ),
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'tax_query'      => $tax_query,
		];

		// Quizzes should use menu_order as a progression-friendly tie-breaker.
		if ( $post_type === 'quiz' ) {
			$args['orderby'] = [ 'menu_order' => 'ASC', 'date' => 'DESC' ];
		}

		$q = new WP_Query( $args );
		if ( empty( $q->posts ) ) return [];
		return array_values( array_map( 'intval', $q->posts ) );
	}

	/**
	 * Tiered matching (briefings/courses): start strict, relax until we find something.
	 */
	private static function query_by_signals_tiered( string $post_type, array $signals, array $exclude_ids, int $limit, string $type_label = '', string &$matched_tier = '' ) : array {
		$limit = max( 1, min( 50, intval( $limit ) ) );

		$groups = array_values( array_filter( array_map( 'intval', (array) ( $signals['groups'] ?? [] ) ) ) );
		$tracks = array_values( array_filter( array_map( 'intval', (array) ( $signals['tracks'] ?? [] ) ) ) );
		$levels = array_values( array_filter( array_map( 'intval', (array) ( $signals['levels'] ?? [] ) ) ) );
		$topics = array_values( array_filter( array_map( 'intval', (array) ( $signals['topics'] ?? [] ) ) ) );

		$tiers = [];

		if ( $groups && $levels && $topics ) $tiers[] = [ 'label' => 'group+level+topic', 'map' => [ self::TAX_GROUP => $groups, self::TAX_LEVEL => $levels, self::TAX_TOPIC => $topics ] ];
		if ( $groups && $levels )          $tiers[] = [ 'label' => 'group+level',      'map' => [ self::TAX_GROUP => $groups, self::TAX_LEVEL => $levels ] ];
		if ( $groups && $topics )          $tiers[] = [ 'label' => 'group+topic',      'map' => [ self::TAX_GROUP => $groups, self::TAX_TOPIC => $topics ] ];
		if ( $groups && $tracks )          $tiers[] = [ 'label' => 'group+track',      'map' => [ self::TAX_GROUP => $groups, self::TAX_TRACK => $tracks ] ];
		if ( $groups )                     $tiers[] = [ 'label' => 'group',           'map' => [ self::TAX_GROUP => $groups ] ];
		if ( $tracks )                     $tiers[] = [ 'label' => 'track',           'map' => [ self::TAX_TRACK => $tracks ] ];

		if ( empty( $tiers ) ) {
			self::debug_add( 'tiered[' . $type_label . ']: no tiers (no signals)' );
			$matched_tier = '';
			return [];
		}

		foreach ( $tiers as $t ) {
			$tax_query = self::build_tax_query_and_from_map( (array) $t['map'] );
			if ( empty( $tax_query ) ) continue;

			$args = [
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'post__not_in'   => array_values( array_unique( array_map( 'intval', $exclude_ids ) ) ),
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'tax_query'      => $tax_query,
			];

			$q = new WP_Query( $args );
			$ids = ! empty( $q->posts ) ? array_values( array_map( 'intval', (array) $q->posts ) ) : [];

			self::debug_add( 'tiered[' . $type_label . '] tier=' . (string) $t['label'] . ' found=' . count( $ids ) );

			if ( ! empty( $ids ) ) { $matched_tier = (string) $t['label']; return $ids; }
		}

		$matched_tier = '';
		return [];
	}

	private static function build_tax_query_and_from_map( array $map ) : array {
		$clauses = [];
		foreach ( $map as $tax => $ids ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
			if ( empty( $ids ) ) continue;
			$clauses[] = [
				'taxonomy' => (string) $tax,
				'field'    => 'term_id',
				'terms'    => $ids,
				'operator' => 'IN',
			];
		}
		if ( empty( $clauses ) ) return [];
		return array_merge( [ 'relation' => 'AND' ], $clauses );
	}


	private static function build_tax_query_or( array $signals ) : array {
		$clauses = [];

		$map = [
			self::TAX_GROUP    => (array) ( $signals['groups'] ?? [] ),
			self::TAX_TRACK    => (array) ( $signals['tracks'] ?? [] ),
			self::TAX_LEVEL    => (array) ( $signals['levels'] ?? [] ),
			self::TAX_AUDIENCE => (array) ( $signals['audiences'] ?? [] ),
			self::TAX_TOPIC    => (array) ( $signals['topics'] ?? [] ),
		];

		foreach ( $map as $tax => $ids ) {
			$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
			if ( empty( $ids ) ) continue;

			$clauses[] = [
				'taxonomy' => $tax,
				'field'    => 'term_id',
				'terms'    => $ids,
				'operator' => 'IN',
			];
		}

		if ( empty( $clauses ) ) return [];

		return array_merge( [ 'relation' => 'OR' ], $clauses );
	}

	private static function fallback_quiz_query( array $exclude_ids, int $limit ) : array {
		$args = [
			'post_type'      => 'quiz',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 50, intval( $limit ) ) ),
			'post__not_in'   => array_values( array_unique( array_map( 'intval', $exclude_ids ) ) ),
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'orderby'        => [ 'menu_order' => 'ASC', 'date' => 'DESC' ],
		];
		$q = new WP_Query( $args );
		if ( empty( $q->posts ) ) return [];
		return array_values( array_map( 'intval', $q->posts ) );
	}

	private static function score_post( int $post_id, array $signals ) : int {
		$post_id = intval( $post_id );

		$score = 0;

		// Base: small bump for newer content (keeps rails from going stale).
		$score += 3;

		$weights = [
			self::TAX_GROUP    => 50,
			self::TAX_TRACK    => 30,
			self::TAX_LEVEL    => 22,
			self::TAX_AUDIENCE => 16,
			self::TAX_TOPIC    => 10,
		];

		foreach ( $weights as $tax => $w ) {
			$match = self::count_term_intersections( $post_id, $tax, $signals );
			if ( $match <= 0 ) continue;

			// Topics can stack a bit.
			if ( $tax === self::TAX_TOPIC ) {
				$score += min( 3, $match ) * $w;
			} else {
				$score += $w;
			}
		}

		// For quizzes: slight bump if it's the next in menu_order progression.
		if ( get_post_type( $post_id ) === 'quiz' ) {
			$score += 5;
		}

		return intval( $score );
	}

	private static function count_term_intersections( int $post_id, string $tax, array $signals ) : int {
		$ref = [];
		switch ( $tax ) {
			case self::TAX_GROUP:    $ref = (array) ( $signals['groups'] ?? [] ); break;
			case self::TAX_TRACK:    $ref = (array) ( $signals['tracks'] ?? [] ); break;
			case self::TAX_LEVEL:    $ref = (array) ( $signals['levels'] ?? [] ); break;
			case self::TAX_AUDIENCE: $ref = (array) ( $signals['audiences'] ?? [] ); break;
			case self::TAX_TOPIC:    $ref = (array) ( $signals['topics'] ?? [] ); break;
		}
		$ref = array_values( array_filter( array_map( 'intval', $ref ) ) );
		if ( empty( $ref ) ) return 0;

		$terms = wp_get_post_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) return 0;
		$terms = array_values( array_filter( array_map( 'intval', (array) $terms ) ) );

		return count( array_intersect( $ref, $terms ) );
	}

	private static function map_type_to_post_type( string $type ) : string {
		if ( $type === 'quiz' ) return 'quiz';
		if ( $type === 'briefing' ) return 'briefingroom';
		if ( $type === 'course' ) return 'academy';
		return '';
	}

	/* --------------------------------------------------------------------- */
	/* Retake logic (results pages) */
	/* --------------------------------------------------------------------- */

	private static function maybe_build_retake_item( int $quiz_post_id, int $user_id, array $signals ) {
		$quiz_post_id = intval( $quiz_post_id );
		if ( $quiz_post_id <= 0 ) return null;
		if ( get_post_type( $quiz_post_id ) !== 'quiz' ) return null;

		$percent = $signals['percent'] ?? null;
		if ( ! is_numeric( $percent ) ) return null;

		$percent = floatval( $percent );
		if ( $percent >= 70 ) return null; // no retake needed

		$grade = $signals['grade'] ?: self::grade_from_percent( $percent );

		return [
			'type'    => 'quiz',
			'post_id' => $quiz_post_id,
			'score'   => 999, // always first
			'_override' => [
				'title'  => get_the_title( $quiz_post_id ),
				'meta'   => 'Last score: ' . self::format_percent( $percent ) . ' (' . $grade . ')',
				'cta'    => 'Retake quiz',
				'reason' => 'Quick win: a retake locks this concept in and pushes you over the pass line.',
			],
		];
	}

	/* --------------------------------------------------------------------- */
	/* Formatting */
	/* --------------------------------------------------------------------- */

	private static function format_item( array $c ) : array {
		$type = strval( $c['type'] ?? '' );
		$pid  = intval( $c['post_id'] ?? 0 );

		$chip = 'QUIZ';
		if ( $type === 'briefing' ) $chip = 'BRIEFING';
		if ( $type === 'course' ) $chip = 'COURSE';

		$title = get_the_title( $pid );
		$url   = get_permalink( $pid );

		$meta = '';
		$cta  = 'Open';

		if ( $type === 'quiz' ) {
			$cta = 'Start quiz';
			$meta = self::build_quiz_meta_line( $pid );
		} else if ( $type === 'briefing' ) {
			$cta = 'Open briefing';
			$meta = self::build_briefing_meta_line( $pid );
		} else if ( $type === 'course' ) {
			$cta = 'View course';
			$meta = self::build_course_meta_line( $pid );
		}

		// Override block (retake)
		$why = (string) ( $c['_why'] ?? '' );

		// Override block (retake)
		if ( ! empty( $c['_override'] ) && is_array( $c['_override'] ) ) {
			$ov = $c['_override'];
			$title = $ov['title'] ?? $title;
			$meta  = $ov['meta'] ?? $meta;
			$cta   = $ov['cta'] ?? $cta;
			$why   = $ov['reason'] ?? $why;
		}

		return [
			'type'  => $type,
			'chip'  => $chip,
			'title' => (string) $title,
			'url'   => (string) $url,
			'meta'  => (string) $meta,
			'cta'   => (string) $cta,
			'why'   => (string) $why,
		];
	}

	private static function build_quiz_meta_line( int $post_id ) : string {
		// Prefer group + level when present.
		$parts = [];

		$g = wp_get_post_terms( $post_id, self::TAX_GROUP, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $g ) && ! empty( $g ) ) $parts[] = (string) $g[0];

		$l = wp_get_post_terms( $post_id, self::TAX_LEVEL, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $l ) && ! empty( $l ) ) $parts[] = (string) $l[0];

		if ( empty( $parts ) ) return 'Quiz';

		return implode( ' • ', array_slice( $parts, 0, 2 ) );
	}

	private static function build_briefing_meta_line( int $post_id ) : string {
		// Prefer format + topic if available; otherwise group.
		$parts = [];

		$fmt = get_the_terms( $post_id, 'formats' );
		if ( is_array( $fmt ) && ! empty( $fmt ) && $fmt[0] instanceof WP_Term ) $parts[] = $fmt[0]->name;

		$t = wp_get_post_terms( $post_id, self::TAX_TOPIC, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $t ) && ! empty( $t ) ) $parts[] = (string) $t[0];

		if ( empty( $parts ) ) {
			$g = wp_get_post_terms( $post_id, self::TAX_GROUP, [ 'fields' => 'names' ] );
			if ( ! is_wp_error( $g ) && ! empty( $g ) ) $parts[] = (string) $g[0];
		}

		return empty( $parts ) ? 'Briefing' : implode( ' • ', array_slice( $parts, 0, 2 ) );
	}

	private static function build_course_meta_line( int $post_id ) : string {
		$parts = [];

		$track = wp_get_post_terms( $post_id, self::TAX_TRACK, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $track ) && ! empty( $track ) ) $parts[] = (string) $track[0];

		$level = wp_get_post_terms( $post_id, self::TAX_LEVEL, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $level ) && ! empty( $level ) ) $parts[] = (string) $level[0];

		return empty( $parts ) ? 'Course' : implode( ' • ', array_slice( $parts, 0, 2 ) );
	}

	

	private static function build_why_line( int $post_id, array $signals, string $type, string $tier_label = '' ) : string {
		$post_id = intval( $post_id );

		// Tier label (briefing/course) comes from strict→relaxed matching.
		if ( ( $type === 'briefing' || $type === 'course' ) && $tier_label ) {
			$map = [
				'group+level+topic' => 'Matched your group, level, and topic',
				'group+level'       => 'Matched your group and level',
				'group+topic'       => 'Matched your group and topic',
				'group+track'       => 'Matched your group and track',
				'group'             => 'Matched your group',
				'track'             => 'Matched your track',
			];
			return $map[ $tier_label ] ?? ( 'Matched: ' . $tier_label );
		}

		// Quiz: keep it simple and human.
		if ( $type === 'quiz' ) {
			$parts = [];

			$g = wp_get_post_terms( $post_id, self::TAX_GROUP, [ 'fields' => 'names' ] );
			if ( ! is_wp_error( $g ) && ! empty( $g ) ) $parts[] = (string) $g[0];

			$l = wp_get_post_terms( $post_id, self::TAX_LEVEL, [ 'fields' => 'names' ] );
			if ( ! is_wp_error( $l ) && ! empty( $l ) ) $parts[] = (string) $l[0];

			if ( ! empty( $parts ) ) {
				return 'Fits your path: ' . implode( ' • ', array_slice( $parts, 0, 2 ) );
			}

			// If the quiz itself has no taxonomy terms (or they were not assigned),
			// still provide a helpful "why" based on the user's current signals.
			$why_parts = [];
			if ( ! empty( $signals['groups'] ) ) {
				$tid = intval( is_array( $signals['groups'] ) ? ( $signals['groups'][0] ?? 0 ) : 0 );
				if ( $tid ) {
					$term = get_term( $tid );
					if ( $term && ! is_wp_error( $term ) ) $why_parts[] = $term->name;
				}
			}
			if ( ! empty( $signals['levels'] ) ) {
				$tid = intval( is_array( $signals['levels'] ) ? ( $signals['levels'][0] ?? 0 ) : 0 );
				if ( $tid ) {
					$term = get_term( $tid );
					if ( $term && ! is_wp_error( $term ) ) $why_parts[] = $term->name;
				}
			}
			if ( ! empty( $why_parts ) ) {
				return 'Based on your progress: ' . implode( ' • ', array_slice( $why_parts, 0, 2 ) );
			}

			return 'Based on your recent activity';
		}

		// Briefing/Course: if we don't have a tier label (rare), still provide a lightweight reason.
		if ( $type === 'briefing' || $type === 'course' ) {
			if ( ! empty( $signals['tracks'] ) ) return 'Matched your track';
			if ( ! empty( $signals['groups'] ) )  return 'Matched your group';
		}

		return '';
	}
private static function fallback_items( array $types ) : array {
		$items = [];
		foreach ( $types as $t ) {
			if ( $t === 'quiz' ) {
				$items[] = [
					'type'  => 'quiz',
					'chip'  => 'QUIZ',
					'title' => 'Explore the Quiz Hub',
					'url'   => home_url( '/quizzes/' ),
					'meta'  => 'Browse quizzes by group and difficulty.',
					'cta'   => 'Explore',
				];
			} else if ( $t === 'briefing' ) {
				$items[] = [
					'type'  => 'briefing',
					'chip'  => 'BRIEFING',
					'title' => 'Browse Briefing Room',
					'url'   => home_url( '/briefingroom/' ),
					'meta'  => 'Short reads, infographics, and quick references.',
					'cta'   => 'Browse',
				];
			} else if ( $t === 'course' ) {
				$items[] = [
					'type'  => 'course',
					'chip'  => 'COURSE',
					'title' => 'Browse Academy Courses',
					'url'   => home_url( '/academy/' ),
					'meta'  => 'Structured learning paths and courses.',
					'cta'   => 'Browse',
				];
			}
		}
		return array_slice( $items, 0, 3 );
	}

	/* --------------------------------------------------------------------- */
	/* WatuPRO attempt helpers */
	/* --------------------------------------------------------------------- */

	private static function resolve_exam_id_for_quiz_post( int $quiz_post_id ) : int {
		$quiz_post_id = intval( $quiz_post_id );
		if ( $quiz_post_id <= 0 ) return 0;

		// Use saved meta first.
		$meta = get_post_meta( $quiz_post_id, self::META_EXAM_ID, true );
		if ( $meta !== '' ) {
			$eid = intval( $meta );
			if ( $eid > 0 ) return $eid;
		}

		// Fall back to parsing post_content.
		$p = get_post( $quiz_post_id );
		if ( ! ( $p instanceof WP_Post ) ) return 0;

		if ( preg_match( '/\[watupro\s+(\d+)\]/i', (string) $p->post_content, $m ) ) {
			return intval( $m[1] );
		}

		return 0;
	}

	private static function get_latest_percent_for_user_exam( int $user_id, int $exam_id ) {
		global $wpdb;

		$user_id = intval( $user_id );
		$exam_id = intval( $exam_id );
		if ( $user_id <= 0 || $exam_id <= 0 ) return null;

		$tbl = $wpdb->prefix . 'watupro_taken_exams';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT percent_correct
				 FROM {$tbl}
				 WHERE user_id = %d
				   AND exam_id = %d
				   AND (in_progress IS NULL OR in_progress = 0)
				   AND (ignore_attempt IS NULL OR ignore_attempt = 0)
				 ORDER BY COALESCE(end_time,'') DESC, ID DESC
				 LIMIT 1",
				$user_id,
				$exam_id
			)
		);

		if ( ! $row || ! isset( $row->percent_correct ) ) return null;

		$pc = floatval( $row->percent_correct );
		if ( $pc < 0 ) $pc = 0;
		if ( $pc > 100 ) $pc = 100;

		return $pc;
	}

	/* --------------------------------------------------------------------- */
	/* Grades (per IKA scale) */
	/* --------------------------------------------------------------------- */

	private static function grade_from_percent( float $p ) : string {
		$p = floatval( $p );

		if ( $p >= 100 ) return 'A+';
		if ( $p >= 91 ) return 'A';
		if ( $p >= 80 ) return 'B'; // (90 is treated as B per your saved scale)
		if ( $p >= 70 ) return 'C';
		if ( $p >= 60 ) return 'D';
		return 'F';
	}

	private static function format_percent( float $p ) : string {
		$p = max( 0, min( 100, floatval( $p ) ) );
		// Display as whole number when possible.
		if ( abs( $p - round($p) ) < 0.0001 ) return intval( round($p) ) . '%';
		return number_format_i18n( $p, 1 ) . '%';
	}

	/**
	 * Stable per-user, per-day shuffle to reduce repeated recommendations.
	 * We sort by a deterministic hash (seed + type + post_id) so ordering rotates daily but is consistent per user.
	 */
	private static function stable_shuffle_candidates( array $cands, int $user_id, string $type ) : array {
		$day = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$seed = $day . '|' . $user_id . '|' . $type . '|ika-recs-v7';
		usort( $cands, function( $a, $b ) use ( $seed ) {
			$pa = intval( $a['post_id'] ?? 0 );
			$pb = intval( $b['post_id'] ?? 0 );
			$ha = md5( $seed . '|' . $pa );
			$hb = md5( $seed . '|' . $pb );
			if ( $ha === $hb ) return 0;
			return ( $ha < $hb ) ? -1 : 1;
		} );
		return $cands;
	}

}
