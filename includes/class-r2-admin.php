<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class R2_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_r2_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_r2_bulk_sync_batch', array( $this, 'ajax_bulk_sync_batch' ) );
        
        // AJAX Action Baru: Scan Directory & Sync File Non-Media Library
        add_action( 'wp_ajax_r2_scan_directory_batch', array( $this, 'ajax_scan_directory_batch' ) );
    }

    public function register_admin_menu() {
        add_management_page( 'R2 Offloader', 'R2 Offloader', 'manage_options', 'r2-media-offloader', array( $this, 'render_admin_page' ) );
    }

    public function register_settings() {
        register_setting( 'r2_offloader_settings', 'r2_account_id' );
        register_setting( 'r2_offloader_settings', 'r2_access_key' );
        register_setting( 'r2_offloader_settings', 'r2_secret_key' );
        register_setting( 'r2_offloader_settings', 'r2_bucket_name' );
        register_setting( 'r2_offloader_settings', 'r2_cdn_domain' );
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );
        
        $core   = R2_Core::get_instance();
        $result = $core->ping_r2_bucket();

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => 'Koneksi ke Cloudflare R2 Berhasil!' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Koneksi Gagal: ' . $result['error'] ) );
        }
    }

    public function ajax_bulk_sync_batch() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        global $wpdb;
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 20;

        $query = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_r2_offloaded')
            WHERE p.post_type = 'attachment' 
            AND p.post_mime_type LIKE 'image/%'
            AND (pm.meta_value IS NULL OR pm.meta_value != '1')
            LIMIT %d
        ";

        $attachments = $wpdb->get_col( $wpdb->prepare( $query, $batch_size ) );
        $processed   = 0;
        $core        = R2_Core::get_instance();

        if ( ! empty( $attachments ) ) {
            foreach ( $attachments as $post_id ) {
                $core->process_single_attachment_offload( $post_id );
                $processed++;
            }
        }

        $stats = R2_Core::get_stats();
        wp_send_json_success( array(
            'processed' => $processed,
            'pending'   => $stats['pending'],
            'total_r2'  => $stats['total_r2'],
            'total_wp'  => $stats['total_wp']
        ) );
    }

    /**
     * FITUR BARU: Memindai Folder Physical Uploads & Sinkronisasi File Langsung
     */
    public function ajax_scan_directory_batch() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        $offset     = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 20;
        
        $upload_dir = wp_upload_dir();$base_dir   = trailingslashit( $upload_dir['basedir'] );$core       = R2_Core::get_instance();

        // Ambil daftar file dari folder /wp-content/uploads/
        if ( false === ( $file_list = get_transient( 'r2_scan_file_cache' ) ) ) {$file_list = array();
            if ( file_exists( $base_dir ) ) {$iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ( $iterator as$file ) {
                    if ( $file->isFile() ) {
                        $ext = strtolower($file->getExtension() );
                        if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' ) ) ) {$file_list[] = str_replace( $base_dir, '',$file->getPathname() );
                        }
                    }
                }
            }
            set_transient( 'r2_scan_file_cache', $file_list, 3600 );
        }

        $total_files = count( $file_list );$slice       = array_slice( $file_list,$offset, $batch_size );$uploaded    = 0;
        $skipped     = 0;

        foreach ( $slice as $relative_path ) {$local_file_path = $base_dir .$relative_path;

            // Jika file sudah ada di R2, fungsi upload_file_to_r2 akan otomatis melewatinya (skip)
            if ( $core->does_file_exist_in_r2( $relative_path ) ) {$skipped++;
            } else {
                if ( $core->upload_file_to_r2($local_file_path, $relative_path ) ) {$uploaded++;
                }
            }
        }

        $next_offset =$offset + count( $slice );$is_finished = $next_offset >=$total_files;

        if ( $is_finished ) {
            delete_transient( 'r2_scan_file_cache' );
        }

        wp_send_json_success( array(
            'total_files' => $total_files,
            'next_offset' => $next_offset,
            'uploaded'    => $uploaded,
            'skipped'     => $skipped,
            'is_finished' => $is_finished
        ) );
    }

    public function render_admin_page() {
        $stats      = R2_Core::get_stats();$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'dashboard';
        ?>
        <div class="wrap">
            <h1>Cloudflare R2 Media Offloader <span style="font-size:12px; color:#666; font-weight:normal;">v<?php echo R2_OFFLOADER_VERSION; ?></span></h1>

            <h2 class="nav-tab-wrapper" style="margin-top:15px;">
                <a href="?page=r2-media-offloader&tab=dashboard" class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">Dashboard & Bulk Sync</a>
                <a href="?page=r2-media-offloader&tab=failsafe" class="nav-tab <?php echo $active_tab === 'failsafe' ? 'nav-tab-active' : ''; ?>">Failsafe / Rollback Lokal</a>
                <a href="?page=r2-media-offloader&tab=guide_cloudflare" class="nav-tab <?php echo $active_tab === 'guide_cloudflare' ? 'nav-tab-active' : ''; ?>">Panduan Setting R2</a>
                <a href="?page=r2-media-offloader&tab=guide_wpcli" class="nav-tab <?php echo $active_tab === 'guide_wpcli' ? 'nav-tab-active' : ''; ?>">Panduan WP-CLI</a>
            </h2>

            <?php if ( 'dashboard' === $active_tab ) : ?>
                <div style="display:flex; gap:20px; margin-top:20px;">
                    <div class="card" style="flex:1; padding:20px; border-left:4px solid #007cba; margin:0;">
                        <h3 style="margin-top:0;">Total Gambar di WordPress</h3>
                        <p id="stat-total-wp" style="font-size:24px; font-weight:bold; margin:0;"><?php echo number_format_i18n( $stats['total_wp'] ); ?></p>
                    </div>
                    <div class="card" style="flex:1; padding:20px; border-left:4px solid #46b450; margin:0;">
                        <h3 style="margin-top:0;">Gambar Ter-offload di R2</h3>
                        <p id="stat-total-r2" style="font-size:24px; font-weight:bold; margin:0;"><?php echo number_format_i18n( $stats['total_r2'] ); ?></p>
                    </div>
                    <div class="card" style="flex:1; padding:20px; border-left:4px solid #d63638; margin:0;">
                        <h3 style="margin-top:0;">Sisa Gambar Belum Sync</h3>
                        <p id="stat-pending" style="font-size:24px; font-weight:bold; margin:0;"><?php echo number_format_i18n( $stats['pending'] ); ?></p>
                    </div>
                </div>

                <div class="card" style="margin-top:20px; padding:20px; background:#fff; max-width:800px;">
                    <h2>1. Bulk Upload / Sync Media Library ke Cloudflare R2</h2>
                    <p>Gunakan fitur ini untuk mensinkronisasikan gambar yang terdaftar di Database Media Library WordPress secara bertahap.</p>
                    
                    <div style="margin-top:15px; display:flex; align-items:center; gap:15px;">
                        <button type="button" id="start-bulk-sync-btn" class="button button-primary button-hero" <?php echo $stats['pending'] === 0 ? 'disabled' : ''; ?>>
                            Mulai Bulk Sync Media Library
                        </button>
                        <button type="button" id="stop-bulk-sync-btn" class="button button-secondary button-hero" style="display:none;">
                            Hentikan Sync
                        </button>
                    </div>

                    <div id="sync-progress-container" style="margin-top:20px; display:none;">
                        <div style="background:#e0e0e0; border-radius:4px; height:22px; width:100%; overflow:hidden;">
                            <div id="sync-progress-bar" style="background:#2271b1; height:100%; width:0%; text-align:center; color:#fff; font-size:12px; line-height:22px;">0%</div>
                        </div>
                        <p id="sync-status-msg" style="margin-top:8px; font-weight:bold;"></p>
                    </div>
                </div>

                <!-- FITUR BARU: SCAN DIREKTORI SERVER -->
                <div class="card" style="margin-top:20px; padding:20px; background:#fff; max-width:800px; border-left:4px solid #f39c12;">
                    <h2>2. Scan & Sync Direktori Uploads Server (/wp-content/uploads/)</h2>
                    <p>Fitur ini akan memindai seluruh file gambar fisik di folder server (termasuk berkas hasil FTP / migrasi manual yang tidak terdaftar di Media Library) dan mengunggahnya ke R2.</p>
                    
                    <div style="margin-top:15px;">
                        <button type="button" id="btn-scan-dir" class="button button-secondary button-hero">
                            Mulai Scan Folder Direktori Web
                        </button>
                    </div>

                    <div id="scan-progress-box" style="margin-top:20px; display:none;">
                        <div style="background:#e0e0e0; border-radius:4px; height:22px; width:100%; overflow:hidden;">
                            <div id="scan-progress-bar" style="background:#f39c12; height:100%; width:0%; text-align:center; color:#fff; font-size:12px; line-height:22px;">0%</div>
                        </div>
                        <p id="scan-status-msg" style="margin-top:8px; font-weight:bold;"></p>
                    </div>
                </div>

                <div class="card" style="margin-top:20px; padding:20px; background:#fff; max-width:800px;">
                    <h2>Pengaturan Kredensial Cloudflare R2</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields( 'r2_offloader_settings' ); ?>
                        <table class="form-table">
                            <tr>
                                <th>Account ID</th>
                                <td><input type="text" name="r2_account_id" value="<?php echo esc_attr( get_option('r2_account_id') ); ?>" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th>Access Key ID</th>
                                <td><input type="text" name="r2_access_key" value="<?php echo esc_attr( get_option('r2_access_key') ); ?>" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th>Secret Access Key</th>
                                <td><input type="password" name="r2_secret_key" value="<?php echo esc_attr( get_option('r2_secret_key') ); ?>" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th>Bucket Name</th>
                                <td><input type="text" name="r2_bucket_name" value="<?php echo esc_attr( get_option('r2_bucket_name') ); ?>" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th>Custom Domain CDN URL</th>
                                <td><input type="url" name="r2_cdn_domain" value="<?php echo esc_attr( get_option('r2_cdn_domain') ); ?>" class="regular-text" placeholder="https://media.domainanda.com" required></td>
                            </tr>
                        </table>
                        <?php submit_button('Simpan Pengaturan'); ?>
                    </form>

                    <hr style="margin:20px 0;">

                    <h3>Uji Koneksi API</h3>
                    <button type="button" id="btn-test-r2" class="button button-secondary">Tes Koneksi ke Cloudflare R2</button>
                    <span id="test-r2-result" style="margin-left:15px; font-weight:bold;"></span>
                </div>

            <?php elseif ( 'failsafe' === $active_tab ) : ?>
                <?php R2_Failsafe::render_tab_failsafe(); ?>
            <?php elseif ( 'guide_cloudflare' === $active_tab ) : ?>
                <?php R2_Guides::render_tab_guide_cloudflare(); ?>
            <?php elseif ( 'guide_wpcli' === $active_tab ) : ?>
                <?php R2_Guides::render_tab_guide_wpcli(); ?>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Tes Koneksi
            $('#btn-test-r2').click(function() {
                var $btn =$(this);
                var $res =$('#test-r2-result');
                $res.text('Menghubungkan...').css('color', '#666');
                $btn.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'r2_test_connection',
                    nonce: '<?php echo wp_create_nonce("r2_admin_nonce"); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $res.text(response.data.message).css('color', 'green');
                    } else {
                        $res.text(response.data.message).css('color', 'red');
                    }
                });
            });

            // Bulk Sync Media Library AJAX
            var isSyncing = false;
            var initialPending = <?php echo (int) $stats['pending']; ?>;

            $('#start-bulk-sync-btn').click(function() {
                if (isSyncing) return;
                isSyncing = true;
                $(this).hide();$('#stop-bulk-sync-btn').show();
                $('#sync-progress-container').show();
                $('#sync-status-msg').text('Memulai proses bulk sync...').css('color', '#007cba');
                runSyncBatch();
            });

            $('#stop-bulk-sync-btn').click(function() {
                isSyncing = false;
                $(this).hide();$('#start-bulk-sync-btn').show();
                $('#sync-status-msg').text('Sync dihentikan oleh pengguna.').css('color', '#d63638');
            });

            function runSyncBatch() {
                if (!isSyncing) return;
                $.post(ajaxurl, {
                    action: 'r2_bulk_sync_batch',
                    batch_size: 20,
                    nonce: '<?php echo wp_create_nonce("r2_admin_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        $('#stat-total-wp').text(Number(data.total_wp).toLocaleString());
                        $('#stat-total-r2').text(Number(data.total_r2).toLocaleString());
                        $('#stat-pending').text(Number(data.pending).toLocaleString());

                        var completed = initialPending - data.pending;
                        var percent = (initialPending > 0) ? Math.min(100, Math.round((completed / initialPending) * 100)) : 100;

                        $('#sync-progress-bar').css('width', percent + '%').text(percent + '%');
                        $('#sync-status-msg').text(`Berhasil memproses ${data.processed} gambar. Sisa: ${data.pending} gambar belum ter-sync.`);

                        if (data.pending > 0 && isSyncing && data.processed > 0) {
                            runSyncBatch();
                        } else {
                            isSyncing = false;
                            $('#stop-bulk-sync-btn').hide();
                            $('#start-bulk-sync-btn').show().prop('disabled', true);
                            $('#sync-status-msg').text('Proses Bulk Sync selesai! Seluruh gambar telah berada di Cloudflare R2.').css('color', 'green');
                        }
                    } else {
                        isSyncing = false;
                        $('#stop-bulk-sync-btn').hide();
                        $('#start-bulk-sync-btn').show();
                        $('#sync-status-msg').text('Terjadi kesalahan saat memproses sync.').css('color', '#d63638');
                    }
                });
            }

            // AJAX HANDLER SCAN DIREKTORI SERVER
            var scanRunning = false;
            var totalUploadedCount = 0;
            var totalSkippedCount = 0;

            $('#btn-scan-dir').click(function() {
                if (scanRunning) return;
                if (!confirm('Mulai memindai folder /wp-content/uploads/ server? Berkas yang belum ada di R2 akan langsung diunggah.')) return;

                scanRunning = true;
                totalUploadedCount = 0;
                totalSkippedCount = 0;

                $(this).prop('disabled', true);$('#scan-progress-box').show();
                $('#scan-progress-bar').css('width', '0%').text('0%');
                $('#scan-status-msg').text('Memulai pemindaian struktur folder server...').css('color', '#d35400');

                runScanDirBatch(0);
            });

            function runScanDirBatch(offset) {
                $.post(ajaxurl, {
                    action: 'r2_scan_directory_batch',
                    offset: offset,
                    batch_size: 20,
                    nonce: '<?php echo wp_create_nonce("r2_admin_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        var data = res.data;
                        totalUploadedCount += data.uploaded;
                        totalSkippedCount += data.skipped;

                        var percent = (data.total_files > 0) ? Math.min(100, Math.round((data.next_offset / data.total_files) * 100)) : 100;

                        $('#scan-progress-bar').css('width', percent + '%').text(percent + '%');
                        $('#scan-status-msg').text(`Memeriksa ${data.next_offset} dari ${data.total_files} berkas. (Diunggah: ${totalUploadedCount}, Di-skip/Sudah Ada: ${totalSkippedCount}).`);

                        if (!data.is_finished && scanRunning) {
                            runScanDirBatch(data.next_offset);
                        } else {
                            scanRunning = false;
                            $('#btn-scan-dir').prop('disabled', false);
                            $('#scan-progress-bar').css('width', '100%').text('100%');
                            $('#scan-status-msg').text(`Pemindaian Selesai 100%! Berhasil mengunggah ${totalUploadedCount} berkas baru ke R2 (${totalSkippedCount} berkas di-skip karena sudah ada).`).css('color', 'green');
                        }
                    } else {
                        scanRunning = false;
                        $('#btn-scan-dir').prop('disabled', false);
                        $('#scan-status-msg').text('Terjadi kesalahan saat memindai direktori.').css('color', 'red');
                    }
                });
            }

            // AJAX HANDLER CLEAN SCALED FILES
            var scaledRunning = false;
            var scaledTotalImages = 0;
            var scaledAccumulated = 0;
            var totalCleanedCount = 0;
            var totalSkippedCount = 0;

            $('#btn-clean-scaled').click(function() {
                if (scaledRunning) return;
                if (!confirm('Mulai memindai dan membersihkan file -scaled lama? File original fisik akan diunggah ke R2, URL di database diubah ke original, lalu file -scaled dihapus dari Lokal & R2.')) return;

                scaledRunning = true;
                scaledAccumulated = 0;
                totalCleanedCount = 0;
                totalSkippedCount = 0;

                $(this).prop('disabled', true);$('#scaled-progress-box').show();
                $('#scaled-progress-bar').css('width', '0%').text('0%');
                $('#scaled-status-msg').text('Mengkalkulasi total gambar...').css('color', '#d35400');

                runCleanScaledBatch(true);
            });

            function runCleanScaledBatch(isFirst) {
                $.post(ajaxurl, {
                    action: 'r2_failsafe_clean_scaled_batch',
                    batch_size: 15,
                    nonce: '<?php echo wp_create_nonce("r2_admin_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        var data = res.data;
                        if (isFirst) scaledTotalImages = data.total_images;

                        scaledAccumulated += data.processed;
                        totalCleanedCount += data.cleaned;
                        totalSkippedCount += data.skipped;

                        var percent = (scaledTotalImages > 0) ? Math.min(100, Math.round((scaledAccumulated / scaledTotalImages) * 100)) : 100;

                        $('#scaled-progress-bar').css('width', percent + '%').text(percent + '%');
                        $('#scaled-status-msg').text(`Memeriksa ${scaledAccumulated} dari ${scaledTotalImages} media. (Dihapus: ${totalCleanedCount}, Di-skip: ${totalSkippedCount}). Sisa: ${data.remaining}`);

                        if (data.remaining > 0 && scaledRunning) {
                            runCleanScaledBatch(false);
                        } else {
                            scaledRunning = false;
                            $('#btn-clean-scaled').prop('disabled', false);
                            $('#scaled-progress-bar').css('width', '100%').text('100%');
                            $('#scaled-status-msg').text(`Selesai 100%! Berhasil menghapus ${totalCleanedCount} file -scaled redundan. ${totalSkippedCount} file di-skip karena file original fisik tidak ada.`).css('color', 'green');
                        }
                    } else {
                        scaledRunning = false;
                        $('#btn-clean-scaled').prop('disabled', false);
                        $('#scaled-status-msg').text('Terjadi kesalahan saat membersihkan file -scaled.').css('color', 'red');
                    }
                });
            }

            // Failsafe Step 1: Regenerate Thumbnail
            var regenRunning = false;
            var regenTotalInitial = 0;
            var regenAccumulated = 0;

            $('#btn-failsafe-regen').click(function() {
                if (regenRunning) return;
                if (!confirm('Mulai membuat ulang file thumbnail fisik di server lokal?')) return;

                regenRunning = true;
                regenAccumulated = 0;
                $(this).prop('disabled', true);$('#regen-progress-box').show();
                $('#regen-progress-bar').css('width', '0%').text('0%');
                $('#regen-status-msg').text('Mengkalkulasi jumlah media...').css('color', '#007cba');

                runRegenBatch(true);
            });

            function runRegenBatch(isFirstStep) {
                $.post(ajaxurl, {
                    action: 'r2_failsafe_regen_batch',
                    batch_size: 5,
                    nonce: '<?php echo wp_create_nonce("r2_admin_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        var data = res.data;
                        if (isFirstStep) regenTotalInitial = data.total_offloaded;

                        regenAccumulated += data.processed;
                        var percent = (regenTotalInitial > 0) ? Math.min(100, Math.round((regenAccumulated / regenTotalInitial) * 100)) : 100;

                        $('#regen-progress-bar').css('width', percent + '%').text(percent + '%');
                        $('#regen-status-msg').text(`Berhasil memproses ${regenAccumulated} dari ${regenTotalInitial} media. Sisa: ${data.remaining} media.`);

                        if (data.remaining > 0 && regenRunning) {
                            runRegenBatch(false);
                        } else {
                            regenRunning = false;
                            $('#btn-failsafe-regen').prop('disabled', false);
                            $('#regen-progress-bar').css('width', '100%').text('100%');
                            $('#regen-status-msg').text('Langkah 1 Selesai (100%)! Seluruh thumbnail lokal telah berhasil dibuat ulang. Silakan lanjutkan ke Langkah 2.').css('color', 'green');
                        }
                    } else {
                        regenRunning = false;
                        $('#btn-failsafe-regen').prop('disabled', false);
                        $('#regen-status-msg').text('Terjadi kesalahan saat generate thumbnail.').css('color', 'red');
                    }
                });
            }

            // Failsafe Step 2: Rollback DB
            var rollbackRunning = false;
            var rollbackTotalInitial = <?php echo (int) $stats['total_r2']; ?>;
            var rollbackAccumulated = 0;

            $('#btn-failsafe-rollback').click(function() {
                if (rollbackRunning) return;
                if (!confirm('Yakin ingin mengembalikan seluruh URL gambar di database ke domain server lokal?')) return;

                rollbackRunning = true;
                rollbackAccumulated = 0;
                $(this).prop('disabled', true);$('#rollback-progress-box').show();
                $('#rollback-progress-bar').css('width', '0%').text('0%');
                $('#rollback-status-msg').text('Memulai mengembalikan URL gambar di database ke lokal...').css('color', '#007cba');

                runRollbackBatch();
            });

            function runRollbackBatch() {
                $.post(ajaxurl, {
                    action: 'r2_failsafe_rollback_batch',
                    batch_size: 20,
                    nonce: '<?php echo wp_create_nonce("r2_admin_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        var data = res.data;
                        rollbackAccumulated += data.processed;

                        var percent = (rollbackTotalInitial > 0) ? Math.min(100, Math.round((rollbackAccumulated / rollbackTotalInitial) * 100)) : 100;

                        $('#rollback-progress-bar').css('width', percent + '%').text(percent + '%');
                        $('#rollback-status-msg').text(`Memproses ${rollbackAccumulated} dari ${rollbackTotalInitial} media. Sisa media R2: ${data.pending}.`);

                        if (data.pending > 0 && data.processed > 0 && rollbackRunning) {
                            runRollbackBatch();
                        } else {
                            rollbackRunning = false;
                            $('#btn-failsafe-rollback').prop('disabled', false);
                            $('#rollback-progress-bar').css('width', '100%').text('100%');
                            $('#rollback-status-msg').text('Proses Rollback Selesai (100%)! Seluruh URL gambar telah kembali disajikan dari server lokal.').css('color', 'green');
                        }
                    } else {
                        rollbackRunning = false;
                        $('#btn-failsafe-rollback').prop('disabled', false);
                        $('#rollback-status-msg').text('Terjadi kesalahan saat rollback database.').css('color', 'red');
                    }
                });
            }
        });
        </script>
        <?php
    }
}