<?php

if (! defined('ABSPATH')) {
    exit;
}

class R2_Offloader_Utils {
    public static function normalize_path($path) {
        return str_replace('\\', '/', $path);
    }

    public static function safe_unlink($path) {
        if (! empty($path) && file_exists($path)) {
            @unlink($path);
        }
    }

    public static function get_uploads_dir() {
        $uploads = wp_upload_dir();
        return $uploads['basedir'];
    }

    public static function get_uploads_url() {
        $uploads = wp_upload_dir();
        return $uploads['baseurl'];
    }

    public static function get_relative_upload_path($absolute_path) {
        $base_dir = self::normalize_path(self::get_uploads_dir());
        $real = self::normalize_path($absolute_path);

        if (strpos($real, $base_dir) === 0) {
            return ltrim(str_replace($base_dir, '', $real), '/');
        }

        return ltrim($real, '/');
    }

    public static function build_remote_url($local_path, $custom_domain = '') {
        $relative = self::get_relative_upload_path($local_path);

        if (! empty($custom_domain)) {
            return rtrim($custom_domain, '/') . '/' . ltrim($relative, '/');
        }

        $public_url = self::get_uploads_url();
        return rtrim($public_url, '/') . '/' . ltrim($relative, '/');
    }

    public static function recursive_dir_scan($root_path) {
        if (! is_dir($root_path)) {
            return array();
        }

        $files = array();
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root_path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $files[] = $fileinfo->getPathname();
                }
            }
        } catch (Exception $e) {
            // Silently fail on permission errors.
        }

        return $files;
    }

    public static function is_valid_image_extension($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'), true);
    }

    public static function get_matching_original_for_scaled($scaled_file) {
        $scaled_file = self::normalize_path($scaled_file);
        $dir = dirname($scaled_file);
        $file_name = basename($scaled_file);

        if (strpos($file_name, '-scaled.') === false) {
            return false;
        }

        $info = pathinfo($file_name);
        $stem = preg_replace('/-scaled$/', '', $info['filename']);
        $ext = strtolower($info['extension'] ?? '');

        if (empty($stem) || empty($ext)) {
            return false;
        }

        $candidate_names = array($stem . '.' . $ext);
        $alt_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        foreach ($alt_exts as $alt_ext) {
            if ($alt_ext !== $ext) {
                $candidate_names[] = $stem . '.' . $alt_ext;
            }
        }

        foreach ($candidate_names as $candidate_name) {
            $candidate = $dir . '/' . $candidate_name;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return false;
    }
}
