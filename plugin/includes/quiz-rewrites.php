<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Quiz CPT: support URLs like /quiz/{parent}/{child}/
 *
 * CPT UI + hierarchical CPTs can be finicky with rewrite generation.
 * These explicit rewrite rules ensure child quiz URLs resolve reliably.
 *
 * After deploying this file, you MUST flush permalinks once:
 * WP Admin → Settings → Permalinks → Save Changes.
 */
add_action( 'init', function() {

	// Two-level: /quiz/parent/child/
	add_rewrite_rule(
		'^quiz/([^/]+)/([^/]+)/?$',
		'index.php?post_type=quiz&pagename=$matches[1]/$matches[2]',
		'top'
	);

	// Three-level (optional safety): /quiz/grandparent/parent/child/
	add_rewrite_rule(
		'^quiz/([^/]+)/([^/]+)/([^/]+)/?$',
		'index.php?post_type=quiz&pagename=$matches[1]/$matches[2]/$matches[3]',
		'top'
	);

}, 20 );

/**
 * Force the permalink output for quiz posts to include ancestor slugs.
 * This keeps URLs consistent with the rewrite rules above.
 */
add_filter( 'post_type_link', function( $permalink, $post ) {

	if ( ! ( $post instanceof WP_Post ) || $post->post_type !== 'quiz' ) {
		return $permalink;
	}

	$ancestors = get_post_ancestors( $post );
	if ( empty( $ancestors ) ) {
		return home_url( '/quiz/' . $post->post_name . '/' );
	}

	$ancestors = array_reverse( $ancestors );

	$parts = [ 'quiz' ];
	foreach ( $ancestors as $ancestor_id ) {
		$parts[] = get_post_field( 'post_name', $ancestor_id );
	}
	$parts[] = $post->post_name;

	return home_url( '/' . implode( '/', array_filter( $parts ) ) . '/' );

}, 99, 2 );
