<?php
/**
 * IKA WatuPRO Quiz Importer + Exporter (JSON)
 * - Import quizzes/questions/answers/explanations into WatuPRO tables
 * - Dry Run supported
 * - Optional: Replace existing questions/answers for an existing quiz
 * - Export existing quiz to JSON (round-trip compatible)
 * - Handles "reuse questions" quizzes by exporting questions from reuse source exam_id
 * - IMPORTANT: Auto-clears reuse_questions_from whenever questions are provided on import
 * - Optional: Create/Update a Quiz CPT post and link via post meta
 *
 * SECURITY:
 * - Admin-only
 * - Nonce protected
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class IKA_WatuPRO_Importer {

	/** =========================
	 * CONFIG (adjust if needed)
	 * ========================= */
	const CPT_POST_TYPE          = 'quiz'; // Your CPT slug (confirmed)
	const CPT_META_EXAM_ID       = '_ika_watupro_exam_id';
	const CPT_META_IMPORT_HASH   = '_ika_watupro_import_hash';

	// Your table names (from your SQL dump)
	private static function t_master()   { return 'wp_2cd0c0f1b0_watupro_master'; }
	private static function t_question() { return 'wp_2cd0c0f1b0_watupro_question'; }
	private static function t_answer()   { return 'wp_2cd0c0f1b0_watupro_answer'; }

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );

		// Import handler
		add_action( 'admin_post_ika_watupro_import', [ __CLASS__, 'handle_import' ] );

		// Export handler
		add_action( 'admin_post_ika_watupro_export', [ __CLASS__, 'handle_export' ] );
	}

	public static function add_menu() {
		add_submenu_page(
			'ika-gamification', // parent slug (adjust ONLY if your parent menu slug differs)
			'WatuPRO Importer',
			'WatuPRO Importer',
			'manage_options',
			'ika-watupro-importer',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$last = get_transient( 'ika_watupro_import_last_result' );
		delete_transient( 'ika_watupro_import_last_result' );

		$export_last = get_transient( 'ika_watupro_export_last_result' );
		delete_transient( 'ika_watupro_export_last_result' );

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

			<hr />

			<h2>Import JSON</h2>
			<p><strong>Tip:</strong> Run Dry Run first. Then Import. If you enable “Replace existing,” it deletes existing questions/answers for that quiz (matched by exact quiz name).</p>

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
						<th scope="row">Replace existing?</th>
						<td>
							<label>
								<input type="checkbox" name="replace_existing" value="1" />
								If quiz exists (by exact name), delete its existing questions/answers and re-import
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">CPT integration</th>
						<td>
							<label>
								<input type="checkbox" name="cpt_enable" value="1" checked />
								Create/Update a Quiz CPT post and link it (meta: <code><?php echo esc_html(self::CPT_META_EXAM_ID); ?></code>)
							</label>
							<p class="description">
								CPT slug configured as <code><?php echo esc_html(self::CPT_POST_TYPE); ?></code>.
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Run Import' ); ?>
			</form>

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
	 * IMPORT
	 * ========================= */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

		if ( empty( $_POST['ika_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ika_nonce'] ) ), 'ika_watupro_import' ) ) {
			wp_die( 'Invalid nonce.' );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'dry';
		$is_dry = ( $mode !== 'import' );

		$replace_existing = ! empty( $_POST['replace_existing'] );
		$cpt_enable = ! empty( $_POST['cpt_enable'] );

		$log = [];

		try {
			if ( empty( $_FILES['ika_json_file']['tmp_name'] ) ) throw new Exception( 'No file uploaded.' );

			$raw = file_get_contents( $_FILES['ika_json_file']['tmp_name'] );
			if ( ! $raw ) throw new Exception( 'Could not read uploaded file.' );

			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) throw new Exception( 'Invalid JSON.' );

			// Normalize common wrappers/case so import is resilient
			if ( isset($data['data']) && is_array($data['data']) )         $data = $data['data'];
			if ( isset($data['payload']) && is_array($data['payload']) )   $data = $data['payload'];
			if ( ! isset($data['questions']) && isset($data['Questions']) ) $data['questions'] = $data['Questions'];
			if ( ! isset($data['quiz']) && isset($data['Quiz']) )           $data['quiz'] = $data['Quiz'];

			self::validate_payload( $data );

			/**
			 * IMPORTANT: Auto-clear reuse_questions_from whenever questions are provided.
			 * If you're importing actual questions/answers, this quiz should NOT remain a "reuse" shell.
			 */
			$has_questions = is_array($data['questions']) && count($data['questions']) > 0;
			if ( $has_questions ) {
				$data['quiz']['reuse_questions_from'] = '';
				if ( ! isset($data['quiz']['settings']) || ! is_array($data['quiz']['settings']) ) {
					$data['quiz']['settings'] = [];
				}
				$data['quiz']['settings']['reuse_questions_from'] = '';
			}

			global $wpdb;

			if ( ! $is_dry ) $wpdb->query( 'START TRANSACTION' );

			$quiz = $data['quiz'];
			$quiz_name = trim( (string) $quiz['name'] );
			$log[] = 'Quiz name: ' . $quiz_name;

			$quiz_id = self::find_quiz_id_by_name( $quiz_name );

			if ( $quiz_id ) {
				$log[] = "Existing quiz found (ID={$quiz_id}).";
				if ( $replace_existing ) {
					$log[] = "Replace enabled → delete existing questions/answers for quiz ID={$quiz_id}.";
					if ( ! $is_dry ) self::delete_quiz_children( (int) $quiz_id, $log );
					else $log[] = '[Dry Run] Would delete existing questions/answers.';
				}

				if ( ! $is_dry ) self::update_master( (int) $quiz_id, $quiz, $log );
				else $log[] = '[Dry Run] Would update quiz master row.';
			} else {
				$log[] = "No existing quiz found. Will create new quiz.";
				if ( ! $is_dry ) $quiz_id = self::insert_master( $quiz, $log );
				else {
					$quiz_id = 0;
					$log[] = '[Dry Run] Would insert new quiz master row.';
				}
			}

			// Import questions/answers
			$questions = is_array($data['questions']) ? $data['questions'] : [];
			$q_count = 0;
			$a_count = 0;

			foreach ( $questions as $i => $q ) {
				$q_count++;

				$sort_order = isset( $q['sort_order'] ) ? (int) $q['sort_order'] : ($i + 1);

				// Safe mapping for answer_type (+ special-case truefalse)
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

			// CPT integration: create/update post and link exam_id
			if ( $cpt_enable ) {
				if ( $is_dry ) {
					$log[] = '[Dry Run] Would create/update CPT post and link exam ID.';
				} else {
					$post_id = self::upsert_quiz_cpt_post( (int) $quiz_id, $quiz, $raw, $log );
					if ( $post_id ) {
						$log[] = "CPT linked: post_id={$post_id}, meta(" . self::CPT_META_EXAM_ID . ")={$quiz_id}.";
					}
				}
			}

			if ( ! $is_dry ) $wpdb->query( 'COMMIT' );

			$msg = $is_dry
				? "Dry run complete. Would import {$q_count} questions and {$a_count} answers."
				: "Import complete. Imported {$q_count} questions and {$a_count} answers.";

			set_transient( 'ika_watupro_import_last_result', [
				'ok'      => true,
				'message' => $msg,
				'log'     => $log,
			], 60 );

		} catch ( Exception $e ) {
			global $wpdb;
			if ( isset( $mode ) && $mode === 'import' ) $wpdb->query( 'ROLLBACK' );

			set_transient( 'ika_watupro_import_last_result', [
				'ok'      => false,
				'message' => $e->getMessage(),
				'log'     => $log,
			], 60 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ika-watupro-importer' ) );
		exit;
	}

	private static function validate_payload( array $data ) {
		if ( empty( $data['quiz'] ) || ! is_array( $data['quiz'] ) ) {
			throw new Exception( 'Missing "quiz" object.' );
		}
		if ( empty( $data['quiz']['name'] ) ) {
			throw new Exception( 'Missing quiz.name.' );
		}

		// Allow questions: [] (do not treat empty array as missing)
		if ( ! array_key_exists( 'questions', $data ) || ! is_array( $data['questions'] ) ) {
			$keys = implode(', ', array_keys($data));
			throw new Exception( 'Missing "questions" array. Top-level keys found: ' . $keys );
		}

		// If questions exist, validate their shape
		foreach ( $data['questions'] as $idx => $q ) {
			if ( empty( $q['question_html'] ) ) {
				throw new Exception( "Question #".($idx+1)." missing question_html." );
			}
			if ( empty( $q['answer_type'] ) ) {
				throw new Exception( "Question #".($idx+1)." missing answer_type." );
			}
			if ( empty( $q['answers'] ) || ! is_array( $q['answers'] ) ) {
				throw new Exception( "Question #".($idx+1)." missing answers array." );
			}
			foreach ( $q['answers'] as $a ) {
				if ( empty( $a['answer_html'] ) ) {
					throw new Exception( "A question has an answer missing answer_html." );
				}
			}
		}
	}

	/** Safe mapping layer */
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

		// Special-case: true/false convenience
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
			'name'                => sanitize_text_field( (string) $quiz['name'] ),
			'description'         => $desc,
			'final_screen'        => $final,
			'added_on'            => current_time( 'mysql' ),

			'is_active'           => isset($settings['is_active']) ? (int)$settings['is_active'] : 1,
			'require_login'       => isset($settings['require_login']) ? (int)$settings['require_login'] : 0,
			'take_again'          => isset($settings['take_again']) ? (int)$settings['take_again'] : 0,
			'randomize_questions' => isset($settings['randomize_questions']) ? (int)$settings['randomize_questions'] : 0,
			'single_page'         => isset($settings['single_page']) ? (int)$settings['single_page'] : 0,
			'login_mode'          => isset($settings['login_mode']) ? sanitize_text_field((string)$settings['login_mode']) : 'open',
			'mode'                => isset($settings['mode']) ? sanitize_text_field((string)$settings['mode']) : 'live',
		];

		// Support both: quiz.reuse_questions_from (top-level) and quiz.settings.reuse_questions_from
		if ( isset($quiz['reuse_questions_from']) ) {
			$row['reuse_questions_from'] = sanitize_text_field( (string) $quiz['reuse_questions_from'] );
		} elseif ( isset($settings['reuse_questions_from']) ) {
			$row['reuse_questions_from'] = sanitize_text_field( (string) $settings['reuse_questions_from'] );
		}

		if ( ! $wpdb->insert( $table, $row ) ) {
			throw new Exception( 'Insert quiz failed: ' . $wpdb->last_error );
		}

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

		foreach ( [ 'is_active','require_login','take_again','randomize_questions','single_page' ] as $k ) {
			if ( array_key_exists( $k, $settings ) ) $update[$k] = (int) $settings[$k];
		}
		if ( array_key_exists( 'login_mode', $settings ) ) $update['login_mode'] = sanitize_text_field((string)$settings['login_mode']);
		if ( array_key_exists( 'mode', $settings ) ) $update['mode'] = sanitize_text_field((string)$settings['mode']);

		// Support both: quiz.reuse_questions_from (top-level) and quiz.settings.reuse_questions_from
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

		if ( ! $wpdb->insert( $table, $row ) ) {
			throw new Exception( 'Insert question failed: ' . $wpdb->last_error );
		}

		$qid = (int) $wpdb->insert_id;
		$log[] = "Inserted question ID={$qid} (quiz ID={$quiz_id}, sort_order={$sort_order}).";
		return $qid;
	}

	private static function insert_answer( int $question_id, array $ans, int $sort_order, array &$log ) {
		global $wpdb;
		$table = self::t_answer();

		$correct = 0;
		if ( isset($ans['correct']) ) $correct = (int) $ans['correct'] ? 1 : 0;
		if ( isset($ans['is_correct']) ) $correct = (int) $ans['is_correct'] ? 1 : 0;

		$row = [
			'question_id'     => $question_id,
			'answer'          => wp_kses_post( (string) $ans['answer_html'] ),
			'correct'         => $correct ? '1' : '0',
			'point'           => isset($ans['point']) ? (float) $ans['point'] : 0.00,
			'sort_order'      => $sort_order,
			'explanation'     => isset($ans['explanation_html']) ? wp_kses_post( (string) $ans['explanation_html'] ) : null,
			'grade_id'        => '0',
			'chk_group'       => 0,
			'is_checked'      => 0,
			'accept_freetext' => 0,
		];

		if ( ! $wpdb->insert( $table, $row ) ) {
			throw new Exception( 'Insert answer failed: ' . $wpdb->last_error );
		}

		$aid = (int) $wpdb->insert_id;
		$log[] = "Inserted answer ID={$aid} (question ID={$question_id}, correct={$row['correct']}, sort_order={$sort_order}).";
		return $aid;
	}

	/** CPT upsert: create/update a WP Quiz post linked to exam ID */
	private static function upsert_quiz_cpt_post( int $exam_id, array $quiz, string $raw_json, array &$log ) : int {
		$post_type = self::CPT_POST_TYPE;

		if ( ! post_type_exists( $post_type ) ) {
			$log[] = "CPT post_type '{$post_type}' does not exist. Skipping CPT creation.";
			return 0;
		}

		$title = sanitize_text_field( (string) $quiz['name'] );
		$content = isset( $quiz['description_html'] ) ? wp_kses_post( (string) $quiz['description_html'] ) : '';

		$existing = get_posts([
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => self::CPT_META_EXAM_ID,
			'meta_value'     => $exam_id,
			'fields'         => 'ids',
		]);

		$postarr = [
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		];

		if ( $existing ) {
			$postarr['ID'] = (int) $existing[0];
			$post_id = wp_update_post( $postarr, true );
			if ( is_wp_error( $post_id ) ) throw new Exception( 'CPT update failed: ' . $post_id->get_error_message() );
			$log[] = "Updated CPT post ID={$post_id}.";
		} else {
			$post_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $post_id ) ) throw new Exception( 'CPT insert failed: ' . $post_id->get_error_message() );
			$log[] = "Created CPT post ID={$post_id}.";
		}

		update_post_meta( $post_id, self::CPT_META_EXAM_ID, $exam_id );

		$hash = hash( 'sha256', $raw_json );
		update_post_meta( $post_id, self::CPT_META_IMPORT_HASH, $hash );

		return (int) $post_id;
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

		// Reuse-aware export: if reuse_questions_from is set, export questions from that exam_id.
		$source_quiz_id = $quiz_id;
		$reuse_raw = isset($master['reuse_questions_from']) ? trim((string)$master['reuse_questions_from']) : '';
		if ( $reuse_raw !== '' && $reuse_raw !== '0' ) {
			if ( preg_match( '/^\d+$/', $reuse_raw ) ) {
				$source_quiz_id = (int) $reuse_raw;
			} else if ( preg_match( '/\d+/', $reuse_raw, $m ) ) {
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
		} else if ( $source_quiz_id !== $quiz_id ) {
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

		return [
			'quiz' => [
				'name'                   => $master['name'],
				'description_html'       => $master['description'],
				'final_screen_html'      => $master['final_screen'],
				'reuse_questions_from'   => $master['reuse_questions_from'],
				'_export_source_exam_id' => $source_quiz_id,
				'settings' => [
					'is_active'           => (int) $master['is_active'],
					'require_login'       => (int) $master['require_login'],
					'take_again'          => (int) $master['take_again'],
					'randomize_questions' => (int) $master['randomize_questions'],
					'single_page'         => (int) $master['single_page'],
					'login_mode'          => (string) $master['login_mode'],
					'mode'                => (string) $master['mode'],
				],
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
