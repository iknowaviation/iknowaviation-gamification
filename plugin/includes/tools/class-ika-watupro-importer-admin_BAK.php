<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class IKA_WatuPRO_Importer_Admin {

	public static function init() {
	add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
			add_action( 'admin_post_ika_watupro_import', [ __CLASS__, 'handle_import' ] );
			add_action( 'admin_post_ika_watupro_export', [ __CLASS__, 'handle_export' ] );
			add_action( 'admin_post_ika_watupro_template_capture', [ __CLASS__, 'handle_template_capture' ] );
	
			// NEW: Builder JSON generator
			add_action( 'admin_post_ika_watupro_build_json', [ __CLASS__, 'handle_builder_generate_json' ] );
		}

	/**
	 * Capture/refresh the default template from a given quiz ID.
	 */
	public static function handle_template_capture() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
		check_admin_referer( 'ika_watupro_template_capture', 'ika_tpl_nonce' );

		$quiz_id = isset( $_POST['template_source_quiz_id'] ) ? absint( $_POST['template_source_quiz_id'] ) : 0;
		if ( $quiz_id <= 0 ) {
			set_transient( 'ika_watupro_import_last_result', [
				'ok' => false,
				'message' => 'Template capture failed: invalid quiz ID.',
				'log' => [],
			], 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer' ) );
			exit;
		}

		// Remember last used quiz ID for template capture UI.
		update_option( 'ika_watupro_tpl_last_source_quiz_id', $quiz_id, false );
		$ok = IKA_WatuPRO_Importer_Templates::templates_refresh_default_from_quiz( $quiz_id );
		set_transient( 'ika_watupro_import_last_result', [
			'ok' => (bool) $ok,
			'message' => $ok
				? sprintf( 'Default template refreshed from quiz ID %d.', $quiz_id )
				: sprintf( 'Template capture failed for quiz ID %d. Make sure it exists.', $quiz_id ),
			'log' => [],
		], 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer' ) );
		exit;
	}

	public static function add_menu() {
			add_submenu_page(
				'ika-gamification',
				'WatuPRO Importer',
				'WatuPRO Importer',
				'manage_options',
				'ika-watupro-importer',
				[ __CLASS__, 'render_page' ]
			);
	
			// NEW: Quiz Builder submenu
			add_submenu_page(
				'ika-gamification',
				'Quiz Builder → JSON',
				'Quiz Builder',
				'manage_options',
				'ika-quiz-builder',
				[ __CLASS__, 'render_builder_page' ]
			);
		}

	public static function render_page() {
	
			if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
	
			$last = get_transient( 'ika_watupro_import_last_result' );
			delete_transient( 'ika_watupro_import_last_result' );
	
			$export_last = get_transient( 'ika_watupro_export_last_result' );
			delete_transient( 'ika_watupro_export_last_result' );
	$quizzes = IKA_WatuPRO_Importer_Engine::list_quizzes();
			?>
			<div class="wrap">
				<h1>WatuPRO Importer / Exporter (JSON)</h1>
	
				<?php if ( $last ) : ?>
					<div class="notice notice-<?php echo esc_attr( $last['ok'] ? 'success' : 'error' ); ?>">
						<p><strong><?php echo esc_html( $last['ok'] ? 'Success' : 'Error' ); ?>:</strong> <?php echo esc_html( $last['message'] ); ?></p>
						<?php if ( ! empty( $last['log'] ) ) : ?>
							<details style="margin-top:8px;">
								<summary>View log</summary>
								<pre style="white-space:pre-wrap;"><?php echo esc_html( implode( "\n", $last['log'] ) ); ?></pre>
							</details>
						<?php endif; ?>
					</div>
				<?php endif; ?>
	
				<?php if ( $export_last ) : ?>
					<div class="notice notice-<?php echo esc_attr( $export_last['ok'] ? 'success' : 'error' ); ?>">
						<p><strong><?php echo esc_html( $export_last['ok'] ? 'Success' : 'Error' ); ?>:</strong> <?php echo esc_html( $export_last['message'] ); ?></p>
					</div>
				<?php endif; ?>
	<hr />

				<h2>Template Capture</h2>
				<p>Refresh the <strong>Default</strong> template by copying quiz-level settings from an existing WatuPRO quiz (e.g., quiz <strong>#6</strong>).</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ika_watupro_template_capture" />
					<?php wp_nonce_field( 'ika_watupro_template_capture', 'ika_tpl_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="template_source_quiz_id">Source quiz ID</label></th>
							<td>
								<input type="number" min="1" step="1" name="template_source_quiz_id" id="template_source_quiz_id" value="<?php echo esc_attr( (int) get_option( 'ika_watupro_tpl_last_source_quiz_id', 6 ) ); ?>" style="width:120px;" />
								<p class="description">Defaults to 6, but you can enter any existing WatuPRO quiz ID.</p>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Capture / Refresh Default Template', 'secondary', 'submit', false ); ?>
				</form>

				<hr />
	
				<h2>Import JSON</h2>
				<p><strong>Tip:</strong> Run Dry Run first. Then Import.</p>
	
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="ika_watupro_import" />
					<?php wp_nonce_field( 'ika_watupro_import', 'ika_nonce' ); ?>
	
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ika_json_file">JSON file</label></th>
							<td><input type="file" name="ika_json_file" id="ika_json_file" accept="application/json" required /></td>
						</tr>
	
						<tr>
							<th scope="row">Mode</th>
							<td>
								<label style="margin-right:12px;">
									<input type="radio" name="mode" value="dry" checked />
									Dry Run (no DB writes)
								</label>
								<label>
									<input type="radio" name="mode" value="import" />
									Import (write to DB)
								</label>
							</td>
						</tr>
	
						<tr>
							<th scope="row">Selective Replace Mode</th>
							<td>
								<select name="replace_mode">
									<option value="auto" selected>Auto (safe default)</option>
									<option value="none">None (no Q/A changes)</option>
									<option value="all">All (settings + replace Q/A)</option>
									<option value="settings">Settings only (no Q/A changes)</option>
									<option value="questions">Questions only (replace Q/A only)</option>
									<option value="tags">Tags only (CPT taxonomies only)</option>
									<option value="cpt">CPT only (enforce post + shortcode)</option>
								</select>
								<p class="description">
									<strong>Auto</strong> uses the legacy checkbox below: if “Replace existing” is checked → All; otherwise → None.
								</p>
							</td>
						</tr>
	
						<?php
						// Templates (used to supply default quiz settings like master.description / hero header).
						IKA_WatuPRO_Importer_Templates::templates_ensure_default_from_quiz( 6 );
						$ika_templates = IKA_WatuPRO_Importer_Templates::templates_get_all();
						$ika_default_template = IKA_WatuPRO_Importer_Templates::templates_get_default_id();
						?>
						<tr>
							<th scope="row">Template</th>
							<td>
								<select name="template_id">
									<option value="">(Use default)</option>
									<?php foreach ( $ika_templates as $tid => $tpl ) :
										$label = is_array($tpl) ? (string)($tpl['name'] ?? $tid) : (string)$tid;
										$sel = selected( $tid, $ika_default_template, false );
									?>
										<option value="<?php echo esc_attr($tid); ?>" <?php echo $sel; ?>>
											<?php echo esc_html($label . " ({$tid})"); ?>
										</option>
									<?php endforeach; ?>
								</select>
								&nbsp;&nbsp;
								<label>
									Variant
									<select name="template_variant">
										<option value="A" selected>A</option>
										<option value="B">B</option>
										<option value="C">C</option>
										<option value="D">D</option>
									</select>
								</label>
								&nbsp;&nbsp;
								<label>
									<input type="checkbox" name="force_template" value="1" />
									Force template values (overwrite JSON)
								</label>
								<p class="description">Templates supply quiz-level settings like Description (hero/header) and other WatuPRO master fields.</p>
							</td>
						</tr>
	
						<tr>
							<th scope="row"><label for="override_description_html">Override Description (optional)</label></th>
							<td>
								<textarea name="override_description_html" id="override_description_html" rows="4" style="width:100%;max-width:900px;"></textarea>
								<p class="description">If provided, this will be saved into WatuPRO quiz master.description (used by your hero/header).</p>
							</td>
						</tr>
	
						<tr>
							<th scope="row"><label for="override_final_screen_html">Override Final Screen (optional)</label></th>
							<td>
								<textarea name="override_final_screen_html" id="override_final_screen_html" rows="4" style="width:100%;max-width:900px;"></textarea>
							</td>
						</tr>
	
	
						<tr>
							<th scope="row">Legacy: Replace existing?</th>
							<td>
								<label>
									<input type="checkbox" name="replace_existing" id="ika_replace_existing" value="1" />
									Legacy toggle (Auto mode only): If quiz exists (by exact name), delete its existing questions/answers and re-import
								</label>
								<p class="description" id="ika_replace_existing_note" style="margin-top:6px;">
									Disabled unless <strong>Selective Replace Mode</strong> is set to <strong>Auto</strong>.
								</p>
							</td>
						</tr>
	
						<tr>
							<th scope="row">CPT integration</th>
							<td>
								<label>
									<input type="checkbox" name="cpt_enable" id="ika_cpt_enable" value="1" checked />
									Create/Update a Quiz CPT post and link it (post content forced to <code>[watupro EXAM_ID]</code>)
								</label>
								<p class="description">
									Your wrapper filter will wrap output in <code>&lt;div class="ika-quiz-page hero-jet"&gt;...&lt;/div&gt;</code> on single quiz CPT pages.
								</p>
							</td>
						</tr>
	
					
						<tr>
							<th scope="row">Template (settings preset)</th>
							<td>
								<?php $tpls = IKA_WatuPRO_Importer_Templates::templates_get_all(); $def_tpl = IKA_WatuPRO_Importer_Templates::templates_get_default_id(); ?>
								<select name="template_id">
									<option value="">(Default)</option>
									<?php foreach ( $tpls as $tid => $tpl ) : if ( ! is_array($tpl) ) continue; $name = isset($tpl['name']) ? (string)$tpl['name'] : (string)$tid; ?>
										<option value="<?php echo esc_attr( $tid ); ?>" <?php selected( $tid, $def_tpl ); ?>><?php echo esc_html( $name . ' [' . $tid . ']' ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Applies template settings + description/final screen defaults unless overridden below.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Template variant</th>
							<td>
								<select name="template_variant">
									<?php foreach ( ['A','B','C','D'] as $v ) : ?>
										<option value="<?php echo esc_attr($v); ?>" <?php selected( $v, 'A' ); ?>><?php echo esc_html($v); ?></option>
									<?php endforeach; ?>
								</select>
								<label style="margin-left:12px;">
									<input type="checkbox" name="force_template" value="1" />
									Force template values (overwrite JSON)
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Override description (optional)</th>
							<td>
								<textarea name="override_description_html" rows="4" style="width:100%;max-width:720px;" placeholder="If set, this overrides the WatuPRO quiz description / hero content..."></textarea>
								<p class="description">Writes to <code>watupro_master.description</code> (this controls your quiz header/hero content).</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Override final screen (optional)</th>
							<td>
								<textarea name="override_final_screen_html" rows="3" style="width:100%;max-width:720px;" placeholder="Optional final screen HTML..."></textarea>
							</td>
						</tr>
</table>
	
					<?php submit_button( 'Run Import' ); ?>
				</form>
	
				<script>
				(function(){
					function syncReplaceModeUI() {
						var mode = document.querySelector('select[name="replace_mode"]');
						var legacy = document.getElementById('ika_replace_existing');
						var note = document.getElementById('ika_replace_existing_note');
						var cpt = document.getElementById('ika_cpt_enable');
	
						if (!mode) return;
	
						var value = (mode.value || '').toLowerCase();
						var isAuto = (value === 'auto');
						var isAll  = (value === 'all');
	
						// Legacy checkbox logic (Auto only)
						if (legacy) {
							legacy.disabled = !isAuto;
							if (!isAuto) legacy.checked = false;
						}
						if (note) {
							note.style.opacity = isAuto ? '1' : '0.65';
						}
	
						// CPT auto-enable when ALL is selected
						if (isAll && cpt) {
							cpt.checked = true;
							cpt.setAttribute('checked', 'checked');
							try { cpt.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
						}
					}
	
					function bind() {
						var mode = document.querySelector('select[name="replace_mode"]');
						if (mode) {
							mode.addEventListener('change', syncReplaceModeUI);
							mode.addEventListener('input', syncReplaceModeUI);
						}
						syncReplaceModeUI();
					}
	
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', bind);
					} else {
						bind();
					}
				})();
				</script>
	
				<hr />
	
				<h2>Export Existing Quiz to JSON</h2>
				<p>Exports the selected quiz. If it reuses questions (reuse_questions_from), the export will pull questions from the source exam_id and note it in the JSON.</p>
	
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ika_watupro_export" />
					<?php wp_nonce_field( 'ika_watupro_export', 'ika_export_nonce' ); ?>
	
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="quiz_id">Select Quiz</label></th>
							<td>
								<select name="quiz_id" id="quiz_id" required>
									<option value="">— Select —</option>
									<?php foreach ( $quizzes as $q ) : ?>
										<option value="<?php echo esc_attr( $q['ID'] ); ?>">
											<?php echo esc_html( $q['name'] . ' (ID ' . $q['ID'] . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
	
					<?php submit_button( 'Download JSON Export', 'secondary' ); ?>
				
	<hr />
	<h2 id="ika-templates">Quiz Templates</h2>
	<p>Templates store quiz-level WatuPRO master settings (including <code>description</code> used by your hero/header). You can create templates by capturing settings from an existing quiz (recommended: Quiz ID 6).</p>
	
	<?php
		IKA_WatuPRO_Importer_Templates::templates_ensure_default_from_quiz( 6 );
		$tpls = IKA_WatuPRO_Importer_Templates::templates_get_all();
		$default_id = IKA_WatuPRO_Importer_Templates::templates_get_default_id();
	
		$edit_id = isset($_GET['edit_template']) ? sanitize_key( (string) $_GET['edit_template'] ) : '';
		$edit_tpl = ( $edit_id && isset($tpls[$edit_id]) && is_array($tpls[$edit_id]) ) ? $tpls[$edit_id] : null;
	
		if ( ! $edit_tpl ) {
			// Start a new template seeded from the default.
			$edit_id = '';
			$edit_tpl = [
				'id' => '',
				'name' => '',
				'source_quiz_id' => 6,
				'settings' => ( isset($tpls['default']['settings']) && is_array($tpls['default']['settings']) ) ? $tpls['default']['settings'] : [],
				'description_variants' => ( isset($tpls['default']['description_variants']) && is_array($tpls['default']['description_variants']) ) ? $tpls['default']['description_variants'] : [ 'A' => '' ],
				'final_screen_html' => (string) ( $tpls['default']['final_screen_html'] ?? '' ),
			];
		}
	?>
	
	<table class="widefat striped" style="max-width:1100px;">
		<thead>
		<tr>
			<th>Template</th>
			<th>ID</th>
			<th>Source Quiz</th>
			<th>Updated</th>
			<th>Default</th>
			<th>Actions</th>
		</tr>
		</thead>
		<tbody>
		<?php if ( empty($tpls) ) : ?>
			<tr><td colspan="6">No templates yet.</td></tr>
		<?php else : foreach ( $tpls as $tid => $tpl ) :
			if ( ! is_array($tpl) ) continue;
		?>
			<tr>
				<td><?php echo esc_html( (string)($tpl['name'] ?? $tid) ); ?></td>
				<td><code><?php echo esc_html($tid); ?></code></td>
				<td><?php echo esc_html( (string)($tpl['source_quiz_id'] ?? '') ); ?></td>
				<td><?php echo esc_html( isset($tpl['updated_at']) ? date('Y-m-d H:i', (int)$tpl['updated_at']) : '' ); ?></td>
				<td><?php echo $tid === $default_id ? '<strong>Yes</strong>' : ''; ?></td>
				<td>
					<a class="button button-small" href="<?php echo esc_url( admin_url('admin.php?page=ika-watupro-importer&edit_template=' . rawurlencode($tid) . '#ika-templates') ); ?>">Edit</a>
					<?php if ( $tid !== 'default' ) : ?>
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=ika_watupro_template_delete&template_id=' . rawurlencode($tid)), 'ika_watupro_template_delete', 'ika_nonce' ) ); ?>" onclick="return confirm('Delete this template?');">Delete</a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
	
	<h3 style="margin-top:18px;"><?php echo $edit_id ? 'Edit Template' : 'Create Template'; ?></h3>
	
	<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
		<input type="hidden" name="action" value="ika_watupro_template_save" />
		<?php wp_nonce_field( 'ika_watupro_template_save', 'ika_nonce' ); ?>
		<table class="form-table" role="presentation" style="max-width:1100px;">
			<tr>
				<th scope="row"><label for="template_id">Template ID</label></th>
				<td>
					<input type="text" id="template_id" name="template_id" value="<?php echo esc_attr( (string)($edit_id ?: '') ); ?>" <?php echo $edit_id ? 'readonly' : ''; ?> />
					<p class="description"><?php echo $edit_id ? 'ID cannot be changed.' : 'Leave blank to auto-generate.'; ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="template_name">Name</label></th>
				<td><input type="text" id="template_name" name="template_name" value="<?php echo esc_attr( (string)($edit_tpl['name'] ?? '') ); ?>" style="width:420px;" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="source_quiz_id">Capture from Quiz ID</label></th>
				<td>
					<input type="number" id="source_quiz_id" name="source_quiz_id" value="<?php echo esc_attr( (int)($edit_tpl['source_quiz_id'] ?? 6) ); ?>" min="1" />
					<label style="margin-left:12px;">
						<input type="checkbox" name="capture_from_quiz" value="1" />
						Overwrite this template by capturing from that quiz now
					</label>
					<p class="description">Capturing copies WatuPRO master settings and saves the current description/final screen as Variant A.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="description_variants">Description Variants</label></th>
				<td>
					<textarea id="description_variants" name="description_variants" rows="8" style="width:100%;max-width:900px;"><?php
						echo esc_textarea( wp_json_encode( $edit_tpl['description_variants'] ?? [ 'A' => '' ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES ) );
					?></textarea>
					<p class="description">Use JSON object (recommended). Example: {"A":"&lt;p&gt;Hero...&lt;/p&gt;","B":"..."}.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="final_screen_html">Final Screen HTML</label></th>
				<td><textarea id="final_screen_html" name="final_screen_html" rows="5" style="width:100%;max-width:900px;"><?php echo esc_textarea( (string)($edit_tpl['final_screen_html'] ?? '') ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="template_settings_json">Settings JSON</label></th>
				<td>
					<textarea id="template_settings_json" name="template_settings_json" rows="8" style="width:100%;max-width:900px;"><?php
						echo esc_textarea( wp_json_encode( $edit_tpl['settings'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES ) );
					?></textarea>
					<p class="description">Advanced: master-field whitelist settings captured from WatuPRO. Leave as-is unless you know exactly what you’re changing.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Default</th>
				<td>
					<label><input type="checkbox" name="set_default" value="1" <?php checked( ($edit_id ?: '') === $default_id ); ?> /> Set as default template</label>
				</td>
			</tr>
		</table>
	
		<p>
			<button type="submit" class="button button-primary">Save Template</button>
			<?php if ( $edit_id ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url('admin.php?page=ika-watupro-importer#ika-templates') ); ?>">Cancel</a>
			<?php endif; ?>
		</p>
	</form>
	
	</form>
			</div>
			<?php
		}

	public static function render_builder_page() {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
	
			// Defaults-from quiz master row (your example: 6)
			$defaults_from = isset($_GET['defaults_from']) ? (int) $_GET['defaults_from'] : 6;
			if ( $defaults_from <= 0 ) $defaults_from = 6;
	
			$defaults = self::get_master_defaults_for_builder( $defaults_from );
	
			// Pre-fill UI with defaults
			$prefill = [
				'name'              => '',
				'description_html'   => (string) ($defaults['description_html'] ?? ''),
				'final_screen_html'  => (string) ($defaults['final_screen_html'] ?? ''),
				'topics'            => '',
				'difficulty'        => '',
				'audience'          => '',
				'questions_json'    => "[]",
				'settings'          => $defaults['settings'] ?? [],
				'advanced_settings' => (string) ($defaults['settings']['advanced_settings'] ?? ''),
			];
	
			?>
			<div class="wrap">
				<h1>Quiz Builder → Generate Import JSON</h1>
	
				<p>
					This tool generates an import-ready JSON payload for your WatuPRO importer.
					It pulls sensible defaults from an existing quiz master row (e.g. your quiz ID <code>6</code>).
				</p>
	
				<form method="get" action="">
					<input type="hidden" name="page" value="ika-quiz-builder" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="defaults_from">Defaults from Quiz ID</label></th>
							<td>
								<input type="number" min="1" name="defaults_from" id="defaults_from" value="<?php echo esc_attr( $defaults_from ); ?>" />
								<button class="button">Reload Defaults</button>
								<p class="description">Loads defaults from <code><?php echo esc_html( IKA_WatuPRO_Importer_Engine::t_master() ); ?></code> for the selected quiz ID.</p>
							</td>
						</tr>
					</table>
				</form>
	
				<hr />
	
				<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
					<input type="hidden" name="action" value="ika_watupro_build_json" />
					<input type="hidden" name="defaults_from" value="<?php echo esc_attr( $defaults_from ); ?>" />
					<?php wp_nonce_field( 'ika_watupro_build_json', 'ika_builder_nonce' ); ?>
	
					<h2>Basics</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="quiz_name">Quiz name</label></th>
							<td>
								<input type="text" name="quiz_name" id="quiz_name" class="regular-text" required value="<?php echo esc_attr( $prefill['name'] ); ?>" />
								<p class="description">This becomes <code>quiz.name</code> and is used to find/create the master quiz row on import.</p>
							</td>
						</tr>
	
						<tr>
							<th scope="row"><label for="description_html">Description HTML</label></th>
							<td>
								<textarea name="description_html" id="description_html" rows="10" class="large-text code"><?php echo esc_textarea( $prefill['description_html'] ); ?></textarea>
							</td>
						</tr>
	
						<tr>
							<th scope="row"><label for="final_screen_html">Final Screen HTML</label></th>
							<td>
								<textarea name="final_screen_html" id="final_screen_html" rows="14" class="large-text code"><?php echo esc_textarea( $prefill['final_screen_html'] ); ?></textarea>
							</td>
						</tr>
					</table>
	
					<h2>Recommendation Engine Tags</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="topics">Topics</label></th>
							<td>
								<input type="text" name="topics" id="topics" class="large-text" placeholder="Comma-separated (e.g., Flight Controls, Lift, Stability)" value="<?php echo esc_attr( $prefill['topics'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="difficulty">Difficulty</label></th>
							<td>
								<input type="text" name="difficulty" id="difficulty" class="regular-text" placeholder="e.g., beginner" value="<?php echo esc_attr( $prefill['difficulty'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="audience">Audience</label></th>
							<td>
								<input type="text" name="audience" id="audience" class="large-text" placeholder="Comma-separated (e.g., Enthusiast, Student Pilot)" value="<?php echo esc_attr( $prefill['audience'] ); ?>" />
							</td>
						</tr>
					</table>
	
					<h2>Settings (defaults loaded from Quiz ID <?php echo esc_html($defaults_from); ?>)</h2>
					<table class="form-table" role="presentation">
						<?php self::render_builder_settings_table( $prefill['settings'] ); ?>
						<tr>
							<th scope="row"><label for="advanced_settings">Advanced settings (serialized)</label></th>
							<td>
								<textarea name="advanced_settings" id="advanced_settings" rows="6" class="large-text code"><?php echo esc_textarea( $prefill['advanced_settings'] ); ?></textarea>
								<p class="description">
									This is the raw serialized <code>advanced_settings</code> field. Leave as-is unless you know exactly what you’re changing.
									(We keep it here so your builder JSON can reproduce your current defaults.)
								</p>
							</td>
						</tr>
					</table>
	
					<h2>Questions JSON</h2>
					<p class="description">
						Paste a JSON array of questions matching your importer schema.
						Example: <code>[{"question_html":"...","answer_type":"radio","answers":[{"answer_html":"...","correct":1}]}]</code>
					</p>
					<textarea name="questions_json" id="questions_json" rows="18" class="large-text code"><?php echo esc_textarea( $prefill['questions_json'] ); ?></textarea>
	
					<?php submit_button( 'Download Import JSON', 'primary' ); ?>
				</form>
			</div>
			<?php
		}

	private static function render_builder_settings_table( array $settings ) : void {
			$fields = IKA_WatuPRO_Importer_Engine::master_settings_whitelist();
	
			foreach ( $fields as $key => $label ) {
				$val = $settings[$key] ?? '';
				?>
				<tr>
					<th scope="row"><label for="set_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
					<td>
						<?php if ( is_int($val) || $val === '0' || $val === '1' || in_array($key, ['is_active','require_login','take_again','email_taker','email_admin','randomize_questions','pull_random','show_answers','single_page','grades_by_percent','disallow_previous_button','live_result','is_scheduled','submit_always_visible','show_pagination','enable_save_button','shareable_final_screen','redirect_final_screen','takings_by_ip','reuse_default_grades','store_progress','custom_per_page','randomize_cats','no_ajax','pay_always','published_odd','delay_results','is_likert_survey','limit_reused_questions','retake_after','is_personality_quiz'], true ) ) : ?>
							<label>
								<input type="checkbox" name="settings[<?php echo esc_attr($key); ?>]" id="set_<?php echo esc_attr($key); ?>" value="1" <?php checked( (int)$val, 1 ); ?> />
								<span class="description">1 = enabled, 0 = disabled</span>
							</label>
						<?php else : ?>
							<input type="text" name="settings[<?php echo esc_attr($key); ?>]" id="set_<?php echo esc_attr($key); ?>" class="regular-text" value="<?php echo esc_attr( (string)$val ); ?>" />
						<?php endif; ?>
					</td>
				</tr>
				<?php
			}
		}

	public static function handle_builder_generate_json() {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
	
			if ( empty($_POST['ika_builder_nonce']) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_POST['ika_builder_nonce']) ), 'ika_watupro_build_json' ) ) {
				wp_die( 'Invalid nonce.' );
			}
	
			$defaults_from = isset($_POST['defaults_from']) ? (int) $_POST['defaults_from'] : 6;
			if ( $defaults_from <= 0 ) $defaults_from = 6;
	
			$quiz_name = isset($_POST['quiz_name']) ? sanitize_text_field( wp_unslash($_POST['quiz_name']) ) : '';
			if ( $quiz_name === '' ) wp_die( 'Quiz name is required.' );
	
			$description_html  = isset($_POST['description_html']) ? wp_kses_post( wp_unslash($_POST['description_html']) ) : '';
			$final_screen_html = isset($_POST['final_screen_html']) ? wp_kses_post( wp_unslash($_POST['final_screen_html']) ) : '';
	
			// Tags
			$topics_csv = isset($_POST['topics']) ? sanitize_text_field( wp_unslash($_POST['topics']) ) : '';
			$difficulty = isset($_POST['difficulty']) ? sanitize_text_field( wp_unslash($_POST['difficulty']) ) : '';
			$aud_csv    = isset($_POST['audience']) ? sanitize_text_field( wp_unslash($_POST['audience']) ) : '';
	
			$topics = IKA_WatuPRO_Importer_Engine::csv_to_terms( $topics_csv );
			$aud    = IKA_WatuPRO_Importer_Engine::csv_to_terms( $aud_csv );
	
			// Settings (merge whitelist defaults from master row)
			$defaults = self::get_master_defaults_for_builder( $defaults_from );
			$settings = is_array($defaults['settings'] ?? null) ? $defaults['settings'] : [];
	
			$post_settings = ( isset($_POST['settings']) && is_array($_POST['settings']) ) ? wp_unslash($_POST['settings']) : [];
			$settings = self::merge_builder_settings( $settings, $post_settings );
	
			// advanced_settings (raw)
			$advanced_settings = isset($_POST['advanced_settings']) ? (string) wp_unslash($_POST['advanced_settings']) : '';
			if ( $advanced_settings !== '' ) {
				$settings['advanced_settings'] = $advanced_settings;
			}
	
			// Questions JSON
			$questions_raw = isset($_POST['questions_json']) ? (string) wp_unslash($_POST['questions_json']) : '[]';
			$questions = json_decode( $questions_raw, true );
			if ( ! is_array($questions) ) {
				wp_die( 'Questions JSON must be a valid JSON array.' );
			}
	
			// Validate questions structure with the same strict validator used by importer
			$tmp = [
				'quiz' => [ 'name' => $quiz_name ],
				'questions' => $questions,
			];
			IKA_WatuPRO_Importer_Engine::validate_payload( $tmp );
	
			$payload = [
				'quiz' => [
					'name'              => $quiz_name,
					'description_html'  => $description_html,
					'final_screen_html' => $final_screen_html,
					// leave empty; importer auto-clears when questions exist anyway
					'reuse_questions_from' => '',
					'settings' => $settings,
				],
				'questions' => $questions,
			];
	
			// Include tags only if provided
			$tag_block = [];
			if ( ! empty($topics) ) $tag_block['topics'] = $topics;
			if ( $difficulty !== '' ) $tag_block['difficulty'] = $difficulty;
			if ( ! empty($aud) ) $tag_block['audience'] = $aud;
	
			if ( ! empty($tag_block) ) {
				$payload['tags'] = $tag_block;
			}
	
			$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$filename = 'ika-quiz-' . sanitize_file_name( $quiz_name ) . '-import.json';
	
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			echo $json;
			exit;
		}

	private static function merge_builder_settings( array $base, array $incoming ) : array {
			$allowed = array_keys( IKA_WatuPRO_Importer_Engine::master_settings_whitelist() );
	
			foreach ( $allowed as $k ) {
				if ( ! array_key_exists( $k, $incoming ) ) {
					// If checkbox-type key isn't present, treat as 0 (unchecked),
					// but ONLY for known boolean-ish keys.
					if ( IKA_WatuPRO_Importer_Engine::is_boolish_master_key( $k ) ) {
						$base[$k] = 0;
					}
					continue;
				}
	
				$v = $incoming[$k];
	
				// Checkbox posts '1' as string
				if ( IKA_WatuPRO_Importer_Engine::is_boolish_master_key( $k ) ) {
					$base[$k] = (int) ( (string)$v === '1' );
				} else {
					$base[$k] = is_scalar($v) ? (string)$v : '';
				}
			}
	
			return $base;
		}

	private static function get_master_defaults_for_builder( int $quiz_id ) : array {
			global $wpdb;
			$tm = IKA_WatuPRO_Importer_Engine::t_master();
	
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$tm} WHERE ID = %d", $quiz_id ),
				ARRAY_A
			);
	
			if ( ! $row ) {
				// Fall back to conservative defaults if the ID isn't found.
				return [
					'description_html'  => '',
					'final_screen_html' => '',
					'settings' => [
						'is_active'           => 1,
						'require_login'       => 1,
						'take_again'          => 1,
						'randomize_questions' => 1,
						'single_page'         => 0,
						'login_mode'          => '',
						'mode'                => 'live',
					],
				];
			}
	
			$settings = [];
			foreach ( IKA_WatuPRO_Importer_Engine::master_settings_whitelist() as $key => $label ) {
				if ( array_key_exists( $key, $row ) ) {
					// Preserve raw strings for non-boolish fields; cast boolish to int
					if ( IKA_WatuPRO_Importer_Engine::is_boolish_master_key( $key ) ) {
						$settings[$key] = (int) $row[$key];
					} else {
						$settings[$key] = (string) $row[$key];
					}
				}
			}
	
			// Force desired default: allow retakes (base quiz may have take_again=0).
			$settings['take_again'] = 1;
	
			return [
				'description_html'  => (string) ($row['description'] ?? ''),
				'final_screen_html' => (string) ($row['final_screen'] ?? ''),
				'settings'          => $settings,
			];
		}

	public static function handle_template_save() {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
	
			check_admin_referer( 'ika_watupro_template_save', 'ika_nonce' );
	
			$id = isset($_POST['template_id']) ? sanitize_key( (string) wp_unslash($_POST['template_id']) ) : '';
			if ( $id === '' ) $id = 'tpl_' . wp_generate_password( 8, false, false );
	
			$name = isset($_POST['template_name']) ? sanitize_text_field( (string) wp_unslash($_POST['template_name']) ) : '';
			if ( $name === '' ) $name = $id;
	
			$source_quiz_id = isset($_POST['source_quiz_id']) ? (int) $_POST['source_quiz_id'] : 0;
			$action_capture = isset($_POST['capture_from_quiz']) ? (string) wp_unslash($_POST['capture_from_quiz']) : '';
	
			$tpls = IKA_WatuPRO_Importer_Templates::templates_get_all();
	
			if ( $action_capture === '1' && $source_quiz_id > 0 ) {
				$tpl = IKA_WatuPRO_Importer_Templates::template_capture_from_quiz( $id, $name, $source_quiz_id );
			} else {
				// Manual save: keep existing settings unless provided as JSON in advanced box.
				$tpl = $tpls[$id] ?? [
					'id' => $id,
					'name' => $name,
					'source_quiz_id' => $source_quiz_id ?: 0,
					'settings' => [],
					'description_variants' => [ 'A' => '' ],
					'final_screen_html' => '',
					'updated_at' => time(),
				];
				$tpl['name'] = $name;
	
				// Variants textarea is JSON object OR simple delimiter format.
				$variants_raw = isset($_POST['description_variants']) ? (string) wp_unslash($_POST['description_variants']) : '';
				$variants = IKA_WatuPRO_Importer_Engine::parse_variants_textarea( $variants_raw );
				if ( $variants ) $tpl['description_variants'] = $variants;
	
				$final_raw = isset($_POST['final_screen_html']) ? (string) wp_unslash($_POST['final_screen_html']) : '';
				if ( $final_raw !== '' ) $tpl['final_screen_html'] = wp_kses_post( $final_raw );
	
				$settings_json = isset($_POST['template_settings_json']) ? (string) wp_unslash($_POST['template_settings_json']) : '';
				if ( trim($settings_json) !== '' ) {
					$decoded = json_decode( $settings_json, true );
					if ( is_array($decoded) ) $tpl['settings'] = $decoded;
				}
	
				$tpl['updated_at'] = time();
			}
	
			$tpls[$id] = $tpl;
			IKA_WatuPRO_Importer_Templates::templates_save_all( $tpls );
	
			if ( isset($_POST['set_default']) && (string)wp_unslash($_POST['set_default']) === '1' ) {
				IKA_WatuPRO_Importer_Templates::templates_set_default_id( $id );
			}
	
			IKA_WatuPRO_Importer_Engine::set_notice( true, 'Template saved.', [ "Saved template {$id}." ] );
			wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer&tab=templates&edit_template=' . rawurlencode($id) ) );
			exit;
		}

	public static function handle_template_delete() {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
			check_admin_referer( 'ika_watupro_template_delete', 'ika_nonce' );
	
			$id = isset($_GET['template_id']) ? sanitize_key( (string) wp_unslash($_GET['template_id']) ) : '';
			if ( $id === '' ) {
				IKA_WatuPRO_Importer_Engine::set_notice( false, 'Missing template id.' );
				wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer&tab=templates' ) );
				exit;
			}
	
			$tpls = IKA_WatuPRO_Importer_Templates::templates_get_all();
			unset( $tpls[$id] );
			IKA_WatuPRO_Importer_Templates::templates_save_all( $tpls );
	
			$default = IKA_WatuPRO_Importer_Templates::templates_get_default_id();
			if ( $default === $id ) IKA_WatuPRO_Importer_Templates::templates_set_default_id( '' );
	
			IKA_WatuPRO_Importer_Engine::set_notice( true, 'Template deleted.' );
			wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer&tab=templates' ) );
			exit;
		}

	public static function handle_import() {
	
		// Always have a log array even if we fail early.
		$log = [];
	
		// --- HARD FAIL-SAFE: capture fatal errors and surface them in the importer notice ---
		register_shutdown_function( function() use ( &$log ) {
			$e = error_get_last();
			if ( ! $e ) return;
	
			$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
			if ( ! in_array( $e['type'], $fatal_types, true ) ) return;
	
			$msg = sprintf(
				'FATAL captured: %s in %s on line %d',
				$e['message'] ?? '(no message)',
				$e['file'] ?? '(unknown file)',
				(int) ( $e['line'] ?? 0 )
			);
	
			$payload = [
				'ok'      => false,
				'message' => $msg,
				'log'     => array_merge( $log ?: [], [ $msg ] ),
				'ts'      => time(),
			];
	
			// Write both transient and option fallback.
			set_transient( 'ika_watupro_import_last_result', $payload, 60 );
			update_option( 'ika_watupro_import_last_result_option', $payload );
		} );
		// --- END FAIL-SAFE ---
	
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
	
		if ( empty( $_POST['ika_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ika_nonce'] ) ), 'ika_watupro_import' ) ) {
			wp_die( 'Invalid nonce.' );
		}
	
		$mode   = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'dry';
		$is_dry = ( $mode !== 'import' );
	
		$replace_existing = ! empty( $_POST['replace_existing'] );
		$cpt_enable       = ! empty( $_POST['cpt_enable'] );
	
		$raw_replace_mode = isset( $_POST['replace_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['replace_mode'] ) ) : 'auto';
		$replace_mode     = IKA_WatuPRO_Importer_Engine::normalize_replace_mode( $raw_replace_mode, $replace_existing );
	
		$plan = IKA_WatuPRO_Importer_Engine::replace_plan( $replace_mode, $cpt_enable );
	
		$log[] = "Replace mode: {$replace_mode}";
		$log[] = "Mode: " . ( $is_dry ? 'dry' : 'import' );
		$log[] = "CPT enabled: " . ( $cpt_enable ? 'yes' : 'no' );
	
		global $wpdb;
	
		try {
			if ( empty( $_FILES['ika_json_file']['tmp_name'] ) ) throw new Exception( 'No file uploaded.' );
	
			$raw = file_get_contents( $_FILES['ika_json_file']['tmp_name'] );
			if ( ! is_string( $raw ) || $raw === '' ) throw new Exception( 'Could not read uploaded file.' );
	
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) throw new Exception( 'Invalid JSON.' );
	
			// Normalize wrappers/case
			if ( isset($data['data']) && is_array($data['data']) )           $data = $data['data'];
			if ( isset($data['payload']) && is_array($data['payload']) )     $data = $data['payload'];
			if ( ! isset($data['questions']) && isset($data['Questions']) )  $data['questions'] = $data['Questions'];
			if ( ! isset($data['quiz']) && isset($data['Quiz']) )            $data['quiz'] = $data['Quiz'];
	
			// Convert locked ChatGPT schema v1.0 → one-or-more internal payloads
			$payloads = IKA_WatuPRO_Importer_Engine::expand_chatgpt_schema_to_payloads( $data, $log );
	
			// Optional: apply a saved template to each payload (settings + description/final screen)
			$template_id = isset($_POST['template_id']) ? sanitize_key( (string) wp_unslash($_POST['template_id']) ) : '';
			if ( $template_id === '' ) {
				$template_id = IKA_WatuPRO_Importer_Templates::templates_get_default_id();
			}
	
			$template_variant = isset($_POST['template_variant']) ? strtoupper( sanitize_key( (string) wp_unslash($_POST['template_variant']) ) ) : 'A';
			if ( $template_variant === '' ) $template_variant = 'A';
	
			$force_template = isset($_POST['force_template']) && (string) wp_unslash($_POST['force_template']) === '1';
	
			$override_desc  = isset($_POST['override_description_html']) ? (string) wp_unslash($_POST['override_description_html']) : '';
			$override_final = isset($_POST['override_final_screen_html']) ? (string) wp_unslash($_POST['override_final_screen_html']) : '';
	
			$tpls = IKA_WatuPRO_Importer_Templates::templates_get_all();
			$tpl  = ( $template_id !== '' && isset($tpls[$template_id]) && is_array($tpls[$template_id]) ) ? $tpls[$template_id] : null;
	
			if ( $tpl ) {
				foreach ( $payloads as $i => $p ) {
					if ( ! is_array($p) ) continue;
					if ( isset($p['quiz']) && is_array($p['quiz']) ) {
						$p['quiz'] = IKA_WatuPRO_Importer_Templates::template_apply_to_quiz_payload( $p['quiz'], $tpl, $template_variant, $force_template );
						if ( trim($override_desc) !== '' )  $p['quiz']['description_html']  = $override_desc;
						if ( trim($override_final) !== '' ) $p['quiz']['final_screen_html'] = $override_final;
						$payloads[$i] = $p;
					}
				}
				$log[] = "Applied template '{$template_id}' (variant {$template_variant})" . ( $force_template ? ' [forced]' : '' ) . ".";
			}
	
	
			if ( ! is_array($payloads) || empty($payloads) ) throw new Exception( 'No quizzes found in JSON.' );
	
			// Validate each payload for the selected replace mode
			foreach ( $payloads as $pi => $p ) {
				if ( ! is_array($p) ) throw new Exception( 'Invalid payload at index ' . $pi );
				IKA_WatuPRO_Importer_Engine::validate_payload_by_mode( $p, $replace_mode );
			}
	
			if ( ! $is_dry ) {
				$wpdb->query( 'START TRANSACTION' );
				$log[] = 'Started DB transaction.';
			}
	
			$total_quizzes   = 0;
			$total_questions = 0;
			$total_answers   = 0;
	
			foreach ( $payloads as $p ) {
				$total_quizzes++;
				$raw_one = wp_json_encode( $p, JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $raw_one ) ) $raw_one = '';
	
				$res = IKA_WatuPRO_Importer_Engine::import_one_payload( $p, $raw_one, $plan, $is_dry, $replace_mode, $cpt_enable, $log );
				$total_questions += (int) ($res['questions'] ?? 0);
				$total_answers   += (int) ($res['answers'] ?? 0);
			}
	
			if ( ! $is_dry ) {
				$wpdb->query( 'COMMIT' );
				$log[] = 'Committed DB transaction.';
			}
	
			$msg = $is_dry
				? "Dry Run complete: {$total_quizzes} quiz(es), {$total_questions} question(s), {$total_answers} answer(s). Mode={$replace_mode}."
				: "Import complete: {$total_quizzes} quiz(es), {$total_questions} question(s), {$total_answers} answer(s). Mode={$replace_mode}.";
	
			$payload = [
				'ok'      => true,
				'message' => $msg,
				'log'     => $log,
				'ts'      => time(),
			];
	
			set_transient( 'ika_watupro_import_last_result', $payload, 60 );
			update_option( 'ika_watupro_import_last_result_option', $payload );
	
		} catch ( Throwable $e ) {
			if ( ! empty( $wpdb ) && ! $is_dry ) {
				$wpdb->query( 'ROLLBACK' );
				$log[] = 'Rolled back DB transaction.';
			}
	
			$payload = [
				'ok'      => false,
				'message' => $e->getMessage(),
				'log'     => array_merge( $log, [ 'ERROR: ' . $e->getMessage() ] ),
				'ts'      => time(),
			];
	
			set_transient( 'ika_watupro_import_last_result', $payload, 60 );
			update_option( 'ika_watupro_import_last_result_option', $payload );
		}
	
		wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer' ) );
		exit;
			// Templates Manager submenu
			add_submenu_page(
				'ika-gamification',
				'Quiz Templates',
				'Quiz Templates',
				'manage_options',
				'ika-watupro-importer-templates',
				[ __CLASS__, 'render_templates_page' ]
			);

	}

	public static function handle_export() {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
	
			if ( empty( $_POST['ika_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ika_export_nonce'] ) ), 'ika_watupro_export' ) ) {
				wp_die( 'Invalid nonce.' );
			}
	
			$quiz_id = isset($_POST['quiz_id']) ? (int) $_POST['quiz_id'] : 0;
			if ( ! $quiz_id ) {
				set_transient( 'ika_watupro_export_last_result', [ 'ok' => false, 'message' => 'No quiz selected.' ], 60 );
				wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer' ) );
				exit;
			}
	
			try {
				$payload = IKA_WatuPRO_Importer_Engine::export_quiz_payload( $quiz_id );
				$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	
				$filename = 'watupro-quiz-' . $quiz_id . '-' . sanitize_file_name( $payload['quiz']['name'] ) . '.json';
	
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
				echo $json;
				exit;
	
			} catch ( Exception $e ) {
				set_transient( 'ika_watupro_export_last_result', [ 'ok' => false, 'message' => $e->getMessage() ], 60 );
				wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer' ) );
				exit;
			}
		}
}
