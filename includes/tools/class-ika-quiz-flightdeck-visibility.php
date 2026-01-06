<?php
/**
 * Quiz CPT: Flight Deck Visibility Toggle
 *
 * Adds a sidebar meta box to Quiz CPT:
 *  - "Hide from Flight Deck (Recommended Next)"
 *
 * Stores:
 *  - _ika_hide_from_flightdeck = 1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class IKA_Quiz_FlightDeck_Visibility {

	const META_KEY = '_ika_hide_from_flightdeck';

	public static function init() : void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_box' ] );
		add_action( 'save_post_quiz', [ __CLASS__, 'save' ], 20, 2 );
	}

	public static function add_box() : void {
		add_meta_box(
			'ika_flightdeck_visibility',
			'Flight Deck',
			[ __CLASS__, 'render' ],
			'quiz',
			'side',
			'high'
		);
	}

	public static function render( \WP_Post $post ) : void {
		$value = get_post_meta( $post->ID, self::META_KEY, true );
		$checked = (string)$value === '1';

		wp_nonce_field( 'ika_fd_visibility_save', 'ika_fd_visibility_nonce' );
		?>
		<p style="margin:0 0 8px;">
			<label>
				<input type="checkbox" name="ika_hide_from_flightdeck" value="1" <?php checked( $checked ); ?> />
				Hide from Flight Deck (Recommended Next)
			</label>
		</p>
		<p style="margin:0;color:#666;font-size:12px;line-height:1.35;">
			Use this for internal tests, importer quizzes, or anything you don’t want suggested.
		</p>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ) : void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( ! isset( $_POST['ika_fd_visibility_nonce'] ) ) return;
		if ( ! wp_verify_nonce( $_POST['ika_fd_visibility_nonce'], 'ika_fd_visibility_save' ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$hide = isset($_POST['ika_hide_from_flightdeck']) ? '1' : '0';

		if ( $hide === '1' ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}
}

IKA_Quiz_FlightDeck_Visibility::init();
