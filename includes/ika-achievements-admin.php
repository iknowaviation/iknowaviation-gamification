<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IKA Achievements – Admin UI (v1)
 *
 * Goal: simple, future-safe management surface so we can iterate quickly.
 *
 * v1 includes:
 * - Levels mapping: map IKA rank ladder labels -> WatuPRO Play level asset (id).
 * - Badge rules (XP milestones only): toggle + XP threshold per WatuPRO Play badge asset (id).
 */

add_action( 'admin_menu', function() {
	add_submenu_page(
		'ika-gamification',
		'Achievements – Levels',
		'Levels',
		'manage_options',
		'ika-achievements-levels',
		'ika_achievements_render_levels_page'
	);

	add_submenu_page(
		'ika-gamification',
		'Achievement Badges',
		'Badges',
		'manage_options',
		'ika-achievements-badges',
		'ika_achievements_render_badges_page'
	);
}, 25 );

function ika_achievements_render_levels_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$updated = false;
	if ( isset( $_POST['ika_ach_levels_save'] ) ) {
		check_admin_referer( 'ika_ach_levels_save', 'ika_ach_levels_nonce' );
		$map = array();
		if ( ! empty( $_POST['level_map'] ) && is_array( $_POST['level_map'] ) ) {
			foreach ( $_POST['level_map'] as $rank_slug => $level_id ) {
				$rank_slug = sanitize_title( (string) $rank_slug );
				$level_id  = (int) $level_id;
				$map[ $rank_slug ] = max( 0, $level_id );
			}
		}
		update_option( 'ika_ach_level_map_v1', $map );
		$updated = true;
	}

	$map = get_option( 'ika_ach_level_map_v1', array() );
	if ( ! is_array( $map ) ) $map = array();

	$ladder = function_exists( 'ika_get_rank_ladder' ) ? ika_get_rank_ladder() : array();
	$assets = function_exists( 'ika_watuproplay_get_raw_levels_rows' ) ? ika_watuproplay_get_raw_levels_rows() : array();
	if ( ! is_array( $assets ) ) $assets = array();

	?>
	<div class="wrap">
		<h1>IKA Achievements – Levels</h1>
		<p style="max-width:900px;">
			Map each IKA rank to a <strong>WatuPRO Play “level” asset</strong> (icon/graphic + name).
			IKA awards levels from XP; Watu Play is only used as the asset library.
		</p>

		<?php if ( $updated ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'ika_ach_levels_save', 'ika_ach_levels_nonce' ); ?>
			<table class="widefat fixed striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th style="width:260px;">IKA Rank</th>
						<th style="width:120px;">Min XP</th>
						<th>Watu Play Level Asset</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $ladder as $step ) :
					$label = $step['label'] ?? '';
					$min_xp = (int) ( $step['min_xp'] ?? 0 );
					$slug = sanitize_title( (string) $label );
					$selected = isset( $map[ $slug ] ) ? (int) $map[ $slug ] : 0;
					?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong><br/><code><?php echo esc_html( $slug ); ?></code></td>
						<td><?php echo esc_html( (string) $min_xp ); ?></td>
						<td>
							<select name="level_map[<?php echo esc_attr( $slug ); ?>]" style="min-width:420px;">
								<option value="0">— Auto-match by name (fallback) —</option>
								<?php foreach ( $assets as $lvl ) :
									$atype_raw = $lvl['atype'] ?? '';
									$atype_norm = function_exists( 'ika_watuproplay_normalize_atype' ) ? ika_watuproplay_normalize_atype( $atype_raw ) : strtolower( trim( (string) $atype_raw ) );
									$id = (int) ( $lvl['id'] ?? 0 );
									$name = (string) ( $lvl['name'] ?? '' );
									$graphic = (string) ( $lvl['badge_graphic'] ?? '' );
									$label_opt = $name ? $name : ('Asset #' . $id);
									$label_opt .= ' [' . ( $atype_norm ?: 'unknown' ) . ']';
									if ( $graphic ) $label_opt .= ' (has icon)';
									?>
									<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $selected, $id ); ?>>
										<?php echo esc_html( $label_opt ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:16px;">
				<button class="button button-primary" type="submit" name="ika_ach_levels_save" value="1">Save Level Mapping</button>
			</p>
		</form>
	</div>
	<?php
}

function ika_achievements_render_badges_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$updated = false;
	if ( isset( $_POST['ika_ach_badges_save'] ) ) {
		check_admin_referer( 'ika_ach_badges_save', 'ika_ach_badges_nonce' );
		$rules = array();
		if ( ! empty( $_POST['badge_rule'] ) && is_array( $_POST['badge_rule'] ) ) {
			foreach ( $_POST['badge_rule'] as $badge_id => $row ) {
				$badge_id = (int) $badge_id;
				if ( $badge_id <= 0 || ! is_array( $row ) ) continue;

				$is_active = ! empty( $row['is_active'] ) ? 1 : 0;
				$xp = isset( $row['xp'] ) ? (int) $row['xp'] : 0;
				$rules[ (string) $badge_id ] = array(
					'is_active' => $is_active,
					'type'      => 'xp_total_at_least',
					'xp'        => max( 0, $xp ),
				);
			}
		}
		update_option( 'ika_ach_badge_rules_v1', $rules );
		$updated = true;
	}

	$rules = get_option( 'ika_ach_badge_rules_v1', array() );
	if ( ! is_array( $rules ) ) $rules = array();

	$assets = function_exists( 'ika_watuproplay_get_raw_levels_rows' ) ? ika_watuproplay_get_raw_levels_rows() : array();
	if ( ! is_array( $assets ) ) $assets = array();

	?>
	<div class="wrap">
		<h1>IKA Achievements – Badges (XP milestones v1)</h1>
		<p style="max-width:1000px;">
			This is the first rules UI (v1). Each active badge is awarded when a user reaches the configured <strong>Total XP</strong> threshold.
			We’ll expand rule types (streaks, group mastery, etc.) after the core pipeline is stable.
		</p>

		<?php if ( $updated ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'ika_ach_badges_save', 'ika_ach_badges_nonce' ); ?>
			<table class="widefat fixed striped" style="max-width:1200px;">
				<thead>
					<tr>
						<th style="width:70px;">Icon</th>
						<th>Badge</th>
						<th style="width:110px;">Active</th>
						<th style="width:160px;">Award at XP</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $assets as $b ) :
					$atype_raw = $b['atype'] ?? '';
					$atype_norm = function_exists( 'ika_watuproplay_normalize_atype' ) ? ika_watuproplay_normalize_atype( $atype_raw ) : strtolower( trim( (string) $atype_raw ) );
					$id = (int) ( $b['id'] ?? 0 );
					$name = (string) ( $b['name'] ?? '' );
					$graphic = (string) ( $b['badge_graphic'] ?? '' );
					$r = $rules[ (string) $id ] ?? array();
					$is_active = ! empty( $r['is_active'] );
					$xp = (int) ( $r['xp'] ?? 0 );
					?>
					<tr>
						<td>
							<?php if ( $graphic ) : ?>
								<img src="<?php echo esc_url( $graphic ); ?>" alt="" style="width:40px;height:40px;object-fit:contain;border-radius:8px;background:#fff;" />
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( $name ?: ('Asset #' . $id) ); ?></strong> <code><?php echo esc_html( '[' . ( $atype_norm ?: 'unknown' ) . ']' ); ?></code><br/>
							<code>ID: <?php echo esc_html( (string) $id ); ?></code>
						</td>
						<td>
							<label>
								<input type="checkbox" name="badge_rule[<?php echo esc_attr( (string) $id ); ?>][is_active]" value="1" <?php checked( $is_active ); ?> />
								Enabled
							</label>
						</td>
						<td>
							<input type="number" min="0" step="10" name="badge_rule[<?php echo esc_attr( (string) $id ); ?>][xp]" value="<?php echo esc_attr( (string) $xp ); ?>" style="width:120px;" />
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:16px;">
				<button class="button button-primary" type="submit" name="ika_ach_badges_save" value="1">Save Badge Rules</button>
			</p>
		</form>
	</div>
	<?php
}
