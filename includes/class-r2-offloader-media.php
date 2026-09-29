<?php

if (! defined('ABSPATH')) {
    exit;
}

class R2_Offloader_Media {
    protected $settings;
    protected $logger;
    protected $r2;

    public function __construct() {
        $this->settings = new R2_Offloader_Settings();
        $this->logger = new R2_Offloader_Logger();
        $this->r2 = new R2_Offloader_R2();
    }

    public function on_attachment_added($attachment_id) {
        if (! $this->is_active()) {
            return;
        }

        if ($this->settings->get('r2_enable_queue')) {
            $queue = new R2_Offloader_Queue();
            $queue->enqueue($attachment_id, 'offload');
            $this->logger->log('Task enqueued', 'info', $attachment_id);
        } else {
            $this->process_attachment($attachment_id);
        }
    }

    public function maybe_convert_and_offload_attachment($metadata, $attachment_id) {
        if (! $this->is_active()) {
            return $metadata;
        }

        if ($this->settings->get('r2_enable_queue')) {
            $queue = new R2_Offloader_Queue();
            $queue->enqueue($attachment_id, 'offload');
        } else {
            $this->process_attachment($attachment_id);
        }

        return $metadata;
    }

    public function is_active() {
        return ! empty($this->settings->get('enabled'));
    }

    public function process_attachment($attachment_id) {
        if (empty($attachment_id)) {
            return false;
        }

        $file = get_attached_file($attachment_id, true);
        if (empty($file) || ! file_exists($file) || ! R2_Offloader_Utils::is_valid_image_extension($file)) {
            return false;
        }

        $this->logger->log('Starting attachment processing', 'info', $attachment_id);

        $this->cleanup_scaled_local_files(false);

        $webp_path = $this->convert_to_webp($file);
        if (! $webp_path) {
            $this->logger->log('WebP conversion failed', 'error', $attachment_id);
            return false;
        }

        $this->delete_original_master($file);
        $this->logger->log('Original master file deleted', 'info', $attachment_id);

        $metadata = wp_generate_attachment_metadata($attachment_id, $webp_path);
        if (! is_wp_error($metadata) && ! empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $this->upload_all_sizes_to_r2($attachment_id, $webp_path);

        $remote_url = $this->build_remote_url_from_local_path($webp_path);
        $local_url = wp_get_attachment_url($attachment_id);

        if (! empty($local_url)) {
            $this->replace_url_in_database($local_url, $remote_url);
        }

        update_post_meta($attachment_id, '_r2_offloader_status', 'offloaded');
        update_post_meta($attachment_id, '_r2_offloader_r2_url', $remote_url);
        update_post_meta($attachment_id, '_r2_offloader_local_url', $local_url);
        update_post_meta($attachment_id, '_r2_offloader_master_webp', $webp_path);

        $this->logger->log('Attachment processing complete', 'success', $attachment_id);
        return true;
    }

    public function filter_attachment_url($url, $attachment_id) {
        $remote_url = get_post_meta($attachment_id, '_r2_offloader_r2_url', true);
        if (! empty($remote_url)) {
            return $remote_url;
        }

        return $url;
    }

    public function filter_attachment_image_src($image, $attachment_id, $size, $icon) {
        if (! is_array($image)) {
            return $image;
        }

        $remote_url = get_post_meta($attachment_id, '_r2_offloader_r2_url', true);
        if (! empty($remote_url) && ! empty($image[0])) {
            $image[0] = $remote_url;
        }

        return $image;
    }

    public function cleanup_scaled_local_files($do_delete = true) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $files = R2_Offloader_Utils::recursive_dir_scan($base_dir);
        $deleted = 0;

        foreach ($files as $file) {
            if (strpos(basename($file), '-scaled.') === false) {
                continue;
            }

            $original = R2_Offloader_Utils::get_matching_original_for_scaled($file);
            if ($original && file_exists($original) && $do_delete) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function cleanup_scaled_r2_files() {
        $uploads_dir = wp_upload_dir();
        $base_dir = $uploads_dir['basedir'];
        $files = R2_Offloader_Utils::recursive_dir_scan($base_dir);
        $deleted = 0;

        foreach ($files as $file) {
            if (strpos(basename($file), '-scaled.') === false) {
                continue;
            }

            $original = R2_Offloader_Utils::get_matching_original_for_scaled($file);
            if ($original && file_exists($original)) {
                $relative = R2_Offloader_Utils::get_relative_upload_path($file);
                if ($this->r2->delete_object($relative)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    protected function convert_to_webp($file) {
        $settings = $this->settings->get_all();
        $max_width = absint($settings['r2_resize_width']) ?: 1000;
        $quality = absint($settings['r2_webp_quality']) ?: 75;

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return false;
        }

        $size = $editor->get_size();
        if (empty($size['width'])) {
            return false;
        }

        if ($size['width'] > $max_width) {
            $editor->resize($max_width, null, false);
        }

        $output_path = dirname($file) . '/' . pathinfo($file, PATHINFO_FILENAME) . '.webp';
        $editor->set_quality($quality);
        $saved = $editor->save($output_path, 'image/webp');

        if (is_wp_error($saved)) {
            return false;
        }

        return $output_path;
    }

    protected function delete_original_master($file) {
        if (! empty($file) && file_exists($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'bmp'), true)) {
                @unlink($file);
            }
        }
    }

    protected function upload_all_sizes_to_r2($attachment_id, $webp_path) {
        $uploads = wp_upload_dir();
        $base_dir = $uploads['basedir'];

        $metadata = wp_get_attachment_metadata($attachment_id, true);

        if (! empty($metadata['file'])) {
            $master_path = $base_dir . '/' . $metadata['file'];
            if (file_exists($master_path)) {
                $this->upload_single_file_to_r2($master_path, $attachment_id);
            }
        }

        $this->upload_single_file_to_r2($webp_path, $attachment_id);

        if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_meta) {
                $local_file = $base_dir . '/' . $size_meta['file'];
                if (file_exists($local_file)) {
                    $this->upload_single_file_to_r2($local_file, $attachment_id);
                    if (! empty($this->settings->get('r2_delete_local_thumbs'))) {
                        @unlink($local_file);
                    }
                }
            }
        }
    }

    protected function upload_single_file_to_r2($local_path, $attachment_id = null) {
        $relative = R2_Offloader_Utils::get_relative_upload_path($local_path);
        return $this->r2->upload_file($local_path, $relative, $attachment_id);
    }

    protected function build_remote_url_from_local_path($local_path) {
        $relative = R2_Offloader_Utils::get_relative_upload_path($local_path);
        $custom_domain = $this->r2->get_public_url();
        return rtrim($custom_domain, '/') . '/' . ltrim($relative, '/');
    }

    protected function replace_url_in_database($old_url, $new_url) {
        global $wpdb;

        if (empty($old_url) || empty($new_url) || $old_url === $new_url) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_name LIKE %s AND option_value LIKE %s",
                $old_url,
                $new_url,
                'theme_mods_%',
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_name LIKE %s AND option_value LIKE %s",
                $old_url,
                $new_url,
                'widget_%',
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_name IN ('custom_logo', 'site_icon', 'header_image', 'background_image') AND option_value LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
    }
}
