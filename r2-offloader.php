<?php
/**
 * Plugin Name: R2 Offloader
 * Plugin URI: https://github.com/musthari/r2-offloader
 * Description: Production-ready WordPress media offloader for Cloudflare R2 with WebP conversion, auto-resize, bulk sync, smart cleanup, async queue, retry handling, and failsafe rollback.
 * Version: 2.0.0
 * Author: Copilot / musthari
 * Text Domain: r2-offloader
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.6
 * License: GPL v3 or later
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('R2_OFFLOADER_VERSION')) {
    define('R2_OFFLOADER_VERSION', '2.0.0');
}

define('R2_OFFLOADER_FILE', __FILE__);
define('R2_OFFLOADER_DIR', plugin_dir_path(__FILE__));
define('R2_OFFLOADER_URL', plugin_dir_url(__FILE__));
define('R2_OFFLOADER_BASENAME', plugin_basename(__FILE__));

require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader.php';

R2_Offloader::instance();

register_activation_hook(__FILE__, 'r2_offloader_activate');
register_deactivation_hook(__FILE__, 'r2_offloader_deactivate');

function r2_offloader_activate() {
    do_action('r2_offloader_activate');
}

function r2_offloader_deactivate() {
    do_action('r2_offloader_deactivate');
}
