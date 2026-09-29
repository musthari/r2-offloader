<?php

if (! defined('ABSPATH')) {
    exit;
}

class R2_Offloader_Ajax {
    protected $settings;
    protected $logger;

    public function __construct() {
        $this->settings = new R2_Offloader_Settings();
        $this->logger = new R2_Offloader_Logger();
    }

    public function test_connection() {
        check_ajax_referer('r2_offloader_ajax', 'nonce');

        $r2 = new R2_Offloader_R2();
        $result = $r2->test_connection();
        wp_send_json_success($result);
    }

    public function bulk_sync() {
        check_ajax_referer('r2_offloader_ajax', 'nonce');

        $batch = isset($_POST['batch']) ? absint($_POST['batch']) : 20;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        if ($this->is_locked('bulk_sync')) {
            wp_send_json_error(array('message' => __('Proses bulk sync masih berjalan. Tunggu selesai.', 'r2-offloader')));
        }

        $this->lock_process('bulk_sync');

        try {
            $attachments = get_posts(array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $batch,
                'offset'         => $offset,
                'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif', 'image/webp'),
                'fields'         => 'ids',
            ));

            $media = new R2_Offloader_Media();
            $processed = 0;
            foreach ($attachments as $attachment_id) {
                if ($media->process_attachment($attachment_id)) {
                    $processed++;
                }
            }

            $total = $this->count_image_attachments();
            $done = ($offset + $processed) >= $total;

            wp_send_json_success(array(
                'processed' => $processed,
                'offset'    => $offset + $processed,
                'total'     => $total,
                'done'      => $done,
            ));
        } catch (Exception $e) {
            $this->logger->log('AJAX bulk_sync error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => $e->getMessage()));
        } finally {
            $this->unlock_process('bulk_sync');
        }
    }

    public function regen_thumbnails() {
        check_ajax_referer('r2_offloader_ajax', 'nonce');

        $batch = isset($_POST['batch']) ? absint($_POST['batch']) : 20;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        if ($this->is_locked('regen_thumbnails')) {
            wp_send_json_error(array('message' => __('Proses regenerate thumbnail masih berjalan.', 'r2-offloader')));
        }

        $this->lock_process('regen_thumbnails');

        try {
            $failsafe = new R2_Offloader_Failsafe();
            $result = $failsafe->regenerate_thumbnails_batch($batch, $offset);
            $total = $this->count_image_attachments();

            wp_send_json_success(array(
                'processed' => $result['processed'],
                'offset'    => $result['offset'],
                'total'     => $total,
                'done'      => ($result['offset'] >= $total),
            ));
        } catch (Exception $e) {
            $this->logger->log('AJAX regen_thumbnails error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => $e->getMessage()));
        } finally {
            $this->unlock_process('regen_thumbnails');
        }
    }

    public function rollback() {
        check_ajax_referer('r2_offloader_ajax', 'nonce');

        $batch = isset($_POST['batch']) ? absint($_POST['batch']) : 20;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        if ($this->is_locked('rollback')) {
            wp_send_json_error(array('message' => __('Proses rollback masih berjalan.', 'r2-offloader')));
        }

        $this->lock_process('rollback');

        try {
            $failsafe = new R2_Offloader_Failsafe();
            $result = $failsafe->rollback_batch($batch, $offset);
            $total = $this->count_image_attachments();

            wp_send_json_success(array(
                'processed' => $result['processed'],
                'offset'    => $result['offset'],
                'total'     => $total,
                'done'      => ($result['offset'] >= $total),
            ));
        } catch (Exception $e) {
            $this->logger->log('AJAX rollback error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => $e->getMessage()));
        } finally {
            $this->unlock_process('rollback');
        }
    }

    public function cleanup_scaled() {
        check_ajax_referer('r2_offloader_ajax', 'nonce');

        $media = new R2_Offloader_Media();
        $local_deleted = $media->cleanup_scaled_local_files(true);
        $r2_deleted = $media->cleanup_scaled_r2_files();

        $this->logger->log('Cleanup -scaled: ' . $local_deleted . ' local + ' . $r2_deleted . ' R2 deleted', 'success');

        wp_send_json_success(array(
            'message' => __('Cleanup selesai. File -scaled lokal dan objek R2 sudah dihapus sesuai kondisi.', 'r2-offloader'),
            'local_deleted' => $local_deleted,
            'r2_deleted' => $r2_deleted,
        ));
    }

    public function get_queue_status() {
        check_ajax_referer('r2_offloader_ajax', 'nonce');

        $queue = new R2_Offloader_Queue();
        $stats = $queue->get_queue_stats();

        wp_send_json_success($stats);
    }

    protected function count_image_attachments() {
        $query = new WP_Query(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        return (int) $query->post_count;
    }

    protected function is_locked($name) {
        $lock = get_option('r2_offloader_lock', array());
        if (empty($lock[$name])) {
            return false;
        }

        $locked_at = (int) $lock[$name];
        return (time() - $locked_at) < 600;
    }

    protected function lock_process($name) {
        $lock = get_option('r2_offloader_lock', array());
        $lock[$name] = time();
        update_option('r2_offloader_lock', $lock);
    }

    protected function unlock_process($name) {
        $lock = get_option('r2_offloader_lock', array());
        if (isset($lock[$name])) {
            unset($lock[$name]);
        }
        update_option('r2_offloader_lock', $lock);
    }
}
