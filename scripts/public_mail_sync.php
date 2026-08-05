<?php
/**
 * 파일 경로: C:\www\cpms\scripts\public_mail_sync.php
 *
 * Windows 작업 스케줄러에서 1분마다 실행하는 네이버 메일 백그라운드 동기화 파일입니다.
 * PHP 5.6 호환 코드입니다.
 *
 * 동작 방식
 * 1. 최근 1년 초기수집이 끝나지 않았으면 초기수집을 계속 진행합니다.
 * 2. 초기수집이 끝났으면 새 메일만 확인합니다.
 * 3. 동시에 두 작업이 실행되지 않도록 잠금 파일을 사용합니다.
 * 4. 네이버 원본메일의 읽음, 삭제, 이동 상태는 변경하지 않습니다.
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "이 파일은 Windows 작업 스케줄러의 PHP CLI에서만 실행할 수 있습니다.\n";
    exit(1);
}

@date_default_timezone_set('Asia/Seoul');
@set_time_limit(0);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

$cpmsRoot = dirname(__DIR__);
@chdir($cpmsRoot);

require_once $cpmsRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $cpmsRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'PublicMailStorageService.php';
require_once $cpmsRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'PublicMailService.php';

use App\Services\PublicMailService;
use App\Services\PublicMailStorageService;

/**
 * 민감정보 없이 백그라운드 실행결과만 기록합니다.
 */
function cpms_public_mail_background_log($message)
{
    try {
        PublicMailStorageService::ensureStorage();
        $path = PublicMailStorageService::path('background_sync.log');

        // 로그가 2MB보다 커지면 이전 로그 한 개만 보관합니다.
        if (is_file($path) && @filesize($path) !== false && @filesize($path) > 2097152) {
            $oldPath = PublicMailStorageService::path('background_sync.previous.log');
            @unlink($oldPath);
            @rename($path, $oldPath);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . trim((string)$message) . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        // 로그 저장 실패가 메일 동기화 자체를 막지 않도록 합니다.
    }
}

$backgroundLock = null;
$exitCode = 0;

try {
    PublicMailStorageService::ensureStorage();

    // 이전 실행이 아직 끝나지 않았다면 이번 실행은 정상적으로 건너뜁니다.
    $backgroundLock = PublicMailStorageService::acquireLock('background_sync');
    if ($backgroundLock === false) {
        cpms_public_mail_background_log('SKIP previous background job is still running');
        exit(0);
    }

    $settings = PublicMailStorageService::getSettings(false);
    $runAt = date('Y-m-d H:i:s');

    if (empty($settings['enabled'])) {
        PublicMailStorageService::saveSyncState(array(
            'background_last_run_at' => $runAt,
            'background_last_result' => 'disabled',
            'background_last_message' => '공용메일 연동이 사용 안 함 상태입니다.'
        ));
        cpms_public_mail_background_log('SKIP mail integration disabled');
        exit(0);
    }

    $state = PublicMailStorageService::getSyncState();
    $mode = !empty($state['completed_initial_sync']) ? 'new' : 'initial';
    $limit = isset($settings['batch_size']) ? (int)$settings['batch_size'] : 50;
    if ($limit < 10) $limit = 10;
    if ($limit > 100) $limit = 100;

    $service = new PublicMailService();
    $result = $service->syncBatch($limit, $mode);

    $addedCount = isset($result['added_count']) ? (int)$result['added_count'] : 0;
    $remainingCount = isset($result['remaining_count']) ? (int)$result['remaining_count'] : 0;
    $message = isset($result['message']) ? trim((string)$result['message']) : '동기화가 완료되었습니다.';

    PublicMailStorageService::saveSyncState(array(
        'background_last_run_at' => $runAt,
        'background_last_success_at' => date('Y-m-d H:i:s'),
        'background_last_result' => 'success',
        'background_last_message' => $message,
        'background_last_mode' => $mode,
        'background_last_added_count' => $addedCount,
        'background_last_remaining_count' => $remainingCount,
        'background_php_version' => PHP_VERSION
    ));

    cpms_public_mail_background_log(
        'OK mode=' . $mode
        . ' added=' . $addedCount
        . ' remaining=' . $remainingCount
    );
} catch (Exception $e) {
    $exitCode = 1;
    $safeMessage = trim((string)$e->getMessage());
    if ($safeMessage === '') $safeMessage = '알 수 없는 동기화 오류입니다.';

    try {
        PublicMailStorageService::saveSyncState(array(
            'background_last_run_at' => date('Y-m-d H:i:s'),
            'background_last_error_at' => date('Y-m-d H:i:s'),
            'background_last_result' => 'error',
            'background_last_message' => $safeMessage
        ));
    } catch (Exception $stateException) {
        // 상태 저장 실패는 아래 로그 기록만 시도합니다.
    }

    cpms_public_mail_background_log('ERROR ' . preg_replace('/[\r\n]+/', ' ', $safeMessage));
} finally {
    if ($backgroundLock !== null) {
        PublicMailStorageService::releaseLock($backgroundLock);
    }
}

exit($exitCode);
