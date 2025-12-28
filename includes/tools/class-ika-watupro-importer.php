<?php
/**
 * IKA WatuPRO Quiz Importer (Refactored Loader)
 * - Loads Engine, Templates, and Admin UI classes.
 * - Self-initializes on include (matches previous behavior in your plugin bootstrap).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-ika-watupro-importer-engine.php';
require_once __DIR__ . '/class-ika-watupro-importer-templates.php';
require_once __DIR__ . '/class-ika-watupro-importer-admin.php';

class IKA_WatuPRO_Importer {
	public static function init() {
		// Admin UI + handlers (menus, screens, admin-post endpoints)
		IKA_WatuPRO_Importer_Admin::init();
	}
}

// Auto-init to keep compatibility with plugin bootstrap which only requires this file.
if ( is_admin() ) {
	IKA_WatuPRO_Importer::init();
}
