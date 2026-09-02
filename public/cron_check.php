<?php
/**
 * CPMS 서버 크론 사용 가능 여부 진단 페이지
 *
 * 파일 경로:
 * C:\www\cpms\public\cron_check.php
 *
 * 운영 서버 예상 위치:
 * /www/cpms/public/cron_check.php
 *
 * 기능:
 * - 서버 운영체제 확인
 * - PHP 버전 확인
 * - 서버 명령 실행 가능 여부 확인
 * - PHP CLI 위치 확인
 * - crontab 설치 여부 확인
 * - 현재 웹 실행 계정의 crontab 접근 가능 여부 확인
 * - AI 일일 파이프라인 실행파일 존재 여부 확인
 * - cURL / OpenSSL 사용 가능 여부 확인
 *
 * 중요:
 * - 서버 설정을 변경하지 않습니다.
 * - crontab을 등록하거나 수정하지 않습니다.
 * - 확인 후 이 파일은 서버에서 삭제하는 것을 권장합니다.
 *
 * PHP 5.6 호환
 */

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


/**
 * HTML 출력용 이스케이프
 */
function cpmsEscape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


/**
 * php.ini에서 특정 함수가 차단되어 있는지 확인
 */
function cpmsFunctionEnabled($functionName)
{
    if (!function_exists($functionName)) {
        return false;
    }

    $disabled = ini_get('disable_functions');

    if ($disabled === false || trim($disabled) === '') {
        return true;
    }

    $disabledFunctions = explode(',', $disabled);

    foreach ($disabledFunctions as $disabledFunction) {
        if (trim($disabledFunction) === $functionName) {
            return false;
        }
    }

    return true;
}


/**
 * 서버 명령 실행
 *
 * 서버 설정은 변경하지 않고 조회 명령만 사용합니다.
 */
function cpmsRunCommand($command, &$exitCode)
{
    $exitCode = null;

    if (cpmsFunctionEnabled('exec')) {
        $output = array();
        $code = 0;

        @exec($command . ' 2>&1', $output, $code);

        $exitCode = $code;

        return trim(implode("\n", $output));
    }

    if (cpmsFunctionEnabled('shell_exec')) {
        $output = @shell_exec($command . ' 2>&1');

        if ($output === null) {
            return '';
        }

        return trim($output);
    }

    return '';
}


/**
 * 진단 결과 추가
 */
function cpmsAddResult(&$results, $title, $status, $value, $description)
{
    $results[] = array(
        'title'       => $title,
        'status'      => $status,
        'value'       => $value,
        'description' => $description
    );
}


/* ============================================================
 * 1. 기본 서버 정보
 * ============================================================ */

$results = array();

$isWindows = (strncasecmp(PHP_OS, 'WIN', 3) === 0);

$osName = PHP_OS;

if ($isWindows) {
    cpmsAddResult(
        $results,
        '서버 운영체제',
        'warning',
        $osName,
        'Windows 계열 서버로 확인됩니다. 일반적인 Linux crontab 방식과 다를 수 있습니다.'
    );
} else {
    cpmsAddResult(
        $results,
        '서버 운영체제',
        'success',
        $osName,
        'Linux/Unix 계열로 보입니다. 일반적인 crontab 사용 가능성을 확인할 수 있습니다.'
    );
}


/* ============================================================
 * 2. PHP 버전
 * ============================================================ */

$phpVersion = PHP_VERSION;

if (version_compare($phpVersion, '5.6.0', '>=')) {
    cpmsAddResult(
        $results,
        '웹 PHP 버전',
        'success',
        $phpVersion,
        '현재 웹사이트에서 실행 중인 PHP 버전입니다.'
    );
} else {
    cpmsAddResult(
        $results,
        '웹 PHP 버전',
        'warning',
        $phpVersion,
        'PHP 5.6보다 낮은 버전입니다.'
    );
}


/* ============================================================
 * 3. PHP 서버 명령 실행 가능 여부
 * ============================================================ */

$execEnabled = cpmsFunctionEnabled('exec');
$shellExecEnabled = cpmsFunctionEnabled('shell_exec');

$commandExecutionAvailable = ($execEnabled || $shellExecEnabled);

if ($commandExecutionAvailable) {

    $availableFunctions = array();

    if ($execEnabled) {
        $availableFunctions[] = 'exec';
    }

    if ($shellExecEnabled) {
        $availableFunctions[] = 'shell_exec';
    }

    cpmsAddResult(
        $results,
        '서버 명령 조회 기능',
        'success',
        implode(', ', $availableFunctions),
        '웹페이지에서 서버의 명령 존재 여부를 조회할 수 있습니다.'
    );

} else {

    cpmsAddResult(
        $results,
        '서버 명령 조회 기능',
        'unknown',
        '차단됨',
        '서버업체가 exec / shell_exec 기능을 막아두었습니다. 이 경우 웹페이지에서 crontab 존재 여부를 확정할 수 없습니다.'
    );
}


/* ============================================================
 * 4. 현재 PHP 실행 사용자
 * ============================================================ */

$currentUser = '';

if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {

    $userInfo = @posix_getpwuid(@posix_geteuid());

    if (is_array($userInfo) && isset($userInfo['name'])) {
        $currentUser = $userInfo['name'];
    }
}

if ($currentUser === '') {

    $environmentUser = getenv('USER');

    if ($environmentUser !== false && $environmentUser !== '') {
        $currentUser = $environmentUser;
    }
}

if ($currentUser === '') {

    $environmentUser = getenv('USERNAME');

    if ($environmentUser !== false && $environmentUser !== '') {
        $currentUser = $environmentUser;
    }
}

if ($currentUser === '') {
    $currentUser = '확인 불가';
}

cpmsAddResult(
    $results,
    '웹 실행 계정',
    'info',
    $currentUser,
    'CPMS 웹페이지를 실제로 실행하고 있는 서버 계정입니다.'
);


/* ============================================================
 * 5. 현재 CPMS 실제 경로 확인
 *
 * cron_check.php가 /public 안에 있으므로
 * 한 단계 위를 CPMS 프로젝트 루트로 판단합니다.
 * ============================================================ */

$projectRoot = realpath(dirname(__DIR__));

if ($projectRoot === false) {
    $projectRoot = dirname(__DIR__);
}

cpmsAddResult(
    $results,
    '현재 CPMS 실제 경로',
    'info',
    $projectRoot,
    '현재 cron_check.php 위치를 기준으로 자동 계산한 CPMS 프로젝트 경로입니다.'
);


/* ============================================================
 * 6. /www/cpms 존재 여부
 * ============================================================ */

$expectedLinuxPath = '/www/cpms';

if (is_dir($expectedLinuxPath)) {

    cpmsAddResult(
        $results,
        '/www/cpms 경로',
        'success',
        '존재함',
        '코덱스에서 안내한 운영 서버 경로 /www/cpms가 실제로 존재합니다.'
    );

} else {

    cpmsAddResult(
        $results,
        '/www/cpms 경로',
        'warning',
        '확인되지 않음',
        '문제가 있다는 뜻은 아닙니다. 실제 CPMS 경로가 다른 위치일 수 있습니다.'
    );
}


/* ============================================================
 * 7. AI 일일 파이프라인 파일 확인
 * ============================================================ */

$pipelineJobPath =
    rtrim($projectRoot, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'tools'
    . DIRECTORY_SEPARATOR
    . 'ai_daily_pipeline_job.php';

if (is_file($pipelineJobPath)) {

    cpmsAddResult(
        $results,
        'AI 일일 파이프라인',
        'success',
        $pipelineJobPath,
        '매일 19시에 실행하려는 AI 파이프라인 파일이 서버에 존재합니다.'
    );

} else {

    cpmsAddResult(
        $results,
        'AI 일일 파이프라인',
        'danger',
        $pipelineJobPath,
        '해당 위치에서 ai_daily_pipeline_job.php 파일을 찾지 못했습니다.'
    );
}


/* ============================================================
 * 8. PHP CLI 위치 확인
 * ============================================================ */

$phpCliPath = '';
$phpCliVersion = '';
$phpCliExitCode = null;

if ($commandExecutionAvailable) {

    if ($isWindows) {
        $phpCliCommand = 'where php';
    } else {
        $phpCliCommand = 'command -v php';
    }

    $phpCliOutput = cpmsRunCommand($phpCliCommand, $phpCliExitCode);

    if ($phpCliOutput !== '') {

        $phpCliLines = preg_split('/\r\n|\r|\n/', $phpCliOutput);

        if (is_array($phpCliLines) && isset($phpCliLines[0])) {
            $phpCliPath = trim($phpCliLines[0]);
        }

        if ($phpCliPath !== '') {

            $versionExitCode = null;

            $phpCliVersionOutput = cpmsRunCommand(
                escapeshellarg($phpCliPath) . ' -v',
                $versionExitCode
            );

            if ($phpCliVersionOutput !== '') {

                $versionLines = preg_split(
                    '/\r\n|\r|\n/',
                    $phpCliVersionOutput
                );

                if (is_array($versionLines) && isset($versionLines[0])) {
                    $phpCliVersion = trim($versionLines[0]);
                }
            }
        }
    }
}

if ($phpCliPath !== '') {

    cpmsAddResult(
        $results,
        'PHP CLI 실행파일',
        'success',
        $phpCliPath,
        $phpCliVersion !== ''
            ? $phpCliVersion
            : '서버에서 명령어용 PHP 실행파일을 찾았습니다.'
    );

} else {

    cpmsAddResult(
        $results,
        'PHP CLI 실행파일',
        $commandExecutionAvailable ? 'warning' : 'unknown',
        $commandExecutionAvailable ? '찾지 못함' : '확인 불가',
        $commandExecutionAvailable
            ? '웹 PHP는 작동하지만 명령어용 PHP 위치를 확인하지 못했습니다.'
            : '서버 명령 실행 기능이 차단되어 있어 확인할 수 없습니다.'
    );
}


/* ============================================================
 * 9. crontab 설치 여부 확인
 * ============================================================ */

$cronPath = '';
$cronPathExitCode = null;

if ($commandExecutionAvailable) {

    if ($isWindows) {
        $cronFindCommand = 'where crontab';
    } else {
        $cronFindCommand = 'command -v crontab';
    }

    $cronPathOutput = cpmsRunCommand(
        $cronFindCommand,
        $cronPathExitCode
    );

    if ($cronPathOutput !== '') {

        $cronLines = preg_split('/\r\n|\r|\n/', $cronPathOutput);

        if (is_array($cronLines) && isset($cronLines[0])) {
            $cronPath = trim($cronLines[0]);
        }
    }
}

if ($cronPath !== '') {

    cpmsAddResult(
        $results,
        'crontab 프로그램',
        'success',
        $cronPath,
        '서버에서 crontab 명령을 찾았습니다.'
    );

} else {

    cpmsAddResult(
        $results,
        'crontab 프로그램',
        $commandExecutionAvailable ? 'warning' : 'unknown',
        $commandExecutionAvailable ? '찾지 못함' : '확인 불가',
        $commandExecutionAvailable
            ? '현재 웹 실행 환경에서는 crontab 명령을 찾지 못했습니다.'
            : '서버 명령 실행 기능이 차단되어 있어 crontab 존재 여부를 확인할 수 없습니다.'
    );
}


/* ============================================================
 * 10. 현재 웹 계정의 crontab 조회 권한 확인
 *
 * 중요:
 * crontab을 추가/수정하지 않습니다.
 * 단순히 crontab -l 조회만 합니다.
 * ============================================================ */

$cronAccessStatus = 'unknown';
$cronAccessText = '';
$cronListOutput = '';
$cronListExitCode = null;

if ($cronPath !== '' && !$isWindows) {

    $cronListOutput = cpmsRunCommand(
        escapeshellarg($cronPath) . ' -l',
        $cronListExitCode
    );

    $cronOutputLower = strtolower($cronListOutput);

    if ($cronListExitCode === 0) {

        $cronAccessStatus = 'success';
        $cronAccessText = 'crontab 조회 가능';

    } elseif (
        strpos($cronOutputLower, 'no crontab for') !== false
        || strpos($cronOutputLower, 'no crontab') !== false
    ) {

        /*
         * 등록된 크론이 없다는 뜻이지,
         * crontab 사용 자체가 막혀 있다는 뜻은 아닙니다.
         */
        $cronAccessStatus = 'success';
        $cronAccessText = '사용 가능 - 현재 등록된 작업 없음';

    } elseif (
        strpos($cronOutputLower, 'permission denied') !== false
        || strpos($cronOutputLower, 'not allowed') !== false
        || strpos($cronOutputLower, 'not authorized') !== false
    ) {

        $cronAccessStatus = 'danger';
        $cronAccessText = '접근 권한 없음';

    } else {

        $cronAccessStatus = 'warning';

        if ($cronListOutput !== '') {
            $cronAccessText = $cronListOutput;
        } else {
            $cronAccessText = '확실하게 판정하지 못했습니다.';
        }
    }

} elseif ($cronPath !== '' && $isWindows) {

    $cronAccessStatus = 'warning';
    $cronAccessText = 'Windows 환경에서는 Linux crontab 방식과 다릅니다.';

} else {

    $cronAccessStatus = 'unknown';

    if ($commandExecutionAvailable) {
        $cronAccessText = 'crontab 프로그램을 찾지 못해 확인하지 못했습니다.';
    } else {
        $cronAccessText = '서버 명령 실행 기능이 차단되어 확인하지 못했습니다.';
    }
}

cpmsAddResult(
    $results,
    '현재 계정의 crontab 접근',
    $cronAccessStatus,
    $cronAccessText,
    '조회만 수행했습니다. 크론 작업은 추가하거나 수정하지 않았습니다.'
);


/* ============================================================
 * 11. cURL 확인
 * ============================================================ */

if (function_exists('curl_init')) {

    cpmsAddResult(
        $results,
        'PHP cURL',
        'success',
        '사용 가능',
        'OpenAI API 등 외부 API 통신에 필요한 PHP cURL 기능이 있습니다.'
    );

} else {

    cpmsAddResult(
        $results,
        'PHP cURL',
        'danger',
        '사용 불가',
        'GPT 요약 등 외부 API 호출에 문제가 생길 수 있습니다.'
    );
}


/* ============================================================
 * 12. OpenSSL 확인
 * ============================================================ */

if (extension_loaded('openssl')) {

    cpmsAddResult(
        $results,
        'OpenSSL',
        'success',
        '사용 가능',
        'HTTPS 외부 통신에 필요한 OpenSSL 확장이 활성화되어 있습니다.'
    );

} else {

    cpmsAddResult(
        $results,
        'OpenSSL',
        'warning',
        '확인되지 않음',
        'HTTPS 외부 통신 기능에 제한이 있을 수 있습니다.'
    );
}


/* ============================================================
 * 13. 최종 판정
 * ============================================================ */

$finalStatus = 'unknown';
$finalTitle = '추가 확인 필요';
$finalMessage = '';

if (
    !$isWindows
    && $commandExecutionAvailable
    && $phpCliPath !== ''
    && $cronPath !== ''
    && $cronAccessStatus === 'success'
) {

    $finalStatus = 'success';
    $finalTitle = '서버 자체 crontab 사용 가능성이 높습니다.';
    $finalMessage =
        'Linux/Unix 계열 서버이고, PHP CLI와 crontab을 찾았으며 '
        . '현재 웹 실행 계정에서도 crontab 조회가 가능합니다. '
        . '코덱스에서 안내한 서버 crontab 방식으로 자동 실행을 구성할 가능성이 높습니다.';

} elseif (
    !$commandExecutionAvailable
) {

    $finalStatus = 'unknown';
    $finalTitle = '웹페이지에서 crontab 여부를 확인할 수 없습니다.';
    $finalMessage =
        '서버업체가 PHP의 서버 명령 실행 기능을 차단해 두었습니다. '
        . '이 결과만으로 crontab이 없다고 판단하면 안 됩니다. '
        . '이 경우 외부 웹크론 방식이 가장 현실적인 대안입니다.';

} elseif (
    $cronPath === ''
) {

    $finalStatus = 'warning';
    $finalTitle = '현재 웹 환경에서는 crontab을 찾지 못했습니다.';
    $finalMessage =
        'crontab이 서버 전체에 없는 것인지, 웹 실행 계정에서만 보이지 않는 것인지는 '
        . '이 페이지 하나만으로 확정할 수 없습니다.';

} elseif (
    $cronAccessStatus === 'danger'
) {

    $finalStatus = 'warning';
    $finalTitle = 'crontab은 있지만 현재 웹 계정에 접근 권한이 없습니다.';
    $finalMessage =
        '서버 자체에는 crontab이 존재하지만 CPMS 웹 실행 계정에서는 사용할 권한이 없습니다. '
        . '서버업체 도움 없이 등록하기는 어려울 가능성이 높습니다.';

} else {

    $finalStatus = 'warning';
    $finalTitle = '일부 조건만 확인되었습니다.';
    $finalMessage =
        '아래 진단 결과를 확인하면 어떤 부분에서 막혀 있는지 판단할 수 있습니다.';
}


/* ============================================================
 * 화면 출력
 * ============================================================ */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>CPMS 크론 진단</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            background: #f4f6f8;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Malgun Gothic",
                Arial,
                sans-serif;
            color: #222;
        }

        .wrap {
            width: 100%;
            max-width: 950px;
            margin: 0 auto;
        }

        .header {
            margin-bottom: 20px;
        }

        .header h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .header p {
            margin: 0;
            color: #666;
            line-height: 1.7;
        }

        .summary {
            padding: 22px;
            margin-bottom: 20px;
            border-radius: 12px;
            background: #fff;
            border: 2px solid #ddd;
        }

        .summary.success {
            border-color: #1f9d55;
        }

        .summary.warning {
            border-color: #d99b18;
        }

        .summary.danger {
            border-color: #d64545;
        }

        .summary.unknown {
            border-color: #777;
        }

        .summary h2 {
            margin: 0 0 10px;
            font-size: 22px;
        }

        .summary p {
            margin: 0;
            line-height: 1.7;
            color: #555;
        }

        .card {
            margin-bottom: 12px;
            padding: 18px 20px;
            background: #fff;
            border-radius: 10px;
            border-left: 5px solid #aaa;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
        }

        .card.success {
            border-left-color: #1f9d55;
        }

        .card.warning {
            border-left-color: #d99b18;
        }

        .card.danger {
            border-left-color: #d64545;
        }

        .card.info {
            border-left-color: #3273dc;
        }

        .card.unknown {
            border-left-color: #777;
        }

        .card-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 6px;
        }

        .card-value {
            margin-bottom: 7px;
            font-size: 15px;
            word-break: break-all;
        }

        .card-description {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        .badge {
            display: inline-block;
            min-width: 78px;
            padding: 4px 8px;
            margin-right: 8px;
            border-radius: 20px;
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            vertical-align: middle;
        }

        .badge.success {
            background: #e7f7ed;
            color: #16783e;
        }

        .badge.warning {
            background: #fff4d6;
            color: #946600;
        }

        .badge.danger {
            background: #fdeaea;
            color: #b52b2b;
        }

        .badge.info {
            background: #e8f1ff;
            color: #235ba8;
        }

        .badge.unknown {
            background: #eeeeee;
            color: #555;
        }

        .notice {
            margin-top: 25px;
            padding: 18px;
            background: #fff7df;
            border: 1px solid #ead28a;
            border-radius: 10px;
            line-height: 1.7;
        }

        .notice strong {
            display: block;
            margin-bottom: 5px;
        }
    </style>
</head>

<body>

<div class="wrap">

    <!-- 크론 진단 화면 제목 -->
    <div class="header">
        <h1>CPMS 서버 크론 진단</h1>

        <p>
            서버 설정을 변경하지 않고
            현재 서버에서 자동 실행 기능을 사용할 수 있는지 확인합니다.
        </p>
    </div>


    <!-- 최종 판정 -->
    <div class="summary <?php echo cpmsEscape($finalStatus); ?>">
        <h2>
            <?php echo cpmsEscape($finalTitle); ?>
        </h2>

        <p>
            <?php echo cpmsEscape($finalMessage); ?>
        </p>
    </div>


    <!-- 개별 진단 결과 -->
    <?php foreach ($results as $result): ?>

        <div class="card <?php echo cpmsEscape($result['status']); ?>">

            <div class="card-title">

                <span class="badge <?php echo cpmsEscape($result['status']); ?>">

                    <?php
                    if ($result['status'] === 'success') {
                        echo '정상';
                    } elseif ($result['status'] === 'warning') {
                        echo '주의';
                    } elseif ($result['status'] === 'danger') {
                        echo '문제';
                    } elseif ($result['status'] === 'info') {
                        echo '정보';
                    } else {
                        echo '확인불가';
                    }
                    ?>

                </span>

                <?php echo cpmsEscape($result['title']); ?>

            </div>

            <div class="card-value">
                <?php echo nl2br(cpmsEscape($result['value'])); ?>
            </div>

            <div class="card-description">
                <?php echo cpmsEscape($result['description']); ?>
            </div>

        </div>

    <?php endforeach; ?>


    <!-- 보안 안내 -->
    <div class="notice">

        <strong>확인 후 삭제하세요.</strong>

        이 페이지는 서버의 기본 환경 정보를 보여주는 임시 진단 페이지입니다.
        결과를 확인하고 캡처한 뒤
        <b>cron_check.php 파일은 운영 서버에서 삭제하는 것을 권장합니다.</b>

    </div>

</div>

</body>
</html>