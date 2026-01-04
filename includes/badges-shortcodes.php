<?php
/**
 * Flight Deck – Badges (Preview) Shortcodes
 *
 * Phase 1 (safe shell):
 * - Renders a restrained badges preview grid on the Flight Deck dashboard.
 * - Uses Flight Deck standard section header markup:
 *     <h2 class="ika-hub-section-title">...</h2>
 *     <p class="ika-hub-section-kicker">...</p>
 * - Uses placeholders until Watu Play badge wiring is added.
 *
 * Shortcode:
 *   [ika_fd_badges_preview limit="6"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'ika_fd_badges_preview', function( $atts ) {

    $atts = shortcode_atts(
        [
            'limit' => 6,
        ],
        (array) $atts,
        'ika_fd_badges_preview'
    );

    $limit = (int) $atts['limit'];
    if ( $limit < 1 ) $limit = 1;
    if ( $limit > 12 ) $limit = 12;

    $badges_url = home_url( '/flight-deck/badges/' );

    ob_start();
    ?>
    <div class="ika-fd-badges-preview">
        <div class="ika-hub-section-head">
            <div class="ika-hub-section-head__text">
                <h2 class="ika-hub-section-title">Badges &amp; Achievements</h2>
                <p class="ika-hub-section-kicker">A quick snapshot of what you’ve earned recently.</p>
            </div>
            <a class="ika-hub-section-link" href="<?php echo esc_url( $badges_url ); ?>">View all badges &rarr;</a>
        </div>

        <?php if ( ! is_user_logged_in() ) : ?>
            <div class="ika-fd-badges-empty">
                <div class="ika-fd-badges-empty__title">Log in to view your badges</div>
                <div class="ika-fd-badges-empty__meta">Your achievements will appear here once you’re signed in.</div>
            </div>
        <?php else : ?>
            <?php
            // Phase 1 placeholders: we’ll replace with Watu Play earned/locked badges later.
            $user_id = get_current_user_id();
            $xp = (int) get_user_meta( $user_id, 'ika_total_xp', true );

            $ladder = function_exists( 'ika_get_rank_ladder' ) ? ika_get_rank_ladder() : [];
            // Ensure ascending by min_xp.
            usort( $ladder, function( $a, $b ) {
                return (int) ($a['min_xp'] ?? 0) <=> (int) ($b['min_xp'] ?? 0);
            });

            $earned = [];
            $locked = [];

            foreach ( (array) $ladder as $r ) {
                $label = isset( $r['label'] ) ? (string) $r['label'] : '';
                $min_xp = isset( $r['min_xp'] ) ? (int) $r['min_xp'] : 0;
                if ( $label === '' ) continue;

                if ( $xp >= $min_xp ) {
                    $earned[] = [ 'title' => $label, 'meta' => 'Earned' ];
                } else {
                    $locked[] = [ 'title' => $label, 'meta' => 'Locked' ];
                }
            }

            // Show a balanced slice: recent earned first, then upcoming locked.
            $items = [];

            $earned_slice = array_slice( array_reverse( $earned ), 0, (int) ceil( $limit / 2 ) );
            foreach ( $earned_slice as $e ) $items[] = $e;

            foreach ( $locked as $l ) {
                if ( count( $items ) >= $limit ) break;
                $items[] = $l;
            }

            // If user has no earned ranks yet, just show the first few locked.
            if ( empty( $items ) ) {
                $items = array_slice( $locked, 0, $limit );
            }
            $items = array_slice( $items, 0, $limit );
            ?>
            <div class="ika-fd-badges-grid">
                <?php foreach ( $items as $b ) : ?>
                    <a class="ika-fd-badge-card" href="<?php echo esc_url( $badges_url ); ?>">
                        <div class="ika-fd-badge-icon" aria-hidden="true"></div>
                        <div class="ika-fd-badge-title"><?php echo esc_html( $b['title'] ); ?></div>
                        <div class="ika-fd-badge-meta"><?php echo esc_html( $b['meta'] ); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
} );
