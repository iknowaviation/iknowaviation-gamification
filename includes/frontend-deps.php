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
	    if ( function_exists( 'is_page' ) && ( is_page( 'flight-deck' ) || is_page( 'flightdeck' ) ) ) {
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
            'ika-fd-jumpto',
            'fd-rankxp',
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


if ( ! function_exists( 'ika_gam_page_has_marker' ) ) {
    /**
     * Searches post_content and Elementor _elementor_data for marker strings.
     *
     * Elementor pages often store layout content in `_elementor_data` instead of `post_content`,
     * so we check both.
     */
    function ika_gam_page_has_marker( array $needles ): bool {
        if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
            return false;
        }

        global $post;
        if ( ! $post ) {
            return false;
        }

        $content = (string) ( $post->post_content ?? '' );
        $edata   = (string) get_post_meta( $post->ID, '_elementor_data', true );

        foreach ( $needles as $needle ) {
            if ( ( $content && stripos( $content, $needle ) !== false ) ||
                 ( $edata   && stripos( $edata,   $needle ) !== false ) ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'ika_gam_is_flightdeck_subpage' ) ) {
    /**
     * Detect Flight Deck sub-pages by either:
     * - direct page slug match (works well for child pages like /flight-deck/missions/), OR
     * - marker match in content/Elementor data
     */
    function ika_gam_is_flightdeck_subpage( string $slug, string $marker ): bool {
        if ( function_exists( 'is_page' ) && is_page( $slug ) ) {
            return true;
        }

        return ika_gam_page_has_marker( array( $marker ) );
    }
}


add_action( 'wp_enqueue_scripts', function () {

    // Normalize plugin URL to prevent accidental double-slash URLs (301 redirects).
    // Individual rel paths in this file include a leading '/'.
    $ika_base_url = function_exists( 'untrailingslashit' )
        ? untrailingslashit( IKA_GAM_PLUGIN_URL )
        : rtrim( IKA_GAM_PLUGIN_URL, '/' );

    // 1) Site-wide master UI CSS (includes Quiz Hub / archive UI)
    $master_rel = '/assets/css/ika_master.css';
    wp_enqueue_style(
        'ika-master',
        $ika_base_url . $master_rel,
        array(),
        ika_gam_asset_ver( $master_rel )
    );

		/**
		 * Quiz Hub (Quiz archive) CSS
		 *
		 * The Quiz archive template now renders a shortcode-driven hub, not an Elementor Loop Grid.
		 * Keep its styling in a dedicated file so we can iterate safely.
		 */
		$hub_rel = '/assets/css/ika_quiz_hub.css';
		$should_load_hub = false;

		// Primary: Quiz CPT archive.
		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'quiz' ) ) {
			$should_load_hub = true;
		}

		// Secondary: any page that contains the shortcode or marker (Elementor HTML widgets or templates).
		if ( ! $should_load_hub && function_exists( 'is_singular' ) && is_singular() ) {
			$post_id = get_the_ID();
			if ( $post_id ) {
				$content = (string) get_post_field( 'post_content', $post_id );
				$edata   = (string) get_post_meta( $post_id, '_elementor_data', true );
				if ( stripos( $content, '[ika_quiz_hub' ) !== false || stripos( $edata, 'ika_quiz_hub' ) !== false || stripos( $edata, 'ika-quiz-hub' ) !== false ) {
					$should_load_hub = true;
				}
			}
		}

		if ( $should_load_hub ) {
			wp_enqueue_style(
				'ika-quiz-hub',
				$ika_base_url . $hub_rel,
				array( 'ika-master' ),
				ika_gam_asset_ver( $hub_rel )
			);
		}



	// 1b) Flight Deck base CSS
	// Load on the main Flight Deck page AND on Flight Deck sub-pages (e.g. /flight-deck/badges/).
	$is_fd_sub_missions    = ika_gam_is_flightdeck_subpage( 'missions', 'ika-fd-marker--missions' );
	$is_fd_sub_badges      = ika_gam_is_flightdeck_subpage( 'badges', 'ika-fd-marker--badges' );
	$is_fd_sub_leaderboard = ika_gam_is_flightdeck_subpage( 'leaderboard', 'ika-fd-marker--leaderboard' );
	$is_fd_sub_logbook     = ika_gam_is_flightdeck_subpage( 'logbook', 'ika-fd-marker--logbook' );
	$is_fd_sub_settings    = ika_gam_is_flightdeck_subpage( 'settings', 'ika-fd-marker--settings' );
	$is_fd_context         = ika_gam_is_flightdeck_page() || $is_fd_sub_missions || $is_fd_sub_badges || $is_fd_sub_leaderboard || $is_fd_sub_logbook || $is_fd_sub_settings;

	if ( $is_fd_context ) {
		$fd_rel = '/assets/css/ika_flightdeck.css';
		wp_enqueue_style(
			'ika-flightdeck',
			$ika_base_url . $fd_rel,
			array( 'ika-master' ),
			ika_gam_asset_ver( $fd_rel )
		);

		// Flight Deck JS (leaderboard tabs)
		$fd_js_rel = '/assets/js/ika_flightdeck_leaderboard_tabs.js';
		wp_enqueue_script(
			'ika-flightdeck-tabs',
			$ika_base_url . $fd_js_rel,
			array(),
			ika_gam_asset_ver( $fd_js_rel ),
			true
		);

		// Flight Deck JS (Jump To active-section highlighting)
		$jumpto_js_rel = '/assets/js/ika_flightdeck_jumpto_active.js';
		wp_enqueue_script(
			'ika-flightdeck-jumpto',
			$ika_base_url . $jumpto_js_rel,
			array(),
			ika_gam_asset_ver( $jumpto_js_rel ),
			true
		);

		// Flight Deck JS (Flight Log filter)
		$fl_js_rel = '/assets/js/ika_flightdeck_flightlog_filter.js';
		wp_enqueue_script(
			'ika-flightdeck-flightlog-filter',
			$ika_base_url . $fl_js_rel,
			array(),
			ika_gam_asset_ver( $fl_js_rel ),
			true
		);

		// Flight Deck sub-page add-ons (only load when page matches)
		// Pages: /flight-deck/missions/ and /flight-deck/badges/
		if ( $is_fd_sub_missions ) {
			$rel = '/assets/css/ika_flightdeck_missions.css';
			wp_enqueue_style(
				'ika-flightdeck-missions',
				$ika_base_url . $rel,
				array( 'ika-master', 'ika-flightdeck' ),
				ika_gam_asset_ver( $rel )
			);
		}

		if ( $is_fd_sub_badges ) {
			$rel = '/assets/css/ika_flightdeck_badges.css';
			wp_enqueue_style(
				'ika-flightdeck-badges',
				$ika_base_url . $rel,
				array( 'ika-master', 'ika-flightdeck' ),
				ika_gam_asset_ver( $rel )
			);
		}
	}

	// 2) WatuPRO quiz + results theme CSS (ONLY on single Quiz CPT pages)
	if ( ika_gam_is_quiz_single() ) {
		$quiz_rel = '/assets/css/ika_watupro_quiz.css';
		wp_enqueue_style(
			'ika-watupro-quiz',
			$ika_base_url . $quiz_rel,
			array( 'ika-master' ),
			ika_gam_asset_ver( $quiz_rel )
		);

		$results_rel = '/assets/css/ika_watupro_results.css';
		wp_enqueue_style(
			'ika-watupro-results',
			$ika_base_url . $results_rel,
			array( 'ika-master', 'ika-watupro-quiz' ),
			ika_gam_asset_ver( $results_rel )
		);


		// Achievements modal (IKA-owned)
		$ach_css_rel = '/assets/css/ika_achievements_modal.css';
		wp_enqueue_style(
			'ika-achievements-modal',
			$ika_base_url . $ach_css_rel,
			array( 'ika-master' ),
			ika_gam_asset_ver( $ach_css_rel )
		);

		$ach_js_rel = '/assets/js/ika_achievements_modal.js';
		wp_enqueue_script(
			'ika-achievements-modal',
			$ika_base_url . $ach_js_rel,
			array( 'jquery', 'jquery-ui-dialog' ),
			ika_gam_asset_ver( $ach_js_rel ),
			true
		);
	}

	// 3) jQuery UI Dialog (used by some UI bits)
	if ( is_user_logged_in() ) {
		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
	}

}, 20 );


// IKA XP Debug tool (admin-only, read-only)
$ika_xp_debug_path = plugin_dir_path( __FILE__ ) . 'debug/class-ika-xp-debug.php';
if ( file_exists( $ika_xp_debug_path ) ) {
    require_once $ika_xp_debug_path;
}
