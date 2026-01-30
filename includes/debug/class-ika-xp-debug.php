<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IKA XP Debug (admin-only, read-only)
 * Shortcode: [ika_xp_debug user_id="27"]
 */
class IKA_XP_Debug {

    public function __construct() {
        add_shortcode( 'ika_xp_debug', array( $this, 'render' ) );
    }

    public function render( $atts ) {
        // Admins only (quietly return nothing for non-admins).
        if ( ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        global $wpdb;

        $atts = shortcode_atts(
            array(
                'user_id' => get_current_user_id(),
            ),
            $atts
        );

        $user_id = intval( $atts['user_id'] );
        if ( $user_id <= 0 ) {
            return '<pre>IKA XP DEBUG\nInvalid user_id</pre>';
        }

        $ledger_table = $wpdb->prefix . 'ika_xp_ledger';

        // Ledger totals
        $all_time = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(xp),0) FROM {$ledger_table} WHERE user_id = %d",
                $user_id
            )
        );

        $last_7 = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(xp),0) FROM {$ledger_table}
                 WHERE user_id = %d AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )",
                $user_id
            )
        );

        // Latest taking_id with totals (attempt-level)
        $latest = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT taking_id,
                        COALESCE(SUM(xp),0) AS total_xp,
                        COALESCE(SUM(CASE WHEN source = 'quiz_attempt' THEN xp ELSE 0 END),0) AS quiz_xp,
                        COALESCE(SUM(CASE WHEN source <> 'quiz_attempt' THEN xp ELSE 0 END),0) AS bonus_xp,
                        MAX(created_at) AS last_ts
                 FROM {$ledger_table}
                 WHERE user_id = %d AND taking_id > 0
                 GROUP BY taking_id
                 ORDER BY last_ts DESC
                 LIMIT 1",
                $user_id
            )
        );

        // Legacy bonus meta (for Step 3 planning only)
        $legacy_bonus = get_user_meta( $user_id, 'ika_total_xp_bonus', true );
        if ( $legacy_bonus === '' || $legacy_bonus === null ) {
            $legacy_bonus = 0;
        }

        $out = array();
        $out[] = 'IKA XP DEBUG';
        $out[] = 'User ID: ' . $user_id;
        $out[] = '';
        $out[] = 'LEDGER TOTALS';
        $out[] = '- All-time XP: ' . $all_time;
        $out[] = '- Last 7 days XP: ' . $last_7;
        $out[] = '';
        $out[] = 'LATEST QUIZ ATTEMPT';
        if ( $latest ) {
            $out[] = '- Taking ID: ' . $latest->taking_id;
            $out[] = '- Quiz XP: ' . (int) $latest->quiz_xp;
            $out[] = '- Bonus XP: ' . (int) $latest->bonus_xp;
            $out[] = '- Total Attempt XP: ' . (int) $latest->total_xp;
            $out[] = '- Last ledger timestamp: ' . $latest->last_ts;
        } else {
            $out[] = '- N/A (no taking_id rows found)';
        }
        $out[] = '';
        $out[] = 'LEGACY CHECK (for migration planning)';
        $out[] = '- ika_total_xp_bonus: ' . $legacy_bonus;

        return '<pre>' . esc_html( implode( "\n", $out ) ) . '</pre>';
    }
}

new IKA_XP_Debug();
