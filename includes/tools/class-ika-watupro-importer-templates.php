<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class IKA_WatuPRO_Importer_Templates {

	public static function templates_get_all() : array {
			$tpls = get_option( IKA_WatuPRO_Importer_Engine::OPT_TEMPLATES, [] );
			return is_array( $tpls ) ? $tpls : [];
		}

	public static function templates_save_all( array $tpls ) : void {
			update_option( IKA_WatuPRO_Importer_Engine::OPT_TEMPLATES, $tpls, false );
		}


		public static function templates_get( string $id ) : ?array {
			$tpls = self::templates_get_all();
			if ( $id === '' || ! isset( $tpls[ $id ] ) || ! is_array( $tpls[ $id ] ) ) return null;
			return $tpls[ $id ];
		}

		public static function templates_upsert( string $id, array $template ) : void {
			$tpls = self::templates_get_all();
			$template['id'] = $id;
			$template['updated_at'] = time();
			$tpls[ $id ] = $template;
			self::templates_save_all( $tpls );
		}

		public static function templates_delete( string $id ) : bool {
			$tpls = self::templates_get_all();
			if ( $id === '' || ! isset( $tpls[ $id ] ) ) return false;
			unset( $tpls[ $id ] );
			self::templates_save_all( $tpls );
			$default = self::templates_get_default_id();
			if ( $default === $id ) self::templates_set_default_id( '' );
			return true;
		}

		public static function templates_duplicate( string $source_id, string $new_id, string $new_name = '' ) : bool {
			$tpls = self::templates_get_all();
			if ( ! isset( $tpls[ $source_id ] ) || ! is_array( $tpls[ $source_id ] ) ) return false;
			if ( $new_id === '' ) return false;
			$copy = $tpls[ $source_id ];
			$copy['id'] = $new_id;
			$copy['name'] = $new_name !== '' ? $new_name : ( (string)($copy['name'] ?? $source_id) . ' (Copy)' );
			$copy['updated_at'] = time();
			$tpls[ $new_id ] = $copy;
			self::templates_save_all( $tpls );
			return true;
		}


	public static function templates_get_default_id() : string {
			$default = (string) get_option( IKA_WatuPRO_Importer_Engine::OPT_DEFAULT_TEMPLATE, '' );
			if ( $default !== '' ) return $default;
	
			// If no default exists yet, lazily create one from quiz ID 6 (best-effort).
			$created = self::templates_ensure_default_from_quiz( 6 );
			return $created ?: '';
		}

	public static function templates_set_default_id( string $id ) : void {
			update_option( IKA_WatuPRO_Importer_Engine::OPT_DEFAULT_TEMPLATE, $id, false );
		}

	public static function templates_ensure_default_from_quiz( int $quiz_id = 6 ) : string {
			$tpls = self::templates_get_all();
			if ( isset( $tpls['default'] ) ) return 'default';
	
			$tpls['default'] = self::template_capture_from_quiz( 'default', 'Default (captured)', $quiz_id );
			self::templates_save_all( $tpls );
			self::templates_set_default_id( 'default' );
			return 'default';
		}

	/**
	 * Refresh (overwrite) the built-in "default" template from a given WatuPRO quiz ID.
	 * Returns true on success.
	 */
	public static function templates_refresh_default_from_quiz( int $quiz_id = 6 ) : bool {
		if ( $quiz_id <= 0 ) return false;
		$tpls = self::templates_get_all();
		$tpls['default'] = self::template_capture_from_quiz( 'default', 'Default (captured)', $quiz_id );
		self::templates_save_all( $tpls );
		self::templates_set_default_id( 'default' );
		return true;
	}

	public static function template_capture_from_quiz( string $id, string $name, int $quiz_id ) : array {
			$defaults = IKA_WatuPRO_Importer_Engine::get_master_defaults_for_builder( $quiz_id );
			$settings = is_array( $defaults['settings'] ?? null ) ? $defaults['settings'] : [];
	
			return [
				'id'                  => $id,
				'name'                => $name,
				'source_quiz_id'       => $quiz_id,
				'settings'             => $settings,
				'description_variants' => [
					'A' => (string) ( $defaults['description_html'] ?? '' ),
				],
				'final_screen_html'    => (string) ( $defaults['final_screen_html'] ?? '' ),
				'updated_at'           => time(),
			];
		}

	public static function template_apply_to_quiz_payload( array $quiz, array $template, string $variant = 'A', bool $force_template = false ) : array {
			// Merge settings: template defaults first, JSON settings override if present.
			$tpl_settings  = ( isset($template['settings']) && is_array($template['settings']) ) ? $template['settings'] : [];
			$json_settings = ( isset($quiz['settings']) && is_array($quiz['settings']) ) ? $quiz['settings'] : [];
			$merged_settings = array_merge( $tpl_settings, $json_settings );
	
			// Force desired default
			$merged_settings['take_again'] = 1;
	
			$quiz['settings'] = $merged_settings;
	
			// Description / final screen: pick variant → allow JSON override unless force_template.
			$variants = ( isset($template['description_variants']) && is_array($template['description_variants']) ) ? $template['description_variants'] : [];
			$tpl_desc = (string) ( $variants[ $variant ] ?? ( $variants['A'] ?? '' ) );
			$tpl_final = (string) ( $template['final_screen_html'] ?? '' );
	
			if ( $force_template || ! isset($quiz['description_html']) || trim((string)$quiz['description_html']) === '' ) {
				$quiz['description_html'] = $tpl_desc;
			}
			if ( $force_template || ! isset($quiz['final_screen_html']) || trim((string)$quiz['final_screen_html']) === '' ) {
				$quiz['final_screen_html'] = $tpl_final;
			}
	
			return $quiz;
		}
}
