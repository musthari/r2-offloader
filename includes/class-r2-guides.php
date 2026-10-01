<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class R2_Guides {

    public static function render_tab_guide_cloudflare() {
        ?>
        <div class="card r2-card-guide">
            <h2>Panduan Lengkap Konfigurasi Cloudflare R2 & Custom Domain</h2>
            <p>Ikuti langkah-langkah berikut untuk mendapatkan kredensial API dan menghubungkan domain CDN secara gratis bebas biaya transfer (Zero Egress Fee):</p>
            
            <hr style="margin:20px 0;">

            <h3>1. Membuat R2 Bucket</h3>
            <ol style="margin-left:20px;">
                <li>Buka dan masuk ke <a href="https://dash.cloudflare.com/" target="_blank">Dashboard Cloudflare</a>.</li>
                <li>Di menu navigasi sebelah kiri, klik <strong>R2 Object Storage</strong>.</li>
                <li>Klik tombol <strong>Create Bucket</strong>.</li>
                <li>Isikan nama bucket dengan <strong>huruf kecil (lowercase) tanpa spasi</strong> (contoh: <code>media-website-news</code>).</li>
                <li>Klik <strong>Create Bucket</strong>. Masukkan nama ini ke kolom <strong>Bucket Name</strong> di tab Pengaturan.</li>
            </ol>

            <h3 style="margin-top:25px;">2. Mendapatkan Account ID</h3>
            <ol style="margin-left:20px;">
                <li>Buka menu <strong>R2 Overview</strong> (halaman utama R2).</li>
                <li>Di panel sebelah kanan, cari bagian <strong>Account Details</strong>.</li>
                <li>Salin 32-karakter acak <strong>Account ID</strong> (contoh: <code>a1b2c3d4e5f67890a1b2c3d4e5f67890</code>).</li>
                <li>Tempelkan string tersebut ke kolom <strong>Account ID</strong> di plugin. <em>(Catatan: Jangan sertakan protokol <code>https://</code> atau domain)</em>.</li>
            </ol>

            <h3 style="margin-top:25px;">3. Membuat API Tokens (Access Key & Secret Key)</h3>
            <ol style="margin-left:20px;">
                <li>Pada halaman R2 Overview, klik link <strong>Manage R2 API Tokens</strong> di sisi kanan.</li>
                <li>Klik tombol <strong>Create API Token</strong>.</li>
                <li>Pada bagian <strong>Permissions</strong>, wajib memilih <strong>Object Read & Write</strong> (atau Admin Read & Write).</li>
                <li>Klik <strong>Create API Token</strong>.</li>
                <li>Salin <strong>Access Key ID</strong> dan <strong>Secret Access Key</strong> yang ditampilkan. Salin dengan teliti tanpa spasi tambahan.</li>
            </ol>

            <h3 style="margin-top:25px;">4. Mengubah Custom Domain CDN (Public Access)</h3>
            <ol style="margin-left:20px;">
                <li>Masuk ke dalam R2 Bucket yang telah dibuat &gt; klik tab <strong>Settings</strong>.</li>
                <li>Scroll ke bawah hingga menemukan seksi <strong>Public Access</strong>.</li>
                <li>Di bagian <strong>Custom Domains</strong>, klik tombol <strong>Connect Domain</strong>.</li>
                <li>Masukkan subdomain yang ingin dijadikan CDN (contoh: <code>media.domainanda.com</code>).</li>
                <li>Cloudflare akan otomatis menambahkan CNAME record ke zona DNS Anda.</li>
                <li>Salin alamat lengkap beserta protokolnya (contoh: <code>https://media.domainanda.com</code>) dan tempelkan ke kolom <strong>Custom Domain CDN URL</strong> di dasbor plugin ini.</li>
            </ol>
        </div>
        <?php
    }

    public static function render_tab_guide_wpcli() {
        ?>
        <div class="card r2-card-guide">
            <h2>Panduan Eksekusi WP-CLI Sync & Maintenance (Terminal SSH)</h2>
            <p>Untuk situs berita berukuran besar dengan puluhan hingga ratusan ribu gambar, penggunaan terminal SSH via <strong>WP-CLI</strong> adalah metode paling stabil, cepat, dan anti-timeout.</p>

            <hr style="margin:20px 0;">

            <h3>1. Perintah Fast Migration (Super Cepat untuk 150k+ Gambar)</h3>
            <p>Gunakan perintah ini jika seluruh file fisik gambar <strong>sudah terunggah di Cloudflare R2</strong> (misalnya hasil migrasi Rclone atau plugin lama). Perintah ini akan langsung memperbarui database dalam hitungan detik tanpa re-upload:</p>
            <pre class="r2-code-block">wp r2-offload fast-migrate</pre>

            <h3 style="margin-top:25px;">2. Perintah Sync Standar Gambar ke Cloudflare R2</h3>
            <p>Arahkan terminal SSH Anda ke folder utama WordPress, lalu jalankan perintah sinkronisasi standar berikut:</p>
            <pre class="r2-code-block">wp r2-offload sync --batch-size=100</pre>
            <p>Untuk menjalankan sinkronisasi otomatis sampai selesai (100% finished), gunakan bash loop:</p>
            <pre class="r2-code-block">while wp r2-offload sync --batch-size=100; do sleep 1; done</pre>
        </div>
        <?php
    }
}