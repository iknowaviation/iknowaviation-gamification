<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * IKA XP Ledger (Phase 2)
 *
 * Purpose:
 * - Decouple IKA XP from WatuPRO quiz scoring "points".
 * - Store per-attempt XP (keyed by Watu taking_id) so the Results page can
 *   show true XP earned for that attempt.
 *
 * XP Rules (locked):
 * - 10 XP per correct question
 * - +10 XP bonus if score >= 90%
 * - +10 XP bonus if first attempt pass (>= 70% and no prior completed attempts for that exam)
 *
 * Notes:
 * - We install the table on plugins_loaded via dbDelta.
 * - Awards are idempotent via UNIQUE(taking_id, source).
 * ======================================================================*/

if ( ! function_exists( 'ika_xp_ledger_table' ) ) {
function ika_xp_ledger_table() {
    global $wpdb;
    return $wpdb->prefix . 'ika_xp_ledger';
}
}

if ( ! function_exists( 'ika_xp_ledger_install' ) ) {
function ika_xp_ledger_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = ika_xp_ledger_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        exam_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        taking_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        xp INT NOT NULL DEFAULT 0,
        source VARCHAR(50) NOT NULL DEFAULT 'quiz_attempt',
        meta LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY taking_unique (taking_id, source),
        KEY user_idx (user_id),
        KEY exam_idx (exam_id),
        KEY created_idx (created_at)
    ) {$charset_collate};";

    dbDelta( $sql );
}
}
add_action( 'plugins_loaded', 'ika_xp_ledger_install', 20 );

/**
 * Get true IKA XP for a given taking_id.
 */
if ( ! function_exists( 'ika_xp_for_taking' ) ) {
function ika_xp_for_taking( int $taking_id ) : int {
    global $wpdb;
    if ( $taking_id <= 0 ) {
        return 0;
    }

    $table = ika_xp_ledger_table();
    $xp = $wpdb->get_var( $wpdb->prepare(
        "SELECT xp FROM {$table} WHERE taking_id = %d AND source = %s LIMIT 1",
        $taking_id,
        'quiz_attempt'
    ) );

    return (int) $xp;
}
}

/**
 * Calculate XP for a Watu attempt.
 *
 * IMPORTANT:
 * We prefer Watu's stored per-attempt counts (num_correct/num_wrong/num_empty),
 * because question types like multi-answer can produce multiple student_answer rows.
 */
if ( ! function_exists( 'ika_calc_xp_for_taking' ) ) {
function ika_calc_xp_for_taking( int $taking_id ) : array {
    global $wpdb;

    $taken   = $wpdb->prefix . 'watupro_taken_exams';
    $answers = $wpdb->prefix . 'watupro_student_answers';

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT ID, user_id, exam_id, percent_correct, num_correct, num_wrong, num_empty, in_progress, ignore_attempt
         FROM {$taken}
         WHERE ID = %d
         LIMIT 1",
        $taking_id
    ) );

    if ( ! $row || empty( $row->user_id ) ) {
        return array( 'xp' => 0, 'meta' => array( 'reason' => 'no_row' ) );
    }

    $user_id = (int) $row->user_id;
    $exam_id = (int) $row->exam_id;
    $percent = isset( $row->percent_correct ) ? (float) $row->percent_correct : 0.0;

    // Preferred: Watu stored correct/wrong/empty per attempt.
    $correct = isset( $row->num_correct ) ? absint( $row->num_correct ) : 0;
    $wrong   = isset( $row->num_wrong ) ? absint( $row->num_wrong ) : 0;
    $empty   = isset( $row->num_empty ) ? absint( $row->num_empty ) : 0;
    $total_questions = $correct + $wrong + $empty;

    // Fallback: if counts are zero/unavailable, infer total_questions from student_answers.
    if ( $total_questions <= 0 ) {
        $total_questions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT question_id) FROM {$answers} WHERE taking_id = %d",
            $taking_id
        ) );

        if ( $total_questions > 0 ) {
            $correct = (int) round( ( $percent / 100.0 ) * $total_questions );
        }
    }

    $xp_per_correct = 10;
    $xp_base = $correct * $xp_per_correct;

    $bonus_90 = ( $percent >= 90.0 ) ? 10 : 0;

    // First attempt pass bonus:
    // - percent >= 70
    // - no prior completed attempts for this exam/user (ignoring in_progress and ignored attempts)
    $bonus_first_pass = 0;
    if ( $percent >= 70.0 ) {
        $prior = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(1)
             FROM {$taken}
             WHERE user_id = %d
               AND exam_id = %d
               AND ID <> %d
               AND (ignore_attempt IS NULL OR ignore_attempt = 0)
               AND (in_progress IS NULL OR in_progress = 0)",
            $user_id, $exam_id, $taking_id
        ) );

        if ( $prior === 0 ) {
            $bonus_first_pass = 10;
        }
    }

    $xp_total = (int) max( 0, $xp_base + $bonus_90 + $bonus_first_pass );

    return array(
        'xp' => $xp_total,
        'meta' => array(
            'user_id' => $user_id,
            'exam_id' => $exam_id,
            'taking_id' => $taking_id,
            'percent' => $percent,
            'total_questions' => $total_questions,
            'correct' => $correct,
            'wrong' => $wrong,
            'empty' => $empty,
            'xp_per_correct' => $xp_per_correct,
            'xp_base' => $xp_base,
            'bonus_90' => $bonus_90,
            'bonus_first_attempt_pass' => $bonus_first_pass,
        ),
    );
}
}

/**
 * Award XP on quiz completion.
 *
 * IMPORTANT:
 * We insert a ledger row even when XP == 0 so the Results page never falls back
 * to Watu "points". This keeps results deterministic.
 */
if ( ! function_exists( 'ika_award_xp_on_watupro_completed_exam' ) ) {
function ika_award_xp_on_watupro_completed_exam( $taking_id ) {
    $taking_id = (int) $taking_id;
    if ( $taking_id <= 0 ) {
        return;
    }

    $calc = ika_calc_xp_for_taking( $taking_id );
    $xp   = isset( $calc['xp'] ) ? (int) $calc['xp'] : 0;

    $user_id = (int) ( $calc['meta']['user_id'] ?? 0 );
    $exam_id = (int) ( $calc['meta']['exam_id'] ?? 0 );

    if ( $user_id <= 0 ) {
        return;
    }

    global $wpdb;
    $table = ika_xp_ledger_table();

    $meta_json = wp_json_encode( $calc['meta'] );

    // Idempotent: INSERT IGNORE relies on UNIQUE(taking_id, source)
    $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$table} (user_id, exam_id, taking_id, xp, source, meta)
         VALUES (%d, %d, %d, %d, %s, %s)",
        $user_id,
        $exam_id,
        $taking_id,
        $xp,
        'quiz_attempt',
        $meta_json
    ) );

    // Rebuild user stats after awarding (this updates ika_total_xp, rank meta, etc.).
    $old_xp = (float) get_user_meta( $user_id, 'ika_total_xp', true );
    if ( ! is_numeric( $old_xp ) ) { $old_xp = 0; }

    if ( function_exists( 'ika_rebuild_stats_for_user' ) ) {
        ika_rebuild_stats_for_user( $user_id );
    }

    // After stats rebuild, run Option C achievements awarding (levels + badges) driven by IKA XP.
    $new_xp = (float) get_user_meta( $user_id, 'ika_total_xp', true );
    if ( ! is_numeric( $new_xp ) ) { $new_xp = 0; }

    if ( function_exists( 'ika_ach_process_awards_after_xp_change' ) ) {
        ika_ach_process_awards_after_xp_change( $user_id, $old_xp, $new_xp, array(
            'taking_id' => $taking_id,
            'exam_id'   => $exam_id,
            'source'    => 'quiz_attempt',
        ) );
    }}
}
add_action( 'watupro_completed_exam', 'ika_award_xp_on_watupro_completed_exam', 15 );


/* ======================================================================
 * Canonical XP totals (single source of truth)
 * ======================================================================*/

if ( ! function_exists( 'ika_get_total_xp_ledger_sum' ) ) {
/**
 * Sum XP from the IKA XP ledger.
 *
 * @param int $user_id
 * @param int|null $days Optional window (e.g. 7 for last 7 days). Null = all time.
 * @return int
 */
function ika_get_total_xp_ledger_sum( $user_id, $days = null ) {
    global $wpdb;
    $user_id = (int) $user_id;
    $table = ika_xp_ledger_table();

    if ( $days !== null ) {
        $days = (int) $days;
        // created_at is DATETIME (UTC/server time). Use DATE_SUB(NOW()) window.
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(xp),0) FROM {$table} WHERE user_id = %d AND created_at >= (NOW() - INTERVAL %d DAY)",
            $user_id,
            $days
        );
        return (int) $wpdb->get_var( $sql );
    }

    $sql = $wpdb->prepare(
        "SELECT COALESCE(SUM(xp),0) FROM {$table} WHERE user_id = %d",
        $user_id
    );
    return (int) $wpdb->get_var( $sql );
}
}

if ( ! function_exists( 'ika_get_total_xp_canonical' ) ) {
/**
 * Canonical total XP for a user.
 *
 * IMPORTANT (2026-01): XP is derived ONLY from ika_xp_ledger.
 * Do not add usermeta-based bonus totals here; all bonuses must be written as ledger rows
 * (e.g., source = mission_bonus) so weekly/all-time are consistent everywhere.
 *
 * @param int $user_id
 * @return int
 */
function ika_get_total_xp_canonical( $user_id ) {
    return ika_get_total_xp_ledger_sum( (int) $user_id, null );
}
}

if ( ! function_exists( 'ika_leaderboard_cache_bump' ) ) {
/**
 * Bump leaderboard cache version so UI is never "one quiz behind".
 */
function ika_leaderboard_cache_bump() {
    $v = (int) get_option( 'ika_lb_cache_v', 1 );
    update_option( 'ika_lb_cache_v', $v + 1, false );
}
}


if ( ! function_exists( 'ika_get_xp_breakdown_from_ledger' ) ) {
/**
 * Return quiz/bonus/total XP from ledger.
 *
 * @param int $user_id
 * @param int|null $days Optional window.
 * @return array{quiz:int, bonus:int, total:int}
 */
function ika_get_xp_breakdown_from_ledger( $user_id, $days = null ) {
    global $wpdb;
    $user_id = (int) $user_id;
    $table = ika_xp_ledger_table();

    $where_window = '';
    $params = array( $user_id );
    if ( $days !== null ) {
        $days = (int) $days;
        $where_window = " AND created_at >= (NOW() - INTERVAL %d DAY)";
        $params[] = $days;
    }

    // quiz XP
    $sql_quiz = "SELECT COALESCE(SUM(xp),0) FROM {$table} WHERE user_id = %d AND source = 'quiz_attempt'{$where_window}";
    $quiz = (int) $wpdb->get_var( $wpdb->prepare( $sql_quiz, ...$params ) );

    // bonus XP = everything else
    $sql_bonus = "SELECT COALESCE(SUM(xp),0) FROM {$table} WHERE user_id = %d AND source <> 'quiz_attempt'{$where_window}";
    $bonus = (int) $wpdb->get_var( $wpdb->prepare( $sql_bonus, ...$params ) );

    return array(
        'quiz'  => $quiz,
        'bonus' => $bonus,
        'total' => $quiz + $bonus,
    );
}
}

