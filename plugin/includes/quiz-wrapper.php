<?php
/**
 * WatuPRO Quiz Output Wrapper (Quiz-only)
 *
 * Wrap ONLY the rendered WatuPRO quiz block on single Quiz CPT pages in:
 *   <div class="ika-quiz-page hero-jet"> ... </div>
 *
 * This keeps your Elementor header/title/layout untouched and only targets
 * the quiz markup (the #watupro_quiz container).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find the end offset (exclusive) of the matching closing </div> for the first
 * <div ...> tag that starts at (or after) $start.
 */
function ika_find_matching_div_end( string $html, int $start ): int {
	$len   = strlen( $html );
	$pos   = $start;
	$depth = 0;

	// Find the first <div ...> tag starting at/after $start.
	if ( ! preg_match( '/<div\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE, $start ) ) {
		return -1;
	}
	$pos   = $m[0][1];
	$depth = 0;

	// Tokenize <div ...> and </div> tags from the first <div> onward.
	if ( preg_match_all( '/<\/?div\b[^>]*>/i', $html, $tokens, PREG_OFFSET_CAPTURE, $pos ) ) {
		foreach ( $tokens[0] as $tok ) {
			$tag = $tok[0];
			$off = $tok[1];

			if ( stripos( $tag, '</div' ) === 0 ) {
				$depth--;
				if ( $depth === 0 ) {
					return $off + strlen( $tag );
				}
			} else {
				$depth++;
			}
		}
	}

	return -1;
}

/**
 * Primary: wrap the #watupro_quiz block inside the_content.
 */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'quiz' ) ) {
		return $content;
	}

	if ( ! is_string( $content ) || $content === '' ) {
		return $content;
	}

	// Already wrapped?
	if ( strpos( $content, 'class="ika-quiz-page' ) !== false || strpos( $content, "class='ika-quiz-page" ) !== false ) {
		return $content;
	}

	// Locate the quiz container produced by WatuPRO.
	if ( ! preg_match( '/<div\b[^>]*\bid=["\']watupro_quiz["\'][^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		return $content;
	}

	$start = (int) $m[0][1];
	$end   = ika_find_matching_div_end( $content, $start );
	if ( $end < 0 || $end <= $start ) {
		return $content;
	}

	$quiz_html = substr( $content, $start, $end - $start );
	$wrapped   = '<div class="ika-quiz-page hero-jet">' . $quiz_html . '</div>';

	return substr( $content, 0, $start ) . $wrapped . substr( $content, $end );
}, 50 );

/**
 * Fallback: if the quiz is rendered purely via [watupro] inside content and
 * the_content doesn't contain #watupro_quiz for some reason, wrap shortcode output.
 */
add_filter( 'do_shortcode_tag', function ( $output, $tag, $attr ) {
	if ( $tag !== 'watupro' ) {
		return $output;
	}
	if ( ! is_singular( 'quiz' ) ) {
		return $output;
	}
	if ( strpos( $output, 'class="ika-quiz-page' ) !== false || strpos( $output, "class='ika-quiz-page" ) !== false ) {
		return $output;
	}
	return '<div class="ika-quiz-page hero-jet">' . $output . '</div>';
}, 10, 3 );
