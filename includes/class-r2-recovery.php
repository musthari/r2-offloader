<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class R2_Offloader_Recovery {
	private $settings;
	private $media;

	public function __construct( $settings, $media ) {
		$this->settings = $settings;
		$this->media = $media;
	}

	public function regenerate_local_batch( $offset = 0, $limit = 10 ) {
		$attachments = $this->get_offloaded_attachments( $offset, $limit );
		$total = $this->count_offloaded_attachments();
		$processed = 0;

		foreach ( $attachments as $attachment ) {
			if ( $this->regenerate_local_from_master( $attachment->ID ) ) {
				$processed++;
			}
		}

		$next_offset = $offset + $processed;
		return array(
			'processed' => $processed,
			'total'     => (int) $total,
			'next_offset' => $next_offset,
			'completed' => $next_offset >= $total,
			'percent'   => $total > 0 ? min( 100, round( ( $next_offset / $total ) * 100 ) ) : 0,
		);
	}

	public function rollback_database_batch( $offset = 0, $limit = 10 ) {
		$attachments = $this->get_offloaded_attachments( $offset, $limit );
		$total = $this->count_offloaded_attachments();
		$processed = 0;

		foreach ( $attachments as $attachment ) {
			if ( $this->rollback_attachment_database( $attachment->ID ) ) {
				$processed++;
			}
		}

		$next_offset = $offset + $processed;
		return array(
			'processed' => $processed,
			'total' => (int) $total,
			'next_offset' => $next_offset,
			'completed' => $next_offset >= $total,
			'percent' => $total > 0 ? min( 100, round( ( $next_offset / $total ) * 100 ) ) : 0,
		);
	}

	private function get_offloaded_attachments( $offset = 0, $limit = 10 ) {
		return get_posts(
			array(
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'posts_per_page' => (int) $limit,
				'offset' => (int) $offset,
				'orderby' => 'ID',
				'order' => 'ASC',
				'meta_key' => '_r2offloader_is_offloaded',
				'meta_value' => '1',
			)
		);
	}

	private function count_offloaded_attachments() {
		$attachments = get_posts(
			array(
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'posts_per_page' => -1,
				'meta_key' => '_r2offloader_is_offloaded',
				'meta_value' => '1',
				'fields' => 'ids',
			)
		);
		return count( $attachments );
	}

	private function regenerate_local_from_master( $attachment_id ) {
		$master = get_post_meta( $attachment_id, '_r2offloader_master_webp', true );
		if ( empty( $master ) || ! file_exists( $master ) ) {
			return false;
		}

		$pathinfo = pathinfo( $master );
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['file'] ) ) {
			$meta['file'] = ltrim( str_replace( wp_upload_dir()['basedir'] . '/', '', $master ), '/' );
		}

		$updated = wp_generate_attachment_metadata( $attachment_id, $master );
		if ( ! is_array( $updated ) ) {
			return false;
		}

		wp_update_attachment_metadata( $attachment_id, $updated );
		return true;
	}

	private function rollback_attachment_database( $attachment_id ) {
		$upload_dir = wp_upload_dir();
		$base_url = trailingslashit( $upload_dir['baseurl'] );
		$cdn_base = trailingslashit( $this->settings->get( 'public_url' ) );
		$attachment = get_post( $attachment_id );

		if ( $attachment && ! empty( $attachment->guid ) ) {
			$updated_guid = str_replace( $cdn_base, $base_url, $attachment->guid );
			wp_update_post( array( 'ID' => $attachment_id, 'guid' => $updated_guid ) );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) ) {
			$meta['file'] = str_replace( $cdn_base, $base_url, $base_url . $meta['file'] );
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		$thumb_map = get_post_meta( $attachment_id, '_r2offloader_thumb_map', true );
		if ( is_array( $thumb_map ) ) {
			foreach ( $thumb_map as $local => $cdn ) {
				$cdn = str_replace( $base_url, $cdn_base, $cdn );
			}
		}

		delete_post_meta( $attachment_id, '_r2offloader_is_offloaded' );
		delete_post_meta( $attachment_id, '_r2offloader_cdn_master_url' );
		delete_post_meta( $attachment_id, '_r2offloader_local_master_url' );
		delete_post_meta( $attachment_id, '_r2offloader_thumb_map' );
		delete_post_meta( $attachment_id, '_r2offloader_master_webp' );
		return true;
	}
}
