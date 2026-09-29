jQuery(function ($) {
  const adminAjax = r2OffloaderAdmin.ajax_url;
  const nonce = r2OffloaderAdmin.nonce;

  function setProgress(target, percent) {
    const bar = document.getElementById(target + '-bar');
    const label = document.getElementById(target + '-label');
    if (bar) {
      bar.style.width = percent + '%';
    }
    if (label) {
      label.textContent = percent + '%';
    }
  }

  function setStatus(target, message, type = '') {
    const el = document.getElementById(target);
    if (!el) return;
    el.className = 'r2offloader-status ' + (type ? type : '');
    el.textContent = message;
  }

  function ajaxCall(action, data, onSuccess, onError) {
    $.ajax({
      url: adminAjax,
      type: 'POST',
      dataType: 'json',
      data: $.extend({ action: action, nonce: nonce }, data),
      success: function (response) {
        if (response && response.success) {
          if (onSuccess) onSuccess(response.data);
          return;
        }
        if (onError) {
          onError(response && response.data ? response.data : { message: 'Error' });
        }
      },
      error: function (xhr, status, err) {
        if (onError) onError({ message: err || 'Request failed' });
      }
    });
  }

  function runProgressLoop({
    action,
    target,
    offset = 0,
    batchSize = 10,
    statusSelector,
    onFinal
  }) {
    ajaxCall(action, { offset: offset, batch_size: batchSize }, function (data) {
      const percent = data.percent || 0;
      setProgress(target, percent);

      if (statusSelector) {
        setStatus(statusSelector, data.message || 'Memproses...', percent >= 100 ? 'success' : '');
      }

      if (data.completed || percent >= 100) {
        setProgress(target, 100);
        if (onFinal) onFinal(data);
        return;
      }

      const nextOffset = Number(data.next_offset || 0);
      setTimeout(function () {
        runProgressLoop({ action, target, offset: nextOffset, batchSize, statusSelector, onFinal });
      }, 250);
    }, function (data) {
      setStatus(statusSelector, data.message || 'Gagal.', 'error');
    });
  }

  $('#r2offloader-settings-form').on('submit', function (e) {
    e.preventDefault();
    const formData = $(this).serialize();
    ajaxCall('r2offloader_save_settings', formData, function (data) {
      setStatus('r2offloader-bulk-status', data.message || 'Pengaturan tersimpan.', 'success');
    }, function (data) {
      setStatus('r2offloader-bulk-status', data.message || 'Gagal menyimpan.', 'error');
    });
  });

  $('#r2offloader-test-connection').on('click', function () {
    ajaxCall('r2offloader_test_connection', {}, function (data) {
      setStatus('r2offloader-bulk-status', data.message || 'OK', 'success');
    }, function (data) {
      setStatus('r2offloader-bulk-status', data.message || 'Koneksi gagal.', 'error');
    });
  });

  $('#r2offloader-bulk-sync').on('click', function () {
    const batchSize = Number($('#batch_size').val()) || 10;
    setProgress('r2offloader-bulk-progress', 0);
    setStatus('r2offloader-bulk-status', 'Memulai bulk sync...', '');
    runProgressLoop({
      action: 'r2offloader_bulk_sync',
      target: 'r2offloader-bulk-progress',
      offset: 0,
      batchSize: batchSize,
      statusSelector: 'r2offloader-bulk-status',
      onFinal: function () {
        setStatus('r2offloader-bulk-status', 'Bulk sync selesai.', 'success');
      }
    });
  });

  $('#r2offloader-regen-local').on('click', function () {
    const batchSize = Number($('#batch_size').val()) || 10;
    setProgress('r2offloader-regen-progress', 0);
    setStatus('r2offloader-regen-status', 'Memulai regenerasi thumbnail lokal...', '');
    runProgressLoop({
      action: 'r2offloader_regen_local_thumbs',
      target: 'r2offloader-regen-progress',
      offset: 0,
      batchSize: batchSize,
      statusSelector: 'r2offloader-regen-status',
      onFinal: function () {
        setStatus('r2offloader-regen-status', 'Regenerasi lokal selesai.', 'success');
      }
    });
  });

  $('#r2offloader-rollback-db').on('click', function () {
    const batchSize = Number($('#batch_size').val()) || 10;
    setProgress('r2offloader-rollback-progress', 0);
    setStatus('r2offloader-rollback-status', 'Memulai rollback database...', '');
    runProgressLoop({
      action: 'r2offloader_rollback_db',
      target: 'r2offloader-rollback-progress',
      offset: 0,
      batchSize: batchSize,
      statusSelector: 'r2offloader-rollback-status',
      onFinal: function () {
        setStatus('r2offloader-rollback-status', 'Rollback database selesai.', 'success');
      }
    });
  });
});
