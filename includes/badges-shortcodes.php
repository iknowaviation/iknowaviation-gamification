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
        $rows = $wpdb->get_results(
            "SELECT id, name, atype, content FROM {$table} WHERE atype IN ('level','badge')",
            ARRAY_A
        );
        if ( ! $rows ) return [];

        $out = [];
        foreach ( $rows as $r ) {
            $name    = (string) ( $r['name'] ?? '' );
            $atype   = (string) ( $r['atype'] ?? '' );
            $content = (string) ( $r['content'] ?? '' );
            $id      = (int) ( $r['id'] ?? 0 );
            if ( $id <= 0 || $name === '' || $atype === '' ) continue;

            $icon = $content ? ika_fd_extract_img_src( $content ) : '';
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

add_shortcode( 'ika_fd_badges_preview', function( $atts ) {

    $atts = shortcode_atts(
        [
            'limit' => 6,
        ],
        (array) $atts,
        'ika_fd_badges_preview'
    );

    $limit = (int) $atts['limit'];
    if ( $limit < 1 ) $limit = 1;
    if ( $limit > 12 ) $limit = 12;

    $badges_url = home_url( '/flight-deck/badges/' );

    ob_start();
    ?>
    <div class="ika-fd-badges-preview">
        <div class="ika-hub-section-head">
            <div class="ika-hub-section-head__text">
                <h2 class="ika-hub-section-title">Badges &amp; Achievements</h2>
                <p class="ika-hub-section-kicker">A quick snapshot of what you’ve earned recently.</p>
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
            // Phase 1 placeholders: we’ll replace with Watu Play earned/locked badges later.
            // However, we *can* pull icon images from WatuPRO Play "levels" records now.
            $icon_map = ika_fd_watuproplay_icon_map();
            $user_id = get_current_user_id();
            $xp = (int) get_user_meta( $user_id, 'ika_total_xp', true );

            $ladder = function_exists( 'ika_get_rank_ladder' ) ? ika_get_rank_ladder() : [];
            // Ensure ascending by min_xp.
            usort( $ladder, function( $a, $b ) {
                return (int) ($a['min_xp'] ?? 0) <=> (int) ($b['min_xp'] ?? 0);
            });

            $earned = [];
            $locked = [];

            foreach ( (array) $ladder as $r ) {
                $label = isset( $r['label'] ) ? (string) $r['label'] : '';
                $min_xp = isset( $r['min_xp'] ) ? (int) $r['min_xp'] : 0;
                if ( $label === '' ) continue;

                if ( $xp >= $min_xp ) {
                    $earned[] = [
                        'title' => $label,
                        'state' => 'earned',
                        'chip'  => 'Earned',
                    ];
                } else {
                    $locked[] = [
                        'title' => $label,
                        'state' => 'locked',
                        'chip'  => 'Locked',
                    ];
                }
            }

            // Show a balanced slice: recent earned first, then upcoming locked.
            $items = [];

            $earned_slice = array_slice( array_reverse( $earned ), 0, (int) ceil( $limit / 2 ) );
            foreach ( $earned_slice as $e ) $items[] = $e;

            foreach ( $locked as $l ) {
                if ( count( $items ) >= $limit ) break;
                $items[] = $l;
            }

            // If user has no earned ranks yet, just show the first few locked.
            if ( empty( $items ) ) {
                $items = array_slice( $locked, 0, $limit );
            }
            $items = array_slice( $items, 0, $limit );
            ?>
            <div class="ika-fd-badges-grid">
                <?php foreach ( $items as $b ) :
                    $state = isset( $b['state'] ) ? (string) $b['state'] : 'locked';
                    $chip  = isset( $b['chip'] ) ? (string) $b['chip'] : 'Locked';
                    $icon_src = ( ! empty( $icon_map ) ) ? ika_fd_icon_for_badge_title( (string) $b['title'], $icon_map ) : '';
                ?>
                    <a class="ika-fd-badge-card ika-fd-badge-card--state-<?php echo esc_attr( $state ); ?>"
                       data-state="<?php echo esc_attr( $state ); ?>"
                       href="<?php echo esc_url( $badges_url ); ?>">
                        <div class="ika-fd-badge-icon" aria-hidden="true">
                            <?php if ( $icon_src ) : ?>
                                <img class="ika-fd-badge-icon__img" src="<?php echo esc_url( $icon_src ); ?>" alt="" loading="lazy" decoding="async" />
                            <?php endif; ?>
                        </div>
                        <div class="ika-fd-badge-title"><?php echo esc_html( $b['title'] ); ?></div>
                        <span class="ika-fd-badge-chip"><?php echo esc_html( $chip ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
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
    $xp      = (int) get_user_meta( $user_id, 'ika_total_xp', true );

    // Pull WatuPRO Play awards (both levels and badges) + icon map.
    $icon_map = ika_fd_watuproplay_icon_map();

    $awards = [];
    if ( function_exists( 'ika_fd_watuproplay_table_exists' ) && ika_fd_watuproplay_table_exists() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'watuproplay_levels';
        $awards = $wpdb->get_results(
            "SELECT ID, name, atype, content FROM {$table} WHERE atype IN ('level','badge')",
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
        $id   = (int) ( $r['ID'] ?? 0 );
        $name = (string) ( $r['name'] ?? '' );
        if ( $id < 1 || $name === '' ) continue;

        $earned = (bool) get_user_meta( $user_id, 'ika_badge_earned_' . $id, true );
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
