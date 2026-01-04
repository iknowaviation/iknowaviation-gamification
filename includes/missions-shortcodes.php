<?php
/**
 * Flight Deck – Missions (Preview) Shortcodes
 *
 * Shortcode:
 *   [ika_fd_missions_preview limit="3"]
 *
 * Goals:
 * - Preserve the original Flight Deck markup/classes (so your existing CSS keeps working)
 * - Read real mission config + per-user mission state
 * - Be resilient to different config/state shapes across iterations
 * - If state shape is unexpected but today's bonus ledger contains an award for the mission key,
 *   treat the mission as Completed (prevents UI desync)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalize config keys across iterations.
 */
function ika_fd_dm_cfg( array $mdef ): array {
    $label  = isset( $mdef['label'] ) ? (string) $mdef['label'] : 'Mission';

    $desc = '';
    if ( isset( $mdef['description'] ) ) $desc = (string) $mdef['description'];
    elseif ( isset( $mdef['desc'] ) ) $desc = (string) $mdef['desc'];
    elseif ( isset( $mdef['hint'] ) ) $desc = (string) $mdef['hint'];

    $target = 1;
    if ( isset( $mdef['target'] ) ) $target = (int) $mdef['target'];
    elseif ( isset( $mdef['goal'] ) ) $target = (int) $mdef['goal'];

    $reward = 0;
    if ( isset( $mdef['xp_reward'] ) ) $reward = (int) $mdef['xp_reward'];
    elseif ( isset( $mdef['reward_xp'] ) ) $reward = (int) $mdef['reward_xp'];
    elseif ( isset( $mdef['reward'] ) ) $reward = (int) $mdef['reward'];
    elseif ( isset( $mdef['xp'] ) ) $reward = (int) $mdef['xp'];
    elseif ( isset( $mdef['reward_points'] ) ) $reward = (int) $mdef['reward_points'];

    return [
        'label'  => $label,
        'desc'   => $desc,
        'target' => max( 1, $target ),
        'reward' => max( 0, $reward ),
    ];
}

/**
 * Normalize per-user state across possible shapes.
 */
function ika_fd_dm_state_for( array $state, string $mid, int $user_id ): array {
    $progress  = 0;
    $completed = false;

    // Shape A: $state['missions'][$mid] = ['progress'=>..,'completed'=>..]
    if ( isset( $state['missions'][ $mid ] ) && is_array( $state['missions'][ $mid ] ) ) {
        $m = $state['missions'][ $mid ];
        $progress  = isset( $m['progress'] ) ? (int) $m['progress'] : 0;
        $completed = ! empty( $m['completed'] );
        return compact( 'progress', 'completed' );
    }

    // Shape B: $state[$mid] = ['progress'=>..,'completed'=>..]
    if ( isset( $state[ $mid ] ) && is_array( $state[ $mid ] ) ) {
        $m = $state[ $mid ];
        $progress  = isset( $m['progress'] ) ? (int) $m['progress'] : 0;
        $completed = ! empty( $m['completed'] );
        return compact( 'progress', 'completed' );
    }

    // Shape C: $state['completed'] = ['mid1','mid2'] OR keyed array, and $state['progress'][$mid]
    if ( isset( $state['completed'] ) && is_array( $state['completed'] ) ) {
        if ( in_array( $mid, $state['completed'], true ) || ! empty( $state['completed'][ $mid ] ) ) {
            $completed = true;
        }
    }
    if ( isset( $state['progress'] ) && is_array( $state['progress'] ) && isset( $state['progress'][ $mid ] ) ) {
        $progress = (int) $state['progress'][ $mid ];
    }

    // Fallback: if we have an XP bonus ledger and see an award today with reason == mission key,
    // treat it as completed (prevents UI desync if state keys evolve).
    if ( ! $completed && function_exists( 'ika_xp_bonus_get_ledger' ) ) {
        $today = wp_date( 'Y-m-d' );
        foreach ( array_reverse( ika_xp_bonus_get_ledger( $user_id ) ) as $row ) {
            if ( empty( $row['date'] ) || empty( $row['reason'] ) ) continue;
            if ( (string) $row['date'] !== $today ) continue;
            if ( (string) $row['reason'] !== $mid ) continue;
            $amount = (int) ( $row['amount'] ?? 0 );
            if ( $amount > 0 ) {
                $completed = true;
                $progress  = max( 1, $progress );
                break;
            }
        }
    }

    return compact( 'progress', 'completed' );
}

add_shortcode( 'ika_fd_missions_preview', function ( $atts ) {

    if ( ! is_user_logged_in() ) {
        return '';
    }

    $atts = shortcode_atts(
        [
            'limit' => 3,
        ],
        $atts,
        'ika_fd_missions_preview'
    );

    $limit = (int) $atts['limit'];
    if ( $limit < 1 ) $limit = 1;
    if ( $limit > 6 ) $limit = 6;

    $user_id = get_current_user_id();

    // Use the Daily Missions module (real mission config + per-user state).
    $config = function_exists( 'ika_dm_get_missions_config' ) ? ika_dm_get_missions_config() : [];
    $state  = function_exists( 'ika_dm_get_state' ) ? ika_dm_get_state( $user_id ) : [ 'missions' => [] ];

    $items = [];

    foreach ( (array) $config as $mid => $mdef ) {

        $mid = (string) $mid;

        $cfg = ika_fd_dm_cfg( is_array( $mdef ) ? $mdef : [] );
        $st  = ika_fd_dm_state_for( is_array( $state ) ? $state : [], $mid, (int) $user_id );

        $progress  = (int) ( $st['progress'] ?? 0 );
        $completed = ! empty( $st['completed'] );

        $state_label = $completed ? 'Completed' : ( $progress > 0 ? 'In progress' : 'Not started' );
        $reward_label = $cfg['reward'] ? sprintf( '+%d XP', (int) $cfg['reward'] ) : '';

        $items[] = [
            'label'     => 'Daily Mission',
            'title'     => $cfg['label'],
            'desc'      => $cfg['desc'],
            'reward'    => $reward_label,
            'state'     => $state_label,
            'progress'  => $progress,
            'target'    => (int) $cfg['target'],
            'completed' => $completed,
            'mid'       => $mid,
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
                'mid' => 'fallback',
            ],
        ];
    }

    // Show actionable missions first.
    usort( $items, function ( $a, $b ) {
        $rank = [ 'Not started' => 0, 'In progress' => 1, 'Completed' => 2 ];
        $ra = $rank[ $a['state'] ] ?? 99;
        $rb = $rank[ $b['state'] ] ?? 99;
        if ( $ra === $rb ) return 0;
        return ( $ra < $rb ) ? -1 : 1;
    } );

    $items = array_slice( $items, 0, $limit );

    // Missions URL: for now jump to the missions section on this page (sticky nav).
    $missions_url = '#fd-missions';

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
                <?php $card_url = ! empty( $m['completed'] ) ? '#fd-flightlog' : '#fd-recommended'; ?>
                <a class="ika-dm-card<?php echo ! empty( $m['completed'] ) ? ' is-complete' : ''; ?>" href="<?php echo esc_url( $card_url ); ?>">
                    <div class="ika-dm-card-label"><?php echo esc_html( $m['label'] ); ?></div>
                    <div class="ika-dm-card-title"><?php echo esc_html( $m['title'] ); ?></div>

                    <?php if ( ! empty( $m['desc'] ) ) : ?>
                        <p class="ika-dm-card-description"><?php echo esc_html( $m['desc'] ); ?></p>
                    <?php endif; ?>

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
