<?php
/**
 * 저장된 최신 월말 예측을 기준으로 현장별 손익 위험 분석을 실행한다.
 * PHP 5.6 CLI 전용.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

if (isset($argv) && count($argv) > 1) {
    echo "Arguments are not supported.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiProfitRiskService.php';

use App\Core\Db;
use App\Services\AiProfitRiskService;

try {
    $result = AiProfitRiskService::analyzeLatest(Db::pdo(), 'CLI');
    if (empty($result['ok'])) {
        echo "Profit risk analysis failed\n";
        echo isset($result['message']) ? $result['message'] . "\n" : "Please check the saved forecast and setup status.\n";
        exit(1);
    }

    echo "Profit risk analysis completed\n";
    echo 'Analysis date: ' . (isset($result['analysis_date']) ? $result['analysis_date'] : '-') . "\n";
    echo 'Target month: ' . (isset($result['target_ym']) ? $result['target_ym'] : '-') . "\n";
    echo 'Projects: ' . (isset($result['projects']) ? (int)$result['projects'] : 0) . "\n";
    echo 'Normal: ' . (isset($result['normal']) ? (int)$result['normal'] : 0) . "\n";
    echo 'Watch: ' . (isset($result['watch']) ? (int)$result['watch'] : 0) . "\n";
    echo 'Warning: ' . (isset($result['warning']) ? (int)$result['warning'] : 0) . "\n";
    echo 'Critical: ' . (isset($result['critical']) ? (int)$result['critical'] : 0) . "\n";
    echo 'Insufficient: ' . (isset($result['insufficient']) ? (int)$result['insufficient'] : 0) . "\n";
    echo 'Failed: ' . (isset($result['failed']) ? (int)$result['failed'] : 0) . "\n";
    exit(0);
} catch (Exception $e) {
    error_log('[AiProfitRisk] CLI run failed');
    echo "Profit risk analysis failed\n";
    exit(1);
}
