<?php
/**
 * IKA WatuPRO Quiz Importer (Refactored Loader)
 * - Loads Engine, Templates, and Admin UI classes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-ika-watupro-importer-engine.php';
require_once __DIR__ . '/class-ika-watupro-importer-templates.php';
require_once __DIR__ . '/class-ika-watupro-importer-admin.php';

class IKA_WatuPRO_Importer {
	public static function init() {
		IKA_WatuPRO_Importer_Admin::init();
	}
}
