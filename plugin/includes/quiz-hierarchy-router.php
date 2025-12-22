<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Quiz Hierarchy Router
 *
 * Ensures hierarchical Quiz CPT URLs resolve, e.g.:
 *   /quiz/intro-to-aviation/importer-wp-parenttax-test-fixed-v2/
 *
 * CPT UI + hierarchical CPT rewrite rules can be inconsistent across WP installs.
 * This router resolves the full path after /quiz/ to a 'quiz' CPT post using
 * get_page_by_path() and forces WP to load it.
 *
 * Safe behavior:
 * - Only runs on front-end requests beginning with /quiz/
 * - Only overrides routing when a matching quiz post is found
 * - Leaves all other URLs untouched
 */
add_action( 'parse_request', function( $wp ) {

	if ( is_admin() ) {
		return;
	}

	$req = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
	if ( $req === '' ) {
		return;
	}

	// Only handle quiz paths
	if ( strpos( $req, 'quiz/' ) !== 0 ) {
		return;
	}

	$path = trim( substr( $req, 5 ), '/' ); // remove leading "quiz/"
	if ( $path === '' ) {
		return; // /quiz/ archive/hub resolution handled normally
	}

	// Only intended for hierarchical paths (at least one slash remaining)
	if ( strpos( $path, '/' ) === false ) {
		return; // /quiz/{slug}/ handled by normal CPT rules
	}

	$post = get_page_by_path( $path, OBJECT, 'quiz' );
	if ( ! $post || empty( $post->ID ) ) {
		return;
	}

	// Force load this post.
	$wp->query_vars['post_type'] = 'quiz';
	$wp->query_vars['p']         = (int) $post->ID;
	$wp->query_vars['name']      = $post->post_name;

	// Avoid page resolution conflicts.
	unset( $wp->query_vars['pagename'] );

}, 0 );

/**
 * Prevent canonical redirects from collapsing quiz child URLs.
 */
add_filter( 'redirect_canonical', function( $redirect_url, $requested_url ) {

	$path = (string) wp_parse_url( $requested_url, PHP_URL_PATH );
	if ( $path && strpos( $path, '/quiz/' ) === 0 ) {
		return false;
	}

	return $redirect_url;
}, 10, 2 );
