<?php
/**
 * Admin Tool: Recommended Next Settings
 *
 * Adds:
 *  Tools → IKA: Recommended Next
 *
 * Options:
 *  - ika_recnext_group_order (one slug per line)
 *  - ika_recnext_track_term  (optional track term slug; blank disables track filtering)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class IKA_Admin_RecNext_Settings {

	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_ika_save_recnext_settings', [ __CLASS__, 'save' ] );
	}

	public static function menu() : void {
		add_management_page(
			'IKA: Recommended Next',
			'IKA: Recommended Next',
			'manage_options',
			'ika-recnext-settings',
			[ __CLASS__, 'render' ]
		);
	}

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$group_order = (string) get_option( 'ika_recnext_group_order', '' );
		if ( trim($group_order) === '' ) {
			$group_order = implode("\n", [
				'aviation-basics',
				'aircraft-systems',
				'airports-airport-operations',
				'weather-basics',
				'navigation-basics',
				'airspace-and-regulations',
				'flight-planning-and-performance',
				'atc-and-radio-basics',
			]);
		}

		$track_term = (string) get_option( 'ika_recnext_track_term', '' );
		$done = isset($_GET['ika_saved']) ? sanitize_text_field($_GET['ika_saved']) : '';
		?>
		<div class="wrap">
			<h1>Recommended Next Settings</h1>
			<p>Configure the progression order and optionally restrict recommendations to a specific Track (like Intro to Aviation or Intro to Dispatch).</p>

			<?php if ( $done === '1' ) : ?>
				<div class="notice notice-success"><p>Settings saved.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
				<?php wp_nonce_field( 'ika_save_recnext_settings' ); ?>
				<input type="hidden" name="action" value="ika_save_recnext_settings" />

				<h2>Group Order (slugs)</h2>
				<p>One group slug per line. This defines the progression order across Groups.</p>
				<textarea name="ika_recnext_group_order" rows="10" style="width:100%;max-width:700px;"><?php echo esc_textarea( $group_order ); ?></textarea>

				<h2 style="margin-top:18px;">Track Filter (optional)</h2>
				<p>Leave blank to recommend across all tracks. To restrict to a track, enter the Track term slug (example: <code>intro-to-aviation</code>).</p>
				<input type="text" name="ika_recnext_track_term" value="<?php echo esc_attr( $track_term ); ?>" style="width:100%;max-width:420px;" />

				<p style="margin-top:16px;">
					<button class="button button-primary">Save Settings</button>
				</p>
			</form>
		</div>
		<?php
	}

	public static function save() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die('Insufficient permissions.');
		check_admin_referer( 'ika_save_recnext_settings' );

		$order = isset($_POST['ika_recnext_group_order']) ? (string) wp_unslash($_POST['ika_recnext_group_order']) : '';
		$track = isset($_POST['ika_recnext_track_term']) ? (string) wp_unslash($_POST['ika_recnext_track_term']) : '';

		update_option( 'ika_recnext_group_order', $order, false );
		update_option( 'ika_recnext_track_term', sanitize_title($track), false );

		wp_safe_redirect( admin_url('tools.php?page=ika-recnext-settings&ika_saved=1') );
		exit;
	}
}

IKA_Admin_RecNext_Settings::init();
