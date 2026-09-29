<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class R2_Offloader_CLI {
	private $settings;
	private $media;
	private $api;
	private $recovery;

	public function __construct( $settings, $media, $api, $recovery ) {
		$this->settings = $settings;
		$this->media = $media;
		$this->api = $api;
		$this->recovery = $recovery;
	}

	public function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'r2-offloader', $this );
		}
	}

	public function status() {
		$configured = $this->settings->is_configured();
		WP_CLI::line( $configured ? 'Cloudflare R2 sudah dikonfigurasi.' : 'Cloudflare R2 belum dikonfigurasi sepenuhnya.' );
		WP_CLI::line( 'Account ID: ' . ( $this->settings->get( 'account_id' ) ?: 'belum diisi' ) );
		WP_CLI::line( 'Bucket: ' . ( $this->settings->get( 'bucket' ) ?: 'belum diisi' ) );
		WP_CLI::line( 'Public URL: ' . ( $this->settings->get( 'public_url' ) ?: 'belum diisi' ) );
	}

	public function test_connection() {
		$result = $this->api->test_connection();
		if ( $result['success'] ) {
			WP_CLI::success( $result['message'] );
		} else {
			WP_CLI::error( $result['message'] );
		}
	}

	public function bulk_sync( $args = array(), $assoc_args = array() ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 20;
		$total = wp_count_posts( 'attachment' )->inherit ?? 0;
		$offset = 0;
		$processed = 0;

		while ( $offset < $total ) {
			$result = $this->media->process_attachment_batch( $offset, $batch_size );
			$processed += (int) $result['processed'];
			$offset = (int) $result['next_offset'];
			WP_CLI::line( 'Processed: ' . $processed . ' / ' . $total );
			if ( $result['completed'] ) {
				break;
			}
		}

		WP_CLI::success( 'Bulk sync selesai.' );
	}

	public function regen_local( $args = array(), $assoc_args = array() ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 15;
		$total = $this->recovery->regenerate_local_batch( 0, $batch_size );
		WP_CLI::success( 'Regenerate local selesai: ' . $total['processed'] . ' item diproses.' );
	}

	public function rollback( $args = array(), $assoc_args = array() ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 15;
		$total = $this->recovery->rollback_database_batch( 0, $batch_size );
		WP_CLI::success( 'Rollback database selesai: ' . $total['processed'] . ' item diproses.' );
	}
}
