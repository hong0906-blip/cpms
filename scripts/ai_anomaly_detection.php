<?php
/** 최신 입력 신뢰도 기준 현장별 이상징후 탐지 CLI. PHP 5.6 compatible. */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

if (isset($argv) && count($argv) > 1) {
    echo 'Date arguments are not supported' . PHP_EOL;
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiAnomalyDetectionService.php';

use App\Core\Db;
use App\Services\AiAnomalyDetectionService;

try {
    $result = AiAnomalyDetectionService::detectLatest(Db::pdo(), 'CLI');
    if (!is_array($result) || empty($result['ok'])) {
        echo 'Anomaly detection failed' . PHP_EOL;
        if (is_array($result) && !empty($result['message'])) echo 'Message: ' . $result['message'] . PHP_EOL;
        exit(is_array($result) && !empty($result['busy']) ? 2 : 1);
    }
    echo 'Anomaly detection completed' . PHP_EOL;
    echo 'Analysis date: ' . (isset($result['analysis_date'])?$result['analysis_date']:'-') . PHP_EOL;
    echo 'Target month: ' . (isset($result['target_ym'])?$result['target_ym']:'-') . PHP_EOL;
    echo 'Projects: ' . (isset($result['projects'])?(int)$result['projects']:0) . PHP_EOL;
    echo 'Normal: ' . (isset($result['normal'])?(int)$result['normal']:0) . PHP_EOL;
    echo 'Watch: ' . (isset($result['watch'])?(int)$result['watch']:0) . PHP_EOL;
    echo 'Warning: ' . (isset($result['warning'])?(int)$result['warning']:0) . PHP_EOL;
    echo 'Critical: ' . (isset($result['critical'])?(int)$result['critical']:0) . PHP_EOL;
    echo 'Insufficient: ' . (isset($result['insufficient'])?(int)$result['insufficient']:0) . PHP_EOL;
    echo 'Anomalies: ' . (isset($result['anomalies'])?(int)$result['anomalies']:0) . PHP_EOL;
    echo 'Failed: ' . (isset($result['failed'])?(int)$result['failed']:0) . PHP_EOL;
    exit(isset($result['status']) && $result['status']==='PARTIAL'?2:0);
} catch (Exception $e) {
    error_log('[AiAnomalyDetection] CLI run failed');
    echo 'Anomaly detection failed' . PHP_EOL;
    exit(1);
}
