<?php
/**
 * Daily AI pipeline CLI entry. PHP 5.6 compatible.
 *
 * Cron (Asia/Seoul, every day including weekends and holidays):
 * 0 19 * * * /usr/bin/php /path/to/cpms/tools/ai_daily_pipeline_job.php
 *
 * Options:
 * --force=1       Run again even when today's pipeline already succeeded.
 * --setup-only=1  Install/update the complete pipeline schema without analysis.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Seoul');
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiDailyPipelineService.php';

use App\Core\Db;
use App\Services\AiDailyPipelineService;

$force = false;
$setupOnly = false;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if ((string)$arg === '--force=1') $force = true;
        if ((string)$arg === '--setup-only=1') $setupOnly = true;
    }
}

/* 파일 잠금 + 서비스의 MySQL GET_LOCK으로 중복 실행을 이중 차단한다. */
$lockPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cpms_ai_daily_pipeline_job.lock';
$lockHandle = @fopen($lockPath, 'c');
if (!$lockHandle) {
    echo '[' . date('Y-m-d H:i:s') . "] AI daily pipeline\nStatus: FAILED\nMessage: 실행 잠금 파일을 열지 못했습니다.\n";
    exit(1);
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] AI daily pipeline\nStatus: SKIPPED\nMessage: 다른 자동 파이프라인이 이미 실행 중입니다.\n";
    fclose($lockHandle);
    exit(0);
}

$exitCode = 1;
try {
    $pdo = Db::pdo();

    /* 최초 배포나 스키마 변경 후에도 다음 크론 실행이 자동으로 준비한다. */
    if ($setupOnly || !AiDailyPipelineService::isInstalled($pdo)) {
        $installResult = AiDailyPipelineService::installOrUpdate($pdo);
        echo '[' . date('Y-m-d H:i:s') . "] AI daily pipeline setup\n";
        echo 'Status: ' . (!empty($installResult['ok']) ? 'SUCCESS' : 'FAILED') . "\n";
        echo 'Message: ' . (isset($installResult['message']) ? $installResult['message'] : '설치 결과를 확인하지 못했습니다.') . "\n";
        if (empty($installResult['ok'])) {
            $exitCode = 1;
        } elseif ($setupOnly) {
            $exitCode = 0;
        }
    }

    if (!$setupOnly && AiDailyPipelineService::isInstalled($pdo)) {
        $result = AiDailyPipelineService::run($pdo, 'CLI', $force);
        $status = isset($result['status']) ? strtoupper((string)$result['status']) : 'FAILED';
        echo '[' . date('Y-m-d H:i:s') . "] AI daily pipeline\n";
        echo 'Status: ' . $status . "\n";
        echo 'Message: ' . (isset($result['message']) ? $result['message'] : '실행 결과를 확인하지 못했습니다.') . "\n";
        $steps = isset($result['steps']) && is_array($result['steps']) ? $result['steps'] : array();
        foreach ($steps as $stepName => $stepResult) {
            $stepStatus = isset($stepResult['status']) ? (string)$stepResult['status'] : 'UNKNOWN';
            echo 'Step ' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$stepName) . ': ' . $stepStatus . "\n";
        }
        if ($status === 'SUCCESS' || $status === 'SKIPPED') $exitCode = 0;
        else if ($status === 'PARTIAL') $exitCode = 2;
        else $exitCode = 1;
    }
} catch (Exception $e) {
    error_log('[AI Pipeline CLI] execution failed: ' . $e->getMessage());
    echo '[' . date('Y-m-d H:i:s') . "] AI daily pipeline\nStatus: FAILED\nMessage: 자동 파이프라인 실행 중 오류가 발생했습니다.\n";
    $exitCode = 1;
}

@flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit($exitCode);
