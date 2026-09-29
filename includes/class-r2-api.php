<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class R2_Offloader_R2_API {
	private $settings;

	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	public function test_connection() {
		$bucket = $this->settings->get( 'bucket' );
		$account = $this->settings->get( 'account_id' );
		$api_key = $this->settings->get( 'api_key' );
		$api_secret = $this->settings->get( 'api_secret' );

		if ( empty( $bucket ) || empty( $account ) || empty( $api_key ) || empty( $api_secret ) ) {
			return array(
				'success' => false,
				'message' => __( 'Lengkapi Account ID, Bucket, API Token, dan Secret terlebih dahulu.', 'r2-offloader' ),
			);
		}

		$response = $this->request( 'GET', '/', '', array(), 'application/xml' );
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 200 && $status_code < 300 ) {
			return array(
				'success' => true,
				'message' => __( 'Koneksi Cloudflare R2 berhasil. Bucket dapat diakses.', 'r2-offloader' ),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		return array(
			'success' => false,
			'message' => sprintf( __( 'Koneksi gagal. HTTP %d. %s', 'r2-offloader' ), $status_code, $body ),
		);
	}

	public function upload_file( $local_path, $remote_path ) {
		if ( ! file_exists( $local_path ) ) {
			return array(
				'success' => false,
				'error'   => __( 'File lokal tidak ditemukan.', 'r2-offloader' ),
			);
		}

		$body = file_get_contents( $local_path );
		if ( $body === false ) {
			return array(
				'success' => false,
				'error'   => __( 'Tidak dapat membaca file lokal.', 'r2-offloader' ),
			);
		}

		$mime = wp_check_filetype( $local_path );
		$headers = array(
			'Content-Type' => ! empty( $mime['type'] ) ? $mime['type'] : 'application/octet-stream',
		);

		$response = $this->request( 'PUT', $remote_path, $body, $headers );
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return array( 'success' => true );
		}

		return array(
			'success' => false,
			'error'   => sprintf( __( 'Upload gagal dengan status HTTP %d.', 'r2-offloader' ), $status ),
		);
	}

	public function delete_file( $remote_path ) {
		$response = $this->request( 'DELETE', $remote_path, '', array() );
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		return array(
			'success' => $status >= 200 && $status < 300,
			'code'    => $status,
		);
	}

	public function request( $method, $remote_path, $body = '', $headers = array(), $content_type = 'application/octet-stream' ) {
		$bucket = $this->settings->get( 'bucket' );
		$account_id = $this->settings->get( 'account_id' );
		$access_key = $this->settings->get( 'api_key' );
		$secret_key = $this->settings->get( 'api_secret' );

		if ( empty( $bucket ) || empty( $account_id ) || empty( $access_key ) || empty( $secret_key ) ) {
			return new WP_Error( 'r2_missing_credentials', __( 'Credential R2 belum diisi.', 'r2-offloader' ) );
		}

		$host = $this->settings->get_bucket_host();
		$path = '/' . ltrim( $remote_path, '/' );
		$url  = 'https://' . $host . $path;

		if ( 'GET' === $method && empty( $remote_path ) ) {
			$url = 'https://' . $host . '/';
		}

		$payload_hash = hash( 'sha256', $body );
		$timestamp = gmdate( 'Ymd\THis\Z' );
		$date      = gmdate( 'Ymd' );
		$region    = $this->settings->get( 'region', 'auto' );
		if ( empty( $region ) ) {
			$region = 'auto';
		}

		$canonical_headers = "host:$host\n";
		$canonical_headers .= "x-amz-content-sha256:$payload_hash\n";
		$canonical_headers .= "x-amz-date:$timestamp\n";

		$canonical_request = implode( "\n", array(
			$method,
			$path,
			'',
			$canonical_headers,
			'host;x-amz-content-sha256;x-amz-date',
			$payload_hash,
		) );

		$credential_scope = $date . '/' . $region . '/s3/aws4_request';
		$string_to_sign = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$timestamp,
			$credential_scope,
			hash( 'sha256', $canonical_request ),
		) );

		$kDate = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$kRegion = hash_hmac( 'sha256', $region, $kDate, true );
		$kService = hash_hmac( 'sha256', 's3', $kRegion, true );
		$kSigning = hash_hmac( 'sha256', 'aws4_request', $kService, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $kSigning );

		$authorization = 'AWS4-HMAC-SHA256 ';
		$authorization .= 'Credential=' . $access_key . '/' . $credential_scope . ', ';
		$authorization .= 'SignedHeaders=host;x-amz-content-sha256;x-amz-date, ';
		$authorization .= 'Signature=' . $signature;

		$final_headers = array(
			'Host' => $host,
			'Content-Type' => $content_type,
			'X-Amz-Date' => $timestamp,
			'X-Amz-Content-Sha256' => $payload_hash,
			'Authorization' => $authorization,
		);

		$request_headers = array_merge( $headers, $final_headers );
		$http_args = array(
			'timeout' => 120,
			'sslverify' => false,
			'method' => $method,
			'headers' => $request_headers,
		);

		if ( ! empty( $body ) || 'PUT' === $method || 'POST' === $method ) {
			$http_args['body'] = $body;
		}

		return wp_remote_request( $url, $http_args );
	}
}
