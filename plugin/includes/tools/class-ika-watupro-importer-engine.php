<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class IKA_WatuPRO_Importer_Engine {


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

	// Templates (stored as option array)
	const OPT_TEMPLATES          = 'ika_watupro_import_templates_v1';
	const OPT_DEFAULT_TEMPLATE   = 'ika_watupro_import_default_template_v1';

	// Table names (prefix-safe)

	public static function t_master()   { global $wpdb; return $wpdb->prefix . 'watupro_master'; }

	public static function t_question() { global $wpdb; return $wpdb->prefix . 'watupro_question'; }

	public static function t_answer()   { global $wpdb; return $wpdb->prefix . 'watupro_answer'; }

	public static function csv_to_terms( string $csv ) : array {
			$csv = trim($csv);
			if ( $csv === '' ) return [];
			$parts = array_map( 'trim', explode(',', $csv ) );
			$parts = array_filter( $parts, function($v){ return $v !== ''; } );
			return array_values( $parts );
		}

	public static function is_boolish_master_key( string $key ) : bool {
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

	public static function master_settings_whitelist() : array {
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

	public static function parse_variants_textarea( string $raw ) : array {
			$raw = trim($raw);
			if ( $raw === '' ) return [];
	
			// Try JSON object first: {"A":"...","B":"..."}
			$decoded = json_decode( $raw, true );
			if ( is_array($decoded) ) {
				$out = [];
				foreach ( $decoded as $k => $v ) {
					$key = sanitize_key( (string) $k );
					if ( $key === '' ) continue;
					$out[ strtoupper($key) ] = (string) $v;
				}
				return $out;
			}
	
			// Fallback delimiter format:
			// A: <html...>
			// --- 
			// B: <html...>
			$parts = preg_split( "/\n-{3,}\n/", $raw );
			$out = [];
			foreach ( $parts as $part ) {
				$part = trim($part);
				if ( $part === '' ) continue;
				if ( preg_match( '/^([A-Za-z0-9_]+)\s*:\s*(.*)$/s', $part, $m ) ) {
					$key = strtoupper( sanitize_key( $m[1] ) );
					$out[$key] = (string) $m[2];
				} else {
					// If no key, treat as A.
					$out['A'] = $part;
				}
			}
			return $out;
		}

	public static function normalize_replace_mode( string $raw_mode, bool $legacy_replace_existing ) : string {
			$raw_mode = strtolower( trim( $raw_mode ) );
	
			$allowed = [ 'auto','none','all','settings','questions','tags','cpt' ];
			if ( ! in_array( $raw_mode, $allowed, true ) ) $raw_mode = 'auto';
	
			if ( $raw_mode === 'auto' ) {
				return $legacy_replace_existing ? 'all' : 'none';
			}
	
			return $raw_mode;
		}

	public static function replace_plan( string $mode, bool $cpt_enable ) : array {
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

	public static function validate_payload_by_mode( array $data, string $replace_mode ) : void {
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

	public static function is_chatgpt_schema( array $data ) : bool {
			return isset($data['version'], $data['quizzes']) && is_array($data['quizzes']);
		}

	public static function expand_chatgpt_schema_to_payloads( array $data, array &$log ) : array {
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
					'description_html'     => ( isset($qz['description_html']) && trim((string)$qz['description_html']) !== '' ) ? (string) $qz['description_html'] : (string) ( $defaults['description_html'] ?? '' ),
					'final_screen_html'    => ( isset($qz['final_screen_html']) && trim((string)$qz['final_screen_html']) !== '' ) ? (string) $qz['final_screen_html'] : (string) ( $defaults['final_screen_html'] ?? '' ),
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

	public static function import_one_payload( array $data, string $raw_one, array $plan, bool $is_dry, string $replace_mode, bool $cpt_enable, array &$log ) : array {
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

	public static function apply_quiz_tags( int $post_id, array $tags, array &$log ) : void {
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

	public static function validate_payload( array $data ) {
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
	
				// Required: exactly one explanation per question (stored in watupro_question.explain_answer)
				if ( empty( $q['explain_answer_html'] ) ) {
					throw new Exception( "Question #".($idx+1)." missing explain_answer_html (required)." );
				}
	
				foreach ( $q['answers'] as $aidx => $a ) {
					if ( empty( $a['answer_html'] ) ) throw new Exception( "Question #".($idx+1)." answer #".($aidx+1)." missing answer_html." );
					// Disallow answer-level explanations (we use question-level only)
					if ( isset($a['explanation_html']) && trim((string)$a['explanation_html']) !== '' ) {
						throw new Exception( "Question #".($idx+1)." answer #".($aidx+1)." includes explanation_html. Use explain_answer_html at the question level only." );
					}
				}
			}
		}

	public static function apply_safe_mappings_to_question( array $q, array &$log ) : array {
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

	public static function find_quiz_id_by_name( string $name ) {
			global $wpdb;
			$table = self::t_master();
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$table} WHERE name = %s LIMIT 1", $name ) );
		}

	public static function insert_master( array $quiz, array &$log ) {
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

	public static function update_master( int $quiz_id, array $quiz, array &$log ) {
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

	public static function settings_to_master_row_whitelist( array $settings ) : array {
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

	public static function delete_quiz_children( int $quiz_id, array &$log ) {
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

	public static function insert_question( int $quiz_id, array $q, int $sort_order, array &$log ) {
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

	public static function insert_answer( int $question_id, array $ans, int $sort_order, array &$log ) {
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

	public static function upsert_quiz_cpt_post( int $exam_id, array $quiz, string $raw_json, array &$log ) : int {
			global $wpdb;
	
			$post_type = self::CPT_POST_TYPE;
	
			if ( ! post_type_exists( $post_type ) ) {
				$log[] = "CPT sync skipped: post_type '{$post_type}' does not exist.";
				return 0;
			}
	
			$title   = sanitize_text_field( (string) ( $quiz['name'] ?? '' ) );
			$content = '[watupro ' . (int) $exam_id . ']';
			$status  = 'publish'; // Requested: create posts as publish.
	
			// Find existing CPT post by exam_id meta (direct DB to avoid hooks)
			$pm = $wpdb->postmeta;
			$pp = $wpdb->posts;
	
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID
					 FROM {$pp} p
					 INNER JOIN {$pm} m ON m.post_id = p.ID
					 WHERE p.post_type = %s
					   AND m.meta_key = %s
					   AND m.meta_value = %s
					 ORDER BY p.ID DESC
					 LIMIT 1",
					$post_type,
					self::CPT_META_EXAM_ID,
					(string) $exam_id
				)
			);
	
			$now_local = current_time( 'mysql' );
			$now_gmt   = current_time( 'mysql', 1 );
			$author_id = get_current_user_id();
			if ( ! $author_id ) $author_id = 1;
	
			if ( $existing_id > 0 ) {
				// Update wp_posts directly (bypass wp_update_post hooks)
				$ok = $wpdb->update(
					$pp,
					[
						'post_title'        => $title,
						'post_content'      => $content,
						'post_status'       => $status,
						'post_modified'     => $now_local,
						'post_modified_gmt' => $now_gmt,
					],
					[ 'ID' => $existing_id ],
					[ '%s','%s','%s','%s','%s' ],
					[ '%d' ]
				);
	
				if ( $ok === false ) {
					throw new Exception( 'CPT DB update failed: ' . $wpdb->last_error );
				}
	
				$post_id = $existing_id;
				$log[] = "Updated CPT post ID={$post_id} via direct DB (forced content: [watupro {$exam_id}]).";
			} else {
				// Insert wp_posts directly (bypass wp_insert_post hooks)
				$ins = $wpdb->insert(
					$pp,
					[
						'post_author'       => $author_id,
						'post_date'         => $now_local,
						'post_date_gmt'     => $now_gmt,
						'post_content'      => $content,
						'post_title'        => $title,
						'post_status'       => $status,
						'comment_status'    => 'closed',
						'ping_status'       => 'closed',
						'post_name'         => '', // optional: leave blank; WP will generate if you edit later
						'post_modified'     => $now_local,
						'post_modified_gmt' => $now_gmt,
						'post_type'         => $post_type,
					],
					[ '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ]
				);
	
				if ( $ins === false ) {
					throw new Exception( 'CPT DB insert failed: ' . $wpdb->last_error );
				}
	
				$post_id = (int) $wpdb->insert_id;
	
				// Set a reasonable GUID (not critical, but helps consistency).
				$guid = home_url( '/?post_type=' . $post_type . '&p=' . $post_id );
				$wpdb->update(
					$pp,
					[ 'guid' => $guid ],
					[ 'ID' => $post_id ],
					[ '%s' ],
					[ '%d' ]
				);
	
				$log[] = "Created CPT post ID={$post_id} via direct DB (forced content: [watupro {$exam_id}]).";
			}
	
			// Upsert post meta directly (avoid update_post_meta hooks)
			self::upsert_postmeta_db( $post_id, self::CPT_META_EXAM_ID, (string) $exam_id );
			self::upsert_postmeta_db( $post_id, self::CPT_META_IMPORT_HASH, hash( 'sha256', $raw_json ) );
	
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $post_id );
			}
	
			return (int) $post_id;
		}

	public static function upsert_postmeta_db( int $post_id, string $meta_key, string $meta_value ) : void {
			global $wpdb;
	
			$pm = $wpdb->postmeta;
	
			$meta_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$pm} WHERE post_id = %d AND meta_key = %s LIMIT 1",
					$post_id,
					$meta_key
				)
			);
	
			if ( $meta_id > 0 ) {
				$ok = $wpdb->update(
					$pm,
					[ 'meta_value' => $meta_value ],
					[ 'meta_id' => $meta_id ],
					[ '%s' ],
					[ '%d' ]
				);
				if ( $ok === false ) {
					throw new Exception( 'Postmeta DB update failed (' . $meta_key . '): ' . $wpdb->last_error );
				}
			} else {
				$ok = $wpdb->insert(
					$pm,
					[ 'post_id' => $post_id, 'meta_key' => $meta_key, 'meta_value' => $meta_value ],
					[ '%d','%s','%s' ]
				);
				if ( $ok === false ) {
					throw new Exception( 'Postmeta DB insert failed (' . $meta_key . '): ' . $wpdb->last_error );
				}
			}
		}

	public static function cpt_health_check( int $post_id, int $exam_id, array &$log ) : void {
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

	public static function export_quiz_payload( int $quiz_id ) : array {
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

	public static function list_quizzes() : array {
			global $wpdb;
			$tm = self::t_master();
			return $wpdb->get_results( "SELECT ID, name FROM {$tm} ORDER BY ID DESC LIMIT 300", ARRAY_A ) ?: [];
		}

		/**
		 * Fetch quiz-level defaults from an existing WatuPRO quiz.
		 *
		 * This is intentionally lightweight: it pulls the master row and maps
		 * whitelisted settings keys into a stable array used by both the Import
		 * Review UI and the Template system.
		 */
		public static function get_master_defaults_for_builder( int $quiz_id ) : array {
			global $wpdb;
			$quiz_id = (int) $quiz_id;
			if ( $quiz_id <= 0 ) {
				return [ 'settings' => [], 'description_html' => '', 'final_screen_html' => '' ];
			}

			$tm = self::t_master();
			$master = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tm} WHERE ID = %d", $quiz_id ), ARRAY_A );
			if ( ! is_array( $master ) ) {
				return [ 'settings' => [], 'description_html' => '', 'final_screen_html' => '' ];
			}

			$settings = [];
			foreach ( self::master_settings_whitelist() as $k => $_label ) {
				if ( array_key_exists( $k, $master ) ) {
					$settings[ $k ] = self::is_boolish_master_key( $k ) ? (int) $master[ $k ] : (string) $master[ $k ];
				}
			}

			return [
				'settings'         => $settings,
				'description_html' => (string) ( $master['description'] ?? '' ),
				'final_screen_html'=> (string) ( $master['final_screen'] ?? '' ),
			];
		}
		
		/**
		 * Admin notice helper (transient + option fallback).
		 * Kept as a stable API because admin/controllers call it.
		 */
		public static function set_notice( bool $success, string $message ): void {
			$payload = [
				'ts'      => time(),
				'success' => $success ? 1 : 0,
				'message' => $message,
			];

			// Primary: transient (short-lived, auto-expires)
			set_transient( 'ika_watupro_importer_notice', $payload, 60 );

			// Fallback: option (in case transient fails / object cache oddities)
			update_option( 'ika_watupro_importer_notice_fallback', $payload, false );
		}

}
