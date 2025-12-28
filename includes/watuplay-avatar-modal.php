<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WATU Play → unified modal enhancer
 * - Detects unlocked level + badges from the WATU modal text
 * - Rebuilds #watuproplay-modal markup into a clean layout
 * - Pulls badge/level display HTML from wp_*_watuproplay_levels.content
 * - Shows ALL HTML in that content field (image or not, or empty)
 * - Uses atype column for filtering (badge / level)
 * - Uses level icon as avatar (optional) IF the HTML contains <img src="">
 */

/**
 * AJAX: save level icon URL (and optional level name) as user's avatar.
 */
add_action( 'wp_ajax_ika_set_level_avatar', 'ika_set_level_avatar' );
function ika_set_level_avatar() {
	check_ajax_referer( 'ika_set_level_avatar', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ) );
	}

	$image_url  = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
	$level_name = isset( $_POST['level_name'] ) ? sanitize_text_field( wp_unslash( $_POST['level_name'] ) ) : '';

	if ( ! $image_url ) {
		wp_send_json_error( array( 'message' => 'no_url' ) );
	}

	$user_id = get_current_user_id();
	update_user_meta( $user_id, 'ika_level_avatar_url', $image_url );

	if ( $level_name ) {
		update_user_meta( $user_id, 'ika_level_name', $level_name );
	}

	wp_send_json_success();
}

/**
 * Filter get_avatar() so UsersWP + core avatars use the level icon where set.
 */
add_filter( 'get_avatar', 'ika_filter_get_avatar_to_use_level_icon', 10, 6 );
function ika_filter_get_avatar_to_use_level_icon( $avatar, $id_or_email, $size, $default, $alt, $args ) {

	$user = null;

	if ( is_numeric( $id_or_email ) ) {
		$user = get_user_by( 'id', (int) $id_or_email );
	} elseif ( $id_or_email instanceof WP_User ) {
		$user = $id_or_email;
	} elseif ( $id_or_email instanceof WP_Comment && $id_or_email->user_id ) {
		$user = get_user_by( 'id', (int) $id_or_email->user_id );
	} elseif ( is_string( $id_or_email ) && strpos( $id_or_email, '@' ) !== false ) {
		$user = get_user_by( 'email', $id_or_email );
	}

	if ( ! $user ) return $avatar;

	$url = get_user_meta( $user->ID, 'ika_level_avatar_url', true );
	if ( ! $url ) return $avatar;

	$size = (int) $size;
	$alt  = esc_attr( $alt ?: $user->display_name );

	return sprintf(
		'<img src="%s" class="%s" width="%d" height="%d" alt="%s" loading="lazy" />',
		esc_url( $url ),
		esc_attr( 'avatar avatar-' . $size . ' ika-level-avatar' ),
		$size,
		$size,
		$alt
	);
}

/**
 * DB helper: fetch [name => content_html] from WATU Play levels table filtered by atype.
 * Your table has an "atype" column (default 'badge'), so we use it as the WHERE clause.
 */
function ika_watuproplay_get_html_map_by_atype( $atype ) {
	global $wpdb;

	$table = $wpdb->prefix . 'watuproplay_levels';

	$atype = sanitize_text_field( (string) $atype );
	if ( $atype === '' ) return array();

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT name, content FROM {$table} WHERE atype = %s",
			$atype
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) return array();

	$out = array();
	foreach ( $rows as $r ) {
		$name = isset( $r['name'] ) ? trim( (string) $r['name'] ) : '';
		if ( $name === '' ) continue;

		$html = isset( $r['content'] ) ? (string) $r['content'] : '';
		$html = wp_kses_post( $html ); // admin-controlled HTML, safe for front-end

		$out[ $name ] = $html;
	}

	return $out;
}

/**
 * Badge + level maps (using atype filter)
 * - Badges: atype='badge' (confirmed by your table)
 * - Levels: commonly atype='level' but some installs may use other labels
 */
function ika_watuproplay_get_badge_html_map() {
	return ika_watuproplay_get_html_map_by_atype( 'badge' );
}

function ika_watuproplay_get_level_html_map() {
    return ika_watuproplay_get_html_map_by_atype( 'level' );
}

/**
 * CSS for the enhanced modal content (keeps it readable + prevents overlap)
 */
add_action( 'wp_head', function() {
	if ( ! is_user_logged_in() ) return;
	?>
	<style>
		/* Force the content area to match your dark theme */
		#watuproplay-modal.ui-dialog-content {
			background: #0D131F !important;
			color: #E6EEF8 !important;
			padding: 0 !important;
			overflow: visible !important;
		}

		/* If WATU's <p> remains (failsafe), keep it readable */
		#watuproplay-modal p {
			margin: 0.5rem 0 !important;
			line-height: 1.35 !important;
			color: #E6EEF8 !important;
		}

		/* Enhanced layout wrapper */
		#watuproplay-modal .ika-badge-modal-inner {
			padding: 16px 18px;
			text-align: center;
		}

		/* Bigger primary line: "You've earned new badges:" */
		#watuproplay-modal .ika-badge-modal-subheading--primary{
			font-size: 15px;
			font-weight: 750;
			line-height: 1.35;
			margin: 6px 0 12px 0;
			opacity: 0.95;
		}

		#watuproplay-modal .ika-badge-modal-section-label {
			margin: 12px 0 8px;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			opacity: 0.85;
		}

		#watuproplay-modal .ika-badge-modal-list {
			display: flex;
			flex-wrap: wrap;
			justify-content: center;
			gap: 10px 14px;
		}

		#watuproplay-modal .ika-badge-modal-item {
			display: flex;
			flex-direction: column;
			align-items: center;
			max-width: 120px;
		}

		/* This container will receive *all HTML* from DB */
		#watuproplay-modal .ika-badge-modal-media {
			display: block;
			margin: 0 0 6px 0;
			line-height: 0;
		}

		/* If that HTML includes an image, normalize it */
		#watuproplay-modal .ika-badge-modal-media img{
			width: 74px;
			height: 74px;
			object-fit: contain;
			display: block;
			filter: drop-shadow(0 6px 14px rgba(0,0,0,0.35));
		}

		#watuproplay-modal .ika-badge-modal-title {
			font-size: 12.5px;
			line-height: 1.25;
			margin: 0;
		}
	</style>
	<?php
}, 50 );

/**
 * JS: rebuild the modal when it opens
 */
add_action( 'wp_footer', function() {

	if ( ! is_user_logged_in() ) return;

	$badge_html = ika_watuproplay_get_badge_html_map();
	$level_html = ika_watuproplay_get_level_html_map();

	$ajax_url = admin_url( 'admin-ajax.php' );
	$nonce    = wp_create_nonce( 'ika_set_level_avatar' );
	?>
	<script>
	jQuery(function($){

		var badgeHtmlMap = <?php echo wp_json_encode( $badge_html ); ?> || {};
		var levelHtmlMap = <?php echo wp_json_encode( $level_html ); ?> || {};

		$(document).on('dialogopen', '#watuproplay-modal', function(){

			var $content = $('#watuproplay-modal');
			if (!$content.length) return;

			if ($content.data('ika-play-enhanced')) return;

			var $dialog = $content.closest('.ui-dialog');
			$dialog.find('.ui-dialog-title').text('Achievement Unlocked');

			// Helper: extract first img src from an HTML blob
			function extractImgSrc(html) {
				if (!html) return '';
				var $tmp = $('<div></div>').html(html);
				return ($tmp.find('img').first().attr('src') || '').trim();
			}

			// Parse LEVEL + BADGES from the original WATU markup (most reliable)
			var levelName = null;
			var badgeNames = [];

			$content.find('p').each(function(){
				var pText = ($(this).text() || '').toLowerCase();
				var bold  = $.trim($(this).find('b').first().text() || '');

				if (pText.indexOf('new level') !== -1 && bold) {
					levelName = bold;
				}
				if (pText.indexOf('new badge') !== -1 && bold) {
					badgeNames.push(bold);
				}
			});

			// If nothing detected, keep the stock modal
			if (!levelName && !badgeNames.length) return;

			// Build enhanced layout
			var $wrap = $('<div class="ika-badge-modal-inner"></div>');

			// Primary message (larger). No redundant "Achievement Unlocked!" line here.
			var subtitle;
			if (levelName && badgeNames.length) subtitle = "You’ve unlocked a new level and earned new badges:";
			else if (levelName) subtitle = "You’ve unlocked a new level:";
			else subtitle = "You’ve earned new badges:";

			// Level section (if present)
			if (levelName) {
				var lvlHtml = levelHtmlMap[levelName] || '';
				$wrap.append('<div class="ika-badge-modal-section-label">New Level Achieved</div>');

				var $lvlList = $('<div class="ika-badge-modal-list"></div>');
				var $lvlItem = $('<div class="ika-badge-modal-item"></div>');

				// Show ALL HTML from DB (image or not)
				if (lvlHtml) {
					$lvlItem.append($('<div class="ika-badge-modal-media"></div>').html(lvlHtml));
				}

				$lvlItem.append('<div class="ika-badge-modal-title">' + levelName + '</div>');
				$lvlList.append($lvlItem);
				$wrap.append($lvlList);

				// Save avatar only if HTML includes an <img src="">
				var avatarUrl = extractImgSrc(lvlHtml);
				if (avatarUrl) {
					$.post('<?php echo esc_js( $ajax_url ); ?>', {
						action: 'ika_set_level_avatar',
						nonce:  '<?php echo esc_js( $nonce ); ?>',
						image_url: avatarUrl,
						level_name: levelName
					});
				}
			}

			// Badges section
			if (badgeNames.length) {
				var badgeLabel = (badgeNames.length === 1) ? 'New Badge Earned' : 'New Badges Earned';
				$wrap.append('<div class="ika-badge-modal-section-label">' + badgeLabel + '</div>');

				var $bList = $('<div class="ika-badge-modal-list"></div>');

				badgeNames.forEach(function(name){
					var html = badgeHtmlMap[name] || '';
					var $item = $('<div class="ika-badge-modal-item"></div>');

					// Show ALL HTML from DB (image or not)
					if (html) {
						$item.append($('<div class="ika-badge-modal-media"></div>').html(html));
					}

					$item.append('<div class="ika-badge-modal-title">' + name + '</div>');
					$bList.append($item);
				});

				$wrap.append($bList);
			}

			// Replace stock content with enhanced content
			$content.empty().append($wrap).data('ika-play-enhanced', true);
		});
	});
	</script>
	<?php
}, 999 );
