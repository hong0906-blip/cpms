<?php
/**
 * CPMS AI Daily Pipeline Cron Manager
 *
 * 로컬 파일 경로:
 * C:\www\cpms\public\cron_manager.php
 *
 * 운영 서버 경로:
 * /home/cmbuild/www/cpms/public/cron_manager.php
 *
 * 기능:
 * - 현재 AI 일일 파이프라인 크론 등록상태 확인
 * - 매일 19:00 자동실행 등록
 * - 19:00 실행은 --force=1로 당일 선행 실행 여부와 관계없이 다시 실행
 * - 이 페이지에서 등록한 크론만 해제
 * - AI 파이프라인 즉시 강제 테스트 실행
 * - 테스트 직후 일일 스냅샷 실제 반영상태 확인
 *
 * 중요:
 * - 기존 crontab 내용은 보존합니다.
 * - 다른 크론 작업은 삭제하지 않습니다.
 * - 기존 AI 서비스 파일은 수정하지 않습니다.
 * - PHP 5.6 호환 문법만 사용합니다.
 * - 작업 완료 후 운영 서버에서 이 파일을 삭제하세요.
 */

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiDailySnapshotService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiDailySnapshotService;

if (!Auth::check()) {
    http_response_code(403);
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>접근 제한</title></head><body>';
    echo '<h2>접근 권한이 없습니다.</h2><p>CPMS에 로그인한 뒤 다시 접속하세요.</p>';
    echo '</body></html>';
    exit;
}

if (session_id() === '') {
    @session_start();
}

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Seoul');
}

/* ============================================================
 * 서버 기본 경로
 * ============================================================ */

$cmProjectRoot = realpath(dirname(__DIR__));
if ($cmProjectRoot === false) {
    $cmProjectRoot = dirname(__DIR__);
}

$cmJobFile = $cmProjectRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'ai_daily_pipeline_job.php';
$cmLogFile = $cmProjectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'ai_daily_pipeline_cron.log';
$cmCronPath = '/usr/bin/crontab';
$cmPhpPath = '/usr/local/php/bin/php';

$cmBeginMarker = '# CPMS_AI_DAILY_PIPELINE_BEGIN';
$cmEndMarker = '# CPMS_AI_DAILY_PIPELINE_END';

/* ============================================================
 * 공통 함수
 * ============================================================ */

function cmH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cmFunctionEnabled($functionName)
{
    if (!function_exists($functionName)) {
        return false;
    }

    $disabled = ini_get('disable_functions');

    if ($disabled === false || trim($disabled) === '') {
        return true;
    }

    $items = explode(',', $disabled);

    foreach ($items as $item) {
        if (trim($item) === $functionName) {
            return false;
        }
    }

    return true;
}

function cmRunCommand($command, &$exitCode)
{
    $exitCode = null;

    if (!cmFunctionEnabled('exec')) {
        return '';
    }

    $output = array();
    $code = 0;

    @exec($command . ' 2>&1', $output, $code);

    $exitCode = $code;

    return trim(implode("\n", $output));
}

function cmReadCrontab($cronPath)
{
    $code = null;

    $output = cmRunCommand(
        escapeshellarg($cronPath) . ' -l',
        $code
    );

    if ($code === 0) {
        return array(
            'ok' => true,
            'content' => $output,
            'message' => '현재 crontab을 읽었습니다.'
        );
    }

    $lower = strtolower($output);

    if (strpos($lower, 'no crontab') !== false) {
        return array(
            'ok' => true,
            'content' => '',
            'message' => '현재 등록된 crontab 작업이 없습니다.'
        );
    }

    return array(
        'ok' => false,
        'content' => '',
        'message' => $output !== ''
            ? $output
            : 'crontab을 읽지 못했습니다.'
    );
}

function cmRemoveManagedBlock($content, $beginMarker, $endMarker)
{
    $normalized = str_replace("\r\n", "\n", (string)$content);
    $normalized = str_replace("\r", "\n", $normalized);

    $lines = explode("\n", $normalized);

    $result = array();
    $inside = false;
    $found = false;
    $malformed = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === $beginMarker) {
            if ($inside) {
                $malformed = true;
                break;
            }

            $inside = true;
            $found = true;
            continue;
        }

        if ($trimmed === $endMarker) {
            if (!$inside) {
                $malformed = true;
                break;
            }

            $inside = false;
            continue;
        }

        if (!$inside) {
            $result[] = $line;
        }
    }

    if ($inside) {
        $malformed = true;
    }

    return array(
        'ok' => !$malformed,
        'found' => $found,
        'content' => rtrim(implode("\n", $result))
    );
}

function cmContainsPipelineOutsideManagedBlock($content)
{
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    if (!is_array($lines)) {
        return false;
    }

    foreach ($lines as $line) {
        if (strpos($line, 'ai_daily_pipeline_job.php') !== false) {
            return true;
        }
    }

    return false;
}

function cmWriteCrontab($cronPath, $content)
{
    $tempFile =
        rtrim(sys_get_temp_dir(), '/\\')
        . DIRECTORY_SEPARATOR
        . 'cpms_cron_manager_'
        . getmypid()
        . '_'
        . mt_rand(100000, 999999)
        . '.txt';

    $writeResult = @file_put_contents(
        $tempFile,
        (string)$content,
        LOCK_EX
    );

    if ($writeResult === false) {
        return array(
            'ok' => false,
            'message' => '임시 crontab 파일을 만들지 못했습니다.'
        );
    }

    @chmod($tempFile, 0600);

    $code = null;

    $output = cmRunCommand(
        escapeshellarg($cronPath)
        . ' '
        . escapeshellarg($tempFile),
        $code
    );

    @unlink($tempFile);

    if ($code !== 0) {
        return array(
            'ok' => false,
            'message' => $output !== ''
                ? $output
                : 'crontab 저장에 실패했습니다.'
        );
    }

    return array(
        'ok' => true,
        'message' => 'crontab 저장이 완료되었습니다.'
    );
}

function cmCsrfToken()
{
    if (
        !isset($_SESSION['cpms_cron_manager_csrf'])
        ||
        !is_string($_SESSION['cpms_cron_manager_csrf'])
    ) {

        $seed =
            uniqid('', true)
            . '|'
            . mt_rand()
            . '|'
            . microtime(true);

        if (function_exists('openssl_random_pseudo_bytes')) {

            $bytes = @openssl_random_pseudo_bytes(32);

            if ($bytes !== false) {
                $seed .= '|' . bin2hex($bytes);
            }
        }

        $_SESSION['cpms_cron_manager_csrf'] =
            hash('sha256', $seed);
    }

    return $_SESSION['cpms_cron_manager_csrf'];
}

function cmHashEquals($known, $user)
{
    $known = (string)$known;
    $user = (string)$user;

    if (function_exists('hash_equals')) {
        return hash_equals($known, $user);
    }

    if (strlen($known) !== strlen($user)) {
        return false;
    }

    $result = 0;
    $length = strlen($known);

    for ($i = 0; $i < $length; $i++) {
        $result |= ord($known[$i]) ^ ord($user[$i]);
    }

    return $result === 0;
}

/* ============================================================
 * 일일 스냅샷 상태 확인
 * ============================================================ */

function cmSnapshotStatus()
{
    $result = array(
        'ok' => false,
        'installed' => false,
        'business_date' => '',
        'latest_snapshot_date' => '',
        'last_captured_at' => '',
        'project_count' => 0,
        'snapshot_row_count' => 0,
        'latest_run_status' => '',
        'latest_run_started_at' => '',
        'latest_run_finished_at' => '',
        'message' => ''
    );

    try {
        $pdo = Db::pdo();

        if (!$pdo) {
            $result['message'] = 'DB 연결 상태를 확인하지 못했습니다.';
            return $result;
        }

        $status =
            AiDailySnapshotService::schemaStatus($pdo);

        $result['ok'] = true;
        $result['installed'] =
            !empty($status['installed']);

        $result['business_date'] =
            AiDailySnapshotService::businessToday();

        $result['latest_snapshot_date'] =
            isset($status['latest_snapshot_date'])
            ? (string)$status['latest_snapshot_date']
            : '';

        $result['last_captured_at'] =
            isset($status['last_captured_at'])
            ? (string)$status['last_captured_at']
            : '';

        $result['project_count'] =
            isset($status['project_count'])
            ? (int)$status['project_count']
            : 0;

        $result['snapshot_row_count'] =
            isset($status['snapshot_row_count'])
            ? (int)$status['snapshot_row_count']
            : 0;

        $latestRun =
            isset($status['latest_run'])
            && is_array($status['latest_run'])
            ? $status['latest_run']
            : array();

        $result['latest_run_status'] =
            isset($latestRun['run_status'])
            ? (string)$latestRun['run_status']
            : '';

        $result['latest_run_started_at'] =
            isset($latestRun['started_at'])
            ? (string)$latestRun['started_at']
            : '';

        $result['latest_run_finished_at'] =
            isset($latestRun['finished_at'])
            ? (string)$latestRun['finished_at']
            : '';

        return $result;

    } catch (Exception $e) {

        $result['message'] =
            '일일 스냅샷 상태를 확인하지 못했습니다.';

        return $result;
    }
}

function cmSnapshotWasRefreshed($before, $after)
{
    if (!is_array($before) || !is_array($after)) {
        return false;
    }

    if (
        empty($after['ok'])
        ||
        empty($after['installed'])
    ) {
        return false;
    }

    $businessDate =
        isset($after['business_date'])
        ? (string)$after['business_date']
        : '';

    $latestDate =
        isset($after['latest_snapshot_date'])
        ? (string)$after['latest_snapshot_date']
        : '';

    $afterCaptured =
        isset($after['last_captured_at'])
        ? (string)$after['last_captured_at']
        : '';

    $beforeCaptured =
        isset($before['last_captured_at'])
        ? (string)$before['last_captured_at']
        : '';

    if (
        $businessDate === ''
        ||
        $latestDate !== $businessDate
        ||
        $afterCaptured === ''
    ) {
        return false;
    }

    if ($beforeCaptured === '') {
        return true;
    }

    return $afterCaptured !== $beforeCaptured;
}

function cmExtractStepStatus($output, $stepName)
{
    $output = (string)$output;

    $stepName =
        preg_replace(
            '/[^A-Za-z0-9_\-]/',
            '',
            (string)$stepName
        );

    if ($stepName === '') {
        return '';
    }

    $pattern =
        '/^Step\s+'
        . preg_quote($stepName, '/')
        . ':\s*([A-Za-z0-9_\-]+)/mi';

    if (preg_match($pattern, $output, $matches)) {

        return isset($matches[1])
            ? strtoupper((string)$matches[1])
            : '';
    }

    return '';
}

/* ============================================================
 * 서버 준비상태
 * ============================================================ */

$cmExecAvailable =
    cmFunctionEnabled('exec');

$cmCronExists =
    is_file($cmCronPath)
    &&
    is_executable($cmCronPath);

$cmPhpExists =
    is_file($cmPhpPath)
    &&
    is_executable($cmPhpPath);

$cmJobExists =
    is_file($cmJobFile);

$cmStorageDir =
    $cmProjectRoot
    . DIRECTORY_SEPARATOR
    . 'storage';

$cmStorageWritable =
    is_dir($cmStorageDir)
    &&
    is_writable($cmStorageDir);

$cmOsTimezone = '';
$cmOsOffset = '';

if ($cmExecAvailable) {

    $cmTzCode = null;

    $cmOsTimezone =
        cmRunCommand(
            'date +%Z',
            $cmTzCode
        );

    $cmOffsetCode = null;

    $cmOsOffset =
        cmRunCommand(
            'date +%z',
            $cmOffsetCode
        );
}

$cmReady =
    $cmExecAvailable
    &&
    $cmCronExists
    &&
    $cmPhpExists
    &&
    $cmJobExists;

/* ============================================================
 * 현재 crontab 읽기
 * ============================================================ */

$cmCronRead = array(
    'ok' => false,
    'content' => '',
    'message' => '확인하지 못했습니다.'
);

$cmManaged = array(
    'ok' => true,
    'found' => false,
    'content' => ''
);

$cmLegacyPipelineFound = false;

if (
    $cmExecAvailable
    &&
    $cmCronExists
) {

    $cmCronRead =
        cmReadCrontab($cmCronPath);

    if (!empty($cmCronRead['ok'])) {

        $cmManaged =
            cmRemoveManagedBlock(
                $cmCronRead['content'],
                $cmBeginMarker,
                $cmEndMarker
            );

        if (!empty($cmManaged['ok'])) {

            $cmLegacyPipelineFound =
                cmContainsPipelineOutsideManagedBlock(
                    $cmManaged['content']
                );
        }
    }
}

$cmRegistered =
    !empty($cmManaged['ok'])
    &&
    !empty($cmManaged['found']);

/* ============================================================
 * 매일 19시 강제 실행
 * ============================================================ */

$cmCronCommand =
    '0 19 * * * cd '
    . escapeshellarg($cmProjectRoot)
    . ' && mkdir -p storage/logs'
    . ' && '
    . escapeshellarg($cmPhpPath)
    . ' tools/ai_daily_pipeline_job.php --force=1'
    . ' >> '
    . escapeshellarg($cmLogFile)
    . ' 2>&1';

$cmManagedBlock =
    $cmBeginMarker
    . "\n"
    . 'CRON_TZ=Asia/Seoul'
    . "\n"
    . $cmCronCommand
    . "\n"
    . $cmEndMarker;

/* ============================================================
 * 화면 처리 변수
 * ============================================================ */

$cmMessage = '';
$cmMessageType = 'info';

$cmTestOutput = '';
$cmTestExitCode = null;

$cmTestStepSnapshot = '';
$cmTestStepForecast = '';
$cmTestStepRisk = '';
$cmTestStepCeo = '';
$cmTestStepGpt = '';

$cmSnapshotBefore =
    cmSnapshotStatus();

$cmSnapshotAfter = array();

$cmSnapshotRefreshed = false;

/* ============================================================
 * 버튼 처리
 * ============================================================ */

if (
    isset($_SERVER['REQUEST_METHOD'])
    &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $postedToken =
        isset($_POST['_csrf'])
        ? (string)$_POST['_csrf']
        : '';

    if (
        !cmHashEquals(
            cmCsrfToken(),
            $postedToken
        )
    ) {

        $cmMessage =
            '요청 확인값이 맞지 않습니다. 페이지를 새로고침한 뒤 다시 시도하세요.';

        $cmMessageType =
            'danger';

    } else {

        $action =
            isset($_POST['action'])
            ? trim((string)$_POST['action'])
            : '';

        /* ====================================================
         * 자동실행 등록
         * ==================================================== */

        if ($action === 'register') {

            if (!$cmReady) {

                $cmMessage =
                    '크론 등록에 필요한 서버 조건이 모두 준비되지 않았습니다. 아래 서버 상태를 확인하세요.';

                $cmMessageType =
                    'danger';

            } elseif (
                empty($cmCronRead['ok'])
            ) {

                $cmMessage =
                    '기존 crontab을 읽지 못했기 때문에 안전을 위해 등록하지 않았습니다.';

                $cmMessageType =
                    'danger';

            } elseif (
                empty($cmManaged['ok'])
            ) {

                $cmMessage =
                    '기존 CPMS 크론 표시 구간이 비정상이라 안전을 위해 수정하지 않았습니다.';

                $cmMessageType =
                    'danger';

            } elseif (
                $cmLegacyPipelineFound
            ) {

                $cmMessage =
                    '관리 표시 밖에 기존 AI 파이프라인 크론이 발견되었습니다. 중복 실행 방지를 위해 자동 등록하지 않았습니다.';

                $cmMessageType =
                    'warning';

            } else {

                $baseContent =
                    $cmManaged['content'];

                $newContent = '';

                if (
                    trim($baseContent)
                    !== ''
                ) {

                    $newContent =
                        rtrim($baseContent)
                        . "\n\n";
                }

                $newContent .=
                    $cmManagedBlock
                    . "\n";

                $saveResult =
                    cmWriteCrontab(
                        $cmCronPath,
                        $newContent
                    );

                if (!empty($saveResult['ok'])) {

                    $verify =
                        cmReadCrontab(
                            $cmCronPath
                        );

                    if (
                        !empty($verify['ok'])
                        &&
                        strpos(
                            $verify['content'],
                            $cmBeginMarker
                        ) !== false
                        &&
                        strpos(
                            $verify['content'],
                            $cmCronCommand
                        ) !== false
                        &&
                        strpos(
                            $verify['content'],
                            $cmEndMarker
                        ) !== false
                    ) {

                        $cmMessage =
                            '등록 성공: AI 파이프라인이 매일 19:00에 강제 실행되도록 등록되었습니다.';

                        $cmMessageType =
                            'success';

                    } else {

                        $cmMessage =
                            '저장 명령은 완료됐지만 등록 결과를 다시 확인하지 못했습니다.';

                        $cmMessageType =
                            'warning';
                    }

                } else {

                    $cmMessage =
                        '등록 실패: '
                        . $saveResult['message'];

                    $cmMessageType =
                        'danger';
                }
            }
        }

        /* ====================================================
         * 자동실행 해제
         * ==================================================== */

        elseif (
            $action === 'remove'
        ) {

            if (
                !$cmExecAvailable
                ||
                !$cmCronExists
            ) {

                $cmMessage =
                    'crontab을 사용할 수 없어 해제하지 못했습니다.';

                $cmMessageType =
                    'danger';

            } else {

                $current =
                    cmReadCrontab(
                        $cmCronPath
                    );

                if (
                    empty($current['ok'])
                ) {

                    $cmMessage =
                        '현재 crontab을 읽지 못해 안전을 위해 해제하지 않았습니다.';

                    $cmMessageType =
                        'danger';

                } else {

                    $removed =
                        cmRemoveManagedBlock(
                            $current['content'],
                            $cmBeginMarker,
                            $cmEndMarker
                        );

                    if (
                        empty($removed['ok'])
                    ) {

                        $cmMessage =
                            'CPMS 크론 표시 구간이 비정상이라 안전을 위해 수정하지 않았습니다.';

                        $cmMessageType =
                            'danger';

                    } elseif (
                        empty($removed['found'])
                    ) {

                        $cmMessage =
                            '해제할 CPMS AI 자동실행 항목이 없습니다.';

                        $cmMessageType =
                            'info';

                    } else {

                        $contentAfterRemove =
                            trim($removed['content'])
                            !== ''
                            ?
                            rtrim(
                                $removed['content']
                            )
                            . "\n"
                            :
                            '';

                        $saveResult =
                            cmWriteCrontab(
                                $cmCronPath,
                                $contentAfterRemove
                            );

                        if (
                            !empty(
                                $saveResult['ok']
                            )
                        ) {

                            $verify =
                                cmReadCrontab(
                                    $cmCronPath
                                );

                            if (
                                !empty($verify['ok'])
                                &&
                                strpos(
                                    $verify['content'],
                                    $cmBeginMarker
                                ) === false
                                &&
                                strpos(
                                    $verify['content'],
                                    $cmEndMarker
                                ) === false
                            ) {

                                $cmMessage =
                                    '해제 성공: CPMS AI 자동실행 항목만 삭제했습니다. 다른 크론 항목은 보존했습니다.';

                                $cmMessageType =
                                    'success';

                            } else {

                                $cmMessage =
                                    '해제 명령은 완료됐지만 결과를 다시 확인하지 못했습니다.';

                                $cmMessageType =
                                    'warning';
                            }

                        } else {

                            $cmMessage =
                                '해제 실패: '
                                . $saveResult['message'];

                            $cmMessageType =
                                'danger';
                        }
                    }
                }
            }
        }

        /* ====================================================
         * 강제 테스트 실행
         * ==================================================== */

        elseif (
            $action === 'test'
        ) {

            if (
                !$cmExecAvailable
                ||
                !$cmPhpExists
                ||
                !$cmJobExists
            ) {

                $cmMessage =
                    '테스트 실행에 필요한 PHP CLI 또는 AI 파이프라인 파일을 찾지 못했습니다.';

                $cmMessageType =
                    'danger';

            } else {

                if (
                    function_exists(
                        'set_time_limit'
                    )
                ) {

                    @set_time_limit(0);
                }

                /*
                 * 테스트 실행 전
                 * 스냅샷 상태 기록
                 */
                $cmSnapshotBefore =
                    cmSnapshotStatus();

                /*
                 * 오늘 이미 실행했더라도
                 * 전체 파이프라인을 다시 실행
                 */
                $testCommand =
                    'cd '
                    . escapeshellarg(
                        $cmProjectRoot
                    )
                    . ' && '
                    . escapeshellarg(
                        $cmPhpPath
                    )
                    . ' tools/ai_daily_pipeline_job.php --force=1';

                $cmTestOutput =
                    cmRunCommand(
                        $testCommand,
                        $cmTestExitCode
                    );

                /*
                 * 주요 단계별 결과 추출
                 */
                $cmTestStepSnapshot =
                    cmExtractStepStatus(
                        $cmTestOutput,
                        'snapshot'
                    );

                $cmTestStepForecast =
                    cmExtractStepStatus(
                        $cmTestOutput,
                        'forecast_v2'
                    );

                $cmTestStepRisk =
                    cmExtractStepStatus(
                        $cmTestOutput,
                        'projection_risk'
                    );

                $cmTestStepCeo =
                    cmExtractStepStatus(
                        $cmTestOutput,
                        'ceo_index_v2'
                    );

                $cmTestStepGpt =
                    cmExtractStepStatus(
                        $cmTestOutput,
                        'gpt_summary'
                    );

                /*
                 * 테스트 실행 후
                 * 스냅샷 실제 DB 상태 다시 확인
                 */
                $cmSnapshotAfter =
                    cmSnapshotStatus();

                $cmSnapshotRefreshed =
                    cmSnapshotWasRefreshed(
                        $cmSnapshotBefore,
                        $cmSnapshotAfter
                    );

                if (
                    $cmTestExitCode === 0
                ) {

                    if (
                        $cmTestStepSnapshot === 'SUCCESS'
                        &&
                        $cmSnapshotRefreshed
                    ) {

                        $cmMessage =
                            '강제 테스트 완료: 전체 파이프라인을 다시 실행했고 일일 스냅샷의 실제 갱신도 확인했습니다.';

                        $cmMessageType =
                            'success';

                    } else {

                        $cmMessage =
                            '강제 테스트는 완료됐습니다. 아래 단계 결과와 일일 스냅샷 실제 반영상태를 확인하세요.';

                        $cmMessageType =
                            'warning';
                    }

                } elseif (
                    $cmTestExitCode === 2
                ) {

                    $cmMessage =
                        '강제 테스트는 실행됐지만 일부 단계가 실패했습니다. 아래 단계별 결과를 확인하세요.';

                    $cmMessageType =
                        'warning';

                } else {

                    $cmMessage =
                        '강제 테스트 실행에 실패했습니다. 아래 실행 결과를 확인하세요.';

                    $cmMessageType =
                        'danger';
                }
            }
        }
    }

    /* ========================================================
     * 처리 후 크론 상태 다시 확인
     * ======================================================== */

    if (
        $cmExecAvailable
        &&
        $cmCronExists
    ) {

        $cmCronRead =
            cmReadCrontab(
                $cmCronPath
            );

        if (
            !empty(
                $cmCronRead['ok']
            )
        ) {

            $cmManaged =
                cmRemoveManagedBlock(
                    $cmCronRead['content'],
                    $cmBeginMarker,
                    $cmEndMarker
                );

            $cmRegistered =
                !empty(
                    $cmManaged['ok']
                )
                &&
                !empty(
                    $cmManaged['found']
                );

            $cmLegacyPipelineFound =
                !empty(
                    $cmManaged['ok']
                )
                ?
                cmContainsPipelineOutsideManagedBlock(
                    $cmManaged['content']
                )
                :
                false;
        }
    }
}

$cmCsrf =
    cmCsrfToken();

$cmUserName =
    Auth::userName();

if (
    $cmUserName === null
    ||
    trim(
        (string)$cmUserName
    ) === ''
) {

    $cmUserName =
        Auth::userEmail();
}

?>
<!DOCTYPE html>
<html lang="ko">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        CPMS AI 자동실행 관리
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            padding: 28px 14px;

            background: #f4f6f8;

            color: #1f2937;

            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Malgun Gothic",
                Arial,
                sans-serif;
        }

        .wrap {

            width: 100%;

            max-width: 1040px;

            margin: 0 auto;
        }

        .hero,
        .card {

            background: #fff;

            border: 1px solid #e5e7eb;

            border-radius: 14px;

            box-shadow:
                0 4px 18px
                rgba(0, 0, 0, .05);
        }

        .hero {

            padding: 24px;

            margin-bottom: 16px;
        }

        .hero h1 {

            margin: 0 0 8px;

            font-size: 26px;
        }

        .hero p {

            margin: 6px 0;

            color: #64748b;

            line-height: 1.65;
        }

        .status-big {

            display: inline-block;

            margin-top: 12px;

            padding: 8px 13px;

            border-radius: 999px;

            font-weight: 800;
        }

        .status-on {

            background: #dcfce7;

            color: #166534;
        }

        .status-off {

            background: #f1f5f9;

            color: #475569;
        }

        .message {

            margin-bottom: 16px;

            padding: 14px 16px;

            border-radius: 12px;

            line-height: 1.6;

            background: #eff6ff;

            color: #1d4ed8;
        }

        .message.success {

            background: #ecfdf5;

            color: #047857;
        }

        .message.warning {

            background: #fffbeb;

            color: #92400e;
        }

        .message.danger {

            background: #fff1f2;

            color: #be123c;
        }

        .grid {

            display: grid;

            grid-template-columns:
                repeat(
                    2,
                    minmax(0, 1fr)
                );

            gap: 14px;

            margin-bottom: 16px;
        }

        .card {

            padding: 20px;
        }

        .card h2 {

            margin: 0 0 14px;

            font-size: 18px;
        }

        .row {

            display: grid;

            grid-template-columns:
                180px
                1fr;

            gap: 10px;

            padding: 9px 0;

            border-bottom:
                1px solid #f1f5f9;

            line-height: 1.5;
        }

        .row:last-child {

            border-bottom: 0;
        }

        .label {

            color: #64748b;

            font-size: 14px;
        }

        .value {

            font-weight: 700;

            word-break: break-all;
        }

        .ok {
            color: #15803d;
        }

        .no {
            color: #b91c1c;
        }

        .warn {
            color: #a16207;
        }

        .actions {

            display: flex;

            flex-wrap: wrap;

            gap: 10px;
        }

        form {

            margin: 0;
        }

        button {

            border: 0;

            border-radius: 10px;

            padding: 11px 15px;

            font-size: 14px;

            font-weight: 800;

            cursor: pointer;
        }

        .btn-register {

            background: #1d4ed8;

            color: #fff;
        }

        .btn-test {

            background: #334155;

            color: #fff;
        }

        .btn-remove {

            background: #b91c1c;

            color: #fff;
        }

        button:disabled {

            opacity: .45;

            cursor: not-allowed;
        }

        code,
        pre {

            font-family:
                Consolas,
                "Courier New",
                monospace;
        }

        .code {

            display: block;

            margin-top: 10px;

            padding: 13px;

            background: #0f172a;

            color: #e2e8f0;

            border-radius: 10px;

            white-space: pre-wrap;

            word-break: break-all;

            line-height: 1.6;

            font-size: 12px;
        }

        .note {

            margin-top: 16px;

            padding: 15px;

            border:
                1px solid #fde68a;

            background: #fffbeb;

            border-radius: 12px;

            line-height: 1.7;

            color: #78350f;
        }

        .test-output {

            margin-top: 16px;
        }

        .test-output pre {

            margin: 0;

            max-height: 420px;

            overflow: auto;

            padding: 15px;

            background: #111827;

            color: #e5e7eb;

            border-radius: 10px;

            white-space: pre-wrap;

            word-break: break-word;

            line-height: 1.6;
        }

        .step-grid {

            display: grid;

            grid-template-columns:
                repeat(
                    5,
                    minmax(0, 1fr)
                );

            gap: 10px;

            margin-top: 14px;
        }

        .step-box {

            border:
                1px solid #e5e7eb;

            border-radius: 10px;

            padding: 12px;

            text-align: center;
        }

        .step-name {

            font-size: 12px;

            color: #64748b;

            margin-bottom: 6px;
        }

        .step-status {

            font-size: 15px;

            font-weight: 800;
        }

        .snapshot-check {

            margin-top: 14px;

            padding: 16px;

            border-radius: 12px;

            background: #f8fafc;

            border:
                1px solid #e2e8f0;
        }

        @media (max-width: 900px) {

            .step-grid {

                grid-template-columns:
                    1fr
                    1fr;
            }
        }

        @media (max-width: 760px) {

            .grid {

                grid-template-columns: 1fr;
            }

            .row {

                grid-template-columns: 1fr;

                gap: 3px;
            }
        }

        @media (max-width: 520px) {

            .step-grid {

                grid-template-columns: 1fr;
            }
        }

    </style>

</head>


<body>

<div class="wrap">


    <!-- 현재 자동실행 상태 -->

    <section class="hero">

        <h1>
            CPMS AI 자동실행 관리
        </h1>


        <p>

            일일 스냅샷
            →
            예측
            →
            위험분석
            →
            CEO Index V2
            →
            GPT 요약을

            매일 19:00에 실행합니다.

        </p>


        <p>

            테스트와 19:00 정식 실행 모두
            <strong>강제 실행</strong>이므로,

            오늘 앞서 실행한 기록이 있어도
            다시 최신 데이터로 계산합니다.

        </p>


        <p>

            현재 로그인:

            <?php
            echo cmH(
                $cmUserName
            );
            ?>

        </p>


        <?php if ($cmRegistered): ?>

            <span
                class="
                    status-big
                    status-on
                "
            >

                자동실행 등록됨 · 매일 19:00 · 강제 실행

            </span>

        <?php else: ?>

            <span
                class="
                    status-big
                    status-off
                "
            >

                자동실행 미등록

            </span>

        <?php endif; ?>

    </section>


    <?php if ($cmMessage !== ''): ?>

        <div
            class="
                message
                <?php
                echo cmH(
                    $cmMessageType
                );
                ?>
            "
        >

            <?php
            echo cmH(
                $cmMessage
            );
            ?>

        </div>

    <?php endif; ?>


    <div class="grid">


        <!-- 서버 상태 -->

        <section class="card">

            <h2>
                서버 상태
            </h2>


            <div class="row">

                <div class="label">
                    CPMS 실제 경로
                </div>

                <div class="value">

                    <?php
                    echo cmH(
                        $cmProjectRoot
                    );
                    ?>

                </div>

            </div>


            <div class="row">

                <div class="label">
                    PHP CLI
                </div>

                <div
                    class="
                        value
                        <?php
                        echo
                            $cmPhpExists
                            ?
                            'ok'
                            :
                            'no';
                        ?>
                    "
                >

                    <?php
                    echo cmH(
                        $cmPhpPath
                    );
                    ?>

                    ·

                    <?php
                    echo
                        $cmPhpExists
                        ?
                        '정상'
                        :
                        '확인 필요';
                    ?>

                </div>

            </div>


            <div class="row">

                <div class="label">
                    crontab
                </div>

                <div
                    class="
                        value
                        <?php
                        echo
                            $cmCronExists
                            ?
                            'ok'
                            :
                            'no';
                        ?>
                    "
                >

                    <?php
                    echo cmH(
                        $cmCronPath
                    );
                    ?>

                    ·

                    <?php
                    echo
                        $cmCronExists
                        ?
                        '정상'
                        :
                        '확인 필요';
                    ?>

                </div>

            </div>


            <div class="row">

                <div class="label">
                    AI 실행파일
                </div>

                <div
                    class="
                        value
                        <?php
                        echo
                            $cmJobExists
                            ?
                            'ok'
                            :
                            'no';
                        ?>
                    "
                >

                    <?php
                    echo
                        $cmJobExists
                        ?
                        '정상'
                        :
                        '찾지 못함';
                    ?>

                </div>

            </div>


            <div class="row">

                <div class="label">
                    storage 쓰기
                </div>

                <div
                    class="
                        value
                        <?php
                        echo
                            $cmStorageWritable
                            ?
                            'ok'
                            :
                            'warn';
                        ?>
                    "
                >

                    <?php
                    echo
                        $cmStorageWritable
                        ?
                        '가능'
                        :
                        '확인 필요';
                    ?>

                </div>

            </div>


            <div class="row">

                <div class="label">
                    서버 시간대
                </div>

                <div
                    class="
                        value
                        <?php
                        echo
                            $cmOsOffset === '+0900'
                            ?
                            'ok'
                            :
                            'warn';
                        ?>
                    "
                >

                    <?php

                    echo cmH(
                        trim(
                            $cmOsTimezone
                            . ' '
                            . $cmOsOffset
                        )
                    );

                    ?>

                </div>

            </div>

        </section>


        <!-- 자동실행 설정 -->

        <section class="card">

            <h2>
                자동실행 설정
            </h2>


            <div class="row">

                <div class="label">
                    실행 시간
                </div>

                <div class="value">
                    매일 19:00
                </div>

            </div>


            <div class="row">

                <div class="label">
                    실행 방식
                </div>

                <div class="value ok">
                    --force=1 · 당일 선행 실행 무시
                </div>

            </div>


            <div class="row">

                <div class="label">
                    한국시간 지정
                </div>

                <div class="value">
                    CRON_TZ=Asia/Seoul
                </div>

            </div>


            <div class="row">

                <div class="label">
                    실행 로그
                </div>

                <div class="value">

                    <?php
                    echo cmH(
                        $cmLogFile
                    );
                    ?>

                </div>

            </div>


            <div class="row">

                <div class="label">
                    기존 크론 보존
                </div>

                <div class="value ok">
                    보존함
                </div>

            </div>


            <div class="row">

                <div class="label">
                    기존 AI 크론 중복
                </div>

                <div
                    class="
                        value
                        <?php
                        echo
                            $cmLegacyPipelineFound
                            ?
                            'warn'
                            :
                            'ok';
                        ?>
                    "
                >

                    <?php

                    echo
                        $cmLegacyPipelineFound
                        ?
                        '별도 항목 발견 - 자동등록 중지'
                        :
                        '발견되지 않음';

                    ?>

                </div>

            </div>

        </section>

    </div>


    <!-- 관리 버튼 -->

    <section class="card">

        <h2>
            실행 버튼
        </h2>


        <div class="actions">


            <form method="post">

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?php echo cmH($cmCsrf); ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="register"
                >


                <button
                    class="btn-register"
                    type="submit"

                    <?php

                    echo
                        (
                            !$cmReady
                            ||
                            $cmLegacyPipelineFound
                        )
                        ?
                        'disabled'
                        :
                        '';

                    ?>
                >

                    <?php

                    echo
                        $cmRegistered
                        ?
                        '19:00 자동실행 다시 등록'
                        :
                        '19:00 자동실행 등록';

                    ?>

                </button>

            </form>


            <form
                method="post"

                onsubmit="
                    return confirm(
                        '오늘 이미 실행한 기록이 있어도 무시하고, 스냅샷부터 GPT 요약까지 전체 파이프라인을 지금 다시 실행합니다. 계속할까요?'
                    );
                "
            >

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?php echo cmH($cmCsrf); ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="test"
                >


                <button
                    class="btn-test"
                    type="submit"

                    <?php

                    echo
                        (
                            !$cmExecAvailable
                            ||
                            !$cmPhpExists
                            ||
                            !$cmJobExists
                        )
                        ?
                        'disabled'
                        :
                        '';

                    ?>
                >

                    지금 강제 테스트 실행

                </button>

            </form>


            <form
                method="post"

                onsubmit="
                    return confirm(
                        'CPMS AI 19:00 자동실행 항목을 해제할까요? 다른 크론 항목은 삭제하지 않습니다.'
                    );
                "
            >

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?php echo cmH($cmCsrf); ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="remove"
                >


                <button
                    class="btn-remove"
                    type="submit"

                    <?php
                    echo
                        !$cmRegistered
                        ?
                        'disabled'
                        :
                        '';
                    ?>
                >

                    자동실행 해제

                </button>

            </form>

        </div>


        <code class="code"><?php
            echo cmH(
                $cmManagedBlock
            );
        ?></code>


        <div class="note">

            <strong>
                테스트와 19:00 정식 실행의 차이:
            </strong>

            <br>

            둘 다
            <code>--force=1</code>로
            전체 파이프라인을 다시 실행합니다.

            따라서 오전에 테스트해도
            저녁 19:00 정식 실행은 스킵되지 않고
            그 시점의 최신 데이터로 다시 실행됩니다.

        </div>


        <?php
        if (
            $cmOsOffset !== ''
            &&
            $cmOsOffset !== '+0900'
        ):
        ?>

            <div class="note">

                서버 자체 시간대가
                한국 표준시(+0900)가 아닙니다.

                이 페이지는
                <code>
                    CRON_TZ=Asia/Seoul
                </code>

                을 함께 등록합니다.

            </div>

        <?php endif; ?>


        <?php
        if (
            empty(
                $cmCronRead['ok']
            )
        ):
        ?>

            <div class="note">

                현재 crontab을
                안전하게 읽지 못했습니다.

                기존 작업 보호를 위해
                등록/해제를 진행하지 않는 것이 좋습니다.

                <br><br>

                확인 내용:

                <?php
                echo cmH(
                    $cmCronRead['message']
                );
                ?>

            </div>

        <?php endif; ?>


        <?php
        if ($cmLegacyPipelineFound):
        ?>

            <div class="note">

                CPMS 관리 표시 밖에서
                ai_daily_pipeline_job.php를 실행하는
                기존 크론 항목을 발견했습니다.

                기존 설정을 임의로 지우지 않기 위해
                자동 등록을 막았습니다.

            </div>

        <?php endif; ?>

    </section>


    <!-- 강제 테스트 결과 -->

    <?php
    if (
        $cmTestOutput !== ''
        ||
        $cmTestExitCode !== null
    ):
    ?>

        <section
            class="
                card
                test-output
            "
        >

            <h2>

                강제 테스트 결과

                · 종료코드

                <?php
                echo cmH(
                    $cmTestExitCode
                );
                ?>

            </h2>


            <div class="step-grid">


                <div class="step-box">

                    <div class="step-name">
                        일일 스냅샷
                    </div>

                    <div
                        class="
                            step-status
                            <?php
                            echo
                                $cmTestStepSnapshot === 'SUCCESS'
                                ?
                                'ok'
                                :
                                'warn';
                            ?>
                        "
                    >

                        <?php

                        echo cmH(
                            $cmTestStepSnapshot !== ''
                            ?
                            $cmTestStepSnapshot
                            :
                            '확인 필요'
                        );

                        ?>

                    </div>

                </div>


                <div class="step-box">

                    <div class="step-name">
                        V2 예측
                    </div>

                    <div
                        class="
                            step-status
                            <?php
                            echo
                                $cmTestStepForecast === 'SUCCESS'
                                ?
                                'ok'
                                :
                                'warn';
                            ?>
                        "
                    >

                        <?php

                        echo cmH(
                            $cmTestStepForecast !== ''
                            ?
                            $cmTestStepForecast
                            :
                            '확인 필요'
                        );

                        ?>

                    </div>

                </div>


                <div class="step-box">

                    <div class="step-name">
                        위험분석
                    </div>

                    <div
                        class="
                            step-status
                            <?php
                            echo
                                $cmTestStepRisk === 'SUCCESS'
                                ?
                                'ok'
                                :
                                'warn';
                            ?>
                        "
                    >

                        <?php

                        echo cmH(
                            $cmTestStepRisk !== ''
                            ?
                            $cmTestStepRisk
                            :
                            '확인 필요'
                        );

                        ?>

                    </div>

                </div>


                <div class="step-box">

                    <div class="step-name">
                        CEO Index V2
                    </div>

                    <div
                        class="
                            step-status
                            <?php
                            echo
                                $cmTestStepCeo === 'SUCCESS'
                                ?
                                'ok'
                                :
                                'warn';
                            ?>
                        "
                    >

                        <?php

                        echo cmH(
                            $cmTestStepCeo !== ''
                            ?
                            $cmTestStepCeo
                            :
                            '확인 필요'
                        );

                        ?>

                    </div>

                </div>


                <div class="step-box">

                    <div class="step-name">
                        GPT 요약
                    </div>

                    <div
                        class="
                            step-status
                            <?php
                            echo
                                $cmTestStepGpt === 'SUCCESS'
                                ?
                                'ok'
                                :
                                'warn';
                            ?>
                        "
                    >

                        <?php

                        echo cmH(
                            $cmTestStepGpt !== ''
                            ?
                            $cmTestStepGpt
                            :
                            '확인 필요'
                        );

                        ?>

                    </div>

                </div>


            </div>


            <!-- 일일 스냅샷 실제 반영 확인 -->

            <div class="snapshot-check">

                <h3>
                    일일 스냅샷 실제 반영 확인
                </h3>


                <div class="row">

                    <div class="label">
                        실제 갱신 여부
                    </div>

                    <div
                        class="
                            value
                            <?php
                            echo
                                $cmSnapshotRefreshed
                                ?
                                'ok'
                                :
                                'warn';
                            ?>
                        "
                    >

                        <?php

                        echo
                            $cmSnapshotRefreshed
                            ?
                            '갱신 확인됨'
                            :
                            '자동 확인 필요';

                        ?>

                    </div>

                </div>


                <div class="row">

                    <div class="label">
                        오늘 기준일
                    </div>

                    <div class="value">

                        <?php
                        echo cmH(
                            isset(
                                $cmSnapshotAfter['business_date']
                            )
                            ?
                            $cmSnapshotAfter['business_date']
                            :
                            ''
                        );
                        ?>

                    </div>

                </div>


                <div class="row">

                    <div class="label">
                        최신 스냅샷 날짜
                    </div>

                    <div class="value">

                        <?php
                        echo cmH(
                            isset(
                                $cmSnapshotAfter['latest_snapshot_date']
                            )
                            ?
                            $cmSnapshotAfter['latest_snapshot_date']
                            :
                            ''
                        );
                        ?>

                    </div>

                </div>


                <div class="row">

                    <div class="label">
                        테스트 전 마지막 수집
                    </div>

                    <div class="value">

                        <?php
                        echo cmH(
                            isset(
                                $cmSnapshotBefore['last_captured_at']
                            )
                            ?
                            $cmSnapshotBefore['last_captured_at']
                            :
                            ''
                        );
                        ?>

                    </div>

                </div>


                <div class="row">

                    <div class="label">
                        테스트 후 마지막 수집
                    </div>

                    <div
                        class="
                            value
                            <?php
                            echo
                                $cmSnapshotRefreshed
                                ?
                                'ok'
                                :
                                '';
                            ?>
                        "
                    >

                        <?php
                        echo cmH(
                            isset(
                                $cmSnapshotAfter['last_captured_at']
                            )
                            ?
                            $cmSnapshotAfter['last_captured_at']
                            :
                            ''
                        );
                        ?>

                    </div>

                </div>


                <div class="row">

                    <div class="label">
                        스냅샷 현장 수
                    </div>

                    <div class="value">

                        <?php
                        echo cmH(
                            isset(
                                $cmSnapshotAfter['project_count']
                            )
                            ?
                            $cmSnapshotAfter['project_count']
                            :
                            0
                        );
                        ?>

                        개

                    </div>

                </div>


                <div class="row">

                    <div class="label">
                        전체 스냅샷 행 수
                    </div>

                    <div class="value">

                        <?php
                        echo cmH(
                            isset(
                                $cmSnapshotAfter['snapshot_row_count']
                            )
                            ?
                            $cmSnapshotAfter['snapshot_row_count']
                            :
                            0
                        );
                        ?>

                        개

                    </div>

                </div>


                <div class="row">

                    <div class="label">
                        스냅샷 실행 상태
                    </div>

                    <div class="value">

                        <?php
                        echo cmH(
                            isset(
                                $cmSnapshotAfter['latest_run_status']
                            )
                            ?
                            $cmSnapshotAfter['latest_run_status']
                            :
                            ''
                        );
                        ?>

                    </div>

                </div>


                <div class="row">

                    <div class="label">
                        스냅샷 실행 완료시각
                    </div>

                    <div class="value">

                        <?php
                        echo cmH(
                            isset(
                                $cmSnapshotAfter['latest_run_finished_at']
                            )
                            ?
                            $cmSnapshotAfter['latest_run_finished_at']
                            :
                            ''
                        );
                        ?>

                    </div>

                </div>

            </div>


            <h3>
                CLI 전체 실행 출력
            </h3>


            <pre><?php

                echo cmH(
                    $cmTestOutput !== ''
                    ?
                    $cmTestOutput
                    :
                    '(출력 없음)'
                );

            ?></pre>

        </section>

    <?php endif; ?>


    <div class="note">

        <strong>
            중요:
        </strong>

        자동실행 등록과 테스트가 끝나면
        운영 서버의

        <code>
            /home/cmbuild/www/cpms/public/cron_manager.php
        </code>

        파일은 삭제하세요.

        <br>

        <strong>
            이 파일을 삭제해도
            이미 등록된 crontab은 그대로 유지됩니다.
        </strong>

    </div>


</div>

</body>

</html>