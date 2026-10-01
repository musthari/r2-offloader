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
}

WP_CLI::add_command( 'r2-offload', 'R2_Offloader_CLI_Command' );