<?php
// FULL FILE: Leaderboard using XP Ledger as source of truth
// Fixes weekly quiz count (7D) using ledger.quiz_attempt rows
// Preserves back-compat shortcodes

if (!defined('ABSPATH')) exit;

function ika_render_leaderboard_table($args = []) {
    global $wpdb;

    $defaults = [
        'period' => 'all', // 'week' or 'all'
        'limit'  => 10,
    ];
    $args = wp_parse_args($args, $defaults);

    $ledger = $wpdb->prefix . 'ika_xp_ledger';

    $date_where = '';
    if ($args['period'] === 'week') {
        $date_where = "AND created_at >= (NOW() - INTERVAL 7 DAY)";
    }

    // XP totals
    $xp_sql = $wpdb->prepare(
        "SELECT user_id, SUM(xp) AS xp_total
         FROM {$ledger}
         WHERE source = 'quiz_attempt'
         {$date_where}
         GROUP BY user_id
         ORDER BY xp_total DESC
         LIMIT %d",
        intval($args['limit'])
    );

    $xp_rows = $wpdb->get_results($xp_sql);

    if (empty($xp_rows)) {
        return '<div class="ika-empty">No leaderboard data yet.</div>';
    }

    // Collect user IDs
    $user_ids = wp_list_pluck($xp_rows, 'user_id');
    $user_ids_sql = implode(',', array_map('intval', $user_ids));

    // Quiz counts
    $quiz_sql = "SELECT user_id, COUNT(DISTINCT taking_id) AS quiz_count
                 FROM {$ledger}
                 WHERE source = 'quiz_attempt'
                 {$date_where}
                 AND user_id IN ({$user_ids_sql})
                 GROUP BY user_id";

    $quiz_rows = $wpdb->get_results($quiz_sql, OBJECT_K);

    ob_start();
    ?>
    <table class="ika-leaderboard-table">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Pilot</th>
                <th>Level</th>
                <th><?php echo ($args['period'] === 'week') ? 'Quizzes (7D)' : 'Quizzes'; ?></th>
                <th><?php echo ($args['period'] === 'week') ? 'XP (7D)' : 'XP'; ?></th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rank = 1;
        foreach ($xp_rows as $row) {
            $user = get_user_by('id', $row->user_id);
            if (!$user) continue;

            $quiz_count = isset($quiz_rows[$row->user_id])
                ? intval($quiz_rows[$row->user_id]->quiz_count)
                : 0;

            $level = get_user_meta($row->user_id, 'ika_rank_label', true);
            if (!$level) $level = '—';
            ?>
            <tr>
                <td><?php echo esc_html($rank); ?></td>
                <td><?php echo esc_html($user->display_name); ?></td>
                <td><?php echo esc_html($level); ?></td>
                <td><?php echo esc_html($quiz_count); ?></td>
                <td><?php echo esc_html(intval($row->xp_total)); ?></td>
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
add_shortcode('ika_leaderboard_week', function($atts) {
    $atts = shortcode_atts(['limit' => 10], $atts);
    return ika_render_leaderboard_table([
        'period' => 'week',
        'limit'  => intval($atts['limit']),
    ]);
});

add_shortcode('ika_leaderboard_all_time', function($atts) {
    $atts = shortcode_atts(['limit' => 10], $atts);
    return ika_render_leaderboard_table([
        'period' => 'all',
        'limit'  => intval($atts['limit']),
    ]);
});

// Back-compat: default leaderboard = all time
add_shortcode('ika_leaderboard', function($atts) {
    $atts = shortcode_atts(['limit' => 10], $atts);
    return ika_render_leaderboard_table([
        'period' => 'all',
        'limit'  => intval($atts['limit']),
    ]);
});
