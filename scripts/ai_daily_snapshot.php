<?php
/**
 * 서버 예약 실행용 일일 스냅샷 CLI.
 * PHP 5.6 compatible.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiDailySnapshotService.php';

$snapshotResult = \App\Services\AiDailySnapshotService::captureToday(\App\Core\Db::pdo(), 'CLI');

if (!is_array($snapshotResult) || empty($snapshotResult['ok'])) {
    echo "Snapshot failed\n";
    echo 'Date: ' . (is_array($snapshotResult) && isset($snapshotResult['date']) ? $snapshotResult['date'] : date('Y-m-d')) . "\n";
    echo 'Message: ' . (is_array($snapshotResult) && isset($snapshotResult['message']) ? $snapshotResult['message'] : '스냅샷 실행에 실패했습니다.') . "\n";
    exit(1);
}

echo "Snapshot completed\n";
echo 'Date: ' . $snapshotResult['date'] . "\n";
echo 'Projects: ' . (int)$snapshotResult['projects'] . "\n";
echo 'Success: ' . (int)$snapshotResult['success'] . "\n";
echo 'Failed: ' . (int)$snapshotResult['failed'] . "\n";
exit($snapshotResult['status'] === 'PARTIAL' ? 2 : 0);
