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
                    <p class="ika-hub-section-kicker">A few smart picks to keep you moving—without the overwhelm.</p>
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
                <p class="ika-hub-section-kicker">A few smart picks to keep you moving—without the overwhelm.</p>
            <?php endif; ?>
        </div>

        <div class="ika-rec-rail__grid">
            <?php foreach ( $items as $it ) : ?>
                <a class="ika-rec-card" href="<?php echo esc_url( $it['url'] ); ?>">
                    <div class="ika-rec-card__title"><?php echo esc_html( $it['title'] ); ?></div>
                    <div class="ika-rec-card__meta"><?php echo esc_html( $it['meta'] ); ?></div>
                    <div class="ika-rec-card__cta"><?php echo esc_html( $it['cta'] ); ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
} );
