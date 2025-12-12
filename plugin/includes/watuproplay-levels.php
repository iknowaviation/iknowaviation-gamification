<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * IKA Gamification – WatuPRO Play integration helpers
 *
 * Central place to read level + badge data from the WatuPRO Play table so
 * we don't have to maintain hard-coded arrays.
 */

/**
 * Fetch all rows from the WatuPRO Play levels table (badges + levels).
 *
 * Result is cached per request and via a transient for a short period
 * to avoid hitting the DB on every page view.
 *
 * @return array[]
 */
function ika_watuproplay_get_raw_levels_rows() {
    static $cached = null;

    if ( null !== $cached ) {
        return $cached;
    }

    $cached = get_transient( 'ika_watuproplay_levels_v1' );
    if ( false !== $cached && is_array( $cached ) ) {
        return $cached;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'watuproplay_levels';

    // Ensure table exists
    $exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
    );

    if ( $exists !== $table ) {
        $cached = array();
        set_transient( 'ika_watuproplay_levels_v1', $cached, HOUR_IN_SECONDS );
        return $cached;
    }

    $rows = $wpdb->get_results(
        "SELECT id, atype, name, content, required_points, rank
         FROM {$table}
         ORDER BY atype, required_points ASC, id ASC",
        ARRAY_A
    );

    if ( ! is_array( $rows ) ) {
        $rows = array();
    }

    $cached = $rows;
    set_transient( 'ika_watuproplay_levels_v1', $cached, HOUR_IN_SECONDS );

    return $cached;
}

/**
 * Utility: extract image URL from WatuPRO Play content column.
 *
 * @param string $content
 * @return string
 */
function ika_watuproplay_extract_image_url( $content ) {
    $content = trim( (string) $content );
    if ( '' === $content ) return '';

    if ( ctype_digit( $content ) ) {
        $att = wp_get_attachment_url( (int) $content );
        return $att ? $att : '';
    }

    if ( filter_var( $content, FILTER_VALIDATE_URL ) ) {
        return $content;
    }

    if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
        $src = trim( $m[1] );
        if ( '' === $src ) return '';

        if ( strpos( $src, '//' ) === 0 ) {
            return ( is_ssl() ? 'https:' : 'http:' ) . $src;
        }

        if ( strpos( $src, '/' ) === 0 ) {
            return home_url( $src );
        }

        if ( strpos( $src, 'wp-content/' ) === 0 ) {
            return home_url( '/' . $src );
        }

        return $src;
    }

    if ( strpos( $content, '/' ) === 0 ) {
        return home_url( $content );
    }

    if ( strpos( $content, 'wp-content/' ) === 0 ) {
        return home_url( '/' . $content );
    }

    return '';
}

/**
 * Get only BADGE rows keyed by name.
 *
 * @return array name => row
 */
function ika_watuproplay_get_badges() {
    $rows   = ika_watuproplay_get_raw_levels_rows();
    $badges = array();

    foreach ( $rows as $row ) {
        if ( isset( $row['atype'] ) && 'badge' === $row['atype'] ) {
            $name = isset( $row['name'] ) ? $row['name'] : '';
            if ( '' === $name ) continue;
            $badges[ $name ] = $row;
        }
    }

    return $badges;
}

/**
 * Get only LEVEL rows keyed by name.
 *
 * @return array name => row
 */
function ika_watuproplay_get_levels() {
    $rows   = ika_watuproplay_get_raw_levels_rows();
    $levels = array();

    foreach ( $rows as $row ) {
        if ( isset( $row['atype'] ) && 'level' === $row['atype'] ) {
            $name = isset( $row['name'] ) ? $row['name'] : '';
            if ( '' === $name ) continue;
            $levels[ $name ] = $row;
        }
    }

    return $levels;
}

/**
 * Return [ Badge Name => HTML Content ]
 * Defined ONLY if not already provided elsewhere.
 */
if ( ! function_exists( 'ika_watuproplay_get_badge_html_map' ) ) {
    function ika_watuproplay_get_badge_html_map() {
        if ( function_exists( 'ika_watuproplay_get_html_map_by_atype' ) ) {
            return ika_watuproplay_get_html_map_by_atype( 'badge' );
        }

        $rows = ika_watuproplay_get_raw_levels_rows();
        $out  = array();

        foreach ( $rows as $row ) {
            if ( isset( $row['atype'] ) && 'badge' !== $row['atype'] ) continue;
            $name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
            if ( $name === '' ) continue;

            $html = isset( $row['content'] ) ? (string) $row['content'] : '';
            $out[ $name ] = wp_kses_post( $html );
        }

        return $out;
    }
}

/**
 * Get level thresholds sorted by required points.
 *
 * @return array[]
 */
function ika_watuproplay_get_level_thresholds() {
    $levels     = ika_watuproplay_get_levels();
    $thresholds = array();

    foreach ( $levels as $name => $row ) {
        $thresholds[] = array(
            'name'            => $name,
            'required_points' => isset( $row['required_points'] ) ? (int) $row['required_points'] : 0,
            'rank'            => isset( $row['rank'] ) ? (int) $row['rank'] : 0,
        );
    }

    usort(
        $thresholds,
        function ( $a, $b ) {
            return $a['required_points'] <=> $b['required_points'];
        }
    );

    return $thresholds;
}

/**
 * Manually clear cached WatuPRO Play levels.
 */
function ika_watuproplay_flush_cache() {
    delete_transient( 'ika_watuproplay_levels_v1' );
}
