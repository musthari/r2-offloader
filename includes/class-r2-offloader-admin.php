<?php

if (! defined('ABSPATH')) {
    exit;
}

class R2_Offloader_Admin {
    protected $settings;

    public function __construct() {
        $this->settings = new R2_Offloader_Settings();
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function register_menu() {
        add_menu_page(
            'R2 Offloader',
            'R2 Offloader',
            'manage_options',
            'r2-offloader',
            array($this, 'render_page'),
            'dashicons-cloud'
        );
    }

    public function enqueue_assets($hook) {
        if ('toplevel_page_r2-offloader' !== $hook) {
            return;
        }

        wp_enqueue_style('r2-offloader-admin', R2_OFFLOADER_URL . 'assets/css/admin.css', array(), R2_OFFLOADER_VERSION);
        wp_enqueue_script('r2-offloader-admin', R2_OFFLOADER_URL . 'assets/js/admin.js', array('jquery'), R2_OFFLOADER_VERSION, true);

        wp_localize_script(
            'r2-offloader-admin',
            'r2Offloader',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('r2_offloader_ajax'),
            )
        );
    }

    public function render_page() {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = $this->settings->get_all();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
        $tabs = array(
            'dashboard' => __('Dashboard & Bulk Sync', 'r2-offloader'),
            'r2-guide' => __('Panduan Setting R2', 'r2-offloader'),
            'wpcli'    => __('Panduan WP-CLI', 'r2-offloader'),
            'failsafe' => __('Failsafe / Rollback Lokal', 'r2-offloader'),
        );
        ?>
        <div class="wrap r2-offloader-wrap">
            <h1><?php echo esc_html__('R2 Offloader v2.0.0', 'r2-offloader'); ?></h1>

            <nav class="nav-tab-wrapper" aria-label="Tabs">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offloader&tab=' . $key)); ?>"
                       class="nav-tab <?php echo ($tab === $key) ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ($tab) {
                case 'r2-guide':
                    $this->render_r2_guide();
                    break;
                case 'wpcli':
                    $this->render_wpcli_guide();
                    break;
                case 'failsafe':
                    $this->render_failsafe_tab();
                    break;
                default:
                    $this->render_dashboard();
                    break;
            }
            ?>
        </div>
        <?php
    }

    protected function render_dashboard() {
        $settings = $this->settings->get_all();
        ?>
        <div class="r2-panel">
            <h2><?php esc_html_e('Pengaturan Plugin', 'r2-offloader'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('r2_offloader_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Aktifkan Plugin', 'r2-offloader'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="r2_offloader_settings[enabled]" value="1" <?php checked(! empty($settings['enabled']), 1); ?> />
                                    <?php esc_html_e('Aktifkan proses offload otomatis', 'r2-offloader'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Account ID R2', 'r2-offloader'); ?></th>
                            <td><input type="text" class="regular-text" name="r2_offloader_settings[r2_account_id]" value="<?php echo esc_attr($settings['r2_account_id']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Bucket Name', 'r2-offloader'); ?></th>
                            <td><input type="text" class="regular-text" name="r2_offloader_settings[r2_bucket_name]" value="<?php echo esc_attr($settings['r2_bucket_name']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Access Key ID', 'r2-offloader'); ?></th>
                            <td><input type="text" class="regular-text" name="r2_offloader_settings[r2_access_key_id]" value="<?php echo esc_attr($settings['r2_access_key_id']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Secret Access Key', 'r2-offloader'); ?></th>
                            <td><input type="password" class="regular-text" name="r2_offloader_settings[r2_secret_access_key]" value="<?php echo esc_attr($settings['r2_secret_access_key']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Custom Domain / Public URL', 'r2-offloader'); ?></th>
                            <td><input type="text" class="regular-text" name="r2_offloader_settings[r2_public_domain]" value="<?php echo esc_attr($settings['r2_public_domain']); ?>" placeholder="https://cdn.example.com" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Region', 'r2-offloader'); ?></th>
                            <td><input type="text" class="regular-text" name="r2_offloader_settings[r2_region]" value="<?php echo esc_attr($settings['r2_region']); ?>" placeholder="auto" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Quality WebP', 'r2-offloader'); ?></th>
                            <td><input type="number" min="1" max="100" name="r2_offloader_settings[r2_webp_quality]" value="<?php echo esc_attr($settings['r2_webp_quality']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Resize Width Maksimal', 'r2-offloader'); ?></th>
                            <td><input type="number" min="200" max="2000" name="r2_offloader_settings[r2_resize_width]" value="<?php echo esc_attr($settings['r2_resize_width']); ?>" /> px</td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Batch Size', 'r2-offloader'); ?></th>
                            <td><input type="number" min="1" max="200" name="r2_offloader_settings[r2_batch_size]" value="<?php echo esc_attr($settings['r2_batch_size']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Queue Processing', 'r2-offloader'); ?></th>
                            <td><input type="checkbox" name="r2_offloader_settings[r2_enable_queue]" value="1" <?php checked(! empty($settings['r2_enable_queue']), 1); ?> /> <?php esc_html_e('Gunakan background queue untuk proses yang lebih stabil', 'r2-offloader'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Max Retries', 'r2-offloader'); ?></th>
                            <td><input type="number" min="1" max="10" name="r2_offloader_settings[r2_max_retries]" value="<?php echo esc_attr($settings['r2_max_retries']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Logging', 'r2-offloader'); ?></th>
                            <td><input type="checkbox" name="r2_offloader_settings[r2_log_enabled]" value="1" <?php checked(! empty($settings['r2_log_enabled']), 1); ?> /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Hapus thumbnail lokal setelah upload', 'r2-offloader'); ?></th>
                            <td><input type="checkbox" name="r2_offloader_settings[r2_delete_local_thumbs]" value="1" <?php checked(! empty($settings['r2_delete_local_thumbs']), 1); ?> /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Simpan master WebP di server lokal', 'r2-offloader'); ?></th>
                            <td><input type="checkbox" name="r2_offloader_settings[r2_keep_master_webp_local]" value="1" <?php checked(! empty($settings['r2_keep_master_webp_local']), 1); ?> /></td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Simpan Pengaturan', 'r2-offloader')); ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Aksi Bulk', 'r2-offloader'); ?></h2>
            <div class="r2-actions">
                <button type="button" class="button button-primary" data-action="test_connection"><?php esc_html_e('Uji Koneksi R2', 'r2-offloader'); ?></button>
                <button type="button" class="button button-secondary" data-action="bulk_sync"><?php esc_html_e('Bulk Sync Media', 'r2-offloader'); ?></button>
                <button type="button" class="button button-secondary" data-action="cleanup_scaled"><?php esc_html_e('Cleanup -scaled', 'r2-offloader'); ?></button>
            </div>

            <div class="r2-progress">
                <div class="r2-progress-label">
                    <span><?php esc_html_e('Progress', 'r2-offloader'); ?></span>
                    <strong id="r2-progress-text">0%</strong>
                </div>
                <div class="r2-progress-bar">
                    <span id="r2-progress-fill" style="width:0%"></span>
                </div>
            </div>
        </div>
        <?php
    }

    protected function render_r2_guide() {
        ?>
        <div class="r2-panel">
            <h2><?php esc_html_e('Panduan Setting Cloudflare R2', 'r2-offloader'); ?></h2>

            <ol>
                <li>Buka Cloudflare lalu masuk ke bagian <strong>R2</strong>.</li>
                <li>Klik <strong>Create bucket</strong>, misalnya nama bucket <code>site-media-prod</code>.</li>
                <li>Salin <strong>Account ID</strong> dari dashboard Cloudflare.</li>
                <li>Buat API Token dengan permission <strong>Object Read &amp; Write</strong>.</li>
                <li>Gunakan <strong>Access Key ID</strong> dan <strong>Secret Access Key</strong> ke plugin.</li>
                <li>Tambahkan <strong>Public Access Custom Domain</strong>, misalnya <code>https://cdn.example.com</code>.</li>
                <li>Pastikan DNS &amp; SSL sudah aktif.</li>
                <li>Masukkan semua data di panel plugin dan klik <strong>Uji Koneksi R2</strong>.</li>
            </ol>

            <h3><?php esc_html_e('Panduan Lengkap', 'r2-offloader'); ?></h3>
            <p><strong>1. Membuat Bucket R2</strong><br />Masuk ke dashboard Cloudflare &gt; R2 &gt; Create bucket. Gunakan nama yang konsisten dan sesuaikan region dengan lokasi WordPress Anda.</p>
            <p><strong>2. Account ID</strong><br />Account ID biasanya muncul di dashboard Cloudflare atau profil akun. Salin lalu masukkan ke field plugin.</p>
            <p><strong>3. API Token</strong><br />Buat token dengan permission <strong>Object Read &amp; Write</strong>. Token ini akan dipakai untuk upload, delete, dan generate URL.</p>
            <p><strong>4. Custom Domain / Public Access</strong><br />Setelah bucket dibuat, aktifkan public access dan asosiasikan custom domain seperti <code>cdn.example.com</code>. Pastikan DNS CNAME atau A record sudah valid.</p>
            <p><strong>5. Endpoint</strong><br />Umumnya bentuk endpoint adalah <code>https://{account_id}.r2.cloudflarestorage.com</code>.</p>
            <p><strong>6. Public URL</strong><br />Gunakan custom domain dari Cloudflare atau endpoint default bila memang diperlukan. Contoh: <code>https://cdn.example.com</code>.</p>
            <p><strong>7. Testing</strong><br />Klik tombol <strong>Uji Koneksi R2</strong> untuk memastikan plugin dapat terhubung ke bucket.</p>
        </div>
        <?php
    }

    protected function render_wpcli_guide() {
        ?>
        <div class="r2-panel">
            <h2><?php esc_html_e('Panduan WP-CLI', 'r2-offloader'); ?></h2>
            <pre><code>wp plugin activate r2-offloader
wp r2-offloader test-connection
wp r2-offloader sync --batch=25
wp r2-offloader cleanup-scaled
wp r2-offloader failsafe-regen --batch=20
wp r2-offloader rollback --batch=20
wp r2-offloader status
wp r2-offloader queue-stats</code></pre>

            <h3><?php esc_html_e('Penjelasan', 'r2-offloader'); ?></h3>
            <ul>
                <li><strong>wp r2-offloader test-connection</strong> — uji koneksi ke Cloudflare R2.</li>
                <li><strong>wp r2-offloader sync --batch=25</strong> — bulk sync media ke R2.</li>
                <li><strong>wp r2-offloader cleanup-scaled</strong> — hapus file <code>-scaled</code> yang benar-benar punya master asli baik lokal maupun di R2.</li>
                <li><strong>wp r2-offloader failsafe-regen --batch=20</strong> — regenerasi thumbnail lokal dari master WebP.</li>
                <li><strong>wp r2-offloader rollback --batch=20</strong> — kembalikan URL ke lokal dan hapus meta offload.</li>
                <li><strong>wp r2-offloader queue-stats</strong> — tampilkan statistik queue processing.</li>
            </ul>
        </div>
        <?php
    }

    protected function render_failsafe_tab() {
        ?>
        <div class="r2-panel">
            <h2><?php esc_html_e('Failsafe / Rollback Lokal', 'r2-offloader'); ?></h2>
            <p><?php esc_html_e('Fitur ini digunakan untuk memastikan Anda tetap dapat mengembalikan media ke server lokal bila perlu.', 'r2-offloader'); ?></p>
            <ol>
                <li><?php esc_html_e('Step 1: Regenerate Thumbnail', 'r2-offloader'); ?> — buat ulang thumbnail fisik di server lokal dari master WebP.</li>
                <li><?php esc_html_e('Step 2: Rollback Database', 'r2-offloader'); ?> — revert semua URL CDN kembali ke server lokal dan hapus meta offload.</li>
            </ol>

            <div class="r2-actions">
                <button type="button" class="button button-secondary" data-action="regen_thumbnails"><?php esc_html_e('Step 1: Regenerate Thumbnail', 'r2-offloader'); ?></button>
                <button type="button" class="button button-secondary" data-action="rollback"><?php esc_html_e('Step 2: Rollback Database', 'r2-offloader'); ?></button>
            </div>

            <div class="r2-progress">
                <div class="r2-progress-label">
                    <span><?php esc_html_e('Progress', 'r2-offloader'); ?></span>
                    <strong id="r2-progress-text">0%</strong>
                </div>
                <div class="r2-progress-bar">
                    <span id="r2-progress-fill" style="width:0%"></span>
                </div>
            </div>
        </div>
        <?php
    }
}
