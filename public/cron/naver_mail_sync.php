<?php
/**
 * 파일 경로: C:\www\cpms\public\cron\naver_mail_sync.php
 *
 * cron-job.org 같은 외부 예약서비스가 호출하는 네이버 메일 자동동기화 주소입니다.
 * 직원 브라우저에서는 이 파일을 자동 호출하지 않으므로 CPMS 화면 로딩을 방해하지 않습니다.
 * 보안키는 URL이 아니라 X-CPMS-Mail-Key 요청 헤더로 전달합니다.
 * PHP 5.6 호환 코드입니다.
 */
require_once dirname(dirname(__DIR__)) . '/app/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/app/services/PublicMailService.php';
require_once dirname(dirname(__DIR__)) . '/app/services/PublicMailStorageService.php';

use App\Services\PublicMailService;
use App\Services\PublicMailStorageService;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
if (function_exists('session_write_close')) @session_write_close();
@ignore_user_abort(true);
@set_time_limit(180);

function pm_cron_header_value($name)
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', (string)$name));
    if (isset($_SERVER[$serverKey])) return trim((string)$_SERVER[$serverKey]);
    if (function_exists('getallheaders')) {
        $headers = @getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string)$key, (string)$name) === 0) return trim((string)$value);
            }
        }
    }
    return '';
}

function pm_cron_finish_response($text)
{
    $text = trim((string)$text);
    if ($text === '') $text = 'OK';
    if (strlen($text) > 500) $text = substr($text, 0, 500);
    header('Content-Length: ' . strlen($text));
    header('Connection: close');
    echo $text;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return true;
    }
    @ob_flush();
    @flush();
    return false;
}

$key = pm_cron_header_value('X-CPMS-Mail-Key');
$service = new PublicMailService();
if (!$service->verifyCronToken($key)) {
    http_response_code(403);
    echo 'FORBIDDEN';
    exit;
}

$startedAt = microtime(true);
$startedText = date('Y-m-d H:i:s');
$currentState = PublicMailStorageService::getSyncState();
$currentRepair = isset($currentState['metadata_repair']) && is_array($currentState['metadata_repair']) ? $currentState['metadata_repair'] : array();
$currentRepair['last_http_ping_at'] = $startedText;
$currentRepair['last_http_status'] = '200 ACCEPTED';
PublicMailStorageService::saveSyncState(array(
    'last_cron_at' => $startedText,
    'last_cron_started_at' => $startedText,
    'last_cron_status' => 'running',
    'last_cron_result' => '자동동기화 실행 중',
    'metadata_repair' => $currentRepair
));

$continuedInBackground = pm_cron_finish_response('ACCEPTED');

try {
    /*
     * 외부 서비스는 보통 30초 안에 응답을 요구합니다.
     * PHP-FPM에서는 응답을 먼저 끝낸 뒤 서버에서 계속 처리합니다.
     * 그 외 환경에서는 한 번에 10건만 처리하여 시간초과 가능성을 낮춥니다.
     */
    $limit = $continuedInBackground ? 30 : 10;
    $result = $service->runAutomationTick($limit);
    $message = isset($result['message']) ? trim((string)$result['message']) : '완료';
    $added = isset($result['added_count']) ? (int)$result['added_count'] : 0;
    $state = isset($result['state']) && is_array($result['state']) ? $result['state'] : PublicMailStorageService::getSyncState();
    $remaining = isset($state['full_import']['remaining_count']) ? (int)$state['full_import']['remaining_count'] : (isset($state['remaining_count']) ? (int)$state['remaining_count'] : 0);
    $duration = (int)round((microtime(true) - $startedAt) * 1000);
    $repaired = isset($result['repaired_count']) ? (int)$result['repaired_count'] : 0;
    $repairRemaining = isset($state['metadata_repair']['remaining_count']) ? (int)$state['metadata_repair']['remaining_count'] : 0;
    $recentAdded = isset($state['recent_mail_recovery']['added_count']) ? (int)$state['recent_mail_recovery']['added_count'] : 0;
    $newFailureCount = isset($state['new_message_failures']) && is_array($state['new_message_failures']) ? count($state['new_message_failures']) : 0;
    $summary = '성공: ' . $message . ' / 추가 ' . $added . '건 / 메일 남음 ' . $remaining . '건 / 최근48시간 복구 ' . $recentAdded . '건 / 격리·재시도 ' . $newFailureCount . '건';
    $finishedText = date('Y-m-d H:i:s');
    $latestRepair = isset($state['metadata_repair']) && is_array($state['metadata_repair']) ? $state['metadata_repair'] : array();
    $latestRepair['last_http_ping_at'] = $finishedText;
    $latestRepair['last_http_status'] = '200 SUCCESS';
    PublicMailStorageService::saveSyncState(array(
        'last_cron_at' => $finishedText,
        'last_cron_finished_at' => $finishedText,
        'last_cron_status' => 'success',
        'last_cron_duration_ms' => $duration,
        'last_cron_result' => $summary,
        'metadata_repair' => $latestRepair
    ));
    if (!$continuedInBackground) {
        /* 이미 ACCEPTED를 출력했으므로 추가 출력은 하지 않습니다. */
    }
} catch (Exception $e) {
    $duration = (int)round((microtime(true) - $startedAt) * 1000);
    $errorText = date('Y-m-d H:i:s');
    $errorState = PublicMailStorageService::getSyncState();
    $errorRepair = isset($errorState['metadata_repair']) && is_array($errorState['metadata_repair']) ? $errorState['metadata_repair'] : array();
    $errorRepair['last_http_ping_at'] = $errorText;
    $errorRepair['last_http_status'] = '500 ERROR';
    PublicMailStorageService::saveSyncState(array(
        'last_cron_at' => $errorText,
        'last_cron_finished_at' => $errorText,
        'last_cron_status' => 'error',
        'last_cron_duration_ms' => $duration,
        'last_cron_result' => '오류: ' . $e->getMessage(),
        'metadata_repair' => $errorRepair
    ));
    if (!$continuedInBackground) {
        /* 예약서비스에는 이미 짧은 응답을 보냈고, 상세 오류는 설정 화면에 기록됩니다. */
    }
}
