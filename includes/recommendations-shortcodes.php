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
    $items = [
        [
            'title' => 'Continue your mission',
            'meta'  => 'Pick up with a quick quiz to keep your streak alive.',
            'url'   => $quiz_hub_url,
            'cta'   => 'Continue',
        ],
        [
            'title' => 'Recommended next quiz',
            'meta'  => 'A smart next step based on your progress (personalization coming next).',
            'url'   => $quiz_hub_url,
            'cta'   => 'Start',
        ],
        [
            'title' => 'Quick win (2–4 minutes)',
            'meta'  => 'A short quiz to earn XP fast and build momentum.',
            'url'   => $quiz_hub_url,
            'cta'   => 'Quick quiz',
        ],
    ];

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
