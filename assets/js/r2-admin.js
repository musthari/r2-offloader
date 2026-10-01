jQuery(document).ready(function($) {
    // Tes Koneksi API R2
    $('#btn-test-r2').click(function() {
        var $btn =$(this);
        var $res =$('#test-r2-result');
        $res.text('Menghubungkan...').css('color', '#666');
        $btn.prop('disabled', true);

        $.post(ajaxurl, {
            action: 'r2_test_connection',
            nonce: r2AdminData.nonce
        }, function(response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $res.text(response.data.message).css('color', 'green');
            } else {
                $res.text(response.data.message).css('color', 'red');
            }
        });
    });

    // Bulk Sync Media Library
    var isSyncing = false;
    var initialPending = r2AdminData.initialPending;

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
            nonce: r2AdminData.nonce
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

    // Scan Directory Server
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
            nonce: r2AdminData.nonce
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

    // Fast Migration
    $('#btn-fast-migrate').click(function() {
        if (!confirm('Gunakan fitur ini jika seluruh file SUDAH TERUNGGAH di R2. Sistem akan langsung memperbarui URL di Database secara instan. Lanjutkan?')) return;
        
        var $btn =$(this);
        var $status =$('#fast-migrate-status');
        $btn.prop('disabled', true);$status.text('Memproses Direct DB Migration...').css('color', '#007cba');

        $.post(ajaxurl, {
            action: 'r2_fast_migrate_db',
            nonce: r2AdminData.nonce
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

    // Rollback URL Ke Lokal
    var rollbackRunning = false;
    var rollbackTotalInitial = r2AdminData.totalR2;
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
            nonce: r2AdminData.nonce
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