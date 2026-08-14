<?php
/**
 * Electronic approval Drive performance regression guards.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_approval_drive_performance_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$storeSource = file_get_contents($root . '/app/views/approval/store.php');
$decideSource = file_get_contents($root . '/app/views/approval/decide.php');
$detailSource = file_get_contents($root . '/app/views/approval/detail.php');
$fileSource = file_get_contents($root . '/app/views/approval/file.php');
$completedPdfSource = file_get_contents($root . '/app/views/approval/completed_pdf.php');
$driveSource = file_get_contents($root . '/app/services/GoogleDriveHelper.php');
$pdfSource = file_get_contents($root . '/app/services/ApprovalPdfService.php');
$commonSource = file_get_contents($root . '/app/views/approval/_common.php');
$routerSource = file_get_contents($root . '/public/index.php');

cpms_approval_drive_performance_guard(
    'document save queues attachments instead of synchronously calling Drive',
    strpos($storeSource, 'cpms_approval_drive_pending_record') !== false
        && strpos($storeSource, 'cpms_approval_drive_upload_local_file') === false
);
cpms_approval_drive_performance_guard(
    'final approval queues completed PDF generation instead of running it inline',
    strpos($decideSource, "'completed_pdf_upload_status' => 'pending'") !== false
        && strpos($decideSource, 'cpms_approval_pdf_upload_completed_pdf') === false
);
cpms_approval_drive_performance_guard(
    'detail page dispatches the protected deferred Drive worker',
    strpos($detailSource, "window.fetch('?r=approval_deferred_sync'") !== false
        && strpos($detailSource, '$detailPdfCacheMissing') !== false
        && strpos($routerSource, "\$route === 'approval_deferred_sync'") !== false
);
cpms_approval_drive_performance_guard(
    'Drive downloads redirect directly instead of proxying normal files through PHP',
    strpos($fileSource, "header('Location: ' . \$driveFallbackUrl)") !== false
        && strpos($fileSource, "header('Location: ' . \$driveFallbackUrl)") < strpos($fileSource, 'cpms_drive_download_file')
);
cpms_approval_drive_performance_guard(
    'completed PDF generation seeds the protected cache and removes temporary files',
    substr_count($pdfSource, "cpms_approval_pdf_cleanup_temp_file(\$pdf['path'])") >= 4
        && strpos($pdfSource, 'cpms_approval_pdf_cache_store_file') !== false
        && strpos($completedPdfSource, 'cpms_approval_pdf_local_path') === false
);
cpms_approval_drive_performance_guard(
    'completed PDFs use protected local cache with authenticated Drive fallback',
    strpos($completedPdfSource, 'cpms_drive_download_file($fileId)') !== false
        && strpos($completedPdfSource, 'cpms_approval_pdf_cache_get_path($fileId') < strpos($completedPdfSource, 'cpms_drive_download_file($fileId)')
        && strpos($completedPdfSource, 'cpms_approval_pdf_cache_store_content($fileId') !== false
        && strpos($completedPdfSource, "header('Location: '") === false
);
cpms_approval_drive_performance_guard(
    'completed PDF cache hits stream directly without buffering the whole file',
    strpos($completedPdfSource, '@readfile($cachedPath)') !== false
        && strpos($completedPdfSource, '@session_write_close()') !== false
);
cpms_approval_drive_performance_guard(
    'existing completed PDFs warm their cache in the deferred worker',
    strpos(file_get_contents($root . '/app/views/approval/deferred_sync.php'), 'completed_pdf_cache') !== false
        && strpos(file_get_contents($root . '/app/views/approval/deferred_sync.php'), 'cpms_approval_pdf_cache_store_content($pdfFileId') !== false
);
cpms_approval_drive_performance_guard(
    'attachment temporary files are deleted after Drive metadata is saved',
    strpos(file_get_contents($root . '/app/services/ApprovalDriveService.php'), 'cpms_approval_drive_remove_local_copy') !== false
        && strpos(file_get_contents($root . '/app/services/ApprovalDriveService.php'), "UPDATE cpms_approval_files SET file_path='', saved_name=''") !== false
);
cpms_approval_drive_performance_guard(
    'Drive OAuth tokens and folder lookups use persistent caches',
    strpos($driveSource, 'Access token loaded from server cache.') !== false
        && strpos($driveSource, 'cpms_drive_folder_cache_get') !== false
);
cpms_approval_drive_performance_guard(
    'approval schema checks load each table column list once',
    strpos($commonSource, 'function approval_table_columns') !== false
        && strpos($commonSource, 'SHOW COLUMNS FROM') !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " approval Drive performance guards\n";
