<?php

if (! defined('ABSPATH')) {
    exit;
}

class R2_Offloader_Settings {
    protected $defaults = array(
        'enabled'                      => 0,
        'r2_account_id'               => '',
        'r2_bucket_name'               => '',
        'r2_access_key_id'            => '',
        'r2_secret_access_key'        => '',
        'r2_public_domain'           => '',
        'r2_endpoint'                => '',
        'r2_region'                  => 'auto',
        'r2_webp_quality'            => 75,
        'r2_resize_width'            => 1000,
        'r2_batch_size'             => 20,
        'r2_delete_local_thumbs'    => 1,
        'r2_keep_master_webp_local' => 1,
        'r2_enable_queue'            => 1,
        'r2_max_retries'            => 3,
        'r2_log_enabled'            => 1,
        'r2_last_sync'               => '',
        'r2_lock'                   => '',
    );

    public function get_all() {
        $saved = get_option('r2_offloader_settings', array());
        return wp_parse_args($saved, $this->defaults);
    }

    public function get($key, $default = null) {
        $settings = $this->get_all();
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public function update($key, $value) {
        $settings = $this->get_all();
        $settings[$key] = $value;
        update_option('r2_offloader_settings', $settings);
        return $settings;
    }

    public function save($data) {
        $settings = $this->get_all();
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = sanitize_text_field($value);
            }
        }
        update_option('r2_offloader_settings', $settings);
        return $settings;
    }
}
