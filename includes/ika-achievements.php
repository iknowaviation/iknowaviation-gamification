<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IKA Achievements (Option C)
 * - Watu Play is an ASSET LIBRARY only.
 * - IKA owns awarding + modal display.
 *
 * This build uses the Watu Play asset `content` field as the authoritative source
 * for the image markup (extracts the first <img ...> tag).
 */

function ika_ach_pending_key() { return 'ika_pending_achievements_v1'; }
function ika_ach_current_user_id() { $uid = get_current_user_id(); return $uid ? (int)$uid : 0; }

function ika_ach_get_pending( $user_id ) {
	$pending = get_user_meta( (int)$user_id, ika_ach_pending_key(), true );
	return is_array( $pending ) ? $pending : array();
}
function ika_ach_set_pending( $user_id, array $items ) {
	update_user_meta( (int)$user_id, ika_ach_pending_key(), array_values( $items ) );
}
function ika_ach_clear_pending( $user_id ) { delete_user_meta( (int)$user_id, ika_ach_pending_key() ); }

/**
 * Extract the first <img> tag from a blob of HTML.
 * Returns '' if no image tag found.
 */
function ika_ach_extract_first_img_tag( $html ) {
	if ( ! is_string( $html ) || $html === '' ) return '';

	// Quick path
	if ( stripos( $html, '<img' ) === false ) return '';

	// Match the first <img ...> tag (self closing or not)
	if ( preg_match( '/<img\b[^>]*>/i', $html, $m ) ) {
		// Sanitize: allow only img tag + its attrs
		$img = $m[0];
		return wp_kses( $img, array(
			'img' => array(
				'src'    => true,
				'srcset' => true,
				'sizes'  => true,
				'alt'    => true,
				'title'  => true,
				'class'  => true,
				'style'  => true,
				'width'  => true,
				'height' => true,
				'loading'=> true,
				'decoding'=> true,
				'referrerpolicy' => true,
			),
		) );
	}

	return '';
}

/**
 * Enrich pending items with Watu Play asset markup if missing.
 *
 * Supported item keys:
 * - asset_id (preferred)
 * - title (fallback exact match to asset `name`)
 *
 * Enrichment result keys:
 * - icon_html (preferred for rendering)
 * - graphic (fallback URL)
 */
function ika_ach_enrich_items_with_assets( array $items ) {
	if ( empty( $items ) ) return $items;

	if ( ! function_exists( 'ika_watuproplay_get_all_assets' ) ) {
		return $items;
	}

	$assets = ika_watuproplay_get_all_assets();
	if ( empty( $assets ) ) return $items;

	$by_id = array();
	$by_name = array();
	foreach ( $assets as $a ) {
		if ( ! empty( $a['id'] ) ) $by_id[ (int)$a['id'] ] = $a;
		if ( ! empty( $a['name'] ) ) $by_name[ strtolower( trim( $a['name'] ) ) ] = $a;
	}

	foreach ( $items as &$it ) {
		if ( ! is_array( $it ) ) continue;

		// If icon_html already provided, keep it.
		if ( ! empty( $it['icon_html'] ) ) continue;

		$asset = null;

		if ( ! empty( $it['asset_id'] ) && isset( $by_id[ (int)$it['asset_id'] ] ) ) {
			$asset = $by_id[ (int)$it['asset_id'] ];
		} elseif ( ! empty( $it['title'] ) ) {
			$key = strtolower( trim( (string) $it['title'] ) );
			if ( isset( $by_name[ $key ] ) ) $asset = $by_name[ $key ];
		}

		if ( ! $asset ) continue;

		// Use content field to pull the image tag (best)
		$content = isset( $asset['content'] ) ? (string) $asset['content'] : '';
		$img_tag = ika_ach_extract_first_img_tag( $content );
		if ( $img_tag ) {
			$it['icon_html'] = $img_tag;
			continue;
		}

		// Fallback to badge_graphic URL if present
		if ( empty( $it['graphic'] ) && ! empty( $asset['badge_graphic'] ) ) {
			$it['graphic'] = $asset['badge_graphic'];
		}
	}
	unset( $it );

	return $items;
}

function ika_ach_render_modal_html( array $items ) {
	if ( empty( $items ) ) return '';

	$items = ika_ach_enrich_items_with_assets( $items );

	ob_start(); ?>
	<div class="ika-ach-modal">
		<div class="ika-ach-body">
			<?php foreach ( $items as $it ) :
				$type  = isset($it['type']) ? sanitize_text_field($it['type']) : 'achievement';
				$title = isset($it['title']) ? sanitize_text_field($it['title']) : '';
				$sub   = isset($it['sub']) ? sanitize_text_field($it['sub']) : '';
				$kicker = ($type === 'level') ? 'New Level' : (($type === 'badge') ? 'New Badge' : 'Achievement');
				$icon_html = isset($it['icon_html']) ? $it['icon_html'] : '';
				$graphic = isset($it['graphic']) ? esc_url($it['graphic']) : '';
				?>
				<div class="ika-ach-item">
					<div class="ika-ach-icon">
						<?php
						if ( $icon_html ) {
							echo $icon_html; // already kses-sanitized
						} elseif ( $graphic ) {
							echo '<img src="' . $graphic . '" alt="" />';
						}
						?>
					</div>
					<div class="ika-ach-meta">
						<p class="ika-ach-kicker"><?php echo esc_html($kicker); ?></p>
						<p class="ika-ach-title"><?php echo esc_html($title ?: 'Achievement Unlocked'); ?></p>
						<?php if ( $sub ) : ?><p class="ika-ach-sub"><?php echo esc_html($sub); ?></p><?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* =====================================================================
 * Awarding pipeline (v1)
 * =====================================================================
 * Called after XP changes (typically after quiz completion + stats rebuild).
 *
 * Responsibilities:
 * - Detect crossed XP thresholds for:
 *   - Levels (IKA rank ladder)
 *   - Badges (Admin-configured XP milestone rules)
 * - Record "earned" state to prevent duplicate awards.
 * - Queue pending achievements into usermeta for the modal to display.
 */

/**
 * Deduplicate pending items by stable key.
 */
function ika_ach_item_key( array $it ) {
	$type = isset( $it['type'] ) ? (string) $it['type'] : 'achievement';
	$asset_id = isset( $it['asset_id'] ) ? (int) $it['asset_id'] : 0;
	$title = isset( $it['title'] ) ? strtolower( trim( (string) $it['title'] ) ) : '';
	return $type . '|' . $asset_id . '|' . $title;
}

/**
 * Get a Watu Play asset name by id (best-effort).
 */
function ika_ach_asset_name_by_id( $asset_id ) {
	$asset_id = (int) $asset_id;
	if ( $asset_id <= 0 ) return '';
	if ( ! function_exists( 'ika_watuproplay_get_all_assets' ) ) return '';
	$assets = ika_watuproplay_get_all_assets();
	if ( empty( $assets ) || ! is_array( $assets ) ) return '';
	foreach ( $assets as $a ) {
		if ( (int) ( $a['id'] ?? 0 ) === $asset_id ) {
			return (string) ( $a['name'] ?? '' );
		}
	}
	return '';
}

/**
 * Find the list of ladder steps crossed between old_xp (exclusive) and new_xp (inclusive).
 */
function ika_ach_get_crossed_ranks( $old_xp, $new_xp ) {
	$old_xp = (float) $old_xp;
	$new_xp = (float) $new_xp;
	if ( $new_xp <= $old_xp ) return array();
	if ( ! function_exists( 'ika_get_rank_ladder' ) ) return array();
	$ladder = ika_get_rank_ladder();
	if ( empty( $ladder ) || ! is_array( $ladder ) ) return array();
	$crossed = array();
	foreach ( $ladder as $step ) {
		$min = (float) ( $step['min_xp'] ?? 0 );
		if ( $min > $old_xp && $min <= $new_xp ) {
			$crossed[] = $step;
		}
	}
	// Lowest -> highest
	usort( $crossed, function( $a, $b ) {
		return (int)( $a['min_xp'] ?? 0 ) <=> (int)( $b['min_xp'] ?? 0 );
	} );
	return $crossed;
}

/**
 * Find active badge rules eligible at new_xp (<= new_xp). Does not require crossing; earned guard prevents duplicates.
 */
function ika_ach_get_crossed_badges( $old_xp, $new_xp ) {
	$old_xp = (float) $old_xp;
	$new_xp = (float) $new_xp;
	if ( $new_xp <= $old_xp ) return array();
	$rules = get_option( 'ika_ach_badge_rules_v1', array() );
	if ( empty( $rules ) || ! is_array( $rules ) ) return array();
	$crossed = array();
	foreach ( $rules as $badge_id => $r ) {
		if ( empty( $r['is_active'] ) ) continue;
		$xp = (int) ( $r['xp'] ?? 0 );
		if ( $xp <= $new_xp ) {
			$crossed[] = array(
				'badge_id' => (int) $badge_id,
				'xp'       => $xp,
			);
		}
	}
	usort( $crossed, function( $a, $b ) {
		return (int)( $a['xp'] ?? 0 ) <=> (int)( $b['xp'] ?? 0 );
	} );
	return $crossed;
}

/**
 * Main awarding entrypoint.
 *
 * @param int   $user_id
 * @param float $old_xp
 * @param float $new_xp
 * @param array $context (optional)
 */
function ika_ach_process_awards_after_xp_change( $user_id, $old_xp, $new_xp, array $context = array() ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) return;

	$old_xp = (float) $old_xp;
	$new_xp = (float) $new_xp;
	if ( $new_xp <= $old_xp ) return;

	$pending = ika_ach_get_pending( $user_id );
	if ( ! is_array( $pending ) ) $pending = array();
	$seen = array();
	foreach ( $pending as $p ) {
		if ( is_array( $p ) ) $seen[ ika_ach_item_key( $p ) ] = true;
	}

	$to_add = array();

	// 1) Levels (rank ladder)
	$level_map = get_option( 'ika_ach_level_map_v1', array() );
	if ( ! is_array( $level_map ) ) $level_map = array();

	$crossed_ranks = ika_ach_get_crossed_ranks( $old_xp, $new_xp );
	foreach ( $crossed_ranks as $step ) {
		$label = (string) ( $step['label'] ?? '' );
		$slug  = sanitize_title( $label );
		$min   = (int) ( $step['min_xp'] ?? 0 );

		// Idempotency guard
		$earned_key = 'ika_level_earned_' . $slug;
		if ( get_user_meta( $user_id, $earned_key, true ) ) continue;

		$asset_id = 0;
		if ( isset( $level_map[ $slug ] ) ) {
			$asset_id = (int) $level_map[ $slug ];
		}

		$item = array(
			'timestamp' => time(),
			'type'      => 'level',
			'title'     => $label ?: 'New Level',
			'sub'       => $min ? ( 'Reached ' . $min . ' XP' ) : '',
		);
		if ( $asset_id > 0 ) {
			$item['asset_id'] = $asset_id;
		}

		$key = ika_ach_item_key( $item );
		if ( ! isset( $seen[ $key ] ) ) {
			$to_add[] = $item;
			$seen[ $key ] = true;
		}

		update_user_meta( $user_id, $earned_key, 1 );
		update_user_meta( $user_id, 'ika_last_awarded_rank_slug', $slug );
	}

	// 2) Badges (XP milestone rules)
	$crossed_badges = ika_ach_get_crossed_badges( $old_xp, $new_xp );
	foreach ( $crossed_badges as $b ) {
		$badge_id = (int) ( $b['badge_id'] ?? 0 );
		$xp       = (int) ( $b['xp'] ?? 0 );
		if ( $badge_id <= 0 ) continue;

		$earned_key = 'ika_badge_earned_' . $badge_id;
		if ( get_user_meta( $user_id, $earned_key, true ) ) continue;

		$title = ika_ach_asset_name_by_id( $badge_id );
		if ( ! $title ) $title = 'New Badge';

		$item = array(
			'timestamp' => time(),
			'type'      => 'badge',
			'asset_id'  => $badge_id,
			'title'     => $title,
			'sub'       => $xp ? ( 'Reached ' . $xp . ' XP' ) : '',
		);

		$key = ika_ach_item_key( $item );
		if ( ! isset( $seen[ $key ] ) ) {
			$to_add[] = $item;
			$seen[ $key ] = true;
		}

		update_user_meta( $user_id, $earned_key, 1 );
	}

	if ( empty( $to_add ) ) return;

	// Recommended order: levels first, then badges; within each group, keep the order we built.
	$pending = array_merge( $pending, $to_add );
	ika_ach_set_pending( $user_id, $pending );
}

/** AJAX: fetch pending achievements for modal */
function ika_ajax_fetch_pending_achievements() {
	if ( ! is_user_logged_in() ) wp_send_json_success( array( 'has' => false ) );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
	if ( ! wp_verify_nonce( $nonce, 'ika_ach_nonce' ) ) wp_send_json_success( array( 'has' => false ) );

	$user_id = ika_ach_current_user_id();

	// Test: JS passes test=1 when URL has ?ika_ach_test=1
	$test = isset($_POST['test']) ? sanitize_text_field($_POST['test']) : '0';
	if ( $test === '1' && current_user_can('manage_options') ) {
		ika_ach_set_pending( $user_id, array(
			array(
				'type'  => 'badge',
				'title' => 'Test Achievement',
				'sub'   => 'This is a forced test modal.',
			)
		) );
	}

	$pending = ika_ach_get_pending( $user_id );
	if ( empty( $pending ) ) wp_send_json_success( array( 'has' => false ) );

	wp_send_json_success( array(
		'has'   => true,
		'html'  => ika_ach_render_modal_html( $pending ),
		'count' => count($pending),
	) );
}
add_action( 'wp_ajax_ika_fetch_pending_achievements', 'ika_ajax_fetch_pending_achievements' );

/** Clear queue */
function ika_ajax_clear_pending_achievements() {
	if ( ! is_user_logged_in() ) wp_send_json_success();
	$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
	if ( ! wp_verify_nonce( $nonce, 'ika_ach_nonce' ) ) wp_send_json_success();
	ika_ach_clear_pending( ika_ach_current_user_id() );
	wp_send_json_success();
}
add_action( 'wp_ajax_ika_clear_pending_achievements', 'ika_ajax_clear_pending_achievements' );

/** Enqueue modal assets (front-end) */
function ika_ach_enqueue_modal_assets() {
	if ( ! is_user_logged_in() ) return;
	if ( function_exists('is_singular') && ! is_singular('quiz') ) return;

	wp_enqueue_script( 'jquery-ui-dialog' );

	$css_url = plugins_url( 'assets/css/ika_achievements_modal.css', dirname(__FILE__) );
	$js_url  = plugins_url( 'assets/js/ika_achievements_modal.js', dirname(__FILE__) );

	wp_enqueue_style( 'ika-achievements-modal', $css_url, array(), '1.0.3' );
	wp_enqueue_script( 'ika-achievements-modal', $js_url, array('jquery','jquery-ui-dialog'), '1.0.3', true );

	wp_localize_script( 'ika-achievements-modal', 'IKAAch', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'ika_ach_nonce' ),
	) );
}
add_action( 'wp_enqueue_scripts', 'ika_ach_enqueue_modal_assets', 30 );

/** Footer injection: always include container so JS can fill it */
function ika_ach_print_modal_container() {
	if ( ! is_user_logged_in() ) return;
	if ( function_exists('is_singular') && ! is_singular('quiz') ) return;
	echo '<div id="ika-achievements-modal" style="display:none;"></div>';
}
add_action( 'wp_footer', 'ika_ach_print_modal_container', 99 );

/** Admin UI + asset helpers */
require_once __DIR__ . '/watuproplay-levels.php';
if ( file_exists( __DIR__ . '/ika-achievements-admin.php' ) ) {
	require_once __DIR__ . '/ika-achievements-admin.php';
}

if ( ! function_exists('ika_gam_render_achievements_page') ) {
	function ika_gam_render_achievements_page() {
		if ( ! current_user_can('manage_options') ) return;
		$table = function_exists('ika_watuproplay_levels_table') ? ika_watuproplay_levels_table() : '';
		$total = function_exists('ika_watuproplay_assets_count') ? ika_watuproplay_assets_count() : 0;
		echo '<div class="wrap"><h1>IKA Achievements</h1>';
		echo '<p>Assets table: <code>' . esc_html($table) . '</code> (rows: ' . esc_html($total) . ')</p>';
		echo '</div>';
	}
}
