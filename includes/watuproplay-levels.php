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

    // Example table: wp_2cd0c0f1b0_watuproplay_levels
    $table = $wpdb->prefix . 'watuproplay_levels';

    // Make sure the table exists for this site.
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table
        )
    );

    if ( $exists !== $table ) {
        $cached = array();
        set_transient( 'ika_watuproplay_levels_v1', $cached, HOUR_IN_SECONDS );
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
	set_transient( 'ika_watuproplay_levels_v1', $rows, HOUR_IN_SECONDS );
        return $cached;

    return $rows;
}

/**
 * Utility: given the "content" column, try to find an image URL.
 * - If it's already a plain URL, return it.
 * - If it's HTML, try to extract the first <img src="..."> URL.
 * - Otherwise, return the original string.
 *
 * This lets you store either a bare URL or a full <img> tag in content.
 *
 * @param string $content
 * @return string
 */
function ika_watuproplay_extract_image_url( $content ) {
	$content = trim( (string) $content );
	if ( '' === $content ) return '';

	// If it's numeric, treat as attachment ID
	if ( ctype_digit( $content ) ) {
		$att = wp_get_attachment_url( (int) $content );
		return $att ? $att : '';
	}

	// If it's already an absolute URL, return it
	if ( filter_var( $content, FILTER_VALIDATE_URL ) ) {
		return $content;
	}

	// If HTML, extract first <img src="...">
	if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
		$src = trim( $m[1] );
		if ( '' === $src ) return '';

		// If protocol-relative
		if ( strpos( $src, '//' ) === 0 ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}

		// If relative path
		if ( strpos( $src, '/' ) === 0 ) {
			return home_url( $src );
		}

		// If missing scheme but looks like wp-content path
		if ( strpos( $src, 'wp-content/' ) === 0 ) {
			return home_url( '/' . $src );
		}

		// Otherwise return as-is
		return $src;
	}

	// If it's a relative path directly
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
            if ( '' === $name ) {
                continue;
            }
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
            if ( '' === $name ) {
                continue;
            }
            $levels[ $name ] = $row;
        }
    }

    return $levels;
}

/**
 * Return [ Badge Name => HTML Content ] from WATU Play levels table.
 */
function ika_watuproplay_get_badge_html_map() {
    global $wpdb;

    $table = $wpdb->prefix . 'watuproplay_levels';

    // Best effort: many installs use "is_badge" or type markers.
    // If your table has a specific column for badges, adjust the WHERE.
    $rows = $wpdb->get_results( "SELECT name, content FROM {$table}", ARRAY_A );
    if ( empty( $rows ) ) return array();

    $out = array();

    foreach ( $rows as $r ) {
        $name = isset( $r['name'] ) ? trim( (string) $r['name'] ) : '';
        if ( $name === '' ) continue;

        $html = isset( $r['content'] ) ? (string) $r['content'] : '';
        // Sanitize for front-end output (admin-controlled content)
        $html = wp_kses_post( $html );

        $out[ $name ] = $html;
    }

    return $out;
}

/**
 * Return [ Level Name => HTML Content ] from WATU Play levels table.
 * If your install differentiates levels vs badges, you can filter later.
 */
function ika_watuproplay_get_level_html_map() {
    // For now identical source; if you have a "type" column, we’ll filter.
    return ika_watuproplay_get_badge_html_map();
}

/**
 * (Optional for later) Get level thresholds in ascending order.
 * Can be used to drive the IKA rank ladder from WatuPRO Play.
 *
 * @return array[] Each: [ 'name' => string, 'required_points' => int, 'rank' => int ]
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

    // Sort by required_points ascending
    usort(
        $thresholds,
        function ( $a, $b ) {
            return $a['required_points'] <=> $b['required_points'];
        }
    );

    return $thresholds;
}

/**
 * Manually clear the cached copy of the WatuPRO Play levels table.
 * Handy if you change things often while tweaking.
 */
function ika_watuproplay_flush_cache() {
    delete_transient( 'ika_watuproplay_levels_v1' );
}
