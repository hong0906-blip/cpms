<?php
/**
 * 최신 일일 스냅샷 기준 기본 월말 예측 CLI.
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
require_once dirname(__DIR__) . '/app/services/AiMonthlyForecastService.php';

use App\Core\Db;
use App\Services\AiMonthlyForecastService;

try {
    $result = AiMonthlyForecastService::forecastLatest(Db::pdo(), 'CLI');
    if (empty($result['ok'])) {
        $message = !empty($result['message']) ? $result['message'] : 'Forecast failed';
        echo $message . PHP_EOL;
        exit(!empty($result['busy']) ? 2 : 1);
    }

    echo 'Forecast completed' . PHP_EOL;
    echo 'Forecast date: ' . (isset($result['forecast_date']) ? $result['forecast_date'] : '-') . PHP_EOL;
    echo 'Target month: ' . (isset($result['target_ym']) ? $result['target_ym'] : '-') . PHP_EOL;
    echo 'Projects: ' . (isset($result['projects']) ? (int)$result['projects'] : 0) . PHP_EOL;
    echo 'Success: ' . (isset($result['success']) ? (int)$result['success'] : 0) . PHP_EOL;
    echo 'Insufficient: ' . (isset($result['insufficient']) ? (int)$result['insufficient'] : 0) . PHP_EOL;
    echo 'Failed: ' . (isset($result['failed']) ? (int)$result['failed'] : 0) . PHP_EOL;
    exit(isset($result['status']) && $result['status'] === 'PARTIAL' ? 2 : 0);
} catch (Exception $e) {
    error_log('[AiMonthlyForecast] CLI run failed');
    echo 'Forecast failed' . PHP_EOL;
    exit(1);
}
