<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class R2_Offloader_CLI_Command {

    /**
     * Fast Migration khusus situs besar (150k+ gambar):
     * Langsung menandai status offloaded & mengganti URL di database tanpa re-upload.
     * 
     * ## OPTIONS
     * 
     * ## EXAMPLES
     *     wp r2-offload fast-migrate
     * 
     * @subcommand fast-migrate
     */
    public function fast_migrate( $args, $assoc_args ) {
        global $wpdb;
        $cdn_domain = rtrim( get_option( 'r2_cdn_domain' ), '/' );
        $upload_dir = wp_upload_dir();
        $base_url   = rtrim( $upload_dir['baseurl'], '/' );

        if ( empty( $cdn_domain ) ) {
            WP_CLI::error( "Custom Domain CDN URL belum dikonfigurasi di admin WordPress!" );
            return;
        }

        WP_CLI::log( "Memulai Fast Migration untuk database masif..." );

        // 1. Ambil seluruh ID attachment gambar yang belum ditandai _r2_offloaded
        $query_get_ids = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_r2_offloaded')
            WHERE p.post_type = 'attachment' 
            AND p.post_mime_type LIKE 'image/%'
            AND (pm.meta_value IS NULL OR pm.meta_value != '1')
        ";

        $attachment_ids = $wpdb->get_col( $query_get_ids );
        $total = count( $attachment_ids );

        if ( empty( $attachment_ids ) ) {
            WP_CLI::success( "Semua media sudah terdaftar sebagai offloaded di R2." );
            return;
        }

        WP_CLI::log( "Ditemukan {$total} media yang akan di-migrate secara langsung..." );

        // 2. Batch Insert Meta _r2_offloaded = 1 secara langsung via SQL
        $chunks = array_chunk( $attachment_ids, 5000 );
        $progress_meta = \WP_CLI\Utils\make_progress_bar( 'Langkah 1: Tagging Metadata DB', count( $chunks ) );

        foreach ( $chunks as $chunk_ids ) {
            $values = array();
            foreach ( $chunk_ids as $id ) {
                $values[] = $wpdb->prepare( "(%d, '_r2_offloaded', '1')", $id );
            }
            $sql_insert = "INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $values );
            $wpdb->query( $sql_insert );
            $progress_meta->tick();
        }
        $progress_meta->finish();

        // 3. Direct SQL Replace URL Lokal ke CDN R2 di post_content
        WP_CLI::log( "Langkah 2: Mengganti URL lokal ke CDN R2 di tabel post_content..." );
        
        $sql_replace = $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $base_url,
            $cdn_domain,
            '%' . $wpdb->esc_like( $base_url ) . '%'
        );

        $affected_rows = $wpdb->query( $sql_replace );

        WP_CLI::success( "Fast Migration Selesai! Berhasil memigrasikan {$total} media. URL yang diperbarui di artikel: {$affected_rows} baris." );
    }

    /**
     * Sync standar dengan pengecekan file R2
     * 
     * ## OPTIONS
     * [--batch-size=<size>]
     * : Jumlah gambar per batch.
     * ---
     * default: 50
     * ---
     * 
     * ## EXAMPLES
     *     wp r2-offload sync --batch-size=100
     */
    public function sync( $args, $assoc_args ) {
        global $wpdb;
        $batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 50;
        $cdn_domain = rtrim( get_option( 'r2_cdn_domain' ), '/' );

        if ( empty( $cdn_domain ) ) {
            WP_CLI::error( "Custom Domain CDN URL belum dikonfigurasi di admin WordPress!" );
            return;
        }

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

        if ( empty( $attachments ) ) {
            WP_CLI::success( "Seluruh gambar lama telah berhasil di-sync ke Cloudflare R2!" );
            return;
        }

        $count = count( $attachments );
        WP_CLI::log( "Memproses {$count} gambar dalam batch ini..." );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Sync R2 Progress', $count );
        $core     = R2_Core::get_instance();

        foreach ( $attachments as $post_id ) {
            $core->process_single_attachment_offload( $post_id );
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success( "Batch selesai disinkronkan!" );
    }

    /**
     * Membersihkan file -scaled redundan
     * 
     * ## OPTIONS
     * [--batch-size=<size>]
     * : Jumlah attachment yang diperiksa per batch.
     * ---
     * default: 50
     * ---
     * 
     * ## EXAMPLES
     *     wp r2-offload clean-scaled --batch-size=100
     * 
     * @subcommand clean-scaled
     */
    public function clean_scaled( $args, $assoc_args ) {
        global $wpdb;
        $batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 50;
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

        if ( empty( $attachments ) ) {
            WP_CLI::success( "Seluruh gambar telah diperiksa!" );
            return;
        }

        $count = count( $attachments );
        WP_CLI::log( "Memeriksa {$count} gambar..." );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Clean Scaled Progress', $count );
        $cleaned  = 0;
        $skipped  = 0;

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
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success( "Batch selesai! (Cleaned: {$cleaned}, Skipped: {$skipped})" );
    }
}

WP_CLI::add_command( 'r2-offload', 'R2_Offloader_CLI_Command' );