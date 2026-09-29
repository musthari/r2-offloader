<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class R2_Offloader_AJAX {
	private $settings;
	private $api;
	private $media;
	private $recovery;

	public function __construct( $settings, $api, $media, $recovery ) {
		$this->settings = $settings;
		$this->api = $api;
		$this->media = $media;
		$this->recovery = $recovery;
	}

	public function init() {
		add_action( 'wp_ajax_r2offloader_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_r2offloader_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_r2offloader_bulk_sync', array( $this, 'bulk_sync' ) );
		add_action( 'wp_ajax_r2offloader_regen_local_thumbs', array( $this, 'regen_local_thumbs' ) );
		add_action( 'wp_ajax_r2offloader_rollback_db', array( $this, 'rollback_db' ) );
	}

	public function save_settings() {
		check_ajax_referer( 'r2offloader_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Akses ditolak.', 'r2-offloader' ) ) );
		}

		$settings = $this->settings->sanitize( $_POST );
		$this->settings->save( $settings );
		wp_send_json_success( array( 'message' => __( 'Pengaturan tersimpan.', 'r2-offloader' ) ) );
	}

	public function test_connection() {
		check_ajax_referer( 'r2offloader_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Akses ditolak.', 'r2-offloader' ) ) );
		}

		$result = $this->api->test_connection();
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	public function bulk_sync() {
		check_ajax_referer( 'r2offloader_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Akses ditolak.', 'r2-offloader' ) ) );
		}

		if ( get_transient( 'r2offloader_bulk_sync_lock' ) ) {
			wp_send_json_error( array( 'message' => __( 'Proses bulk sync masih berjalan. Tunggu sampai selesai.', 'r2-offloader' ) ) );
		}

		set_transient( 'r2offloader_bulk_sync_lock', time(), 300 );
		try {
			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 10;
			$batch_size = $batch_size > 0 ? $batch_size : 10;

			$result = $this->media->process_attachment_batch( $offset, $batch_size );
			$percent = 0;
			$total = (int) $result['total'];
			if ( $total > 0 ) {
				$percent = min( 100, round( ( (int) $result['next_offset'] / $total ) * 100 ) );
			}
			$result['percent'] = $percent;
			if ( $result['completed'] ) {
				delete_transient( 'r2offloader_bulk_sync_lock' );
			}
			wp_send_json_success( $result );
		} catch ( \\Throwable $e ) {
			delete_transient( 'r2offloader_bulk_sync_lock' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function regen_local_thumbs() {
		check_ajax_referer( 'r2offloader_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Akses ditolak.', 'r2-offloader' ) ) );
		}

		if ( get_transient( 'r2offloader_regen_lock' ) ) {
			wp_send_json_error( array( 'message' => __( 'Proses regenerasi thumbnail lokal masih berjalan.', 'r2-offloader' ) ) );
		}

		set_transient( 'r2offloader_regen_lock', time(), 300 );
		try {
			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 10;
			$result = $this->recovery->regenerate_local_batch( $offset, $batch_size );
			if ( $result['completed'] ) {
				delete_transient( 'r2offloader_regen_lock' );
			}
			wp_send_json_success( $result );
		} catch ( \\Throwable $e ) {
			delete_transient( 'r2offloader_regen_lock' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function rollback_db() {
		check_ajax_referer( 'r2offloader_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Akses ditolak.', 'r2-offloader' ) ) );
		}

		if ( get_transient( 'r2offloader_rollback_lock' ) ) {
			wp_send_json_error( array( 'message' => __( 'Proses rollback database masih berjalan.', 'r2-offloader' ) ) );
		}

		set_transient( 'r2offloader_rollback_lock', time(), 300 );
		try {
			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 10;
			$result = $this->recovery->rollback_database_batch( $offset, $batch_size );
			if ( $result['completed'] ) {
				delete_transient( 'r2offloader_rollback_lock' );
			}
			wp_send_json_success( $result );
		} catch ( \\Throwable $e ) {
			delete_transient( 'r2offloader_rollback_lock' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}
