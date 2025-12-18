<?php
/**
 * Quiz CPT Enhancements
 * - Registers quiz-scoped taxonomies used for organization + recommendation signals.
 * - Forces the `quiz` CPT to be hierarchical so you can use parent/child URLs like:
 *     /quiz/intro-to-aviation/what-makes-an-airplane-fly/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Force CPT UI's `quiz` CPT to be hierarchical + support `page-attributes`.
 * This keeps the structure stable even if CPT UI settings get changed later.
 */
add_filter( 'register_post_type_args', function( array $args, string $post_type ) : array {
	if ( $post_type !== 'quiz' ) {
		return $args;
	}

	$args['hierarchical'] = true;

	// Ensure page-attributes is available so post_parent can be set cleanly in WP.
	if ( empty( $args['supports'] ) || ! is_array( $args['supports'] ) ) {
		$args['supports'] = [];
	}
	if ( ! in_array( 'page-attributes', $args['supports'], true ) ) {
		$args['supports'][] = 'page-attributes';
	}

	return $args;
}, 20, 2 );

/**
 * Register quiz taxonomies.
 * These are attached to the `quiz` CPT and used by the importer.
 */
add_action( 'init', function() {
	$post_types = [ 'quiz' ];

	$common = [
		'public'            => true,
		'show_ui'           => true,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'query_var'         => true,
		'rewrite'           => true,
	];

	// Track (course alignment): Intro to Aviation, Intro to Dispatch, Standalone...
	register_taxonomy( 'ika_quiz_track', $post_types, array_merge( $common, [
		'hierarchical' => true,
		'labels'       => [
			'name'          => 'Quiz Tracks',
			'singular_name' => 'Quiz Track',
			'menu_name'     => 'Tracks',
		],
	] ) );

	// Group (your 8 modules): Aviation Basics, Weather, ATC & Radio Basics...
	register_taxonomy( 'ika_quiz_group', $post_types, array_merge( $common, [
		'hierarchical' => true,
		'labels'       => [
			'name'          => 'Quiz Groups',
			'singular_name' => 'Quiz Group',
			'menu_name'     => 'Groups',
		],
	] ) );

	// Topic (fine-grain signals): Lift, Airspace, METAR...
	register_taxonomy( 'ika_quiz_topic', $post_types, array_merge( $common, [
		'hierarchical' => false,
		'labels'       => [
			'name'          => 'Quiz Topics',
			'singular_name' => 'Quiz Topic',
			'menu_name'     => 'Topics',
		],
	] ) );

	// Difficulty (beginner/intermediate/advanced). Useful as a taxonomy for filtering.
	register_taxonomy( 'ika_quiz_difficulty', $post_types, array_merge( $common, [
		'hierarchical' => false,
		'labels'       => [
			'name'          => 'Quiz Difficulty',
			'singular_name' => 'Quiz Difficulty',
			'menu_name'     => 'Difficulty',
		],
	] ) );

	// Audience (enthusiast/simmer/student pilot/etc.). Keep non-hierarchical for tagging.
	register_taxonomy( 'ika_quiz_audience', $post_types, array_merge( $common, [
		'hierarchical' => false,
		'labels'       => [
			'name'          => 'Quiz Audience',
			'singular_name' => 'Quiz Audience',
			'menu_name'     => 'Audience',
		],
	] ) );
}, 20 );
