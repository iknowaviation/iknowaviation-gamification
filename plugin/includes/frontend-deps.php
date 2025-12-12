<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ======================================================================
 * Front-end dependencies
 * ======================================================================*/

/* Ensure jQuery UI Dialog is available (for any modals/ui you might add) */
add_action( 'wp_enqueue_scripts', function() {
	if ( is_user_logged_in() ) {
		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
	}
} 
);