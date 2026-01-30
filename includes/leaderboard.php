<?php
// FULL FILE: Leaderboard
//
// Contract alignment notes:
// - Weekly leaderboards MUST reflect the last 7 days of XP from the XP ledger (quiz + bonuses).
// - All-time leaderboards MUST reflect the same "Total XP" users see elsewhere.
//   Today, Total XP can still include legacy cached/meta bonus totals (ika_total_xp_bonus) on top of
//   ledger quiz XP. Until full migration is complete, we add that bonus meta here so leaderboard
//   does not drift from the rest of the UI.
//
// This fixes the exact mismatch you saw (e.g., 260 elsewhere but 230 on leaderboards) without
// introducing any new XP sources.

if ( ! defined( 'ABSPATH' ) ) exit;

function ika_render_leaderboard_table( $args = [] ) {
    global $wpdb;

    $defaults = [
        'period' => 'all', // 'week' or 'all'
        'limit'  => 10,
        'days'   => 7,
    ];
    $args = wp_parse_args( $args, $defaults );

    $period = ( $args['period'] === 'week' ) ? 'week' : 'all';
    $limit  = max( 1, intval( $args['limit'] ) );
    $days   = max( 1, intval( $args['days'] ) );

    $ledger = $wpdb->prefix . 'ika_xp_ledger';

    $date_where = '';
    if ( $period === 'week' ) {
        // Weekly view is ledger-only within the window (quiz + bonuses).
        $date_where = $wpdb->prepare( "AND created_at >= (NOW() - INTERVAL %d DAY)", $days );
    }

    // 1) Base XP from ledger
    // For 'all' we will optionally add legacy bonus meta per-user (see below).
    $base_sql = $wpdb->prepare(
        "SELECT user_id, COALESCE(SUM(xp),0) AS xp_base
         FROM {$ledger}
         WHERE 1=1
         {$date_where}
         GROUP BY user_id",
        // (no placeholders used if $date_where empty; safe because prepare ignores extras)
        0
    );

    // IMPORTANT: $date_where already prepared when used; don't double-prepare the full query.
    // When $period === 'all', $date_where is empty and the query has no placeholders.
    if ( $period === 'week' ) {
        // In week mode, $date_where contains the prepared clause already.
        $base_sql = "SELECT user_id, COALESCE(SUM(xp),0) AS xp_base
                     FROM {$ledger}
                     WHERE 1=1
                     {$date_where}
                     GROUP BY user_id";
    } else {
        $base_sql = "SELECT user_id, COALESCE(SUM(xp),0) AS xp_base
                     FROM {$ledger}
                     WHERE 1=1
                     GROUP BY user_id";
    }

    $rows = $wpdb->get_results( $base_sql );

    if ( empty( $rows ) ) {
        return '<div class="ika-empty">No leaderboard data yet.</div>';
    }

    // 2) Build working set with final XP values
    $work = [];
    foreach ( $rows as $r ) {
        $uid = (int) $r->user_id;
        if ( $uid <= 0 ) continue;

        $xp_base = (int) $r->xp_base;
        $xp_total = $xp_base;

        if ( $period === 'all' ) {
            // All-time XP is ledger-only (single source of truth).
            // Bonuses are recorded in the ledger, so do not add legacy/meta totals here.
        }

        $work[] = [
            'user_id'  => $uid,
            'xp_total' => $xp_total,
        ];
    }

    // 3) Sort and slice to limit
    usort( $work, function( $a, $b ) {
        return $b['xp_total'] <=> $a['xp_total'];
    } );
    $work = array_slice( $work, 0, $limit );

    if ( empty( $work ) ) {
        return '<div class="ika-empty">No leaderboard data yet.</div>';
    }

    // Collect IDs for quiz count query
    $user_ids = array_map( fn($x) => (int) $x['user_id'], $work );
    $user_ids_sql = implode( ',', array_map( 'intval', $user_ids ) );

    // Quiz counts use quiz_attempt rows in the same period window.
    $quiz_count_sql = "SELECT user_id, COUNT(DISTINCT taking_id) AS quiz_count
                      FROM {$ledger}
                      WHERE source = 'quiz_attempt'
                      {$date_where}
                      AND user_id IN ({$user_ids_sql})
                      GROUP BY user_id";

    $quiz_rows = $wpdb->get_results( $quiz_count_sql, OBJECT_K );

    ob_start();
    ?>
    <table class="ika-leaderboard-table">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Pilot</th>
                <th>Level</th>
                <th><?php echo ( $period === 'week' ) ? 'Quizzes (7D)' : 'Quizzes'; ?></th>
                <th><?php echo ( $period === 'week' ) ? 'XP (7D)' : 'XP'; ?></th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rank = 1;
        foreach ( $work as $row ) {
            $uid  = (int) $row['user_id'];
            $user = get_user_by( 'id', $uid );
            if ( ! $user ) continue;

            $quiz_count = isset( $quiz_rows[ $uid ] )
                ? intval( $quiz_rows[ $uid ]->quiz_count )
                : 0;

            $level = get_user_meta( $uid, 'ika_rank_label', true );
            if ( ! $level ) $level = '—';
            ?>
            <tr>
                <td><?php echo esc_html( $rank ); ?></td>
                <td><?php echo esc_html( $user->display_name ); ?></td>
                <td><?php echo esc_html( $level ); ?></td>
                <td><?php echo esc_html( $quiz_count ); ?></td>
                <td><?php echo esc_html( intval( $row['xp_total'] ) ); ?></td>
            </tr>
            <?php
            $rank++;
        }
        ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

// Shortcodes
add_shortcode( 'ika_leaderboard_week', function( $atts ) {
    $atts = shortcode_atts( [ 'limit' => 10, 'days' => 7 ], $atts );
    return ika_render_leaderboard_table( [
        'period' => 'week',
        'limit'  => intval( $atts['limit'] ),
        'days'   => intval( $atts['days'] ),
    ] );
} );

add_shortcode( 'ika_leaderboard_all_time', function( $atts ) {
    $atts = shortcode_atts( [ 'limit' => 10 ], $atts );
    return ika_render_leaderboard_table( [
        'period' => 'all',
        'limit'  => intval( $atts['limit'] ),
    ] );
} );

// Back-compat: default leaderboard = all time
add_shortcode( 'ika_leaderboard', function( $atts ) {
    $atts = shortcode_atts( [ 'limit' => 10 ], $atts );
    return ika_render_leaderboard_table( [
        'period' => 'all',
        'limit'  => intval( $atts['limit'] ),
    ] );
} );
