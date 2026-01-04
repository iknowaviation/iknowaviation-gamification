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
    $user_id = get_current_user_id();

    // Use the Daily Missions module (real mission config + per-user state).
    $config = function_exists( 'ika_dm_get_missions_config' ) ? ika_dm_get_missions_config() : [];
    $state  = function_exists( 'ika_dm_get_state' ) ? ika_dm_get_state( $user_id ) : [ 'missions' => [] ];

    $items = [];

    foreach ( (array) $config as $mid => $mdef ) {
        $label = isset( $mdef['label'] ) ? (string) $mdef['label'] : 'Mission';
        $desc  = isset( $mdef['description'] ) ? (string) $mdef['description'] : '';
        $target = isset( $mdef['target'] ) ? (int) $mdef['target'] : 1;
        $reward = isset( $mdef['xp_reward'] ) ? (int) $mdef['xp_reward'] : 0;

        $progress = 0;
        $completed = false;

        if ( isset( $state['missions'][ $mid ] ) ) {
            $progress  = isset( $state['missions'][ $mid ]['progress'] ) ? (int) $state['missions'][ $mid ]['progress'] : 0;
            $completed = ! empty( $state['missions'][ $mid ]['completed'] );
        }

        $state_label = $completed ? 'Completed' : ( $progress > 0 ? 'In progress' : 'Not started' );
        $reward_label = $reward ? sprintf( '+%d XP', $reward ) : '';

        $items[] = [
            'label'  => 'Daily Mission',
            'title'  => $label,
            'desc'   => $desc,
            'reward' => $reward_label,
            'state'  => $state_label,
            'progress' => $progress,
            'target' => $target,
            'completed' => $completed,
        ];
    }

    // Conservative fallback (should rarely happen).
    if ( empty( $items ) ) {
        $items = [
            [
                'label' => 'Daily Mission',
                'title' => 'Complete a quiz',
                'desc'  => 'Finish any quiz today to keep your momentum.',
                'reward'=> '+5 XP',
                'state' => 'Not started',
                'progress' => 0,
                'target' => 1,
                'completed' => false,
            ],
        ];
    }

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
