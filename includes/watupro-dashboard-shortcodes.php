<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * iKnowAviation – WatuPRO dashboard stat shortcodes
 *
 * Shortcodes:
 *  [ika_quizzes_completed]
 *  [ika_total_attempts]
 *  [ika_avg_score]
 *  [ika_best_score]
 */

/**
 * Helper to resolve WatuPRO table names safely.
 */
if ( ! function_exists( 'ika_watupro_table' ) ) {
    function ika_watupro_table( $const_name, $fallback_suffix ) {
        global $wpdb;

        if ( defined( $const_name ) ) {
            return constant( $const_name );
        }

        return $wpdb->prefix . $fallback_suffix;
    }
}

/**
 * 1. Quizzes Completed – number of distinct quizzes the user has completed.
 *
 * Usage: [ika_quizzes_completed]
 */
function ika_sc_quizzes_completed() {
    if ( ! is_user_logged_in() ) {
        return '0';
    }

    global $wpdb;
    $user_id     = get_current_user_id();
    $takings_tbl = ika_watupro_table( 'WATUPRO_TAKEN_EXAMS', 'watupro_taken_exams' );

    // exam_id, user_id, in_progress are the usual columns in WatuPRO
    $sql   = "SELECT COUNT(DISTINCT exam_id)
              FROM {$takings_tbl}
              WHERE user_id = %d
                AND (in_progress = 0 OR in_progress IS NULL)";
    $count = $wpdb->get_var( $wpdb->prepare( $sql, $user_id ) );

    return intval( $count );
}
add_shortcode( 'ika_quizzes_completed', 'ika_sc_quizzes_completed' );

/**
 * 2. Total Attempts – total completed attempts (all quizzes).
 *
 * Usage: [ika_total_attempts]
 */
function ika_sc_total_attempts() {
    if ( ! is_user_logged_in() ) {
        return '0';
    }

    global $wpdb;
    $user_id     = get_current_user_id();
    $takings_tbl = ika_watupro_table( 'WATUPRO_TAKEN_EXAMS', 'watupro_taken_exams' );

    $sql   = "SELECT COUNT(*)
              FROM {$takings_tbl}
              WHERE user_id = %d
                AND (in_progress = 0 OR in_progress IS NULL)";
    $count = $wpdb->get_var( $wpdb->prepare( $sql, $user_id ) );

    return intval( $count );
}
add_shortcode( 'ika_total_attempts', 'ika_sc_total_attempts' );

/**
 * 3. Average Score – average percent correct across completed attempts.
 *
 * IMPORTANT: The column name here assumes `percent_correct`.
 * If your table uses a different name (e.g. `percent`), change it below.
 *
 * Usage: [ika_avg_score]
 */
function ika_sc_avg_score() {
    if ( ! is_user_logged_in() ) {
        return '0%';
    }

    global $wpdb;
    $user_id     = get_current_user_id();
    $takings_tbl = ika_watupro_table( 'WATUPRO_TAKEN_EXAMS', 'watupro_taken_exams' );

    $sql = "SELECT AVG(percent_correct)
            FROM {$takings_tbl}
            WHERE user_id = %d
              AND (in_progress = 0 OR in_progress IS NULL)";
    $avg = $wpdb->get_var( $wpdb->prepare( $sql, $user_id ) );

    if ( $avg === null ) {
        $avg = 0;
    }

    $avg_rounded = round( floatval( $avg ) );

    return $avg_rounded . '%';
}
add_shortcode( 'ika_avg_score', 'ika_sc_avg_score' );

/**
 * 4. Best Score – highest percent correct on any attempt.
 *
 * Usage: [ika_best_score]
 */
function ika_sc_best_score() {
    if ( ! is_user_logged_in() ) {
        return '0%';
    }

    global $wpdb;
    $user_id     = get_current_user_id();
    $takings_tbl = ika_watupro_table( 'WATUPRO_TAKEN_EXAMS', 'watupro_taken_exams' );

    $sql  = "SELECT MAX(percent_correct)
             FROM {$takings_tbl}
             WHERE user_id = %d
               AND (in_progress = 0 OR in_progress IS NULL)";
    $best = $wpdb->get_var( $wpdb->prepare( $sql, $user_id ) );

    if ( $best === null ) {
        $best = 0;
    }

    return round( floatval( $best ) ) . '%';
}
add_shortcode( 'ika_best_score', 'ika_sc_best_score' );

