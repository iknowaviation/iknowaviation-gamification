<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * Front-end dependencies + UI asset loading
 *
 * What this file does:
 *  - Enqueues site-wide master UI CSS (ika_master.css)
 *  - Enqueues WatuPRO quiz theme CSS ONLY on single Quiz CPT pages
 *  - Enqueues WatuPRO results theme CSS ONLY on single Quiz CPT pages
 *  - Enqueues Watu Play modal CSS ONLY on single Quiz CPT pages
 *  - Enqueues jQuery UI Dialog for logged-in users (used by some UI modals)
 *
 * Notes:
 *  - We use filemtime() for cache-busting.
 * ======================================================================*/

if ( ! function_exists( 'ika_gam_asset_ver' ) ) {
    /**
     * Cache-busting version via filemtime.
     *
     * @param string $rel_path Relative path from plugin root (e.g. '/assets/css/ika_master.css').
     * @return string
     */
    function ika_gam_asset_ver( string $rel_path ): string {
        $rel_path = '/' . ltrim( $rel_path, '/' );
        $abs      = IKA_GAM_PLUGIN_PATH . ltrim( $rel_path, '/' );
        $ts       = @filemtime( $abs );
        return $ts ? (string) $ts : IKA_GAM_PLUGIN_VERSION;
    }
}

/**
 * Helper: are we on a Quiz CPT single?
 */
if ( ! function_exists( 'ika_gam_is_quiz_single' ) ) {
    function ika_gam_is_quiz_single(): bool {
        return function_exists( 'is_singular' ) && is_singular( 'quiz' );
    }

}
/**
 * Helper: is this the Flight Deck / Profile Hub page?
 *
 * We keep this conservative + production-safe:
 *  - Only on singular pages
 *  - Checks for a wrapper marker in post content to avoid hardcoding IDs
 */
if ( ! function_exists( 'ika_gam_is_flightdeck_page' ) ) {
    function ika_gam_is_flightdeck_page(): bool {

        // Fast + explicit: Flight Deck page slug.
        // (This avoids relying on Elementor-rendered content, which is usually stored in post meta.)
        if ( function_exists( 'is_page' ) && is_page( 'flight-deck' ) ) {
            return true;
        }

        // Fallback: search for wrapper markers in post_content and Elementor stored layout JSON.
        if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
            return false;
        }

        global $post;
        if ( ! $post ) {
            return false;
        }

        $needles = array(
            'ika-scope-flightdeck',
            'ika-profile-hub',
            'ika-hub-hero',
        );

        // Check classic post_content.
        $content = (string) ( $post->post_content ?? '' );
        foreach ( $needles as $needle ) {
            if ( $content && stripos( $content, $needle ) !== false ) {
                return true;
            }
        }

        // Check Elementor stored layout JSON (most common for Elementor pages).
        $edata = (string) get_post_meta( $post->ID, '_elementor_data', true );
        foreach ( $needles as $needle ) {
            if ( $edata && stripos( $edata, $needle ) !== false ) {
                return true;
            }
        }

        return false;
    }
}


add_action( 'wp_enqueue_scripts', function () {

    // 1) Site-wide master UI CSS (includes Quiz Hub / archive UI)
    $master_rel = '/assets/css/ika_master.css';
    wp_enqueue_style(
        'ika-master',
        IKA_GAM_PLUGIN_URL . $master_rel,
        array(),
        ika_gam_asset_ver( $master_rel )
    );



    // 1b) Flight Deck / Profile Hub CSS (only on the Flight Deck page)
    if ( ika_gam_is_flightdeck_page() ) {
        $fd_rel = '/assets/css/ika_flightdeck.css';
        wp_enqueue_style(
            'ika-flightdeck',
            IKA_GAM_PLUGIN_URL . $fd_rel,
            array( 'ika-master' ),
            ika_gam_asset_ver( $fd_rel )
        );
    }

    // 2) WatuPRO quiz + results theme CSS (ONLY on single Quiz CPT pages)
    if ( ika_gam_is_quiz_single() ) {

        $quiz_rel = '/assets/css/ika_watupro_quiz.css';
        wp_enqueue_style(
            'ika-watupro-quiz',
            IKA_GAM_PLUGIN_URL . $quiz_rel,
            array( 'ika-master' ),
            ika_gam_asset_ver( $quiz_rel )
        );

        $results_rel = '/assets/css/ika_watupro_results.css';
        wp_enqueue_style(
            'ika-watupro-results',
            IKA_GAM_PLUGIN_URL . $results_rel,
            array( 'ika-master', 'ika-watupro-quiz' ),
            ika_gam_asset_ver( $results_rel )
        );

        // Watu Play modal styling (badge/level modal)
        $modal_rel = '/assets/css/ika_watuproplay_modal.css';
        wp_enqueue_style(
            'ika-watuproplay-modal',
            IKA_GAM_PLUGIN_URL . $modal_rel,
            array( 'ika-master' ),
            ika_gam_asset_ver( $modal_rel )
        );
    }

    // 3) jQuery UI Dialog (used by some UI bits)
    if ( is_user_logged_in() ) {
        wp_enqueue_script( 'jquery-ui-dialog' );
        wp_enqueue_style( 'wp-jquery-ui-dialog' );
    }

}, 20 );
