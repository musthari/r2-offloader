<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class R2_Offloader_CLI_Command {

    /**
     * Sinkronisasi gambar lama ke Cloudflare R2
     * 
     * ## OPTIONS
     * [--batch-size=<size>]
     * : Jumlah gambar yang diproses per batch.
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
     * Membersihkan file -scaled redundan lama secara aman
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
            WP_CLI::success( "Seluruh gambar telah diperiksa. Tidak ada file -scaled yang perlu dibersihkan!" );
            return;
        }

        $count = count( $attachments );
        WP_CLI::log( "Memeriksa {$count} gambar untuk file -scaled..." );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Clean Scaled Progress', $count );
        $cleaned  = 0;
        $skipped  = 0;

        foreach ( $attachments as $post_id ) {
            $relative_path = get_post_meta( $post_id, '_wp_attached_file', true );

            if ( $relative_path && strpos( $relative_path, '-scaled.' ) !== false ) {
                $original_relative_path = preg_replace( '/-scaled\./', '.', $relative_path );
                
                $scaled_local_file   = $base_dir . $relative_path;
                $original_local_file = $base_dir . $original_relative_path;

                // Memastikan file original lokal ada sebelum melakukan pembersihan
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
        WP_CLI::success( "Batch selesai! (Berhasil Dihapus: {$cleaned}, Di-skip: {$skipped})" );
    }
}

WP_CLI::add_command( 'r2-offload', 'R2_Offloader_CLI_Command' );