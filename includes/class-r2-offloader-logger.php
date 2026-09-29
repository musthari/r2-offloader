<?php

if (! defined('ABSPATH')) {
    exit;
}

class R2_Offloader_Logger {
    protected $log_dir;

    public function __construct() {
        $uploads = wp_upload_dir();
        $this->log_dir = $uploads['basedir'] . '/r2-offloader-logs';
        $this->init_log_dir();
    }

    protected function init_log_dir() {
        if (! is_dir($this->log_dir)) {
            @mkdir($this->log_dir, 0755, true);
            file_put_contents($this->log_dir . '/.htaccess', 'deny from all');
        }
    }

    public function log($message, $level = 'info', $attachment_id = null) {
        $settings = new R2_Offloader_Settings();
        if (empty($settings->get('r2_log_enabled'))) {
            return;
        }

        $log_file = $this->log_dir . '/r2-offloader-' . gmdate('Y-m-d') . '.log';
        $timestamp = gmdate('Y-m-d H:i:s');
        $log_line = "[{$timestamp}] [{$level}]";

        if (! empty($attachment_id)) {
            $log_line .= " [Attachment #{$attachment_id}]";
        }

        $log_line .= " {$message}\n";
        @file_put_contents($log_file, $log_line, FILE_APPEND);
    }

    public function get_logs($limit = 100) {
        $log_file = $this->log_dir . '/r2-offloader-' . gmdate('Y-m-d') . '.log';

        if (! file_exists($log_file)) {
            return array();
        }

        $lines = file($log_file);
        return array_slice($lines, -$limit);
    }

    public function cleanup_old_logs($days = 7) {
        $cutoff = time() - ($days * 86400);
        $files = glob($this->log_dir . '/r2-offloader-*.log');

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
