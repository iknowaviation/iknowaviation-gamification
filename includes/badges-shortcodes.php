<?php
/**
 * Flight Deck – Badges (Preview) Shortcodes
 *
 * Phase 1 (safe shell):
 * - Renders a restrained badges preview grid on the Flight Deck dashboard.
 * - Uses Flight Deck standard section header markup:
 *     <h2 class="ika-hub-section-title">...</h2>
 *     <p class="ika-hub-section-kicker">...</p>
 * - Uses placeholders until Watu Play badge wiring is added.
 *
 * Shortcode:
 *   [ika_fd_badges_preview limit="6"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ----------------------------------------------------------------------
 * Shared helpers (Preview + Full Badges Page)
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'ika_fd_norm_key' ) ) {
    function ika_fd_norm_key( string $s ) : string {
        $s = strtolower( trim( $s ) );
        // Normalize NBSP and odd whitespace.
        $s = str_replace( [ "\xC2\xA0", "\t", "\r", "\n" ], ' ', $s );
        $s = preg_replace( '/\s+/', ' ', $s );
        // Strip punctuation for resilient matching.
        $s = preg_replace( '/[^a-z0-9 ]+/', '', $s );
        $s = preg_replace( '/\s+/', ' ', $s );
        return trim( $s );
    }
}

if ( ! function_exists( 'ika_fd_extract_img_src' ) ) {
    function ika_fd_extract_img_src( string $html ) : string {
        // Decode and de-escape common DB/export encodings.
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $html = str_replace( [ '\\"', "\\'" ], [ '"', "'" ], $html );
        // Grab the first img src.
        if ( preg_match( '/<img[^>]*\ssrc\s*=\s*(["\'])([^"\']+)\1/i', $html, $m ) ) {
            return trim( $m[2] );
        }
        // Fallback: src without quotes (rare).
        if ( preg_match( '/<img[^>]*\ssrc\s*=\s*([^\s>]+)/i', $html, $m ) ) {
            return trim( $m[1], "\"'" );
        }
        return '';
    }
}

if ( ! function_exists( 'ika_fd_watuproplay_table_exists' ) ) {
    function ika_fd_watuproplay_table_exists(): bool {
        global $wpdb;
        $table   = $wpdb->prefix . 'watuproplay_levels';
        $pattern = $wpdb->esc_like( $table );
        $exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
        return ! empty( $exists );
    }
}

if ( ! function_exists( 'ika_fd_watuproplay_table_columns' ) ) {
    /**
     * Return column names for the WatuPRO Play levels table.
     *
     * @return string[]
     */
    function ika_fd_watuproplay_table_columns() : array {
        static $cols = null;
        if ( is_array( $cols ) ) return $cols;

        global $wpdb;
        $table = $wpdb->prefix . 'watuproplay_levels';
        $raw = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $cols = [];
        foreach ( (array) $raw as $r ) {
            if ( ! empty( $r['Field'] ) ) $cols[] = (string) $r['Field'];
        }
        return $cols;
    }
}

if ( ! function_exists( 'ika_fd_watuproplay_has_col' ) ) {
    function ika_fd_watuproplay_has_col( string $col ) : bool {
        $cols = ika_fd_watuproplay_table_columns();
        $lc = array_map( 'strtolower', $cols );
        return in_array( strtolower( $col ), $lc, true );
    }
}

if ( ! function_exists( 'ika_fd_watuproplay_guess_icon_from_row' ) ) {
    /**
     * Best-effort icon URL extraction.
     * Tries: explicit columns (image/icon/etc), then content <img>.
     */
    function ika_fd_watuproplay_guess_icon_from_row( array $row ) : string {
        $candidates = [ 'image', 'image_url', 'img', 'icon', 'icon_url', 'badge_image', 'picture', 'thumb', 'thumbnail' ];

        foreach ( $candidates as $k ) {
            if ( empty( $row[ $k ] ) ) continue;
            $v = trim( (string) $row[ $k ] );
            if ( $v === '' ) continue;

            // Attachment ID.
            if ( ctype_digit( $v ) && (int) $v > 0 ) {
                $u = wp_get_attachment_url( (int) $v );
                if ( $u ) return (string) $u;
            }

            // Full URL.
            if ( preg_match( '#^https?://#i', $v ) ) {
                return $v;
            }

            // Absolute path from site root.
            if ( strpos( $v, '/' ) === 0 ) {
                return home_url( $v );
            }

            // Relative-ish path.
            if ( strpos( $v, 'uploads/' ) !== false ) {
                return content_url( '/' . ltrim( $v, '/' ) );
            }
        }

        $content = ! empty( $row['content'] ) ? (string) $row['content'] : '';
        if ( $content ) {
            if ( function_exists( 'ika_watuproplay_extract_image_url' ) ) {
                $u = (string) ika_watuproplay_extract_image_url( $content );
                if ( $u ) return $u;
            }
            if ( function_exists( 'ika_fd_extract_img_src' ) ) {
                $u = (string) ika_fd_extract_img_src( $content );
                if ( $u ) return $u;
            }
        }

        return '';
    }
}

if ( ! function_exists( 'ika_fd_watuproplay_fetch_awards' ) ) {
    /**
     * Fetch WatuPRO Play awards (levels + badges) with extracted icon URL.
     *
     * @return array[] Each: [id, atype, name, icon]
     */
    function ika_fd_watuproplay_fetch_awards(): array {
        global $wpdb;
        if ( ! ika_fd_watuproplay_table_exists() ) {
            return [];
        }

        $table = $wpdb->prefix . 'watuproplay_levels';
        // Select additional columns if present (some installs store icon/image separately).
        $select = [ 'id', 'name', 'atype', 'content' ];
        foreach ( [ 'image', 'image_url', 'img', 'icon', 'icon_url', 'badge_image', 'picture', 'thumb', 'thumbnail' ] as $maybe ) {
            if ( function_exists( 'ika_fd_watuproplay_has_col' ) && ika_fd_watuproplay_has_col( $maybe ) ) {
                $select[] = $maybe;
            }
        }
        $sql = 'SELECT ' . implode( ',', array_unique( $select ) ) . " FROM {$table} WHERE atype IN ('level','badge')";
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! $rows ) return [];

        $out = [];
        foreach ( $rows as $r ) {
            $name    = (string) ( $r['name'] ?? '' );
            $atype   = (string) ( $r['atype'] ?? '' );
            $content = (string) ( $r['content'] ?? '' );
            $id      = (int) ( $r['id'] ?? 0 );
            if ( $id <= 0 || $name === '' || $atype === '' ) continue;

            // Best-effort icon detection.
            $icon = '';
            if ( function_exists( 'ika_fd_watuproplay_guess_icon_from_row' ) ) {
                $icon = ika_fd_watuproplay_guess_icon_from_row( $r );
            }
            if ( ! $icon && $content ) {
                $icon = ika_fd_extract_img_src( $content );
            }
            $out[] = [
                'id'   => $id,
                'atype'=> $atype,
                'name' => $name,
                'icon' => $icon,
            ];
        }

        return $out;
    }
}

if ( ! function_exists( 'ika_fd_watuproplay_icon_map' ) ) {
    /**
     * Map normalized award name -> icon URL.
     */
    function ika_fd_watuproplay_icon_map(): array {
        $rows = ika_fd_watuproplay_fetch_awards();
        if ( ! $rows ) return [];
        $map = [];
        foreach ( $rows as $r ) {
            $name = (string) ( $r['name'] ?? '' );
            $icon = (string) ( $r['icon'] ?? '' );
            if ( $name === '' || $icon === '' ) continue;
            $map[ ika_fd_norm_key( $name ) ] = $icon;
        }
        return $map;
    }
}

if ( ! function_exists( 'ika_fd_icon_for_badge_title' ) ) {
    function ika_fd_icon_for_badge_title( string $title, array $map ) : string {
        $key = ika_fd_norm_key( $title );
        if ( isset( $map[ $key ] ) ) return $map[ $key ];

        // Known Phase 1 mismatches between rank label and Watu "level" names.
        $aliases = [
            'instrument rated' => 'instrument pilot',
        ];
        if ( isset( $aliases[ $key ] ) ) {
            $alt = ika_fd_norm_key( $aliases[ $key ] );
            if ( isset( $map[ $alt ] ) ) return $map[ $alt ];
        }

        // Soft match: try removing the word "rated".
        $soft = trim( str_replace( ' rated', '', $key ) );
        if ( $soft && isset( $map[ $soft ] ) ) return $map[ $soft ];

        return '';
    }
}

/* ----------------------------------------------------------------------
 * Earned detection helpers (Badges)
 *
 * Contracted key (B5.1):
 *   ika_badge_earned_{id}
 *
 * Some WatuPRO Play installs record earned awards in a per-user table.
 * This helper checks both the contracted usermeta flag and common WatuPRO Play
 * user-award tables (auto-detected) so "earned" previews work out of the box.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'ika_fd_watuproplay_find_user_awards_table' ) ) {
    /**
     * Try to locate a WatuPRO Play per-user awards table and its award id column.
     *
     * @return array|null [ 'table' => string, 'award_col' => string, 'user_col' => string ]
     */
    function ika_fd_watuproplay_find_user_awards_table() {
        static $found = null;
        static $did_scan = false;

        if ( $did_scan ) return $found;
        $did_scan = true;

        global $wpdb;

        // Pull candidate tables and pick the first that matches expected columns.
        $like = $wpdb->esc_like( $wpdb->prefix . 'watuproplay_' ) . '%';
        $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
        if ( ! is_array( $tables ) || empty( $tables ) ) {
            $found = null;
            return $found;
        }

        // Prefer tables that include "user" and "level" (badges are stored as levels rows with atype=badge).
        usort( $tables, function( $a, $b ) {
            $aa = ( stripos( $a, 'user' ) !== false && stripos( $a, 'level' ) !== false ) ? 0 : 1;
            $bb = ( stripos( $b, 'user' ) !== false && stripos( $b, 'level' ) !== false ) ? 0 : 1;
            return $aa <=> $bb;
        } );

        $award_cols = [ 'level_id', 'badge_id', 'award_id', 'item_id' ];
        $user_cols  = [ 'user_id', 'userid', 'wp_user_id' ];

        foreach ( $tables as $t ) {
            $lt = strtolower( (string) $t );
            if ( strpos( $lt, 'watuproplay_' ) === false ) continue;
            if ( strpos( $lt, 'user' ) === false ) continue;

            $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}", 0 );
            if ( ! is_array( $cols ) || empty( $cols ) ) continue;

            $cols_lc = array_map( 'strtolower', $cols );

            $user_col = '';
            foreach ( $user_cols as $uc ) {
                $idx = array_search( $uc, $cols_lc, true );
                if ( $idx !== false ) { $user_col = $cols[ $idx ]; break; }
            }
            if ( $user_col === '' ) continue;

            $award_col = '';
            foreach ( $award_cols as $ac ) {
                $idx = array_search( $ac, $cols_lc, true );
                if ( $idx !== false ) { $award_col = $cols[ $idx ]; break; }
            }
            if ( $award_col === '' ) continue;

            $found = [
                'table'     => $t,
                'award_col' => $award_col,
                'user_col'  => $user_col,
            ];
            return $found;
        }

        $found = null;
        return $found;
    }
}

if ( ! function_exists( 'ika_fd_user_has_badge_earned' ) ) {
    /**
     * Determine whether the user has earned a given badge id.
     *
     * First checks contracted usermeta flag, then falls back to a WatuPRO Play
     * per-user awards table if present.
     */
    function ika_fd_user_has_badge_earned( int $user_id, int $badge_id ) : bool {
        if ( $user_id <= 0 || $badge_id <= 0 ) return false;

        $meta_key = 'ika_badge_earned_' . $badge_id;
        $earned_flag = get_user_meta( $user_id, $meta_key, true );
        if ( ! empty( $earned_flag ) ) {
            return true;
        }

        $info = ika_fd_watuproplay_find_user_awards_table();
        if ( empty( $info['table'] ) || empty( $info['award_col'] ) || empty( $info['user_col'] ) ) {
            return false;
        }

        global $wpdb;
        $table = $info['table'];
        $user_col = $info['user_col'];
        $award_col = $info['award_col'];

        $sql = "SELECT 1 FROM {$table} WHERE {$user_col} = %d AND {$award_col} = %d LIMIT 1";
        $hit = $wpdb->get_var( $wpdb->prepare( $sql, $user_id, $badge_id ) );
        if ( ! empty( $hit ) ) {
            // Backfill the contracted flag for faster subsequent reads.
            update_user_meta( $user_id, $meta_key, 1 );
            return true;
        }

        return false;
    }
}


/* ----------------------------------------------------------------------
 * Flight Deck – Levels Summary (Dashboard)
 *
 * Shortcode:
 *   [ika_fd_levels_preview]
 *
 * Purpose:
 * - Renders the "Levels" summary module on the Flight Deck hub.
 * - Uses the canonical XP ladder (ika_get_rank_ladder).
 * - Icons are sourced from WatuPRO Play "level" rows when available.
 * - Links to /flight-deck/badges/ (full Levels + Badges workspace).
 * ---------------------------------------------------------------------- */

add_shortcode( 'ika_fd_levels_preview', function( $atts ) {

    $levels_url = home_url( '/flight-deck/badges/' );

    ob_start();
    ?>
    <div class="ika-fd-levels-preview">
        <div class="ika-hub-section-head">
            <div class="ika-hub-section-head__text">
                <h2 class="ika-hub-section-title">Levels</h2>
                <p class="ika-hub-section-kicker">Your progression ranks, unlocked as you earn XP.</p>
            </div>
            <a class="ika-hub-section-link" href="<?php echo esc_url( $levels_url ); ?>">View all levels &rarr;</a>
        </div>

        <?php if ( ! is_user_logged_in() ) : ?>
            <div class="ika-fd-badges-empty">
                <div class="ika-fd-badges-empty__title">Log in to view your level</div>
                <div class="ika-fd-badges-empty__meta">Your progression will appear here once you’re signed in.</div>
            </div>
        <?php else : ?>
            <?php
                $user_id = get_current_user_id();
                $xp      = (int) ( function_exists('ika_get_total_xp_canonical') ? ika_get_total_xp_canonical( (int) $user_id ) : get_user_meta( $user_id, 'ika_total_xp', true ) );

                $icon_map = function_exists( 'ika_fd_watuproplay_icon_map' ) ? ika_fd_watuproplay_icon_map() : [];

                $ladder = function_exists( 'ika_get_rank_ladder' ) ? ika_get_rank_ladder() : [];
                usort( $ladder, function( $a, $b ) {
                    return (int) ( $a['min_xp'] ?? 0 ) <=> (int) ( $b['min_xp'] ?? 0 );
                } );

                $items = [];
                foreach ( (array) $ladder as $step ) {
                    $label  = (string) ( $step['label'] ?? '' );
                    $min_xp = (int) ( $step['min_xp'] ?? 0 );
                    if ( $label === '' ) continue;

                    $state = ( $xp >= $min_xp ) ? 'earned' : 'locked';
                    $chip  = ( $state === 'earned' ) ? 'Earned' : 'Locked';
                    $icon  = ( ! empty( $icon_map ) ) ? ika_fd_icon_for_badge_title( $label, $icon_map ) : '';

                    $items[] = [
                        'title'    => $label,
                        'state'    => $state,
                        'chip'     => $chip,
                        'icon_src' => $icon,
                        'meta'     => $min_xp . ' XP',
                    ];
                }
            ?>

            <div class="ika-fd-badges-grid ika-fd-badges-grid--full">
                <?php foreach ( $items as $b ) :
                    $state = (string) ( $b['state'] ?? 'locked' );
                    $chip  = (string) ( $b['chip'] ?? 'Locked' );
                    $meta  = (string) ( $b['meta'] ?? '' );
                    $icon  = (string) ( $b['icon_src'] ?? '' );
                ?>
                    <a class="ika-fd-badge-card ika-fd-badge-card--state-<?php echo esc_attr( $state ); ?>"
                       data-state="<?php echo esc_attr( $state ); ?>"
                       href="<?php echo esc_url( $levels_url ); ?>">
                        <div class="ika-fd-badge-icon" aria-hidden="true">
                            <?php if ( $icon ) : ?>
                                <img class="ika-fd-badge-icon__img" src="<?php echo esc_url( $icon ); ?>" alt="" loading="lazy" decoding="async" />
                            <?php endif; ?>
                        </div>
                        <div class="ika-fd-badge-title"><?php echo esc_html( (string) $b['title'] ); ?></div>
                        <span class="ika-fd-badge-chip"><?php echo esc_html( $chip ); ?></span>
                        <?php if ( $meta ) : ?>
                            <div class="ika-fd-badge-sub"><?php echo esc_html( $meta ); ?></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
} );


/* ----------------------------------------------------------------------
 * Flight Deck – Badges Summary (Dashboard) — Phase B5.1
 *
 * Shortcode:
 *   [ika_fd_badges_preview]
 *
 * Locked behavior:
 * - Earned badges only (atype='badge')
 * - Max 5 tiles; if more earned exist, show a "+X more" tile
 * - Mini tiles (icon + title only; no chips)
 * - Tiles link to /flight-deck/badges/
 */

if ( ! function_exists( 'ika_fd_get_badges_preview_data' ) ) {
    /**
     * Data contract for the Flight Deck badges summary module.
     *
     * @param int $user_id
     * @param int $max_tiles
     * @return array
     */
    function ika_fd_get_badges_preview_data( int $user_id, int $max_tiles = 5 ) : array {
        static $cache = [];

        $max_tiles = max( 1, min( 5, (int) $max_tiles ) );

        $ck = $user_id . ':' . $max_tiles;
        if ( isset( $cache[ $ck ] ) ) {
            return $cache[ $ck ];
        }

        $badges_url = home_url( '/flight-deck/badges/' );

        $data = [
            'earned_count'       => 0,
            'total_badges_count' => 0,
            'items'              => [],
            'more_count'         => 0,
        ];

        if ( $user_id <= 0 ) {
            $cache[ $ck ] = $data;
            return $cache[ $ck ];
        }

        if ( ! function_exists( 'ika_fd_watuproplay_table_exists' ) || ! ika_fd_watuproplay_table_exists() ) {
            $cache[ $ck ] = $data;
            return $cache[ $ck ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'watuproplay_levels';

        // Ordering: id DESC (future-ready for timestamps).
        // Pull badge rows; include extra icon/image columns when present.
        $select = [ 'id', 'name', 'content' ];
        foreach ( [ 'image', 'image_url', 'img', 'icon', 'icon_url', 'badge_image', 'picture', 'thumb', 'thumbnail' ] as $maybe ) {
            if ( function_exists( 'ika_fd_watuproplay_has_col' ) && ika_fd_watuproplay_has_col( $maybe ) ) {
                $select[] = $maybe;
            }
        }
        $sql = 'SELECT ' . implode( ',', array_unique( $select ) ) . " FROM {$table} WHERE atype = 'badge' ORDER BY id DESC";

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            $rows = [];
        }

        $data['total_badges_count'] = count( $rows );

        $earned = [];
        foreach ( $rows as $r ) {
            $id    = (int) ( $r['id'] ?? 0 );
            $title = (string) ( $r['name'] ?? '' );
            if ( $id <= 0 || $title === '' ) continue;

            // Earned-only: detect via contracted usermeta flag, with a WatuPRO Play
            // per-user awards table fallback.
            $is_earned = function_exists( 'ika_fd_user_has_badge_earned' )
                ? ika_fd_user_has_badge_earned( $user_id, $id )
                : (bool) get_user_meta( $user_id, 'ika_badge_earned_' . $id, true );
            if ( ! $is_earned ) continue;

            // Icon/image: try explicit columns first, then content <img>.
            $img_url = '';
            if ( function_exists( 'ika_fd_watuproplay_guess_icon_from_row' ) ) {
                $img_url = (string) ika_fd_watuproplay_guess_icon_from_row( $r );
            }
            if ( ! $img_url ) {
                $content = (string) ( $r['content'] ?? '' );
                if ( function_exists( 'ika_watuproplay_extract_image_url' ) ) {
                    $img_url = (string) ika_watuproplay_extract_image_url( $content );
                } elseif ( function_exists( 'ika_fd_extract_img_src' ) ) {
                    $img_url = (string) ika_fd_extract_img_src( $content );
                }
            }

            $earned[] = [
                'id'      => $id,
                'title'   => $title,
                'img_url' => $img_url,
                'href'    => $badges_url,
            ];
        }

        $data['earned_count'] = count( $earned );

        $items = array_slice( $earned, 0, $max_tiles );
        $data['items'] = $items;

        $data['more_count'] = max( 0, $data['earned_count'] - count( $items ) );

        $cache[ $ck ] = $data;
        return $cache[ $ck ];
    }
}


add_shortcode( 'ika_fd_badges_preview', function( $atts ) {

    $atts = shortcode_atts(
        [
            // Locked to 5, but allow safe override for debugging.
            'max' => 5,
        ],
        (array) $atts,
        'ika_fd_badges_preview'
    );

    $max_tiles = (int) $atts['max'];
    if ( $max_tiles < 1 ) $max_tiles = 1;
    if ( $max_tiles > 5 ) $max_tiles = 5;

    $badges_url = home_url( '/flight-deck/badges/' );

    ob_start();
    ?>
    <div class="ika-fd-badges-preview ika-fd-badges-preview--mini">
        <div class="ika-hub-section-head">
            <div class="ika-hub-section-head__text">
                <h2 class="ika-hub-section-title">Badges &amp; Achievements</h2>
                <p class="ika-hub-section-kicker">Your earned badges — right on the dashboard.</p>
            </div>
            <a class="ika-hub-section-link" href="<?php echo esc_url( $badges_url ); ?>">View all badges &rarr;</a>
        </div>

        <?php if ( ! is_user_logged_in() ) : ?>
            <div class="ika-fd-badges-empty">
                <div class="ika-fd-badges-empty__title">Log in to view your badges</div>
                <div class="ika-fd-badges-empty__meta">Your achievements will appear here once you’re signed in.</div>
            </div>
        <?php else : ?>
            <?php
                $user_id = get_current_user_id();
                $data    = function_exists( 'ika_fd_get_badges_preview_data' ) ? ika_fd_get_badges_preview_data( $user_id, $max_tiles ) : [
                    'earned_count'       => 0,
                    'total_badges_count' => 0,
                    'items'              => [],
                    'more_count'         => 0,
                ];

                $items      = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : [];
                $more_count = isset( $data['more_count'] ) ? (int) $data['more_count'] : 0;
            ?>

            <?php if ( empty( $items ) ) : ?>
                <div class="ika-fd-badges-empty">
                    <div class="ika-fd-badges-empty__title">No badges yet</div>
                    <div class="ika-fd-badges-empty__meta">Complete quizzes and missions to start earning achievements.</div>
                </div>
            <?php else : ?>
                <div class="ika-fd-badges-preview__subhead">
                    <h3 class="ika-fd-badges-preview__title">Badges</h3>
                </div>
                <div class="ika-fd-badges-grid ika-fd-badges-grid--full">
                    <?php foreach ( $items as $b ) :
                        $title   = isset( $b['title'] ) ? (string) $b['title'] : '';
                        $img_url = isset( $b['img_url'] ) ? (string) $b['img_url'] : '';
                        $href    = isset( $b['href'] ) ? (string) $b['href'] : $badges_url;
                    ?>
                        <a class="ika-fd-badge-card" href="<?php echo esc_url( $href ); ?>">
                            <div class="ika-fd-badge-icon" aria-hidden="true">
                                <?php if ( $img_url ) : ?>
                                    <img class="ika-fd-badge-icon__img" src="<?php echo esc_url( $img_url ); ?>" alt="" loading="lazy" decoding="async" />
                                <?php else : ?>
                                    <span class="ika-fd-badge-mini__fallback"></span>
                                <?php endif; ?>
                            </div>
                            <div class="ika-fd-badge-title"><?php echo esc_html( $title ); ?></div>
                        </a>
                    <?php endforeach; ?>

                    <?php if ( $more_count > 0 ) : ?>
                        <a class="ika-fd-badge-card ika-fd-badge-card--more" href="<?php echo esc_url( $badges_url ); ?>">
                            <div class="ika-fd-badge-icon" aria-hidden="true">
                                <span class="ika-fd-badge-mini__more-count">+<?php echo esc_html( (string) $more_count ); ?></span>
                            </div>
                            <div class="ika-fd-badge-title">More</div>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
} );

/* ----------------------------------------------------------------------
 * Flight Deck – Badges Page (B5)
 *
 * Shortcode:
 *   [ika_fd_badges_page]
 *
 * Renders two groups:
 *  - Levels (atype=level) ordered by XP ladder thresholds
 *  - Badges (atype=badge) ordered by name
 *
 * State rules:
 *  - Levels: XP ladder thresholds (earned/locked)
 *  - Badges: usermeta grants (earned if ika_badge_earned_{id} truthy)
 * ---------------------------------------------------------------------- */

add_shortcode( 'ika_fd_badges_page', function( $atts ) {

    if ( ! is_user_logged_in() ) {
        return '<div class="ika-fd-badges-page ika-fd-marker--badges"><div class="ika-fd-badges-empty"><div class="ika-fd-badges-empty__title">Log in to view your badges</div><div class="ika-fd-badges-empty__meta">Your achievements will appear here once you\'re signed in.</div></div></div>';
    }

    $user_id = get_current_user_id();
    $xp      = (int) ( function_exists('ika_get_total_xp_canonical') ? ika_get_total_xp_canonical( (int) $user_id ) : get_user_meta( $user_id, 'ika_total_xp', true ) );

    // Pull WatuPRO Play awards (both levels and badges) + icon map.
    $icon_map = ika_fd_watuproplay_icon_map();

    $awards = [];
    if ( function_exists( 'ika_fd_watuproplay_table_exists' ) && ika_fd_watuproplay_table_exists() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'watuproplay_levels';
        // Column is `id` (lowercase) in WatuPRO Play tables.
        $awards = $wpdb->get_results(
            "SELECT id, name, atype, content FROM {$table} WHERE atype IN ('level','badge')",
            ARRAY_A
        );
    }

    $levels_rows = [];
    $badges_rows = [];
    foreach ( (array) $awards as $r ) {
        $atype = isset( $r['atype'] ) ? (string) $r['atype'] : '';
        if ( $atype === 'level' ) {
            $levels_rows[] = $r;
        } elseif ( $atype === 'badge' ) {
            $badges_rows[] = $r;
        }
    }

    // XP ladder thresholds (canonical) for ordering + state.
    $ladder = function_exists( 'ika_get_rank_ladder' ) ? ika_get_rank_ladder() : [];
    usort( $ladder, function( $a, $b ) {
        return (int) ( $a['min_xp'] ?? 0 ) <=> (int) ( $b['min_xp'] ?? 0 );
    } );

    // Build a name->row map for Watu levels to retrieve icons by ladder label.
    $levels_by_name = [];
    foreach ( $levels_rows as $r ) {
        $name = isset( $r['name'] ) ? (string) $r['name'] : '';
        if ( $name === '' ) continue;
        $levels_by_name[ ika_fd_norm_key( $name ) ] = $r;
    }

    // Build ordered level items using the ladder labels (not DB order).
    $level_items = [];
    foreach ( (array) $ladder as $step ) {
        $label  = (string) ( $step['label'] ?? '' );
        $min_xp = (int) ( $step['min_xp'] ?? 0 );
        if ( $label === '' ) continue;

        $state = ( $xp >= $min_xp ) ? 'earned' : 'locked';
        $chip  = ( $state === 'earned' ) ? 'Earned' : 'Locked';

        $icon_src = ( ! empty( $icon_map ) ) ? ika_fd_icon_for_badge_title( $label, $icon_map ) : '';

        $level_items[] = [
            'title'    => $label,
            'state'    => $state,
            'chip'     => $chip,
            'icon_src' => $icon_src,
            'meta'     => $min_xp . ' XP',
        ];
    }

    // Badges: order by name.
    usort( $badges_rows, function( $a, $b ) {
        return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
    } );

    $badge_items = [];
    foreach ( $badges_rows as $r ) {
        $id   = (int) ( $r['id'] ?? 0 );
        $name = (string) ( $r['name'] ?? '' );
        if ( $id < 1 || $name === '' ) continue;

        $earned = function_exists( 'ika_fd_user_has_badge_earned' )
            ? ika_fd_user_has_badge_earned( $user_id, $id )
            : (bool) get_user_meta( $user_id, 'ika_badge_earned_' . $id, true );
        $state  = $earned ? 'earned' : 'locked';
        $chip   = $earned ? 'Earned' : 'Locked';

        $icon_src = ( ! empty( $icon_map ) ) ? ika_fd_icon_for_badge_title( $name, $icon_map ) : '';

        $badge_items[] = [
            'title'    => $name,
            'state'    => $state,
            'chip'     => $chip,
            'icon_src' => $icon_src,
            'meta'     => '',
        ];
    }

    $flight_deck_url = home_url( '/flight-deck/' );

    ob_start();
    ?>
    <div class="ika-fd-badges-page ika-fd-marker--badges">

        <a class="ika-fd-back-link" href="<?php echo esc_url( $flight_deck_url ); ?>">&larr; Back to Flight Deck</a>

        <div class="ika-fd-badges-block">
            <div class="ika-hub-section-head">
                <div class="ika-hub-section-head__text">
                    <h2 class="ika-hub-section-title">Levels</h2>
                    <p class="ika-hub-section-kicker">Your progression ranks, unlocked as you earn XP.</p>
                </div>
            </div>

            <div class="ika-fd-badges-grid ika-fd-badges-grid--full">
                <?php foreach ( $level_items as $b ) :
                    $state = (string) ( $b['state'] ?? 'locked' );
                    $chip  = (string) ( $b['chip'] ?? 'Locked' );
                    $meta  = (string) ( $b['meta'] ?? '' );
                    $icon  = (string) ( $b['icon_src'] ?? '' );
                ?>
                    <div class="ika-fd-badge-card ika-fd-badge-card--state-<?php echo esc_attr( $state ); ?>" data-state="<?php echo esc_attr( $state ); ?>">
                        <div class="ika-fd-badge-icon" aria-hidden="true">
                            <?php if ( $icon ) : ?>
                                <img class="ika-fd-badge-icon__img" src="<?php echo esc_url( $icon ); ?>" alt="" loading="lazy" decoding="async" />
                            <?php endif; ?>
                        </div>
                        <div class="ika-fd-badge-title"><?php echo esc_html( (string) $b['title'] ); ?></div>
                        <span class="ika-fd-badge-chip"><?php echo esc_html( $chip ); ?></span>
                        <?php if ( $meta ) : ?>
                            <div class="ika-fd-badge-sub"><?php echo esc_html( $meta ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ika-fd-badges-block">
            <div class="ika-hub-section-head">
                <div class="ika-hub-section-head__text">
                    <h2 class="ika-hub-section-title">Badges</h2>
                    <p class="ika-hub-section-kicker">Achievement badges you can earn as you explore the platform.</p>
                </div>
            </div>

            <?php if ( empty( $badge_items ) ) : ?>
                <div class="ika-fd-badges-empty ika-fd-badges-empty--soft">
                    <div class="ika-fd-badges-empty__title">Badges coming soon</div>
                    <div class="ika-fd-badges-empty__meta">You’ll see achievements here as we roll out new badge challenges.</div>
                </div>
            <?php else : ?>
                <div class="ika-fd-badges-grid ika-fd-badges-grid--full">
                    <?php foreach ( $badge_items as $b ) :
                        $state = (string) ( $b['state'] ?? 'locked' );
                        $chip  = (string) ( $b['chip'] ?? 'Locked' );
                        $icon  = (string) ( $b['icon_src'] ?? '' );
                    ?>
                        <div class="ika-fd-badge-card ika-fd-badge-card--state-<?php echo esc_attr( $state ); ?>" data-state="<?php echo esc_attr( $state ); ?>">
                            <div class="ika-fd-badge-icon" aria-hidden="true">
                                <?php if ( $icon ) : ?>
                                    <img class="ika-fd-badge-icon__img" src="<?php echo esc_url( $icon ); ?>" alt="" loading="lazy" decoding="async" />
                                <?php endif; ?>
                            </div>
                            <div class="ika-fd-badge-title"><?php echo esc_html( (string) $b['title'] ); ?></div>
                            <span class="ika-fd-badge-chip"><?php echo esc_html( $chip ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php
    return ob_get_clean();
} );
