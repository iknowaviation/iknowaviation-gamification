<?php
/**
 * Flight Deck – Leaderboard wrapper shortcodes
 *
 * Goal: standardize header/kicker/tabs markup on Flight Deck pages,
 * while reusing the existing leaderboard engine shortcodes:
 *   - [ika_leaderboard] (all time)
 *   - [ika_leaderboard_week] (weekly, bonus+quiz)
 *
 * Shortcode:
 *   [ika_fd_leaderboard limit="10" mode="week"]
 *
 * Notes:
 * - We render BOTH panels (week + all) and toggle with JS for instant switching.
 * - "Friends" is intentionally omitted (future feature).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'ika_fd_leaderboard', function ( $atts ) {

    $atts = shortcode_atts(
        [
            'limit' => 10,
            'mode'  => 'week', // week|all
        ],
        $atts,
        'ika_fd_leaderboard'
    );

    $limit = max( 1, intval( $atts['limit'] ) );
    $mode  = ( $atts['mode'] === 'all' ) ? 'all' : 'week';

    $leaderboard_url = home_url( '/flight-deck/leaderboard/' );

    ob_start();
    ?>
    <div class="ika-fd-leaderboard" data-ika-fd-leaderboard>
        <div class="ika-hub-section-head">
            <div class="ika-hub-section-head__text">
                <h2 class="ika-hub-section-title">Squadron Leaderboard</h2>
                <p class="ika-hub-section-kicker">See how you stack up against other pilots.</p>
            </div>
            <a class="ika-hub-section-link" href="<?php echo esc_url( $leaderboard_url ); ?>">View leaderboard &rarr;</a>
        </div>

        <?php if ( ! is_user_logged_in() ) : ?>
            <div class="ika-fd-leaderboard-empty">
                <div class="ika-fd-leaderboard-empty__title">Log in to view rankings</div>
                <div class="ika-fd-leaderboard-empty__meta">Your squadron standings and weekly highlights will show here once you’re signed in.</div>
                <a class="ika-hub-section-link" href="<?php echo esc_url( wp_login_url( esc_url_raw( $_SERVER['REQUEST_URI'] ?? home_url( '/' ) ) ) ); ?>">Log in</a>
            </div>
        <?php else : ?>

            <div class="ika-fd-leaderboard-headrow">
                <div class="ika-fd-leaderboard-label" data-ika-fd-leaderboard-label>
                    <?php echo ( $mode === 'all' ) ? 'All-time rankings' : 'This week’s rankings'; ?>
                </div>

                <div class="ika-fd-leaderboard-tabs" role="tablist" aria-label="Leaderboard filters">
                    <button class="ika-fd-tab<?php echo $mode === 'week' ? ' is-active' : ''; ?>"
                            type="button"
                            data-mode="week"
                            role="tab"
                            aria-selected="<?php echo $mode === 'week' ? 'true' : 'false'; ?>">
                        This week
                    </button>

                    <button class="ika-fd-tab<?php echo $mode === 'all' ? ' is-active' : ''; ?>"
                            type="button"
                            data-mode="all"
                            role="tab"
                            aria-selected="<?php echo $mode === 'all' ? 'true' : 'false'; ?>">
                        All time
                    </button>
                </div>
            </div>

            <div class="ika-fd-leaderboard-card">
                <div class="ika-fd-leaderboard-panel" data-mode="week" <?php echo $mode === 'week' ? '' : 'hidden'; ?>>
                    <?php echo do_shortcode( '[ika_leaderboard_week limit="' . (int) $limit . '" days="7"]' ); ?>
                </div>

                <div class="ika-fd-leaderboard-panel" data-mode="all" <?php echo $mode === 'all' ? '' : 'hidden'; ?>>
                    <?php echo do_shortcode( '[ika_leaderboard limit="' . (int) $limit . '"]' ); ?>
                </div>
            </div>

        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
} );
