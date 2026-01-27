<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * IKA Avatar (Level Icon) Support
 *
 * Under Option C, IKA owns achievements + the modal. We still keep the
 * "level icon as avatar" feature because it works well with the Flight Deck
 * and UsersWP.
 *
 * The achievement modal JS calls the AJAX action below when a new level is earned.
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
