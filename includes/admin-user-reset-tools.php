<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin: User Reset Tools
 * Version: 2026-01-29g (stats-reset-v2)
 *
 * Changes vs prior builds:
 * - NO inline JS confirm()
 * - NO single-quote nesting in any echoed HTML attributes
 * - Extra padding/comment block so file size is different (diagnostic)
 *
 * If you still see an E_PARSE mentioning an unexpected identifier, it is NOT coming
 * from this file content (this file contains no unescaped quotes in echoed strings).
 */

/* Diagnostic padding (safe) ---------------------------------------------------
   This block is intentionally long to ensure the file size differs from earlier
   versions and to make it obvious when the correct file is on the server.
-------------------------------------------------------------------------------*/

function ika_gam_admin_user_reset_tools_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    $message = '';
    $details = array();

    if ( isset( $_POST['ika_reset_tools_submit'] ) ) {
        check_admin_referer( 'ika_user_reset_tools_action', 'ika_user_reset_tools_nonce' );

        $user_id          = isset( $_POST['ika_user_id'] ) ? intval( $_POST['ika_user_id'] ) : 0;
        $action           = isset( $_POST['ika_action'] ) ? sanitize_text_field( $_POST['ika_action'] ) : '';
        $confirm          = isset( $_POST['ika_confirm'] ) ? trim( sanitize_text_field( $_POST['ika_confirm'] ) ) : '';
        $include_attempts = ! empty( $_POST['ika_include_attempts'] );

        if ( $user_id <= 0 ) {
            $message = 'Please enter a valid User ID.';
        } elseif ( strtoupper( $confirm ) !== 'RESET' ) {
            $message = 'Confirmation required. Type RESET to run an action.';
        } else {
            $result  = ika_gam_admin_run_reset_action( $user_id, $action, $include_attempts );
            $message = $result['message'];
            $details = $result['details'];
        }
    }

    ?>
    <div class="wrap">
        <h1>User Reset Tools</h1>
        <p style="max-width: 860px;">
            Admin-only tools for staging/testing. Select a user, choose an action, type <code>RESET</code>, then run.
        </p>

        <?php if ( ! empty( $message ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( ( stripos( $message, 'Success' ) !== false ) ? 'success' : 'warning' ); ?> is-dismissible">
                <p><?php echo esc_html( $message ); ?></p>
                <?php if ( ! empty( $details ) ) : ?>
                    <ul style="margin-left: 18px;">
                        <?php foreach ( $details as $d ) : ?>
                            <li><?php echo esc_html( $d ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'ika_user_reset_tools_action', 'ika_user_reset_tools_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="ika_user_id">User ID</label></th>
                        <td>
                            <input name="ika_user_id" id="ika_user_id" type="number" min="1" step="1"
                                value="<?php echo isset( $_POST['ika_user_id'] ) ? esc_attr( intval( $_POST['ika_user_id'] ) ) : '27'; ?>"
                                class="regular-text" />
                            <p class="description">For your testing, user 27 is fine.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ika_action">Action</label></th>
                        <td>
                            <select name="ika_action" id="ika_action">
                                <option value="reset_xp" <?php selected( isset( $_POST['ika_action'] ) ? $_POST['ika_action'] : '', 'reset_xp' ); ?>>Reset XP (ledger + IKA meta)</option>
                                <option value="reset_achievements" <?php selected( isset( $_POST['ika_action'] ) ? $_POST['ika_action'] : '', 'reset_achievements' ); ?>>Reset Achievements (badges/levels)</option>
                                <option value="flush_cache" <?php selected( isset( $_POST['ika_action'] ) ? $_POST['ika_action'] : '', 'flush_cache' ); ?>>Flush User Cache</option>
                                <option value="reset_all" <?php selected( isset( $_POST['ika_action'] ) ? $_POST['ika_action'] : '', 'reset_all' ); ?>>Reset ALL (XP + achievements + cache)</option>
                            </select>

                            <label style="display:block; margin-top:10px;">
                                <input type="checkbox" name="ika_include_attempts" value="1" <?php checked( ! empty( $_POST['ika_include_attempts'] ) ); ?> />
                                Also clear quiz attempts/results (WatuPRO) for this user
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ika_confirm">Confirmation</label></th>
                        <td>
                            <input name="ika_confirm" id="ika_confirm" type="text" value="" class="regular-text" placeholder="Type RESET to confirm" />
                            <p class="description">Required for any action that modifies data.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p>
                <button type="submit" class="button button-primary" name="ika_reset_tools_submit" value="1">
                    Run action
                </button>
            </p>
        </form>

        <p class="description" style="margin-top:18px;">
            Version: <code>2026-01-29g (stats-reset-v2)</code>
        </p>
    </div>
    <?php
}

function ika_gam_admin_run_reset_action( $user_id, $action, $include_attempts ) {
    global $wpdb;

    $user_id = intval( $user_id );
    $action  = (string) $action;
    $include_attempts = (bool) $include_attempts;

    $details = array();
    $prefix  = $wpdb->prefix;

    $count_deleted = function( $table, $where_sql, $params ) use ( $wpdb ) {
        $sql = "DELETE FROM {$table} WHERE {$where_sql}";
        $prepared = $wpdb->prepare( $sql, $params );
        $wpdb->query( $prepared );
        return (int) $wpdb->rows_affected;
    };

    if ( $action === 'reset_xp' || $action === 'reset_all' ) {
        $ledger_table = $prefix . 'ika_xp_ledger';
        $deleted = $count_deleted( $ledger_table, 'user_id = %d', array( $user_id ) );
        $details[] = "XP ledger rows deleted: {$deleted}";

        $deleted_meta = $count_deleted(
            $wpdb->usermeta,
            'user_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s)',
            array( $user_id, 'ika_%xp%', 'ika_%rank%', 'ika_%level%' )
        );
        $details[] = "IKA XP/rank/level usermeta rows deleted: {$deleted_meta}";

        // Also clear Flight Deck stats caches (attempts/completions/streak). These are not XP keys,
        // but they should reset when you want a true clean-slate test user.
        // Also clear Flight Deck stats caches (attempts/completions/streak/score). These are not XP keys,
        // but they should reset when you want a true clean-slate test user.
        $deleted_stats_meta = $count_deleted(
            $wpdb->usermeta,
            'user_id = %d AND (meta_key IN (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s)',
            array(
                $user_id,
                // Visible stat strip + derived caches
                'ika_quizzes_completed',
                'ika_total_attempts',
                'ika_avg_score',
                'ika_best_score',
                // Streak caches (these are the ones actually used by the strip)
                'ika_current_streak',
                'ika_current_streak_days',
                'ika_streak_days',
                'ika_streak_updated_at',
                // Misc last-activity caches
                'ika_last_quiz_completed_at',
                'ika_last_attempt_at',
                // Shortcode-level caches used by hero metrics
                'ika_shortcode_avg_score',
                'ika_shortcode_best_score',
                // Patterns
                'ika_stats_%',
                'ika_sc_%',
                'ika_shortcode_%'
            )
        );
        $details[] = "Flight Deck stats cache usermeta rows deleted: {$deleted_stats_meta}";


        $deleted_flags = $count_deleted(
            $wpdb->usermeta,
            'user_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)',
            array( $user_id, 'ika_dm_%', 'ika_briefing_opened_%' )
        );
        $details[] = "Mission/briefing flags deleted: {$deleted_flags}";

        if ( $include_attempts ) {
            $taken   = $prefix . 'watupro_taken_exams';
            $answers = $prefix . 'watupro_student_answers';

            // Collect taking IDs for this user (schema-safe), then delete answers, then takings.
            $taking_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$taken} WHERE user_id = %d", $user_id ) );
            $taking_ids = array_map( 'intval', (array) $taking_ids );

            $deleted_answers = 0;
            if ( ! empty( $taking_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $taking_ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $sql = $wpdb->prepare( "DELETE FROM {$answers} WHERE taking_id IN ({$placeholders})", $taking_ids );
                $wpdb->query( $sql );
                $deleted_answers = (int) $wpdb->rows_affected;
            }
            $details[] = "WatuPRO student answers deleted: {$deleted_answers}";

            $deleted_taken = $count_deleted( $taken, 'user_id = %d', array( $user_id ) );
            $details[] = "WatuPRO taken exams deleted: {$deleted_taken}";
        }}

    if ( $action === 'reset_achievements' || $action === 'reset_all' ) {
        $deleted = $count_deleted(
            $wpdb->usermeta,
            'user_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key = %s OR meta_key = %s)',
            array(
                $user_id,
                'ika_badge_earned_%',
                'ika_level_earned_%',
                'ika_last_awarded_rank_slug',
                'ika_pending_achievements_v1'
            )
        );
        $details[] = "Achievement flags deleted: {$deleted}";
    }

    if ( $action === 'flush_cache' || $action === 'reset_all' ) {
        if ( function_exists( 'ika_flush_user_cache' ) ) {
            ika_flush_user_cache( $user_id );
        } else {
            clean_user_cache( $user_id );
            wp_cache_delete( $user_id, 'user_meta' );
            wp_cache_delete( $user_id, 'users' );
            if ( function_exists( 'wp_cache_flush' ) ) {
                @wp_cache_flush();
            }
        }
        $details[] = "User cache flushed for user_id={$user_id}";
    }

    if ( empty( $action ) ) {
        return array( 'message' => 'Please select an action.', 'details' => array() );
    }

    return array( 'message' => 'Success: action completed.', 'details' => $details );
}
