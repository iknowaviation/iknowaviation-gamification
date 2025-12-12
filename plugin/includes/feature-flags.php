<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Feature Flags
 *
 * Storage order (highest priority first):
 * 1) wp-config.php constant IKA_GAM_FEATURE_FLAGS (array)
 * 2) WP option: ika_gam_feature_flags (array)
 * 3) defaults in ika_gam_feature_flags_defaults()
 */

/**
 * Defaults: set everything you consider "core" to true.
 * Flip to false for quickly disabling a subsystem.
 */
function ika_gam_feature_flags_defaults() {
    return array(
        'xp'          => true,
        'ranks'       => true,
        'streaks'     => true,
        'leaderboard' => true,
        'missions'    => true,
        'watuplay'    => true,  // badges/levels modal + avatar sync logic lives here
        'admin_tools' => true,  // debug panel visibility
    );
}

/** Get all flags (merged defaults + saved + constant override). */
function ika_gam_get_feature_flags() {
    $flags = ika_gam_feature_flags_defaults();

    $saved = get_option( 'ika_gam_feature_flags', array() );
    if ( is_array( $saved ) ) {
        $flags = array_merge( $flags, $saved );
    }

    // Optional hard override via wp-config.php
    if ( defined( 'IKA_GAM_FEATURE_FLAGS' ) && is_array( IKA_GAM_FEATURE_FLAGS ) ) {
        $flags = array_merge( $flags, IKA_GAM_FEATURE_FLAGS );
    }

    // Normalize to strict booleans
    foreach ( $flags as $k => $v ) {
        $flags[ $k ] = (bool) $v;
    }

    return $flags;
}

/** Check if a specific flag is enabled. */
function ika_gam_feature_enabled( $key ) {
    $flags = ika_gam_get_feature_flags();
    return isset( $flags[ $key ] ) ? (bool) $flags[ $key ] : false;
}

/** Update a single flag (stored in WP option). */
function ika_gam_set_feature_flag( $key, $enabled ) {
    $saved = get_option( 'ika_gam_feature_flags', array() );
    if ( ! is_array( $saved ) ) { $saved = array(); }

    $saved[ $key ] = (bool) $enabled;

    update_option( 'ika_gam_feature_flags', $saved, false );
}
