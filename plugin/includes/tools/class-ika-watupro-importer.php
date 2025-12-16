<?php
/**
 * IKA WatuPRO Quiz Importer + Exporter (JSON)
 * - Import quizzes/questions/answers/explanations into WatuPRO tables
 * - Dry Run supported
 * - Selective Replace Modes (hardening)
 * - Export existing quiz to JSON (round-trip compatible)
 * - Handles "reuse questions" quizzes by exporting questions from reuse source exam_id
 * - Auto-clears reuse_questions_from whenever questions are provided on import
 * - CPT integration:
 *   - Ensures a Quiz CPT post exists and contains [watupro EXAM_ID]
 *   - Relies on your existing do_shortcode_tag wrapper filter to add:
 *     <div class="ika-quiz-page hero-jet"> ... </div>
 * - NEW: Quiz Builder UI → generates import-ready JSON using defaults from an existing master row (e.g. quiz ID 6)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class IKA_WatuPRO_Importer {

	/** =========================
	 * CONFIG
	 * ========================= */
	const CPT_POST_TYPE          = 'quiz';
	const CPT_META_EXAM_ID       = '_ika_watupro_exam_id';
	const CPT_META_IMPORT_HASH   = '_ika_watupro_import_hash';

	// Optional taxonomies for recommendation engine tagging (CPT-level)
	const TAX_TOPIC              = 'ika_topic';
	const TAX_DIFFICULTY         = 'ika_difficulty';
	const TAX_AUDIENCE           = 'ika_audience';

	// Table names (prefix-safe)
	private static function t_master()   { global $wpdb; return $wpdb->prefix . 'watupro_master'; }
	private static function t_question() { global $wpdb; return $wpdb->prefix . 'watupro_question'; }
	private static function t_answer()   { global $wpdb; return $wpdb->prefix . 'watupro_answer'; }

	public static function init() {
		add_action( 'admin_post_ika_watupro_ping', [ __CLASS__, 'handle_ping' ] );

		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_post_ika_watupro_import', [ __CLASS__, 'handle_import' ] );
		add_action( 'admin_post_ika_watupro_export', [ __CLASS__, 'handle_export' ] );

		// NEW: Builder JSON generator
		add_action( 'admin_post_ika_watupro_build_json', [ __CLASS__, 'handle_builder_generate_json' ] );
	}

	public static function handle_ping() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

		set_transient( 'ika_watupro_import_last_result', [
			'ok'      => true,
			'message' => 'PING OK: admin-post handler executed.',
			'log'     => [ 'Ping reached at ' . gmdate('c') ],
		], 300 );

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

	/** =========================
	 * IMPORTER / EXPORTER PAGE
	 * ========================= */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

		$last = get_transient( 'ika_watupro_import_last_result' );
		delete_transient( 'ika_watupro_import_last_result' );

		$export_last = get_transient( 'ika_watupro_export_last_result' );
		delete_transient( 'ika_watupro_export_last_result' );

		
		$ck = get_transient( 'ika_watupro_cpt_last_checkpoint' );
$quizzes = self::list_quizzes();
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
			<?php if ( ! empty( $ck ) && ! empty( $ck['msg'] ) ) : ?>
				<div class="notice notice-warning">
					<p><strong>Last CPT checkpoint:</strong> <?php echo esc_html( $ck['msg'] ); ?></p>
				</div>
			<?php endif; ?>



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
			</form>
		</div>
		<?php
	}

	/** =========================
	 * QUIZ BUILDER → JSON
	 * ========================= */
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
							<p class="description">Loads defaults from <code><?php echo esc_html( self::t_master() ); ?></code> for the selected quiz ID.</p>
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
		$fields = self::master_settings_whitelist();

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

		$topics = self::csv_to_terms( $topics_csv );
		$aud    = self::csv_to_terms( $aud_csv );

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
		self::validate_payload( $tmp );

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

	private static function csv_to_terms( string $csv ) : array {
		$csv = trim($csv);
		if ( $csv === '' ) return [];
		$parts = array_map( 'trim', explode(',', $csv ) );
		$parts = array_filter( $parts, function($v){ return $v !== ''; } );
		return array_values( $parts );
	}

	private static function merge_builder_settings( array $base, array $incoming ) : array {
		$allowed = array_keys( self::master_settings_whitelist() );

		foreach ( $allowed as $k ) {
			if ( ! array_key_exists( $k, $incoming ) ) {
				// If checkbox-type key isn't present, treat as 0 (unchecked),
				// but ONLY for known boolean-ish keys.
				if ( self::is_boolish_master_key( $k ) ) {
					$base[$k] = 0;
				}
				continue;
			}

			$v = $incoming[$k];

			// Checkbox posts '1' as string
			if ( self::is_boolish_master_key( $k ) ) {
				$base[$k] = (int) ( (string)$v === '1' );
			} else {
				$base[$k] = is_scalar($v) ? (string)$v : '';
			}
		}

		return $base;
	}

	private static function is_boolish_master_key( string $key ) : bool {
		return in_array($key, [
			'is_active','require_login','take_again','email_taker','email_admin',
			'randomize_questions','pull_random','show_answers','single_page',
			'grades_by_percent','disallow_previous_button','live_result','is_scheduled',
			'submit_always_visible','show_pagination','enable_save_button',
			'shareable_final_screen','redirect_final_screen','takings_by_ip',
			'reuse_default_grades','store_progress','custom_per_page','randomize_cats',
			'no_ajax','pay_always','published_odd','delay_results','is_likert_survey',
			'limit_reused_questions','retake_after','is_personality_quiz'
		], true);
	}

	/**
	 * Safe whitelist of master-row settings you want to treat as defaults (seeded from quiz ID 6)
	 * This is the bridge between your "full master table row" reality and your importer JSON.
	 */
	private static function master_settings_whitelist() : array {
		return [
			// Common flags
			'is_active'                => 'is_active',
			'require_login'            => 'require_login',
			'take_again'               => 'take_again',
			'email_taker'              => 'email_taker',
			'email_admin'              => 'email_admin',
			'randomize_questions'      => 'randomize_questions',
			'login_mode'               => 'login_mode',
			'time_limit'               => 'time_limit',
			'pull_random'              => 'pull_random',
			'show_answers'             => 'show_answers',
			'single_page'              => 'single_page',
			'mode'                     => 'mode',
			'require_captcha'          => 'require_captcha',
			'grades_by_percent'        => 'grades_by_percent',
			'admin_email'              => 'admin_email',
			'disallow_previous_button' => 'disallow_previous_button',
			'email_output'             => 'email_output',
			'live_result'              => 'live_result',
			'gradecat_design'          => 'gradecat_design',
			'is_scheduled'             => 'is_scheduled',
			'schedule_from'            => 'schedule_from',
			'schedule_to'              => 'schedule_to',
			'submit_always_visible'    => 'submit_always_visible',
			'show_pagination'          => 'show_pagination',
			'advanced_settings'        => 'advanced_settings',
			'enable_save_button'       => 'enable_save_button',
			'shareable_final_screen'   => 'shareable_final_screen',
			'redirect_final_screen'    => 'redirect_final_screen',

			// Some of your other defaults
			'editor_id'                => 'editor_id',
			'takings_by_ip'            => 'takings_by_ip',
			'reuse_default_grades'     => 'reuse_default_grades',
			'store_progress'           => 'store_progress',
			'custom_per_page'          => 'custom_per_page',
			'randomize_cats'           => 'randomize_cats',
			'no_ajax'                  => 'no_ajax',
			'email_subject'            => 'email_subject',
			'pay_always'               => 'pay_always',
			'published_odd'            => 'published_odd',
			'published_odd_url'        => 'published_odd_url',
			'delay_results'            => 'delay_results',
			'delay_results_date'       => 'delay_results_date',
			'delay_results_content'    => 'delay_results_content',
			'is_likert_survey'         => 'is_likert_survey',
			'tags'                     => 'tags',
			'thumb'                    => 'thumb',
			'limit_reused_questions'   => 'limit_reused_questions',
			'retake_after'             => 'retake_after',
			'is_personality_quiz'      => 'is_personality_quiz',
		];
	}

	private static function get_master_defaults_for_builder( int $quiz_id ) : array {
		global $wpdb;
		$tm = self::t_master();

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
		foreach ( self::master_settings_whitelist() as $key => $label ) {
			if ( array_key_exists( $key, $row ) ) {
				// Preserve raw strings for non-boolish fields; cast boolish to int
				if ( self::is_boolish_master_key( $key ) ) {
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

	/** =========================
	 * IMPORT
	 * ========================= */
	public static function handle_import() {
		// --- HARD FAIL-SAFE: capture fatal errors and surface them in the importer notice ---
			register_shutdown_function( function() {
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

				// If the normal result transient wasn't written, write it now so the importer page shows *something*.
				set_transient( 'ika_watupro_import_last_result', [
					'ok'      => false,
					'message' => $msg,
					'log'     => [ $msg ],
				], 300 );
			} );

			// Also drop a "started" marker so we know handle_import was reached at all.
			set_transient( 'ika_watupro_import_last_result', [
				'ok'      => false,
				'message' => 'DEBUG: handle_import started (if you later see a fatal, it happened after this point).',
				'log'     => [ 'DEBUG: handle_import started' ],
			], 300 );
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
		$replace_mode     = self::normalize_replace_mode( $raw_replace_mode, $replace_existing );

		$plan = self::replace_plan( $replace_mode, $cpt_enable );

		$log = [];		
		$log[] = "Replace mode: {$replace_mode}";
		
		// DEBUG marker so we always know the handler completed enough to reach this point.
		set_transient( 'ika_watupro_import_last_result', [
			'ok'      => false,
			'message' => 'DEBUG: handle_import reached (if this is the only message, the request died later).',
			'log'     => $log,
		], 300 );

		try {
			if ( empty( $_FILES['ika_json_file']['tmp_name'] ) ) throw new Exception( 'No file uploaded.' );

			$raw = file_get_contents( $_FILES['ika_json_file']['tmp_name'] );
			if ( ! $raw ) throw new Exception( 'Could not read uploaded file.' );

			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) throw new Exception( 'Invalid JSON.' );

			// Normalize wrappers/case
			if ( isset($data['data']) && is_array($data['data']) )           $data = $data['data'];
			if ( isset($data['payload']) && is_array($data['payload']) )     $data = $data['payload'];
			if ( ! isset($data['questions']) && isset($data['Questions']) )  $data['questions'] = $data['Questions'];
			if ( ! isset($data['quiz']) && isset($data['Quiz']) )            $data['quiz'] = $data['Quiz'];

			// Convert locked ChatGPT schema v1.0 → one-or-more internal payloads
			$payloads = self::expand_chatgpt_schema_to_payloads( $data, $log );
			if ( ! is_array($payloads) || empty($payloads) ) throw new Exception( 'No quizzes found in JSON.' );

			// Validate each payload for the selected replace mode
			foreach ( $payloads as $pi => $p ) {
				if ( ! is_array($p) ) throw new Exception( 'Invalid payload at index ' . $pi );
				self::validate_payload_by_mode( $p, $replace_mode );
			}

			global $wpdb;
			if ( ! $is_dry ) $wpdb->query( 'START TRANSACTION' );

			$total_quizzes   = 0;
			$total_questions = 0;
			$total_answers   = 0;

			foreach ( $payloads as $p ) {
				$total_quizzes++;
				$raw_one = wp_json_encode( $p, JSON_UNESCAPED_SLASHES );
				$res = self::import_one_payload( $p, $raw_one, $plan, $is_dry, $replace_mode, $cpt_enable, $log );
				$total_questions += (int) ($res['questions'] ?? 0);
				$total_answers   += (int) ($res['answers'] ?? 0);
			}

			if ( ! $is_dry ) $wpdb->query( 'COMMIT' );

			$msg = $is_dry
				? "Dry Run complete: {$total_quizzes} quiz(es), {$total_questions} question(s), {$total_answers} answer(s). Mode={$replace_mode}."
				: "Import complete: {$total_quizzes} quiz(es), {$total_questions} question(s), {$total_answers} answer(s). Mode={$replace_mode}.";

			set_transient( 'ika_watupro_import_last_result', [
				'ok'      => true,
				'message' => $msg,
				'log'     => $log,
			], 60 );

		} catch ( Throwable $e ) {
			global $wpdb;
			if ( ! empty( $wpdb ) && ! $is_dry ) {
				$wpdb->query( 'ROLLBACK' );
			}

			set_transient( 'ika_watupro_import_last_result', [
				'ok'      => false,
				'message' => $e->getMessage(),
				'log'     => $log,
			], 60 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer' ) );
		exit;
	}

	/** =========================
	 * SELECTIVE REPLACE: MODE + PLAN
	 * ========================= */
	private static function normalize_replace_mode( string $raw_mode, bool $legacy_replace_existing ) : string {
		$raw_mode = strtolower( trim( $raw_mode ) );

		$allowed = [ 'auto','none','all','settings','questions','tags','cpt' ];
		if ( ! in_array( $raw_mode, $allowed, true ) ) $raw_mode = 'auto';

		if ( $raw_mode === 'auto' ) {
			return $legacy_replace_existing ? 'all' : 'none';
		}

		return $raw_mode;
	}

	private static function replace_plan( string $mode, bool $cpt_enable ) : array {
		$plan = [
			'needs_exam'        => false,
			'update_master'     => false,
			'replace_questions' => false,
			'touch_questions'   => false,
			'sync_cpt'          => false,
			'apply_tags'        => false,
		];

		switch ( $mode ) {
			case 'all':
				$plan['needs_exam']        = true;
				$plan['update_master']     = true;
				$plan['replace_questions'] = true;
				$plan['touch_questions']   = true;
				$plan['sync_cpt']          = $cpt_enable;
				$plan['apply_tags']        = true;
				break;

			case 'settings':
				$plan['needs_exam']        = true;
				$plan['update_master']     = true;
				$plan['sync_cpt']          = $cpt_enable;
				break;

			case 'questions':
				$plan['needs_exam']        = true;
				$plan['replace_questions'] = true;
				$plan['touch_questions']   = true;
				$plan['sync_cpt']          = $cpt_enable;
				break;

			case 'tags':
				$plan['needs_exam']        = false;
				$plan['sync_cpt']          = $cpt_enable;
				$plan['apply_tags']        = true;
				break;

			case 'cpt':
				$plan['needs_exam']        = false;
				$plan['sync_cpt']          = $cpt_enable;
				break;

			case 'none':
			default:
				$plan['needs_exam']        = true;
				$plan['update_master']     = true;
				$plan['sync_cpt']          = $cpt_enable;
				break;
		}

		return $plan;
	}

	/** =========================
	 * MODE-AWARE VALIDATION
	 * ========================= */
	private static function validate_payload_by_mode( array $data, string $replace_mode ) : void {
		if ( empty( $data['quiz'] ) || ! is_array( $data['quiz'] ) ) throw new Exception( 'Missing "quiz" object.' );
		if ( empty( $data['quiz']['name'] ) ) throw new Exception( 'Missing quiz.name.' );

		$needs_questions = in_array( $replace_mode, [ 'all', 'questions' ], true );

		if ( $needs_questions ) {
			self::validate_payload( $data );
			return;
		}

		if ( array_key_exists( 'questions', $data ) && ! is_array( $data['questions'] ) ) {
			throw new Exception( '"questions" must be an array when provided.' );
		}
	}

	/** =========================
	 * CHATGPT LOCKED SCHEMA (v1.0) → INTERNAL PAYLOAD
	 * ========================= */

	private static function is_chatgpt_schema( array $data ) : bool {
		return isset($data['version'], $data['quizzes']) && is_array($data['quizzes']);
	}

	private static function expand_chatgpt_schema_to_payloads( array $data, array &$log ) : array {
		if ( ! self::is_chatgpt_schema( $data ) ) {
			return [ $data ];
		}

		$ver = (string) $data['version'];
		if ( $ver !== '1.0' ) {
			throw new Exception( 'Unsupported schema version: ' . $ver . ' (expected 1.0).' );
		}

		$payloads = [];
		foreach ( $data['quizzes'] as $qi => $qz ) {
			if ( ! is_array($qz) ) throw new Exception( 'Invalid quiz object at quizzes['.$qi.'].' );

			$quiz_title = isset($qz['quiz_title']) ? trim((string)$qz['quiz_title']) : '';
			if ( $quiz_title === '' ) throw new Exception( 'Missing quiz_title at quizzes['.$qi.'].' );

			$base_exam_id = isset($qz['base_exam_id']) ? (int) $qz['base_exam_id'] : 0;
			if ( $base_exam_id <= 0 ) $base_exam_id = 6;

			$defaults = self::get_master_defaults_for_builder( $base_exam_id );
			$settings = is_array($defaults['settings'] ?? null) ? $defaults['settings'] : [];

			$over = ( isset($qz['settings_overrides']) && is_array($qz['settings_overrides']) ) ? $qz['settings_overrides'] : [];
			if ( $over ) $settings = array_merge( $settings, $over );

			// Force desired platform default: allow retakes
			$settings['take_again'] = 1;

			$quiz_block = [
				'name'                 => $quiz_title,
				'description_html'     => isset($qz['description_html']) ? (string) $qz['description_html'] : '',
				'final_screen_html'    => isset($qz['final_screen_html']) ? (string) $qz['final_screen_html'] : '',
				'reuse_questions_from' => '',
				'settings'             => $settings,
			];

			if ( empty($qz['questions']) || ! is_array($qz['questions']) ) {
				throw new Exception( 'Missing questions array at quizzes['.$qi.'].' );
			}

			$questions = [];
			foreach ( $qz['questions'] as $i => $qq ) {
				if ( ! is_array($qq) ) throw new Exception( 'Invalid question object at quizzes['.$qi.'].questions['.$i.'].' );

				$qtext = isset($qq['q']) ? (string) $qq['q'] : '';
				if ( trim($qtext) === '' ) throw new Exception( 'Missing q at quizzes['.$qi.'].questions['.$i.'].' );

				$qtype = isset($qq['type']) ? strtolower(trim((string)$qq['type'])) : 'single';
				$answer_type = ( $qtype === 'single' ) ? 'radio' : ( $qtype === 'multi' ? 'checkbox' : 'radio' );

				$explain = isset($qq['explanation']) ? (string) $qq['explanation'] : '';
				if ( trim($explain) === '' ) throw new Exception( 'Missing explanation at quizzes['.$qi.'].questions['.$i.'].' );

				$choices = isset($qq['choices']) && is_array($qq['choices']) ? $qq['choices'] : [];
				if ( ! $choices ) throw new Exception( 'Missing choices at quizzes['.$qi.'].questions['.$i.'].' );

				$answers = [];
				foreach ( $choices as $ci => $cc ) {
					if ( ! is_array($cc) ) throw new Exception( 'Invalid choice at quizzes['.$qi.'].questions['.$i.'].choices['.$ci.'].' );
					$atext = isset($cc['a']) ? (string) $cc['a'] : '';
					if ( trim($atext) === '' ) throw new Exception( 'Missing choice text at quizzes['.$qi.'].questions['.$i.'].choices['.$ci.'].' );

					$answers[] = [
						'answer_html' => $atext,
						'correct'     => ! empty($cc['correct']) ? 1 : 0,
					];
				}

				$questions[] = [
					'question_html'       => $qtext,
					'answer_type'         => $answer_type,
					'explain_answer_html' => $explain,
					'answers'             => $answers,
				];
			}

			$tags_block = [];
			if ( isset($qz['tags']) && is_array($qz['tags']) && ! empty($qz['tags']) ) {
				$tags_block['topics'] = array_values( array_map('strval', $qz['tags']) );
			}
			if ( isset($qz['level']) && (string)$qz['level'] !== '' ) {
				$tags_block['difficulty'] = (string) $qz['level'];
			}
			if ( isset($qz['group']) && (string)$qz['group'] !== '' ) {
				$tags_block['audience'] = [ (string) $qz['group'] ];
			}

			$payload = [
				'quiz'      => $quiz_block,
				'questions' => $questions,
			];

			if ( $tags_block ) $payload['tags'] = $tags_block;

			$payloads[] = $payload;
			$log[] = "Converted ChatGPT schema quiz #".($qi+1)." → internal payload (base_exam_id={$base_exam_id}).";
		}

		return $payloads;
	}

	private static function import_one_payload( array $data, string $raw_one, array $plan, bool $is_dry, string $replace_mode, bool $cpt_enable, array &$log ) : array {
		try {
		
			// Auto-clear reuse_questions_from whenever questions are provided on import (even if mode won't apply them)
			$has_questions = isset($data['questions']) && is_array($data['questions']) && count($data['questions']) > 0;
			if ( $has_questions ) {
				$data['quiz']['reuse_questions_from'] = '';
				if ( ! isset($data['quiz']['settings']) || ! is_array($data['quiz']['settings']) ) {
					$data['quiz']['settings'] = [];
				}
				$data['quiz']['settings']['reuse_questions_from'] = '';
			}

			$quiz      = $data['quiz'];
			$quiz_name = trim( (string) $quiz['name'] );
			$log[]     = 'Quiz name: ' . $quiz_name;

			$quiz_id = self::find_quiz_id_by_name( $quiz_name );

			if ( $quiz_id ) $log[] = "Existing quiz found (ID={$quiz_id}).";
			else $log[] = "No existing quiz found.";

			if ( ! $quiz_id ) {
				if ( $plan['needs_exam'] ) {
					if ( ! $is_dry ) {
						$quiz_id = self::insert_master( $quiz, $log );
					} else {
						// Dry Run: simulate a quiz ID so we can preview/import questions/answers and get accurate counts/logs.
						$quiz_id = -1;
						$log[] = '[Dry Run] Would insert new quiz master row (simulated quiz_id=-1 for preview).';
					}
				} else {
					$log[] = "Mode '{$replace_mode}' does not create master quiz rows. Nothing to do without an existing quiz.";
				}
			}

			if ( $quiz_id && $plan['update_master'] ) {
				if ( ! $is_dry ) self::update_master( (int) $quiz_id, $quiz, $log );
				else $log[] = '[Dry Run] Would update quiz master row.';
			}

			$questions = ( isset($data['questions']) && is_array($data['questions']) ) ? $data['questions'] : [];
			$q_count = 0;
			$a_count = 0;

			if ( $quiz_id && $plan['replace_questions'] ) {
				if ( ! $is_dry ) {
					$log[] = "Replace questions enabled → delete existing questions/answers for quiz ID={$quiz_id}.";
					self::delete_quiz_children( (int) $quiz_id, $log );
				} else {
					$log[] = '[Dry Run] Would delete existing questions/answers.';
				}

				foreach ( $questions as $i => $q ) {
					$q_count++;
					$sort_order = isset( $q['sort_order'] ) ? (int) $q['sort_order'] : ($i + 1);

					$q = self::apply_safe_mappings_to_question( $q, $log );

					if ( ! $is_dry ) $qid = self::insert_question( (int) $quiz_id, $q, $sort_order, $log );
					else {
						$qid = 0;
						$log[] = "[Dry Run] Would insert question #{$q_count} (sort_order={$sort_order}, answer_type=" . (isset($q['answer_type']) ? $q['answer_type'] : '') . ").";
					}

					$answers = isset($q['answers']) && is_array($q['answers']) ? $q['answers'] : [];
					foreach ( $answers as $j => $ans ) {
						$a_count++;
						$ans_sort = isset( $ans['sort_order'] ) ? (int) $ans['sort_order'] : ($j + 1);
						if ( ! $is_dry ) self::insert_answer( (int) $qid, $ans, $ans_sort, $log );
						else $log[] = "[Dry Run] Would insert answer (sort_order={$ans_sort}).";
					}
				}

			} else {
				if ( $plan['touch_questions'] ) $log[] = "Question import skipped (quiz missing or mode does not allow question changes).";
				else $log[] = "No question changes in mode '{$replace_mode}'.";
			}

			$post_id = 0;
			if ( $plan['sync_cpt'] ) {
				if ( $cpt_enable ) {
					if ( $is_dry ) {
						$log[] = '[Dry Run] Would create/update CPT post and link exam ID.';
					} else {
					  try {
						$post_id = self::upsert_quiz_cpt_post( (int) $quiz_id, $quiz, $raw_one, $log );

						if ( $post_id ) {
						  $log[] = "CPT linked: post_id={$post_id}, meta(" . self::CPT_META_EXAM_ID . ")={$quiz_id}.";
						  self::cpt_health_check( (int) $post_id, (int) $quiz_id, $log );
						} else {
						  $log[] = "CPT sync returned no post_id (unexpected).";
						}
					  } catch ( Throwable $t ) {
						// PHP 8+: catches Errors (undefined function, type errors, etc.) as well as Exceptions
						$log[] = 'CPT sync failed (caught): ' . $t->getMessage();
						if ( function_exists('wp_get_environment_type') ) {
						  $log[] = 'WP env: ' . wp_get_environment_type();
						}
					  }
					}
				} else {
					$log[] = "CPT integration disabled by checkbox. Skipping CPT work.";
				}
			}

			if ( $plan['apply_tags'] ) {
				$tags = [];
				if ( isset($data['tags']) && is_array($data['tags']) ) $tags = $data['tags'];
				elseif ( isset($data['quiz']['tags']) && is_array($data['quiz']['tags']) ) $tags = $data['quiz']['tags'];

				if ( $is_dry ) {
					$log[] = '[Dry Run] Would apply tags to CPT post (topics/difficulty/audience).';
				} else {
					if ( ! $cpt_enable ) $log[] = "Tags mode requested but CPT integration checkbox is OFF. Cannot apply tags without a CPT post.";
					elseif ( ! $post_id ) $log[] = "Tags mode requested but no CPT post_id available. Skipping tags.";
					else self::apply_quiz_tags( (int) $post_id, $tags, $log );
				}
			}

			return [ 'quiz_id' => (int)$quiz_id, 'post_id' => (int)$post_id, 'questions' => (int)$q_count, 'answers' => (int)$a_count ];
		} catch ( Throwable $t ) {
			$log[] = 'IMPORT_ONE_PAYLOAD fatal (caught): ' . $t->getMessage();
			if ( function_exists('wp_get_environment_type') ) {
				$log[] = 'WP env: ' . wp_get_environment_type();
			}
			throw $t;
		}	
	}

	/** =========================
	 * TAG APPLICATION (CPT taxonomies)
	 * ========================= */
	private static function apply_quiz_tags( int $post_id, array $tags, array &$log ) : void {
		if ( ! $post_id ) return;

		if ( isset($tags['topics']) && is_array($tags['topics']) ) {
			if ( taxonomy_exists( self::TAX_TOPIC ) ) {
				wp_set_object_terms( $post_id, array_values($tags['topics']), self::TAX_TOPIC, false );
				$log[] = "Applied topics (" . count($tags['topics']) . ") to taxonomy " . self::TAX_TOPIC . ".";
			} else {
				$log[] = "Taxonomy missing: " . self::TAX_TOPIC . " (topics not applied).";
			}
		}

		if ( isset($tags['difficulty']) && $tags['difficulty'] !== '' ) {
			if ( taxonomy_exists( self::TAX_DIFFICULTY ) ) {
				wp_set_object_terms( $post_id, [ (string)$tags['difficulty'] ], self::TAX_DIFFICULTY, false );
				$log[] = "Applied difficulty to taxonomy " . self::TAX_DIFFICULTY . ".";
			} else {
				$log[] = "Taxonomy missing: " . self::TAX_DIFFICULTY . " (difficulty not applied).";
			}
		}

		if ( isset($tags['audience']) && is_array($tags['audience']) ) {
			if ( taxonomy_exists( self::TAX_AUDIENCE ) ) {
				wp_set_object_terms( $post_id, array_values($tags['audience']), self::TAX_AUDIENCE, false );
				$log[] = "Applied audience (" . count($tags['audience']) . ") to taxonomy " . self::TAX_AUDIENCE . ".";
			} else {
				$log[] = "Taxonomy missing: " . self::TAX_AUDIENCE . " (audience not applied).";
			}
		}
	}

	/** =========================
	 * ORIGINAL HELPERS
	 * ========================= */
	private static function validate_payload( array $data ) {
		if ( empty( $data['quiz'] ) || ! is_array( $data['quiz'] ) ) throw new Exception( 'Missing "quiz" object.' );
		if ( empty( $data['quiz']['name'] ) ) throw new Exception( 'Missing quiz.name.' );

		if ( ! array_key_exists( 'questions', $data ) || ! is_array( $data['questions'] ) ) {
			$keys = implode(', ', array_keys($data));
			throw new Exception( 'Missing "questions" array. Top-level keys found: ' . $keys );
		}

		foreach ( $data['questions'] as $idx => $q ) {
			if ( empty( $q['question_html'] ) ) throw new Exception( "Question #".($idx+1)." missing question_html." );
			if ( empty( $q['answer_type'] ) ) throw new Exception( "Question #".($idx+1)." missing answer_type." );
			if ( empty( $q['answers'] ) || ! is_array( $q['answers'] ) ) throw new Exception( "Question #".($idx+1)." missing answers array." );

			// ✅ Required: exactly one explanation per question (stored in watupro_question.explain_answer)
			if ( empty( $q['explain_answer_html'] ) ) {
				throw new Exception( "Question #".($idx+1)." missing explain_answer_html (required)." );
			}

			foreach ( $q['answers'] as $aidx => $a ) {
				if ( empty( $a['answer_html'] ) ) throw new Exception( "Question #".($idx+1)." answer #".($aidx+1)." missing answer_html." );
				// 🚫 Disallow answer-level explanations (we use question-level only)
				if ( isset($a['explanation_html']) && trim((string)$a['explanation_html']) !== '' ) {
					throw new Exception( "Question #".($idx+1)." answer #".($aidx+1)." includes explanation_html. Use explain_answer_html at the question level only." );
				}
			}
		}
	}


	private static function apply_safe_mappings_to_question( array $q, array &$log ) : array {
		$raw_type = isset($q['answer_type']) ? strtolower(trim((string)$q['answer_type'])) : '';
		$map = [
			'single'   => 'radio',
			'multi'    => 'checkbox',
			'multiple' => 'checkbox',
			'open'     => 'textarea',
			'text'     => 'textarea',
		];

		if ( $raw_type && isset($map[$raw_type]) ) {
			$q['answer_type'] = $map[$raw_type];
			$log[] = "Safe-mapped answer_type '{$raw_type}' → '{$q['answer_type']}'.";
		}

		if ( $raw_type === 'truefalse' || $raw_type === 'true_false' || $raw_type === 'tf' ) {
			$q['answer_type'] = 'radio';
			$q['truefalse'] = 1;
			$log[] = "Safe-mapped answer_type '{$raw_type}' → radio + truefalse=1.";
		}

		return $q;
	}

	private static function find_quiz_id_by_name( string $name ) {
		global $wpdb;
		$table = self::t_master();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$table} WHERE name = %s LIMIT 1", $name ) );
	}

	private static function insert_master( array $quiz, array &$log ) {
		global $wpdb;
		$table = self::t_master();

		$desc  = isset( $quiz['description_html'] ) ? wp_kses_post( (string) $quiz['description_html'] ) : '';
		$final = isset( $quiz['final_screen_html'] ) ? wp_kses_post( (string) $quiz['final_screen_html'] ) : '';

		$settings = ( isset( $quiz['settings'] ) && is_array( $quiz['settings'] ) ) ? $quiz['settings'] : [];

		$row = [
			'name'         => sanitize_text_field( (string) $quiz['name'] ),
			'description'  => $desc,
			'final_screen' => $final,
			'added_on'     => current_time( 'mysql' ),
		];

		// Apply whitelisted master fields from settings (safe)
		$row = array_merge( $row, self::settings_to_master_row_whitelist( $settings ) );

		// reuse_questions_from may be passed at quiz root or inside settings
		if ( isset($quiz['reuse_questions_from']) ) {
			$row['reuse_questions_from'] = sanitize_text_field( (string) $quiz['reuse_questions_from'] );
		} elseif ( isset($settings['reuse_questions_from']) ) {
			$row['reuse_questions_from'] = sanitize_text_field( (string) $settings['reuse_questions_from'] );
		}

		if ( ! $wpdb->insert( $table, $row ) ) throw new Exception( 'Insert quiz failed: ' . $wpdb->last_error );

		$id = (int) $wpdb->insert_id;
		$log[] = "Inserted quiz master row ID={$id}.";
		return $id;
	}

	private static function update_master( int $quiz_id, array $quiz, array &$log ) {
		global $wpdb;
		$table = self::t_master();

		$desc  = array_key_exists( 'description_html', $quiz ) ? wp_kses_post( (string) $quiz['description_html'] ) : null;
		$final = array_key_exists( 'final_screen_html', $quiz ) ? wp_kses_post( (string) $quiz['final_screen_html'] ) : null;

		$settings = ( isset( $quiz['settings'] ) && is_array( $quiz['settings'] ) ) ? $quiz['settings'] : [];

		$update = [];

		if ( isset( $quiz['name'] ) ) $update['name'] = sanitize_text_field( (string) $quiz['name'] );
		if ( $desc !== null ) $update['description'] = $desc;
		if ( $final !== null ) $update['final_screen'] = $final;

		// Apply whitelisted master fields from settings (safe)
		$update = array_merge( $update, self::settings_to_master_row_whitelist( $settings ) );

		if ( array_key_exists( 'reuse_questions_from', $quiz ) ) {
			$update['reuse_questions_from'] = sanitize_text_field((string)$quiz['reuse_questions_from']);
		} elseif ( array_key_exists( 'reuse_questions_from', $settings ) ) {
			$update['reuse_questions_from'] = sanitize_text_field((string)$settings['reuse_questions_from']);
		}

		if ( empty( $update ) ) {
			$log[] = "No master fields to update for quiz ID={$quiz_id}.";
			return;
		}

		$ok = $wpdb->update( $table, $update, [ 'ID' => $quiz_id ] );
		if ( $ok === false ) throw new Exception( 'Update quiz failed: ' . $wpdb->last_error );

		$log[] = "Updated quiz master row ID={$quiz_id}.";
	}

	private static function settings_to_master_row_whitelist( array $settings ) : array {
		$out = [];

		// Only write fields that truly exist in master table AND are in our whitelist mapping.
		foreach ( self::master_settings_whitelist() as $k => $_label ) {
			if ( ! array_key_exists( $k, $settings ) ) continue;

			$v = $settings[$k];

			if ( self::is_boolish_master_key( $k ) ) {
				$out[$k] = (int) ( (string)$v === '1' || (int)$v === 1 );
			} else {
				// preserve raw strings for fields like admin_email, email_output, gradecat_design, advanced_settings, etc.
				$out[$k] = is_scalar($v) ? (string)$v : '';
			}
		}

		return $out;
	}

	private static function delete_quiz_children( int $quiz_id, array &$log ) {
		global $wpdb;

		$tq = self::t_question();
		$ta = self::t_answer();

		$qids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$tq} WHERE exam_id = %d", $quiz_id ) );
		if ( $qids ) {
			$in = implode( ',', array_map( 'intval', $qids ) );
			$wpdb->query( "DELETE FROM {$ta} WHERE question_id IN ({$in})" );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tq} WHERE exam_id = %d", $quiz_id ) );
			$log[] = "Deleted " . count($qids) . " questions and their answers for quiz ID={$quiz_id}.";
		} else {
			$log[] = "No existing questions found to delete for quiz ID={$quiz_id}.";
		}
	}

	private static function insert_question( int $quiz_id, array $q, int $sort_order, array &$log ) {
		global $wpdb;
		$table = self::t_question();

		$row = [
			'exam_id'                => $quiz_id,
			'question'               => wp_kses_post( (string) $q['question_html'] ),
			'answer_type'            => sanitize_text_field( (string) $q['answer_type'] ),
			'sort_order'             => $sort_order,
			'explain_answer'         => isset($q['explain_answer_html']) ? wp_kses_post( (string) $q['explain_answer_html'] ) : null,
			'title'                  => isset($q['title']) ? sanitize_text_field( (string) $q['title'] ) : '',
			'dont_randomize_answers' => isset($q['dont_randomize_answers']) ? (int) $q['dont_randomize_answers'] : 0,
			'truefalse'              => isset($q['truefalse']) ? (int) $q['truefalse'] : 0,
		];

		if ( ! $wpdb->insert( $table, $row ) ) throw new Exception( 'Insert question failed: ' . $wpdb->last_error );

		$qid = (int) $wpdb->insert_id;
		$log[] = "Inserted question ID={$qid} (quiz ID={$quiz_id}, sort_order={$sort_order}).";
		return $qid;
	}

	private static function insert_answer( int $question_id, array $ans, int $sort_order, array &$log ) {
		global $wpdb;
		$table = self::t_answer();

		$correct = 0;
		if ( isset($ans['correct']) )    $correct = (int) $ans['correct'] ? 1 : 0;
		if ( isset($ans['is_correct']) ) $correct = (int) $ans['is_correct'] ? 1 : 0;

		$row = [
			'question_id'     => $question_id,
			'answer'          => wp_kses_post( (string) $ans['answer_html'] ),
			'correct'         => $correct ? '1' : '0',
			'point'           => isset($ans['point']) ? (float) $ans['point'] : 0.00,
			'sort_order'      => $sort_order,
			'explanation'     => null, // locked: explanations are question-level only
			'grade_id'        => '0',
			'chk_group'       => 0,
			'is_checked'      => 0,
			'accept_freetext' => 0,
		];

		if ( ! $wpdb->insert( $table, $row ) ) throw new Exception( 'Insert answer failed: ' . $wpdb->last_error );

		$aid = (int) $wpdb->insert_id;
		$log[] = "Inserted answer ID={$aid} (question ID={$question_id}, correct={$row['correct']}, sort_order={$sort_order}).";
		return $aid;
	}

	/**
	 * CPT upsert:
	 * - Find existing CPT post by meta _ika_watupro_exam_id
	 * - Fallback: find by exact title match if no meta-linked post exists
	 * - ALWAYS force post_content to [watupro EXAM_ID] so wrapper filter can apply hero-jet
	 * - Always attach exam_id meta for stability
	 */
	
	private static function cpt_checkpoint( string $msg, array &$log ) : void {
		$log[] = '[CPT] ' . $msg;
		set_transient( 'ika_watupro_cpt_last_checkpoint', [
			'time' => time(),
			'msg'  => $msg,
		], 300 );
	}

private static function upsert_quiz_cpt_post( int $exam_id, array $quiz, string $raw_json, array &$log ) : int {
		self::cpt_checkpoint( "Entered upsert_quiz_cpt_post(exam_id={$exam_id})", $log );

		$post_type = self::CPT_POST_TYPE;

		if ( ! post_type_exists( $post_type ) ) {
			self::cpt_checkpoint( "post_type '{$post_type}' does not exist", $log );
			return 0;
		}

		$title   = sanitize_text_field( (string) ( $quiz['name'] ?? '' ) );
		$content = '[watupro ' . (int) $exam_id . ']';

		self::cpt_checkpoint( 'Looking for existing CPT post by exam_id meta', $log );

		$existing = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => self::CPT_META_EXAM_ID,
			'meta_value'     => $exam_id,
			'fields'         => 'ids',
		] );

		$existing_id = ! empty( $existing ) ? (int) $existing[0] : 0;
		self::cpt_checkpoint( $existing_id ? "Found existing post_id={$existing_id}" : 'No existing post found', $log );

		$postarr = [
			'ID'           => $existing_id,
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		];

		if ( $existing_id ) {
			self::cpt_checkpoint( "Calling wp_update_post(post_id={$existing_id})", $log );
			$post_id = wp_update_post( $postarr, true );
			self::cpt_checkpoint( 'Returned from wp_update_post()', $log );

			if ( is_wp_error( $post_id ) ) throw new Exception( 'CPT update failed: ' . $post_id->get_error_message() );
			$log[] = "Updated CPT post ID={$post_id} (forced content: [watupro {$exam_id}]).";
		} else {
			self::cpt_checkpoint( 'Calling wp_insert_post()', $log );
			$post_id = wp_insert_post( $postarr, true );
			self::cpt_checkpoint( 'Returned from wp_insert_post()', $log );

			if ( is_wp_error( $post_id ) ) throw new Exception( 'CPT insert failed: ' . $post_id->get_error_message() );
			$log[] = "Created CPT post ID={$post_id} (forced content: [watupro {$exam_id}]).";
		}

		self::cpt_checkpoint( 'Updating post meta', $log );
		update_post_meta( $post_id, self::CPT_META_EXAM_ID, $exam_id );
		update_post_meta( $post_id, self::CPT_META_IMPORT_HASH, hash( 'sha256', (string) $raw_json ) );

		self::cpt_checkpoint( "Done upsert_quiz_cpt_post(post_id={$post_id})", $log );
		return (int) $post_id;
	}


	private static function cpt_health_check( int $post_id, int $exam_id, array &$log ) : void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$log[] = "[CPT Health] ERROR: post_id={$post_id} not found.";
			return;
		}

		$meta_exam = get_post_meta( $post_id, self::CPT_META_EXAM_ID, true );
		$expected_shortcode = '[watupro ' . (int) $exam_id . ']';

		$has_shortcode = ( strpos( (string) $post->post_content, $expected_shortcode ) !== false );

		$log[] = "[CPT Health] post_id={$post_id}, post_type={$post->post_type}, status={$post->post_status}";
		$log[] = "[CPT Health] meta " . self::CPT_META_EXAM_ID . " = " . ( $meta_exam === '' ? '(empty)' : $meta_exam ) . " (expected {$exam_id})";
		$log[] = "[CPT Health] shortcode present: " . ( $has_shortcode ? 'YES' : 'NO' ) . " (expected {$expected_shortcode})";

		if ( (string) $meta_exam !== (string) $exam_id ) {
			$log[] = "[CPT Health] WARNING: CPT meta exam_id mismatch. Importer may not update the correct post next run.";
		}
		if ( ! $has_shortcode ) {
			$log[] = "[CPT Health] WARNING: CPT post_content does not contain the expected WatuPRO shortcode, wrapper will not apply.";
		}
	}

	/** =========================
	 * EXPORT
	 * ========================= */
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
			$payload = self::export_quiz_payload( $quiz_id );
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

	private static function export_quiz_payload( int $quiz_id ) : array {
		global $wpdb;

		$tm = self::t_master();
		$tq = self::t_question();
		$ta = self::t_answer();

		$master = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tm} WHERE ID = %d", $quiz_id ), ARRAY_A );
		if ( ! $master ) throw new Exception( "Quiz ID {$quiz_id} not found." );

		$source_quiz_id = $quiz_id;
		$reuse_raw = isset($master['reuse_questions_from']) ? trim((string)$master['reuse_questions_from']) : '';
		if ( $reuse_raw !== '' && $reuse_raw !== '0' ) {
			if ( preg_match( '/^\d+$/', $reuse_raw ) ) {
				$source_quiz_id = (int) $reuse_raw;
			} elseif ( preg_match( '/\d+/', $reuse_raw, $m ) ) {
				$source_quiz_id = (int) $m[0];
			}
		}

		$questions = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tq} WHERE exam_id = %d ORDER BY sort_order ASC, ID ASC", $source_quiz_id ),
			ARRAY_A
		);

		$export_note = '';
		if ( empty($questions) ) {
			$export_note = 'No questions found for the exported source exam_id. This quiz may be empty or the source exam_id may be incorrect.';
		} elseif ( $source_quiz_id !== $quiz_id ) {
			$export_note = 'This quiz reuses questions. Export pulled questions from the source exam_id instead of the selected quiz ID.';
		}

		$out_questions = [];
		foreach ( $questions as $q ) {
			$answers = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$ta} WHERE question_id = %d ORDER BY sort_order ASC, ID ASC", (int)$q['ID'] ),
				ARRAY_A
			);

			$out_answers = [];
			foreach ( $answers as $a ) {
				$out_answers[] = [
					'answer_html'      => $a['answer'],
					'correct'          => (int) ( $a['correct'] === '1' ),
					'point'            => (float) $a['point'],
					'sort_order'       => (int) $a['sort_order'],
					'explanation_html' => $a['explanation'],
				];
			}

			$out_questions[] = [
				'title'                 => $q['title'],
				'question_html'          => $q['question'],
				'answer_type'            => $q['answer_type'],
				'sort_order'             => (int) $q['sort_order'],
				'explain_answer_html'    => $q['explain_answer'],
				'dont_randomize_answers' => (int) $q['dont_randomize_answers'],
				'truefalse'              => (int) $q['truefalse'],
				'answers'                => $out_answers,
			];
		}

		// Export settings using the same whitelist keys to keep builder/import aligned
		$settings = [];
		foreach ( self::master_settings_whitelist() as $k => $_label ) {
			if ( array_key_exists( $k, $master ) ) {
				$settings[$k] = self::is_boolish_master_key($k) ? (int)$master[$k] : (string)$master[$k];
			}
		}

		return [
			'quiz' => [
				'name'                   => $master['name'],
				'description_html'       => $master['description'],
				'final_screen_html'      => $master['final_screen'],
				'reuse_questions_from'   => $master['reuse_questions_from'],
				'_export_source_exam_id' => $source_quiz_id,
				'settings'               => $settings,
			],
			'questions'    => $out_questions,
			'_export_note' => $export_note,
		];
	}

	private static function list_quizzes() : array {
		global $wpdb;
		$tm = self::t_master();
		return $wpdb->get_results( "SELECT ID, name FROM {$tm} ORDER BY ID DESC LIMIT 300", ARRAY_A ) ?: [];
	}
}

IKA_WatuPRO_Importer::init();
