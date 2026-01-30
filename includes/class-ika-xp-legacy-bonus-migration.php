<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Step 3: Legacy bonus -> Ledger migration (admin-only)
 *
 * Background:
 * - Some historical bonus XP may exist in user meta (ika_total_xp_bonus).
 * - Contract v1.0 target state: Ledger is the only source of truth.
 *
 * This tool:
 * - DRY RUN by default (shows what would be migrated)
 * - Only migrates when confirm="MIGRATE" is supplied
 * - Writes ONE ledger row per user for the legacy bonus amount
 * - Sets ika_total_xp_bonus to 0 and records ika_legacy_bonus_migrated_at
 */
class IKA_XP_Legacy_Bonus_Migration {

    const META_KEY_BONUS = 'ika_total_xp_bonus';
    const META_KEY_MIGRATED_AT = 'ika_legacy_bonus_migrated_at';

    public function __construct() {
        add_shortcode( 'ika_xp_legacy_bonus_migration', [ $this, 'shortcode' ] );
    }

    public function shortcode( $atts ) : string {
        if ( ! current_user_can( 'manage_options' ) ) { return ''; }

        $atts = shortcode_atts( [
            'confirm' => '',
            'limit'   => 50,  // safety cap per run
            'user_id' => 0,   // optional single-user
        ], $atts );

        $confirm = strtoupper( trim( (string) $atts['confirm'] ) );
        $limit   = max( 1, min( 500, intval( $atts['limit'] ) ) );
        $user_id = intval( $atts['user_id'] );

        $is_migrate = ( $confirm === 'MIGRATE' );

        $rows = $this->get_candidates( $limit, $user_id );

        $out = [];
        $out[] = "IKA LEGACY BONUS -> LEDGER MIGRATION (Step 3)";
        $out[] = "Mode: " . ( $is_migrate ? "MIGRATE" : "DRY RUN" );
        $out[] = "Limit: {$limit}" . ( $user_id ? " (user_id={$user_id})" : "" );
        $out[] = "";

        if ( empty( $rows ) ) {
            $out[] = "No users found with non-zero legacy bonus meta (" . self::META_KEY_BONUS . ").";
            return '<pre>' . esc_html( implode( "\n", $out ) ) . '</pre>';
        }

        $total_users = count( $rows );
        $sum_bonus   = array_sum( array_map( function( $r ) { return (int) $r['bonus']; }, $rows ) );

        $out[] = "Candidates: {$total_users}";
        $out[] = "Sum legacy bonus XP (would migrate): {$sum_bonus}";
        $out[] = "";
        $out[] = "USER LIST";
        foreach ( $rows as $r ) {
            $out[] = "- user_id={$r['user_id']} legacy_bonus={$r['bonus']} migrated_at=" . ( $r['migrated_at'] ?: 'N/A' );
        }

        if ( ! $is_migrate ) {
            $out[] = "";
            $out[] = "To migrate: [ika_xp_legacy_bonus_migration confirm="MIGRATE" limit="{$limit}"]";
            $out[] = "Optional single user: [ika_xp_legacy_bonus_migration confirm="MIGRATE" user_id="27"]";
            return '<pre>' . esc_html( implode( "\n", $out ) ) . '</pre>';
        }

        // MIGRATE
        $out[] = "";
        $out[] = "MIGRATING...";
        $result = $this->migrate_rows( $rows );

        $out[] = "Migrated users: " . $result['migrated'];
        $out[] = "Skipped users: " . $result['skipped'];
        $out[] = "Ledger rows inserted: " . $result['inserted'];
        $out[] = "Total XP migrated: " . $result['xp_migrated'];

        return '<pre>' . esc_html( implode( "\n", $out ) ) . '</pre>';
    }

    /**
     * @return array<int, array{user_id:int, bonus:int, migrated_at:string|null}>
     */
    private function get_candidates( int $limit, int $user_id = 0 ) : array {
        global $wpdb;

        $umeta = $wpdb->usermeta;
        $key_bonus = self::META_KEY_BONUS;
        $key_migrated = self::META_KEY_MIGRATED_AT;

        if ( $user_id > 0 ) {
            $bonus = get_user_meta( $user_id, $key_bonus, true );
            $bonus_int = intval( $bonus );
            if ( $bonus_int <= 0 ) { return []; }
            $migrated_at = get_user_meta( $user_id, $key_migrated, true );
            return [[
                'user_id'     => $user_id,
                'bonus'       => $bonus_int,
                'migrated_at' => $migrated_at ? (string) $migrated_at : null,
            ]];
        }

        // NOTE: meta_value is stored as string; CAST to SIGNED for numeric compare.
        $sql = $wpdb->prepare(
            "SELECT um.user_id as user_id,
                    CAST(um.meta_value AS SIGNED) as bonus,
                    um2.meta_value as migrated_at
             FROM {$umeta} um
             LEFT JOIN {$umeta} um2
               ON um2.user_id = um.user_id AND um2.meta_key = %s
             WHERE um.meta_key = %s
               AND CAST(um.meta_value AS SIGNED) > 0
             ORDER BY bonus DESC
             LIMIT %d",
            $key_migrated,
            $key_bonus,
            $limit
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) { return []; }

        return array_map( function( $r ) {
            return [
                'user_id'     => intval( $r['user_id'] ),
                'bonus'       => intval( $r['bonus'] ),
                'migrated_at' => $r['migrated_at'] ? (string) $r['migrated_at'] : null,
            ];
        }, $rows );
    }

    /**
     * @param array<int, array{user_id:int, bonus:int, migrated_at:string|null}> $rows
     * @return array{migrated:int, skipped:int, inserted:int, xp_migrated:int}
     */
    private function migrate_rows( array $rows ) : array {
        $migrated = 0;
        $skipped = 0;
        $inserted = 0;
        $xp_migrated = 0;

        foreach ( $rows as $r ) {
            $user_id = (int) $r['user_id'];
            $bonus   = (int) $r['bonus'];

            if ( $bonus <= 0 ) { $skipped++; continue; }

            // Idempotency safety: if migrated_at is already set, do not migrate again.
            $already = get_user_meta( $user_id, self::META_KEY_MIGRATED_AT, true );
            if ( ! empty( $already ) ) {
                $skipped++;
                continue;
            }

            // Insert one ledger row for the legacy bonus.
            if ( function_exists( 'ika_xp_ledger_add_row' ) ) {
                $ok = ika_xp_ledger_add_row( [
                    'user_id'    => $user_id,
                    'xp'         => $bonus,
                    'source'     => 'legacy_bonus_migration',
                    'reason'     => 'Legacy bonus meta migrated to ledger',
                    'taking_id'  => 0,
                    'ref_id'     => 'legacy-bonus-meta',
                ] );
                if ( $ok ) {
                    $inserted++;
                    $xp_migrated += $bonus;

                    // Set legacy meta to 0 and mark migrated.
                    update_user_meta( $user_id, self::META_KEY_BONUS, 0 );
                    update_user_meta( $user_id, self::META_KEY_MIGRATED_AT, current_time( 'mysql' ) );

                    // Sync caches (if your plugin uses them).
                    if ( function_exists( 'ika_xp_sync_user_cache_from_ledger' ) ) {
                        ika_xp_sync_user_cache_from_ledger( $user_id );
                    }
                    if ( function_exists( 'ika_leaderboard_cache_bump' ) ) {
                        ika_leaderboard_cache_bump();
                    }

                    $migrated++;
                } else {
                    $skipped++;
                }
            } else {
                $skipped++;
            }
        }

        return [
            'migrated'    => $migrated,
            'skipped'     => $skipped,
            'inserted'    => $inserted,
            'xp_migrated' => $xp_migrated,
        ];
    }
}

new IKA_XP_Legacy_Bonus_Migration();
