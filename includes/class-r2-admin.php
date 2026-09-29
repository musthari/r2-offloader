<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class R2_Offloader_Admin {
	private $settings;

	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'R2 Offloader', 'r2-offloader' ),
			__( 'R2 Offloader', 'r2-offloader' ),
			'manage_options',
			'r2-offloader',
			array( $this, 'render_page' ),
			'dashicons-cloud',
			26
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_r2-offloader' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'r2offloader-admin-css', R2OFFLOADER_URL . 'assets/css/admin.css', array(), R2OFFLOADER_VERSION );
		wp_enqueue_script( 'r2offloader-admin-js', R2OFFLOADER_URL . 'assets/js/admin.js', array( 'jquery' ), R2OFFLOADER_VERSION, true );
		wp_localize_script( 'r2offloader-admin-js', 'r2OffloaderAdmin', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'r2offloader_admin_nonce' ),
			'i18n'     => array(
				'processing' => __( 'Memproses...', 'r2-offloader' ),
				'completed'  => __( 'Selesai', 'r2-offloader' ),
			),
		) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings->get_all();
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$tabs = array(
			'dashboard'  => __( 'Dashboard & Bulk Sync', 'r2-offloader' ),
			'setup'      => __( 'Panduan Setting R2', 'r2-offloader' ),
			'wpcli'      => __( 'Panduan WP-CLI', 'r2-offloader' ),
			'failsafe'   => __( 'Failsafe / Rollback Lokal', 'r2-offloader' ),
		);
		?>
		<div class="wrap r2offloader-wrap">
			<h1><?php esc_html_e( 'R2 Offloader', 'r2-offloader' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=r2-offloader&tab=' . $tab_key ) ); ?>" class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="r2offloader-content">
				<?php if ( 'dashboard' === $active_tab ) : ?>
					<?php $this->render_dashboard( $settings ); ?>
				<?php elseif ( 'setup' === $active_tab ) : ?>
					<?php $this->render_setup_guide(); ?>
				<?php elseif ( 'wpcli' === $active_tab ) : ?>
					<?php $this->render_wpcli_guide(); ?>
				<?php else : ?>
					<?php $this->render_failsafe(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_dashboard( $settings ) {
		?>
		<div class="r2offloader-card">
			<h2><?php esc_html_e( 'Pengaturan Cloudflare R2', 'r2-offloader' ); ?></h2>
			<form id="r2offloader-settings-form" method="post" action="">
				<?php wp_nonce_field( 'r2offloader_admin_nonce', 'r2offloader_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="account_id"><?php esc_html_e( 'Account ID', 'r2-offloader' ); ?></label></th>
						<td><input type="text" id="account_id" name="account_id" value="<?php echo esc_attr( $settings['account_id'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="bucket"><?php esc_html_e( 'Bucket Name', 'r2-offloader' ); ?></label></th>
						<td><input type="text" id="bucket" name="bucket" value="<?php echo esc_attr( $settings['bucket'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="public_url"><?php esc_html_e( 'Public Custom Domain', 'r2-offloader' ); ?></label></th>
						<td><input type="text" id="public_url" name="public_url" value="<?php echo esc_attr( $settings['public_url'] ); ?>" class="regular-text" placeholder="https://cdn.example.com" /></td>
					</tr>
					<tr>
						<th><label for="api_key"><?php esc_html_e( 'API Token Access Key', 'r2-offloader' ); ?></label></th>
						<td><input type="text" id="api_key" name="api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="api_secret"><?php esc_html_e( 'API Token Secret Key', 'r2-offloader' ); ?></label></th>
						<td><input type="password" id="api_secret" name="api_secret" value="<?php echo esc_attr( $settings['api_secret'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="region"><?php esc_html_e( 'Region', 'r2-offloader' ); ?></label></th>
						<td><input type="text" id="region" name="region" value="<?php echo esc_attr( $settings['region'] ); ?>" class="regular-text" placeholder="auto" /></td>
					</tr>
					<tr>
						<th><label for="batch_size"><?php esc_html_e( 'Batch Size', 'r2-offloader' ); ?></label></th>
						<td><input type="number" min="1" id="batch_size" name="batch_size" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" /></td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Simpan Pengaturan', 'r2-offloader' ); ?></button>
					<button type="button" id="r2offloader-test-connection" class="button"><?php esc_html_e( 'Tes Koneksi R2', 'r2-offloader' ); ?></button>
				</p>
			</form>
		</div>

		<div class="r2offloader-card">
			<h2><?php esc_html_e( 'Bulk Sync Media', 'r2-offloader' ); ?></h2>
			<p><?php esc_html_e( 'Proses unggah master WebP dan thumbnail ke R2, lalu bersihkan file lokal thumbnail untuk menghemat ruang disk.', 'r2-offloader' ); ?></p>
			<div class="r2offloader-progress">
				<div class="r2offloader-progress-bar"><span id="r2offloader-bulk-progress-bar" style="width:0%;"></span></div>
				<div class="r2offloader-progress-text"><span id="r2offloader-bulk-progress-label">0%</span></div>
			</div>
			<p>
				<button id="r2offloader-bulk-sync" class="button button-primary"><?php esc_html_e( 'Mulai Bulk Sync', 'r2-offloader' ); ?></button>
			</p>
			<div id="r2offloader-bulk-status" class="r2offloader-status"></div>
		</div>
		<?php
	}

	private function render_setup_guide() {
		?>
		<div class="r2offloader-card">
			<h2><?php esc_html_e( 'Panduan Setting Cloudflare R2', 'r2-offloader' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Buat bucket R2', 'r2-offloader' ); ?></strong> di dashboard Cloudflare R2. Pilih lokasi region yang sesuai, contohnya <code>APAC</code> atau <code>ASIA</code>.</li>
				<li><strong><?php esc_html_e( 'Ambil Account ID', 'r2-offloader' ); ?></strong> dari panel <code>R2 Overview</code>. Salin Account ID dan masukkan ke field plugin.</li>
				<li><strong><?php esc_html_e( 'Buat API Token', 'r2-offloader' ); ?></strong> dengan permission <code>Object Read & Write</code>. Salin Access Key dan Secret Key.</li>
				<li><strong><?php esc_html_e( 'Aktifkan Public Access', 'r2-offloader' ); ?></strong> via menu <code>Public Access</code> dan buat custom domain, misalnya <code>https://cdn.example.com</code>.</li>
				<li><strong><?php esc_html_e( 'Masukkan konfigurasi', 'r2-offloader' ); ?></strong> ke halaman plugin: Account ID, Bucket, Public URL, API Key, Secret Key, dan region.</li>
				<li><strong><?php esc_html_e( 'Tes koneksi', 'r2-offloader' ); ?></strong> menggunakan tombol <code>Tes Koneksi R2</code> untuk memastikan token aktif.</li>
			</ol>
			<h3><?php esc_html_e( 'Contoh struktur domain publik', 'r2-offloader' ); ?></h3>
			<p><code>https://cdn.example.com/2026/09/namafile.webp</code></p>
			<p><?php esc_html_e( 'Gunakan custom domain yang bersifat publik agar browser dapat memuat file hasil offload.', 'r2-offloader' ); ?></p>
		</div>
		<?php
	}

	private function render_wpcli_guide() {
		?>
		<div class="r2offloader-card">
			<h2><?php esc_html_e( 'Panduan WP-CLI', 'r2-offloader' ); ?></h2>
			<pre># 1. Tes koneksi R2
wp r2-offloader test-connection

# 2. Bulk Sync semua media
wp r2-offloader bulk-sync --batch-size=20

# 3. Regenerate thumbnail lokal dari master WebP
wp r2-offloader regen-local --batch-size=15

# 4. Rollback database ke URL lokal
wp r2-offloader rollback --batch-size=15

# 5. Cek status konfigurasi
wp r2-offloader status</pre>
			<p><?php esc_html_e( 'Pastikan WP-CLI sudah aktif di server dan WordPress dapat diakses melalui terminal.', 'r2-offloader' ); ?></p>
		</div>
		<?php
	}

	private function render_failsafe() {
		?>
		<div class="r2offloader-card">
			<h2><?php esc_html_e( 'Failsafe / Rollback Lokal', 'r2-offloader' ); ?></h2>
			<p><?php esc_html_e( 'Operational rollback lokal dibagi menjadi dua langkah:', 'r2-offloader' ); ?></p>
			<ol>
				<li><strong><?php esc_html_e( 'Step 1: Regenerate Thumbnail Lokal', 'r2-offloader' ); ?></strong> — regenerasi ukuran thumbnail dari master WebP yang tetap disimpan di server lokal.</li>
				<li><strong><?php esc_html_e( 'Step 2: Rollback Database', 'r2-offloader' ); ?></strong> — ubah URL CDN kembali ke URL lokal, lalu hapus metadata offload.</li>
			</ol>
			<div class="r2offloader-progress">
				<div class="r2offloader-progress-bar"><span id="r2offloader-regen-progress-bar" style="width:0%;"></span></div>
				<div class="r2offloader-progress-text"><span id="r2offloader-regen-progress-label">0%</span></div>
			</div>
			<p><button id="r2offloader-regen-local" class="button button-primary"><?php esc_html_e( 'Jalankan Step 1: Regen Thumbnail', 'r2-offloader' ); ?></button></p>
			<div id="r2offloader-regen-status" class="r2offloader-status"></div>

			<div class="r2offloader-progress" style="margin-top:26px;">
				<div class="r2offloader-progress-bar"><span id="r2offloader-rollback-progress-bar" style="width:0%;"></span></div>
				<div class="r2offloader-progress-text"><span id="r2offloader-rollback-progress-label">0%</span></div>
			</div>
			<p><button id="r2offloader-rollback-db" class="button button-secondary"><?php esc_html_e( 'Jalankan Step 2: Rollback DB', 'r2-offloader' ); ?></button></p>
			<div id="r2offloader-rollback-status" class="r2offloader-status"></div>
		</div>
		<?php
	}
}
