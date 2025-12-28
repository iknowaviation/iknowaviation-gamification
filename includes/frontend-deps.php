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

add_action( 'wp_enqueue_scripts', function () {

    // 1) Site-wide master UI CSS (includes Quiz Hub / archive UI)
    $master_rel = '/assets/css/ika_master.css';
    wp_enqueue_style(
        'ika-master',
        IKA_GAM_PLUGIN_URL . $master_rel,
        array(),
        ika_gam_asset_ver( $master_rel )
    );

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
