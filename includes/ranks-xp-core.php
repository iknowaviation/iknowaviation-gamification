<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Flush WP user/meta caches for a user.
 *
 * Why: Direct DB edits (phpMyAdmin) do not invalidate WP's object cache.
 * This helper forces user meta to reflect current DB state.
 */
if ( ! function_exists( 'ika_flush_user_cache' ) ) {
	function ika_flush_user_cache( int $user_id ) : void {
		if ( $user_id <= 0 ) return;
		// Clears user meta cache.
		clean_user_cache( $user_id );
		// Best-effort object cache deletes.
		wp_cache_delete( $user_id, 'user_meta' );
		wp_cache_delete( $user_id, 'users' );
		// If a persistent object cache is active, flush just this group if supported.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			@wp_cache_flush_group( 'user_meta' );
		}
	}
}

/* ======================================================================
 * Rank ladder + helpers (CANONICAL: IKA XP ladder)
 * ======================================================================*/

/**
 * Canonical IKA Rank Ladder (Phase 1 foundation)
 *
 * IMPORTANT:
 * - This ladder is the single source of truth for rank thresholds across the site.
 * - WatuPRO Play "levels" are treated as an ASSET LIBRARY ONLY (icons/names), not as threshold drivers.
 *
 * If you later want ladder management in WP Admin, add a dedicated IKA settings UI
 * that writes to an IKA-owned option/meta and update this function to read that.
 * Do NOT derive thresholds from Watu Play required_points.
 */
function ika_get_rank_ladder() {

    // Locked IKA XP ladder (Phase 1 foundation):
    // 0, 50, 150, 300, 500, 800, 1200, 1700, 2500, 3500, 5000
    $ladder = array(
        array( 'slug' => 'aviation-enthusiast',      'label' => 'Aviation Enthusiast',      'min_xp' => 0 ),
        array( 'slug' => 'student-pilot',           'label' => 'Student Pilot',           'min_xp' => 50 ),
        array( 'slug' => 'sport-pilot',             'label' => 'Sport Pilot',             'min_xp' => 150 ),
        array( 'slug' => 'private-pilot',           'label' => 'Private Pilot',           'min_xp' => 300 ),
        array( 'slug' => 'instrument-rated',        'label' => 'Instrument Rated',        'min_xp' => 500 ),
        array( 'slug' => 'commercial-pilot',        'label' => 'Commercial Pilot',        'min_xp' => 800 ),
        array( 'slug' => 'airline-transport-pilot', 'label' => 'Airline Transport Pilot', 'min_xp' => 1200 ),
        array( 'slug' => 'airline-first-officer',   'label' => 'Airline First Officer',   'min_xp' => 1700 ),
        array( 'slug' => 'airline-captain',         'label' => 'Airline Captain',         'min_xp' => 2500 ),
        array( 'slug' => 'chief-pilot',             'label' => 'Chief Pilot',             'min_xp' => 3500 ),
        array( 'slug' => 'aviation-master',         'label' => 'Aviation Master',         'min_xp' => 5000 ),
    );

    // Allow future extension without editing core (but keep IKA-owned sources only).
    $ladder = apply_filters( 'ika_rank_ladder', $ladder );

    // Defensive sort (prevents accidental out-of-order edits from breaking rank calc).
    usort(
        $ladder,
        function ( $a, $b ) {
            return (int)($a['min_xp'] ?? 0) <=> (int)($b['min_xp'] ?? 0);
        }
    );

    return $ladder;
}

/**
 * Rank for a given XP.
 */
function ika_get_rank_for_xp( $xp ) {
    $xp      = floatval( $xp );
    $ladder  = ika_get_rank_ladder();
    $current = $ladder[0];

    foreach ( $ladder as $step ) {
        if ( $xp >= (float)$step['min_xp'] ) {
            $current = $step;
        } else {
            break;
        }
    }
    return $current;
}

/**
 * Next rank after current XP (or null for top rank).
 */
function ika_get_next_rank_for_xp( $xp ) {
    $xp     = floatval( $xp );
    $ladder = ika_get_rank_ladder();
    foreach ( $ladder as $step ) {
        if ( $xp < (float)$step['min_xp'] ) {
            return $step;
        }
    }
    return null;
}

/**
 * Convenience: XP + rank data for a user.
 */
function ika_get_user_xp_and_rank( $user_id = 0 ) {
    $user_id = $user_id ? (int) $user_id : get_current_user_id();

    // Single source of truth for XP:
    // - quiz XP: SUM(xp) from IKA XP Ledger
    // - bonus XP: ika_total_xp_bonus (missions/promos)
    // Cached combined total is stored as ika_total_xp.
    $xp = (float) ika_get_total_xp_canonical( $user_id );

    $rank = ika_get_rank_for_xp( $xp );
    $next = ika_get_next_rank_for_xp( $xp );

    return array(
        'user_id' => $user_id,
        'xp'      => $xp,
        'rank'    => $rank,
        'next'    => $next,
    );
}

/* ======================================================================
 * Canonical XP helpers
 * ======================================================================*/

/**
 * Canonical Total XP getter.
 *
 * IMPORTANT:
 * - Do NOT read legacy keys (ika_xp_total, watuproplay-points, watupro_total_points, etc.)
 * - The only truth is: SUM(ika_xp_ledger.xp for quiz_attempt) + ika_total_xp_bonus
 * - We cache totals to ika_total_xp / ika_total_xp_quiz for performance.
 */
if ( ! function_exists( 'ika_get_total_xp_canonical' ) ) {
function ika_get_total_xp_canonical( $user_id = 0, $force_recompute = false ) : int {
    $user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
    if ( $user_id <= 0 ) {
        return 0;
    }

    // Contract v1.0: Ledger is the only source of truth.
    // We recompute from the ledger to avoid stale meta after direct DB edits/resets.
    $total_xp = 0;
    $quiz_xp_total = 0;
    $bonus_xp = 0;

    if ( function_exists( 'ika_xp_ledger_table' ) ) {
        global $wpdb;
        $ledger_table = ika_xp_ledger_table();

        $total_xp = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(xp),0) FROM {$ledger_table} WHERE user_id = %d",
            $user_id
        ) );

        $quiz_xp_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(xp),0) FROM {$ledger_table} WHERE user_id = %d AND source = %s",
            $user_id,
            'quiz_attempt'
        ) );

        $bonus_xp = max( 0, (int) $total_xp - (int) $quiz_xp_total );
    } else {
        // Fallback: legacy cache (should be rare).
        $total_xp = (int) get_user_meta( $user_id, 'ika_total_xp', true );
        $quiz_xp_total = (int) get_user_meta( $user_id, 'ika_total_xp_quiz', true );
        $bonus_xp = max( 0, (int) get_user_meta( $user_id, 'ika_total_xp_bonus', true ) );
    }

    $total_xp = max( 0, (int) $total_xp );

    // Keep cache/meta in sync with ledger for other UI consumers.
    update_user_meta( $user_id, 'ika_total_xp_quiz', (int) $quiz_xp_total );
    update_user_meta( $user_id, 'ika_total_xp_bonus', (int) $bonus_xp );
    update_user_meta( $user_id, 'ika_total_xp', (int) $total_xp );

    return (int) $total_xp;
}
}
