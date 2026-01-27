<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * IKA Gamification – WatuPRO Play integration helpers
 *
 * Watu Play is treated as an ASSET LIBRARY ONLY (levels + badges icons/graphics).
 * IKA owns awarding logic.
 */

/**
 * Normalize atype across WatuPlay versions/data.
 */
function ika_watuproplay_normalize_atype( $atype ) {
    $atype = strtolower( trim( (string) $atype ) );
    if ( $atype === 'levels' ) $atype = 'level';
    if ( $atype === 'badges' ) $atype = 'badge';
    // Some imports store 'Level'/'Badge'
    if ( $atype === 'level' || $atype === 'badge' ) return $atype;
    return $atype;
}

/**
 * Fetch all rows from the WatuPRO Play levels table (badges + levels).
 * Cached per request + transient.
 *
 * @return array[]
 */
function ika_watuproplay_get_raw_levels_rows() {
    static $cached = null;

    if ( null !== $cached ) {
        return $cached;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'watuproplay_levels';

    // Verify table existence first.
    $exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
    );

    if ( $exists !== $table ) {
        $cached = array();
        set_transient( 'ika_watuproplay_levels_v1', $cached, 5 * MINUTE_IN_SECONDS );
        return $cached;
    }

    // Try transient cache (allow empty array caching too).
    $t = get_transient( 'ika_watuproplay_levels_v1' );
    if ( false !== $t && is_array( $t ) ) {
        $cached = $t;
        return $cached;
    }

    // Watu Play schema varies — SELECT * defensively.
    $rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
    if ( ! is_array( $rows ) ) {
        $rows = array();
    }

    // Normalize keys we rely on (ensure expected keys exist).
    foreach ( $rows as &$row ) {
        if ( ! is_array( $row ) ) continue;

        $row['id']            = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $row['atype']         = $row['atype'] ?? ( $row['a_type'] ?? ( $row['type'] ?? '' ) );
        $row['atype']         = ika_watuproplay_normalize_atype( $row['atype'] );
        $row['name']          = $row['name'] ?? ( $row['title'] ?? '' );

        // Common image fields across versions.
        $row['badge_graphic'] = $row['badge_graphic'] ?? ( $row['graphic'] ?? ( $row['image'] ?? ( $row['icon'] ?? '' ) ) );

        // Some installs store full markup in `content` (including <img> tag).
        $row['content']       = $row['content'] ?? ( $row['html'] ?? ( $row['description'] ?? '' ) );
    }
    unset( $row );

    $cached = $rows;
    set_transient( 'ika_watuproplay_levels_v1', $cached, 5 * MINUTE_IN_SECONDS );

    return $cached;
}

/**
 * Return a normalized "assets" array for use across the plugin.
 *
 * Each item includes: id, atype (level/badge), name, content, badge_graphic
 *
 * @return array[]
 */
function ika_watuproplay_get_all_assets() {
    $rows = ika_watuproplay_get_raw_levels_rows();
    if ( ! is_array( $rows ) ) return array();

    $assets = array();
    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) ) continue;
        $assets[] = array(
            'id'           => (int) ( $row['id'] ?? 0 ),
            'atype'        => ika_watuproplay_normalize_atype( $row['atype'] ?? '' ),
            'name'         => (string) ( $row['name'] ?? '' ),
            'content'      => (string) ( $row['content'] ?? '' ),
            'badge_graphic'=> (string) ( $row['badge_graphic'] ?? '' ),
        );
    }
    return $assets;
}

/**
 * Clear cached Watu Play assets.
 */
function ika_watuproplay_clear_asset_cache() {
    delete_transient( 'ika_watuproplay_levels_v1' );
}
