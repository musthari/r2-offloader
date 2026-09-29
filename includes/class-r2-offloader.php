<?php

if (! defined('ABSPATH')) {
    exit;
}

final class R2_Offloader {
    protected static $instance = null;

    protected $settings;
    protected $media;
    protected $r2;
    protected $admin;
    protected $ajax;
    protected $failsafe;
    protected $queue;
    protected $logger;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init();
    }

    private function load_dependencies() {
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-settings.php';
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-logger.php';
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-utils.php';
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-r2.php';
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-queue.php';
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-media.php';
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-admin.php';
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-ajax.php';
        require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-failsafe.php';

        if (defined('WP_CLI') && WP_CLI) {
            require_once R2_OFFLOADER_DIR . 'includes/class-r2-offloader-cli.php';
        }
    }

    private function init() {
        $this->logger   = new R2_Offloader_Logger();
        $this->settings = new R2_Offloader_Settings();
        $this->media    = new R2_Offloader_Media();
        $this->r2       = new R2_Offloader_R2();
        $this->queue    = new R2_Offloader_Queue();
        $this->admin    = new R2_Offloader_Admin();
        $this->ajax     = new R2_Offloader_Ajax();
        $this->failsafe = new R2_Offloader_Failsafe();

        add_action('admin_init', array($this, 'register_admin_settings'));
        add_action('init', array($this, 'register_hooks'));

        add_action('r2_offloader_process_queue', array($this->queue, 'process_batch'));
        add_action('r2_offloader_cleanup_logs', array($this->logger, 'cleanup_old_logs'));

        add_action('add_attachment', array($this->media, 'on_attachment_added'));
        add_filter('wp_generate_attachment_metadata', array($this->media, 'maybe_convert_and_offload_attachment'), 999, 2);
        add_filter('wp_get_attachment_url', array($this->media, 'filter_attachment_url'), 999, 2);
        add_filter('wp_get_attachment_image_src', array($this->media, 'filter_attachment_image_src'), 999, 4);

        add_action('wp_ajax_r2_offloader_test_connection', array($this->ajax, 'test_connection'));
        add_action('wp_ajax_r2_offloader_bulk_sync', array($this->ajax, 'bulk_sync'));
        add_action('wp_ajax_r2_offloader_regen_thumbnails', array($this->ajax, 'regen_thumbnails'));
        add_action('wp_ajax_r2_offloader_rollback', array($this->ajax, 'rollback'));
        add_action('wp_ajax_r2_offloader_cleanup_scaled', array($this->ajax, 'cleanup_scaled'));
        add_action('wp_ajax_r2_offloader_get_queue_status', array($this->ajax, 'get_queue_status'));

        add_action('r2_offloader_activate', array($this, 'on_activate'));
        add_action('r2_offloader_deactivate', array($this, 'on_deactivate'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('r2-offloader', 'R2_Offloader_CLI');
        }
    }

    public function register_admin_settings() {
        register_setting('r2_offloader_settings_group', 'r2_offloader_settings');
    }

    public function register_hooks() {
        // Additional hooks for init.
    }

    public function on_activate() {
        // Schedule cron jobs.
        if (! wp_next_scheduled('r2_offloader_process_queue')) {
            wp_schedule_event(time(), 'twicedaily', 'r2_offloader_process_queue');
        }

        if (! wp_next_scheduled('r2_offloader_cleanup_logs')) {
            wp_schedule_event(time() + 86400, 'daily', 'r2_offloader_cleanup_logs');
        }
    }

    public function on_deactivate() {
        // Remove cron jobs.
        wp_clear_scheduled_hook('r2_offloader_process_queue');
        wp_clear_scheduled_hook('r2_offloader_cleanup_logs');
    }

    public function get_settings() {
        return $this->settings->get_all();
    }

    public function get_media() {
        return $this->media;
    }

    public function get_r2() {
        return $this->r2;
    }

    public function get_queue() {
        return $this->queue;
    }

    public function get_logger() {
        return $this->logger;
    }
}
