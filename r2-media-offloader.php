<?php
/**
 * Plugin Name: Cloudflare R2 Media Offloader
 * Description: Offload media WordPress ke Cloudflare R2, penyajian URL CDN, cleanup media lokal, dan WP-CLI support.
 * Version:     2.3.0
 * Author:      Mus
 * License:     GPL v2 or later
 * Text Domain: r2-media-offloader
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'R2_OFFLOADER_VERSION', '2.3.0' );
define( 'R2_OFFLOADER_PATH', plugin_dir_path( __FILE__ ) );
define( 'R2_OFFLOADER_URL', plugin_dir_url( __FILE__ ) );

// Load Komponen Terpisah
require_once R2_OFFLOADER_PATH . 'includes/class-r2-core.php';
require_once R2_OFFLOADER_PATH . 'includes/class-r2-failsafe.php';
require_once R2_OFFLOADER_PATH . 'includes/class-r2-guides.php';
require_once R2_OFFLOADER_PATH . 'includes/class-r2-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once R2_OFFLOADER_PATH . 'includes/class-r2-cli.php';
}

// Inisialisasi Plugin Utama
function r2_media_offloader_init() {
    R2_Core::get_instance();
    R2_Failsafe::get_instance();
    R2_Admin::get_instance();
}
add_action( 'plugins_loaded', 'r2_media_offloader_init' );