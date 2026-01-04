<?php
/**
 * Flight Deck – Flight Log (Preview) Shortcode
 *
 * Phase 1 (safe shell):
 * - Renders a restrained flight log preview on the Flight Deck dashboard.
 * - Uses Flight Deck standard section header markup:
 *     <h2 class="ika-hub-section-title">...</h2>
 *     <p class="ika-hub-section-kicker">...</p>
 * - Uses placeholders until quiz-attempt history wiring is added.
 *
 * Shortcode:
 *   [ika_fd_flightlog_preview limit="5"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'ika_fd_flightlog_preview', function( $atts ) {

    $atts = shortcode_atts(
        [
            'limit' => 5,
        ],
        (array) $atts,
        'ika_fd_flightlog_preview'
    );

    $limit = (int) $atts['limit'];
    if ( $limit < 1 ) $limit = 1;
    if ( $limit > 20 ) $limit = 20;

    $logbook_url = home_url( '/flight-deck/logbook/' );
    $quizzes_url = home_url( '/quizzes/' );

    ob_start();
    ?>
    <div class="ika-fd-flightlog-preview">
        <div class="ika-hub-section-head">
            <div class="ika-hub-section-head__text">
                <h2 class="ika-hub-section-title">Flight Log</h2>
                <p class="ika-hub-section-kicker">Your recent quiz activity and progress.</p>
            </div>
            <a class="ika-hub-section-link" href="<?php echo esc_url( $logbook_url ); ?>">View full logbook &rarr;</a>
        </div>

        <?php if ( ! is_user_logged_in() ) : ?>
            <div class="ika-fd-flightlog-empty">
                <div class="ika-fd-flightlog-empty__title">Log in to view your flight log</div>
                <div class="ika-fd-flightlog-empty__meta">We’ll track your recent quizzes, scores, and next actions here.</div>
                <a class="ika-hub-section-link" href="<?php echo esc_url( wp_login_url( (string) ( $_SERVER['REQUEST_URI'] ?? home_url( '/' ) ) ) ); ?>">Log in</a>
            </div>
        <?php else : ?>
            <?php
            // Phase 1 placeholders — replace with WatuPRO attempt history + status mapping.
            $summary = [
                [ 'label' => 'Last quiz',               'value' => '2 hours ago' ],
                [ 'label' => 'Average (last 10)',       'value' => '74%' ],
                [ 'label' => 'Highest recent score',    'value' => '100%' ],
            ];

            $user_id = get_current_user_id();

            global $wpdb;
            $takings_tbl = function_exists( 'ika_fd_taken_table' ) ? ika_fd_taken_table() : $wpdb->prefix . 'watupro_taken_exams';

            // Pull recent finished attempts.
            $attempts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT exam_id, percent_correct, points, end_time
                     FROM {$takings_tbl}
                     WHERE user_id = %d
                       AND (in_progress IS NULL OR in_progress = 0)
                       AND (ignore_attempt IS NULL OR ignore_attempt = 0)
                     ORDER BY COALESCE(end_time,'') DESC, ID DESC
                     LIMIT %d",
                    $user_id,
                    max( 1, min( 25, $limit * 5 ) )
                )
            );

            $rows = [];

            if ( ! empty( $attempts ) ) {
                // Count attempts per exam_id for the subset we're showing.
                $exam_ids = array_values( array_unique( array_map( fn($r)=> (int) $r->exam_id, $attempts ) ) );
                $counts = [];

                if ( ! empty( $exam_ids ) ) {
                    $in = implode( ',', array_map( 'intval', $exam_ids ) );
                    $count_rows = $wpdb->get_results(
                        "SELECT exam_id, COUNT(*) AS c
                         FROM {$takings_tbl}
                         WHERE user_id = " . intval( $user_id ) . "
                           AND exam_id IN ({$in})
                           AND (in_progress IS NULL OR in_progress = 0)
                           AND (ignore_attempt IS NULL OR ignore_attempt = 0)
                         GROUP BY exam_id"
                    );
                    foreach ( (array) $count_rows as $cr ) {
                        $counts[ (int) $cr->exam_id ] = (int) $cr->c;
                    }
                }

                foreach ( array_slice( $attempts, 0, $limit ) as $a ) {
                    $exam_id = (int) $a->exam_id;
                    $pct     = isset( $a->percent_correct ) ? (float) $a->percent_correct : 0.0;

                    $post_id = function_exists( 'ika_fd_get_quiz_post_id_by_exam_id' ) ? ika_fd_get_quiz_post_id_by_exam_id( $exam_id ) : 0;

                    $title = $post_id ? get_the_title( $post_id ) : ( 'Quiz #' . $exam_id );
                    $url   = $post_id ? get_permalink( $post_id ) : $logbook_url;

                    $is_complete = ( $pct >= 70.0 );

                    $rows[] = [
                        'quiz'     => $title,
                        'url'      => $url,
                        'score'    => sprintf( '%d%%', (int) round( $pct ) ),
                        'attempts' => (string) ( $counts[ $exam_id ] ?? 1 ),
                        'xp'       => ( isset( $a->points ) ? ( '+' . intval( $a->points ) ) : '' ),
                        'status'   => $is_complete ? 'Completed' : 'In Progress',
                        'status_class' => $is_complete ? 'is-complete' : 'is-started',
                        'action'   => $is_complete ? 'Retake' : 'Continue',
                        'action_url' => $url,
                        'date'     => function_exists( 'ika_fd_format_attempt_date' ) ? ika_fd_format_attempt_date( $a->end_time ) : '',
                    ];
                }
            }
            $rows = array_slice( $rows, 0, $limit );
            ?>

            <div class="ika-fd-flightlog-layout">
                <!-- Summary -->
                <div class="ika-fd-flightlog-summary">
                    <div class="ika-fd-flightlog-summary__title">Recent Activity</div>
                    <div class="ika-fd-flightlog-statlist">
                        <?php foreach ( $summary as $s ) : ?>
                            <div class="ika-fd-flightlog-stat">
                                <span class="ika-fd-flightlog-label"><?php echo esc_html( $s['label'] ); ?>:</span>
                                <span><?php echo esc_html( $s['value'] ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Table -->
                <div class="ika-fd-flightlog-tablecard">
                    <div class="ika-fd-flightlog-controls">
                        <div class="ika-hub-section-kicker" style="margin:0;">Latest attempts</div>
                        <select class="ika-fd-flightlog-filter" disabled>
                            <option>All quizzes</option>
                            <option>Completed</option>
                            <option>In progress</option>
                            <option>Favorites</option>
                        </select>
                    </div>

                    <div class="ika-fd-table-scroll">
                        <table class="ika-fd-flightlog-table">
                            <thead>
                                <tr>
                                    <th>Quiz</th>
                                    <th>Last score</th>
                                    <th>Attempts</th>
                                    <th>XP</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $rows as $r ) : ?>
                                    <tr>
                                        <td class="ika-fd-flightlog-quizname">
                                            <a href="<?php echo esc_url( $r['url'] ); ?>"><?php echo esc_html( $r['quiz'] ); ?></a>
                                        </td>
                                        <td class="ika-fd-flightlog-score"><?php echo esc_html( $r['score'] ); ?></td>
                                        <td><?php echo esc_html( $r['attempts'] ); ?></td>
                                        <td class="ika-fd-flightlog-xp"><?php echo esc_html( $r['xp'] ); ?></td>
                                        <td>
                                            <span class="ika-fd-flightlog-status-badge <?php echo esc_attr( $r['status_class'] ); ?>">
                                                <?php echo esc_html( $r['status'] ); ?>
                                            </span>
                                        </td>
                                        <td class="ika-fd-flightlog-action">
                                            <a href="<?php echo esc_url( $r['action_url'] ); ?>" class="ika-hub-section-link"><?php echo esc_html( $r['action'] ); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
} );
