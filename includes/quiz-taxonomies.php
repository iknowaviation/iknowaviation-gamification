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
	$post_types = [ 'quiz', 'briefingroom', 'academy' ];

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
			'name'          => 'Quiz Levels',
			'singular_name' => 'Quiz Level',
			'menu_name'     => 'Levels',
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


/**
 * Admin guardrails for recommendable content.
 * - Shows a notice on edit screens for Briefings / Academy when Group or Level is missing.
 * - Adds a "Recs Tags" column in list tables so you can spot missing tags without opening each post.
 *
 * Required for recommendations: ika_quiz_group + ika_quiz_difficulty (Levels).
 */
if ( is_admin() ) {

	/**
	 * Returns an array of missing required recommendation tags for a post.
	 *
	 * @param int $post_id
	 * @return string[] Missing label strings (e.g. ['Group','Level'])
	 */
	function ika_recs_missing_required_tags( int $post_id ) : array {
		$missing = [];

		if ( ! has_term( '', 'ika_quiz_group', $post_id ) ) {
			$missing[] = 'Group';
		}
		if ( ! has_term( '', 'ika_quiz_difficulty', $post_id ) ) {
			$missing[] = 'Level';
		}

		return $missing;
	}


/**
 * Admin list-table column: "Recs Tags" (Quiz + Briefing Room + Academy).
 * Shows whether required recommendation taxonomies are assigned.
 */
function ika_recs_add_list_table_column( string $post_type ) : void {
	$col_key = 'ika_recs_tags';

	add_filter( "manage_{$post_type}_posts_columns", function( array $cols ) use ( $col_key ) : array {
		// Insert after Title if possible.
		$new = [];
		foreach ( $cols as $k => $label ) {
			$new[ $k ] = $label;
			if ( $k === 'title' ) {
				$new[ $col_key ] = 'Recs Tags';
			}
		}
		if ( ! isset( $new[ $col_key ] ) ) {
			$new[ $col_key ] = 'Recs Tags';
		}
		return $new;
	} );

	add_action( "manage_{$post_type}_posts_custom_column", function( string $column, int $post_id ) use ( $col_key ) : void {
		if ( $column !== $col_key ) return;

		$missing = ika_recs_missing_required_tags( $post_id );
		if ( empty( $missing ) ) {
			echo '<span class="ika-recs-pill ika-recs-pill--ok">OK</span>';
			return;
		}

		$txt = 'Missing: ' . esc_html( implode( ', ', $missing ) );
		echo '<span class="ika-recs-pill ika-recs-pill--warn">' . $txt . '</span>';
	}, 10, 2 );
}

// Register list-table column for recommendable CPTs.
add_action( 'admin_init', function() {
	ika_recs_add_list_table_column( 'quiz' );
	ika_recs_add_list_table_column( 'briefingroom' );
	ika_recs_add_list_table_column( 'academy' );
} );

/**
 * Admin styling for the Recs Tags pills.
 */
add_action( 'admin_head', function() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) return;
	if ( ! in_array( $screen->post_type, [ 'quiz', 'briefingroom', 'academy' ], true ) ) return;

	echo '<style>
		.ika-recs-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;line-height:18px;font-weight:600}
		.ika-recs-pill--ok{background:#e7f7ee;color:#116b2f;border:1px solid #bfe8cf}
		.ika-recs-pill--warn{background:#fff4e5;color:#8a4b00;border:1px solid #ffd9a8}
	</style>';
} );

	/**
	 * Edit-screen notice (Briefing Room + Academy).
	 */
	add_action( 'admin_notices', function() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) return;

		// Only show on edit screens for these CPTs.
		$post_type = $screen->post_type ?? '';
		if ( ! in_array( $post_type, [ 'briefingroom', 'academy' ], true ) ) return;

		// Only on post edit (not term edit).
		if ( $screen->base !== 'post' ) return;

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) return;

		$missing = ika_recs_missing_required_tags( $post_id );
		if ( empty( $missing ) ) return;

		$missing_text = implode( ' + ', $missing );

		$groups_url = admin_url( 'edit-tags.php?taxonomy=ika_quiz_group&post_type=' . $post_type );
		$levels_url = admin_url( 'edit-tags.php?taxonomy=ika_quiz_difficulty&post_type=' . $post_type );

		echo '<div class="notice notice-warning is-dismissible">';
		echo '<p><strong>Recommendation tags missing:</strong> ' . esc_html( $missing_text ) . '.</p>';
		echo '<p>To appear in Recommended Next, assign <strong>Group</strong> and <strong>Level</strong> using the canonical Quiz taxonomies.</p>';
		echo '<p><a href="' . esc_url( $groups_url ) . '">Manage Groups</a> &nbsp;|&nbsp; <a href="' . esc_url( $levels_url ) . '">Manage Levels</a></p>';
		echo '</div>';
	} );

	/**
	 * Add list-table column for Briefings + Academy.
	 */
	function ika_recs_add_list_column( array $columns ) : array {
		// Insert near the end but before date if present.
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( $key === 'date' ) {
				$new['ika_recs_tags'] = 'Recs Tags';
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['ika_recs_tags'] ) ) {
			$new['ika_recs_tags'] = 'Recs Tags';
		}
		return $new;
	}

	add_filter( 'manage_briefingroom_posts_columns', 'ika_recs_add_list_column', 20 );
	add_filter( 'manage_academy_posts_columns', 'ika_recs_add_list_column', 20 );

	/**
	 * Render list-table column values.
	 */
	function ika_recs_render_list_column( string $column, int $post_id ) : void {
		if ( $column !== 'ika_recs_tags' ) return;

		$missing = ika_recs_missing_required_tags( $post_id );

		if ( empty( $missing ) ) {
			echo '<span class="ika-recs-ok">OK</span>';
			return;
		}

		echo '<span class="ika-recs-missing">Missing: ' . esc_html( implode( ', ', $missing ) ) . '</span>';
	}

	add_action( 'manage_briefingroom_posts_custom_column', 'ika_recs_render_list_column', 20, 2 );
	add_action( 'manage_academy_posts_custom_column', 'ika_recs_render_list_column', 20, 2 );

	/**
	 * Add a subtle row highlight when required tags are missing.
	 */
	add_filter( 'post_class', function( array $classes, array $class, int $post_id ) : array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) return $classes;

		if ( ! in_array( $screen->post_type ?? '', [ 'briefingroom', 'academy' ], true ) ) return $classes;

		$missing = ika_recs_missing_required_tags( $post_id );
		if ( ! empty( $missing ) ) {
			$classes[] = 'ika-recs-row-missing';
		}
		return $classes;
	}, 20, 3 );

	/**
	 * Admin-only CSS for badges + row highlight.
	 */
	add_action( 'admin_head', function() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) return;

		if ( ! in_array( $screen->post_type ?? '', [ 'briefingroom', 'academy' ], true ) ) return;

		echo '<style>
			/* IKA Recs guardrails */
			.column-ika_recs_tags { width: 140px; }
			.ika-recs-ok { display:inline-block; padding:2px 8px; border-radius:999px; font-weight:600; font-size:12px; background:#e7f7ed; color:#116b2e; }
			.ika-recs-missing { display:inline-block; padding:2px 8px; border-radius:999px; font-weight:600; font-size:12px; background:#fff4e5; color:#8a4b00; }
			tr.ika-recs-row-missing > td, tr.ika-recs-row-missing > th { background: #fffdf7; }
		</style>';
	} );
}
