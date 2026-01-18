<?php
/**
 * Recommendations Shortcodes
 *
 * Flight Deck + Results page "Recommended Next" rail.
 *
 * Phase 1 (safe scaffold):
 * - No JS
 * - No heavy queries
 * - Conservative fallbacks (always returns useful links)
 *
 * Shortcode:
 *   [ika_recommendations_rail context="flightdeck" limit="3"]
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_shortcode( 'ika_recommendations_rail', function( $atts = [] ) {

    $atts = shortcode_atts( [
        'context' => 'flightdeck',
        'limit'   => 3,
        'show_header' => 1,
    ], $atts, 'ika_recommendations_rail' );

    $context = sanitize_key( $atts['context'] );
    $limit   = max( 1, min( 6, intval( $atts['limit'] ) ) );

    $tmp = isset( $atts['show_header'] ) ? filter_var( $atts['show_header'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;
    $show_header = ( $tmp === null ) ? true : (bool) $tmp;

    // Base URLs (stable, no assumptions about future routing)
    $quiz_hub_url = home_url( '/quizzes/' );

    // If not logged in, keep it helpful but simple.
    if ( ! is_user_logged_in() ) {
        $login_url = wp_login_url( $quiz_hub_url );
        ob_start(); ?>
        <div class="ika-rec-rail ika-rec-rail--loggedout" data-context="<?php echo esc_attr( $context ); ?>">
            <div class="ika-rec-rail__head">
                <?php if ( ! empty( $show_header ) ) : ?>
                    <h2 class="ika-hub-section-title">Recommended Next</h2>
                <?php endif; ?>
            </div>
            <div class="ika-rec-rail__grid">
                <a class="ika-rec-card" href="<?php echo esc_url( $login_url ); ?>">
                    <div class="ika-rec-card__title">Log in to unlock recommendations</div>
                    <div class="ika-rec-card__meta">We’ll suggest the best next quiz for your level.</div>
                    <div class="ika-rec-card__cta">Log in</div>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Phase 1 picks (safe fallbacks):
     * - These will be replaced by the unified recommendation engine scoring later.
     * - Keep UX restrained: max 3 items by default.
     */
    // Logged-in recommendations (Phase 1 wiring):
    // - Exclude completed quizzes (best attempt >= 70%)
    // - Prefer the same quiz group as the user's most recent attempt
    // - Fallback to the quiz hub if we can't resolve posts cleanly

    $user_id = get_current_user_id();

    $completed_exam_ids = function_exists( 'ika_fd_get_completed_exam_ids' )
        ? ika_fd_get_completed_exam_ids( $user_id, 70 )
        : [];

    $last_exam_id = function_exists( 'ika_fd_get_last_attempt_exam_id' )
        ? ika_fd_get_last_attempt_exam_id( $user_id )
        : 0;

    $preferred_group_ids = [];
    if ( $last_exam_id && function_exists( 'ika_fd_get_quiz_post_id_by_exam_id' ) ) {
        $last_post_id = ika_fd_get_quiz_post_id_by_exam_id( $last_exam_id );
        if ( $last_post_id && function_exists( 'ika_fd_get_quiz_group_term_ids' ) ) {
            $preferred_group_ids = ika_fd_get_quiz_group_term_ids( $last_post_id );
        }
    }

    // Build candidate posts from the Quiz index.
    $idx = function_exists( 'ika_fd_get_quiz_index' ) ? ika_fd_get_quiz_index() : [ 'by_exam' => [] ];
    $candidates = [];

    foreach ( (array) ( $idx['by_exam'] ?? [] ) as $exam_id => $post_id ) {
        $exam_id = (int) $exam_id;
        $post_id = (int) $post_id;

        if ( ! $exam_id || ! $post_id ) continue;
        if ( in_array( $exam_id, $completed_exam_ids, true ) ) continue;

        $candidates[] = $post_id;
    }

    // Prefer same-group candidates if possible.
    if ( ! empty( $preferred_group_ids ) ) {
        $grouped = [];
        foreach ( $candidates as $pid ) {
            $gids = function_exists( 'ika_fd_get_quiz_group_term_ids' ) ? ika_fd_get_quiz_group_term_ids( $pid ) : [];
            if ( array_intersect( $preferred_group_ids, $gids ) ) {
                $grouped[] = $pid;
            }
        }
        if ( ! empty( $grouped ) ) {
            $candidates = $grouped;
        }
    }

    // Stable sort: menu_order then title.
    usort( $candidates, function( $a, $b ) {
        $ao = (int) get_post_field( 'menu_order', $a );
        $bo = (int) get_post_field( 'menu_order', $b );
        if ( $ao !== $bo ) return $ao <=> $bo;
        return strcmp( (string) get_the_title( $a ), (string) get_the_title( $b ) );
    });

    $items = [];
    foreach ( array_slice( $candidates, 0, $limit ) as $pid ) {
        $title = get_the_title( $pid );
        $url   = get_permalink( $pid );

        // Light metadata: group name if available.
        $meta = 'Recommended next step';
        $terms = get_the_terms( $pid, 'ika_quiz_group' );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $meta = 'Group: ' . $terms[0]->name;
        }

        $items[] = [
            'title' => $title ? $title : 'Recommended quiz',
            'meta'  => $meta,
            'url'   => $url ? $url : $quiz_hub_url,
            'cta'   => 'Start quiz',
        ];
    }

    // Fallback if we couldn't resolve anything.
    if ( empty( $items ) ) {
        $items = [
            [
                'title' => 'Browse quizzes',
                'meta'  => 'Pick a quick quiz and keep your progress moving.',
                'url'   => $quiz_hub_url,
                'cta'   => 'Open quiz hub',
            ],
        ];
    }

    $items = array_slice( $items, 0, $limit );

    ob_start(); ?>
    <div class="ika-rec-rail" data-context="<?php echo esc_attr( $context ); ?>">
        <div class="ika-rec-rail__head">
            <?php if ( ! empty( $show_header ) ) : ?>
                <h2 class="ika-hub-section-title">Recommended Next</h2>
            <?php endif; ?>
        </div>

        <div class="ika-rec-rail__grid">
            <?php foreach ( $items as $it ) : ?>
                <a class="ika-rec-card ika-rec-card--<?php echo esc_attr( $it['type'] ?? 'item' ); ?>" href="<?php echo esc_url( $it['url'] ); ?>">
                    <?php if ( ! empty( $it['chip'] ) ) : ?><div class="ika-rec-card__type"><?php echo esc_html( $it['chip'] ); ?></div><?php endif; ?>
                    <div class="ika-rec-card__title"><?php echo esc_html( $it['title'] ); ?></div>
                    <div class="ika-rec-card__meta"><?php echo esc_html( $it['meta'] ); ?></div>
						<?php if ( ! empty( $it['why'] ) ) : ?><div class="ika-rec-card__why"><?php echo esc_html( $it['why'] ); ?></div><?php endif; ?>
                    <div class="ika-rec-card__cta"><?php echo esc_html( $it['cta'] ); ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
} );


/**
 * Recommendation Engine v7 — New shortcodes
 *
 * [ika_rec_briefings limit="3" context="flightdeck"]
 * [ika_rec_courses   limit="3" context="flightdeck"]
 * [ika_results_recs  limit="3" title="Recommended Next"]
 * [ika_recs          type="all|quiz|briefing|course" limit="3" context="flightdeck|results|post" title="Recommended Next"]
 */

if ( class_exists( 'IKA_Recs_V7' ) ) {

	/**
	 * Shared renderer (uses the same card UI as the Flight Deck rail).
	 */
	$ika_render_recs_rail = function( array $items, array $opts = [] ) : string {
		$opts = array_merge( [
			'title'       => 'Recommended Next',
			'desc'        => '',
			'show_header' => true,
		], $opts );

		$title = (string) $opts['title'];
		$desc  = (string) $opts['desc'];
		$show_header = filter_var( $opts['show_header'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( $show_header === null ) $show_header = true;

		ob_start(); ?>
		<div class="ika-rec-rail">
			<?php if ( $show_header ) : ?>
				<div class="ika-rec-rail__head">
					<div class="ika-rec-rail__head-left">
					<div class="ika-rec-rail__label"><?php echo esc_html( $title ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<div class="ika-rec-rail__grid">
				<?php if ( empty( $items ) ) : ?>
					<div class="ika-rec-empty">
						<div class="ika-rec-empty__title">No recommendations yet</div>
						<div class="ika-rec-empty__desc">Take a quiz (or finish one) and we’ll suggest the best next step.</div>
					</div>
				<?php else : ?>
					<?php foreach ( $items as $it ) : ?>
					<a class="ika-rec-card ika-rec-card--<?php echo esc_attr( $it['type'] ?? 'item' ); ?> ika-rec-card--state-<?php echo esc_attr( $it['state'] ?? 'unknown' ); ?>" data-state="<?php echo esc_attr( $it['state'] ?? 'unknown' ); ?>" href="<?php echo esc_url( $it['url'] ); ?>">
						<?php if ( ! empty( $it['chip'] ) ) : ?><div class="ika-rec-card__type"><?php echo esc_html( $it['chip'] ); ?></div><?php endif; ?>
						<div class="ika-rec-card__title"><?php echo esc_html( $it['title'] ); ?></div>
						<div class="ika-rec-card__meta"><?php echo esc_html( $it['meta'] ); ?></div>
						<?php if ( ! empty( $it['why'] ) ) : ?><div class="ika-rec-card__why"><?php echo esc_html( $it['why'] ); ?></div><?php endif; ?>
						<div class="ika-rec-card__cta"><?php echo esc_html( $it['cta'] ); ?></div>
					</a>
				<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

			<?php if ( method_exists( 'IKA_Recs_V7', 'get_debug_comment' ) ) echo IKA_Recs_V7::get_debug_comment(); ?>
		<?php
		return ob_get_clean();
	};

	add_shortcode( 'ika_rec_briefings', function( $atts = [] ) use ( $ika_render_recs_rail ) {
		$atts = shortcode_atts( [
			'limit'   => 3,
			'context' => 'flightdeck',
			'title'   => 'Recommended Briefings',
		], $atts );

		$items = IKA_Recs_V7::get( [
			'context' => (string) $atts['context'],
			'types'   => [ 'briefing' ],
			'limit'   => intval( $atts['limit'] ),
		] );

		return $ika_render_recs_rail( $items, [
			'title' => (string) $atts['title'],
			'desc'  => 'Quick reads and references that match what you\'re learning.',
		] );
	} );

	add_shortcode( 'ika_rec_courses', function( $atts = [] ) use ( $ika_render_recs_rail ) {
		$atts = shortcode_atts( [
			'limit'   => 3,
			'context' => 'flightdeck',
			'title'   => 'Recommended Courses',
		], $atts );

		$items = IKA_Recs_V7::get( [
			'context' => (string) $atts['context'],
			'types'   => [ 'course' ],
			'limit'   => intval( $atts['limit'] ),
		] );

		return $ika_render_recs_rail( $items, [
			'title' => (string) $atts['title'],
			'desc'  => 'Structured learning paths that fit your current track.',
		] );
	} );

	add_shortcode( 'ika_results_recs', function( $atts = [] ) use ( $ika_render_recs_rail ) {
		$atts = shortcode_atts( [
			'limit' => 3,
			'title' => 'Recommended Next',
		], $atts );

		$p = get_post();
		if ( ! ( $p instanceof WP_Post ) ) return '';

		$items = IKA_Recs_V7::get_results_bundle( intval( $p->ID ), get_current_user_id(), intval( $atts['limit'] ) );

		return $ika_render_recs_rail( $items, [
			'title' => (string) $atts['title'],
			'desc'  => 'Based on your results, here are the best next steps.',
		] );
	} );

	add_shortcode( 'ika_recs', function( $atts = [] ) use ( $ika_render_recs_rail ) {
		$atts = shortcode_atts( [
			'type'    => 'all',          // all|quiz|briefing|course
			'limit'   => 3,
			'context' => 'flightdeck',   // flightdeck|results|post
			'title'   => 'Recommended Next',
			'desc'    => '',
		], $atts );

		$type = strtolower( trim( (string) $atts['type'] ) );
		$types = [ 'quiz', 'briefing', 'course' ];
		if ( $type === 'quiz' ) $types = [ 'quiz' ];
		else if ( $type === 'briefing' ) $types = [ 'briefing' ];
		else if ( $type === 'course' ) $types = [ 'course' ];

		$items = IKA_Recs_V7::get( [
			'context' => (string) $atts['context'],
			'types'   => $types,
			'limit'   => intval( $atts['limit'] ),
		] );

		return $ika_render_recs_rail( $items, [
			'title' => (string) $atts['title'],
			'desc'  => (string) $atts['desc'],
		] );
	} );
}
