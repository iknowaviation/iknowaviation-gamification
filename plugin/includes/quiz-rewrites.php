<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Quiz URL routing: Page Hub → Quiz CPT child
 *
 * Desired URL:
 *   /quiz/{hub-slug}/{quiz-slug}/
 *
 * - {hub-slug} is a WordPress Page under the /quiz/ section (e.g. /quiz/intro-to-aviation/)
 * - {quiz-slug} is a Quiz CPT post (post_type=quiz)
 *
 * We implement this with a rewrite rule + a lightweight guard that ensures the quiz
 * being requested belongs to the hub slug (stored in post meta by the importer).
 *
 * IMPORTANT: After deploying changes, flush permalinks:
 *   WP Admin → Settings → Permalinks → Save Changes
 */

// Allow our custom query var from rewrite.
add_filter( 'query_vars', function( $vars ) {
	$vars[] = 'ika_hub';
	return $vars;
} );

// Rewrite: /quiz/hub/quiz/ → load quiz by name + capture hub segment.
add_action( 'init', function() {
	add_rewrite_rule(
		'^quiz/([^/]+)/([^/]+)/?$',
		'index.php?post_type=quiz&name=$matches[2]&ika_hub=$matches[1]',
		'top'
	);
}, 20 );

/**
 * Guard: if a hub segment is present, ensure the quiz belongs to that hub.
 * Quizzes store their hub slug in post meta: _ika_quiz_hub_slug
 */
add_action( 'pre_get_posts', function( $q ) {
	if ( is_admin() || ! $q->is_main_query() ) return;

	$hub = $q->get( 'ika_hub' );
	if ( ! $hub ) return;

	$post_type = $q->get( 'post_type' );
	if ( $post_type !== 'quiz' ) return;

	// Restrict by meta so /quiz/{hub}/{quiz}/ can't resolve to the wrong hub.
	$q->set( 'meta_query', [
		[
			'key'   => '_ika_quiz_hub_slug',
			'value' => sanitize_title( $hub ),
			'compare' => '=',
		],
	] );
}, 10 );

/**
 * Permalink output: if a quiz has a hub slug meta, output /quiz/{hub}/{quiz}/.
 * Otherwise fall back to the default /quiz/{quiz}/.
 */
add_filter( 'post_type_link', function( $permalink, $post ) {
	if ( ! ( $post instanceof WP_Post ) || $post->post_type !== 'quiz' ) {
		return $permalink;
	}

	$hub = get_post_meta( $post->ID, '_ika_quiz_hub_slug', true );
	$hub = $hub ? sanitize_title( (string) $hub ) : '';

	if ( $hub ) {
		return home_url( '/quiz/' . $hub . '/' . $post->post_name . '/' );
	}

	return home_url( '/quiz/' . $post->post_name . '/' );
}, 99, 2 );
