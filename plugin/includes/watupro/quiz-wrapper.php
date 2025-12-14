<?php
/**
 * WatuPRO Quiz Output Wrapper
 *
 * Automatically wraps WatuPRO quiz output in a consistent
 * container for styling and layout purposes.
 *
 * <div class="ika-quiz-page hero-jet">
 *     [watupro X]
 * </div>
 *
 * This ensures all Quiz CPT pages have consistent markup,
 * regardless of how the shortcode is entered in post content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'do_shortcode_tag', function ( $output, $tag, $attr ) {

	// Only target WatuPRO shortcode
	if ( $tag !== 'watupro' ) {
		return $output;
	}

	// Only wrap on single Quiz CPT pages
	if ( ! is_singular( 'quiz' ) ) {
		return $output;
	}

	// Prevent double-wrapping (legacy posts)
	if ( strpos( $output, 'class="ika-quiz-page' ) !== false ) {
		return $output;
	}

	return '<div class="ika-quiz-page hero-jet">' . $output . '</div>';

}, 10, 3 );
