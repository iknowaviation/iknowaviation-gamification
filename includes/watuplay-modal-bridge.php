<?php
/**
 * IKA WatuPlay Modal Bridge (Phase 1)
 *
 * Goal:
 * - Take control of the achievement modal UI on the quiz results page.
 * - Continue to let WatuPlay calculate earned badges/levels for now.
 *
 * How it works:
 * - On watupro_completed_exam (same action WatuPlay uses), snapshot a user's WatuPlay
 *   state BEFORE and AFTER WatuPlay updates it.
 * - Diff to determine newly earned badge(s)/level(s).
 * - Store the result in a transient keyed by taking_id.
 *
 * Notes:
 * - This does NOT change WatuPlay's award logic; it only observes before/after.
 * - This bridge is a stepping stone to Phase 2 (IKA-owned XP ledger + IKA-owned achievements).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IKA_WatuPlay_Modal_Bridge {

	/**
	 * Attach hooks.
	 */
	public static function init() : void {
		// Run before WatuPlay updates meta (WatuPlay hooks at default priority 10).
		add_action( 'watupro_completed_exam', array( __CLASS__, 'snapshot_before' ), 5 );

		// Run after WatuPlay updates meta.
		add_action( 'watupro_completed_exam', array( __CLASS__, 'snapshot_after' ), 20 );
	}

	/**
	 * Snapshot BEFORE WatuPlay updates.
	 */
	public static function snapshot_before( $taking_id ) : void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// WatuPlay stores these keys on most installs.
		$level  = get_user_meta( $user_id, 'watuproplay_user_level', true );
		$badges = get_user_meta( $user_id, 'watuproplay_user_badges', true );

		update_user_meta( $user_id, '_ika_wpplay_before_level', $level );
		update_user_meta( $user_id, '_ika_wpplay_before_badges', $badges );
	}

	/**
	 * Snapshot AFTER WatuPlay updates, compute diff, store transient.
	 */
	public static function snapshot_after( $taking_id ) : void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$taking_id = (int) $taking_id;
		if ( $taking_id <= 0 ) {
			return;
		}

		$before_level  = get_user_meta( $user_id, '_ika_wpplay_before_level', true );
		$after_level   = get_user_meta( $user_id, 'watuproplay_user_level', true );

		$before_badges = get_user_meta( $user_id, '_ika_wpplay_before_badges', true );
		$after_badges  = get_user_meta( $user_id, 'watuproplay_user_badges', true );

		// Normalize badge arrays (WatuPlay sometimes stores serialized arrays).
		$before_arr = is_array( $before_badges ) ? $before_badges : (array) maybe_unserialize( $before_badges );
		$after_arr  = is_array( $after_badges )  ? $after_badges  : (array) maybe_unserialize( $after_badges );

		$before_arr = array_values( array_filter( array_map( 'strval', $before_arr ) ) );
		$after_arr  = array_values( array_filter( array_map( 'strval', $after_arr ) ) );

		$earned_level  = ( $after_level && (string) $after_level !== (string) $before_level ) ? (string) $after_level : '';
		$earned_badges = array_values( array_diff( $after_arr, $before_arr ) );

		if ( $earned_level === '' && empty( $earned_badges ) ) {
			// Nothing earned; clear any prior transient for this taking_id.
			delete_transient( self::key( $taking_id ) );
			return;
		}

		$payload = array(
			'level'  => $earned_level,
			'badges' => $earned_badges,
		);

		set_transient( self::key( $taking_id ), $payload, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Retrieve earned payload for a taking_id.
	 */
	public static function get_earned_for_taking( int $taking_id ) {
		if ( $taking_id <= 0 ) {
			return null;
		}
		return get_transient( self::key( $taking_id ) );
	}

	private static function key( int $taking_id ) : string {
		return 'ika_wpplay_earned_' . $taking_id;
	}
}

// IMPORTANT (2026-01): IKA now owns XP + achievements.
// Keep WatuPlay as an ASSET LIBRARY ONLY (icons/names) unless explicitly enabled.
// To re-enable the bridge, add this in wp-config.php or via a must-use plugin:
// define('IKA_ENABLE_WATUPLAY_MODAL_BRIDGE', true);
if ( defined( 'IKA_ENABLE_WATUPLAY_MODAL_BRIDGE' ) && IKA_ENABLE_WATUPLAY_MODAL_BRIDGE ) {
	IKA_WatuPlay_Modal_Bridge::init();
}
