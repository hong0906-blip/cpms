<?php
/** Daily AI pipeline CLI entry. PHP 5.6 compatible. */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit(1); }
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Seoul');
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiDailyPipelineService.php';

use App\Core\Db;
use App\Services\AiDailyPipelineService;

$force = false;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) if ((string)$arg === '--force=1') $force = true;
}
try {
    $pdo = Db::pdo();
    $result = AiDailyPipelineService::run($pdo, 'CLI', $force);
    echo "AI daily pipeline\n";
    echo 'Status: ' . (isset($result['status']) ? $result['status'] : 'FAILED') . "\n";
    echo 'Message: ' . (isset($result['message']) ? $result['message'] : '실행 결과를 확인하지 못했습니다.') . "\n";
    $steps = isset($result['steps']) && is_array($result['steps']) ? $result['steps'] : array();
    foreach ($steps as $stepName => $stepResult) {
        $stepStatus = isset($stepResult['status']) ? (string)$stepResult['status'] : 'UNKNOWN';
        echo 'Step ' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$stepName) . ': ' . $stepStatus . "\n";
    }
    exit(!empty($result['ok']) || (isset($result['status']) && $result['status'] === 'SKIPPED') ? 0 : 1);
} catch (Exception $e) {
    error_log('[AI Pipeline CLI] execution failed');
    echo "AI daily pipeline\nStatus: FAILED\n";
    exit(1);
}
