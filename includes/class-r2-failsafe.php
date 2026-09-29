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
        add_action( 'wp_ajax_r2_failsafe_regen_batch', array( $this, 'ajax_failsafe_regen_batch' ) );
        add_action( 'wp_ajax_r2_failsafe_rollback_batch', array( $this, 'ajax_failsafe_rollback_batch' ) );
        add_action( 'wp_ajax_r2_failsafe_clean_scaled_batch', array( $this, 'ajax_failsafe_clean_scaled_batch' ) );
        
        // Action AJAX Baru: Fast Direct DB Migration
        add_action( 'wp_ajax_r2_fast_migrate_db', array( $this, 'ajax_fast_migrate_db' ) );
    }

    /**
     * AJAX Fast Migration Direct DB
     */
    public function ajax_fast_migrate_db() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        global $wpdb;
        $cdn_domain = rtrim( get_option( 'r2_cdn_domain' ), '/' );
        $upload_dir = wp_upload_dir();
        $base_url   = rtrim( $upload_dir['baseurl'], '/' );

        if ( empty( $cdn_domain ) ) {
            wp_send_json_error( array( 'message' => 'Custom Domain CDN URL belum diatur.' ) );
        }

        // Tag Meta _r2_offloaded
        $wpdb->query( "
            INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
            SELECT ID, '_r2_offloaded', '1' 
            FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
        " );

        // Replace URL di Database
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $base_url, $cdn_domain, '%' . $wpdb->esc_like( $base_url ) . '%'
        ) );

        $stats = R2_Core::get_stats();
        wp_send_json_success( array(
            'message'  => 'Fast Migration Berhasil!',
            'affected' => $affected,
            'stats'    => $stats
        ) );
    }

    public function ajax_failsafe_regen_batch() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        global $wpdb;
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 10;

        $query = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_r2_offloaded' AND pm.meta_value = '1')
            LEFT JOIN {$wpdb->postmeta} pm_regen ON (p.ID = pm_regen.post_id AND pm_regen.meta_key = '_r2_regen_done')
            WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
            AND (pm_regen.meta_value IS NULL OR pm_regen.meta_value != '1')
            LIMIT %d
        ";

        $attachments = $wpdb->get_col( $wpdb->prepare( $query, $batch_size ) );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        $processed = 0;
        if ( ! empty( $attachments ) ) {
            foreach ( $attachments as $post_id ) {
                $file_path = get_attached_file( $post_id );
                if ( file_exists( $file_path ) ) {
                    $metadata = wp_generate_attachment_metadata( $post_id, $file_path );
                    wp_update_attachment_metadata( $post_id, $metadata );
                }
                update_post_meta( $post_id, '_r2_regen_done', 1 );
                $processed++;
            }
        }

        $total_offloaded = (int) $wpdb->get_var( "
            SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_r2_offloaded' AND pm.meta_value = '1')
            WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
        " );

        $remaining = (int) $wpdb->get_var( "
            SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_r2_offloaded' AND pm.meta_value = '1')
            LEFT JOIN {$wpdb->postmeta} pm_regen ON (p.ID = pm_regen.post_id AND pm_regen.meta_key = '_r2_regen_done')
            WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
            AND (pm_regen.meta_value IS NULL OR pm_regen.meta_value != '1')
        " );

        wp_send_json_success( array(
            'processed'       => $processed,
            'remaining'       => $remaining,
            'total_offloaded' => $total_offloaded
        ) );
    }

    public function ajax_failsafe_rollback_batch() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        global $wpdb;
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 20;
        $cdn_domain = rtrim( get_option( 'r2_cdn_domain' ), '/' );
        $upload_dir = wp_upload_dir();
        $base_url   = rtrim( $upload_dir['baseurl'], '/' );

        $query = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_r2_offloaded' AND pm.meta_value = '1')
            WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
            LIMIT %d
        ";

        $attachments = $wpdb->get_col( $wpdb->prepare( $query, $batch_size ) );
        $processed   = 0;

        if ( ! empty( $attachments ) && ! empty( $cdn_domain ) ) {
            foreach ( $attachments as $post_id ) {
                $relative_path = get_post_meta( $post_id, '_wp_attached_file', true );
                if ( $relative_path ) {
                    $cdn_file_url   = $cdn_domain . '/' . ltrim( $relative_path, '/' );
                    $local_file_url = $base_url . '/' . ltrim( $relative_path, '/' );

                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                        $cdn_file_url, $local_file_url, '%' . $wpdb->esc_like( $cdn_file_url ) . '%'
                    ) );
                }

                delete_post_meta( $post_id, '_r2_offloaded' );
                delete_post_meta( $post_id, '_r2_regen_done' );
                $processed++;
            }
        }

        $stats = R2_Core::get_stats();
        wp_send_json_success( array( 'processed' => $processed, 'pending' => $stats['total_r2'] ) );
    }

    public function ajax_failsafe_clean_scaled_batch() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        global $wpdb;
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 15;
        $core       = R2_Core::get_instance();
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $base_url   = trailingslashit( $upload_dir['baseurl'] );
        $cdn_domain = rtrim( get_option( 'r2_cdn_domain' ), '/' );

        $query = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_scaled ON (p.ID = pm_scaled.post_id AND pm_scaled.meta_key = '_r2_scaled_cleaned')
            WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
            AND (pm_scaled.meta_value IS NULL OR pm_scaled.meta_value != '1')
            LIMIT %d
        ";

        $attachments = $wpdb->get_col( $wpdb->prepare( $query, $batch_size ) );
        $processed = 0;
        $cleaned   = 0;
        $skipped   = 0;

        if ( ! empty( $attachments ) ) {
            foreach ( $attachments as $post_id ) {
                $relative_path = get_post_meta( $post_id, '_wp_attached_file', true );

                if ( $relative_path && strpos( $relative_path, '-scaled.' ) !== false ) {
                    $original_relative_path = preg_replace( '/-scaled\./', '.', $relative_path );
                    
                    $scaled_local_file   = $base_dir . $relative_path;
                    $original_local_file = $base_dir . $original_relative_path;

                    if ( file_exists( $original_local_file ) ) {
                        $is_offloaded = get_post_meta( $post_id, '_r2_offloaded', true );
                        if ( $is_offloaded ) {
                            $core->upload_file_to_r2( $original_local_file, $original_relative_path );
                        }

                        $scaled_url_local   = $base_url . ltrim( $relative_path, '/' );
                        $original_url_local = $base_url . ltrim( $original_relative_path, '/' );

                        $wpdb->query( $wpdb->prepare(
                            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                            $scaled_url_local, $original_url_local, '%' . $wpdb->esc_like( $scaled_url_local ) . '%'
                        ) );

                        if ( ! empty( $cdn_domain ) ) {
                            $scaled_url_cdn   = $cdn_domain . '/' . ltrim( $relative_path, '/' );
                            $original_url_cdn = $cdn_domain . '/' . ltrim( $original_relative_path, '/' );

                            $wpdb->query( $wpdb->prepare(
                                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                                $scaled_url_cdn, $original_url_cdn, '%' . $wpdb->esc_like( $scaled_url_cdn ) . '%'
                            ) );
                        }

                        update_post_meta( $post_id, '_wp_attached_file', $original_relative_path );
                        $core->delete_file_from_r2( $relative_path );

                        if ( file_exists( $scaled_local_file ) ) {
                            @unlink( $scaled_local_file );
                        }

                        $cleaned++;
                    } else {
                        $skipped++;
                    }
                }

                update_post_meta( $post_id, '_r2_scaled_cleaned', 1 );
                $processed++;
            }
        }

        $remaining = (int) $wpdb->get_var( "
            SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_scaled ON (p.ID = pm_scaled.post_id AND pm_scaled.meta_key = '_r2_scaled_cleaned')
            WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
            AND (pm_scaled.meta_value IS NULL OR pm_scaled.meta_value != '1')
        " );

        $total_images = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );

        wp_send_json_success( array(
            'processed'    => $processed,
            'cleaned'      => $cleaned,
            'skipped'      => $skipped,
            'remaining'    => $remaining,
            'total_images' => $total_images
        ) );
    }

    public static function render_tab_failsafe() {
        $stats = R2_Core::get_stats();
        ?>
        <div class="card" style="margin-top:20px; padding:25px; background:#fff; max-width:850px; border-left:4px solid #d63638;">
            <h2>Failsafe & Fitur Migrasi Cepat Database</h2>
            <p>Fitur untuk migrasi instan jika gambar sudah ada di R2, pembersihan file redundan, dan pemulihan ke server lokal.</p>

            <hr style="margin:20px 0;">

            <!-- FITUR FAST MIGRATION -->
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

            <div style="margin-bottom:30px;">
                <h3>Fitur Pembersihan File Redundan (-scaled)</h3>
                <p class="description">
                    Mencari file <code>-scaled</code> lama, mengganti URL ke original, dan menghapus file <code>-scaled</code> dari Lokal & R2.
                </p>
                <button type="button" id="btn-clean-scaled" class="button button-secondary button-hero" style="margin-top:10px;">
                    Bersihkan File -scaled Lama
                </button>
                <div id="scaled-progress-box" style="margin-top:15px; display:none;">
                    <div style="background:#e0e0e0; border-radius:4px; height:22px; width:100%; overflow:hidden;">
                        <div id="scaled-progress-bar" style="background:#f39c12; height:100%; width:0%; text-align:center; color:#fff; font-size:12px; line-height:22px;">0%</div>
                    </div>
                    <p id="scaled-status-msg" style="margin-top:8px; font-weight:bold;"></p>
                </div>
            </div>

            <hr style="margin:20px 0;">

            <div style="margin-bottom:30px;">
                <h3>Langkah 1: Generate Ulang Thumbnail Fisik Lokal</h3>
                <button type="button" id="btn-failsafe-regen" class="button button-primary button-hero" style="margin-top:10px;">
                    1. Klik untuk Generate Thumbnail Lokal
                </button>
                <div id="regen-progress-box" style="margin-top:15px; display:none;">
                    <div style="background:#e0e0e0; border-radius:4px; height:22px; width:100%; overflow:hidden;">
                        <div id="regen-progress-bar" style="background:#007cba; height:100%; width:0%; text-align:center; color:#fff; font-size:12px; line-height:22px;">0%</div>
                    </div>
                    <p id="regen-status-msg" style="margin-top:8px; font-weight:bold;"></p>
                </div>
            </div>

            <hr style="margin:20px 0;">

            <div>
                <h3>Langkah 2: Kembalikan URL Gambar di Database ke Lokal</h3>
                <button type="button" id="btn-failsafe-rollback" class="button button-secondary button-hero" style="margin-top:10px;" <?php echo $stats['total_r2'] === 0 ? 'disabled' : ''; ?>>
                    2. Kembalikan URL Gambar ke Server Lokal
                </button>
                <div id="rollback-progress-box" style="margin-top:15px; display:none;">
                    <div style="background:#e0e0e0; border-radius:4px; height:22px; width:100%; overflow:hidden;">
                        <div id="rollback-progress-bar" style="background:#46b450; height:100%; width:0%; text-align:center; color:#fff; font-size:12px; line-height:22px;">0%</div>
                    </div>
                    <p id="rollback-status-msg" style="margin-top:8px; font-weight:bold;"></p>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#btn-fast-migrate').click(function() {
                if (!confirm('Gunakan fitur ini jika seluruh file SUDAH TERUNGGAH di R2. Sistem akan langsung memperbarui URL di Database secara instan. Lanjutkan?')) return;
                
                var $btn = $(this);
                var $status = $('#fast-migrate-status');
                $btn.prop('disabled', true);
                $status.text('Memproses Direct DB Migration...').css('color', '#007cba');

                $.post(ajaxurl, {
                    action: 'r2_fast_migrate_db',
                    nonce: '<?php echo wp_create_nonce("r2_admin_nonce"); ?>'
                }, function(res) {
                    $btn.prop('disabled', false);
                    if (res.success) {
                        $status.text(res.data.message + ' URL diperbarui: ' + res.data.affected + ' baris.').css('color', 'green');
                        location.reload();
                    } else {
                        $status.text('Gagal: ' + res.data.message).css('color', 'red');
                    }
                });
            });
        });
        </script>
        <?php
    }
}