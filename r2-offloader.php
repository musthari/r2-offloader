<?php
/*
Plugin Name: R2 Offloader
Plugin URI: https://github.com/musthari/r2-offloader
Description: Offload gambar media WordPress ke Cloudflare R2, convert otomatis ke WebP, resize untuk lebar maksimal 1000px, serta menambahkan failsafe rollback lokal.
Version: 1.0.0
Author: Musthari
Text Domain: r2-offloader
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'R2OFFLOADER_VERSION', '1.0.0' );
define( 'R2OFFLOADER_PATH', plugin_dir_path( __FILE__ ) );
define( 'R2OFFLOADER_URL', plugin_dir_url( __FILE__ ) );
define( 'R2OFFLOADER_BASENAME', plugin_basename( __FILE__ ) );

require_once R2OFFLOADER_PATH . 'includes/class-r2-settings.php';
require_once R2OFFLOADER_PATH . 'includes/class-r2-api.php';
require_once R2OFFLOADER_PATH . 'includes/class-r2-media-processor.php';
require_once R2OFFLOADER_PATH . 'includes/class-r2-admin.php';
require_once R2OFFLOADER_PATH . 'includes/class-r2-ajax.php';
require_once R2OFFLOADER_PATH . 'includes/class-r2-recovery.php';
require_once R2OFFLOADER_PATH . 'includes/class-r2-cli.php';

function r2offloader() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new R2_Offloader();
	}

	return $instance;
}

final class R2_Offloader {
	private $settings;
	private $api;
	private $media;
	private $admin;
	private $ajax;
	private $recovery;
	private $cli;

	public function __construct() {
		$this->settings = new R2_Offloader_Settings();
		$this->api      = new R2_Offloader_R2_API( $this->settings );
		$this->media    = new R2_Offloader_Media_Processor( $this->settings );
		$this->recovery = new R2_Offloader_Recovery( $this->settings, $this->media );
		$this->admin    = new R2_Offloader_Admin( $this->settings );
		$this->ajax     = new R2_Offloader_AJAX( $this->settings, $this->api, $this->media, $this->recovery );
		$this->cli      = new R2_Offloader_CLI( $this->settings, $this->media, $this->api, $this->recovery );
	}

	public function init() {
		$this->register_hooks();
		$this->admin->init();
		$this->ajax->init();
		$this->cli->init();
	}

	public function get_settings() {
		return $this->settings;
	}

	public function get_api() {
		return $this->api;
	}

	public function get_media() {
		return $this->media;
	}

	public function get_recovery() {
		return $this->recovery;
	}

	private function register_hooks() {
		add_filter( 'big_image_size_threshold', function () {
			return PHP_INT_MAX;
		}, 999 );

		add_filter( 'intermediate_image_sizes_advanced', function ( $sizes ) {
			if ( empty( $sizes ) ) {
				return $sizes;
			}

			$blocked = array( '1536x1536', '2048x2048' );
			foreach ( $blocked as $name ) {
				if ( isset( $sizes[ $name ] ) ) {
					unset( $sizes[ $name ] );
				}
			}

			return $sizes;
		}, 999 );

		add_action( 'wp_generate_attachment_metadata', function ( $metadata, $attachment_id ) {
			if ( empty( $metadata['file'] ) ) {
				return $metadata;
			}

			$upload_dir = wp_upload_dir();
			$base_dir   = $upload_dir['basedir'];
			$files = array();
			if ( ! empty( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$files[] = $base_dir . '/' . dirname( $metadata['file'] ) . '/' . $size['file'];
					}
				}
			}

			$master_file = $base_dir . '/' . $metadata['file'];
			$scaled_file = preg_replace( '/\.(jpe?g|png|webp)$/i', '-scaled$0', $master_file );
			if ( file_exists( $scaled_file ) ) {
				@unlink( $scaled_file );
			}

			foreach ( $files as $file ) {
				if ( false !== strpos( basename( $file ), '-scaled' ) && file_exists( $file ) ) {
					@unlink( $file );
				}
			}

			return $metadata;
		}, 10, 2 );
	}
}

add_action( 'plugins_loaded', function () {
	if ( function_exists( 'r2offloader' ) ) {
		r2offloader()->init();
	}
} );

register_activation_hook( __FILE__, function () {
	$defaults = array(
		'account_id'      => '',
		'bucket'          => '',
		'api_key'         => '',
		'api_secret'      => '',
		'public_url'      => '',
		'region'          => 'auto',
		'batch_size'      => 10,
		'convert_to_webp' => 1,
		'resize_to_1000'  => 1,
		'delete_local'    => 1,
	);
	update_option( 'r2offloader_settings', array_merge( $defaults, get_option( 'r2offloader_settings', array() ) ) );
} );

register_deactivation_hook( __FILE__, function () {
	delete_transient( 'r2offloader_bulk_sync_lock' );
	delete_transient( 'r2offloader_regen_lock' );
	delete_transient( 'r2offloader_rollback_lock' );
} );
