<?php
/**
 * Task attachment loading/performance regression guards.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_task_file_performance_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$appJs = file_get_contents($root . '/public/assets/js/app.js');
$detail = file_get_contents($root . '/app/views/tasks/detail.php');
$helpers = file_get_contents($root . '/app/views/tasks/helpers.php');
$fileDownload = file_get_contents($root . '/app/views/tasks/file.php');
$zipDownload = file_get_contents($root . '/app/views/tasks/files_download.php');
$footer = file_get_contents($root . '/app/views/layout/footer.php');
$router = file_get_contents($root . '/public/index.php');

cpms_task_file_performance_guard(
    'task downloads are excluded from the global page-navigation loader',
    strpos($appJs, "r=tasks\\/files_download") !== false
    && strpos($appJs, "r=tasks\\/file") !== false
    && strpos($appJs, "download=1") !== false
    && strpos($appJs, "shouldSkipLoadingUrl(form.getAttribute('action') || '')") !== false
);
cpms_task_file_performance_guard(
    'task download controls explicitly opt out of global loading',
    substr_count($detail, 'data-cpms-no-loading="1"') >= 4
);
cpms_task_file_performance_guard(
    'uploaded files are saved locally before deferred Drive work',
    strpos($helpers, '@move_uploaded_file($tmpName, $absolutePath)') !== false
    && strpos($helpers, "['upload_status'] = 'pending'") !== false
    && strpos($helpers, 'function cpms_tasks_process_pending_drive_files') !== false
);
cpms_task_file_performance_guard(
    'completion notifications are queued for deferred delivery',
    strpos($helpers, 'function cpms_tasks_queue_deferred_notification') !== false
    && strpos($helpers, "'TASK_COMPLETED'") !== false
    && strpos($helpers, "'TASK_COMPLETION_PENDING'") !== false
    && strpos($helpers, 'function cpms_tasks_process_deferred_notifications') !== false
);
cpms_task_file_performance_guard(
    'deferred task work runs automatically without a cron task',
    strpos($footer, "window.fetch('?r=tasks/deferred_sync'") !== false
    && strpos($footer, 'keepalive: true') !== false
    && strpos($router, "if (\$route === 'tasks/deferred_sync')") !== false
);
cpms_task_file_performance_guard(
    'single and ZIP downloads release the PHP session lock',
    strpos($fileDownload, "session_write_close") !== false
    && strpos($zipDownload, "session_write_close") !== false
);
$localSinglePosition = strpos($fileDownload, '$path = cpms_tasks_local_file_path');
$driveSinglePosition = strpos($fileDownload, 'cpms_drive_download_file');
$localZipPosition = strpos($zipDownload, '$localPath = cpms_tasks_local_file_path');
$driveZipPosition = strpos($zipDownload, 'cpms_drive_download_file');
cpms_task_file_performance_guard(
    'local cached files are preferred before Google Drive downloads',
    $localSinglePosition !== false && $driveSinglePosition !== false && $localSinglePosition < $driveSinglePosition
    && $localZipPosition !== false && $driveZipPosition !== false && $localZipPosition < $driveZipPosition
);
cpms_task_file_performance_guard(
    'task schema checks use a lightweight ready fast path',
    strpos($helpers, 'function cpms_tasks_schema_ready_quick') !== false
    && strpos($helpers, 'cpms_tasks_schema_ready_quick($pdo)') !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " task file performance checks\n";
