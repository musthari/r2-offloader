# R2 Offloader

Plugin WordPress modular untuk offload media ke Cloudflare R2, auto-resize & WebP conversion, serta failsafe rollback lokal.

## Fitur utama
- Auto resize gambar > 1000px menjadi 1000px proporsional
- Convert master gambar ke WebP kualitas 75% dan hapus JPG/PNG lama
- Nonaktifkan pembentukan `-scaled` oleh WordPress
- Upload master WebP dan thumbnail ke Cloudflare R2 via AWS Signature V4
- Hapus thumbnail lokal setelah upload, simpan master WebP lokal
- Timpa URL lokal di database ke URL CDN
- Failsafe Step 1 regen thumbnail lokal
- Failsafe Step 2 rollback database
- Dashboard admin tabbed dengan progress bar real-time
- AJAX security dengan nonce + anti-race condition
- Test koneksi R2
- Panduan lengkap R2 dan WP-CLI

## Struktur folder
```text
r2-offloader/
├── r2-offloader.php
├── README.md
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
├── includes/
│   ├── class-r2-settings.php
│   ├── class-r2-api.php
│   ├── class-r2-media-processor.php
│   ├── class-r2-admin.php
│   ├── class-r2-ajax.php
│   ├── class-r2-recovery.php
│   └── class-r2-cli.php
└── languages/
```

## Panduan setting R2 (lengkap)

### 1) Buat bucket Cloudflare R2
1. Login ke Cloudflare.
2. Buka menu R2.
3. Klik Create bucket.
4. Beri nama unik, misalnya `wp-assets-prod`.
5. Pilih lokasi region yang dekat dengan wilayah trafik situs Anda.
6. Simpan bucket name karena akan dipakai di pengaturan plugin.

### 2) Ambil Account ID
- Dari panel R2 Overview, salin `Account ID`.
- Account ID ini dipakai untuk membangun endpoint API seperti:
  `bucket.accountid.r2.cloudflarestorage.com`

### 3) Buat API Token
- Buka menu R2 > API Tokens.
- Klik Create API Token.
- Atur permission minimal: `Object Read & Write`.
- Salin `Access Key ID` dan `Secret Access Key`.
- Masukkan ke field plugin:
  - Account ID
  - Bucket Name
  - Public Custom Domain
  - API Token Access Key
  - API Token Secret Key

### 4) Public Access Custom Domain
- Di dashboard R2, buka menu Public access.
- Buat custom domain yang terhubung ke bucket.
- Misalnya: `https://cdn.example.com`
- Domain ini digunakan sebagai base URL CDN pada database dan frontend.

### 5) Entry ke plugin WordPress
Pada halaman admin `R2 Offloader`, isi:
- Account ID
- Bucket Name
- Public URL
- API Token Access Key
- API Token Secret Key
- Region (`auto` umumnya cukup)
- Batch Size (misalnya 10)

Klik `Tes Koneksi R2` untuk memastikan koneksi aktif.

## Panduan WP-CLI
```bash
# Tes koneksi
wp r2-offloader test-connection

# Bulk sync semua attachment
wp r2-offloader bulk-sync --batch-size=20

# Regenerate thumbnail lokal dari master WebP
wp r2-offloader regen-local --batch-size=15

# Rollback database ke URL lokal
wp r2-offloader rollback --batch-size=15

# Cek konfigurasi
wp r2-offloader status
```

## Catatan penting
- File master WebP tetap dipertahankan di server lokal.
- Semua thumbnail lokal akan dihapus setelah berhasil diupload ke R2.
- Proses offload dan rollback dilengkapi nonce + transient lock untuk mencegah race condition.
- Failsafe Step 1 dan Step 2 diimplementasikan dalam AJAX batching agar proses tidak terlalu berat dan dapat dipantau lewat progress bar.
- Plugin ini mencegah WordPress membuat file `-scaled` dengan filter `big_image_size_threshold` dan `intermediate_image_sizes_advanced`.

## Install
1. Copy folder `r2-offloader` ke folder `wp-content/plugins/`.
2. Aktivasi plugin dari menu Plugins WordPress.
3. Masukkan konfigurasi Cloudflare R2.
4. Jalankan Bulk Sync.

## Lisensi
GPL v2 atau setelahnya.
