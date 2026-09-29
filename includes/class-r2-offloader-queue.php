<?php

if (! defined('ABSPATH')) {
    exit;
}

class R2_Offloader_Queue {
    protected $table_name;
    protected $settings;
    protected $logger;
    protected $r2;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'r2_offloader_queue';
        $this->settings = new R2_Offloader_Settings();
        $this->logger = new R2_Offloader_Logger();
        $this->r2 = new R2_Offloader_R2();
        $this->create_table();
    }

    protected function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            task_type VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            retries INT UNSIGNED NOT NULL DEFAULT 0,
            error_message LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY status (status),
            KEY task_type (task_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function enqueue($attachment_id, $task_type = 'offload') {
        global $wpdb;

        return $wpdb->insert(
            $this->table_name,
            array(
                'attachment_id' => $attachment_id,
                'task_type'     => $task_type,
                'status'       => 'pending',
            ),
            array('%d', '%s', '%s')
        );
    }

    public function get_pending_tasks($limit = 20) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE status = 'pending' AND retries < %d ORDER BY created_at ASC LIMIT %d",
                $this->settings->get('r2_max_retries'),
                $limit
            )
        );
    }

    public function mark_as_processing($task_id) {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            array('status' => 'processing'),
            array('id' => $task_id),
            array('%s'),
            array('%d')
        );
    }

    public function mark_as_complete($task_id) {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            array('status' => 'complete'),
            array('id' => $task_id),
            array('%s'),
            array('%d')
        );
    }

    public function mark_as_failed($task_id, $error_message = '') {
        global $wpdb;

        $retries = $wpdb->get_var(
            $wpdb->prepare("SELECT retries FROM {$this->table_name} WHERE id = %d", $task_id)
        );
        $retries = (int) $retries + 1;
        $new_status = $retries >= $this->settings->get('r2_max_retries') ? 'failed' : 'pending';

        return $wpdb->update(
            $this->table_name,
            array(
                'status'          => $new_status,
                'retries'        => $retries,
                'error_message' => $error_message,
            ),
            array('id' => $task_id),
            array('%s', '%d', '%s'),
            array('%d')
        );
    }

    public function process_batch($batch_size = 20) {
        $tasks = $this->get_pending_tasks($batch_size);

        if (empty($tasks)) {
            return 0;
        }

        $processed = 0;
        $media = new R2_Offloader_Media();

        foreach ($tasks as $task) {
            $this->mark_as_processing($task->id);

            try {
                if ($task->task_type === 'offload') {
                    $success = $media->process_attachment($task->attachment_id);
                } else {
                    $success = false;
                }

                if ($success) {
                    $this->mark_as_complete($task->id);
                    $this->logger->log(
                        'Task completed successfully',
                        'success',
                        $task->attachment_id
                    );
                    $processed++;
                } else {
                    throw new Exception('Processing failed');
                }
            } catch (Exception $e) {
                $this->mark_as_failed($task->id, $e->getMessage());
                $this->logger->log(
                    'Task failed: ' . $e->getMessage(),
                    'error',
                    $task->attachment_id
                );
            }
        }

        return $processed;
    }

    public function get_queue_stats() {
        global $wpdb;

        $pending = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'pending'"
        );
        $processing = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'processing'"
        );
        $complete = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'complete'"
        );
        $failed = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'failed'"
        );

        return array(
            'pending'    => (int) $pending,
            'processing' => (int) $processing,
            'complete'   => (int) $complete,
            'failed'     => (int) $failed,
        );
    }
}
