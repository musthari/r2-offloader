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
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        add_action( 'wp_ajax_r2_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_r2_bulk_sync_batch', array( $this, 'ajax_bulk_sync_batch' ) );
        add_action( 'wp_ajax_r2_scan_directory_batch', array( $this, 'ajax_scan_directory_batch' ) );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'tools_page_r2-media-offloader' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'r2-admin-css', R2_OFFLOADER_URL . 'assets/css/r2-admin.css', array(), R2_OFFLOADER_VERSION );
        wp_enqueue_script( 'r2-admin-js', R2_OFFLOADER_URL . 'assets/js/r2-admin.js', array( 'jquery' ), R2_OFFLOADER_VERSION, true );

        $stats = R2_Core::get_stats();
        wp_localize_script( 'r2-admin-js', 'r2AdminData', array(
            'nonce'          => wp_create_nonce( 'r2_admin_nonce' ),
            'initialPending' => (int) $stats['pending'],
            'totalR2'        => (int) $stats['total_r2']
        ) );
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

    public function ajax_scan_directory_batch() {
        check_ajax_referer( 'r2_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Akses ditolak.' ) );

        $offset     = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 20;
        
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $core       = R2_Core::get_instance();

        if ( false === ( $file_list = get_transient( 'r2_scan_file_cache' ) ) ) {
            $file_list = array();
            if ( file_exists( $base_dir ) ) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ( $iterator as $file ) {
                    if ( $file->isFile() ) {
                        $ext = strtolower( $file->getExtension() );
                        if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' ) ) ) {
                            $file_list[] = str_replace( $base_dir, '', $file->getPathname() );
                        }
                    }
                }
            }
            set_transient( 'r2_scan_file_cache', $file_list, 3600 );
        }

        $total_files = count( $file_list );
        $slice       = array_slice( $file_list, $offset, $batch_size );
        $uploaded    = 0;
        $skipped     = 0;

        foreach ( $slice as $relative_path ) {
            $local_file_path = $base_dir . $relative_path;

            if ( $core->does_file_exist_in_r2( $relative_path ) ) {
                $skipped++;
            } else {
                if ( $core->upload_file_to_r2( $local_file_path, $relative_path ) ) {
                    $uploaded++;
                }
            }
        }

        $next_offset = $offset + count( $slice );
        $is_finished = $next_offset >= $total_files;

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
        $stats      = R2_Core::get_stats();
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'dashboard';
        ?>
        <div class="wrap">
            <h1>Cloudflare R2 Media Offloader <span style="font-size:12px; color:#666; font-weight:normal;">v<?php echo R2_OFFLOADER_VERSION; ?> | by Mus</span></h1>

            <h2 class="nav-tab-wrapper" style="margin-top:15px;">
                <a href="?page=r2-media-offloader&tab=dashboard" class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">Dashboard & Bulk Sync</a>
                <a href="?page=r2-media-offloader&tab=failsafe" class="nav-tab <?php echo $active_tab === 'failsafe' ? 'nav-tab-active' : ''; ?>">Failsafe / Rollback Lokal</a>
                <a href="?page=r2-media-offloader&tab=guide_cloudflare" class="nav-tab <?php echo $active_tab === 'guide_cloudflare' ? 'nav-tab-active' : ''; ?>">Panduan Setting R2</a>
                <a href="?page=r2-media-offloader&tab=guide_wpcli" class="nav-tab <?php echo $active_tab === 'guide_wpcli' ? 'nav-tab-active' : ''; ?>">Panduan WP-CLI</a>
            </h2>

            <?php if ( 'dashboard' === $active_tab ) : ?>
                <div class="r2-stats-container">
                    <div class="card r2-stat-card r2-card-primary">
                        <h3>Total Gambar di WordPress</h3>
                        <p id="stat-total-wp" class="r2-stat-number"><?php echo number_format_i18n( $stats['total_wp'] ); ?></p>
                    </div>
                    <div class="card r2-stat-card" style="border-left:4px solid #46b450;">
                        <h3>Gambar Ter-offload di R2</h3>
                        <p id="stat-total-r2" class="r2-stat-number"><?php echo number_format_i18n( $stats['total_r2'] ); ?></p>
                    </div>
                    <div class="card r2-stat-card" style="border-left:4px solid #d63638;">
                        <h3>Sisa Gambar Belum Sync</h3>
                        <p id="stat-pending" class="r2-stat-number"><?php echo number_format_i18n( $stats['pending'] ); ?></p>
                    </div>
                </div>

                <div class="card r2-card">
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
                        <div class="r2-progress-outer">
                            <div id="sync-progress-bar" class="r2-progress-inner" style="background:#2271b1;">0%</div>
                        </div>
                        <p id="sync-status-msg" class="r2-status-msg"></p>
                    </div>
                </div>

                <div class="card r2-card r2-card-warning">
                    <h2>2. Scan & Sync Direktori Uploads Server (/wp-content/uploads/)</h2>
                    <p>Fitur ini akan memindai seluruh file gambar fisik di folder server (termasuk berkas hasil FTP / migrasi manual yang tidak terdaftar di Media Library) dan mengunggahnya ke R2.</p>
                    
                    <div style="margin-top:15px;">
                        <button type="button" id="btn-scan-dir" class="button button-secondary button-hero">
                            Mulai Scan Folder Direktori Web
                        </button>
                    </div>

                    <div id="scan-progress-box" style="margin-top:20px; display:none;">
                        <div class="r2-progress-outer">
                            <div id="scan-progress-bar" class="r2-progress-inner" style="background:#f39c12;">0%</div>
                        </div>
                        <p id="scan-status-msg" class="r2-status-msg"></p>
                    </div>
                </div>

                <div class="card r2-card">
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
        <?php
    }
}