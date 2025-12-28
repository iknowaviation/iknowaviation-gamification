<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * Rank ladder + helpers (using your existing ladder)
 * ======================================================================*/

/**
 * Rank ladder: now driven primarily by WatuPRO Play levels.
 *
 * - If we can read levels from the watuproplay_levels table, use those.
 *   Each LEVEL row becomes a rank with:
 *     - label = name
 *     - slug  = sanitize_title( name )
 *     - min_xp = required_points
 *
 * - If the table is missing or empty, fall back to the original hard-coded ladder
 *   so nothing breaks.
 */
function ika_get_rank_ladder() {
    // Prefer WatuPRO Play levels if available.
    if ( function_exists( 'ika_watuproplay_get_level_thresholds' ) ) {
        $thresholds = ika_watuproplay_get_level_thresholds();

        if ( ! empty( $thresholds ) ) {
            $ladder = array();

            foreach ( $thresholds as $row ) {
                $name   = isset( $row['name'] ) ? $row['name'] : '';
                $min_xp = isset( $row['required_points'] ) ? (int) $row['required_points'] : 0;

                if ( '' === $name ) {
                    continue;
                }

                $ladder[] = array(
                    'slug'   => sanitize_title( $name ),
                    'label'  => $name,
                    'min_xp' => $min_xp,
                );
            }

            // Just to be safe, ensure it’s sorted by min_xp ascending.
            usort(
                $ladder,
                function ( $a, $b ) {
                    return $a['min_xp'] <=> $b['min_xp'];
                }
            );

            return $ladder;
        }
    }

	// ---- Fallback ladder (ONLY used if WatuPRO Play levels cannot be read) ----
	// This MUST match your locked IKA XP ladder (Phase 1 foundation):
	// 0, 50, 150, 300, 500, 800, 1200, 1700, 2500, 3500, 5000
	$ladder = array(
		array( 'slug' => 'aviation-enthusiast',     'label' => 'Aviation Enthusiast',     'min_xp' => 0 ),
		array( 'slug' => 'student-pilot',          'label' => 'Student Pilot',          'min_xp' => 50 ),
		array( 'slug' => 'sport-pilot',            'label' => 'Sport Pilot',            'min_xp' => 150 ),
		array( 'slug' => 'private-pilot',          'label' => 'Private Pilot',          'min_xp' => 300 ),
		array( 'slug' => 'instrument-rated',       'label' => 'Instrument Rated',       'min_xp' => 500 ),
		array( 'slug' => 'commercial-pilot',       'label' => 'Commercial Pilot',       'min_xp' => 800 ),
		array( 'slug' => 'airline-transport-pilot','label' => 'Airline Transport Pilot','min_xp' => 1200 ),
		array( 'slug' => 'airline-first-officer',  'label' => 'Airline First Officer',  'min_xp' => 1700 ),
		array( 'slug' => 'airline-captain',        'label' => 'Airline Captain',        'min_xp' => 2500 ),
		array( 'slug' => 'chief-pilot',            'label' => 'Chief Pilot',            'min_xp' => 3500 ),
		array( 'slug' => 'aviation-master',        'label' => 'Aviation Master',        'min_xp' => 5000 ),
	);

	// Defensive sort (prevents accidental out-of-order edits from breaking rank calc).
	usort(
		$ladder,
		function ( $a, $b ) {
			return $a['min_xp'] <=> $b['min_xp'];
		}
	);

	return $ladder;
}

/**
 * Rank for a given XP.
 */
function ika_get_rank_for_xp( $xp ) {
	$xp     = floatval( $xp );
	$ladder = ika_get_rank_ladder();
	$current = $ladder[0];

	foreach ( $ladder as $step ) {
		if ( $xp >= $step['min_xp'] ) {
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
		if ( $xp < $step['min_xp'] ) {
			return $step;
		}
	}
	return null;
}

/**
 * Convenience: XP + rank data for a user.
 */
function ika_get_user_xp_and_rank( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id ) return null;

	$xp   = floatval( get_user_meta( $user_id, 'ika_total_xp', true ) );
	$rank = ika_get_rank_for_xp( $xp );

	return array(
		'xp'         => intval( $xp ),
		'rank_slug'  => $rank['slug'],
		'rank_label' => $rank['label'],
	);
}

/* Give new users a starting rank (0 XP). */
add_action( 'user_register', function( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) return;

	$xp   = 0;
	$rank = ika_get_rank_for_xp( $xp );

	update_user_meta( $user_id, 'ika_total_xp',   $xp );
	update_user_meta( $user_id, 'ika_rank_slug',  $rank['slug'] );
	update_user_meta( $user_id, 'ika_rank_label', $rank['label'] );
} );


/* ======================================================================
 * Generic meta helpers
 * ======================================================================*/

function ika_get_user_meta_int( $meta_key, $default = 0, $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id ) {
		return (int) $default;
	}

	$value = get_user_meta( $user_id, $meta_key, true );
	if ( $value === '' || $value === null ) {
		return (int) $default;
	}

	return (int) $value;
}

function ika_get_user_meta_float( $meta_key, $default = 0.0, $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id ) {
		return (float) $default;
	}

	$value = get_user_meta( $user_id, $meta_key, true );
	if ( $value === '' || $value === null ) {
		return (float) $default;
	}

	return (float) $value;
}