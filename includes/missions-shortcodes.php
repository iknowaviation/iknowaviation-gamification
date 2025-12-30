<?php
/**
 * Flight Deck – Missions (Preview) Shortcodes
 *
 * Phase 1 (safe shell):
 * - Renders a restrained missions preview grid on the Flight Deck dashboard.
 * - Uses Flight Deck standard section header markup:
 *     <h2 class="ika-hub-section-title">...</h2>
 *     <p class="ika-hub-section-kicker">...</p>
 * - Uses placeholders until the missions engine is wired.
 *
 * Shortcode:
 *   [ika_fd_missions_preview limit="3"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'ika_fd_missions_preview', function( $atts ) {

    $atts = shortcode_atts(
        [
            'limit' => 3,
        ],
        (array) $atts,
        'ika_fd_missions_preview'
    );

    $limit = (int) $atts['limit'];
    if ( $limit < 1 ) $limit = 1;
    if ( $limit > 6 ) $limit = 6;

    // Destination page (sub-page already exists / planned).
    $missions_url = home_url( '/flight-deck/missions/' );

    // Phase 1 placeholders (will be replaced by real mission engine + recommendation tags).
    $items = [
        [
            'label' => 'Today\'s Mission',
            'title' => 'Complete 2 quizzes',
            'desc'  => 'Knock out a quick set to keep your streak alive.',
            'reward'=> '+50 XP',
            'state' => 'Not started',
        ],
        [
            'label' => 'Weekly Mission',
            'title' => 'Try a new topic',
            'desc'  => 'Explore a different quiz group this week.',
            'reward'=> '+150 XP',
            'state' => 'In progress',
        ],
        [
            'label' => 'Special Mission',
            'title' => 'Perfect score run',
            'desc'  => 'Earn a perfect score on any quiz.',
            'reward'=> '+Badge',
            'state' => 'Optional',
        ],
    ];

    $items = array_slice( $items, 0, $limit );

    ob_start();
    ?>
    <div class="ika-fd-missions-preview">
        <div class="ika-hub-section-head">
            <div class="ika-hub-section-head__text">
                <h2 class="ika-hub-section-title">Missions</h2>
                <p class="ika-hub-section-kicker">Small goals that guide what to do next.</p>
            </div>
            <a class="ika-hub-section-link" href="<?php echo esc_url( $missions_url ); ?>">View all missions &rarr;</a>
        </div>

        <div class="ika-dm-grid">
            <?php foreach ( $items as $m ) : ?>
                <a class="ika-dm-card" href="<?php echo esc_url( $missions_url ); ?>">
                    <div class="ika-dm-card-label"><?php echo esc_html( $m['label'] ); ?></div>
                    <div class="ika-dm-card-title"><?php echo esc_html( $m['title'] ); ?></div>
                    <p class="ika-dm-card-description"><?php echo esc_html( $m['desc'] ); ?></p>

                    <div class="ika-dm-card-footer">
                        <span class="ika-dm-card-reward"><?php echo esc_html( $m['reward'] ); ?></span>
                        <span class="ika-dm-card-state"><?php echo esc_html( $m['state'] ); ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
} );
