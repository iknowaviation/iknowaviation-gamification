<?php
/**
 * Flight Deck – Leaderboard wrapper shortcodes
 *
 * Goal: standardize header/kicker/tabs markup on Flight Deck pages,
 * while reusing the existing leaderboard engine shortcode:
 *   [ika_leaderboard]
 *
 * Shortcode:
 *   [ika_fd_leaderboard limit="10"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'ika_fd_leaderboard', function( $atts ){

    $atts = shortcode_atts(
        [
            'limit' => 10,
            'mode'  => 'week', // week|all|friends (UI only for now)
        ],
        (array) $atts,
        'ika_fd_leaderboard'
    );

    $limit = (int) $atts['limit'];
    if ( $limit < 1 )  $limit = 1;
    if ( $limit > 50 ) $limit = 50;

    $mode = sanitize_key( (string) $atts['mode'] );
    if ( ! in_array( $mode, [ 'week', 'all', 'friends' ], true ) ) {
        $mode = 'week';
    }

    // NOTE: We keep the tab UI "dumb" for now (Phase 1 shell).
    // In Phase 2 we can wire mode to the underlying engine.
    $tab_week   = $mode === 'week'    ? ' is-active' : '';
    $tab_all    = $mode === 'all'     ? ' is-active' : '';
    $tab_friend = $mode === 'friends' ? ' is-active' : '';

    $friends_url = home_url( '/flight-deck/leaderboard/' );

    ob_start();
    ?>
    <div class="ika-fd-leaderboard">
        <div class="ika-hub-section-head">
            <div class="ika-hub-section-head__text">
                <h2 class="ika-hub-section-title">Squadron Leaderboard</h2>
                <p class="ika-hub-section-kicker">See how you stack up against other pilots.</p>
            </div>
            <a class="ika-hub-section-link" href="<?php echo esc_url( $friends_url ); ?>">View leaderboard &rarr;</a>
        </div>

        <?php if ( ! is_user_logged_in() ) : ?>
            <div class="ika-fd-leaderboard-empty">
                <div class="ika-fd-leaderboard-empty__title">Log in to view rankings</div>
                <div class="ika-fd-leaderboard-empty__meta">Your squadron standings and weekly highlights will show here once you’re signed in.</div>
                <a class="ika-hub-section-link" href="<?php echo esc_url( wp_login_url( (string) ( $_SERVER['REQUEST_URI'] ?? home_url( '/' ) ) ) ); ?>">Log in</a>
            </div>
        <?php else : ?>

            <div class="ika-fd-leaderboard-headrow">
                <div class="ika-fd-leaderboard-label">This week’s rankings</div>
                <div class="ika-fd-leaderboard-tabs" role="tablist" aria-label="Leaderboard filters">
                    <button class="ika-fd-tab<?php echo esc_attr( $tab_week ); ?>" type="button" aria-selected="<?php echo $mode==='week'?'true':'false'; ?>">This week</button>
                    <button class="ika-fd-tab<?php echo esc_attr( $tab_all ); ?>" type="button" aria-selected="<?php echo $mode==='all'?'true':'false'; ?>">All time</button>
                    <button class="ika-fd-tab<?php echo esc_attr( $tab_friend ); ?>" type="button" aria-selected="<?php echo $mode==='friends'?'true':'false'; ?>">Friends</button>
                </div>
            </div>

            <div class="ika-fd-leaderboard-card">
                <?php
                // Reuse your existing leaderboard output.
                echo do_shortcode( ( $mode === 'week' )
                    ? '[ika_leaderboard_week limit="' . (int) $limit . '" days="7"]'
                    : '[ika_leaderboard limit="' . (int) $limit . '"]' );
                ?>
            </div>

        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
} );
