<?php
/**
 * Flight Deck — Recommended Next Engine (v6, Track-ready)
 *
 * Goals:
 * - Deterministic "one confident next action"
 * - Uses Groups taxonomy + canonical group progression order (configurable)
 * - Optional Track filter (Intro to Aviation / Intro to Dispatch / Standalone) (configurable)
 * - Retake most recent fail still wins
 * - Skips empty groups automatically
 * - Never disappears: shows "caught up" state when nothing is eligible
 *
 * Shortcode:
 *  [ika_recommended_next]
 *
 * Settings (Options):
 *  - ika_recnext_group_order (string; one group slug per line)
 *  - ika_recnext_track_term  (string; optional track term slug; blank = no filter)
 *
 * Filters:
 *  - ika_recnext_groups_taxonomy (string taxonomy slug)
 *  - ika_recnext_tracks_taxonomy (string taxonomy slug)
 *  - ika_recnext_group_order (array slugs)
 *  - ika_recnext_track_term (string slug)
 *  - ika_recnext_excluded_title_keywords (array)
 *  - ika_recnext_should_exclude_post (bool, $post_id)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class IKA_FlightDeck_Recommended_Next {

	const CACHE_TTL        = 180;
	const THRESH_CACHE_TTL = 600;

	const META_EXAM_ID     = '_ika_exam_id';
	const META_HIDE_FD     = '_ika_hide_from_flightdeck';

	public static function init() : void {
		add_shortcode( 'ika_recommended_next', [ __CLASS__, 'shortcode' ] );
		add_action( 'save_post_quiz', [ __CLASS__, 'on_save_quiz_post' ], 20, 2 );
	}

	/* =========================================================
	 * Shortcode
	 * ======================================================= */

	public static function shortcode( $atts = [] ) : string {
		if ( ! is_user_logged_in() ) return '';

		$user_id = get_current_user_id();
		$rec     = self::get_recommendation( $user_id );

		// Never disappear: show caught-up state
		if ( ! $rec || empty( $rec['url'] ) ) {
			$hub_url = home_url( '/quizzes/' );
			return '<div class="ika-recnext ika-recnext--empty">
				<div class="ika-recnext__label">Recommended Next</div>
				<div class="ika-recnext__title">You&apos;re all caught up</div>
				<div class="ika-recnext__reason">Nice work — check back soon for new quizzes, or explore another topic from the Quiz Hub.</div>
				<a class="ika-recnext__cta" href="' . esc_url( $hub_url ) . '">Explore Quizzes</a>
			</div>';
		}

		$title  = esc_html( $rec['title'] ?? 'Recommended Next' );
		$reason = esc_html( $rec['reason'] ?? '' );
		$cta    = esc_html( $rec['cta'] ?? 'Start' );
		$url    = esc_url( $rec['url'] );

		ob_start(); ?>
		<div class="ika-recnext">
			<div class="ika-recnext__label">Recommended Next</div>
			<div class="ika-recnext__title"><?php echo $title; ?></div>
			<?php if ( $reason ) : ?>
				<div class="ika-recnext__reason"><?php echo $reason; ?></div>
			<?php endif; ?>
			<a class="ika-recnext__cta" href="<?php echo $url; ?>"><?php echo $cta; ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	/* =========================================================
	 * Recommendation
	 * ======================================================= */

	public static function get_recommendation( int $user_id ) : ?array {
		if ( $user_id <= 0 ) return null;

		$cache_key = 'ika_rec_next_' . $user_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['url'] ) ) return $cached;

		// 1) Retake most recent fail (global)
		$recent_fail = self::get_most_recent_attempt_if_failed( $user_id );
		if ( $recent_fail ) {
			set_transient( $cache_key, $recent_fail, self::CACHE_TTL );
			return $recent_fail;
		}

		// 2) Track-ready progression using Groups (and optional Track filter)
		$next = self::get_next_by_group_progression( $user_id );
		if ( $next ) {
			set_transient( $cache_key, $next, self::CACHE_TTL );
			return $next;
		}

		// 3) Nothing eligible -> shortcode will show caught-up state
		return null;
	}

	/* =========================================================
	 * Exam ID meta mapping
	 * ======================================================= */

	public static function on_save_quiz_post( int $post_id, \WP_Post $post ) : void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( $post->post_type !== 'quiz' ) return;

		$exam_id = self::parse_exam_id_from_content( (string) $post->post_content );
		if ( $exam_id > 0 ) {
			update_post_meta( $post_id, self::META_EXAM_ID, $exam_id );
		} else {
			delete_post_meta( $post_id, self::META_EXAM_ID );
		}
	}

	private static function parse_exam_id_from_content( string $content ) : int {
		if ( preg_match( '/\[watupro\s+(\d+)\]/i', $content, $m ) ) {
			return intval( $m[1] );
		}
		return 0;
	}

	/* =========================================================
	 * Rule #1: Most recent completed attempt fail -> retake
	 * ======================================================= */

	private static function get_most_recent_attempt_if_failed( int $user_id ) : ?array {
		global $wpdb;

		$taken  = self::t_taken();
		$master = self::t_master();

		$sql = "
			SELECT
				te.exam_id,
				te.percent_correct,
				te.points,
				te.max_points,
				te.in_progress,
				te.end_time,
				te.start_time,
				te.date,
				te.ID AS taken_id,
				m.name AS exam_name
			FROM {$taken} te
			LEFT JOIN {$master} m ON m.ID = te.exam_id
			WHERE te.user_id = %d
				AND te.in_progress = 0
			ORDER BY
				COALESCE(te.end_time, te.start_time) DESC,
				te.date DESC,
				te.ID DESC
			LIMIT 1
		";

		$r = $wpdb->get_row( $wpdb->prepare( $sql, $user_id ), ARRAY_A );
		if ( empty( $r ) ) return null;

		$exam_id = intval( $r['exam_id'] ?? 0 );
		if ( $exam_id <= 0 ) return null;

		$score_pct = self::score_pct_from_taken_row( $r );
		if ( $score_pct === null ) return null;

		$threshold = self::pass_threshold( $exam_id );
		if ( $score_pct >= $threshold ) return null;

		$post_id = self::find_quiz_post_id_for_exam( $exam_id );
		if ( $post_id <= 0 ) return null;

		// Don't recommend hidden/internal quizzes even for retake
		if ( self::should_exclude_post( $post_id ) ) return null;

		return [
			'type'    => 'retake_failed',
			'user_id' => $user_id,
			'post_id' => $post_id,
			'exam_id' => $exam_id,
			'url'     => get_permalink( $post_id ),
			'title'   => get_the_title( $post_id ) ?: ( $r['exam_name'] ?? 'Quiz' ),
			'cta'     => 'Retake Quiz',
			'reason'  => 'Quick win: retake your most recent quiz and lock it in.',
		];
	}

	/* =========================================================
	 * Rule #2: Group progression (track-ready)
	 * ======================================================= */

	private static function get_next_by_group_progression( int $user_id ) : ?array {
		$groups_tax = self::groups_taxonomy();
		if ( ! taxonomy_exists( $groups_tax ) ) return null;

		$group_order = self::group_order_slugs();
		if ( empty( $group_order ) ) return null;

		$track_term = self::track_term_slug(); // optional
		$start_group_slug = self::detect_user_current_group_slug( $user_id, $groups_tax, $group_order, $track_term );

		// Build a traversal list that starts at the detected group, then continues forward
		$traversal = self::rotate_order_from_slug( $group_order, $start_group_slug );

		foreach ( $traversal as $group_slug ) {
			$next_in_group = self::get_next_unpassed_quiz_in_group( $user_id, $groups_tax, $group_slug, $track_term );
			if ( $next_in_group ) return $next_in_group;
		}

		return null;
	}

	private static function detect_user_current_group_slug( int $user_id, string $groups_tax, array $group_order, string $track_term ) : string {
		// If we can map the user's most recent quiz attempt -> CPT -> group term, start there.
		$last_post_id = self::get_most_recent_quiz_post_id_attempted( $user_id );
		if ( $last_post_id > 0 ) {
			// If a track filter is active, ensure the last quiz is within that track; if not, ignore and start from first.
			if ( $track_term !== '' && ! self::post_has_term_slug( $last_post_id, self::tracks_taxonomy(), $track_term ) ) {
				return $group_order[0];
			}

			$terms = get_the_terms( $last_post_id, $groups_tax );
			if ( is_array( $terms ) ) {
				// Prefer a term that is in the canonical order list
				foreach ( $terms as $t ) {
					$slug = (string)($t->slug ?? '');
					if ( $slug && in_array( $slug, $group_order, true ) ) {
						return $slug;
					}
				}
			}
		}

		return $group_order[0];
	}

	private static function rotate_order_from_slug( array $order, string $start_slug ) : array {
		$idx = array_search( $start_slug, $order, true );
		if ( $idx === false ) return $order;
		return array_merge( array_slice( $order, $idx ), array_slice( $order, 0, $idx ) );
	}

	private static function get_next_unpassed_quiz_in_group( int $user_id, string $groups_tax, string $group_slug, string $track_term ) : ?array {
		$args = [
			'post_type'      => 'quiz',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [
				[
					'taxonomy' => $groups_tax,
					'field'    => 'slug',
					'terms'    => [ $group_slug ],
				],
			],
		];

		// Optional Track filter (Track taxonomy term slug)
		$tracks_tax = self::tracks_taxonomy();
		if ( $track_term !== '' && taxonomy_exists( $tracks_tax ) ) {
			$args['tax_query'][] = [
				'taxonomy' => $tracks_tax,
				'field'    => 'slug',
				'terms'    => [ $track_term ],
			];
		}

		$q = new WP_Query( $args );
		if ( empty( $q->posts ) ) return null;

		foreach ( $q->posts as $post_id ) {
			$post_id = intval( $post_id );
			if ( $post_id <= 0 ) continue;

			if ( self::should_exclude_post( $post_id ) ) continue;

			$exam_id = self::get_exam_id_from_quiz_post( $post_id );
			if ( $exam_id <= 0 ) continue;

			$status = self::get_user_exam_status( $user_id, $exam_id );
			if ( $status !== 'passed' ) {
				$group_name = self::term_name_from_slug( $groups_tax, $group_slug );
				$reason = $group_name ? ("Next in " . $group_name . ".") : "Next up in your current group.";

				return [
					'type'    => 'group_progression',
					'user_id' => $user_id,
					'post_id' => $post_id,
					'exam_id' => $exam_id,
					'url'     => get_permalink( $post_id ),
					'title'   => get_the_title( $post_id ) ?: 'Quiz',
					'cta'     => ( $status === 'failed' ? 'Try Again' : 'Start Quiz' ),
					'reason'  => $reason,
				];
			}
		}

		return null;
	}

	private static function term_name_from_slug( string $tax, string $slug ) : string {
		$term = get_term_by( 'slug', $slug, $tax );
		return ( $term && ! is_wp_error( $term ) ) ? (string)$term->name : '';
	}

	private static function get_most_recent_quiz_post_id_attempted( int $user_id ) : int {
		global $wpdb;
		$taken = self::t_taken();

		$sql = "
			SELECT exam_id, end_time, start_time, date, ID
			FROM {$taken}
			WHERE user_id = %d
				AND in_progress = 0
			ORDER BY
				COALESCE(end_time, start_time) DESC,
				date DESC,
				ID DESC
			LIMIT 1
		";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $user_id ), ARRAY_A );
		if ( empty( $row ) ) return 0;

		$exam_id = intval( $row['exam_id'] ?? 0 );
		if ( $exam_id <= 0 ) return 0;

		return self::find_quiz_post_id_for_exam( $exam_id );
	}

	private static function post_has_term_slug( int $post_id, string $tax, string $slug ) : bool {
		if ( $post_id <= 0 || $slug === '' || ! taxonomy_exists( $tax ) ) return false;
		$terms = get_the_terms( $post_id, $tax );
		if ( ! is_array( $terms ) ) return false;
		foreach ( $terms as $t ) {
			if ( (string)($t->slug ?? '') === $slug ) return true;
		}
		return false;
	}

	/* =========================================================
	 * Taxonomy + settings (configurable)
	 * ======================================================= */

	private static function groups_taxonomy() : string {
		$default = 'groups';
		$tax = apply_filters( 'ika_recnext_groups_taxonomy', $default );
		return is_string( $tax ) && $tax ? $tax : $default;
	}

	private static function tracks_taxonomy() : string {
		$default = 'tracks';
		$tax = apply_filters( 'ika_recnext_tracks_taxonomy', $default );
		return is_string( $tax ) && $tax ? $tax : $default;
	}

	private static function group_order_slugs() : array {
		$raw = get_option( 'ika_recnext_group_order', '' );
		$order = [];

		if ( is_string( $raw ) && trim( $raw ) !== '' ) {
			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			foreach ( $lines as $line ) {
				$slug = sanitize_title( trim( (string)$line ) );
				if ( $slug !== '' ) $order[] = $slug;
			}
		}

		// Default canonical Phase 1 order (your locked 8 groups)
		if ( empty( $order ) ) {
			$order = [
				'aviation-basics',
				'aircraft-systems',
				'airports-airport-operations',
				'weather-basics',
				'navigation-basics',
				'airspace-and-regulations',
				'flight-planning-and-performance',
				'atc-and-radio-basics',
			];
		}

		$order = apply_filters( 'ika_recnext_group_order', $order );

		// Keep only unique, non-empty strings
		$clean = [];
		foreach ( (array)$order as $slug ) {
			$slug = sanitize_title( (string)$slug );
			if ( $slug && ! in_array( $slug, $clean, true ) ) $clean[] = $slug;
		}
		return $clean;
	}

	private static function track_term_slug() : string {
		$raw = get_option( 'ika_recnext_track_term', '' );
		$slug = sanitize_title( (string)$raw );
		$slug = apply_filters( 'ika_recnext_track_term', $slug );
		return is_string( $slug ) ? $slug : '';
	}

	/* =========================================================
	 * Exclusion logic (manual + keyword safety)
	 * ======================================================= */

	private static function excluded_title_keywords() : array {
		$keywords = [
			'importer',
			'sandbox',
			'demo',
			'debug',
			'do not use',
			'staging',
			'dummy',
			'sample',
			'qa ',
			'[qa]',
		];

		$keywords = apply_filters( 'ika_recnext_excluded_title_keywords', $keywords );
		if ( ! is_array( $keywords ) ) $keywords = [];

		$keywords = array_map( function($s){
			return strtolower( trim( (string)$s ) );
		}, $keywords );

		return array_values( array_filter( $keywords ) );
	}

	private static function should_exclude_post( int $post_id ) : bool {
		if ( $post_id <= 0 ) return true;

		$hide = get_post_meta( $post_id, self::META_HIDE_FD, true );
		if ( (string)$hide === '1' ) {
			return (bool) apply_filters( 'ika_recnext_should_exclude_post', true, $post_id );
		}

		$title  = (string) get_the_title( $post_id );
		$ltitle = strtolower( $title );

		foreach ( self::excluded_title_keywords() as $kw ) {
			if ( $kw !== '' && strpos( $ltitle, $kw ) !== false ) {
				return (bool) apply_filters( 'ika_recnext_should_exclude_post', true, $post_id );
			}
		}

		return (bool) apply_filters( 'ika_recnext_should_exclude_post', false, $post_id );
	}

	/* =========================================================
	 * Helpers: tables + score + thresholds + mapping
	 * ======================================================= */

	private static function t_taken() : string {
		global $wpdb;
		return $wpdb->prefix . 'watupro_taken_exams';
	}

	private static function t_master() : string {
		global $wpdb;
		return $wpdb->prefix . 'watupro_master';
	}

	private static function t_grading() : string {
		global $wpdb;
		return $wpdb->prefix . 'watupro_grading';
	}

	private static function score_pct_from_taken_row( array $r ) : ?float {
		if ( isset( $r['percent_correct'] ) && $r['percent_correct'] !== '' && $r['percent_correct'] !== null ) {
			return floatval( $r['percent_correct'] );
		}
		$points     = isset( $r['points'] ) ? floatval( $r['points'] ) : null;
		$max_points = isset( $r['max_points'] ) ? floatval( $r['max_points'] ) : null;
		if ( $points !== null && $max_points !== null && $max_points > 0 ) {
			return ( $points / $max_points * 100.0 );
		}
		return null;
	}

	private static function pass_threshold( int $exam_id ) : float {
		$ck = 'ika_rec_thr_' . $exam_id;
		$cached = get_transient( $ck );
		if ( $cached !== false && is_numeric( $cached ) ) return floatval( $cached );

		$thr = self::compute_threshold_from_grading( $exam_id );
		if ( $thr <= 0 ) $thr = 71.0; // C starts at 71

		set_transient( $ck, $thr, self::THRESH_CACHE_TTL );
		return $thr;
	}

	private static function compute_threshold_from_grading( int $exam_id ) : float {
		global $wpdb;
		$grading = self::t_grading();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gtitle, gfrom, gto FROM {$grading} WHERE exam_id = %d ORDER BY gfrom ASC",
				$exam_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$rows = $wpdb->get_results(
				"SELECT gtitle, gfrom, gto FROM {$grading} WHERE exam_id = 0 ORDER BY gfrom ASC",
				ARRAY_A
			);
		}

		if ( empty( $rows ) ) return 0.0;

		foreach ( $rows as $r ) {
			$title = strtoupper( trim( (string)($r['gtitle'] ?? '') ) );
			if ( $title === 'C' ) return floatval( $r['gfrom'] ?? 0 );
		}

		return 0.0;
	}

	private static function get_exam_id_from_quiz_post( int $post_id ) : int {
		$meta = intval( get_post_meta( $post_id, self::META_EXAM_ID, true ) );
		if ( $meta > 0 ) return $meta;

		$content = get_post_field( 'post_content', $post_id );
		if ( ! $content ) return 0;

		return self::parse_exam_id_from_content( (string)$content );
	}

	private static function find_quiz_post_id_for_exam( int $exam_id ) : int {
		$q = new WP_Query([
			'post_type'      => 'quiz',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => self::META_EXAM_ID,
					'value'   => strval( intval( $exam_id ) ),
					'compare' => '=',
				]
			],
		]);

		if ( ! empty( $q->posts ) ) {
			foreach ( $q->posts as $pid ) {
				$pid = intval($pid);
				if ( $pid > 0 && ! self::should_exclude_post( $pid ) ) return $pid;
			}
		}

		$q2 = new WP_Query([
			'post_type'      => 'quiz',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			's'              => '[watupro ' . intval( $exam_id ) . ']',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		if ( ! empty( $q2->posts ) ) {
			foreach ( $q2->posts as $pid ) {
				$pid = intval($pid);
				if ( $pid > 0 && ! self::should_exclude_post( $pid ) ) return $pid;
			}
		}

		return 0;
	}

	private static function get_user_exam_status( int $user_id, int $exam_id ) : string {
		global $wpdb;
		$taken = self::t_taken();

		$sql = "
			SELECT percent_correct, points, max_points, end_time, start_time, date, ID
			FROM {$taken}
			WHERE user_id = %d
				AND exam_id = %d
				AND in_progress = 0
			ORDER BY
				COALESCE(end_time, start_time) DESC,
				date DESC,
				ID DESC
			LIMIT 1
		";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $user_id, $exam_id ), ARRAY_A );
		if ( empty( $row ) ) return 'never';

		$pct = self::score_pct_from_taken_row( $row );
		if ( $pct === null ) return 'never';

		return ( $pct >= self::pass_threshold( $exam_id ) ) ? 'passed' : 'failed';
	}
}

IKA_FlightDeck_Recommended_Next::init();
