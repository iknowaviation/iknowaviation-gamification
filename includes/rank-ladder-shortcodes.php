<?php
/**
 * Flight Deck – Rank Ladder Labels (Phase A2)
 * Shortcode: [ika_fd_rank_ladder_labels]
 *
 * Outputs <span>Label</span> elements for the FULL rank ladder.
 * Uses ika_get_rank_ladder() if available, otherwise falls back to
 * the Phase 1 ladder labels (locked).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_shortcode( 'ika_fd_rank_ladder_labels', function() {

    // Prefer a shared ladder function if it exists.
    if ( function_exists( 'ika_get_rank_ladder' ) ) {
        $ladder = ika_get_rank_ladder();
        if ( is_array( $ladder ) && ! empty( $ladder ) ) {
            $out = '';
            foreach ( $ladder as $r ) {
                $label = isset( $r['label'] ) ? $r['label'] : '';
                if ( $label === '' ) { continue; }
                $out .= '<span>' . esc_html( $label ) . '</span>';
            }
            if ( $out !== '' ) { return $out; }
        }
    }

    // Fallback: Phase 1 ladder (labels only).
    $labels = array(
        'Aviation Enthusiast',
        'Student Pilot',
        'Sport Pilot',
        'Private Pilot',
        'Instrument Rated',
        'Commercial Pilot',
        'Airline Transport Pilot',
        'Airline First Officer',
        'Airline Captain',
        'Chief Pilot',
        'Aviation Master',
    );

    $out = '';
    foreach ( $labels as $label ) {
        $out .= '<span>' . esc_html( $label ) . '</span>';
    }
    return $out;
} );
