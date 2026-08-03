<?php
/**
 * 최신 월말 예측 기준 입력 신뢰도 분석 CLI.
 * PHP 5.6 compatible.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

if (isset($argv) && count($argv) > 1) {
    echo 'Date arguments are not supported' . PHP_EOL;
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiInputReliabilityService.php';

use App\Core\Db;
use App\Services\AiInputReliabilityService;

try {
    $result = AiInputReliabilityService::calculateLatest(Db::pdo(), 'CLI');
    if (!is_array($result) || empty($result['ok'])) {
        echo 'Input reliability analysis failed' . PHP_EOL;
        if (is_array($result) && !empty($result['message'])) echo 'Message: ' . $result['message'] . PHP_EOL;
        exit(is_array($result) && !empty($result['busy']) ? 2 : 1);
    }

    echo 'Reliability completed' . PHP_EOL;
    echo 'Analysis date: ' . (isset($result['analysis_date']) ? $result['analysis_date'] : '-') . PHP_EOL;
    echo 'Target month: ' . (isset($result['target_ym']) ? $result['target_ym'] : '-') . PHP_EOL;
    echo 'Projects: ' . (isset($result['projects']) ? (int)$result['projects'] : 0) . PHP_EOL;
    echo 'High: ' . (isset($result['high']) ? (int)$result['high'] : 0) . PHP_EOL;
    echo 'Good: ' . (isset($result['good']) ? (int)$result['good'] : 0) . PHP_EOL;
    echo 'Caution: ' . (isset($result['caution']) ? (int)$result['caution'] : 0) . PHP_EOL;
    echo 'Low: ' . (isset($result['low']) ? (int)$result['low'] : 0) . PHP_EOL;
    echo 'Insufficient: ' . (isset($result['insufficient']) ? (int)$result['insufficient'] : 0) . PHP_EOL;
    echo 'Failed: ' . (isset($result['failed']) ? (int)$result['failed'] : 0) . PHP_EOL;
    exit(isset($result['status']) && $result['status'] === 'PARTIAL' ? 2 : 0);
} catch (Exception $e) {
    error_log('[AiInputReliability] CLI run failed');
    echo 'Input reliability analysis failed' . PHP_EOL;
    exit(1);
}
