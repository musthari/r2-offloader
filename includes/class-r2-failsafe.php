<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class R2_Failsafe {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_r2_failsafe_rollback_batch', array( $this, 'ajax_failsafe_rollback_batch' ) );
        add_action( 'wp_ajax_r2_fast_migrate_db', array( $this, 'ajax_fast_migrate_db' ) );
    }

    public function ajax_fast_migrate_db() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        global $wpdb;
        $cdn_domain = rtrim( get_option( 'r2_cdn_domain' ), '/' );$upload_dir = wp_upload_dir();
        $base_url   = rtrim($upload_dir['baseurl'], '/' );

        if ( empty( $cdn_domain ) ) {
            wp_send_json_error( array( 'message' => 'Custom Domain CDN URL belum diatur.' ) );
        }

        $wpdb->query( "
            INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
            SELECT ID, '_r2_offloaded', '1' 
            FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
        " );

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $base_url,$cdn_domain, '%' . $wpdb->esc_like($base_url ) . '%'
        ) );

        $stats = R2_Core::get_stats();
        wp_send_json_success( array(
            'message'  => 'Fast Migration Berhasil!',
            'affected' => $affected,
            'stats'    => $stats
        ) );
    }

    public function ajax_failsafe_rollback_batch() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        global $wpdb;
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 20;
        $cdn_domain = rtrim( get_option( 'r2_cdn_domain' ), '/' );$upload_dir = wp_upload_dir();
        $base_url   = rtrim($upload_dir['baseurl'], '/' );

        $query = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_r2_offloaded' AND pm.meta_value = '1')
            WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
            LIMIT %d
        ";

        $attachments =$wpdb->get_col( $wpdb->prepare($query, $batch_size ) );$processed   = 0;

        if ( ! empty( $attachments ) && ! empty( $cdn_domain ) ) {
            foreach ( $attachments as$post_id ) {
                $relative_path = get_post_meta($post_id, '_wp_attached_file', true );
                if ( $relative_path ) {
                    $cdn_file_url   =$cdn_domain . '/' . ltrim( $relative_path, '/' );$local_file_url = $base_url . '/' . ltrim( $relative_path, '/' );

                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                        $cdn_file_url,$local_file_url, '%' . $wpdb->esc_like($cdn_file_url ) . '%'
                    ) );
                }

                delete_post_meta( $post_id, '_r2_offloaded' );$processed++;
            }
        }

        $stats = R2_Core::get_stats();
        wp_send_json_success( array( 'processed' => $processed, 'pending' =>$stats['total_r2'] ) );
    }

    public static function render_tab_failsafe() {
        $stats = R2_Core::get_stats();
        ?>
        <div class="card r2-card-failsafe">
            <h2>Failsafe & Fitur Migrasi Cepat Database</h2>
            <p>Fitur untuk migrasi instan jika gambar sudah ada di R2 dan pemulihan URL ke server lokal.</p>

            <hr style="margin:20px 0;">

            <div style="margin-bottom:30px;">
                <h3>Fitur Fast Migration (Migrasi Instan 150k+ Gambar)</h3>
                <p class="description">
                    Gunakan fitur ini jika seluruh file gambar sebenarnya <strong>SUDAH TERUNGGAH ke Cloudflare R2</strong> (misal via Rclone/plugin lama). Sistem akan langsung menandai status di DB dan mengganti URL tanpa melakukan upload ulang file.
                </p>
                <button type="button" id="btn-fast-migrate" class="button button-primary button-hero" style="margin-top:10px; background:#27ae60; border-color:#27ae60;">
                    Eksekusi Fast Migration Sekarang (Instan)
                </button>
                <span id="fast-migrate-status" style="margin-left:15px; font-weight:bold;"></span>
            </div>

            <hr style="margin:20px 0;">

            <div>
                <h3>Kembalikan URL Gambar di Database ke Server Lokal</h3>
                <p class="description">
                    Menimpa kembali URL gambar di dalam artikel (`post_content`) dari domain CDN ke URL lokal server WordPress Anda.
                </p>
                <button type="button" id="btn-failsafe-rollback" class="button button-secondary button-hero" style="margin-top:10px;" <?php echo $stats['total_r2'] === 0 ? 'disabled' : ''; ?>>
                    Kembalikan URL Gambar ke Server Lokal
                </button>
                <div id="rollback-progress-box" style="margin-top:15px; display:none;">
                    <div class="r2-progress-outer">
                        <div id="rollback-progress-bar" class="r2-progress-inner" style="background:#46b450;">0%</div>
                    </div>
                    <p id="rollback-status-msg" class="r2-status-msg"></p>
                </div>
            </div>
        </div>
        <?php
    }
}