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

    // ---- Fallback: your existing static ladder (keep this exactly as it was) ----

    return array(
        array(
            'slug'   => 'aviation-enthusiast',
            'label'  => 'Aviation Enthusiast',
            'min_xp' => 0,
        ),
        array(
            'slug'   => 'student-pilot',
            'label'  => 'Student Pilot',
            'min_xp' => 18,
        ),
        array(
            'slug'   => 'private-pilot',
            'label'  => 'Private Pilot',
            'min_xp' => 25,
        ),
        array(
            'slug'   => 'instrument-rated-pilot',
            'label'  => 'Instrument-Rated Pilot',
            'min_xp' => 27,
        ),
        array(
            'slug'   => 'commercial-pilot',
            'label'  => 'Commercial Pilot',
            'min_xp' => 29,
        ),
        array(
            'slug'   => 'sport-pilot',
            'label'  => 'Sport Pilot',
            'min_xp' => 23,
        ),
        array(
            'slug'   => 'airline-transport-pilot',
            'label'  => 'Airline Transport Pilot',
            'min_xp' => 31,
        ),
        array(
            'slug'   => 'captain',
            'label'  => 'Captain',
            'min_xp' => 33,
        ),
    );
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