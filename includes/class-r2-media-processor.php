<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class R2_Offloader_Media_Processor {
	private $settings;

	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	public function process_attachment_batch( $offset = 0, $limit = 10 ) {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => (int) $limit,
				'offset'         => (int) $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'post_mime_type' => 'image',
			)
		);

		$total = wp_count_posts( 'attachment' )->inherit ?? 0;
		$processed = 0;

		foreach ( $attachments as $attachment ) {
			$result = $this->offload_attachment( $attachment->ID );
			if ( $result['success'] ) {
				$processed++;
			}
		}

		return array(
			'processed' => $processed,
			'total'     => (int) $total,
			'next_offset' => (int) $offset + (int) $processed,
			'completed' => ( (int) $offset + (int) $processed ) >= (int) $total,
		);
	}

	public function offload_attachment( $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return array( 'success' => false, 'reason' => 'not_image' );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['file'] ) ) {
			return array( 'success' => false, 'reason' => 'missing_file' );
		}

		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];
		$master_file = $base_dir . '/' . $meta['file'];

		if ( ! file_exists( $master_file ) ) {
			return array( 'success' => false, 'reason' => 'missing_local_file' );
		}

		$master_file = $this->ensure_master_webp( $attachment_id, $master_file );
		if ( empty( $master_file ) || ! file_exists( $master_file ) ) {
			return array( 'success' => false, 'reason' => 'webp_not_created' );
		}

		$thumbs = $this->collect_thumbnail_files( $attachment_id, $meta );
		$api = r2offloader()->get_api();

		$master_remote = $this->relative_file_path( $master_file );
		$upload_result = $api->upload_file( $master_file, $master_remote );
		if ( ! $upload_result['success'] ) {
			return array( 'success' => false, 'reason' => 'master_upload_failed', 'message' => $upload_result['error'] ?? __( 'Upload master gagal.', 'r2-offloader' ) );
		}

		foreach ( $thumbs as $thumb_path ) {
			$remote_path = $this->relative_file_path( $thumb_path );
			$thumb_result = $api->upload_file( $thumb_path, $remote_path );
			if ( ! $thumb_result['success'] ) {
				return array( 'success' => false, 'reason' => 'thumb_upload_failed', 'message' => $thumb_result['error'] ?? __( 'Upload thumbnail gagal.', 'r2-offloader' ) );
			}
		}

		foreach ( $thumbs as $thumb_path ) {
			if ( file_exists( $thumb_path ) ) {
				@unlink( $thumb_path );
			}
		}

		$this->store_offload_meta( $attachment_id, $master_file, $thumbs );
		$this->replace_urls_with_cdn( $attachment_id, $master_file, $thumbs );

		update_post_meta( $attachment_id, '_r2offloader_is_offloaded', '1' );
		update_post_meta( $attachment_id, '_r2offloader_master_webp', $master_file );

		return array( 'success' => true );
	}

	public function ensure_master_webp( $attachment_id, $source_file ) {
		$source_ext = strtolower( pathinfo( $source_file, PATHINFO_EXTENSION ) );
		if ( 'webp' === $source_ext ) {
			return $source_file;
		}

		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];
		$target = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_file );

		$editor = wp_get_image_editor( $source_file );
		if ( is_wp_error( $editor ) ) {
			return '';
		}

		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && $size['width'] > 1000 ) {
			$editor->resize( 1000, null, false );
		}

		$editor->set_quality( 75 );
		$result = $editor->save( $target, 'image/webp' );
		if ( is_wp_error( $result ) ) {
			return '';
		}

		$original = $source_file;
		$exts = array( 'jpg', 'jpeg', 'png' );
		foreach ( $exts as $ext ) {
			$old = preg_replace( '/\.(jpe?g|png)$/i', '.' . $ext, $original );
			if ( file_exists( $old ) && $old !== $target ) {
				@unlink( $old );
			}
		}

		$attachment_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $attachment_meta['file'] ) ) {
			$attachment_meta['file'] = str_replace( pathinfo( $attachment_meta['file'], PATHINFO_EXTENSION ), 'webp', $attachment_meta['file'] );
			wp_update_attachment_metadata( $attachment_id, $attachment_meta );
		}

		return $target;
	}

	public function collect_thumbnail_files( $attachment_id, $meta ) {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];
		$dir = dirname( $base_dir . '/' . $meta['file'] );
		$files = array();

		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$path = $dir . '/' . $size['file'];
				if ( file_exists( $path ) ) {
					$files[] = $path;
				}
			}
		}

		return array_unique( $files );
	}

	public function relative_file_path( $full_path ) {
		$upload_dir = wp_upload_dir();
		$basedir = trailingslashit( $upload_dir['basedir'] );
		$relative = str_replace( $basedir, '', $full_path );
		return ltrim( $relative, '/' );
	}

	public function store_offload_meta( $attachment_id, $master_file, $thumbs ) {
		$upload_dir = wp_upload_dir();
		$cdn_base = trailingslashit( $this->settings->get( 'public_url' ) );
		$local_base = trailingslashit( $upload_dir['baseurl'] );
		$master_remote = $this->relative_file_path( $master_file );
		$master_url = $cdn_base . $master_remote;
		update_post_meta( $attachment_id, '_r2offloader_cdn_master_url', $master_url );
		update_post_meta( $attachment_id, '_r2offloader_local_master_url', $local_base . $master_remote );

		$thumb_map = array();
		foreach ( $thumbs as $thumb_path ) {
			$relative = $this->relative_file_path( $thumb_path );
			$thumb_map[ $local_base . $relative ] = $cdn_base . $relative;
		}
		update_post_meta( $attachment_id, '_r2offloader_thumb_map', $thumb_map );
	}

	public function replace_urls_with_cdn( $attachment_id, $master_file, $thumbs ) {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$base_url = trailingslashit( $upload_dir['baseurl'] );
		$cdn_base = trailingslashit( $this->settings->get( 'public_url' ) );

		$attachment = get_post( $attachment_id );
		if ( $attachment && ! empty( $attachment->guid ) ) {
			$new_guid = str_replace( $base_url, $cdn_base, $attachment->guid );
			if ( $new_guid !== $attachment->guid ) {
				wp_update_post( array(
					'ID' => $attachment_id,
					'guid' => $new_guid,
				) );
			}
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['file'] ) ) {
			$meta['file'] = str_replace( $base_url, $cdn_base, $base_url . $meta['file'] );
		}
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $key => $size ) {
				if ( ! empty( $size['file'] ) ) {
					$meta['sizes'][ $key ]['file'] = $size['file'];
				}
			}
		}
		wp_update_attachment_metadata( $attachment_id, $meta );

		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s",
				'_wp_attachment_metadata',
				'%' . esc_sql( $base_url ) . '%'
			)
		);

		foreach ( $meta_rows as $row ) {
			$updated = str_replace( $base_url, $cdn_base, $row->meta_value );
			if ( $updated !== $row->meta_value ) {
				$wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $updated ),
					array( 'meta_id' => $row->meta_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM $wpdb->posts WHERE post_content LIKE %s",
				'%' . esc_sql( $base_url ) . '%'
			)
		);

		foreach ( $posts as $post ) {
			$updated_content = str_replace( $base_url, $cdn_base, $post->post_content );
			if ( $updated_content !== $post->post_content ) {
				$wpdb->update(
					$wpdb->posts,
					array( 'post_content' => $updated_content ),
					array( 'ID' => $post->ID ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}
}
