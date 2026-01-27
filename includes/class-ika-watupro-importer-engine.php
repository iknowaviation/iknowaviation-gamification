<?php
/**
 * Compatibility shim.
 *
 * Some modules expect the importer engine at:
 *   includes/class-ika-watupro-importer-engine.php
 *
 * The canonical file lives at:
 *   includes/tools/class-ika-watupro-importer-engine.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$ika_engine_file = plugin_dir_path( __FILE__ ) . 'tools/class-ika-watupro-importer-engine.php';

if ( file_exists( $ika_engine_file ) ) {
    require_once $ika_engine_file;
} else {
    // Fail softly: prevent fatal errors in unrelated requests.
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[IKA] Missing importer engine file: ' . $ika_engine_file );
    }
}
