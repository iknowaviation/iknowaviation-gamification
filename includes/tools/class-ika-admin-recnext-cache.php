<?php
/**
 * Admin Tool: Clear Recommended Next Cache
 *
 * Adds a Tools submenu item:
 *   Tools → IKA: Clear Recommended Next Cache
 *
 * Clears:
 * - _transient_ika_rec_next_*
 * - _transient_timeout_ika_rec_next_*
 * - _transient_ika_rec_thr_*
 * - _transient_timeout_ika_rec_thr_*
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class IKA_Admin_RecNext_Cache_Tool {

	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_ika_clear_recnext_cache', [ __CLASS__, 'handle' ] );
	}

	public static function menu() : void {
		add_management_page(
			'IKA: Clear Recommended Next Cache',
			'IKA: Clear RecNext Cache',
			'manage_options',
			'ika-clear-recnext-cache',
			[ __CLASS__, 'render' ]
		);
	}

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$done = isset($_GET['ika_done']) ? sanitize_text_field($_GET['ika_done']) : '';
		?>
		<div class="wrap">
			<h1>Clear Recommended Next Cache</h1>
			<p>This clears cached Recommended Next results (per-user) and pass-threshold caches.</p>

			<?php if ( $done === '1' ) : ?>
				<div class="notice notice-success"><p>Recommended Next cache cleared.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
				<?php wp_nonce_field( 'ika_clear_recnext_cache' ); ?>
				<input type="hidden" name="action" value="ika_clear_recnext_cache" />
				<p>
					<button class="button button-primary">Clear Recommended Next Cache</button>
				</p>
			</form>
		</div>
		<?php
	}

	public static function handle() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die('Insufficient permissions.');
		}
		check_admin_referer( 'ika_clear_recnext_cache' );

		global $wpdb;

		$patterns = [
			'_transient_ika_rec_next_%',
			'_transient_timeout_ika_rec_next_%',
			'_transient_ika_rec_thr_%',
			'_transient_timeout_ika_rec_thr_%',
		];

		foreach ( $patterns as $like ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			) );
		}

		wp_safe_redirect( admin_url('tools.php?page=ika-clear-recnext-cache&ika_done=1') );
		exit;
	}
}

IKA_Admin_RecNext_Cache_Tool::init();
