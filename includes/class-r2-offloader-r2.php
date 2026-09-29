<?php

if (! defined('ABSPATH')) {
    exit;
}

class R2_Offloader_R2 {
    protected $settings;
    protected $logger;

    public function __construct() {
        $this->settings = new R2_Offloader_Settings();
        $this->logger = new R2_Offloader_Logger();
    }

    public function get_bucket_name() {
        return trim((string) $this->settings->get('r2_bucket_name'));
    }

    public function get_account_id() {
        return trim((string) $this->settings->get('r2_account_id'));
    }

    public function get_access_key() {
        return trim((string) $this->settings->get('r2_access_key_id'));
    }

    public function get_secret_key() {
        return trim((string) $this->settings->get('r2_secret_access_key'));
    }

    public function get_public_domain() {
        return trim((string) $this->settings->get('r2_public_domain'));
    }

    public function get_endpoint() {
        $endpoint = trim((string) $this->settings->get('r2_endpoint'));
        if (! empty($endpoint)) {
            return rtrim($endpoint, '/');
        }

        $account_id = $this->get_account_id();
        if (! empty($account_id)) {
            return 'https://' . $account_id . '.r2.cloudflarestorage.com';
        }

        return '';
    }

    public function get_public_url() {
        $domain = $this->get_public_domain();
        if (! empty($domain)) {
            return rtrim($domain, '/');
        }

        return rtrim($this->get_endpoint(), '/');
    }

    public function get_region() {
        return trim((string) $this->settings->get('r2_region')) ?: 'auto';
    }

    public function build_url($remote_key = '', $with_bucket = true) {
        $endpoint = $this->get_endpoint();
        $bucket = $this->get_bucket_name();
        $key = '/' . ltrim((string) $remote_key, '/');

        if (empty($endpoint)) {
            return '';
        }

        if ($with_bucket && ! empty($bucket)) {
            return rtrim($endpoint, '/') . '/' . $bucket . $key;
        }

        return rtrim($endpoint, '/') . $key;
    }

    public function test_connection() {
        $bucket = $this->get_bucket_name();
        $access = $this->get_access_key();
        $secret = $this->get_secret_key();

        if (empty($bucket) || empty($access) || empty($secret)) {
            return array(
                'success' => false,
                'message' => __('Pastikan Bucket, Access Key, dan Secret Key R2 sudah diisi.', 'r2-offloader'),
            );
        }

        $url = $this->build_url('', true);
        $response = $this->request('HEAD', $url, null, 25);

        if (is_wp_error($response)) {
            $this->logger->log('Connection test failed: ' . $response->get_error_message(), 'error');
            return array(
                'success' => false,
                'message' => __('Koneksi R2 gagal: ', 'r2-offloader') . $response->get_error_message(),
            );
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status >= 200 && $status < 400) {
            $this->logger->log('Connection test successful', 'success');
            return array(
                'success' => true,
                'message' => __('Koneksi berhasil. Cloudflare R2 siap digunakan.', 'r2-offloader'),
            );
        }

        $this->logger->log('Connection test failed with HTTP status: ' . $status, 'error');
        return array(
            'success' => false,
            'message' => __('Koneksi gagal dengan status HTTP: ', 'r2-offloader') . $status,
        );
    }

    public function upload_file($local_path, $remote_key, $attachment_id = null) {
        if (! file_exists($local_path)) {
            $this->logger->log('File not found: ' . $local_path, 'error', $attachment_id);
            return false;
        }

        $bucket = $this->get_bucket_name();
        $access = $this->get_access_key();
        $secret = $this->get_secret_key();

        if (empty($bucket) || empty($access) || empty($secret)) {
            return false;
        }

        $body = file_get_contents($local_path);
        if ($body === false) {
            $this->logger->log('Failed to read file: ' . $local_path, 'error', $attachment_id);
            return false;
        }

        $url = $this->build_url($remote_key, true);
        $response = $this->request('PUT', $url, $body, 180);

        if (is_wp_error($response)) {
            $this->logger->log('Upload failed: ' . $response->get_error_message(), 'error', $attachment_id);
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            $this->logger->log('File uploaded to R2: ' . $remote_key, 'success', $attachment_id);
            return true;
        }

        $this->logger->log('Upload failed with HTTP status: ' . $status, 'error', $attachment_id);
        return false;
    }

    public function delete_object($remote_key, $attachment_id = null) {
        $bucket = $this->get_bucket_name();
        $access = $this->get_access_key();
        $secret = $this->get_secret_key();

        if (empty($bucket) || empty($access) || empty($secret)) {
            return false;
        }

        $url = $this->build_url($remote_key, true);
        $response = $this->request('DELETE', $url, null, 60);

        if (is_wp_error($response)) {
            $this->logger->log('Delete failed: ' . $response->get_error_message(), 'error', $attachment_id);
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            $this->logger->log('Object deleted from R2: ' . $remote_key, 'success', $attachment_id);
            return true;
        }

        return false;
    }

    public function list_objects($prefix = '') {
        $bucket = $this->get_bucket_name();
        $access = $this->get_access_key();
        $secret = $this->get_secret_key();

        if (empty($bucket) || empty($access) || empty($secret)) {
            return array();
        }

        $url = $this->build_url('', true);
        if (! empty($prefix)) {
            $url .= '?prefix=' . rawurlencode($prefix);
        }

        $response = $this->request('GET', $url, null, 60);
        if (is_wp_error($response)) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return array();
        }

        $xml = simplexml_load_string($body);
        if (! $xml) {
            return array();
        }

        $objects = array();
        foreach ($xml->Contents as $content) {
            $key = (string) $content->Key;
            if (! empty($key)) {
                $objects[] = $key;
            }
        }

        return $objects;
    }

    protected function request($method, $url, $body = null, $timeout = 60) {
        $parts = wp_parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && ! empty($parts['query']) ? '?' . $parts['query'] : '';

        $access = $this->get_access_key();
        $secret = $this->get_secret_key();
        $region = $this->get_region();
        $service = 's3';
        $date = gmdate('Ymd');
        $timestamp = gmdate('Ymd\THis\Z');

        $payload_hash = hash('sha256', $body !== null ? $body : '');

        $canonical_query = $this->aws_canonical_query($parts['query'] ?? '');
        $canonical_uri = $this->aws_canonical_uri($path);
        $canonical_headers = "host:" . strtolower($host) . "\n";
        $signed_headers = 'host';

        $canonical_request = implode("\n", array(
            strtoupper($method),
            $canonical_uri,
            $canonical_query,
            $canonical_headers . '\n',
            $signed_headers,
            $payload_hash,
        ));

        $credential_scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $string_to_sign = implode("\n", array(
            'AWS4-HMAC-SHA256',
            $timestamp,
            $credential_scope,
            hash('sha256', $canonical_request),
        ));

        $signature = $this->aws_signature($secret, $date, $region, $service, $string_to_sign);

        $headers = array(
            'Host'                 => strtolower($host),
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'          => $timestamp,
            'Authorization'      => 'AWS4-HMAC-SHA256 Credential=' . $access . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
        );

        $args = array(
            'method'    => strtoupper($method),
            'headers'   => $headers,
            'timeout'   => $timeout,
            'sslverify' => false,
            'body'      => $body,
        );

        return wp_remote_request($url . $query, $args);
    }

    protected function aws_canonical_uri($uri) {
        $uri = rawurlencode($uri);
        return str_replace('%2F', '/', $uri);
    }

    protected function aws_canonical_query($query) {
        if (empty($query)) {
            return '';
        }

        $pairs = explode('&', $query);
        sort($pairs);
        $encoded = array();

        foreach ($pairs as $pair) {
            if (empty($pair)) {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $key = rawurlencode(rawurldecode($parts[0]));
            $value = isset($parts[1]) ? rawurlencode(rawurldecode($parts[1])) : '';
            $encoded[] = $key . '=' . $value;
        }

        return implode('&', $encoded);
    }

    protected function aws_signature($secret_key, $date, $region, $service, $string_to_sign) {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret_key, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $string_to_sign, $kSigning);
    }
}
