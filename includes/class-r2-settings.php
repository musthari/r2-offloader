<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class R2_Offloader_Settings {
	private $option_key = 'r2offloader_settings';

	public function get_all() {
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

		$settings = get_option( $this->option_key, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( $defaults, $settings );
	}

	public function get( $key, $default = '' ) {
		$settings = $this->get_all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	public function update( $key, $value ) {
		$settings = $this->get_all();
		$settings[ $key ] = $value;
		update_option( $this->option_key, $settings );
	}

	public function save( $settings ) {
		$clean = $this->sanitize( $settings );
		update_option( $this->option_key, $clean );
		return $clean;
	}

	public function sanitize( $settings ) {
		$clean = $this->get_all();
		if ( ! is_array( $settings ) ) {
			return $clean;
		}

		foreach ( $settings as $key => $value ) {
			if ( isset( $clean[ $key ] ) ) {
				if ( is_string( $clean[ $key ] ) ) {
					$clean[ $key ] = trim( wp_unslash( $value ) );
				} else {
					$clean[ $key ] = sanitize_text_field( wp_unslash( $value ) );
				}
			}
		}

		if ( isset( $clean['public_url'] ) ) {
			$clean['public_url'] = untrailingslashit( rtrim( $clean['public_url'], '/' ) );
		}

		return $clean;
	}

	public function is_configured() {
		return ! empty( $this->get( 'account_id' ) )
			&& ! empty( $this->get( 'bucket' ) )
			&& ! empty( $this->get( 'api_key' ) )
			&& ! empty( $this->get( 'api_secret' ) )
			&& ! empty( $this->get( 'public_url' ) );
	}

	public function get_bucket_host() {
		$bucket = $this->get( 'bucket' );
		$account_id = $this->get( 'account_id' );
		if ( empty( $bucket ) || empty( $account_id ) ) {
			return '';
		}

		return $bucket . '.' . $account_id . '.r2.cloudflarestorage.com';
	}

	public function get_private_host() {
		$bucket = $this->get( 'bucket' );
		$account_id = $this->get( 'account_id' );
		if ( empty( $bucket ) || empty( $account_id ) ) {
			return '';
		}

		return $bucket . '.' . $account_id . '.r2.cloudflarestorage.com';
	}
}
