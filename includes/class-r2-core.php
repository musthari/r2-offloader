<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class R2_Core {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Nonaktifkan pembuatan file -scaled bawaan WordPress
        add_filter( 'big_image_size_threshold', '__return_false' );

        // Hook Auto Resize (1000px) & Convert ke WebP saat upload
        add_filter( 'wp_handle_upload', array( $this, 'process_image_resize_and_webp' ), 10, 2 );

        // Hook Offload ke R2 & Hapus Thumbnail Lokal
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'offload_new_upload' ), 10, 2 );
        add_action( 'delete_attachment', array( $this, 'delete_from_r2_on_delete' ) );

        // Filter Rewrite URL ke Frontend
        add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_attachment_url' ), 10, 2 );
        add_filter( 'wp_get_attachment_image_src', array( $this, 'rewrite_image_src' ), 10, 4 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_image_srcset' ), 10, 5 );
    }

    /**
     * PROSES RESIZE MAKSIMAL 1000PX & KONVERSI KE WEBP
     */
    public function process_image_resize_and_webp( $upload, $context ) {
        if ( empty( $upload['type'] ) || strpos( $upload['type'], 'image/' ) === false ) {
            return $upload;
        }

        if ( $upload['type'] === 'image/webp' ) {
            return $upload;
        }

        $file_path = $upload['file'];
        if ( ! file_exists( $file_path ) ) {
            return $upload;
        }

        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            return $upload;
        }

        $size = $editor->get_size();
        if ( isset( $size['width'] ) && $size['width'] > 1000 ) {
            $editor->resize( 1000, null, false );
        }

        $path_info = pathinfo( $file_path );
        $new_webp_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';

        $saved = $editor->save( $new_webp_path, 'image/webp' );

        if ( ! is_wp_error( $saved ) && file_exists( $saved['path'] ) ) {
            @unlink( $file_path );

            $upload['file'] = $saved['path'];
            $upload['url']  = str_replace( $path_info['basename'], pathinfo( $saved['path'], PATHINFO_BASENAME ), $upload['url'] );
            $upload['type'] = 'image/webp';
        }

        return $upload;
    }

    /**
     * EKSEKUSI REQUEST KE CLOUDFLARE R2 (AWS SIGV4)
     */
    public function execute_r2_s3_request( $method, $r2_key, $body = '', $content_type = '' ) {
        $account_id = trim( get_option( 'r2_account_id' ) );
        $access_key = trim( get_option( 'r2_access_key' ) );
        $secret_key = trim( get_option( 'r2_secret_key' ) );
        $bucket     = trim( get_option( 'r2_bucket_name' ) );

        if ( empty( $account_id ) || empty( $access_key ) || empty( $secret_key ) || empty( $bucket ) ) {
            return new WP_Error( 'r2_empty_credentials', 'Kredensial Cloudflare R2 belum lengkap.' );
        }

        $host      = "{$account_id}.r2.cloudflarestorage.com";
        $service   = 's3';
        $region    = 'auto';
        $clean_key = ltrim( $r2_key, '/' );
        $path      = '/' . $bucket . ( $clean_key ? '/' . $clean_key : '' );
        $endpoint  = "https://{$host}" . $path;

        $algorithm    = 'AWS4-HMAC-SHA256';
        $amzdate      = gmdate( 'Ymd\THis\Z' );
        $datestamp    = gmdate( 'Ymd' );
        $payload_hash = hash( 'sha256', $body );

        $headers = array(
            'host'                 => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $amzdate,
        );

        if ( ! empty( $content_type ) ) {
            $headers['content-type'] = $content_type;
        }

        ksort( $headers );
        $canonical_headers = '';
        $signed_headers_arr = array();

        foreach ( $headers as $k => $v ) {
            $canonical_headers .= strtolower( $k ) . ':' . trim( $v ) . "\n";
            $signed_headers_arr[] = strtolower( $k );
        }

        $signed_headers    = implode( ';', $signed_headers_arr );
        $canonical_request = implode( "\n", array(
            strtoupper( $method ),
            $path,
            '',
            $canonical_headers,
            $signed_headers,
            $payload_hash
        ) );

        $credential_scope = "{$datestamp}/{$region}/{$service}/aws4_request";
        $string_to_sign   = implode( "\n", array(
            $algorithm,
            $amzdate,
            $credential_scope,
            hash( 'sha256', $canonical_request )
        ) );

        $kDate     = hash_hmac( 'sha256', $datestamp, 'AWS4' . $secret_key, true );
        $kRegion   = hash_hmac( 'sha256', $region, $kDate, true );
        $kService  = hash_hmac( 'sha256', $service, $kRegion, true );
        $kSigning  = hash_hmac( 'sha256', 'aws4_request', $kService, true );
        $signature = hash_hmac( 'sha256', $string_to_sign, $kSigning );

        $authorization_header = "{$algorithm} Credential={$access_key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

        $request_headers = array(
            'Host'                 => $host,
            'x-amz-date'           => $amzdate,
            'x-amz-content-sha256' => $payload_hash,
            'Authorization'        => $authorization_header,
        );

        if ( ! empty( $content_type ) ) {
            $request_headers['Content-Type'] = $content_type;
        }

        return wp_remote_request( $endpoint, array(
            'method'  => strtoupper( $method ),
            'body'    => $body,
            'timeout' => 30,
            'headers' => $request_headers
        ) );
    }

    /**
     * CEK APALAH FILE SUDAH ADA DI BUCKET R2 (HTTP HEAD REQUEST)
     */
    public function does_file_exist_in_r2( $r2_key ) {
        $response = $this->execute_r2_s3_request( 'HEAD', $r2_key );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $response );
        return ( $code === 200 );
    }

    public function ping_r2_bucket() {
        $response = $this->execute_r2_s3_request( 'GET', '' );
        if ( is_wp_error( $response ) ) return array( 'success' => false, 'error' => $response->get_error_message() );
        $code = wp_remote_retrieve_response_code( $response );
        return ( $code === 200 ) ? array( 'success' => true ) : array( 'success' => false, 'error' => "HTTP Code: {$code}." );
    }

    /**
     * UPLOAD DENGAN LOGIKA SKIP JIKA FILE SUDAH ADA DI R2
     */
    public function upload_file_to_r2( $file_path, $r2_key ) {
        if ( ! file_exists( $file_path ) ) return false;

        // SKIP UPLOAD jika file sudah ada di R2
        if ( $this->does_file_exist_in_r2( $r2_key ) ) {
            return true;
        }

        $response = $this->execute_r2_s3_request( 'PUT', $r2_key, file_get_contents( $file_path ), mime_content_type( $file_path ) );
        if ( is_wp_error( $response ) ) return false;
        $code = wp_remote_retrieve_response_code( $response );
        return ( $code === 200 || $code === 201 );
    }

    public function delete_file_from_r2( $r2_key ) {
        $response = $this->execute_r2_s3_request( 'DELETE', $r2_key );
        if ( is_wp_error( $response ) ) return false;
        $code = wp_remote_retrieve_response_code( $response );
        return ( $code === 200 || $code === 204 );
    }

    public function delete_from_r2_on_delete( $post_id ) {
        if ( ! get_post_meta( $post_id, '_r2_offloaded', true ) ) return;
        $relative_path = get_post_meta( $post_id, '_wp_attached_file', true );
        $metadata      = wp_get_attachment_metadata( $post_id );

        if ( $relative_path ) {
            $this->delete_file_from_r2( $relative_path );
            if ( ! empty( $metadata['sizes'] ) ) {
                $sub_dir = ( '.' === dirname( $relative_path ) ) ? '' : trailingslashit( dirname( $relative_path ) );
                foreach ( $metadata['sizes'] as $size_info ) {
                    $this->delete_file_from_r2( $sub_dir . $size_info['file'] );
                }
            }
        }
    }

    public function process_single_attachment_offload( $post_id ) {
        $upload_dir    = wp_upload_dir();
        $base_dir      = trailingslashit( $upload_dir['basedir'] );
        $base_url      = trailingslashit( $upload_dir['baseurl'] );
        $cdn_domain    = rtrim( get_option( 'r2_cdn_domain' ), '/' );
        $relative_path = get_post_meta( $post_id, '_wp_attached_file', true );

        if ( ! $relative_path || empty( $cdn_domain ) ) return false;

        $metadata      = wp_get_attachment_metadata( $post_id );
        $original_file = $base_dir . $relative_path;

        if ( file_exists( $original_file ) ) {
            $this->upload_file_to_r2( $original_file, $relative_path );
        }

        if ( ! empty( $metadata['sizes'] ) ) {
            $sub_dir = ( '.' === dirname( $relative_path ) ) ? '' : trailingslashit( dirname( $relative_path ) );
            foreach ( $metadata['sizes'] as $size_info ) {
                $thumb_local_path = $base_dir . $sub_dir . $size_info['file'];
                if ( file_exists( $thumb_local_path ) ) {
                    if ( $this->upload_file_to_r2( $thumb_local_path, $sub_dir . $size_info['file'] ) ) {
                        @unlink( $thumb_local_path );
                    }
                }
            }
        }

        global $wpdb;
        $old_url = $base_url . '/' . ltrim( $relative_path, '/' );
        $new_url = $cdn_domain . '/' . ltrim( $relative_path, '/' );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $old_url, $new_url, '%' . $wpdb->esc_like( $old_url ) . '%'
        ) );

        update_post_meta( $post_id, '_r2_offloaded', 1 );
        return true;
    }

    public function offload_new_upload( $metadata, $attachment_id ) {
        $this->process_single_attachment_offload( $attachment_id );
        return $metadata;
    }

    public function rewrite_attachment_url( $url, $post_id ) {
        if ( get_post_meta( $post_id, '_r2_offloaded', true ) && $cdn = rtrim( get_option( 'r2_cdn_domain' ), '/' ) ) {
            if ( $path = get_post_meta( $post_id, '_wp_attached_file', true ) ) {
                return $cdn . '/' . ltrim( $path, '/' );
            }
        }
        return $url;
    }

    public function rewrite_image_src( $image, $attachment_id, $size, $icon ) {
        if ( get_post_meta( $attachment_id, '_r2_offloaded', true ) && $cdn = rtrim( get_option( 'r2_cdn_domain' ), '/' ) ) {
            if ( is_array( $image ) ) {
                $base_url = rtrim( wp_upload_dir()['baseurl'], '/' );
                $image[0] = str_replace( $base_url, $cdn, $image[0] );
            }
        }
        return $image;
    }

    public function rewrite_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( get_post_meta( $attachment_id, '_r2_offloaded', true ) && $cdn = rtrim( get_option( 'r2_cdn_domain' ), '/' ) ) {
            if ( is_array( $sources ) ) {
                $base_url = rtrim( wp_upload_dir()['baseurl'], '/' );
                foreach ( $sources as $w => $src ) {
                    $sources[$w]['url'] = str_replace( $base_url, $cdn, $src['url'] );
                }
            }
        }
        return $sources;
    }

    public static function get_stats() {
        global $wpdb;
        $total_wp = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
        $total_r2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_r2_offloaded' AND meta_value = '1'" );
        return array( 'total_wp' => $total_wp, 'total_r2' => $total_r2, 'pending' => max( 0, $total_wp - $total_r2 ) );
    }
}